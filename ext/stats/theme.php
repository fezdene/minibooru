<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

class StatsTheme extends Themelet
{
    /** @param array<string, mixed> $s */
    public function display_dashboard(array $s): void
    {
        // ── Pre-process file type labels ──────────────────────────────────
        $mime_labels = [
            'image/jpeg'      => 'JPEG',
            'image/png'       => 'PNG',
            'image/gif'       => 'GIF',
            'image/webp'      => 'WebP',
            'image/avif'      => 'AVIF',
            'image/svg+xml'   => 'SVG',
            'video/mp4'       => 'MP4',
            'video/webm'      => 'WebM',
            'application/pdf' => 'PDF',
        ];

        $ft_labels = [];
        $ft_values = [];
        foreach ($s['file_types'] as $mime => $cnt) {
            $ft_labels[] = $mime_labels[$mime] ?? strtoupper(explode('/', (string)$mime)[1] ?? $mime);
            $ft_values[] = (int)$cnt;
        }

        $tag_labels = array_keys($s['top_tags']);
        $tag_values = array_values($s['top_tags']);

        $month_labels  = array_keys($s['by_month']);
        $month_values  = array_values($s['by_month']);
        $cmt_m_labels  = array_keys($s['comments_by_month']);
        $cmt_m_values  = array_values($s['comments_by_month']);

        // ── JSON for JS ───────────────────────────────────────────────────
        $j_ft_labels   = json_encode($ft_labels,    JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $j_ft_values   = json_encode($ft_values,    JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $j_tag_labels  = json_encode($tag_labels,   JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $j_tag_values  = json_encode($tag_values,   JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $j_mo_labels   = json_encode($month_labels, JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        $j_mo_values   = json_encode($month_values, JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        // ── Human-readable size helper ────────────────────────────────────
        $fmt_bytes = static function (float $b): string {
            if ($b >= 1073741824) {
                return round($b / 1073741824, 2) . ' GB';
            }
            if ($b >= 1048576) {
                return round($b / 1048576, 2) . ' MB';
            }
            return round($b / 1024, 1) . ' KB';
        };

        $total_size_str  = $fmt_bytes($s['total_bytes']);
        $avg_size_str    = $fmt_bytes($s['avg_bytes']);
        $largest_str     = $fmt_bytes($s['largest_bytes']);
        $source_pct      = $s['total_posts'] > 0 ? round($s['with_source'] / $s['total_posts'] * 100) : 0;
        $title_pct       = $s['total_posts'] > 0 ? round($s['with_title']  / $s['total_posts'] * 100) : 0;

        $n = $s['total_posts'];
        $updated = (new \DateTime('now', new \DateTimeZone('Asia/Kuala_Lumpur')))->format('F j, Y — g:i A') . ' MYT';

        $html = <<<HTML
<style>
/* ═══════════════════════════════════════════════════════════════
   ARCHIVE ANALYTICS DASHBOARD
   ═══════════════════════════════════════════════════════════════ */

.as-wrap {
    font-family: inherit;
    font-size: 0.875rem;
    color: var(--mb-text, #1E293B);
}

/* ── Page header ──────────────────────────────────────────────── */
.as-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.75rem;
}
.as-header-left h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--mb-text, #1E293B);
    margin: 0 0 0.25rem;
    letter-spacing: -0.02em;
}
.as-header-left p {
    font-size: 0.8rem;
    color: var(--mb-text-3, #94A3B8);
    margin: 0;
}

/* ── Filter pills ─────────────────────────────────────────────── */
.as-filters {
    display: flex;
    gap: 0.375rem;
    align-items: center;
    flex-shrink: 0;
}
.as-filter-btn {
    padding: 0.35rem 0.875rem;
    border: 1.5px solid var(--mb-border, #E2E8F0);
    border-radius: 9999px;
    background: var(--mb-surface, #fff);
    color: var(--mb-text-2, #475569);
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.15s;
}
.as-filter-btn:hover {
    border-color: var(--mb-accent, #6366F1);
    color: var(--mb-accent, #6366F1);
}
.as-filter-btn.as-active {
    background: var(--mb-accent, #6366F1);
    border-color: var(--mb-accent, #6366F1);
    color: #fff;
}
.as-refresh-btn {
    padding: 0.35rem 0.875rem;
    border: none;
    border-radius: 8px;
    background: var(--mb-accent, #6366F1);
    color: #fff;
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s;
    white-space: nowrap;
    flex-shrink: 0;
}
.as-refresh-btn:hover { background: var(--mb-accent-hover, #4F46E5); }

/* ── Stat cards row ───────────────────────────────────────────── */
.as-kpi-row {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.as-kpi {
    background: var(--mb-surface, #fff);
    border: 1px solid var(--mb-border, #E2E8F0);
    border-radius: 0.75rem;
    padding: 1.125rem 1.25rem;
    position: relative;
    overflow: hidden;
    transition: box-shadow 0.2s, transform 0.2s;
    cursor: default;
}
.as-kpi:hover {
    box-shadow: 0 4px 20px rgba(99,102,241,0.12);
    transform: translateY(-2px);
}
.as-kpi-accent {
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
    border-radius: 0.75rem 0 0 0.75rem;
}
.as-kpi-icon {
    font-size: 1.375rem;
    margin-bottom: 0.625rem;
    display: block;
}
.as-kpi-num {
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: -0.04em;
    line-height: 1;
    color: var(--mb-text, #1E293B);
    margin-bottom: 0.25rem;
}
.as-kpi-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--mb-text-3, #94A3B8);
}
.as-kpi-sub {
    font-size: 0.7rem;
    color: var(--mb-text-3, #94A3B8);
    margin-top: 0.375rem;
}
.as-kpi-delta {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.1em 0.45em;
    border-radius: 9999px;
    margin-top: 0.375rem;
}
.as-kpi-delta.up   { background: #DCFCE7; color: #16A34A; }
.as-kpi-delta.same { background: #F1F5F9; color: #64748B; }

/* ── Chart grid ───────────────────────────────────────────────── */
.as-charts {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 1rem;
    margin-bottom: 1rem;
}
.as-charts-wide {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

/* ── Chart card ───────────────────────────────────────────────── */
.as-card {
    background: var(--mb-surface, #fff);
    border: 1px solid var(--mb-border, #E2E8F0);
    border-radius: 0.75rem;
    padding: 1.25rem;
}
.as-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 1rem;
    gap: 0.5rem;
}
.as-card-title {
    font-size: 0.8125rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--mb-text-3, #94A3B8);
}
.as-card-badge {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.15em 0.5em;
    border-radius: 9999px;
    background: var(--mb-accent-light, #EEF2FF);
    color: var(--mb-accent, #6366F1);
    white-space: nowrap;
}
.as-chart-wrap {
    position: relative;
    height: 200px;
}
.as-chart-wrap.tall { height: 240px; }

/* ── Progress / completeness bars ─────────────────────────────── */
.as-progress-list { display: flex; flex-direction: column; gap: 1.125rem; }
.as-progress-item {}
.as-progress-meta {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 0.375rem;
}
.as-progress-name { font-size: 0.8rem; font-weight: 600; color: var(--mb-text-2, #475569); }
.as-progress-pct  { font-size: 0.8rem; font-weight: 700; color: var(--mb-text, #1E293B); }
.as-progress-track {
    height: 8px;
    border-radius: 9999px;
    background: var(--mb-page-bg, #F1F5F9);
    overflow: hidden;
}
.as-progress-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 1.2s cubic-bezier(0.4,0,0.2,1);
}

/* ── Dimension chips ──────────────────────────────────────────── */
.as-dim-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-top: 1rem;
}
.as-dim-chip {
    background: var(--mb-page-bg, #F1F5F9);
    border-radius: 0.5rem;
    padding: 0.75rem;
    text-align: center;
}
.as-dim-val  { font-size: 1.125rem; font-weight: 700; color: var(--mb-text, #1E293B); }
.as-dim-key  { font-size: 0.7rem; color: var(--mb-text-3, #94A3B8); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.2rem; }

/* ── Legend ───────────────────────────────────────────────────── */
.as-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.625rem;
    margin-top: 0.875rem;
}
.as-legend-item {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    color: var(--mb-text-2, #475569);
    font-weight: 500;
}
.as-legend-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

/* ── Empty state ──────────────────────────────────────────────── */
.as-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--mb-text-3, #94A3B8);
    font-size: 0.8125rem;
    font-style: italic;
}

/* ── Responsive ───────────────────────────────────────────────── */
@media (max-width: 900px) {
    .as-kpi-row          { grid-template-columns: repeat(3, 1fr); }
    .as-charts           { grid-template-columns: 1fr; }
    .as-charts-wide      { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .as-kpi-row          { grid-template-columns: repeat(2, 1fr); }
    .as-header           { flex-direction: column; }
}
</style>

<div class="as-wrap">

<!-- ── Header ──────────────────────────────────────────────────── -->
<div class="as-header">
    <div class="as-header-left">
        <h2>📊 Archive Analytics</h2>
        <p>Last refreshed {$updated}</p>
    </div>
    <div class="as-filters">
        <button class="as-filter-btn as-active" data-range="all">All time</button>
        <button class="as-filter-btn" data-range="30d">30 days</button>
        <button class="as-filter-btn" data-range="7d">7 days</button>
        <button class="as-refresh-btn" onclick="location.reload()">&#8635; Refresh</button>
    </div>
</div>

<!-- ── KPI cards ───────────────────────────────────────────────── -->
<div class="as-kpi-row">

    <div class="as-kpi">
        <div class="as-kpi-accent" style="background:#6366F1"></div>
        <span class="as-kpi-icon">🖼️</span>
        <div class="as-kpi-num" id="kpi-posts">0</div>
        <div class="as-kpi-label">Total Posts</div>
        <div class="as-kpi-delta up" id="kpi-posts-delta">↑ +0 this month</div>
    </div>

    <div class="as-kpi">
        <div class="as-kpi-accent" style="background:#8B5CF6"></div>
        <span class="as-kpi-icon">🏷️</span>
        <div class="as-kpi-num" id="kpi-tags">0</div>
        <div class="as-kpi-label">Active Tags</div>
        <div class="as-kpi-sub">Unique tags in use</div>
    </div>

    <div class="as-kpi">
        <div class="as-kpi-accent" style="background:#06B6D4"></div>
        <span class="as-kpi-icon">💾</span>
        <div class="as-kpi-num" id="kpi-size" style="font-size:1.375rem">{$total_size_str}</div>
        <div class="as-kpi-label">Total Storage</div>
        <div class="as-kpi-sub">Avg {$avg_size_str} · Largest {$largest_str}</div>
    </div>

    <div class="as-kpi">
        <div class="as-kpi-accent" style="background:#F59E0B"></div>
        <span class="as-kpi-icon">👥</span>
        <div class="as-kpi-num" id="kpi-users">0</div>
        <div class="as-kpi-label">Users</div>
        <div class="as-kpi-sub">Registered accounts</div>
    </div>

    <div class="as-kpi">
        <div class="as-kpi-accent" style="background:#10B981"></div>
        <span class="as-kpi-icon">💬</span>
        <div class="as-kpi-num" id="kpi-comments">0</div>
        <div class="as-kpi-label">Comments</div>
        <div class="as-kpi-delta up" id="kpi-comments-delta">↑ +0 this month</div>
    </div>

</div>

<!-- ── Row 2: File Types + Tags ────────────────────────────────── -->
<div class="as-charts">

    <div class="as-card">
        <div class="as-card-head">
            <span class="as-card-title">File Types</span>
            <span class="as-card-badge" id="ft-badge">{$n} files</span>
        </div>
        <div class="as-chart-wrap">
            <canvas id="chart-filetypes"></canvas>
        </div>
        <div class="as-legend" id="ft-legend"></div>
    </div>

    <div class="as-card">
        <div class="as-card-head">
            <span class="as-card-title">Top Tags</span>
            <span class="as-card-badge">by post count</span>
        </div>
        <div class="as-chart-wrap tall">
            <canvas id="chart-tags"></canvas>
        </div>
    </div>

</div>

<!-- ── Row 3: Upload Timeline + Completeness ───────────────────── -->
<div class="as-charts-wide">

    <div class="as-card">
        <div class="as-card-head">
            <span class="as-card-title">Upload Activity</span>
            <span class="as-card-badge" id="timeline-badge">Monthly</span>
        </div>
        <div class="as-chart-wrap tall">
            <canvas id="chart-timeline"></canvas>
        </div>
    </div>

    <div class="as-card">
        <div class="as-card-head">
            <span class="as-card-title">Content Quality</span>
            <span class="as-card-badge">{$n} posts</span>
        </div>

        <div class="as-progress-list">
            <div class="as-progress-item">
                <div class="as-progress-meta">
                    <span class="as-progress-name">📎 Has Source URL</span>
                    <span class="as-progress-pct">{$source_pct}%</span>
                </div>
                <div class="as-progress-track">
                    <div class="as-progress-fill" id="bar-source" style="width:0;background:#6366F1"></div>
                </div>
                <div style="font-size:0.7rem;color:var(--mb-text-3,#94A3B8);margin-top:0.3rem">{$s['with_source']} of {$n} posts</div>
            </div>

            <div class="as-progress-item">
                <div class="as-progress-meta">
                    <span class="as-progress-name">✏️ Has Title</span>
                    <span class="as-progress-pct">{$title_pct}%</span>
                </div>
                <div class="as-progress-track">
                    <div class="as-progress-fill" id="bar-title" style="width:0;background:#8B5CF6"></div>
                </div>
                <div style="font-size:0.7rem;color:var(--mb-text-3,#94A3B8);margin-top:0.3rem">{$s['with_title']} of {$n} posts</div>
            </div>

            <div class="as-progress-item">
                <div class="as-progress-meta">
                    <span class="as-progress-name">🏷️ Tagged (any tag)</span>
                    <span class="as-progress-pct">100%</span>
                </div>
                <div class="as-progress-track">
                    <div class="as-progress-fill" id="bar-tagged" style="width:0;background:#10B981"></div>
                </div>
                <div style="font-size:0.7rem;color:var(--mb-text-3,#94A3B8);margin-top:0.3rem">{$n} of {$n} posts</div>
            </div>
        </div>

        <div class="as-dim-grid">
            <div class="as-dim-chip">
                <div class="as-dim-val">{$s['avg_width']} × {$s['avg_height']}</div>
                <div class="as-dim-key">Avg Resolution</div>
            </div>
            <div class="as-dim-chip">
                <div class="as-dim-val">{$s['total_pools']}</div>
                <div class="as-dim-key">Pools</div>
            </div>
        </div>
    </div>

</div>

</div><!-- /.as-wrap -->

<script>
(function () {
'use strict';

/* ── Data ─────────────────────────────────────────────────────── */
const DATA = {
    all: {
        posts:    {$s['total_posts']},
        tags:     {$s['total_tags']},
        users:    {$s['total_users']},
        comments: {$s['total_comments']},
        posts_delta:    {$s['posts_30d']},
        comments_delta: {$s['comments_30d']},
    },
    '30d': {
        posts:    {$s['posts_30d']},
        tags:     {$s['total_tags']},
        users:    {$s['total_users']},
        comments: {$s['comments_30d']},
        posts_delta:    {$s['posts_7d']},
        comments_delta: {$s['comments_7d']},
    },
    '7d': {
        posts:    {$s['posts_7d']},
        tags:     {$s['total_tags']},
        users:    {$s['total_users']},
        comments: {$s['comments_7d']},
        posts_delta:    {$s['posts_7d']},
        comments_delta: {$s['comments_7d']},
    },
};

const FT_LABELS  = {$j_ft_labels};
const FT_VALUES  = {$j_ft_values};
const TAG_LABELS = {$j_tag_labels};
const TAG_VALUES = {$j_tag_values};
const MO_LABELS  = {$j_mo_labels};
const MO_VALUES  = {$j_mo_values};

/* ── Palette ──────────────────────────────────────────────────── */
const COLORS = [
    '#6366F1','#8B5CF6','#06B6D4','#F59E0B','#10B981',
    '#EF4444','#EC4899','#3B82F6','#84CC16','#F97316',
    '#14B8A6','#A855F7'
];
const FONT = getComputedStyle(document.body).fontFamily || 'system-ui,sans-serif';
const TEXT3 = '#94A3B8';

/* ── Chart.js global defaults ─────────────────────────────────── */
function applyDefaults() {
    if (!window.Chart) return;
    Chart.defaults.font.family = FONT;
    Chart.defaults.color       = TEXT3;
    Chart.defaults.plugins.legend.display = false;
    Chart.defaults.animation.duration     = 900;
}

/* ── Count-up animation ───────────────────────────────────────── */
function countUp(el, target, duration) {
    const start = Date.now();
    const from  = parseInt(el.textContent) || 0;
    (function tick() {
        const p = Math.min((Date.now() - start) / duration, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(from + (target - from) * eased).toLocaleString();
        if (p < 1) requestAnimationFrame(tick);
    })();
}

/* ── Progress bar animation ───────────────────────────────────── */
function animateBars() {
    setTimeout(function () {
        var s = document.getElementById('bar-source');
        var t = document.getElementById('bar-title');
        var g = document.getElementById('bar-tagged');
        if (s) s.style.width = '{$source_pct}%';
        if (t) t.style.width = '{$title_pct}%';
        if (g) g.style.width = '100%';
    }, 300);
}

/* ── KPI update ───────────────────────────────────────────────── */
function updateKPIs(range) {
    const d = DATA[range];
    countUp(document.getElementById('kpi-posts'),    d.posts,    700);
    countUp(document.getElementById('kpi-tags'),     d.tags,     700);
    countUp(document.getElementById('kpi-users'),    d.users,    700);
    countUp(document.getElementById('kpi-comments'), d.comments, 700);

    const rangeLabel = range === 'all' ? 'this month' : (range === '30d' ? 'in 7d' : 'today');
    document.getElementById('kpi-posts-delta').textContent    = '↑ +' + d.posts_delta    + ' ' + rangeLabel;
    document.getElementById('kpi-comments-delta').textContent = '↑ +' + d.comments_delta + ' ' + rangeLabel;
}

/* ── File types chart ─────────────────────────────────────────── */
function buildFiletypesChart() {
    const ctx = document.getElementById('chart-filetypes');
    if (!ctx || !window.Chart) return;

    const chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels:   FT_LABELS,
            datasets: [{
                data:            FT_VALUES,
                backgroundColor: COLORS.slice(0, FT_VALUES.length),
                borderWidth:     2,
                borderColor:     '#fff',
                hoverOffset:     8,
            }]
        },
        options: {
            cutout:  '68%',
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            const total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            const pct   = Math.round(ctx.parsed / total * 100);
                            return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                        }
                    }
                }
            },
            animation: { animateRotate: true, duration: 900 },
        }
    });

    // Build legend
    const legend = document.getElementById('ft-legend');
    if (legend) {
        FT_LABELS.forEach(function (lbl, i) {
            const item = document.createElement('div');
            item.className = 'as-legend-item';
            item.innerHTML = '<span class="as-legend-dot" style="background:' + COLORS[i] + '"></span>' + lbl + ' (' + FT_VALUES[i] + ')';
            legend.appendChild(item);
        });
    }

    return chart;
}

/* ── Tags chart ───────────────────────────────────────────────── */
function buildTagsChart() {
    const ctx = document.getElementById('chart-tags');
    if (!ctx || !window.Chart) return;

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels:   TAG_LABELS,
            datasets: [{
                label:           'Posts',
                data:            TAG_VALUES,
                backgroundColor: COLORS.map(function (c) { return c + 'CC'; }),
                borderColor:     COLORS,
                borderWidth:     1.5,
                borderRadius:    4,
                borderSkipped:   false,
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function (ctx) { return ' ' + ctx.parsed.x + ' posts'; }
                    }
                }
            },
            scales: {
                x: {
                    grid:  { color: 'rgba(0,0,0,0.05)' },
                    ticks: { stepSize: 1 },
                },
                y: { grid: { display: false } }
            }
        }
    });
}

/* ── Timeline chart ───────────────────────────────────────────── */
function buildTimelineChart() {
    const ctx = document.getElementById('chart-timeline');
    if (!ctx || !window.Chart) return;

    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0,   'rgba(99,102,241,0.25)');
    gradient.addColorStop(1,   'rgba(99,102,241,0.00)');

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels:   MO_LABELS.length ? MO_LABELS : ['(no data)'],
            datasets: [{
                label:           'Uploads',
                data:            MO_VALUES.length ? MO_VALUES : [0],
                borderColor:     '#6366F1',
                backgroundColor: gradient,
                borderWidth:     2.5,
                pointBackgroundColor: '#6366F1',
                pointRadius:     5,
                pointHoverRadius:7,
                fill:            true,
                tension:         0.35,
            }]
        },
        options: {
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function (ctx) { return ' ' + ctx.parsed.y + ' uploads'; }
                    }
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                    ticks: { stepSize: 1 },
                }
            }
        }
    });
}

/* ── Filter buttons ───────────────────────────────────────────── */
function initFilters() {
    document.querySelectorAll('.as-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.as-filter-btn').forEach(function (b) {
                b.classList.remove('as-active');
            });
            btn.classList.add('as-active');
            updateKPIs(btn.dataset.range);
        });
    });
}

/* ── Bootstrap ────────────────────────────────────────────────── */
function init() {
    applyDefaults();
    updateKPIs('all');
    animateBars();
    buildFiletypesChart();
    buildTagsChart();
    buildTimelineChart();
    initFilters();
}

// Load Chart.js from CDN then initialise
if (window.Chart) {
    init();
} else {
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
    s.onload = init;
    document.head.appendChild(s);
}

}());
</script>
HTML;

        Ctx::$page->add_block(new Block(null, rawHTML($html), "main", 10));
    }

}
