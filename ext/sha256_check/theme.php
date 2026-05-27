<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

class Sha256CheckTheme extends Themelet
{
    public function get_terminal_html(Post $post): \MicroHTML\HTMLElement
    {
        $post_id    = $post->id;
        $verify_url = (string)make_link('sha256_check/verify/' . $post_id);
        $has_stored = $post->sha256_hash !== null;
        $stored_preview = $has_stored
            ? htmlspecialchars(substr($post->sha256_hash, 0, 12), ENT_QUOTES, 'UTF-8') . '&hellip;'
            : 'not recorded';

        $html = <<<HTML
<style>
/* ── SHA-256 integrity card (sv- prefix) ── */
.sv-card{border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;overflow:hidden;font-size:.84rem;margin-top:.25rem}
.sv-hdr{display:flex;align-items:center;gap:.55rem;padding:.6rem .9rem;
        background:var(--mb-card-bg,#F8FAFC);cursor:pointer;user-select:none;
        border-bottom:1px solid transparent;transition:background .14s}
.sv-hdr:hover{background:var(--mb-hover,#F1F5F9)}
.sv-hdr.open{border-bottom-color:var(--mb-border,#E2E8F0)}
.sv-hdr-icon{font-size:.95rem;width:1.2rem;text-align:center;transition:transform .2s}
.sv-hdr-title{font-weight:600;color:var(--mb-text-1,#0F172A);flex:1}
.sv-hdr-badge{font-size:.67rem;font-family:monospace;color:var(--mb-text-3,#94A3B8);
              background:var(--mb-bg,#fff);border:1px solid var(--mb-border,#E2E8F0);
              border-radius:4px;padding:.1rem .38rem;max-width:120px;
              overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sv-hdr-chevron{color:var(--mb-text-3,#94A3B8);font-size:.65rem;transition:transform .2s;flex-shrink:0}
.sv-hdr-chevron.open{transform:rotate(180deg)}
.sv-body{display:none;padding:.85rem .9rem;background:var(--mb-bg,#fff)}
.sv-body.open{display:block}
/* Status banner */
.sv-banner{display:flex;align-items:flex-start;gap:.7rem;padding:.8rem .9rem;border-radius:8px;margin-bottom:.6rem}
.sv-banner-icon{font-size:1.4rem;line-height:1;flex-shrink:0;margin-top:.05rem}
.sv-banner-text strong{display:block;font-size:.875rem;margin-bottom:.18rem}
.sv-banner-text p{font-size:.77rem;line-height:1.55;margin:0;opacity:.85}
.sv-ok{background:#F0FDF4}.sv-ok .sv-banner-icon,.sv-ok strong{color:#15803D}.sv-ok p{color:#166534}
.sv-warn{background:#FFFBEB}.sv-warn .sv-banner-icon,.sv-warn strong{color:#B45309}.sv-warn p{color:#92400E}
.sv-err{background:#FEF2F2}.sv-err .sv-banner-icon,.sv-err strong{color:#B91C1C}.sv-err p{color:#991B1B}
/* Hash details */
.sv-details{margin-bottom:.5rem}
.sv-details summary{font-size:.72rem;font-weight:600;color:var(--mb-text-3,#94A3B8);
                    cursor:pointer;padding:.2rem 0;list-style:none}
.sv-details summary::-webkit-details-marker{display:none}
.sv-details summary::marker{display:none}
.sv-details summary:hover{color:var(--mb-text-2,#475569)}
.sv-hrows{display:flex;flex-direction:column;gap:.3rem;margin-top:.45rem}
.sv-hitem{background:var(--mb-card-bg,#F8FAFC);border:1px solid var(--mb-border,#E2E8F0);
          border-radius:6px;padding:.35rem .55rem}
.sv-hlabel{font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
           color:var(--mb-text-3,#94A3B8);margin-bottom:.14rem}
.sv-hval{font-family:monospace;font-size:.67rem;color:var(--mb-text-1,#0F172A);word-break:break-all}
.sv-hval-ok{color:#15803D}.sv-hval-bad{color:#B91C1C}
/* Footer */
.sv-foot{display:flex;align-items:center;justify-content:space-between;margin-top:.5rem}
.sv-timing{font-size:.7rem;color:var(--mb-text-3,#94A3B8)}
.sv-retry{background:none;border:1px solid var(--mb-border,#E2E8F0);border-radius:6px;
          padding:.22rem .6rem;font-size:.72rem;color:var(--mb-text-2,#475569);cursor:pointer}
.sv-retry:hover{background:var(--mb-hover,#F1F5F9)}
/* Loading */
.sv-loading{display:flex;align-items:center;gap:.6rem;padding:.5rem 0;
            color:var(--mb-text-2,#475569);font-size:.8rem}
.sv-spin{width:.95rem;height:.95rem;border:2px solid var(--mb-border,#E2E8F0);
         border-top-color:var(--mb-accent,#6366F1);border-radius:50%;
         animation:sv-spin .7s linear infinite;flex-shrink:0}
@keyframes sv-spin{to{transform:rotate(360deg)}}
</style>

<div class="sv-card" id="sv-card-{$post_id}">
  <div class="sv-hdr" id="sv-hdr-{$post_id}">
    <span class="sv-hdr-icon" id="sv-ico-{$post_id}">&#x1F512;</span>
    <span class="sv-hdr-title">File Integrity</span>
    <span class="sv-hdr-badge" title="Stored SHA-256">{$stored_preview}</span>
    <span class="sv-hdr-chevron" id="sv-chv-{$post_id}">&#9660;</span>
  </div>
  <div class="sv-body" id="sv-bdy-{$post_id}"></div>
</div>

<script>
(function () {
  var pid  = {$post_id};
  var url  = '{$verify_url}';
  var hdr  = document.getElementById('sv-hdr-'  + pid);
  var bdy  = document.getElementById('sv-bdy-'  + pid);
  var chv  = document.getElementById('sv-chv-'  + pid);
  var ico  = document.getElementById('sv-ico-'  + pid);
  var ran  = false;

  function hitem(label, value, cls) {
    return '<div class="sv-hitem"><div class="sv-hlabel">' + label + '</div>' +
           '<div class="sv-hval ' + (cls || '') + '">' + value + '</div></div>';
  }

  function setLoading() {
    bdy.innerHTML = '<div class="sv-loading"><div class="sv-spin"></div>' +
                    '<span>Computing SHA-256 hash…</span></div>';
  }

  function render(d) {
    var bannerClass, iconHtml, title, desc, hashes = '', timing = '';

    if (d.elapsed_ms != null) {
      timing = 'Verified in ' + d.elapsed_ms + ' ms';
    }

    if (d.status === 'ok') {
      bannerClass = 'sv-ok';
      iconHtml    = '&#x2705;';
      title       = 'File is intact';
      desc        = 'The computed hash matches the stored record. This file has not been modified or corrupted.';
      hashes      = hitem('Stored hash',   d.stored,   'sv-hval-ok') +
                    hitem('Computed hash', d.computed, 'sv-hval-ok');
      ico.innerHTML = '&#x2705;';

    } else if (d.status === 'mismatch') {
      bannerClass = 'sv-err';
      iconHtml    = '&#x26A0;&#xFE0F;';
      title       = 'Hash mismatch — possible corruption';
      desc        = 'The file on disk does not match the stored hash. It may have been corrupted, replaced, or tampered with. Investigate before serving this file.';
      hashes      = hitem('Expected (stored)',    d.stored,   'sv-hval-bad') +
                    hitem('Found on disk',        d.computed, '');
      ico.innerHTML = '&#x274C;';

    } else if (d.status === 'no_stored') {
      bannerClass = 'sv-warn';
      iconHtml    = '&#x2139;&#xFE0F;';
      title       = 'No reference hash on record';
      desc        = 'This file was uploaded before SHA-256 tracking was enabled, so there is no baseline to compare against. The hash below reflects the current state of the file.';
      hashes      = hitem('Current hash (no baseline)', d.computed, '');
      ico.innerHTML = '&#x26A0;&#xFE0F;';

    } else if (d.status === 'missing') {
      bannerClass = 'sv-err';
      iconHtml    = '&#x274C;';
      title       = 'File not found on disk';
      desc        = 'The post exists in the database but the actual file could not be located in storage. It may have been deleted, moved, or failed to sync.';
      if (d.stored) {
        hashes = hitem('Stored hash (unverifiable)', d.stored, '');
      }
      ico.innerHTML = '&#x274C;';

    } else if (d.status === 'not_found') {
      bannerClass = 'sv-err';
      iconHtml    = '&#x274C;';
      title       = 'Post not found';
      desc        = 'Post #' + d.post_id + ' does not exist in the database.';
      ico.innerHTML = '&#x274C;';

    } else {
      bannerClass = 'sv-err';
      iconHtml    = '&#x274C;';
      title       = 'Something went wrong';
      desc        = d.error || 'An unexpected error occurred. Please try again.';
      ico.innerHTML = '&#x274C;';
    }

    var html = '';
    html += '<div class="sv-banner ' + bannerClass + '">';
    html += '<div class="sv-banner-icon">' + iconHtml + '</div>';
    html += '<div class="sv-banner-text"><strong>' + title + '</strong><p>' + desc + '</p></div>';
    html += '</div>';

    if (hashes) {
      html += '<details class="sv-details"><summary>Click for hash details</summary>';
      html += '<div class="sv-hrows">' + hashes + '</div></details>';
    }

    html += '<div class="sv-foot">';
    html += '<span class="sv-timing">' + timing + '</span>';
    html += '<button class="sv-retry" id="sv-retry-' + pid + '">&#8635; Verify again</button>';
    html += '</div>';

    bdy.innerHTML = html;

    document.getElementById('sv-retry-' + pid).addEventListener('click', function (e) {
      e.stopPropagation();
      ico.innerHTML = '&#x1F512;';
      run();
    });
  }

  function run() {
    setLoading();
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function (e) {
        ico.innerHTML = '&#x274C;';
        bdy.innerHTML =
          '<div class="sv-banner sv-err">' +
          '<div class="sv-banner-icon">&#x274C;</div>' +
          '<div class="sv-banner-text"><strong>Network error</strong>' +
          '<p>' + e.message + '</p></div></div>';
      });
  }

  hdr.addEventListener('click', function () {
    var open = bdy.classList.toggle('open');
    hdr.classList.toggle('open', open);
    chv.classList.toggle('open', open);
    if (open && !ran) { ran = true; run(); }
  });
})();
</script>
HTML;

        return rawHTML($html);
    }
}
