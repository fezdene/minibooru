<?php

declare(strict_types=1);

namespace Shimmie2;

/**
 * Adds native PDF upload, thumbnail generation (via ImageMagick + Ghostscript),
 * and in-browser viewing support to Shimmie2.
 *
 * Thumbnail pipeline:
 *   ImageMagick's `convert` delegates PDF rasterisation to Ghostscript (gs).
 *   The first page is rendered at 150 DPI, flattened onto a white background
 *   (removing any PDF transparency), and scaled to fit within 300×300 px.
 *
 * View pipeline:
 *   PDFFileHandlerTheme::build_media() returns an <embed> element, which the
 *   browser uses to display the PDF inline via its native PDF plugin.
 *   The DataHandlerExtension base class routes to this theme method automatically
 *   through onDisplayingPost() — no central if/else template is required.
 */
final class PDFFileHandler extends DataHandlerExtension
{
    public const KEY = 'handle_pdf';

    /** @var string[] */
    protected const SUPPORTED_MIME = [MimeType::PDF];

    // -------------------------------------------------------------------------
    // Media properties
    // PDFs have no pixel dimensions and are not raster images, video, or audio.
    // -------------------------------------------------------------------------

    protected function media_check_properties(Post $image): ?MediaProperties
    {
        return new MediaProperties(
            width: 0,
            height: 0,
            lossless: false,
            video: false,
            audio: false,
            image: false,
            video_codec: null,
            length: null,
        );
    }

    // -------------------------------------------------------------------------
    // Content validation
    // Checks for the `%PDF-` magic bytes at the start of the file.
    // This is called by the base class before any DB write occurs.
    // -------------------------------------------------------------------------

    protected function check_contents(Path $tmpname): bool
    {
        $header = file_get_contents($tmpname->str(), false, null, 0, 5);
        return $header !== false && $header === '%PDF-';
    }

    // -------------------------------------------------------------------------
    // Thumbnail generation
    //
    // Command breakdown:
    //   convert                          — ImageMagick CLI
    //   -density 150                     — rasterise PDF at 150 DPI *before*
    //                                      any resize; must come before input
    //   {input}[0]                       — first page only (ImageMagick frame
    //                                      selector; safe inside escapeshellarg)
    //   -background white                — set canvas background for transparency
    //   -alpha remove -alpha off         — flatten alpha channel onto white bg
    //   -resize 300x300                  — scale to fit within 300×300, preserve
    //                                      aspect ratio, never upscale
    //   -quality 85                      — JPEG compression quality
    //   {output}                         — destination JPG path
    //
    // Both paths are wrapped in escapeshellarg() — no raw user data ever
    // reaches the shell, as file paths are derived from the server-side hash.
    // -------------------------------------------------------------------------

    protected function create_thumb(Post $image): bool
    {
        $inpath  = $image->get_media_filename()->str();
        $outpath = $image->get_thumb_filename()->str();

        // Ensure the warehouse thumbnail subdirectory exists.
        $outdir = dirname($outpath);
        if (!is_dir($outdir)) {
            mkdir($outdir, 0755, true);
        }

        // Appending [0] inside escapeshellarg is intentional: the shell sees a
        // literal bracket (not a glob), and ImageMagick receives it as its
        // frame/page selector after the shell strips the surrounding quotes.
        $safe_in  = escapeshellarg($inpath . '[0]');
        $safe_out = escapeshellarg($outpath);

        $command = "convert -density 150 {$safe_in} "
                 . "-background white -alpha remove -alpha off "
                 . "-resize 300x300 "
                 . "-quality 85 "
                 . "jpeg:{$safe_out} 2>&1";

        $output = shell_exec($command);

        if (!$image->get_thumb_filename()->exists()) {
            Log::warning(
                'handle_pdf',
                "Thumbnail generation failed for {$image->hash}. "
                . "ImageMagick output: " . trim((string)$output)
            );
            return false;
        }

        return true;
    }
}
