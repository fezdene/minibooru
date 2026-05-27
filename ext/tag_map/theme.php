<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

class TagMapTheme extends Themelet
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tag_color(float $scale): string
    {
        if ($scale < 0.5) return '#64748B';
        if ($scale < 1.0) return '#3B82F6';
        if ($scale < 1.5) return '#6366F1';
        if ($scale < 2.0) return '#8B5CF6';
        return '#EC4899';
    }

    private function tag_size(float $scale): string
    {
        $em = max(0.78, min(2.4, 0.78 + $scale * 0.55));
        return number_format($em, 2) . 'rem';
    }

    private function css(): string
    {
        return <<<'CSS'
<style>
/* ── Tag pages (tm- prefix) ────────────────────────── */
.tm-wrap{font-size:.875rem;color:var(--mb-text,#1E293B)}
.tm-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;
        gap:.75rem;margin-bottom:1.25rem}
.tm-tabs{display:flex;gap:.35rem}
.tm-tab{padding:.38rem .9rem;border-radius:8px;border:1.5px solid var(--mb-border,#E2E8F0);
        font-size:.8rem;font-weight:600;color:var(--mb-text-2,#475569);text-decoration:none;transition:all .14s}
.tm-tab:hover{border-color:var(--mb-accent,#6366F1);color:var(--mb-accent,#6366F1)}
.tm-tab--active{background:var(--mb-accent,#6366F1);border-color:var(--mb-accent,#6366F1);color:#fff!important}
.tm-actions{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.tm-pill-link{font-size:.75rem;padding:.28rem .65rem;border-radius:6px;text-decoration:none;
              border:1px solid var(--mb-border,#E2E8F0);color:var(--mb-text-2,#475569);transition:all .12s}
.tm-pill-link:hover{background:var(--mb-border,#E2E8F0)}
.tm-mincount{font-size:.75rem;color:var(--mb-text-3,#94A3B8)}
/* A–Z */
.tm-az{display:flex;flex-wrap:wrap;gap:.25rem;padding:.65rem .9rem;
       background:var(--mb-card-bg,#F8FAFC);border:1px solid var(--mb-border,#E2E8F0);
       border-radius:10px;margin-bottom:1rem}
.tm-az-btn{padding:.22rem .5rem;border-radius:5px;font-size:.78rem;font-weight:600;
           text-decoration:none;color:var(--mb-text-2,#475569);transition:all .12s}
.tm-az-btn:hover,.tm-az-btn--active{background:var(--mb-accent,#6366F1);color:#fff}
/* Map cloud */
.tm-cloud{display:flex;flex-wrap:wrap;align-items:baseline;gap:.4rem .55rem;
          padding:1.25rem;background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px}
.tm-cloud-tag{text-decoration:none;font-weight:600;line-height:1.4;transition:opacity .12s}
.tm-cloud-tag:hover{opacity:.65}
/* Alphabetic */
.tm-alpha-group{margin-bottom:1.25rem}
.tm-alpha-letter{display:inline-block;font-size:1.25rem;font-weight:800;
                 color:var(--mb-accent,#6366F1);margin-bottom:.5rem;
                 padding:.1rem .55rem;border-left:3px solid var(--mb-accent,#6366F1)}
.tm-alpha-tags{display:flex;flex-wrap:wrap;gap:.3rem}
.tm-alpha-tag{display:inline-flex;align-items:center;gap:.3rem;padding:.28rem .65rem;
              border-radius:20px;font-size:.8rem;font-weight:500;text-decoration:none;
              transition:all .14s;white-space:nowrap;
              background:var(--mb-card-bg,#F8FAFC);border:1px solid var(--mb-border,#E2E8F0);
              color:var(--mb-text,#1E293B)}
.tm-alpha-tag:hover{border-color:var(--mb-accent,#6366F1);color:var(--mb-accent,#6366F1);
                    background:var(--mb-accent-light,#EEF2FF)}
.tm-alpha-count{font-size:.66rem;font-weight:700;background:var(--mb-border,#E2E8F0);
                color:var(--mb-text-3,#94A3B8);border-radius:9999px;padding:.04rem .36rem}
/* Popularity — flat list */
.tm-all-tags{display:flex;flex-wrap:wrap;gap:.3rem}
.tm-pop-tag{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .7rem;
            border-radius:20px;font-size:.82rem;font-weight:500;text-decoration:none;
            transition:all .14s;white-space:nowrap}
.tm-pop-tag:hover{transform:translateY(-1px)}
.tm-pop-count{font-size:.67rem;font-weight:700;opacity:.65}
.tm-t-flat{background:var(--mb-card-bg,#F8FAFC);color:var(--mb-text,#1E293B);
           border:1.5px solid var(--mb-border,#E2E8F0)}
.tm-t-flat:hover{border-color:var(--mb-accent,#6366F1);color:var(--mb-accent,#6366F1);
                 background:var(--mb-accent-light,#EEF2FF)}
</style>
CSS;
    }

    private function header(string $active, int $tags_min, bool $show_filter = true): string
    {
        $map_url   = htmlspecialchars((string)make_link('tags/map'),        ENT_QUOTES, 'UTF-8');
        $alpha_url = htmlspecialchars((string)make_link('tags/alphabetic'), ENT_QUOTES, 'UTF-8');
        $pop_url   = htmlspecialchars((string)make_link('tags/popularity'), ENT_QUOTES, 'UTF-8');

        $tc = fn(string $k) => $k === $active ? 'tm-tab tm-tab--active' : 'tm-tab';

        $actions = '';
        if ($show_filter) {
            $all_url = htmlspecialchars(
                (string)Url::current()->withModifiedQuery(['mincount' => '1']),
                ENT_QUOTES, 'UTF-8'
            );
            $min_html = $tags_min > 1
                ? '<span class="tm-mincount">Min. ' . $tags_min . ' posts</span>'
                : '';
            $actions = '<div class="tm-actions">'
                . $min_html
                . '<a class="tm-pill-link" href="' . $all_url . '">Show all</a>'
                . '</div>';
        }

        return '<div class="tm-bar">'
            . '<div class="tm-tabs">'
            . '<a class="' . $tc('map')        . '" href="' . $map_url   . '">Map</a>'
            . '<a class="' . $tc('alphabetic') . '" href="' . $alpha_url . '">Alphabetic</a>'
            . '<a class="' . $tc('popularity') . '" href="' . $pop_url   . '">Popularity</a>'
            . '</div>'
            . $actions
            . '</div>';
    }

    private function az_nav(int $tags_min, string $current = ''): string
    {
        $letters = Ctx::$database->get_col(
            "SELECT DISTINCT LOWER(SUBSTR(tag,1,1)) FROM tags WHERE count >= :m ORDER BY LOWER(SUBSTR(tag,1,1))",
            ['m' => $tags_min]
        );
        if (empty($letters)) {
            return '';
        }
        $html = '<div class="tm-az">';
        foreach ($letters as $l) {
            $url = htmlspecialchars(
                (string)Url::current()->withModifiedQuery(['starts_with' => $l]),
                ENT_QUOTES, 'UTF-8'
            );
            $cls = (mb_strtolower((string)$l) === mb_strtolower($current))
                ? 'tm-az-btn tm-az-btn--active'
                : 'tm-az-btn';
            $html .= '<a class="' . $cls . '" href="' . $url . '">'
                . htmlspecialchars(strtoupper((string)$l), ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    // ── Page renderers ────────────────────────────────────────────────────────

    /**
     * @param array<array{tag:tag-string,scaled:float}> $tag_data
     */
    public function display_map(int $tags_min, array $tag_data): void
    {
        $use_az = Ctx::$config->get(TagMapConfig::PAGES);
        $sw     = (string)($_GET['starts_with'] ?? '');

        $cloud = '';
        if (empty($tag_data)) {
            $cloud = '<span style="color:var(--mb-text-3,#94A3B8);font-style:italic">No tags found.</span>';
        } else {
            foreach ($tag_data as $row) {
                $tag   = (string)$row['tag'];
                $scale = (float)$row['scaled'];
                $url   = htmlspecialchars((string)search_link([$tag]), ENT_QUOTES, 'UTF-8');
                $label = htmlspecialchars(str_replace('_', ' ', $tag), ENT_QUOTES, 'UTF-8');
                $cloud .= '<a class="tm-cloud-tag" href="' . $url . '" '
                    . 'style="color:' . $this->tag_color($scale) . ';font-size:' . $this->tag_size($scale) . '"'
                    . ' title="' . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
            }
        }

        $html = $this->css()
            . '<div class="tm-wrap">'
            . $this->header('map', $tags_min)
            . ($use_az ? $this->az_nav($tags_min, $sw) : '')
            . '<div class="tm-cloud">' . $cloud . '</div>'
            . '</div>';

        $page = Ctx::$page;
        $page->set_title('Tag Map');
        $page->set_heading('Tag Map');
        $page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }

    /**
     * @param array<string|int,int> $tag_data
     */
    public function display_alphabetic(string $starts_with, int $tags_min, array $tag_data): void
    {
        $use_az = Ctx::$config->get(TagMapConfig::PAGES);
        $sw     = rtrim($starts_with, '%');

        mb_internal_encoding('UTF-8');
        ksort($tag_data, SORT_STRING | SORT_FLAG_CASE);

        $groups = [];
        foreach ($tag_data as $tag => $count) {
            $tag  = (string)$tag;
            $pfx  = mb_strtolower(mb_substr($tag, 0, mb_strlen($sw) + 1));
            $groups[$pfx][] = [$tag, (int)$count];
        }

        $body = '';
        foreach ($groups as $letter => $items) {
            $let_esc = htmlspecialchars(strtoupper((string)$letter), ENT_QUOTES, 'UTF-8');
            $body .= '<div class="tm-alpha-group">'
                . '<div class="tm-alpha-letter">' . $let_esc . '</div>'
                . '<div class="tm-alpha-tags">';
            foreach ($items as [$tag, $count]) {
                $url   = htmlspecialchars((string)search_link([$tag]), ENT_QUOTES, 'UTF-8');
                $label = htmlspecialchars(str_replace('_', ' ', $tag), ENT_QUOTES, 'UTF-8');
                $body .= '<a class="tm-alpha-tag" href="' . $url . '">'
                    . $label
                    . '<span class="tm-alpha-count">' . number_format($count) . '</span>'
                    . '</a>';
            }
            $body .= '</div></div>';
        }

        if ($body === '') {
            $body = '<p style="color:var(--mb-text-3,#94A3B8)">No tags found.</p>';
        }

        $html = $this->css()
            . '<div class="tm-wrap">'
            . $this->header('alphabetic', $tags_min)
            . ($use_az ? $this->az_nav($tags_min, $sw) : '')
            . $body
            . '</div>';

        $page = Ctx::$page;
        $page->set_title('Tags — Alphabetic');
        $page->set_heading('Tags A–Z');
        $page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }

    /**
     * @param array<array{tag:tag-string,count:int,scaled:float}> $tag_data
     */
    public function display_popularity(array $tag_data): void
    {
        // Sort by count descending (highest first).
        usort($tag_data, fn($a, $b) => (int)$b['count'] <=> (int)$a['count']);

        $tags = '';
        foreach ($tag_data as $row) {
            $tag   = (string)$row['tag'];
            $count = (int)$row['count'];
            $url   = htmlspecialchars((string)search_link([$tag]), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars(str_replace('_', ' ', $tag), ENT_QUOTES, 'UTF-8');
            $tags .= '<a class="tm-pop-tag tm-t-flat" href="' . $url . '">'
                . $label
                . '<span class="tm-pop-count">' . number_format($count) . '</span>'
                . '</a>';
        }

        $body = $tags !== ''
            ? '<div class="tm-all-tags">' . $tags . '</div>'
            : '<p style="color:var(--mb-text-3,#94A3B8)">No tags found.</p>';

        $html = $this->css()
            . '<div class="tm-wrap">'
            . $this->header('popularity', 1, false)
            . $body
            . '</div>';

        $page = Ctx::$page;
        $page->set_title('Tags — Popularity');
        $page->set_heading('Tags by Popularity');
        $page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }

    // kept for interface compatibility
    protected function display_nav(): void {}
}
