<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\{A, DIV, SPAN, rawHTML, emptyHTML};

class ModernbooruViewPostTheme extends ViewPostTheme
{
    /**
     * Inject a download button block into the left sidebar on every post page.
     *
     * @param HTMLElement[] $editor_parts
     * @param HTMLElement[] $sidebar_parts
     */
    public function display_page(Post $image, array $editor_parts, array $sidebar_parts): void
    {
        parent::display_page($image, $editor_parts, $sidebar_parts);
        Ctx::$page->add_block(new Block(null, $this->build_download_block($image), "left", 5, "PostDownload"));
    }

    private function build_download_block(Post $image): \MicroHTML\HTMLElement
    {
        $media_url = (string)$image->get_media_link();
        $filename  = $this->safe_filename($image);
        $size_fmt  = $this->fmt_size($image->filesize);
        $ext_lower = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: '');
        $ext_badge = strtoupper($ext_lower ?: 'FILE');
        $dims      = ($image->width && $image->height)
                       ? "{$image->width}×{$image->height}" : '';

        // Color-code the type badge so users can instantly identify the file type.
        $badge_bg = match(true) {
            in_array($ext_lower, ['jpg','jpeg','png','gif','webp','avif','svg','ico'], true) => '#0ea5e9',
            in_array($ext_lower, ['mp4','webm','mkv','mov','avi','flv'], true)               => '#ef4444',
            in_array($ext_lower, ['mp3','ogg','flac','wav','aac'], true)                     => '#a855f7',
            $ext_lower === 'pdf'  => '#f97316',
            $ext_lower === 'html' => '#22c55e',
            $ext_lower === 'cbz'  => '#f59e0b',
            default               => '#6366f1',
        };

        $meta_parts = array_filter([$size_fmt, $dims]);
        $meta = implode(' · ', $meta_parts);

        $css = <<<'CSS'
<style>
.mb-dl-card{border:1px solid #e2e8f0;border-radius:.75rem;overflow:hidden;margin:.125rem 0;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.mb-dl-info-row{display:flex;align-items:center;gap:.65rem;padding:.65rem .85rem;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.mb-dl-ext{flex-shrink:0;color:#fff;font-size:.6rem;font-weight:800;letter-spacing:.05em;padding:.3em .55em;border-radius:.3rem;text-transform:uppercase}
.mb-dl-file-meta{display:flex;flex-direction:column;gap:.1rem;min-width:0}
.mb-dl-filename{font-size:.8rem;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mb-dl-size{font-size:.7rem;color:#64748b}
.mb-dl-btn{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.65rem 1rem;background:#6366f1;color:#fff;text-decoration:none;font-size:.875rem;font-weight:600;transition:background .15s;box-sizing:border-box}
.mb-dl-btn:hover{background:#4f46e5}
.mb-dl-btn:active{background:#4338ca}
.mb-dl-btn.dl-clicked{background:#16a34a!important}
.mb-dl-btn-icon{transition:transform .15s}
.mb-dl-btn:hover .mb-dl-btn-icon{transform:translateY(2px)}
</style>
CSS;

        $js = <<<'JS'
<script>
(function(){
  var btn = document.getElementById('mb-dl-btn');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var label = btn.querySelector('.mb-dl-label');
    btn.classList.add('dl-clicked');
    if (label) label.textContent = 'Downloading…';
    setTimeout(function(){
      btn.classList.remove('dl-clicked');
      if (label) label.textContent = 'Download this file';
    }, 2500);
  });
})();
</script>
JS;

        return emptyHTML(
            rawHTML($css),
            DIV(
                ['class' => 'mb-dl-card'],
                DIV(
                    ['class' => 'mb-dl-info-row'],
                    SPAN(
                        ['class' => 'mb-dl-ext', 'style' => "background:{$badge_bg}"],
                        $ext_badge
                    ),
                    DIV(
                        ['class' => 'mb-dl-file-meta'],
                        SPAN(['class' => 'mb-dl-filename'], $filename),
                        SPAN(['class' => 'mb-dl-size'], $meta),
                    ),
                ),
                A(
                    [
                        'id'       => 'mb-dl-btn',
                        'class'    => 'mb-dl-btn',
                        'href'     => $media_url,
                        'download' => $filename,
                        'title'    => 'Download this file',
                    ],
                    SPAN(['class' => 'mb-dl-label', 'style' => 'color:#fff;text-align:center;width:100%'], 'Download this file'),
                ),
            ),
            rawHTML($js)
        );
    }

    private function safe_filename(Post $image): string
    {
        $name = $image->filename ?? "post-{$image->id}";
        // Strip any path components and non-safe characters.
        $name = basename($name);
        $name = preg_replace('/[^\w.\-]/u', '_', $name) ?? "post-{$image->id}";
        return $name !== '' ? $name : "post-{$image->id}";
    }

    private function fmt_size(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1_024) {
            return number_format($bytes / 1_024, 0) . ' KB';
        }
        return $bytes . ' B';
    }
}
