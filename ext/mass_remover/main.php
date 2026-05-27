<?php

declare(strict_types=1);

namespace Shimmie2;

final class MassRemover extends Extension
{
    public const KEY = 'mass_remover';
    /** @var MassRemoverTheme */
    protected Themelet $theme;

    // ── Show widget on post listing ──────────────────────────────────────────

    #[EventListener]
    public function onPostListBuilding(PostListBuildingEvent $event): void
    {
        if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
            return;
        }
        $this->theme->display_mass_action();
    }

    // ── Route POST actions ───────────────────────────────────────────────────

    #[EventListener]
    public function onPageRequest(PageRequestEvent $event): void
    {
        if (!Ctx::$user->can(AdminPermission::MANAGE_ADMINTOOLS)) {
            return;
        }
        if ($event->page_matches('mass_remover/remove', method: 'POST')) {
            $this->handle_mass_remove();
        } elseif ($event->page_matches('mass_remover/tag', method: 'POST')) {
            $this->handle_mass_tag();
        }
    }

    // ── Mass delete ──────────────────────────────────────────────────────────

    private function handle_mass_remove(): void
    {
        $raw     = trim((string)($_POST['ids'] ?? ''));
        $confirm = (string)($_POST['confirm'] ?? '');

        if ($raw === '' || $confirm !== 'set') {
            Ctx::$page->flash('Mass delete cancelled: no posts selected or confirmation not checked.');
            Ctx::$page->set_redirect(make_link('post/list'));
            return;
        }

        $ids = $this->parse_ids($raw);

        $removed = 0;
        foreach ($ids as $id) {
            $post = Post::by_id($id);
            if ($post !== null) {
                send_event(new PostDeletionEvent($post, force: true));
                $removed++;
            }
        }

        Log::info(self::KEY, Ctx::$user->name . " mass-deleted {$removed} post(s) (attempted " . count($ids) . ").");
        $noun = $removed === 1 ? 'post' : 'posts';
        Ctx::$page->flash("Deleted {$removed} {$noun}." . ($removed < count($ids) ? ' ' . (count($ids) - $removed) . ' skipped.' : ''));
        Ctx::$page->set_redirect(make_link('post/list'));
    }

    // ── Mass tag ─────────────────────────────────────────────────────────────

    private function handle_mass_tag(): void
    {
        $raw        = trim((string)($_POST['ids']        ?? ''));
        $tags_raw   = trim((string)($_POST['tags']       ?? ''));
        $tag_action = (string)($_POST['tag_action']      ?? 'add');

        if ($raw === '' || $tags_raw === '') {
            Ctx::$page->flash('Mass tag cancelled: no posts selected or no tags entered.');
            Ctx::$page->set_redirect(make_link('post/list'));
            return;
        }

        if (!in_array($tag_action, ['add', 'remove'], true)) {
            $tag_action = 'add';
        }

        $ids      = $this->parse_ids($raw);
        $new_tags = Tag::explode($tags_raw);

        $applied = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $post = Post::by_id($id);
            if ($post === null) {
                $skipped++;
                continue;
            }

            $current = Tag::explode($post->get_tag_list());

            if ($tag_action === 'add') {
                $final = array_values(array_unique(array_merge($current, $new_tags)));
            } else {
                $final = Tag::get_diff_tags($current, $new_tags);
                if (count($final) === 0) {
                    // Cannot leave a post with zero tags — skip it
                    $skipped++;
                    continue;
                }
            }

            try {
                $post->set_tags($final);
                $applied++;
            } catch (TagSetException $e) {
                Log::warning(self::KEY, "Mass tag skipped post {$id}: " . $e->getMessage());
                $skipped++;
            }
        }

        Log::info(self::KEY, Ctx::$user->name . " mass-{$tag_action}ed [{$tags_raw}] on {$applied} post(s).");
        $noun = $applied === 1 ? 'post' : 'posts';
        $verb = $tag_action === 'add' ? 'Added tags to' : 'Removed tags from';
        Ctx::$page->flash("{$verb} {$applied} {$noun}." . ($applied < count($ids) ? ' ' . (count($ids) - $applied) . ' skipped.' : ''));
        Ctx::$page->set_redirect(make_link('post/list'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return int[] */
    private function parse_ids(string $raw): array
    {
        return array_values(array_map(
            'intval',
            array_filter(
                array_map('trim', explode(':', $raw)),
                fn(string $v) => ctype_digit($v) && $v !== ''
            )
        ));
    }
}
