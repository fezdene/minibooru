<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\DIV;

use MicroHTML\HTMLElement;

/**
 * Modernbooru overrides for common UI components.
 *
 * build_thumb() wraps each thumbnail anchor in a .mb-thumb-card div so CSS
 * can apply card-style hover effects without touching any shm-* classes.
 */
class ModernbooruCommonElementsTheme extends CommonElementsTheme
{
    public function build_thumb(Post $image): HTMLElement
    {
        return DIV(['class' => 'mb-thumb-card'], parent::build_thumb($image));
    }
}
