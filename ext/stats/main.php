<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

/** @extends Extension<StatsTheme> */
final class Stats extends Extension
{
    public const KEY = "stats";

    #[EventListener]
    public function onPageRequest(PageRequestEvent $event): void
    {
        if ($event->page_matches("stats")) {
            Ctx::$page->set_title("Archive Analytics");
            Ctx::$page->set_heading("Archive Analytics");
            $this->theme->display_dashboard($this->gather_stats());
        }
    }

    #[EventListener]
    public function onPageNavBuilding(PageNavBuildingEvent $event): void
    {
        $event->add_nav_link(make_link('stats'), "Stats", category: "stats");
    }

    /** @return array<string, mixed> */
    private function gather_stats(): array
    {
        $db = Ctx::$database;

        // ── All-time counts ───────────────────────────────────────────────
        $total_posts    = (int)$db->get_one("SELECT COUNT(*) FROM images");
        $total_tags     = (int)$db->get_one("SELECT COUNT(*) FROM tags WHERE count > 0");
        $total_users    = (int)$db->get_one("SELECT COUNT(*) FROM users WHERE class != 'anonymous'");
        $total_comments = CommentListInfo::is_enabled() ? (int)$db->get_one("SELECT COUNT(*) FROM comments") : 0;
        $total_pools    = PoolsInfo::is_enabled()      ? (int)$db->get_one("SELECT COUNT(*) FROM pools")    : 0;

        // ── Storage ───────────────────────────────────────────────────────
        $total_bytes    = (float)$db->get_one("SELECT COALESCE(SUM(filesize),0) FROM images");
        $avg_bytes      = (float)$db->get_one("SELECT COALESCE(AVG(filesize),0) FROM images");
        $largest_bytes  = (float)$db->get_one("SELECT COALESCE(MAX(filesize),0) FROM images");

        // ── Dimensions ────────────────────────────────────────────────────
        $avg_width  = (int)$db->get_one("SELECT COALESCE(ROUND(AVG(width)),0)  FROM images WHERE width  > 0");
        $avg_height = (int)$db->get_one("SELECT COALESCE(ROUND(AVG(height)),0) FROM images WHERE height > 0");

        // ── File types ────────────────────────────────────────────────────
        $file_types_raw = $db->get_pairs(
            "SELECT COALESCE(mime,'unknown'), COUNT(*) FROM images GROUP BY mime ORDER BY COUNT(*) DESC"
        );

        // ── Top tags ──────────────────────────────────────────────────────
        $top_tags = $db->get_pairs(
            "SELECT tag, count FROM tags WHERE count > 0 ORDER BY count DESC LIMIT 12"
        );

        // ── Uploads by month (last 12 months) ────────────────────────────
        $by_month = $db->get_pairs(
            "SELECT SUBSTR(posted,1,7) as m, COUNT(*) FROM images
             GROUP BY m ORDER BY m ASC LIMIT 12"
        );

        // ── Comments by month ─────────────────────────────────────────────
        $comments_by_month = $db->get_pairs(
            "SELECT SUBSTR(posted,1,7) as m, COUNT(*) FROM comments
             GROUP BY m ORDER BY m ASC LIMIT 12"
        );

        // ── Content completeness ──────────────────────────────────────────
        $with_source = (int)$db->get_one(
            "SELECT COUNT(*) FROM images WHERE source IS NOT NULL AND source != ''"
        );
        $with_title  = (int)$db->get_one(
            "SELECT COUNT(*) FROM images WHERE title  IS NOT NULL AND title  != ''"
        );

        // ── Time-windowed quick stats ─────────────────────────────────────
        $posts_30d    = (int)$db->get_one(
            "SELECT COUNT(*) FROM images   WHERE posted  >= datetime('now','-30 days')"
        );
        $posts_7d     = (int)$db->get_one(
            "SELECT COUNT(*) FROM images   WHERE posted  >= datetime('now','-7 days')"
        );
        $comments_30d = (int)$db->get_one(
            "SELECT COUNT(*) FROM comments WHERE posted  >= datetime('now','-30 days')"
        );
        $comments_7d  = (int)$db->get_one(
            "SELECT COUNT(*) FROM comments WHERE posted  >= datetime('now','-7 days')"
        );

        return [
            'total_posts'       => $total_posts,
            'total_tags'        => $total_tags,
            'total_users'       => $total_users,
            'total_comments'    => $total_comments,
            'total_pools'       => $total_pools,
            'total_bytes'       => $total_bytes,
            'avg_bytes'         => $avg_bytes,
            'largest_bytes'     => $largest_bytes,
            'avg_width'         => $avg_width,
            'avg_height'        => $avg_height,
            'file_types'        => $file_types_raw,
            'top_tags'          => $top_tags,
            'by_month'          => $by_month,
            'comments_by_month' => $comments_by_month,
            'with_source'       => $with_source,
            'with_title'        => $with_title,
            'posts_30d'         => $posts_30d,
            'posts_7d'          => $posts_7d,
            'comments_30d'      => $comments_30d,
            'comments_7d'       => $comments_7d,
        ];
    }
}
