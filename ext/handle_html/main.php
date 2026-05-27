<?php

declare(strict_types=1);

namespace Shimmie2;

/**
 * Handles self-contained HTML files produced by SingleFile.
 *
 * Thumbnail pipeline:
 *   Chromium headless takes a full-page screenshot → ImageMagick resizes it to
 *   a JPEG thumbnail. Falls back silently if Chromium is unavailable.
 *
 * View pipeline:
 *   HTMLFileHandlerTheme::build_media() renders the page inside a sandboxed
 *   <iframe>, so the archived page is viewable inline in the browser.
 */
final class HTMLFileHandler extends DataHandlerExtension
{
    public const KEY = 'handle_html';

    private const CHROMIUM   = '/usr/bin/chromium';
    private const THUMB_SIZE = 300;

    /** @var string[] */
    protected const SUPPORTED_MIME = [MimeType::HTML];

    protected function media_check_properties(Post $image): ?MediaProperties
    {
        return new MediaProperties(
            width: 0,
            height: 0,
            lossless: true,
            video: false,
            audio: false,
            image: false,
            video_codec: null,
            length: null,
        );
    }

    protected function check_contents(Path $tmpname): bool
    {
        $header = file_get_contents($tmpname->str(), false, null, 0, 100);
        if ($header === false) {
            return false;
        }
        $lower = strtolower(ltrim($header));
        return str_starts_with($lower, '<!doctype html') || str_starts_with($lower, '<html');
    }

    protected function create_thumb(Post $image): bool
    {
        if (!file_exists(self::CHROMIUM)) {
            Log::warning('handle_html', "Chromium not found at " . self::CHROMIUM . " — skipping thumbnail.");
            return false;
        }

        $html_path  = $image->get_media_filename()->str();
        $thumb_path = $image->get_thumb_filename()->str();

        $thumb_dir = dirname($thumb_path);
        if (!is_dir($thumb_dir)) {
            mkdir($thumb_dir, 0755, true);
        }

        $png_path = $thumb_path . '.tmp.png';

        $cmd_shot = escapeshellarg(self::CHROMIUM)
            . ' --headless'
            . ' --no-sandbox'
            . ' --disable-setuid-sandbox'
            . ' --disable-gpu'
            . ' --disable-dev-shm-usage'
            . ' --window-size=1280,960'
            . ' --screenshot=' . escapeshellarg($png_path)
            . ' ' . escapeshellarg('file://' . $html_path)
            . ' 2>/dev/null';

        exec($cmd_shot, $shot_out, $shot_exit);

        if ($shot_exit !== 0 || !file_exists($png_path)) {
            Log::warning('handle_html', "Screenshot failed for {$image->hash}.");
            return false;
        }

        $size = self::THUMB_SIZE;
        $cmd_resize = 'convert'
            . ' ' . escapeshellarg($png_path)
            . " -resize {$size}x{$size}\\>"
            . ' -quality 85'
            . ' jpeg:' . escapeshellarg($thumb_path)
            . ' 2>&1';

        exec($cmd_resize, $resize_out, $resize_exit);
        @unlink($png_path);

        if (!file_exists($thumb_path)) {
            Log::warning('handle_html', "Thumbnail resize failed for {$image->hash}: " . implode(' ', $resize_out));
            return false;
        }

        return true;
    }
}
