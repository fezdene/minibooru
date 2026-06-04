<?php

declare(strict_types=1);

namespace Shimmie2;

class NetworkOps extends Extension
{
    public const KEY = 'network_ops';
    /** @var NetworkOpsTheme */
    protected Themelet $theme;

    private const RSYNC_SCRIPT    = '/opt/scripts/rsync_push.sh';
    private const AUDIT_LOG       = '/var/log/rsync_audit.log';
    private const MONITOR_PORT    = 80;
    private const MONITOR_TIMEOUT = 2;
    // Flat file outside data/ — never overwritten by rsync.
    private const NODE_CFG_FILE   = '/var/www/shimmie/config/network_ops_node.json';
    // Shared background-job status file used by all minibooru extensions.
    private const BG_STATUS_FILE  = '/tmp/minibooru_bg_jobs.json';

    // ── Navigation ───────────────────────────────────────────────────────────

    #[EventListener]
    public function onPageNavBuilding(PageNavBuildingEvent $event): void
    {
        if (Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
            $event->add_nav_link(make_link('network'), "Network", ["network"], "network");
        }
    }

    #[EventListener]
    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event): void
    {
        if ($event->parent !== "network") {
            return;
        }
        $event->add_nav_link(make_link('network'), "Analytics", ["network"], order: 10);
        $event->add_nav_link(make_link('network/ops'), "Operations", ["network/ops"], order: 20);
        $event->add_nav_link(make_link('network/status'), "Mirror Status", ["network/status"], order: 30);
        $event->add_nav_link(make_link('network/audit'), "Audit Log", ["network/audit"], order: 40);
    }

    // ── Request handling ─────────────────────────────────────────────────────

    #[EventListener]
    public function onPageRequest(PageRequestEvent $event): void
    {
        // ── POST actions ─────────────────────────────────────────────────────

        if ($event->page_matches("admin/network_ops_save", method: "POST")) {
            if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                throw new PermissionDenied("Admin access required.");
            }
            $this->handle_save_config();
            return;
        }

        if ($event->page_matches("admin/network_ops_sync", method: "POST")) {
            if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                throw new PermissionDenied("Admin access required.");
            }
            $this->handle_force_sync();
            return;
        }

        // ── Live latency JSON — all mirrors ──────────────────────────────────
        if ($event->page_matches("network/latency", method: "GET")) {
            if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                Ctx::$page->set_data(MimeType::JSON, json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR));
                return;
            }
            $mirrors    = $this->read_node_mirrors();
            $probe_data = [];
            foreach ($mirrors as $m) {
                $p = $this->probe_mirror($m);
                $probe_data[] = [
                    'host'       => $m,
                    'online'     => $p['online'],
                    'latency_ms' => $p['latency_ms'],
                ];
            }
            Ctx::$page->set_data(
                MimeType::JSON,
                json_encode([
                    'mirrors' => $probe_data,
                    'ts'      => (new \DateTime('now', new \DateTimeZone('Asia/Kuala_Lumpur')))->format('H:i:s'),
                ], JSON_THROW_ON_ERROR)
            );
            return;
        }

        // ── Background job status JSON — admin only ───────────────────────────
        if ($event->page_matches("network/bg_status", method: "GET")) {
            if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                Ctx::$page->set_data(MimeType::JSON, json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR));
                return;
            }
            Ctx::$page->set_data(
                MimeType::JSON,
                json_encode(['jobs' => self::bg_get_active(), 'ts' => time()], JSON_THROW_ON_ERROR)
            );
            return;
        }

        // ── GET pages — admin only ────────────────────────────────────────────

        if ($event->page_matches("network")) {
            if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                throw new PermissionDenied("Admin access required.");
            }
            Ctx::$page->set_title("Network Analytics");
            Ctx::$page->set_heading("Network Analytics");
            $this->theme->display_analytics($this->gather_analytics());
        }

        if ($event->page_matches("network/ops")) {
            if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                throw new PermissionDenied("Admin access required.");
            }
            $mirrors = $this->read_node_mirrors();
            $probes  = $this->probe_all_mirrors($mirrors);
            Ctx::$page->set_title("Network Operations");
            Ctx::$page->set_heading("Network Operations");
            $this->theme->display_ops($mirrors, $probes, $this->read_rsync_receipt());
        }

        if ($event->page_matches("network/status")) {
            if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                throw new PermissionDenied("Admin access required.");
            }
            $mirrors = $this->read_node_mirrors();
            $probes  = $this->probe_all_mirrors($mirrors);
            Ctx::$page->set_title("Mirror Node Status");
            Ctx::$page->set_heading("Mirror Node Status");
            $this->theme->display_status($mirrors, $probes);
        }

        if ($event->page_matches("network/audit")) {
            if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                throw new PermissionDenied("Admin access required.");
            }
            if (isset($_GET['json'])) {
                $lines = $this->read_audit_log_lines(500);
                Ctx::$page->set_data(MimeType::JSON, json_encode([
                    'lines' => array_values($lines),
                    'ts'    => (new \DateTime('now', new \DateTimeZone('Asia/Kuala_Lumpur')))->format('H:i:s'),
                ], JSON_THROW_ON_ERROR));
                return;
            }
            Ctx::$page->set_title("Audit Log");
            Ctx::$page->set_heading("Audit Log");
            $this->theme->display_audit($this->read_audit_log_lines(500));
        }
    }

    // ── Analytics data gathering ─────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function gather_analytics(): array
    {
        $db      = Ctx::$database;
        $mirrors = $this->read_node_mirrors();
        $probes  = $this->probe_all_mirrors($mirrors);

        // If no mirrors are configured, this node IS a mirror — show receipt.
        $is_mirror = count($mirrors) === 0;

        $mirrors_data = [];
        foreach ($mirrors as $m) {
            $mirrors_data[] = ['host' => $m, 'probe' => $probes[$m]];
        }

        $local_posts = (int)$db->get_one("SELECT COUNT(*) FROM images");
        $local_size  = (float)$db->get_one("SELECT COALESCE(SUM(filesize),0) FROM images");

        $slot_sql = "
            SELECT
                SUBSTR(posted,1,13) || ':' ||
                CASE WHEN CAST(SUBSTR(posted,15,2) AS INTEGER) < 30 THEN '00' ELSE '30' END,
                COUNT(*)
            FROM %s
            WHERE posted >= datetime('now','-30 days')
            GROUP BY 1
            ORDER BY 1 ASC";

        $upload_slots  = array_map('intval', $db->get_pairs(sprintf($slot_sql, 'images')));
        $comment_slots = array_map('intval', $db->get_pairs(sprintf($slot_sql, 'comments')));

        $log_lines = $this->read_audit_log_lines(500);
        $sessions  = $this->parse_sync_sessions($log_lines);

        $last        = count($sessions) > 0 ? end($sessions) : null;
        $total_files = (int)array_sum(array_column($sessions, 'files'));
        $total_bytes = (int)array_sum(array_column($sessions, 'bytes'));

        return [
            'mirrors'       => $mirrors_data,
            'is_mirror'     => $is_mirror,
            'probe'         => $is_mirror ? ['configured' => false, 'online' => false, 'latency_ms' => null]
                                          : ($probes[$mirrors[0]] ?? ['configured' => false, 'online' => false, 'latency_ms' => null]),
            'mirror_ip'     => $is_mirror ? '' : ($mirrors[0] ?? ''),
            'local_posts'   => $local_posts,
            'local_size'    => $local_size,
            'upload_slots'  => $upload_slots,
            'comment_slots' => $comment_slots,
            'sessions'      => $sessions,
            'last_sync'     => $last,
            'total_files'   => $total_files,
            'total_bytes'   => $total_bytes,
            'receipt'       => $this->read_rsync_receipt(),
        ];
    }

    // ── Mirror probing ───────────────────────────────────────────────────────

    /**
     * Probe every mirror and return results keyed by hostname.
     *
     * @param  string[]                                                         $mirrors
     * @return array<string, array{configured: bool, online: bool, latency_ms: ?float}>
     */
    private function probe_all_mirrors(array $mirrors): array
    {
        $results = [];
        foreach ($mirrors as $m) {
            $results[$m] = $this->probe_mirror($m);
        }
        return $results;
    }

    /** @return array{configured: bool, online: bool, latency_ms: ?float} */
    private function probe_mirror(string $mirror_ip): array
    {
        if ($mirror_ip === '') {
            return ['configured' => false, 'online' => false, 'latency_ms' => null];
        }
        $t0   = microtime(true);
        $sock = @fsockopen($mirror_ip, self::MONITOR_PORT, $ec, $em, self::MONITOR_TIMEOUT);
        $ms   = round((microtime(true) - $t0) * 1000, 1);
        if ($sock !== false) {
            fclose($sock);
            return ['configured' => true, 'online' => true, 'latency_ms' => $ms];
        }
        Log::debug('network_ops', "Probe failed [{$mirror_ip}:80]: [{$ec}] {$em}");
        return ['configured' => true, 'online' => false, 'latency_ms' => null];
    }

    // ── Audit log helpers ────────────────────────────────────────────────────

    /** @return string[] */
    private function read_audit_log_lines(int $n = 50): array
    {
        if (!file_exists(self::AUDIT_LOG) || !is_readable(self::AUDIT_LOG)) {
            return [];
        }
        $all = file(self::AUDIT_LOG, FILE_IGNORE_NEW_LINES) ?: [];
        return array_slice($all, -$n);
    }

    /** @return array<array{date: string, files: int, bytes: int}> */
    private function parse_sync_sessions(array $lines): array
    {
        $sessions = [];
        $cur      = null;
        foreach ($lines as $line) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/', $line, $m)) {
                if ($cur !== null) {
                    $sessions[] = $cur;
                }
                $cur = ['date' => $m[1], 'files' => 0, 'bytes' => 0];
            }
            if ($cur === null) {
                continue;
            }
            if (preg_match('/Number of (?:regular )?files transferred:\s*([\d,]+)/i', $line, $m)) {
                $cur['files'] = (int)str_replace(',', '', $m[1]);
            }
            if (preg_match('/Total transferred file size:\s*([\d,]+)/i', $line, $m)) {
                $cur['bytes'] = (int)str_replace(',', '', $m[1]);
            }
            if ($cur['bytes'] === 0 && preg_match('/sent\s+([\d,]+)\s+bytes/i', $line, $m)) {
                $cur['bytes'] = (int)str_replace(',', '', $m[1]);
            }
        }
        if ($cur !== null) {
            $sessions[] = $cur;
        }
        return $sessions;
    }

    // ── Action handlers ──────────────────────────────────────────────────────

    private function handle_save_config(): void
    {
        $action = trim((string)($_POST['action'] ?? 'add'));
        $host   = trim((string)($_POST['mirror_host'] ?? ''));

        if ($host === '' && $action === 'add') {
            Ctx::$page->flash("No host provided.");
            Ctx::$page->set_redirect(make_link("network/ops"));
            return;
        }

        $mirrors = $this->read_node_mirrors();

        if ($action === 'remove') {
            $mirrors = array_values(array_filter($mirrors, fn ($m) => $m !== $host));
            $this->write_node_mirrors($mirrors);
            Log::info('network_ops', "Mirror removed: {$host} by " . Ctx::$user->name);
            Ctx::$page->flash("Mirror removed: {$host}");
        } else {
            // add
            if (!$this->is_valid_host($host)) {
                Ctx::$page->flash("Invalid host: \"{$host}\". Use a valid IPv4, IPv6, or hostname.");
                Ctx::$page->set_redirect(make_link("network/ops"));
                return;
            }
            if (!in_array($host, $mirrors, true)) {
                $mirrors[] = $host;
                $this->write_node_mirrors($mirrors);
                Log::info('network_ops', "Mirror added: {$host} by " . Ctx::$user->name);
                Ctx::$page->flash("Mirror added: {$host}");
            } else {
                Ctx::$page->flash("Mirror already listed: {$host}");
            }
        }

        Ctx::$page->set_redirect(make_link("network/ops"));
    }

    private function handle_force_sync(): void
    {
        if (!is_executable(self::RSYNC_SCRIPT)) {
            Ctx::$page->flash("Re-Sync failed: script not found or not executable.");
            Ctx::$page->set_redirect(make_link("network/ops"));
            return;
        }

        $target  = trim((string)($_POST['mirror_host'] ?? ''));
        $mirrors = $this->read_node_mirrors();

        // Target a specific mirror or all of them.
        $targets = $target !== '' ? [$target] : $mirrors;
        $targets = array_filter($targets, fn ($m) => in_array($m, $mirrors, true));

        if (empty($targets)) {
            Ctx::$page->flash("No valid mirrors to sync.");
            Ctx::$page->set_redirect(make_link("network/ops"));
            return;
        }

        $safe_log = escapeshellarg(self::AUDIT_LOG);
        foreach ($targets as $m) {
            $safe_m = escapeshellarg($m);
            self::bg_start("rsync_{$m}", "RSYNC push \u{2192} {$m}", 300);
            shell_exec(self::RSYNC_SCRIPT . ' ' . $safe_m . ' >> ' . $safe_log . ' 2>&1 &');
            Log::info('network_ops', "Force Re-Sync dispatched → {$m} by " . Ctx::$user->name);
        }

        $list = implode(', ', $targets);
        Ctx::$page->flash("Re-Sync dispatched to: {$list}. Check Audit Log for progress.");
        Ctx::$page->set_redirect(make_link("network/ops"));
    }

    // ── Config file helpers ──────────────────────────────────────────────────

    /**
     * Returns the list of configured mirror hostnames.
     * Reads new format (mirrors:[]) with backward-compat for old (mirror_ip:"").
     *
     * @return string[]
     */
    private function read_node_mirrors(): array
    {
        $file = self::NODE_CFG_FILE;
        if (!file_exists($file) || !is_readable($file)) {
            return [];
        }
        $json = json_decode(file_get_contents($file) ?: '{}', true);
        if (!is_array($json)) {
            return [];
        }
        // New format
        if (isset($json['mirrors']) && is_array($json['mirrors'])) {
            return array_values(array_filter($json['mirrors'], fn ($m) => is_string($m) && $m !== ''));
        }
        // Backward compat: old single mirror_ip field
        $old = (string)($json['mirror_ip'] ?? '');
        return $old !== '' ? [$old] : [];
    }

    /** @param string[] $mirrors */
    private function write_node_mirrors(array $mirrors): void
    {
        $dir = dirname(self::NODE_CFG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $json = [];
        if (file_exists(self::NODE_CFG_FILE)) {
            $existing = json_decode(file_get_contents(self::NODE_CFG_FILE) ?: '{}', true);
            if (is_array($existing)) {
                $json = $existing;
            }
        }
        $json['mirrors']  = array_values($mirrors);
        // Remove old single-mirror key to avoid confusion
        unset($json['mirror_ip']);
        file_put_contents(self::NODE_CFG_FILE, json_encode($json, JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed>|null */
    private function read_rsync_receipt(): ?array
    {
        $file = '/var/www/shimmie/config/rsync_receipt.json';
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file) ?: '{}', true);
        return is_array($data) ? $data : null;
    }

    // ── Background job status helpers ────────────────────────────────────────

    private static function bg_start(string $id, string $label, int $timeout = 300): void
    {
        $file = self::BG_STATUS_FILE;
        $jobs = json_decode(@file_get_contents($file) ?: '[]', true) ?: [];
        $now  = time();
        $jobs = array_filter($jobs, fn ($j) => $j['id'] !== $id && ($now - $j['started_at']) < $j['timeout']);
        $jobs[] = ['id' => $id, 'label' => $label, 'started_at' => $now, 'timeout' => $timeout];
        @file_put_contents($file, json_encode(array_values($jobs)), LOCK_EX);
    }

    private static function bg_finish(string $id): void
    {
        $file = self::BG_STATUS_FILE;
        $jobs = json_decode(@file_get_contents($file) ?: '[]', true) ?: [];
        $now  = time();
        $jobs = array_filter($jobs, fn ($j) => $j['id'] !== $id && ($now - $j['started_at']) < $j['timeout']);
        @file_put_contents($file, json_encode(array_values($jobs)), LOCK_EX);
    }

    /** @return list<array{id: string, label: string, started_at: int, timeout: int}> */
    private static function bg_get_active(): array
    {
        $file = self::BG_STATUS_FILE;
        $jobs = json_decode(@file_get_contents($file) ?: '[]', true) ?: [];
        $now  = time();
        return array_values(array_filter($jobs, fn ($j) => ($now - $j['started_at']) < $j['timeout']));
    }

    private function is_valid_host(string $host): bool
    {
        if (preg_match('/[\/:@?#]/', $host)) {
            return false;
        }
        if (strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return (bool)preg_match(
            '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*'
            . '[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/',
            $host
        );
    }
}
