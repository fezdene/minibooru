<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

class HTMLFileHandlerTheme extends Themelet
{
    /**
     * Renders the archived HTML page inside a sandboxed iframe.
     * The sandbox allows scripts so the page renders faithfully,
     * but blocks navigation away from the frame and form submission.
     */
    public function build_media(Post $image): \MicroHTML\HTMLElement
    {
        $src = htmlspecialchars(
            (string)$image->get_media_link(),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return rawHTML(
            '<div style="display:flex;flex-direction:column;gap:.5rem;">'
            . '<iframe'
            . ' src="' . $src . '"'
            . ' class="shm-main-image"'
            . ' style="width:100%;min-height:800px;border:1px solid #e2e8f0;border-radius:.5rem;"'
            . ' sandbox="allow-same-origin allow-scripts"'
            . '>'
            . '<p style="padding:1em;">Your browser cannot display this page inline.'
            . ' <a href="' . $src . '">View the archived page</a>.</p>'
            . '</iframe>'
            . '<p style="font-size:.78rem;color:#94a3b8;margin:0;">'
            . '&#x1F4C4; Archived webpage — '
            . '<a href="' . $src . '" download style="color:inherit;">download HTML</a>'
            . '</p>'
            . '</div>'
        );
    }
}
