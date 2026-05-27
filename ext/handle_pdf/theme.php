<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

/**
 * Renders the single-post view for PDF files.
 *
 * Shimmie2's DataHandlerExtension base class dispatches onDisplayingPost()
 * to whichever handler's SUPPORTED_MIME matches the post's MIME type, then
 * calls build_media() on that handler's theme. This means the routing between
 * <img> (images) and <embed> (PDFs) is handled automatically by the framework
 * — no central if/else template needs to be modified.
 *
 * Equivalent logic if you were writing it in a central view template:
 *
 *   if ($post->get_mime() == MimeType::PDF) {
 *       echo '<embed src="' . $post->get_media_link() . '" type="application/pdf" ...>';
 *   } else {
 *       echo '<img src="' . $post->get_media_link() . '" ...>';
 *   }
 */
class PDFFileHandlerTheme extends Themelet
{
    /**
     * Returns an <embed> element that instructs the browser to display the PDF
     * inline using its native PDF plugin (Chrome PDF Viewer, Firefox PDF.js, etc.).
     *
     * Falls back to a download link for browsers without a PDF plugin.
     */
    public function build_media(Post $image): \MicroHTML\HTMLElement
    {
        // get_media_link() returns a Url object; cast to string for HTML output.
        // The URL is server-generated (not user input), but we escape it anyway
        // to produce well-formed HTML and prevent any attribute-breaking chars.
        $src = htmlspecialchars(
            (string)$image->get_media_link(),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        // <object> wraps <embed> so browsers that do not support <embed> for PDFs
        // (e.g. some mobile browsers) fall through to the download link.
        // The user specifically requested <embed type="application/pdf">; it is
        // the primary renderer inside the <object> fallback chain.
        return rawHTML(
            '<object'
            . ' id="main_pdf"'
            . ' class="shm-main-image"'
            . ' data="' . $src . '"'
            . ' type="application/pdf"'
            . ' width="100%"'
            . ' style="min-height:800px;">'
                . '<embed'
                . ' src="' . $src . '"'
                . ' type="application/pdf"'
                . ' width="100%"'
                . ' height="800">'
                . '<p style="padding:1em;">'
                .     'Your browser does not support embedded PDFs. '
                .     '<a href="' . $src . '">Download the PDF</a>.'
                . '</p>'
            . '</object>'
        );
    }
}
