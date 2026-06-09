<?php

declare(strict_types=1);

namespace Shimmie2;

class GalleryDlIngest extends Extension
{
    public const KEY = 'gallerydl_ingest';
    /** @var GalleryDlIngestTheme */
    protected Themelet $theme;

    private const GALLERY_DL_BIN  = '/usr/local/bin/gallery-dl';
    private const YTDLP_BIN       = '/usr/local/bin/yt-dlp';
    private const SINGLEFILE_BIN  = '/usr/local/bin/single-file';
    private const CHROMIUM_BIN    = '/usr/bin/chromium';
    private const INGEST_TMP_BASE = '/tmp/ingest';
    private const BG_STATUS_FILE  = '/tmp/minibooru_bg_jobs.json';
    private const DEFAULT_TAGS    = 'gallery-dl:ingested';

    // Per-engine exec() timeouts (seconds). SingleFile has a separate
    // --browser-timeout (ms) that fires first; the shell timeout is a hard backstop.
    private const TIMEOUT_GALLERY_DL  = 300;  // image galleries can be large
    private const TIMEOUT_YTDLP       = 600;  // video downloads can be large
    private const TIMEOUT_SINGLEFILE  = 120;  // webpage capture should be fast
    private const TIMEOUT_CHROMIUM    = 90;   // PDF rendering of a local HTML file
    private const BROWSER_TIMEOUT_MS  = 90000; // SingleFile --browser-timeout

    // Metadata and sidecar files produced alongside media — skip these.
    private const SKIP_EXTENSIONS = ['json', 'txt', 'log', 'part', 'aria2', 'vtt', 'srt', 'ass', 'ssa', 'sbv'];

    // -------------------------------------------------------------------------
    // Database upgrade
    // -------------------------------------------------------------------------

    #[EventListener]
    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event): void
    {
        if ($this->get_version() < 1) {
            Ctx::$database->create_table('gallerydl_queue', "
                id SCORE_AIPK,
                user_id INTEGER NOT NULL,
                url TEXT NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                reviewer_id INTEGER NULL DEFAULT NULL,
                result_message TEXT NULL DEFAULT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ");
            $this->set_version(1);
        }

        if ($this->get_version() < 2) {
            Ctx::$database->execute(
                "ALTER TABLE gallerydl_queue ADD COLUMN ingest_format VARCHAR(4) NOT NULL DEFAULT 'pdf'"
            );
            $this->set_version(2);
        }

        if ($this->get_version() < 3) {
            Ctx::$database->execute(
                "ALTER TABLE gallerydl_queue ADD COLUMN ingest_engine VARCHAR(16) NOT NULL DEFAULT 'auto'"
            );
            $this->set_version(3);
        }
    }

    // -------------------------------------------------------------------------
    // Page request handler
    // -------------------------------------------------------------------------

    #[EventListener]
    public function onPageRequest(PageRequestEvent $event): void
    {
        // Show pending badge in header on every page for admins
        if (Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
            $pending = $this->get_pending_count();
            if ($pending > 0) {
                $this->theme->display_notification_badge($pending);
            }
        }

        if ($event->page_matches("upload", method: "GET")) {
            if (Ctx::$user->can(ImagePermission::CREATE_IMAGE)) {
                $this->theme->display_ingest_form(
                    Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)
                );
            }
        }

        if ($event->page_matches("gallerydl_ingest/submit", method: "POST", permission: ImagePermission::CREATE_IMAGE)) {
            if (Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
                $this->handle_direct_ingest();
            } else {
                $this->handle_queue_request();
            }
        }

        if ($event->page_matches("admin/gallerydl_queue", method: "GET", permission: AdminPermission::MANAGE_ADMINTOOLS)) {
            $this->show_queue_page();
        }

        if ($event->page_matches("admin/gallerydl_queue/approve", method: "POST", permission: AdminPermission::MANAGE_ADMINTOOLS)) {
            $this->handle_queue_approve();
        }

        if ($event->page_matches("admin/gallerydl_queue/reject", method: "POST", permission: AdminPermission::MANAGE_ADMINTOOLS)) {
            $this->handle_queue_reject();
        }
    }

    // -------------------------------------------------------------------------
    // Direct ingest — admin submits, runs immediately
    // -------------------------------------------------------------------------

    private function handle_direct_ingest(): void
    {
        $raw_url = trim((string)($_POST['ingest_url'] ?? ''));
        $title   = trim((string)($_POST['ingest_title'] ?? ''));
        $format  = ($_POST['ingest_format'] ?? 'pdf') === 'html' ? 'html' : 'pdf';
        $engine  = $this->safe_engine($_POST['ingest_engine'] ?? 'auto');

        if (!$this->is_safe_url($raw_url)) {
            Ctx::$page->flash("Ingest error: Invalid URL. Only http:// and https:// schemes are permitted.");
            Ctx::$page->set_redirect(make_link("upload"));
            return;
        }

        Ctx::$event_bus->set_timeout(null);
        Ctx::$database->set_timeout(null);
        $job_id = 'ingest_' . bin2hex(random_bytes(4));
        $host   = parse_url($raw_url, PHP_URL_HOST) ?: 'unknown';
        self::bg_start($job_id, "Ingest ({$engine}): {$host}", 600);
        [, $message] = $this->run_ingest($raw_url, $title, $format, $engine);
        self::bg_finish($job_id);
        Ctx::$page->flash($message);
        Ctx::$page->set_redirect(make_link("upload"));
    }

    // -------------------------------------------------------------------------
    // Queue request — user submits, waits for admin approval
    // -------------------------------------------------------------------------

    private function handle_queue_request(): void
    {
        $raw_url = trim((string)($_POST['ingest_url'] ?? ''));
        $title   = trim((string)($_POST['ingest_title'] ?? ''));
        $format  = ($_POST['ingest_format'] ?? 'pdf') === 'html' ? 'html' : 'pdf';
        $engine  = $this->safe_engine($_POST['ingest_engine'] ?? 'auto');

        if (!$this->is_safe_url($raw_url)) {
            Ctx::$page->flash("Invalid URL. Only http:// and https:// schemes are permitted.");
            Ctx::$page->set_redirect(make_link("upload"));
            return;
        }

        Ctx::$database->execute(
            "INSERT INTO gallerydl_queue (user_id, url, title, ingest_format, ingest_engine) VALUES (:user_id, :url, :title, :fmt, :eng)",
            ['user_id' => Ctx::$user->id, 'url' => $raw_url, 'title' => $title, 'fmt' => $format, 'eng' => $engine]
        );

        Log::info('gallerydl_ingest', 'Queue request from ' . Ctx::$user->name . ': ' . $raw_url);
        Ctx::$page->flash("Your ingest request has been submitted and is awaiting admin approval.");
        Ctx::$page->set_redirect(make_link("upload"));
    }

    // -------------------------------------------------------------------------
    // Admin queue management
    // -------------------------------------------------------------------------

    private function show_queue_page(): void
    {
        $items = Ctx::$database->get_all("
            SELECT q.*, u.name AS username
            FROM gallerydl_queue q
            JOIN users u ON q.user_id = u.id
            ORDER BY
                CASE q.status WHEN 'pending' THEN 0 ELSE 1 END ASC,
                q.created_at DESC
            LIMIT 100
        ");
        $this->theme->display_queue_page($items);
    }

    private function handle_queue_approve(): void
    {
        $id = (int)($_POST['queue_id'] ?? 0);
        if ($id <= 0) {
            Ctx::$page->set_redirect(make_link("admin/gallerydl_queue"));
            return;
        }

        $row = Ctx::$database->get_row(
            "SELECT * FROM gallerydl_queue WHERE id = :id AND status = 'pending'",
            ['id' => $id]
        );
        if (!$row) {
            Ctx::$page->flash("Request not found or already processed.");
            Ctx::$page->set_redirect(make_link("admin/gallerydl_queue"));
            return;
        }

        $fmt = ((string)($row['ingest_format'] ?? 'pdf')) === 'html' ? 'html' : 'pdf';
        $eng = $this->safe_engine((string)($row['ingest_engine'] ?? 'auto'));
        Ctx::$event_bus->set_timeout(null);
        Ctx::$database->set_timeout(null);
        $job_id = 'ingest_q' . $id;
        $host   = parse_url((string)$row['url'], PHP_URL_HOST) ?: 'unknown';
        self::bg_start($job_id, "Queue ingest ({$eng}): {$host}", 600);
        [$ok, $message] = $this->run_ingest((string)$row['url'], (string)$row['title'], $fmt, $eng);
        self::bg_finish($job_id);

        Ctx::$database->execute(
            "UPDATE gallerydl_queue
             SET status = :status, reviewed_at = CURRENT_TIMESTAMP,
                 reviewer_id = :reviewer_id, result_message = :msg
             WHERE id = :id",
            [
                'status'      => $ok === 'ok' ? 'done' : 'failed',
                'reviewer_id' => Ctx::$user->id,
                'msg'         => $message,
                'id'          => $id,
            ]
        );

        Ctx::$page->flash($message);
        Ctx::$page->set_redirect(make_link("admin/gallerydl_queue"));
    }

    private function handle_queue_reject(): void
    {
        $id = (int)($_POST['queue_id'] ?? 0);
        if ($id <= 0) {
            Ctx::$page->set_redirect(make_link("admin/gallerydl_queue"));
            return;
        }

        $row = Ctx::$database->get_row(
            "SELECT * FROM gallerydl_queue WHERE id = :id AND status = 'pending'",
            ['id' => $id]
        );
        if (!$row) {
            Ctx::$page->flash("Request not found or already processed.");
            Ctx::$page->set_redirect(make_link("admin/gallerydl_queue"));
            return;
        }

        Ctx::$database->execute(
            "UPDATE gallerydl_queue
             SET status = 'rejected', reviewed_at = CURRENT_TIMESTAMP, reviewer_id = :reviewer_id
             WHERE id = :id",
            ['reviewer_id' => Ctx::$user->id, 'id' => $id]
        );

        Ctx::$page->flash("Request rejected.");
        Ctx::$page->set_redirect(make_link("admin/gallerydl_queue"));
    }

    private function get_pending_count(): int
    {
        try {
            return (int)Ctx::$database->get_one(
                "SELECT COUNT(*) FROM gallerydl_queue WHERE status = 'pending'"
            );
        } catch (\Exception $e) {
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Core ingest runner — returns ['ok'|'error', message]
    // -------------------------------------------------------------------------

    private function safe_engine(string $value): string
    {
        return in_array($value, ['auto', 'gallery-dl', 'yt-dlp', 'singlefile'], true) ? $value : 'auto';
    }

    /** @return array{string, string} */
    private function run_ingest(string $raw_url, string $title, string $format = 'pdf', string $engine = 'auto'): array
    {
        $tmp_dir = self::INGEST_TMP_BASE . '/' . bin2hex(random_bytes(8));

        try {
            if (!mkdir($tmp_dir, 0700, true) && !is_dir($tmp_dir)) {
                throw new \RuntimeException("Cannot create temporary directory: {$tmp_dir}");
            }

            if ($engine === 'gallery-dl') {
                $dl_output = $this->run_gallery_dl($raw_url, $tmp_dir);
            } elseif ($engine === 'yt-dlp') {
                $dl_output = $this->run_ytdlp($raw_url, $tmp_dir);
            } elseif ($engine === 'singlefile') {
                $dl_output = $this->run_singlefile($raw_url, $tmp_dir, $format);
            } else {
                // Auto: gallery-dl → yt-dlp → singlefile
                // Cascade on ANY failure OR on exit 0 with no files downloaded
                // (gallery-dl/yt-dlp may "succeed" silently on unsupported URLs).
                $engine = 'gallery-dl';
                try {
                    $dl_output = $this->run_gallery_dl($raw_url, $tmp_dir);
                    if (!$this->directory_has_files($tmp_dir)) {
                        throw new \RuntimeException("gallery-dl succeeded but downloaded no files.");
                    }
                } catch (\RuntimeException $e) {
                    Log::info('gallerydl_ingest', "gallery-dl failed ({$e->getMessage()}) — trying yt-dlp.");
                    $engine = 'yt-dlp';
                    try {
                        $dl_output = $this->run_ytdlp($raw_url, $tmp_dir);
                        if (!$this->directory_has_files($tmp_dir)) {
                            throw new \RuntimeException("yt-dlp succeeded but downloaded no files.");
                        }
                    } catch (\RuntimeException $ytdlp_err) {
                        Log::info('gallerydl_ingest', "yt-dlp failed ({$ytdlp_err->getMessage()}) — archiving with SingleFile.");
                        $engine    = 'singlefile';
                        $dl_output = $this->run_singlefile($raw_url, $tmp_dir, $format);
                    }
                }
            }

            Log::info('gallerydl_ingest', "[{$engine}] completed (" . count($dl_output) . " output lines).");
            $counts = $this->ingest_directory($tmp_dir, $raw_url, $title);

            $cfg_limit = (int)(Ctx::$config->get(UploadConfig::SIZE) ?? 0);
            $limit_str = $cfg_limit > 0 ? to_shorthand_int($cfg_limit) : 'the configured limit';
            if ($counts['success'] === 0 && $counts['oversized'] > 0 && $counts['failed'] === 0) {
                $noun = $counts['oversized'] === 1 ? 'file exceeds' : 'files exceed';
                $message = "Nothing was added — {$counts['oversized']} {$noun} the {$limit_str} size limit. "
                    . "An admin can raise the limit under Board Config → Upload → Max size per file.";
            } else {
                $oversized_note = $counts['oversized'] > 0
                    ? " ({$counts['oversized']} skipped — over the {$limit_str} size limit)"
                    : '';
                $error_detail = ($counts['failed'] > 0 && $counts['last_error'] !== '')
                    ? " Last error: " . htmlspecialchars($counts['last_error'], ENT_QUOTES, 'UTF-8')
                    : '';
                $message = "[{$engine}] Ingest complete — "
                    . "Ingested: {$counts['success']}, "
                    . "Duplicates skipped: {$counts['skipped']}"
                    . $oversized_note
                    . ", Errors: {$counts['failed']}."
                    . $error_detail;
            }

            return ['ok', $message];

        } catch (\Throwable $e) {
            try {
                Log::error('gallerydl_ingest', "Fatal ingest error: " . $e->getMessage());
            } catch (\Throwable) {
                // Ignore — request may have exceeded time limit before we could log
            }
            return ['error', "Ingest failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
        } finally {
            $this->cleanup_directory($tmp_dir);
        }
    }

    // -------------------------------------------------------------------------
    // URL validation
    // Three layers: non-empty → PHP URL filter → scheme whitelist.
    // The URL is additionally wrapped in escapeshellarg() before shell use.
    // -------------------------------------------------------------------------

    private function is_safe_url(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        return in_array($scheme, ['http', 'https'], true);
    }

    // -------------------------------------------------------------------------
    // SingleFile engine (last resort — archives any webpage as PDF)
    // Flow: SingleFile saves self-contained HTML → Chromium prints to PDF
    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function run_singlefile(string $url, string $output_dir, string $format = 'pdf'): array
    {
        $chromium = self::CHROMIUM_BIN;
        if (!is_executable($chromium)) {
            throw new \RuntimeException(
                "SingleFile requires Chromium ({$chromium}) — rebuild the Docker image with Chromium installed."
            );
        }

        if (!is_executable(self::SINGLEFILE_BIN)) {
            throw new \RuntimeException(
                "SingleFile CLI not found at " . self::SINGLEFILE_BIN . " — rebuild the Docker image."
            );
        }

        $html_file = $output_dir . '/webpage.html';
        // Per-ingest Chromium profile dirs in /tmp (not inside output_dir — the
        // ingest_directory scanner would otherwise try to ingest profile files).
        // www-data can write to /tmp; /var/www/.config/chromium is root-owned.
        $profile_suffix  = basename($output_dir);
        $sf_profile_dir  = '/tmp/chromium-sf-'  . $profile_suffix;
        $pdf_profile_dir = '/tmp/chromium-pdf-' . $profile_suffix;

        $browser_args = escapeshellarg(json_encode([
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--user-data-dir=' . $sf_profile_dir,
        ]));

        try {
            $cmd_sf = 'timeout ' . self::TIMEOUT_SINGLEFILE . ' ' . self::SINGLEFILE_BIN
                . ' --browser-executable-path ' . escapeshellarg($chromium)
                . ' --browser-args '            . $browser_args
                . ' --browser-wait-until=networkidle2'
                . ' --browser-timeout='         . self::BROWSER_TIMEOUT_MS
                . ' '                           . escapeshellarg($url)
                . ' '                           . escapeshellarg($html_file)
                . ' 2>&1';

            exec($cmd_sf, $sf_lines, $sf_exit);

            if ($sf_exit !== 0 || !file_exists($html_file)) {
                $tail = implode('; ', array_slice($sf_lines, -3));
                throw new \RuntimeException("SingleFile failed (exit {$sf_exit}): {$tail}");
            }

            // HTML format: keep the .html file as-is for ingestion by handle_html
            if ($format === 'html') {
                return $sf_lines;
            }

            // PDF format: convert the HTML to PDF then remove the HTML file
            $pdf_file = $output_dir . '/webpage.pdf';

            $cmd_pdf = 'timeout ' . self::TIMEOUT_CHROMIUM . ' ' . escapeshellarg($chromium)
                . ' --headless=new'
                . ' --no-sandbox'
                . ' --disable-setuid-sandbox'
                . ' --disable-gpu'
                . ' --disable-dev-shm-usage'
                . ' --user-data-dir=' . escapeshellarg($pdf_profile_dir)
                . ' --run-all-compositor-stages-before-draw'
                . ' --print-to-pdf=' . escapeshellarg($pdf_file)
                . ' '                . escapeshellarg('file://' . $html_file)
                . ' 2>&1';

            exec($cmd_pdf, $pdf_lines, $pdf_exit);

            @unlink($html_file);

            if ($pdf_exit !== 0 || !file_exists($pdf_file)) {
                $tail = implode('; ', array_slice($pdf_lines, -3));
                throw new \RuntimeException("PDF conversion failed (exit {$pdf_exit}): {$tail}");
            }

            return array_merge($sf_lines, $pdf_lines);

        } finally {
            $this->cleanup_directory($sf_profile_dir);
            $this->cleanup_directory($pdf_profile_dir);
        }
    }

    // -------------------------------------------------------------------------
    // gallery-dl runner (primary engine — 300+ sites)
    // -------------------------------------------------------------------------

    /** @return list<string> stdout/stderr lines from gallery-dl */
    private function run_gallery_dl(string $url, string $output_dir): array
    {
        if (!is_executable(self::GALLERY_DL_BIN)) {
            throw new \RuntimeException("gallery-dl binary not found at " . self::GALLERY_DL_BIN);
        }

        $safe_dir = escapeshellarg($output_dir);
        $safe_url = escapeshellarg($url);

        // --no-part : prevents incomplete .part files appearing as valid media.
        // --        : end-of-options sentinel; stops gallery-dl from treating
        //             the URL as a command-line flag (e.g. --exec injection).
        $command = 'timeout ' . self::TIMEOUT_GALLERY_DL . ' '
            . self::GALLERY_DL_BIN . " --no-part --write-metadata -d {$safe_dir} -- {$safe_url} 2>&1";

        exec($command, $output_lines, $exit_code);

        if ($exit_code === 64) {
            throw new \RuntimeException("UNSUPPORTED_URL: gallery-dl has no extractor for this URL.");
        }

        if ($exit_code !== 0) {
            if (!$this->directory_has_files($output_dir)) {
                $tail = implode('; ', array_slice($output_lines, -5));
                throw new \RuntimeException("gallery-dl failed (exit {$exit_code}): {$tail}");
            }
            Log::warning(
                'gallerydl_ingest',
                "gallery-dl exited {$exit_code} but files were downloaded — treating as partial success."
            );
        }

        return $output_lines;
    }

    // -------------------------------------------------------------------------
    // yt-dlp runner (fallback engine — video platforms and unsupported URLs)
    // -------------------------------------------------------------------------

    /** @return list<string> stdout/stderr lines from yt-dlp */
    private function run_ytdlp(string $url, string $output_dir): array
    {
        if (!is_executable(self::YTDLP_BIN)) {
            throw new \RuntimeException("yt-dlp binary not found at " . self::YTDLP_BIN);
        }

        $safe_dir = escapeshellarg($output_dir);
        $safe_url = escapeshellarg($url);

        // -P: set the download base path (avoids %-template shell expansion).
        $command = 'timeout ' . self::TIMEOUT_YTDLP . ' '
            . self::YTDLP_BIN . " --no-part --write-info-json -P {$safe_dir} -- {$safe_url} 2>&1";

        exec($command, $output_lines, $exit_code);

        if ($exit_code !== 0) {
            if (!$this->directory_has_files($output_dir)) {
                $tail = implode('; ', array_slice($output_lines, -5));
                throw new \RuntimeException("yt-dlp failed (exit {$exit_code}): {$tail}");
            }
            Log::warning(
                'gallerydl_ingest',
                "yt-dlp exited {$exit_code} but files were downloaded — treating as partial success."
            );
        }

        return $output_lines;
    }

    // -------------------------------------------------------------------------
    // Directory ingestion — feeds each file through Shimmie2's DataUploadEvent
    // pipeline, which handles MIME detection, thumbnail generation, dedup, and
    // the sha256_hash insertion.
    // -------------------------------------------------------------------------

    /** @return array{success: int, skipped: int, failed: int, last_error: string} */
    private function ingest_directory(string $dir, string $source_url, string $title = ''): array
    {
        $counts = ['success' => 0, 'skipped' => 0, 'oversized' => 0, 'failed' => 0, 'last_error' => ''];
        $size_limit = (int)(Ctx::$config->get(UploadConfig::SIZE) ?? 0);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file_info) {
            assert($file_info instanceof \SplFileInfo);

            if (!$file_info->isFile() || !$file_info->isReadable()) {
                continue;
            }

            $ext = strtolower($file_info->getExtension());
            if (in_array($ext, self::SKIP_EXTENSIONS, true)) {
                Log::info('gallerydl_ingest', "Skipped non-media file: " . $file_info->getFilename());
                continue;
            }

            $filepath  = $file_info->getPathname();
            $filename  = $file_info->getFilename();
            $file_size = $file_info->getSize();

            if ($size_limit > 0 && $file_size > $size_limit) {
                $size_str  = to_shorthand_int($file_size);
                $limit_str = to_shorthand_int($size_limit);
                Log::warning('gallerydl_ingest', "Skipped (too large) [{$filename}]: {$size_str} > {$limit_str}");
                $counts['oversized']++;
                continue;
            }

            try {
                $path    = new Path($filepath);
                $sidecar = $this->extract_sidecar_metadata($filepath);
                $effective_title = $title !== '' ? $title : $this->build_auto_title($sidecar);

                $meta_array = [
                    'tags'   => self::DEFAULT_TAGS,
                    'source' => $source_url,
                ];
                if ($effective_title !== '') {
                    $meta_array['title'] = $effective_title;
                }
                $metadata = new QueryArray($meta_array);

                $created_posts = [];
                // with_savepoint rolls back the DB transaction if anything throws,
                // preventing partial inserts.
                Ctx::$database->with_savepoint(
                    function () use ($path, $filename, $metadata, &$created_posts): void {
                        $event = send_event(new DataUploadEvent($path, $filename, 0, $metadata));
                        if (count($event->posts) === 0) {
                            throw new UploadException("No MIME handler for: {$event->mime}");
                        }
                        $created_posts = $event->posts;
                    }
                );

                // Store source text as post description
                if ($sidecar['description'] !== '' && !empty($created_posts)) {
                    foreach ($created_posts as $post) {
                        try {
                            send_event(new PostDescriptionSetEvent($post->id, $sidecar['description']));
                        } catch (\Throwable $e) {
                            Log::warning('gallerydl_ingest', "Could not set description for post {$post->id}: " . $e->getMessage());
                        }
                    }
                }

                $counts['success']++;
                Log::info('gallerydl_ingest', "Ingested: {$filename}");

            } catch (UploadException $e) {
                if (str_contains($e->getMessage(), 'already has hash')) {
                    $counts['skipped']++;
                } else {
                    $counts['failed']++;
                    $counts['last_error'] = $e->getMessage();
                    error_log("gallerydl_ingest UploadException [{$filename}]: " . $e->getMessage());
                }
            } catch (\Throwable $e) {
                $counts['failed']++;
                $counts['last_error'] = get_class($e) . ': ' . $e->getMessage();
                error_log("gallerydl_ingest Throwable [{$filename}]: " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        return $counts;
    }

    // -------------------------------------------------------------------------
    // Sidecar metadata extraction
    // -------------------------------------------------------------------------

    /** @return array{username: string, date: string, platform: string, description: string} */
    private function extract_sidecar_metadata(string $media_path): array
    {
        $meta = ['username' => '', 'date' => '', 'platform' => '', 'description' => ''];

        // gallery-dl appends .json to the full filename: image.jpg → image.jpg.json
        // yt-dlp uses {stem}.info.json; fallback to {stem}.json
        $stem = (string)preg_replace('/\.[^.]+$/', '', $media_path);
        $json_path = null;
        foreach (["{$media_path}.json", "{$stem}.info.json", "{$stem}.json"] as $candidate) {
            if (is_readable($candidate)) {
                $json_path = $candidate;
                break;
            }
        }
        if ($json_path === null) {
            return $meta;
        }

        $data = json_decode((string)file_get_contents($json_path), true);
        if (!is_array($data)) {
            return $meta;
        }

        // Platform
        $category = (string)($data['category'] ?? $data['extractor'] ?? '');
        $meta['platform'] = $this->normalize_platform($category);

        // Username — try common field names, including nested objects
        foreach (['username', 'uploader', 'author', 'owner', 'creator', 'poster', 'channel'] as $key) {
            if (empty($data[$key])) {
                continue;
            }
            $val = $data[$key];
            if (is_string($val) && $val !== '') {
                $meta['username'] = $val;
                break;
            }
            if (is_array($val)) {
                foreach (['name', 'nick', 'username', 'login', 'display_name'] as $sub) {
                    if (!empty($val[$sub]) && is_string($val[$sub])) {
                        $meta['username'] = $val[$sub];
                        break 2;
                    }
                }
            }
        }

        // Date
        foreach (['date', 'upload_date', 'created_at', 'date_upload', 'published_at', 'timestamp', 'upload_timestamp'] as $key) {
            if (empty($data[$key])) {
                continue;
            }
            $val = $data[$key];
            if (is_string($val)) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) {
                    $meta['date'] = substr($val, 0, 10);
                    break;
                }
                $ts = strtotime($val);
                if ($ts !== false) {
                    $meta['date'] = date('Y-m-d', $ts);
                    break;
                }
            } elseif (is_int($val) || is_float($val)) {
                $meta['date'] = date('Y-m-d', (int)$val);
                break;
            }
        }

        // Description / post text — 'content' is gallery-dl's field for tweet/post body
        foreach (['content', 'description', 'body', 'caption', 'text', 'comment', 'selftext', 'tweet', 'note'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                $meta['description'] = trim($data[$key]);
                break;
            }
        }

        return $meta;
    }

    private function normalize_platform(string $category): string
    {
        if ($category === '') {
            return '';
        }
        return match (strtolower($category)) {
            'instagram'           => 'Instagram',
            'twitter', 'x'       => 'Twitter/X',
            'danbooru'            => 'Danbooru',
            'reddit'              => 'Reddit',
            'pixiv'               => 'Pixiv',
            'flickr'              => 'Flickr',
            'deviantart'          => 'DeviantArt',
            'tumblr'              => 'Tumblr',
            'youtube'             => 'YouTube',
            'tiktok'              => 'TikTok',
            'artstation'          => 'ArtStation',
            'singlefile'          => 'Web Archive',
            'bluesky', 'bsky'    => 'Bluesky',
            'weibo'               => 'Weibo',
            'mastodon'            => 'Mastodon',
            default               => ucfirst($category),
        };
    }

    /** @param array{username: string, date: string, platform: string, description: string} $meta */
    private function build_auto_title(array $meta): string
    {
        $parts = array_filter([$meta['username'], $meta['date'], $meta['platform']], fn ($p) => $p !== '');
        return empty($parts) ? '' : '[' . implode(' · ', $parts) . ']';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function directory_has_files(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            assert($f instanceof \SplFileInfo);
            if ($f->isFile()) {
                return true;
            }
        }
        return false;
    }

    private function cleanup_directory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file_info) {
            assert($file_info instanceof \SplFileInfo);
            $file_info->isDir()
                ? rmdir($file_info->getPathname())
                : unlink($file_info->getPathname());
        }

        rmdir($dir);
    }

    // ── Background job status helpers ────────────────────────────────────────

    private static function bg_start(string $id, string $label, int $timeout = 600): void
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
}
