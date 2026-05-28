<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

class NetworkOpsTheme extends Themelet
{
    // ── Analytics dashboard ──────────────────────────────────────────────────

    /** @param array<string, mixed> $s */
    public function display_analytics(array $s): void
    {
        $hostname  = htmlspecialchars(php_uname('n'), ENT_QUOTES, 'UTF-8');
        $now       = (new \DateTime('now', new \DateTimeZone('Asia/Kuala_Lumpur')))->format('F j, Y — g:i:s A') . ' MYT';

        $fmt_bytes = static function (int $b): string {
            if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
            if ($b >= 1048576)   return round($b / 1048576, 2) . ' MB';
            if ($b >= 1024)      return round($b / 1024, 1) . ' KB';
            return $b . ' B';
        };

        $local_posts = number_format((int)$s['local_posts']);
        $local_size  = $fmt_bytes((int)$s['local_size']);
        $total_syncs = count($s['sessions']);
        $total_files = number_format((int)$s['total_files']);
        $total_bytes = $fmt_bytes((int)$s['total_bytes']);

        $last = $s['last_sync'];
        if ($last) {
            $last_date  = htmlspecialchars(substr((string)$last['date'], 0, 16), ENT_QUOTES, 'UTF-8');
            $last_files = number_format($last['files']) . ' files';
            $last_vol   = $fmt_bytes($last['bytes']);
        } else {
            $last_date  = 'Never';
            $last_files = '—';
            $last_vol   = '—';
        }

        $is_mirror = (bool)($s['is_mirror'] ?? false);

        // ── Topology: mirror-node view (receiving) ────────────────────────────
        if ($is_mirror) {
            $receipt    = is_array($s['receipt'] ?? null) ? $s['receipt'] : null;
            $recv_src   = htmlspecialchars((string)($receipt['source']    ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
            $recv_at    = htmlspecialchars((string)($receipt['synced_at'] ?? '—'),       ENT_QUOTES, 'UTF-8');
            $recv_st    = (string)($receipt['status'] ?? '');
            $recv_files = number_format((int)($receipt['files_count'] ?? 0));
            $recv_bytes = (int)($receipt['bytes'] ?? 0);
            $recv_size  = $recv_bytes >= 1048576
                ? round($recv_bytes / 1048576, 2) . ' MB'
                : ($recv_bytes >= 1024 ? round($recv_bytes / 1024, 1) . ' KB' : $recv_bytes . ' B');

            $kpi1_label = 'Sync Source';
            $kpi1_val   = $receipt ? $recv_src : '<span style="color:#94A3B8;font-style:italic;font-size:.85rem">No receipt yet</span>';
            $kpi1_sub   = $receipt
                ? '<span class="na-badge ' . ($recv_st === 'ok' ? 'na-online' : 'na-offline') . '">'
                  . ($recv_st === 'ok' ? '✓ Last push OK' : '✗ Last push failed') . '</span>'
                : '';
            $kpi2_label = 'Last Received';
            $kpi2_val   = $receipt ? '<span style="font-size:.88rem">' . htmlspecialchars(substr($recv_at, 0, 16), ENT_QUOTES, 'UTF-8') . '</span>' : '—';
            $kpi2_sub   = $receipt ? $recv_files . ' files &nbsp;·&nbsp; ' . $recv_size : 'Waiting for master push';
            $mirror_kpi = '';

            $topo_html = <<<HTML
  <div class="na-topo">
    <div class="na-topo-node">
      <div class="na-node-box na-node-master">
        <div class="na-node-icon">🖥️</div>
        <div class="na-node-name">Master Node</div>
        <div class="na-node-host">{$recv_src}</div>
      </div>
    </div>
    <div class="na-topo-link na-topo-link--up">
      <div class="na-link-proto">RSYNC</div>
      <div class="na-link-arrow">──────▶</div>
      <div class="na-link-status">{$recv_at}</div>
    </div>
    <div class="na-topo-node">
      <div class="na-node-box na-node-mirror" style="border-color:var(--mb-accent,#6366F1)">
        <div class="na-node-icon">🖥️</div>
        <div class="na-node-name">This Node (Mirror)</div>
        <div class="na-node-host">{$hostname}</div>
      </div>
    </div>
  </div>
HTML;
            $latency_html = <<<HTML
  <div class="na-chart-full" style="margin-bottom:1rem;display:flex;align-items:center;gap:1rem;padding:1.25rem 1.5rem;flex-wrap:wrap">
    <div style="font-size:2rem;line-height:1;flex-shrink:0">📥</div>
    <div>
      <div class="na-chart-title">Incoming Sync — Mirror Mode</div>
      <div class="na-chart-sub" style="margin-top:.3rem">
        This node receives data from master. Last push: <strong>{$recv_at}</strong> from <strong>{$recv_src}</strong>.
      </div>
    </div>
  </div>
HTML;
        } else {
            // ── Master node: show all mirrors in topology ─────────────────────
            /** @var array<int, array{host: string, probe: array{configured: bool, online: bool, latency_ms: ?float}}> */
            $mirrors_data = $s['mirrors'] ?? [];
            $online_count = count(array_filter($mirrors_data, fn($m) => $m['probe']['online']));
            $total_count  = count($mirrors_data);

            $kpi1_label = 'Mirrors';
            $kpi1_val   = (string)$total_count;
            $kpi1_sub   = '<span class="na-badge ' . ($online_count > 0 ? 'na-online' : ($total_count > 0 ? 'na-offline' : 'na-nc')) . '">'
                . $online_count . ' / ' . $total_count . ' online</span>';
            $kpi2_label = 'Best Latency';
            $lat_vals   = array_filter(array_column(array_column($mirrors_data, 'probe'), 'latency_ms'));
            $best_lat   = count($lat_vals) > 0 ? min($lat_vals) : null;
            $kpi2_val   = $best_lat !== null ? '<span class="' . ($best_lat < 50 ? 'na-lat-good' : ($best_lat < 200 ? 'na-lat-warn' : 'na-lat-bad')) . '">' . $best_lat . ' ms</span>' : '—';
            $kpi2_sub   = 'port 80 · 2s timeout';

            // Multi-mirror KPI chip (counts)
            $mirror_kpi = '';

            // Build topology rows (one per mirror)
            $mirror_rows = '';
            if (empty($mirrors_data)) {
                $mirror_rows = '<div class="na-topo-node"><div class="na-node-box" style="border-color:#94A3B8;color:#94A3B8;font-size:.8rem;padding:.75rem 1rem">No mirrors configured</div></div>';
            } else {
                foreach ($mirrors_data as $md) {
                    $mh    = htmlspecialchars($md['host'], ENT_QUOTES, 'UTF-8');
                    $probe = $md['probe'];
                    if ($probe['online']) {
                        $link_cls  = 'na-topo-link--up';
                        $lbl       = '● LIVE · ' . $probe['latency_ms'] . ' ms';
                        $node_cls  = 'na-node-mirror';
                    } else {
                        $link_cls  = 'na-topo-link--down';
                        $lbl       = '● DOWN';
                        $node_cls  = 'na-node-mirror na-node-down';
                    }
                    $mirror_rows .= <<<ROW
    <div class="na-topo-link {$link_cls}">
      <div class="na-link-proto">RSYNC</div>
      <div class="na-link-arrow">──────▶</div>
      <div class="na-link-status">{$lbl}</div>
    </div>
    <div class="na-topo-node">
      <div class="na-node-box {$node_cls}">
        <div class="na-node-icon">🖥️</div>
        <div class="na-node-name">Mirror</div>
        <div class="na-node-host">{$mh}</div>
      </div>
    </div>
ROW;
                }
            }

            $topo_html = <<<HTML
  <div class="na-topo" style="flex-wrap:wrap">
    <div class="na-topo-node">
      <div class="na-node-box na-node-master">
        <div class="na-node-icon">🖥️</div>
        <div class="na-node-name">Master Node</div>
        <div class="na-node-host">{$hostname}</div>
      </div>
    </div>
    {$mirror_rows}
  </div>
HTML;

            // Live probe table (refreshes via JS)
            $latency_html = <<<HTML
  <div class="na-chart-full" style="margin-bottom:1rem">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem">
      <div>
        <div class="na-chart-title">Mirror Node Probes — Live</div>
        <div class="na-chart-sub">All mirrors probed every 3 s &nbsp;&bull;&nbsp; TCP port 80</div>
      </div>
      <div style="display:flex;align-items:center;gap:.5rem;font-size:.7rem;font-weight:700;letter-spacing:.05em;color:#64748B">
        <span id="na-lat-dot" style="width:8px;height:8px;border-radius:50%;background:#94A3B8;display:inline-block;animation:na-pulse 1.6s ease-in-out infinite;flex-shrink:0"></span>
        LIVE
      </div>
    </div>
    <table class="na-table" id="na-probe-table">
      <thead><tr><th>Mirror Host</th><th>Status</th><th>Latency</th></tr></thead>
      <tbody id="na-probe-tbody"><tr><td colspan="3" class="na-empty-td">Loading&hellip;</td></tr></tbody>
    </table>
  </div>
HTML;
        }

        $j_is_mirror   = $is_mirror ? 'true' : 'false';
        $j_uploads     = json_encode($s['upload_slots'],  JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $j_comments    = json_encode($s['comment_slots'], JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $j_sessions    = json_encode($s['sessions'],      JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $j_latency_url = json_encode((string)make_link('network/latency'), JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        $html = <<<HTML
<style>
.na-wrap{font-size:.875rem;color:var(--mb-text,#1E293B)}
.na-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem}
.na-header h2{font-size:1.5rem;font-weight:700;margin:0 0 .25rem;letter-spacing:-.02em;color:var(--mb-text,#1E293B)}
.na-header p{font-size:.78rem;color:var(--mb-text-3,#94A3B8);margin:0}
.na-refresh-btn{background:var(--mb-accent,#6366F1);color:#fff;border:none;padding:.45rem 1rem;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;white-space:nowrap}
.na-refresh-btn:hover{background:var(--mb-accent-hover,#4F46E5)}
.na-filter-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;padding:.875rem 1.25rem;margin-bottom:1.25rem}
.na-pills{display:flex;gap:.35rem;flex-wrap:wrap}
.na-pill{background:transparent;border:1px solid var(--mb-border,#E2E8F0);color:var(--mb-text-2,#475569);padding:.25rem .7rem;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .12s}
.na-pill:hover{border-color:var(--mb-accent,#6366F1);color:var(--mb-accent,#6366F1)}
.na-pill--active{background:var(--mb-accent,#6366F1);border-color:var(--mb-accent,#6366F1);color:#fff}
.na-divider{width:1px;height:1.5rem;background:var(--mb-border,#E2E8F0);flex-shrink:0}
.na-custom-range{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.na-custom-range span{font-size:.75rem;color:var(--mb-text-3,#94A3B8)}
.na-dt{padding:.25rem .6rem;border:1px solid var(--mb-border,#E2E8F0);border-radius:6px;font-size:.78rem;color:var(--mb-text,#1E293B);background:#fff}
.na-apply{background:#0EA5E9;color:#fff;border:none;padding:.25rem .75rem;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer}
.na-apply:hover{background:#0284C7}
.na-res{font-size:.72rem;color:var(--mb-text-3,#94A3B8);margin-left:auto;white-space:nowrap}
.na-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:1rem;margin-bottom:1.25rem}
.na-kpi{background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;padding:1.1rem 1.25rem}
.na-kpi-label{font-size:.72rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--mb-text-3,#94A3B8);margin-bottom:.4rem}
.na-kpi-val{font-size:1.45rem;font-weight:700;color:var(--mb-text,#1E293B);line-height:1.1}
.na-kpi-sub{font-size:.75rem;color:var(--mb-text-3,#94A3B8);margin-top:.2rem}
.na-badge{display:inline-block;padding:.2rem .65rem;border-radius:9999px;font-size:.7rem;font-weight:700;letter-spacing:.04em;margin-top:.35rem}
.na-online{background:#DCFCE7;color:#15803D}
.na-offline{background:#FEE2E2;color:#DC2626}
.na-nc{background:#F1F5F9;color:#64748B}
.na-pulse{display:inline-block;width:8px;height:8px;background:#22C55E;border-radius:50%;vertical-align:middle;margin-right:.35rem;animation:na-pulse 1.6s ease-in-out infinite}
@keyframes na-pulse{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.6)}50%{box-shadow:0 0 0 6px rgba(34,197,94,0)}}
.na-lat-good{color:#16A34A}.na-lat-warn{color:#D97706}.na-lat-bad{color:#DC2626}.na-lat-na{color:var(--mb-text-3,#94A3B8)}
.na-topo{display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;padding:1.5rem 2rem;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem}
.na-topo-node{text-align:center}
.na-node-box{border:2px solid var(--mb-border,#E2E8F0);border-radius:10px;padding:.75rem 1.5rem;min-width:120px}
.na-node-master{border-color:var(--mb-accent,#6366F1);background:var(--mb-accent-light,#EEF2FF)}
.na-node-mirror{border-color:#0EA5E9;background:#F0F9FF}
.na-node-down{border-color:#EF4444!important;background:#FFF5F5}
.na-node-icon{font-size:1.6rem;margin-bottom:.25rem}
.na-node-name{font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--mb-text-3,#94A3B8)}
.na-node-host{font-size:.82rem;font-weight:600;color:var(--mb-text,#1E293B);margin-top:.1rem;word-break:break-all}
.na-topo-link{display:flex;flex-direction:column;align-items:center;padding:0 1rem;gap:.2rem}
.na-topo-link--up .na-link-arrow{color:#22C55E}
.na-topo-link--down .na-link-arrow{color:#EF4444}
.na-topo-link--up .na-link-status{color:#16A34A}
.na-topo-link--down .na-link-status{color:#DC2626}
.na-link-proto{font-size:.65rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mb-text-3,#94A3B8)}
.na-link-arrow{font-size:1.2rem;line-height:1}
.na-link-status{font-size:.7rem;font-weight:700}
.na-chart-full{background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1rem}
.na-charts-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem}
@media(max-width:680px){.na-charts-row{grid-template-columns:1fr}}
.na-chart-card{background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;padding:1.25rem 1.5rem}
.na-chart-title{font-size:.85rem;font-weight:700;color:var(--mb-text,#1E293B)}
.na-chart-sub{font-size:.72rem;color:var(--mb-text-3,#94A3B8);margin:.1rem 0 .85rem}
.na-lifetime{display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
.na-ls{flex:1;min-width:140px;background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;padding:1rem 1.25rem;text-align:center}
.na-ls-val{font-size:1.35rem;font-weight:700;color:var(--mb-text,#1E293B)}
.na-ls-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--mb-text-3,#94A3B8);margin-top:.2rem}
.na-sec-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mb-text-3,#94A3B8);margin:0 0 .6rem;display:flex;align-items:center;gap:.5rem}
.na-sec-badge{background:var(--mb-accent-light,#EEF2FF);color:var(--mb-accent,#6366F1);font-size:.7rem;padding:.1rem .45rem;border-radius:9999px}
.na-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;overflow:hidden;font-size:.82rem}
.na-table th{padding:.6rem 1rem;text-align:left;background:var(--mb-card-bg,#F8FAFC);font-weight:600;color:var(--mb-text-2,#475569);border-bottom:1px solid var(--mb-border,#E2E8F0)}
.na-table td{padding:.55rem 1rem;border-bottom:1px solid var(--mb-border,#E2E8F0);color:var(--mb-text,#1E293B)}
.na-table tr:last-child td{border-bottom:none}
.na-table tr:hover td{background:var(--mb-accent-light,#EEF2FF)}
.na-empty-td{text-align:center;color:var(--mb-text-3,#94A3B8);padding:1.5rem!important}
</style>

<div class="na-wrap">

  <!-- Header -->
  <div class="na-header">
    <div>
      <h2>Network Analytics</h2>
      <p>Probed: {$now} &nbsp;·&nbsp; Auto-refresh in <strong id="na-cd">30</strong>s</p>
    </div>
    <button class="na-refresh-btn" onclick="location.reload()">&#8635; Refresh Now</button>
  </div>

  <!-- Filter bar -->
  <div class="na-filter-bar">
    <div class="na-pills">
      <button class="na-pill" data-preset="30m">30m</button>
      <button class="na-pill" data-preset="1h">1h</button>
      <button class="na-pill" data-preset="3h">3h</button>
      <button class="na-pill" data-preset="6h">6h</button>
      <button class="na-pill" data-preset="12h">12h</button>
      <button class="na-pill na-pill--active" data-preset="24h">24h</button>
      <button class="na-pill" data-preset="7d">7d</button>
      <button class="na-pill" data-preset="30d">30d</button>
    </div>
    <div class="na-divider"></div>
    <div class="na-custom-range">
      <input type="datetime-local" id="na-from" class="na-dt" step="1800">
      <span>to</span>
      <input type="datetime-local" id="na-to" class="na-dt" step="1800">
      <button id="na-apply-btn" class="na-apply">Apply</button>
    </div>
    <span class="na-res" id="na-resolution">Resolution: —</span>
  </div>

  <!-- KPI cards -->
  <div class="na-kpi-grid">
    <div class="na-kpi">
      <div class="na-kpi-label">{$kpi1_label}</div>
      <div class="na-kpi-val">{$kpi1_val}</div>
      <div>{$kpi1_sub}</div>
    </div>
    <div class="na-kpi">
      <div class="na-kpi-label">{$kpi2_label}</div>
      <div class="na-kpi-val">{$kpi2_val}</div>
      <div class="na-kpi-sub">{$kpi2_sub}</div>
    </div>
    <div class="na-kpi">
      <div class="na-kpi-label">Uploads in Range</div>
      <div class="na-kpi-val" id="na-kpi-uploads">—</div>
      <div class="na-kpi-sub" id="na-kpi-comments-sub">— comments</div>
    </div>
    <div class="na-kpi">
      <div class="na-kpi-label">Syncs in Range</div>
      <div class="na-kpi-val" id="na-kpi-syncs">—</div>
      <div class="na-kpi-sub">matching sessions</div>
    </div>
    <div class="na-kpi">
      <div class="na-kpi-label">Archive</div>
      <div class="na-kpi-val">{$local_posts}</div>
      <div class="na-kpi-sub">{$local_size} on disk</div>
    </div>
    <div class="na-kpi">
      <div class="na-kpi-label">Last Sync</div>
      <div class="na-kpi-val" style="font-size:1rem;">{$last_date}</div>
      <div class="na-kpi-sub">{$last_files} &nbsp;·&nbsp; {$last_vol}</div>
    </div>
  </div>

  <!-- Topology -->
  {$topo_html}

  <!-- Live probe / mirror incoming summary -->
  {$latency_html}

  <!-- Activity chart -->
  <div class="na-chart-full">
    <div class="na-chart-title">Upload &amp; Comment Activity</div>
    <div class="na-chart-sub">Posts uploaded and comments posted per time bucket</div>
    <canvas id="na-act-chart"></canvas>
  </div>

  <!-- Sync + Volume row -->
  <div class="na-charts-row">
    <div class="na-chart-card">
      <div class="na-chart-title">Sync Operations</div>
      <div class="na-chart-sub">RSYNC sessions per bucket</div>
      <canvas id="na-sync-chart"></canvas>
    </div>
    <div class="na-chart-card">
      <div class="na-chart-title">Transfer Volume</div>
      <div class="na-chart-sub">Megabytes transferred per bucket</div>
      <canvas id="na-vol-chart"></canvas>
    </div>
  </div>

  <!-- Lifetime stats -->
  <div class="na-lifetime">
    <div class="na-ls"><div class="na-ls-val">{$total_syncs}</div><div class="na-ls-label">Total Syncs (All-time)</div></div>
    <div class="na-ls"><div class="na-ls-val">{$total_files}</div><div class="na-ls-label">Files Synced (All-time)</div></div>
    <div class="na-ls"><div class="na-ls-val">{$total_bytes}</div><div class="na-ls-label">Data Transferred (All-time)</div></div>
    <div class="na-ls"><div class="na-ls-val">{$local_posts}</div><div class="na-ls-label">Total Posts</div></div>
  </div>

  <!-- Sessions in range -->
  <div class="na-sec-title">
    Sync Sessions in Range
    <span class="na-sec-badge" id="na-sess-count">0</span>
  </div>
  <table class="na-table">
    <thead><tr><th>Timestamp</th><th>Files</th><th>Volume</th></tr></thead>
    <tbody id="na-sessions-tbody">
      <tr><td colspan="3" class="na-empty-td">Loading&hellip;</td></tr>
    </tbody>
  </table>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function () {
  'use strict';

  const MY_OFFSET_MS = 8 * 3600 * 1000;
  const IS_MIRROR    = {$j_is_mirror};
  const LAT_URL      = {$j_latency_url};

  const DATA = {
    uploadSlots:  {$j_uploads},
    commentSlots: {$j_comments},
    syncSessions: {$j_sessions}
  };

  const parseTs = s => new Date(s.replace(' ', 'T') + 'Z').getTime();

  const fmtBytes = b => {
    b = +b;
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576)    return (b / 1048576).toFixed(2) + ' MB';
    if (b >= 1024)       return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
  };

  const fmtNum = n => (+n).toLocaleString();

  const fmtBucket = (ms, bucketMins) => {
    const d  = new Date(ms + MY_OFFSET_MS);
    const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getUTCMonth()];
    const dy = d.getUTCDate();
    const hh = String(d.getUTCHours()).padStart(2, '0');
    const mm = String(d.getUTCMinutes()).padStart(2, '0');
    return bucketMins >= 1440 ? (mo + ' ' + dy) : (mo + ' ' + dy + ' ' + hh + ':' + mm);
  };

  const toLocalInput = ms => {
    const d = new Date(ms + MY_OFFSET_MS);
    const p = n => String(n).padStart(2, '0');
    return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()) + 'T' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes());
  };

  function buildData(startMs, endMs) {
    const rangeMins  = (endMs - startMs) / 60000;
    const bucketMins = rangeMins <= 180 ? 30 : rangeMins <= 1440 ? 60 : rangeMins <= 10080 ? 240 : 1440;
    const bucketMs   = bucketMins * 60000;
    const numBuckets = Math.max(1, Math.ceil((endMs - startMs) / bucketMs));

    const labels   = [];
    const uploads  = new Array(numBuckets).fill(0);
    const comments = new Array(numBuckets).fill(0);
    const syncs    = new Array(numBuckets).fill(0);
    const rawVols  = new Array(numBuckets).fill(0);

    for (let i = 0; i < numBuckets; i++) {
      labels.push(fmtBucket(startMs + i * bucketMs, bucketMins));
    }

    for (const [key, cnt] of Object.entries(DATA.uploadSlots)) {
      const ts = parseTs(key);
      if (ts < startMs || ts >= endMs) continue;
      const idx = Math.floor((ts - startMs) / bucketMs);
      if (idx >= 0 && idx < numBuckets) uploads[idx] += cnt;
    }

    for (const [key, cnt] of Object.entries(DATA.commentSlots)) {
      const ts = parseTs(key);
      if (ts < startMs || ts >= endMs) continue;
      const idx = Math.floor((ts - startMs) / bucketMs);
      if (idx >= 0 && idx < numBuckets) comments[idx] += cnt;
    }

    const filteredSessions = [];
    for (const sess of DATA.syncSessions) {
      const ts = parseTs(sess.date);
      if (ts < startMs || ts >= endMs) continue;
      const idx = Math.floor((ts - startMs) / bucketMs);
      if (idx >= 0 && idx < numBuckets) {
        syncs[idx]++;
        rawVols[idx] += sess.bytes;
      }
      filteredSessions.push(sess);
    }
    filteredSessions.reverse();

    return { labels, uploads, comments, syncs,
             vols:        rawVols.map(b => +(b / 1048576).toFixed(2)),
             kpiUploads:  uploads.reduce((a, b) => a + b, 0),
             kpiComments: comments.reduce((a, b) => a + b, 0),
             kpiSyncs:    filteredSessions.length,
             sessions:    filteredSessions, bucketMins, numBuckets };
  }

  const accent  = (getComputedStyle(document.documentElement).getPropertyValue('--mb-accent') || '').trim() || '#6366F1';
  const gridClr = '#F1F5F9';
  const scaleX  = { grid: { color: gridClr }, ticks: { font: { size: 10 }, maxRotation: 45, autoSkip: true, maxTicksLimit: 12 } };
  const scaleY  = { grid: { color: gridClr }, ticks: { font: { size: 10 } }, beginAtZero: true };

  let actChart, syncChart, volChart;

  function createCharts() {
    actChart = new Chart(document.getElementById('na-act-chart'), {
      type: 'line',
      data: { labels: [], datasets: [
        { label: 'Uploads',  data: [], borderColor: accent,    backgroundColor: accent + '33',    fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5 },
        { label: 'Comments', data: [], borderColor: '#0EA5E9', backgroundColor: '#0EA5E933', fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5 },
      ]},
      options: { responsive: true, aspectRatio: 3.5,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: true, labels: { font: { size: 11 }, boxWidth: 12 } } },
        scales: { x: scaleX, y: scaleY } },
    });

    syncChart = new Chart(document.getElementById('na-sync-chart'), {
      type: 'bar',
      data: { labels: [], datasets: [
        { label: 'Syncs', data: [], backgroundColor: '#8B5CF666', borderColor: '#8B5CF6', borderWidth: 1, borderRadius: 3 },
      ]},
      options: { responsive: true, aspectRatio: 2.2,
        plugins: { legend: { display: false },
          tooltip: { callbacks: { label: ctx => ctx.parsed.y + ' sync' + (ctx.parsed.y !== 1 ? 's' : '') } } },
        scales: { x: scaleX, y: scaleY } },
    });

    volChart = new Chart(document.getElementById('na-vol-chart'), {
      type: 'line',
      data: { labels: [], datasets: [
        { label: 'MB', data: [], borderColor: '#10B981', backgroundColor: '#10B98133', fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5 },
      ]},
      options: { responsive: true, aspectRatio: 2.2,
        plugins: { legend: { display: false },
          tooltip: { callbacks: { label: ctx => ctx.parsed.y.toFixed(2) + ' MB' } } },
        scales: { x: scaleX, y: scaleY } },
    });
  }

  function updateAll(d) {
    const pr = d.numBuckets <= 48 ? 3 : 0;
    actChart.data.labels = syncChart.data.labels = volChart.data.labels = d.labels;
    actChart.data.datasets[0].data = d.uploads;
    actChart.data.datasets[0].pointRadius = pr;
    actChart.data.datasets[1].data = d.comments;
    actChart.data.datasets[1].pointRadius = pr;
    actChart.update('none');
    syncChart.data.datasets[0].data = d.syncs; syncChart.update('none');
    volChart.data.datasets[0].data = d.vols;
    volChart.data.datasets[0].pointRadius = pr;
    volChart.update('none');

    document.getElementById('na-kpi-uploads').textContent      = fmtNum(d.kpiUploads);
    document.getElementById('na-kpi-comments-sub').textContent = fmtNum(d.kpiComments) + ' comments';
    document.getElementById('na-kpi-syncs').textContent        = fmtNum(d.kpiSyncs);

    const resLabel = d.bucketMins >= 1440 ? '1-day' : d.bucketMins >= 240 ? '4-hour' :
                     d.bucketMins >= 60   ? '1-hour' : '30-min';
    document.getElementById('na-resolution').textContent =
      'Resolution: ' + resLabel + ' buckets (' + d.numBuckets + ' pts)';

    const countEl = document.getElementById('na-sess-count');
    if (countEl) countEl.textContent = d.sessions.length;

    const tbody = document.getElementById('na-sessions-tbody');
    if (d.sessions.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" class="na-empty-td">No sync sessions in selected range.</td></tr>';
    } else {
      const limit = Math.min(d.sessions.length, 25);
      let rows = '';
      for (let i = 0; i < limit; i++) {
        const sess = d.sessions[i];
        rows += '<tr><td>' + String(sess.date).substring(0, 16) + '</td><td>' + fmtNum(sess.files) + '</td><td>' + fmtBytes(sess.bytes) + '</td></tr>';
      }
      if (d.sessions.length > 25) {
        rows += '<tr><td colspan="3" class="na-empty-td">&hellip; and ' + (d.sessions.length - 25) + ' more</td></tr>';
      }
      tbody.innerHTML = rows;
    }
  }

  /* Live probe table (multi-mirror) */
  function fetchProbes() {
    fetch(LAT_URL, { headers: { 'Accept': 'application/json' } })
      .then(r => r.json())
      .then(d => {
        const tbody = document.getElementById('na-probe-tbody');
        if (!tbody || !Array.isArray(d.mirrors)) return;
        if (d.mirrors.length === 0) {
          tbody.innerHTML = '<tr><td colspan="3" class="na-empty-td">No mirrors configured on this node.</td></tr>';
          return;
        }
        let rows = '';
        for (const m of d.mirrors) {
          const cls = m.online
            ? 'style="color:#15803D;font-weight:600"'
            : 'style="color:#DC2626;font-weight:600"';
          const st  = m.online ? '● Online' : '● Offline';
          const lat = m.online && m.latency_ms !== null ? m.latency_ms + ' ms' : '—';
          rows += '<tr><td style="font-family:monospace">' + m.host + '</td><td ' + cls + '>' + st + '</td><td>' + lat + '</td></tr>';
        }
        tbody.innerHTML = rows;
        const dot = document.getElementById('na-lat-dot');
        if (dot) dot.style.background = '#22C55E';
      })
      .catch(() => {
        const dot = document.getElementById('na-lat-dot');
        if (dot) dot.style.background = '#EF4444';
      });
  }

  const PRESETS = { '30m':30,'1h':60,'3h':180,'6h':360,'12h':720,'24h':1440,'7d':10080,'30d':43200 };

  function applyPreset(key) {
    const mins = PRESETS[key];
    if (!mins) return;
    const endMs   = Date.now();
    const startMs = endMs - mins * 60000;
    document.getElementById('na-from').value = toLocalInput(startMs);
    document.getElementById('na-to').value   = toLocalInput(endMs);
    updateAll(buildData(startMs, endMs));
    document.querySelectorAll('.na-pill').forEach(el => {
      el.classList.toggle('na-pill--active', el.dataset.preset === key);
    });
  }

  function applyCustom() {
    const fromVal = document.getElementById('na-from').value;
    const toVal   = document.getElementById('na-to').value;
    if (!fromVal || !toVal) return;
    const startMs = new Date(fromVal + ':00Z').getTime() - MY_OFFSET_MS;
    const endMs   = new Date(toVal   + ':00Z').getTime() - MY_OFFSET_MS;
    if (isNaN(startMs) || isNaN(endMs) || endMs <= startMs) return;
    updateAll(buildData(startMs, endMs));
    document.querySelectorAll('.na-pill').forEach(el => el.classList.remove('na-pill--active'));
  }

  document.addEventListener('DOMContentLoaded', () => {
    createCharts();

    if (!IS_MIRROR) {
      fetchProbes();
      setInterval(fetchProbes, 3000);
    }

    applyPreset('24h');

    document.querySelectorAll('.na-pill').forEach(el => {
      el.addEventListener('click', () => applyPreset(el.dataset.preset));
    });
    document.getElementById('na-apply-btn').addEventListener('click', applyCustom);
    ['na-from', 'na-to'].forEach(id => {
      document.getElementById(id).addEventListener('keydown', e => {
        if (e.key === 'Enter') applyCustom();
      });
    });

    let secs = 30;
    const cd = document.getElementById('na-cd');
    setInterval(() => {
      if (cd) cd.textContent = --secs;
      if (secs <= 0) location.reload();
    }, 1000);
  });
})();
</script>
HTML;

        Ctx::$page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }

    // ── Operations page ──────────────────────────────────────────────────────

    /**
     * @param string[]                                                          $mirrors
     * @param array<string, array{configured: bool, online: bool, latency_ms: ?float}> $probes
     * @param array<string, mixed>|null                                         $receipt
     */
    public function display_ops(array $mirrors, array $probes, ?array $receipt = null): void
    {
        $save_action = make_link("admin/network_ops_save");
        $sync_action = make_link("admin/network_ops_sync");
        $token       = Ctx::$user->get_auth_token();
        $hostname    = htmlspecialchars(php_uname('n'), ENT_QUOTES, 'UTF-8');
        $self_ip     = htmlspecialchars((string)gethostbyname(gethostname()), ENT_QUOTES, 'UTF-8');
        $is_mirror   = count($mirrors) === 0;

        // ── Mirror list rows ──────────────────────────────────────────────────
        $mirror_rows = '';
        if (empty($mirrors)) {
            $mirror_rows = '<div class="no-empty">No mirrors configured yet.</div>';
        } else {
            foreach ($mirrors as $m) {
                $m_esc  = htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
                $probe  = $probes[$m] ?? ['configured' => true, 'online' => false, 'latency_ms' => null];
                if ($probe['online']) {
                    $st_cls = 'no-badge no-online';
                    $st_txt = '&#9679; Online &nbsp;·&nbsp; ' . $probe['latency_ms'] . ' ms';
                } else {
                    $st_cls = 'no-badge no-offline';
                    $st_txt = '&#9679; Offline';
                }
                $sync_disabled = $probe['online'] ? '' : 'disabled';
                $sync_confirm  = "return confirm('Force RSYNC push to {$m_esc}?');";
                $mirror_rows  .= <<<ROW
<div class="no-mirror-row">
  <div class="no-mirror-host">{$m_esc}</div>
  <span class="{$st_cls}">{$st_txt}</span>
  <div class="no-mirror-actions">
    <form method="POST" action="{$sync_action}" style="margin:0;display:inline">
      <input type="hidden" name="auth_token" value="{$token}">
      <input type="hidden" name="mirror_host" value="{$m_esc}">
      <button type="submit" class="no-btn no-btn-sync" {$sync_disabled}
              onclick="{$sync_confirm}">&#x21BB; Sync</button>
    </form>
    <form method="POST" action="{$save_action}" style="margin:0;display:inline">
      <input type="hidden" name="auth_token" value="{$token}">
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="mirror_host" value="{$m_esc}">
      <button type="submit" class="no-btn no-btn-remove"
              onclick="return confirm('Remove mirror {$m_esc}?')">&#10005; Remove</button>
    </form>
  </div>
</div>
ROW;
            }
        }

        // ── Force-sync-all button ─────────────────────────────────────────────
        $sync_all_disabled = empty($mirrors) ? 'disabled' : '';
        $sync_all_block    = count($mirrors) > 1
            ? <<<ALL
<form method="POST" action="{$sync_action}" style="margin-top:.75rem">
  <input type="hidden" name="auth_token" value="{$token}">
  <button type="submit" class="no-btn no-btn-sync" {$sync_all_disabled}
          onclick="return confirm('Force RSYNC push to ALL mirrors?')">
    &#x21BB; Sync All Mirrors
  </button>
</form>
ALL
            : '';

        // ── Role callout ──────────────────────────────────────────────────────
        if ($is_mirror) {
            $role_callout = <<<CALL
<div class="no-role-note no-role-mirror">
  <strong>Mirror node — no mirrors should be added here.</strong>
  This node receives sync from master. To pair, configure the master node's mirror list
  with this node's address:
  <code style="background:#FEF3C7;padding:.1rem .35rem;border-radius:4px;font-size:.82rem">{$self_ip}</code>
  &nbsp;<span style="font-size:.72rem;color:#92400E">(hostname: {$hostname})</span>
</div>
CALL;
        } else {
            $role_callout = <<<CALL
<div class="no-role-note no-role-master">
  <strong>Master node:</strong> RSYNC pushes from <em>this</em> node to each listed mirror.
  The auto-sync daemon (if started) pushes every 30 s automatically.
</div>
CALL;
        }

        // ── Receipt card (mirror only) ────────────────────────────────────────
        $receipt_html = '';
        if ($is_mirror) {
            if ($receipt === null) {
                $receipt_html = <<<'REC'
<div class="no-receipt">
  <h3>&#128229; Last Sync Received from Master</h3>
  <div class="no-receipt-none">No sync receipt on record yet. Waiting for master push.</div>
</div>
REC;
            } else {
                $r_at     = htmlspecialchars((string)($receipt['synced_at']  ?? '—'), ENT_QUOTES, 'UTF-8');
                $r_src    = htmlspecialchars((string)($receipt['source']     ?? '—'), ENT_QUOTES, 'UTF-8');
                $r_files  = number_format((int)($receipt['files_count'] ?? 0));
                $r_bytes  = (int)($receipt['bytes'] ?? 0);
                $r_status = (string)($receipt['status'] ?? '');
                if ($r_bytes >= 1073741824)  $r_size = round($r_bytes / 1073741824, 2) . ' GB';
                elseif ($r_bytes >= 1048576) $r_size = round($r_bytes / 1048576, 2) . ' MB';
                elseif ($r_bytes >= 1024)    $r_size = round($r_bytes / 1024, 1) . ' KB';
                else                         $r_size = $r_bytes . ' B';
                $r_badge = $r_status === 'ok'
                    ? '<span class="no-rs-ok">&#10003; Success</span>'
                    : '<span class="no-rs-err">&#10007; Error</span>';
                $receipt_html = <<<REC
<div class="no-receipt">
  <h3>&#128229; Last Sync Received from Master</h3>
  <div class="no-receipt-grid">
    <div class="no-receipt-cell"><div class="no-receipt-label">Received At</div><div class="no-receipt-val" style="font-size:.82rem">{$r_at}</div></div>
    <div class="no-receipt-cell"><div class="no-receipt-label">Source Node</div><div class="no-receipt-val" style="font-family:monospace">{$r_src}</div></div>
    <div class="no-receipt-cell"><div class="no-receipt-label">Files Transferred</div><div class="no-receipt-val">{$r_files}</div></div>
    <div class="no-receipt-cell"><div class="no-receipt-label">Data Volume</div><div class="no-receipt-val">{$r_size}</div></div>
    <div class="no-receipt-cell"><div class="no-receipt-label">Status</div><div class="no-receipt-val">{$r_badge}</div></div>
  </div>
</div>
REC;
            }
        }

        $html = <<<HTML
<style>
/* ni- = node identity banner */
.ni-banner{display:flex;align-items:stretch;gap:0;margin-bottom:1.25rem;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;overflow:hidden;background:#fff}
.ni-node{flex:1;padding:1rem 1.25rem}
.ni-node--local{background:var(--mb-accent-light,#EEF2FF);border-right:1px solid var(--mb-border,#E2E8F0)}
.ni-node--remote{background:var(--mb-card-bg,#F8FAFC)}
.ni-node-pill{display:inline-block;font-size:.65rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:.2rem .55rem;border-radius:5px;margin-bottom:.5rem}
.ni-pill--local{background:var(--mb-accent,#6366F1);color:#fff}
.ni-node-host{font-size:1.1rem;font-weight:700;color:var(--mb-text,#1E293B);font-family:monospace;margin-bottom:.25rem;word-break:break-all}
.ni-node-sub{font-size:.72rem;color:var(--mb-text-3,#94A3B8)}
/* no- = operations page */
.no-section{background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;padding:1.5rem;margin-bottom:1rem}
.no-section h3{margin:0 0 1rem;font-size:.95rem;font-weight:700;color:var(--mb-text,#1E293B)}
.no-badge{display:inline-block;padding:4px 12px;border-radius:6px;font-weight:700;font-family:monospace;font-size:.82rem}
.no-online{background:#DCFCE7;color:#15803D}.no-offline{background:#FEE2E2;color:#DC2626}.no-nc{background:#F1F5F9;color:#64748B}
.no-mirror-row{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;padding:.6rem 0;border-bottom:1px solid var(--mb-border,#E2E8F0)}
.no-mirror-row:last-child{border-bottom:none}
.no-mirror-host{font-family:monospace;font-size:.9rem;font-weight:600;flex:1;min-width:120px}
.no-mirror-actions{display:flex;gap:.5rem;flex-shrink:0}
.no-empty{font-size:.82rem;color:var(--mb-text-3,#94A3B8);padding:.5rem 0;font-style:italic}
.no-add-form{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--mb-border,#E2E8F0)}
.no-input{flex:1;min-width:200px;max-width:360px;padding:.45rem .75rem;border-radius:8px;border:1px solid var(--mb-border,#E2E8F0);font-size:.875rem}
.no-btn{padding:.45rem 1.25rem;border-radius:8px;border:none;cursor:pointer;font-size:.875rem;font-weight:600}
.no-btn-save{background:var(--mb-accent,#6366F1);color:#fff}.no-btn-save:hover{background:var(--mb-accent-hover,#4F46E5)}
.no-btn-sync{background:#0EA5E9;color:#fff}.no-btn-sync:hover{background:#0284C7}
.no-btn-sync:disabled{background:#CBD5E1;color:#94A3B8;cursor:not-allowed}
.no-btn-remove{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA}.no-btn-remove:hover{background:#FCA5A5;color:#991B1B}
.no-hint{font-size:.78rem;color:var(--mb-text-3,#94A3B8);margin-top:.5rem}
.no-role-note{font-size:.78rem;margin-top:.85rem;padding:.6rem .85rem;border-radius:8px;line-height:1.5;border-left:3px solid}
.no-role-master{background:#EFF6FF;border-color:#3B82F6;color:#1E40AF}
.no-role-mirror{background:#FFFBEB;border-color:#F59E0B;color:#92400E}
.no-receipt{background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;padding:1.5rem;margin-bottom:1rem}
.no-receipt h3{margin:0 0 1rem;font-size:.95rem;font-weight:700;color:var(--mb-text,#1E293B)}
.no-receipt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem}
.no-receipt-cell{background:var(--mb-card-bg,#F8FAFC);border:1px solid var(--mb-border,#E2E8F0);border-radius:8px;padding:.75rem 1rem}
.no-receipt-label{font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mb-text-3,#94A3B8);margin-bottom:.3rem}
.no-receipt-val{font-size:.95rem;font-weight:700;color:var(--mb-text,#1E293B);word-break:break-all}
.no-receipt-none{font-size:.82rem;color:var(--mb-text-3,#94A3B8);font-style:italic;padding:.25rem 0}
.no-rs-ok{color:#15803D}.no-rs-err{color:#DC2626}
</style>

<div>
  <!-- Node identity banner -->
  <div class="ni-banner">
    <div class="ni-node ni-node--local">
      <div class="ni-node-pill ni-pill--local">&#128205; This Node</div>
      <div class="ni-node-host">{$hostname}</div>
      <div class="ni-node-sub">Managing network operations from this instance</div>
    </div>
  </div>

  <!-- Configured mirrors -->
  <div class="no-section">
    <h3>Mirror Nodes</h3>
    {$mirror_rows}

    <!-- Add mirror form -->
    <form method="POST" action="{$save_action}" class="no-add-form">
      <input type="hidden" name="auth_token" value="{$token}">
      <input type="hidden" name="action" value="add">
      <input class="no-input" type="text" name="mirror_host" placeholder="hostname or IP (e.g. mirror-2)"
             autocomplete="off" spellcheck="false">
      <button type="submit" class="no-btn no-btn-save">+ Add Mirror</button>
    </form>
    <div class="no-hint">Enter a hostname, IPv4, or IPv6 address. Docker container hostnames (e.g. <code>mirror-node</code>) work on the internal network.</div>

    {$role_callout}

    {$sync_all_block}
  </div>

  <!-- Receipt (mirror only) -->
  {$receipt_html}
</div>
HTML;

        Ctx::$page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }

    // ── Mirror status page ───────────────────────────────────────────────────

    /**
     * @param string[]                                                          $mirrors
     * @param array<string, array{configured: bool, online: bool, latency_ms: ?float}> $probes
     */
    public function display_status(array $mirrors, array $probes): void
    {
        $hostname = htmlspecialchars(php_uname('n'), ENT_QUOTES, 'UTF-8');
        $ts       = (new \DateTime('now', new \DateTimeZone('Asia/Kuala_Lumpur')))->format('F j, Y — g:i:s A') . ' MYT';

        $rows = '';
        if (empty($mirrors)) {
            $rows = '<tr><td colspan="4" class="ns-empty">No mirrors configured on this node.</td></tr>';
        } else {
            foreach ($mirrors as $m) {
                $m_esc  = htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
                $probe  = $probes[$m] ?? ['online' => false, 'latency_ms' => null];
                if ($probe['online']) {
                    $st_badge = '<span class="ns-badge ns-online">ONLINE</span>';
                    $lat_ms   = $probe['latency_ms'];
                    $lat_str  = $lat_ms . ' ms';
                    $lat_cls  = $lat_ms < 50 ? 'ns-lat-good' : ($lat_ms < 200 ? 'ns-lat-warn' : 'ns-lat-bad');
                    $lat_html = '<span class="' . $lat_cls . '">' . $lat_str . '</span>';
                } else {
                    $st_badge = '<span class="ns-badge ns-offline">OFFLINE</span>';
                    $lat_html = '<span style="color:#94A3B8">—</span>';
                }
                $rows .= '<tr><td style="font-family:monospace;font-weight:600">' . $m_esc . '</td>'
                       . '<td>' . $st_badge . '</td>'
                       . '<td>' . $lat_html . '</td>'
                       . '<td><a href="?q=network/ops" style="font-size:.78rem;color:var(--mb-accent,#6366F1)">Manage →</a></td></tr>';
            }
        }

        $html = <<<HTML
<style>
.ns-header{font-size:.78rem;color:var(--mb-text-3,#94A3B8);margin-bottom:1.25rem}
.ns-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;overflow:hidden;font-size:.875rem;margin-bottom:1rem}
.ns-table th{padding:.65rem 1rem;text-align:left;background:var(--mb-card-bg,#F8FAFC);font-weight:600;color:var(--mb-text-2,#475569);border-bottom:1px solid var(--mb-border,#E2E8F0)}
.ns-table td{padding:.6rem 1rem;border-bottom:1px solid var(--mb-border,#E2E8F0);color:var(--mb-text,#1E293B)}
.ns-table tr:last-child td{border-bottom:none}
.ns-badge{display:inline-block;padding:.25rem .75rem;border-radius:9999px;font-size:.75rem;font-weight:700}
.ns-online{background:#DCFCE7;color:#15803D}.ns-offline{background:#FEE2E2;color:#DC2626}.ns-nc{background:#F1F5F9;color:#64748B}
.ns-lat-good{color:#16A34A}.ns-lat-warn{color:#D97706}.ns-lat-bad{color:#DC2626}
.ns-empty{text-align:center;color:var(--mb-text-3,#94A3B8);padding:1.5rem!important;font-style:italic}
</style>
<div class="ns-header">
  This node: <strong style="font-family:monospace">{$hostname}</strong> &nbsp;·&nbsp; Probed at {$ts}
</div>
<table class="ns-table">
  <thead><tr><th>Mirror Host</th><th>Status</th><th>TCP Latency</th><th></th></tr></thead>
  <tbody>{$rows}</tbody>
</table>
HTML;

        Ctx::$page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }

    // ── Audit log page ───────────────────────────────────────────────────────

    /** @param string[] $lines */
    public function display_audit(array $lines): void
    {
        $j_lines    = json_encode(array_values($lines), JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $audit_base = (string)make_link('network/audit');
        $json_sep   = str_contains($audit_base, '?') ? '&' : '?';
        $j_live_url = json_encode($audit_base . $json_sep . 'json=1', JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $html = <<<HTML
<style>
@import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&display=swap');
.aud-wrap{font-size:.875rem;color:var(--mb-text,#1E293B)}
.aud-hdr{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem}
.aud-hdr-left h2{font-size:1.4rem;font-weight:700;margin:0 0 .2rem;letter-spacing:-.02em;color:var(--mb-text,#1E293B)}
.aud-hdr-left p{font-size:.75rem;color:#64748B;margin:0}
.aud-hdr-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.aud-btn{padding:.4rem .9rem;border-radius:8px;border:1px solid var(--mb-border,#E2E8F0);background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:var(--mb-text,#1E293B)}
.aud-btn:hover{background:var(--mb-accent-light,#EEF2FF);border-color:var(--mb-accent,#6366F1);color:var(--mb-accent,#6366F1)}
.aud-btn-primary{background:var(--mb-accent,#6366F1);color:#fff;border-color:var(--mb-accent,#6366F1)}
.aud-btn-primary:hover{background:#4F46E5;color:#fff}
.aud-live-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#22C55E;margin-right:.4rem;animation:aud-pulse 1.6s ease-in-out infinite;vertical-align:middle}
@keyframes aud-pulse{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.5)}50%{box-shadow:0 0 0 5px rgba(34,197,94,0)}}
.aud-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem;margin-bottom:1rem}
.aud-stat{background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:10px;padding:.9rem 1.1rem}
.aud-stat-label{font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#94A3B8;margin-bottom:.3rem}
.aud-stat-val{font-size:1.45rem;font-weight:700;color:var(--mb-text,#1E293B);line-height:1}
.aud-stat-sub{font-size:.7rem;color:#94A3B8;margin-top:.2rem}
.aud-filter-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.6rem;margin-bottom:.85rem}
.aud-search{flex:1;min-width:200px;max-width:320px;padding:.38rem .75rem;border:1px solid var(--mb-border,#E2E8F0);border-radius:8px;font-size:.82rem;background:#fff;color:var(--mb-text,#1E293B)}
.aud-search:focus{outline:none;border-color:var(--mb-accent,#6366F1);box-shadow:0 0 0 2px rgba(99,102,241,.15)}
.aud-pills{display:flex;gap:.3rem;flex-wrap:wrap}
.aud-pill{background:#F1F5F9;border:1px solid transparent;color:#475569;padding:.22rem .65rem;border-radius:20px;font-size:.72rem;font-weight:700;cursor:pointer;transition:all .1s}
.aud-pill:hover{border-color:#94A3B8}
.aud-pill--active{border-color:var(--aud-pc,#6366F1);color:var(--aud-pc,#6366F1);background:var(--aud-pb,#EEF2FF)}
.aud-terminal{background:#0D1117;border:1px solid #21262D;border-radius:12px;overflow:hidden}
.aud-terminal-hdr{display:flex;align-items:center;justify-content:space-between;padding:.55rem 1rem;background:#161B22;font-family:'Fira Code',monospace;font-size:.75rem;color:#8B949E;font-weight:500;flex-wrap:wrap;gap:.35rem}
.aud-log-body{max-height:620px;overflow-y:auto;font-family:'Fira Code',monospace;font-size:.82rem;line-height:1.65}
.aud-log-body::-webkit-scrollbar{width:6px}
.aud-log-body::-webkit-scrollbar-track{background:#0D1117}
.aud-log-body::-webkit-scrollbar-thumb{background:#30363D;border-radius:3px}
.aud-row{display:grid;grid-template-columns:7rem 3rem 1fr;gap:.3rem .65rem;padding:.35rem 1rem;border-bottom:1px solid rgba(255,255,255,.04);align-items:baseline}
.aud-row:last-child{border-bottom:none}
.aud-row--crit{background:rgba(220,38,38,.18)}
.aud-row--err{background:rgba(234,88,12,.1)}
.aud-row--warn{background:rgba(234,179,8,.07)}
.aud-row--heal{background:rgba(109,40,217,.15)}
.aud-row--done{background:rgba(34,197,94,.08)}
.aud-row--sep{opacity:.4;border-bottom:1px solid rgba(255,255,255,.1)!important}
.aud-ts{color:#6E7681;font-size:.77rem;white-space:nowrap;letter-spacing:.01em}
.aud-lv{font-size:.7rem;font-weight:600;letter-spacing:.05em;text-align:right;white-space:nowrap}
.aud-msg{color:#E6EDF3;word-break:break-word}
.aud-incident{grid-column:1/-1;padding:0;background:rgba(220,38,38,.18);border-bottom:1px solid rgba(255,255,255,.04)}
.aud-incident summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:7rem 3rem 1fr;gap:.3rem .65rem;padding:.35rem 1rem;font-size:.82rem;line-height:1.65;color:#FCA5A5}
.aud-incident summary::-webkit-details-marker{display:none}
.aud-incident summary .aud-ts{color:#6E7681;font-size:.77rem}
.aud-incident summary .aud-lv{font-size:.7rem;font-weight:600;color:#F87171;text-align:right}
.aud-incident summary .aud-smsg::before{content:'&#9658;  '}
.aud-incident[open] summary .aud-smsg::before{content:'&#9660;  '}
.aud-incident-body{margin:.25rem 1rem .7rem calc(7rem + 3rem + 1.3rem + .65rem * 2);background:rgba(0,0,0,.4);border-left:2px solid #EF4444;border-radius:0 6px 6px 0;padding:.65rem 1rem}
.aud-irow{display:grid;grid-template-columns:10rem 1fr;gap:.15rem .5rem;font-size:.78rem;margin-bottom:.3rem}
.aud-irow:last-child{margin-bottom:0}
.aud-ilabel{color:#8B949E;font-weight:500}
.aud-ival{color:#E6EDF3;word-break:break-all}
.aud-ival--ok{color:#3FB950}
.aud-ival--err{color:#F85149}
.aud-empty-log{text-align:center;padding:3rem 1rem;color:#6E7681;background:#0D1117;font-family:'Fira Code',monospace}
.aud-empty-log div:first-child{font-size:2rem;margin-bottom:.5rem}
mark.aud-hl{background:#E3B341;color:#0D1117;border-radius:2px;padding:0 2px}
</style>

<div class="aud-wrap">
  <div class="aud-hdr">
    <div class="aud-hdr-left">
      <h2><span class="aud-live-dot"></span>Integrity Audit Log</h2>
      <p id="aud-sub">Auto-refresh in <strong id="aud-cd">30</strong>s &nbsp;&middot;&nbsp; Ctrl+F to search &nbsp;&middot;&nbsp; Esc to clear</p>
    </div>
    <div class="aud-hdr-actions">
      <button class="aud-btn" onclick="audExport()">&#8595; Export .log</button>
      <button class="aud-btn aud-btn-primary" onclick="audRefresh()">&#8635; Refresh Now</button>
    </div>
  </div>

  <div class="aud-stats">
    <div class="aud-stat"><div class="aud-stat-label">Audit Runs</div><div class="aud-stat-val" id="s-runs">-</div><div class="aud-stat-sub">in loaded window</div></div>
    <div class="aud-stat"><div class="aud-stat-label">Violations</div><div class="aud-stat-val" id="s-viol">-</div><div class="aud-stat-sub" id="s-viol-sub">-</div></div>
    <div class="aud-stat"><div class="aud-stat-label">Files Healed</div><div class="aud-stat-val" id="s-heal">-</div><div class="aud-stat-sub" id="s-heal-sub">-</div></div>
    <div class="aud-stat"><div class="aud-stat-label">Heal Rate</div><div class="aud-stat-val" id="s-rate">-</div><div class="aud-stat-sub" id="s-rate-sub"></div></div>
    <div class="aud-stat"><div class="aud-stat-label">Last Audit</div><div class="aud-stat-val" id="s-last" style="font-size:.9rem">-</div><div class="aud-stat-sub" id="s-last-sub"></div></div>
  </div>

  <div class="aud-filter-bar">
    <input id="aud-search" class="aud-search" type="text" placeholder="Search - file, hash, message..." oninput="audFilter()" autocomplete="off" spellcheck="false">
    <div class="aud-pills" id="aud-pills"></div>
  </div>

  <div class="aud-terminal">
    <div class="aud-terminal-hdr">
      <span>&#128203; /var/log/rsync_audit.log &nbsp;&middot;&nbsp; newest first</span>
      <span id="aud-vis-count" style="color:#94A3B8;font-size:.7rem">-</span>
    </div>
    <div class="aud-log-body" id="aud-log-body">
      <div class="aud-empty-log"><div>&#9203;</div><div>Loading...</div></div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var LIVE_URL   = {$j_live_url};
  var INIT_LINES = {$j_lines};
  var allEvents  = [];
  var activeFilter = 'ALL';
  var cdSecs = 30;

  function parseLines(raw) {
    return raw.map(function (line) {
      var m = line.match(/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(\w+)\s*\]\s*(.+)$/);
      if (!m) return { ts: '', time: '', lvl: 'RAW', msg: line.trim(), raw: line };
      return { ts: m[1], time: m[1].substring(11, 19), lvl: m[2].trim().toUpperCase(), msg: m[3].trim(), raw: line };
    });
  }

  function evType(e) {
    var msg = e.msg;
    if (msg.indexOf('INTEGRITY MISMATCH') !== -1 || msg.indexOf('FILE MISSING FROM DISK') !== -1) return 'INCIDENT';
    if (msg.indexOf('Self-heal VERIFIED') !== -1 || msg.indexOf('rsync_pull: SUCCESS') !== -1 || msg.indexOf('Self-heal: calling') !== -1) return 'HEAL';
    if (msg.indexOf('AUDIT COMPLETE') !== -1) return 'DONE';
    if (msg.indexOf('━') !== -1 || msg.indexOf('Integrity Audit STARTED') !== -1) return 'SEP';
    return e.lvl;
  }

  function computeStats(events) {
    var runs = 0, viol = 0, healed = 0, lastTs = '', lastSub = '';
    for (var i = 0; i < events.length; i++) {
      var t = evType(events[i]);
      if (events[i].msg.indexOf('Integrity Audit STARTED') !== -1) runs++;
      if (t === 'INCIDENT') viol++;
      if (t === 'HEAL' && events[i].msg.indexOf('Self-heal VERIFIED') !== -1) healed++;
      if (t === 'DONE' && events[i].ts > lastTs) {
        lastTs = events[i].ts;
        var m = events[i].msg, mm;
        var sc = 0, ok = 0, corr = 0, miss = 0;
        if ((mm = m.match(/Files scanned: ([\d,]+)/))) sc   = parseInt(mm[1].replace(/,/g,''));
        if ((mm = m.match(/\bOK: ([\d,]+)/)))          ok   = parseInt(mm[1].replace(/,/g,''));
        if ((mm = m.match(/Corrupted: ([\d,]+)/)))      corr = parseInt(mm[1].replace(/,/g,''));
        if ((mm = m.match(/Missing: ([\d,]+)/)))        miss = parseInt(mm[1].replace(/,/g,''));
        lastSub = sc + ' scanned, ' + ok + ' OK' + (corr + miss > 0 ? ', ' + (corr + miss) + ' corrupted' : '');
      }
    }
    return { runs: runs, viol: viol, healed: healed,
             rate: viol > 0 ? Math.round(healed / viol * 100) : (runs > 0 ? 100 : null),
             lastTs: lastTs, lastSub: lastSub };
  }

  function renderStats(s) {
    var el = function (id) { return document.getElementById(id); };
    el('s-runs').textContent = s.runs;
    el('s-viol').textContent = s.viol;
    el('s-viol').style.color = s.viol > 0 ? '#DC2626' : '#15803D';
    el('s-viol-sub').textContent = s.viol > 0 ? s.viol + ' integrity breach' + (s.viol !== 1 ? 'es' : '') : 'All clear';
    el('s-heal').textContent = s.healed;
    el('s-heal').style.color = s.healed > 0 ? '#7C3AED' : '#94A3B8';
    el('s-heal-sub').textContent = s.viol > 0 ? s.healed + ' of ' + s.viol + ' restored' : 'No incidents';
    var rateEl = el('s-rate');
    rateEl.textContent = s.rate !== null ? s.rate + '%' : '-';
    rateEl.style.color = s.rate === 100 ? '#15803D' : s.rate !== null ? '#DC2626' : '';
    el('s-rate-sub').textContent = s.rate === 100 ? 'Perfect record' : s.rate !== null ? 'Unresolved incidents' : 'No data';
    el('s-last').textContent = s.lastTs ? s.lastTs.substring(11, 16) : '-';
    el('s-last-sub').textContent = s.lastSub || 'No completed run found';
  }

  function parseIncident(msg) {
    var g = function (re) { var m = msg.match(re); return m ? m[1] : ''; };
    return { file: g(/file='([^']+)'/), md5: g(/md5='([^']+)'/), dbSha: g(/db_sha256='([^']+)'/), actualSha: g(/actual_sha256='([^']+)'/) };
  }

  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function highlight(s, q) {
    if (!q) return esc(s);
    var idx = s.toLowerCase().indexOf(q.toLowerCase());
    if (idx === -1) return esc(s);
    return esc(s.substring(0, idx)) + '<mark class="aud-hl">' + esc(s.substring(idx, idx + q.length)) + '</mark>' + esc(s.substring(idx + q.length));
  }

  var LV_COLOR = { INCIDENT:'#F87171', HEAL:'#A78BFA', DONE:'#4ADE80', SEP:'#334155', CRITICAL:'#F87171', ERROR:'#FB923C', WARNING:'#FBBF24', INFO:'#60A5FA', DEBUG:'#475569', RAW:'#334155' };
  var LV_LABEL = { INCIDENT:'CRIT', HEAL:'HEAL', DONE:'DONE', SEP:'', CRITICAL:'CRIT', ERROR:'ERR', WARNING:'WARN', INFO:'INFO', DEBUG:'DBG', RAW:'...' };
  var LV_ROW   = { INCIDENT:'aud-row--crit', HEAL:'aud-row--heal', DONE:'aud-row--done', SEP:'aud-row--sep', CRITICAL:'aud-row--crit', ERROR:'aud-row--err', WARNING:'aud-row--warn' };

  function renderLog(events, filter, search) {
    var rev = events.slice().reverse(), html = '', vis = 0;
    var srch = search.trim().toLowerCase();
    for (var i = 0; i < rev.length; i++) {
      var e = rev[i], t = evType(e);
      var show = filter === 'ALL'
        || (filter === 'INCIDENT' && t === 'INCIDENT')
        || (filter === 'HEAL'     && t === 'HEAL')
        || (filter === 'ERROR'    && (t === 'ERROR'   || e.lvl === 'ERROR'))
        || (filter === 'WARNING'  && (t === 'WARNING' || e.lvl === 'WARNING'))
        || (filter === 'INFO'     && (t === 'DONE' || t === 'SEP' || t === 'INFO' || e.lvl === 'INFO'));
      if (!show) continue;
      if (srch && e.msg.toLowerCase().indexOf(srch) === -1 && e.ts.indexOf(srch) === -1) continue;
      vis++;
      var ts = esc(e.time), lvc = LV_COLOR[t] || '#60A5FA', lbl = LV_LABEL[t] != null ? LV_LABEL[t] : t.substring(0,4);
      var cls = 'aud-row ' + (LV_ROW[t] || '');
      if (t === 'INCIDENT') {
        var inc = parseIncident(e.msg);
        var match = inc.dbSha && inc.actualSha && inc.dbSha === inc.actualSha;
        html += '<details class="aud-incident">'
              + '<summary><span class="aud-ts">' + ts + '</span><span class="aud-lv" style="color:#F87171">CRIT</span><span class="aud-smsg">' + highlight(e.msg, search) + '</span></summary>'
              + '<div class="aud-incident-body">';
        if (inc.file)      html += '<div class="aud-irow"><span class="aud-ilabel">File</span><span class="aud-ival">' + highlight(inc.file, search) + '</span></div>';
        if (inc.md5)       html += '<div class="aud-irow"><span class="aud-ilabel">MD5 (file path)</span><span class="aud-ival">' + highlight(inc.md5, search) + '</span></div>';
        if (inc.dbSha)     html += '<div class="aud-irow"><span class="aud-ilabel">Expected SHA-256</span><span class="aud-ival aud-ival--ok">' + esc(inc.dbSha) + '</span></div>';
        if (inc.actualSha) html += '<div class="aud-irow"><span class="aud-ilabel">Actual SHA-256</span><span class="aud-ival ' + (match ? 'aud-ival--ok' : 'aud-ival--err') + '">' + esc(inc.actualSha) + '</span></div>';
        if (inc.dbSha && inc.actualSha) html += '<div class="aud-irow"><span class="aud-ilabel">Status</span><span class="aud-ival ' + (match ? 'aud-ival--ok' : 'aud-ival--err') + '">' + (match ? '&#10003; Hashes match' : '&#10007; Hashes differ - corrupted') + '</span></div>';
        html += '</div></details>';
        continue;
      }
      html += '<div class="' + cls + '"><span class="aud-ts">' + ts + '</span><span class="aud-lv" style="color:' + lvc + '">' + lbl + '</span><span class="aud-msg">' + highlight(e.msg, search) + '</span></div>';
    }
    document.getElementById('aud-log-body').innerHTML = html || '<div class="aud-empty-log"><div>&#128269;</div><div>No entries match your filter.</div></div>';
    document.getElementById('aud-vis-count').textContent = vis + ' / ' + events.length + ' entries';
  }

  var PILLS = [
    { key:'ALL',      label:'All',       pc:'#6366F1', pb:'#EEF2FF' },
    { key:'INCIDENT', label:'Incidents', pc:'#DC2626', pb:'#FEE2E2' },
    { key:'HEAL',     label:'Healed',    pc:'#7C3AED', pb:'#EDE9FE' },
    { key:'ERROR',    label:'Errors',    pc:'#C2410C', pb:'#FFF7ED' },
    { key:'WARNING',  label:'Warnings',  pc:'#D97706', pb:'#FFFBEB' },
    { key:'INFO',     label:'Info',      pc:'#0369A1', pb:'#F0F9FF' },
  ];

  function renderPills(events) {
    var c = { ALL: events.length, INCIDENT:0, HEAL:0, ERROR:0, WARNING:0, INFO:0 };
    for (var i = 0; i < events.length; i++) {
      var t = evType(events[i]);
      if (t === 'INCIDENT') c.INCIDENT++;
      else if (t === 'HEAL') c.HEAL++;
      else if (t === 'ERROR'   || events[i].lvl === 'ERROR')   c.ERROR++;
      else if (t === 'WARNING' || events[i].lvl === 'WARNING') c.WARNING++;
      else c.INFO++;
    }
    var html = '';
    for (var j = 0; j < PILLS.length; j++) {
      var p = PILLS[j], n = c[p.key] || 0;
      if (n === 0 && p.key !== 'ALL') continue;
      var act = activeFilter === p.key ? ' aud-pill--active' : '';
      html += '<button class="aud-pill' + act + '" data-key="' + p.key + '" style="--aud-pc:' + p.pc + ';--aud-pb:' + p.pb + '">' + p.label + ' <strong>' + n + '</strong></button>';
    }
    var el = document.getElementById('aud-pills');
    el.innerHTML = html;
    el.querySelectorAll('.aud-pill').forEach(function (b) {
      b.addEventListener('click', function () { activeFilter = b.dataset.key; audFilter(); renderPills(allEvents); });
    });
  }

  function audFilter() {
    renderLog(allEvents, activeFilter, document.getElementById('aud-search').value);
  }

  function fullRender(events) {
    allEvents = events;
    renderStats(computeStats(events));
    renderPills(events);
    renderLog(events, activeFilter, document.getElementById('aud-search').value);
  }

  function audRefresh() {
    cdSecs = 30;
    fetch(LIVE_URL, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (Array.isArray(d.lines)) {
          fullRender(parseLines(d.lines));
          var sub = document.getElementById('aud-sub');
          if (sub && d.ts) sub.innerHTML = 'Last refreshed: <strong>' + d.ts + ' MYT</strong> &nbsp;&middot;&nbsp; Auto-refresh in <strong id="aud-cd">30</strong>s';
        }
      })
      .catch(function () {});
  }

  function audExport() {
    var text = allEvents.map(function (e) { return e.raw || e.msg; }).join('\\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    a.download = 'audit_' + new Date().toISOString().substring(0, 19).replace(/[T:]/g, '-') + '.log';
    a.click();
  }

  window.audRefresh = audRefresh;
  window.audExport  = audExport;
  window.audFilter  = audFilter;

  setInterval(function () {
    var el = document.getElementById('aud-cd');
    if (el) el.textContent = --cdSecs;
    if (cdSecs <= 0) audRefresh();
  }, 1000);

  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && ev.key === 'f') {
      var s = document.getElementById('aud-search');
      if (s) { ev.preventDefault(); s.focus(); s.select(); }
    }
    if (ev.key === 'Escape') {
      var s = document.getElementById('aud-search');
      if (s && s.value) { s.value = ''; audFilter(); }
    }
  });

  fullRender(parseLines(INIT_LINES));
  document.getElementById('aud-log-body').scrollIntoView({ behavior: 'smooth', block: 'start' });
})();
</script>
HTML;

        Ctx::$page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }
}