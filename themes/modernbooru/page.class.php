<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\{A, ARTICLE, BODY, DIV, FOOTER, H1, H3, HEADER, IMG, INPUT, LABEL, LINK, NAV, SECTION, SPAN, emptyHTML, rawHTML};

use MicroHTML\HTMLElement;

/**
 * Name: Modernbooru Dashboard Theme
 * Description: A clean SaaS-style dashboard layout: fixed left sidebar,
 *              top navigation bar, and card-based main content area.
 *              Sidebar collapses to a hamburger menu on mobile.
 */
class ModernbooruPage extends Page
{
    // -------------------------------------------------------------------------
    // Keep layout-* body class for JS/extension compat; add our own class too.
    // -------------------------------------------------------------------------

    public function add_auto_html_headers(): void
    {
        $this->add_html_header(LINK([
            'rel'  => 'preconnect',
            'href' => 'https://fonts.googleapis.com',
        ]), 10);
        $this->add_html_header(LINK([
            'rel'         => 'preconnect',
            'href'        => 'https://fonts.gstatic.com',
            'crossorigin' => '',
        ]), 11);
        $this->add_html_header(LINK([
            'rel'  => 'stylesheet',
            'href' => 'https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Google+Sans+Display:wght@400;700&display=swap',
        ]), 12);
        parent::add_auto_html_headers();
    }

    public function body_attrs(): array
    {
        $attrs = parent::body_attrs();
        $attrs['class'] .= ' mb-layout';
        return $attrs;
    }

    // -------------------------------------------------------------------------
    // Main layout builder
    // -------------------------------------------------------------------------

    protected function body_html(): HTMLElement
    {
        [$nav_links, $sub_links] = $this->get_nav_links();

        // Explicit category ordering for the top-bar nav.
        // Items whose category isn't listed fall back to their own $link->order.
        $cat_order = [
            'user'    => 10,   // Account
            'posts'   => 20,   // Posts
            'comment' => 30,   // Comments
            'help'    => 40,   // Help
            'note'    => 50,   // Notes
            'stats'   => 55,   // Stats
            'network' => 60,   // Network  ← between Stats and System
            'system'  => 65,   // System
            'tags'    => 70,   // Tags
            'upload'  => 80,   // Upload
        ];
        usort($nav_links, function (NavLink $a, NavLink $b) use ($cat_order): int {
            $oa = $cat_order[$a->category] ?? $a->order;
            $ob = $cat_order[$b->category] ?? $b->order;
            return $oa - $ob;
        });

        $left_blocks = [];
        $user_blocks = [];
        $main_blocks = [];
        $sub_blocks  = [];

        $sidebar_exclude = ['Manual Upload', 'Comments', 'Bookmarklets'];

        foreach ($this->blocks as $block) {
            match ($block->section) {
                'left' => in_array($block->header, $sidebar_exclude, true)
                    ? null
                    : ($left_blocks[] = $this->block_html($block, true)),
                'user'       => $user_blocks[]  = $block->body,
                'subheading' => $sub_blocks[]   = $block->body,
                'main'       => $main_blocks[]  = $this->block_html($block, false),
                default      => null,
            };
        }

        $site_name = Ctx::$config->get(SetupConfig::TITLE);
        $main_page = Ctx::$config->get(SetupConfig::MAIN_PAGE);

        // ── Primary top-bar navigation links ────────────────────────────────
        $user_class = Ctx::$user->class->name;
        $hidden_categories = match ($user_class) {
            'anonymous' => ['user', 'upload', 'system'],
            'user'      => ['system'],
            default     => [],
        };

        $topbar_nav = DIV(['class' => 'mb-topbar-nav']);
        foreach ($nav_links as $link) {
            if (in_array($link->category, $hidden_categories, true)) {
                continue;
            }
            $topbar_nav->appendChild(
                A(
                    ['href' => $link->link, 'class' => $link->active ? 'mb-nav-link mb-nav-link--active' : 'mb-nav-link'],
                    $link->description,
                )
            );
        }

        // ── Secondary (context-sensitive) sub-navigation ─────────────────────
        $subnav_html = emptyHTML();
        if (count($sub_links) > 0) {
            $subnav_bar = DIV(['class' => 'mb-subnav']);
            foreach ($sub_links as $link) {
                $subnav_bar->appendChild(
                    A(
                        ['href' => $link->link, 'class' => $link->active ? 'mb-subnav-link mb-subnav-link--active' : 'mb-subnav-link'],
                        $link->description,
                    )
                );
            }
            $subnav_html = $subnav_bar;
        }

        // ── Page heading (only when different from the site name itself) ─────
        $page_heading = emptyHTML();
        if ($this->heading !== '' && $this->heading !== $site_name) {
            $page_heading = H1(['class' => 'mb-page-heading'], $this->heading);
        }

        return BODY(
            $this->body_attrs(),

            // CSS-only sidebar toggle — must precede sidebar/main as a sibling
            // so the :checked ~ selector works. display:none hides it visually
            // but :checked still fires (CSS spec §:checked).
            INPUT(['type' => 'checkbox', 'id' => 'mb-nav-toggle', 'class' => 'mb-nav-toggle', 'aria-hidden' => 'true']),

            // ── Fixed top navigation bar ─────────────────────────────────────
            HEADER(
                ['class' => 'mb-topbar'],
                DIV(
                    ['class' => 'mb-topbar-brand'],
                    LABEL(
                        ['for' => 'mb-nav-toggle', 'class' => 'mb-hamburger', 'aria-label' => 'Toggle sidebar'],
                        SPAN(['class' => 'mb-bar']),
                        SPAN(['class' => 'mb-bar']),
                        SPAN(['class' => 'mb-bar']),
                    ),
                    A(
                        ['href' => make_link($main_page), 'class' => 'mb-brand'],
                        IMG(['src' => 'https://minecraft.wiki/images/Chest.gif?ca959', 'alt' => '', 'style' => 'height:1.8em;width:auto;vertical-align:middle;margin-right:.4rem;image-rendering:pixelated']),
                        $site_name,
                    ),
                ),
                $topbar_nav,
                DIV(
                    ['class' => 'mb-topbar-user'],
                    ...(function () use ($user_blocks): array {
                        $items = $user_blocks;
                        if (!Ctx::$user->is_anonymous()) {
                            $items[] = SPAN(
                                ['class' => 'mb-welcome'],
                                'Welcome, ',
                                A(['href' => make_link('user'), 'class' => 'mb-welcome-name'], Ctx::$user->name),
                                SPAN(['class' => 'mb-welcome-sep'], '·'),
                                A(['href' => make_link('user_admin/logout'), 'class' => 'mb-welcome-logout'], 'Log Out'),
                            );
                        }
                        return $items;
                    })(),
                ),
            ),

            // ── Secondary sub-nav (in normal flow, sits just below fixed topbar)
            $subnav_html,

            // ── Fixed left sidebar ────────────────────────────────────────────
            NAV(['class' => 'mb-sidebar'], ...$left_blocks),

            // ── Main content area ─────────────────────────────────────────────
            ARTICLE(
                ['class' => 'mb-main'],
                ...array_merge([$page_heading], $sub_blocks, [$this->flash_html()], $main_blocks),
            ),

            // ── Footer ───────────────────────────────────────────────────────
            FOOTER(['class' => 'mb-footer'], $this->footer_html()),
        );
    }

    // -------------------------------------------------------------------------
    // Block → card rendering
    // Keeps 'blockbody' class (used by extension JS) and 'shm-toggler' on
    // collapsible sidebar block headers. Only mb-* classes are our additions.
    // -------------------------------------------------------------------------

    protected function block_html(Block $block, bool $hidable): HTMLElement
    {
        // "Manual Upload" uses a native <details>/<summary> for a JS-free peekaboo toggle.
        if ($block->header === 'Manual Upload') {
            $body_html = !empty($block->body)
                ? (string)DIV(['class' => 'blockbody mb-card-body'], $block->body)
                : '';
            return rawHTML(sprintf(
                '<details id="%s" class="mb-card"><summary class="mb-card-title">%s</summary>%s</details>',
                htmlspecialchars((string)$block->id, ENT_QUOTES),
                htmlspecialchars($block->header, ENT_QUOTES),
                $body_html
            ));
        }

        $card = SECTION(['id' => $block->id, 'class' => 'mb-card']);

        if (!empty($block->header)) {
            $h3_class = 'mb-card-title' . ($hidable ? ' shm-toggler' : '');
            $card->appendChild(
                H3(['data-toggle-sel' => "#{$block->id}", 'class' => $h3_class], $block->header)
            );
        }

        if (!empty($block->body)) {
            $card->appendChild(DIV(['class' => 'blockbody mb-card-body'], $block->body));
        }

        return $card;
    }
}
