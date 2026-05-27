<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\{A, LI, SPAN, UL, emptyHTML};

use MicroHTML\HTMLElement;

class ModernbooruTagListTheme extends TagListTheme
{
    /** @param array<array{tag: tag-string, count: int}> $tag_infos */
    public function display_popular_block(array $tag_infos): void
    {
        Ctx::$page->add_block(new Block("Popular Tags", $this->mb_tag_list($tag_infos), "left", 60));
    }

    /** @param array<array{tag: tag-string, count: int}> $tag_infos */
    public function display_related_block(array $tag_infos, string $block_name): void
    {
        Ctx::$page->add_block(new Block($block_name, $this->mb_tag_list($tag_infos), "left", 10));
    }

    /**
     * @param array<array{tag: tag-string, count: int}> $tag_infos
     * @param search-term-array $search
     */
    public function display_refine_block(array $tag_infos, array $search): void
    {
        Ctx::$page->add_block(new Block("Refine Search", $this->mb_tag_list($tag_infos), "left", 60));
    }

    /** @param array<array{tag: tag-string, count: int}> $tag_infos */
    private function mb_tag_list(array $tag_infos): HTMLElement
    {
        $show_count = Ctx::$config->get(TagListConfig::SHOW_NUMBERS);

        $ul = UL(['class' => 'mb-tag-list']);
        foreach ($tag_infos as $row) {
            $tag   = $row['tag'];
            $count = $row['count'];

            $li = LI(
                ['class' => 'mb-tag-item'],
                A(['href' => search_link([$tag]), 'class' => 'mb-tag-link'], $tag),
            );
            if ($show_count) {
                $li->appendChild(SPAN(['class' => 'mb-tag-count'], (string)$count));
            }
            $ul->appendChild($li);
        }

        return emptyHTML(
            $ul,
            A(['class' => 'mb-tag-more', 'href' => make_link('tags')], 'Full list →')
        );
    }
}
