<?php

declare(strict_types=1);

namespace Shimmie2;

final class Sha256Check extends Extension
{
    public const KEY = 'sha256_check';
    /** @var Sha256CheckTheme */
    protected Themelet $theme;

    // ── Add terminal widget to every post's info box (all users) ─────────────

    #[EventListener]
    public function onPostInfoBoxBuilding(PostInfoBoxBuildingEvent $event): void
    {
        $event->add_part($this->theme->get_terminal_html($event->image), 85);
    }

    // ── JSON endpoint: sha256_check/verify/{post_id} ─────────────────────────

    #[EventListener]
    public function onPageRequest(PageRequestEvent $event): void
    {
        if (!$event->page_matches('sha256_check/verify/{post_id}', method: 'GET')) {
            return;
        }
        $this->handle_verify($event->get_iarg('post_id'));
    }

    private function handle_verify(int $post_id): void
    {
        $post = Post::by_id($post_id);
        if ($post === null) {
            Ctx::$page->set_data(
                MimeType::JSON,
                json_encode(['status' => 'not_found', 'post_id' => $post_id], JSON_THROW_ON_ERROR)
            );
            return;
        }

        $file = $post->get_media_filename();

        if (!$file->exists()) {
            Ctx::$page->set_data(
                MimeType::JSON,
                json_encode([
                    'status'  => 'missing',
                    'post_id' => $post_id,
                    'stored'  => $post->sha256_hash,
                ], JSON_THROW_ON_ERROR)
            );
            return;
        }

        $t0       = microtime(true);
        $computed = $file->sha256();
        $elapsed  = round((microtime(true) - $t0) * 1000, 1);

        $stored = $post->sha256_hash;

        if ($stored === null) {
            $status = 'no_stored';
        } elseif (hash_equals($stored, $computed)) {
            $status = 'ok';
        } else {
            $status = 'mismatch';
        }

        Ctx::$page->set_data(
            MimeType::JSON,
            json_encode([
                'status'     => $status,
                'post_id'    => $post_id,
                'stored'     => $stored,
                'computed'   => $computed,
                'elapsed_ms' => $elapsed,
            ], JSON_THROW_ON_ERROR)
        );
    }
}
