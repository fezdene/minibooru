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
        $count = count($lines);

        // ── Empty state ────────────────────────────────────────────────────────
        if ($count === 0) {
            $html = <<<'HTML'
<div style="background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:3rem;text-align:center;color:#94A3B8">
  <div style="font-size:2.5rem;margin-bottom:.75rem">📋</div>
  <div style="font-size:1rem;font-weight:600;color:#475569;margin-bottom:.35rem">No audit log yet</div>
  <div style="font-size:.8rem">The cron job writes to <code style="background:#F1F5F9;padding:.1em .4em;border-radius:4px">/var/log/rsync_audit.log</code> every 5 minutes.</div>
</div>
HTML;
            Ctx::$page->add_block(new Block(null, rawHTML($html), 'main', 10));
            return;
        }

        // ── Parse log lines ────────────────────────────────────────────────────
        // Supports both Python logger format: [YYYY-MM-DD HH:MM:SS] [LEVEL   ] msg
        // and shell script format:            [YYYY-MM-DD HH:MM:SS] [LEVEL] msg
        $parsed = [];
        $counts = ['CRITICAL' => 0, 'ERROR' => 0, 'WARNING' => 0, 'INFO' => 0, 'DEBUG' => 0];
        $last_complete = null;

        foreach ($lines as $raw) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(\w+)\s*\]\s*(.+)$/', $raw, $m)) {
                $lvl = strtoupper(trim($m[2]));
                $counts[$lvl] = ($counts[$lvl] ?? 0) + 1;
                $entry = ['ts' => $m[1], 'lvl' => $lvl, 'msg' => $m[3]];
                if (str_contains($m[3], 'AUDIT COMPLETE')) {
                    $last_complete = $entry;
                }
            } else {
                $entry = ['ts' => '', 'lvl' => 'RAW', 'msg' => $raw];
            }
            $parsed[] = $entry;
        }

        // ── Health banner ──────────────────────────────────────────────────────
        if ($last_complete !== null) {
            $msg = $last_complete['msg'];
            $scanned   = 0; $ok = 0; $corrupted = 0; $missing = 0; $healed = 0; $unresolved = 0;
            if (preg_match('/Files scanned: ([\d,]+)/', $msg, $m)) $scanned   = (int)str_replace(',', '', $m[1]);
            if (preg_match('/\bOK: ([\d,]+)/',          $msg, $m)) $ok        = (int)str_replace(',', '', $m[1]);
            if (preg_match('/Corrupted: ([\d,]+)/',      $msg, $m)) $corrupted = (int)str_replace(',', '', $m[1]);
            if (preg_match('/Missing: ([\d,]+)/',        $msg, $m)) $missing   = (int)str_replace(',', '', $m[1]);
            if (preg_match('/Healed: ([\d,]+)/',         $msg, $m)) $healed    = (int)str_replace(',', '', $m[1]);
            if (preg_match('/Unresolved: ([\d,]+)/',     $msg, $m)) $unresolved = (int)str_replace(',', '', $m[1]);
            $run_secs = '';
            if (preg_match('/in ([\d.]+)s/', $msg, $m)) $run_secs = ' &nbsp;·&nbsp; completed in ' . $m[1] . 's';

            $last_ts = htmlspecialchars($last_complete['ts'], ENT_QUOTES, 'UTF-8');

            if ($unresolved > 0) {
                [$b_bg, $b_border, $b_icon, $b_color, $b_title] =
                    ['#FEF2F2', '#FECACA', '✗', '#DC2626', $unresolved . ' unresolved issue(s)'];
            } elseif (($corrupted + $missing) > 0) {
                [$b_bg, $b_border, $b_icon, $b_color, $b_title] =
                    ['#FFFBEB', '#FDE68A', '⚠', '#D97706', 'Issues detected — all healed'];
            } else {
                [$b_bg, $b_border, $b_icon, $b_color, $b_title] =
                    ['#F0FDF4', '#BBF7D0', '✓', '#15803D', 'All files verified'];
            }

            $stats_chips = '';
            if ($scanned > 0) {
                $stats_chips .= '<span class="nal-sc nal-sc--ok">' . number_format($ok) . ' OK</span>';
                if ($corrupted > 0) $stats_chips .= '<span class="nal-sc nal-sc--err">' . $corrupted . ' corrupted</span>';
                if ($missing   > 0) $stats_chips .= '<span class="nal-sc nal-sc--err">' . $missing   . ' missing</span>';
                if ($healed    > 0) $stats_chips .= '<span class="nal-sc nal-sc--heal">' . $healed   . ' healed</span>';
                $stats_chips = '<div class="nal-sc-row">'
                    . '<span class="nal-sc nal-sc--total">' . number_format($scanned) . ' files scanned</span>'
                    . $stats_chips . '</div>';
            }

            $banner = <<<HTML
<div class="nal-banner" style="background:{$b_bg};border-color:{$b_border}">
  <div class="nal-banner-icon" style="color:{$b_color}">{$b_icon}</div>
  <div>
    <div class="nal-banner-title" style="color:{$b_color}">{$b_title}</div>
    <div class="nal-banner-sub">Last audit: <strong>{$last_ts} MYT</strong>{$run_secs}</div>
    {$stats_chips}
  </div>
</div>
HTML;
        } else {
            $banner = <<<'HTML'
<div class="nal-banner" style="background:#F8FAFC;border-color:#E2E8F0">
  <div class="nal-banner-icon" style="color:#94A3B8">⏳</div>
  <div>
    <div class="nal-banner-title" style="color:#64748B">Waiting for first audit cycle</div>
    <div class="nal-banner-sub">The cron job runs every 5 minutes. No completed audit found in the loaded lines.</div>
  </div>
</div>
HTML;
        }

        // ── Event count chips ──────────────────────────────────────────────────
        $chips = '';
        foreach ([
            'ALL'      => ['#F1F5F9', '#475569', $count],
            'CRITICAL' => ['#FEE2E2', '#DC2626', $counts['CRITICAL']],
            'ERROR'    => ['#FFF7ED', '#C2410C', $counts['ERROR']],
            'WARNING'  => ['#FFFBEB', '#D97706', $counts['WARNING']],
        ] as $lvl => [$bg, $color, $n]) {
            if ($n === 0 && $lvl !== 'ALL') continue;
            $active = $lvl === 'ALL' ? ' nal-filter--active' : '';
            $chips .= "<button class=\"nal-filter{$active}\" data-lvl=\"{$lvl}\" "
                . "style=\"--chip-bg:{$bg};--chip-color:{$color}\">"
                . htmlspecialchars($lvl, ENT_QUOTES, 'UTF-8') . " <strong>{$n}</strong></button>";
        }

        // ── Log rows ───────────────────────────────────────────────────────────
        $log_rows = '';
        foreach (array_reverse($parsed) as $entry) {
            $ts_esc  = htmlspecialchars($entry['ts'] ? substr($entry['ts'], 11) : '', ENT_QUOTES, 'UTF-8');
            $msg_esc = htmlspecialchars($entry['msg'], ENT_QUOTES, 'UTF-8');
            $lvl     = $entry['lvl'];

            // Special event rows get their own style and override the level
            if (str_contains($entry['msg'], 'INTEGRITY MISMATCH') || str_contains($entry['msg'], 'FILE MISSING FROM DISK')) {
                $log_rows .= "<div class=\"nal-row nal-row--crit\" data-lvl=\"CRITICAL\">"
                    . "<span class=\"nal-ts\">{$ts_esc}</span>"
                    . "<span class=\"nal-lv\" style=\"color:#F87171\">CRIT</span>"
                    . "<span class=\"nal-msg\" style=\"color:#FCA5A5\">{$msg_esc}</span></div>";
                continue;
            }
            if (str_contains($entry['msg'], 'Self-heal VERIFIED') || str_contains($entry['msg'], 'rsync_pull: SUCCESS')) {
                $log_rows .= "<div class=\"nal-row nal-row--heal\" data-lvl=\"INFO\">"
                    . "<span class=\"nal-ts\">{$ts_esc}</span>"
                    . "<span class=\"nal-lv\" style=\"color:#A78BFA\">HEAL</span>"
                    . "<span class=\"nal-msg\" style=\"color:#C4B5FD\">{$msg_esc}</span></div>";
                continue;
            }
            if (str_contains($entry['msg'], 'AUDIT COMPLETE')) {
                $log_rows .= "<div class=\"nal-row nal-row--done\" data-lvl=\"INFO\">"
                    . "<span class=\"nal-ts\">{$ts_esc}</span>"
                    . "<span class=\"nal-lv\" style=\"color:#4ADE80\">DONE</span>"
                    . "<span class=\"nal-msg\" style=\"color:#86EFAC\">{$msg_esc}</span></div>";
                continue;
            }
            if (str_contains($entry['msg'], '━') || str_contains($entry['msg'], 'Integrity Audit STARTED')) {
                $log_rows .= "<div class=\"nal-row nal-row--sep\" data-lvl=\"INFO\">"
                    . "<span class=\"nal-ts\">{$ts_esc}</span>"
                    . "<span class=\"nal-lv\"></span>"
                    . "<span class=\"nal-msg\" style=\"color:#334155\">{$msg_esc}</span></div>";
                continue;
            }

            $abbr = match($lvl) {
                'CRITICAL' => 'CRIT', 'WARNING' => 'WARN', 'DEBUG' => 'DBG', 'RAW' => '···', default => $lvl
            };
            $lv_color = match($lvl) {
                'CRITICAL' => '#F87171', 'ERROR' => '#FB923C', 'WARNING' => '#FBBF24',
                'INFO'     => '#60A5FA', 'DEBUG' => '#475569', default => '#334155'
            };
            $row_cls = match($lvl) {
                'CRITICAL' => 'nal-row--crit', 'ERROR' => 'nal-row--err',
                'WARNING'  => 'nal-row--warn', default => 'nal-row--info'
            };
            $log_rows .= "<div class=\"nal-row {$row_cls}\" data-lvl=\"{$lvl}\">"
                . "<span class=\"nal-ts\">{$ts_esc}</span>"
                . "<span class=\"nal-lv\" style=\"color:{$lv_color}\">{$abbr}</span>"
                . "<span class=\"nal-msg\">{$msg_esc}</span></div>";
        }

        $html = <<<HTML
<style>
.nal-banner{display:flex;align-items:flex-start;gap:1rem;border:1px solid;border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1rem}
.nal-banner-icon{font-size:1.75rem;line-height:1;flex-shrink:0;font-weight:700;margin-top:.1rem}
.nal-banner-title{font-size:1.05rem;font-weight:700;margin-bottom:.2rem}
.nal-banner-sub{font-size:.78rem;color:#64748B}
.nal-sc-row{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.65rem}
.nal-sc{display:inline-block;padding:.2rem .65rem;border-radius:9999px;font-size:.75rem;font-weight:600}
.nal-sc--total{background:#F1F5F9;color:#475569}
.nal-sc--ok{background:#DCFCE7;color:#15803D}
.nal-sc--err{background:#FEE2E2;color:#DC2626}
.nal-sc--heal{background:#EDE9FE;color:#6D28D9}
.nal-filters{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.85rem}
.nal-filter{background:var(--chip-bg,#F1F5F9);color:var(--chip-color,#475569);border:1px solid transparent;padding:.3rem .85rem;border-radius:9999px;font-size:.75rem;font-weight:600;cursor:pointer;transition:all .12s}
.nal-filter:hover{border-color:var(--chip-color,#475569)}
.nal-filter--active{border-color:var(--chip-color,#475569);box-shadow:0 0 0 1px var(--chip-color,#475569)}
.nal-wrap{background:#fff;border:1px solid #E2E8F0;border-radius:12px;overflow:hidden}
.nal-hdr{display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;background:#F8FAFC;border-bottom:1px solid #E2E8F0;font-size:.75rem;color:#64748B;font-weight:600;flex-wrap:wrap;gap:.4rem}
.nal-body{max-height:560px;overflow-y:auto;background:#0F172A}
.nal-row{display:flex;align-items:baseline;gap:.55rem;padding:.28rem .9rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.775rem;font-family:monospace;line-height:1.5}
.nal-row:last-child{border-bottom:none}
.nal-row--crit{background:rgba(220,38,38,.18)}
.nal-row--err{background:rgba(234,88,12,.1)}
.nal-row--warn{background:rgba(234,179,8,.07)}
.nal-row--done{background:rgba(34,197,94,.08)}
.nal-row--heal{background:rgba(109,40,217,.15)}
.nal-row--sep{opacity:.4}
.nal-ts{color:#475569;flex-shrink:0;font-size:.7rem;white-space:nowrap}
.nal-lv{flex-shrink:0;font-size:.65rem;font-weight:700;letter-spacing:.04em;width:2.8rem;text-align:right}
.nal-msg{color:#CBD5E1;flex:1;word-break:break-all}
.nal-hidden{display:none!important}
</style>

{$banner}

<div class="nal-filters">{$chips}</div>

<div class="nal-wrap">
  <div class="nal-hdr">
    <span>Integrity Audit Log &nbsp;·&nbsp; newest first</span>
    <span id="nal-visible-count">{$count} entries</span>
  </div>
  <div class="nal-body" id="nal-body">{$log_rows}</div>
</div>

<script>
(function () {
  var active = 'ALL';
  var filters = document.querySelectorAll('.nal-filter');
  var rows    = document.querySelectorAll('#nal-body .nal-row');
  var countEl = document.getElementById('nal-visible-count');

  function applyFilter(lvl) {
    active = lvl;
    var vis = 0;
    rows.forEach(function (r) {
      var show = lvl === 'ALL' || r.dataset.lvl === lvl;
      r.classList.toggle('nal-hidden', !show);
      if (show) vis++;
    });
    filters.forEach(function (b) {
      b.classList.toggle('nal-filter--active', b.dataset.lvl === lvl);
    });
    if (countEl) countEl.textContent = vis + ' entries';
  }

  filters.forEach(function (b) {
    b.addEventListener('click', function () { applyFilter(b.dataset.lvl); });
  });
})();
</script>
HTML;

        Ctx::$page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }
}
