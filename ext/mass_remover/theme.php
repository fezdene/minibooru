<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

class MassRemoverTheme extends Themelet
{
    // ── Sidebar widget ───────────────────────────────────────────────────────

    public function display_mass_action(): void
    {
        $del_action = make_link('mass_remover/remove');
        $tag_action = make_link('mass_remover/tag');
        $token      = Ctx::$user->get_auth_token();

        $html = <<<HTML
<style>
/* ── Mass Action widget (mr- prefix) ──────────────────────── */
#mr-form .mr-btn {
  display:block; width:100%; padding:.42rem .75rem; border-radius:8px;
  border:none; cursor:pointer; font-size:.8rem; font-weight:600;
  text-align:center; transition:background .14s;
}
.mr-btn--primary { background:var(--mb-accent,#6366F1); color:#fff; margin-bottom:.3rem; }
.mr-btn--primary:hover { background:var(--mb-accent-hover,#4F46E5); }
.mr-btn--danger  { background:#EF4444; color:#fff; }
.mr-btn--danger:hover  { background:#DC2626; }
.mr-btn--danger:disabled,
.mr-btn--apply:disabled { background:#CBD5E1; color:#94A3B8; cursor:not-allowed; }
.mr-btn--apply   { background:#0EA5E9; color:#fff; }
.mr-btn--apply:hover   { background:#0284C7; }
.mr-btn--ghost   { background:var(--mb-card-bg,#F8FAFC); color:var(--mb-text-2,#475569);
                   border:1px solid var(--mb-border,#E2E8F0); }
.mr-btn--ghost:hover { background:var(--mb-border,#E2E8F0); }
.mr-hint { font-size:.72rem; color:var(--mb-text-3,#94A3B8); line-height:1.5;
           margin:.4rem 0 .5rem; }
.mr-count { font-size:.78rem; font-weight:700; color:var(--mb-accent,#6366F1);
            margin:.35rem 0; }
.mr-sel-row { display:flex; gap:.4rem; margin-bottom:.6rem; }
.mr-sel-row .mr-btn { flex:1; }
/* Tabs */
.mr-tabs { display:flex; gap:0; border:1px solid var(--mb-border,#E2E8F0);
           border-radius:8px; overflow:hidden; margin-bottom:.6rem; }
.mr-tab  { flex:1; padding:.3rem .5rem; border:none; cursor:pointer;
           font-size:.75rem; font-weight:600; background:var(--mb-card-bg,#F8FAFC);
           color:var(--mb-text-2,#475569); transition:background .12s; }
.mr-tab:first-child { border-right:1px solid var(--mb-border,#E2E8F0); }
.mr-tab--active { background:var(--mb-accent,#6366F1); color:#fff; }
.mr-panel { display:none; }
.mr-panel--active { display:block; }
/* Delete panel */
.mr-confirm-row { display:flex; align-items:center; gap:.5rem;
                  font-size:.78rem; color:var(--mb-text-2,#475569);
                  margin:.4rem 0 .5rem; }
/* Tag panel */
.mr-tag-input { width:100%; padding:.38rem .6rem; border-radius:7px;
                border:1px solid var(--mb-border,#E2E8F0); font-size:.8rem;
                margin-bottom:.45rem; box-sizing:border-box; }
.mr-tag-input:focus { outline:none; border-color:var(--mb-accent,#6366F1); }
.mr-radio-row { display:flex; gap:.75rem; font-size:.78rem;
                color:var(--mb-text-2,#475569); margin-bottom:.5rem; }
.mr-radio-row label { display:flex; align-items:center; gap:.3rem; cursor:pointer; }
/* Thumb selection */
.shm-thumb.mr-selected { outline:3px solid var(--mb-accent,#6366F1) !important;
                          outline-offset:2px; opacity:.82; }
</style>

<form id="mr-form" method="POST">
  <input type="hidden" name="auth_token" value="{$token}">
  <input type="hidden" name="ids"        id="mr-ids" value="">

  <button type="button" id="mr-activate" class="mr-btn mr-btn--primary">
    &#9673; Activate
  </button>

  <div id="mr-controls" style="display:none">
    <div class="mr-hint">Click thumbnails to select. At least one post required.</div>
    <div class="mr-count" id="mr-count">0 selected</div>
    <div class="mr-sel-row">
      <button type="button" id="mr-select-all" class="mr-btn mr-btn--ghost">All</button>
      <button type="button" id="mr-deselect"   class="mr-btn mr-btn--ghost">None</button>
    </div>

    <!-- Action tabs -->
    <div class="mr-tabs">
      <button type="button" class="mr-tab mr-tab--active" data-tab="delete">&#128465; Delete</button>
      <button type="button" class="mr-tab"                data-tab="tag">&#127991; Tag</button>
    </div>

    <!-- Delete panel -->
    <div class="mr-panel mr-panel--active" id="mr-panel-delete">
      <div class="mr-confirm-row">
        <input type="checkbox" name="confirm" value="set" id="mr-confirm">
        <label for="mr-confirm">Confirm deletion</label>
      </div>
      <button type="submit" id="mr-del-btn" class="mr-btn mr-btn--danger" disabled
              formaction="{$del_action}">
        &#128465; Delete selected
      </button>
    </div>

    <!-- Tag panel -->
    <div class="mr-panel" id="mr-panel-tag">
      <input type="text" name="tags" id="mr-tags-input" class="mr-tag-input"
             placeholder="e.g. artist:foo  safe" autocomplete="off" spellcheck="false">
      <div class="mr-radio-row">
        <label>
          <input type="radio" name="tag_action" value="add" checked> Add tags
        </label>
        <label>
          <input type="radio" name="tag_action" value="remove"> Remove tags
        </label>
      </div>
      <button type="submit" id="mr-tag-btn" class="mr-btn mr-btn--apply" disabled
              formaction="{$tag_action}">
        &#10003; Apply to selected
      </button>
    </div>
  </div>
</form>

<script>
(function () {
  'use strict';

  var sel = new Set();

  function sync() {
    document.getElementById('mr-ids').value         = Array.from(sel).join(':');
    document.getElementById('mr-count').textContent = sel.size + ' selected';

    var chk    = document.getElementById('mr-confirm');
    var delBtn = document.getElementById('mr-del-btn');
    delBtn.disabled = sel.size === 0 || !chk.checked;

    var tags   = (document.getElementById('mr-tags-input').value || '').trim();
    var tagBtn = document.getElementById('mr-tag-btn');
    tagBtn.disabled = sel.size === 0 || tags === '';
  }

  function markBlock(block, select) {
    block.classList.toggle('mr-selected', select);
  }

  function toggleThumb(block) {
    var id = block.dataset.postId;
    if (!id) return;
    if (sel.has(id)) { sel.delete(id); markBlock(block, false); }
    else             { sel.add(id);    markBlock(block, true);  }
    sync();
  }

  function activate() {
    document.querySelectorAll('.shm-thumb').forEach(function (block) {
      block.style.cursor = 'pointer';
      block.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleThumb(block);
      });
    });
    document.getElementById('mr-controls').style.display = '';
    document.getElementById('mr-activate').style.display = 'none';
  }

  function selectAll() {
    document.querySelectorAll('.shm-thumb').forEach(function (block) {
      var id = block.dataset.postId;
      if (!id) return;
      sel.add(id);
      markBlock(block, true);
    });
    sync();
  }

  function deselectAll() {
    document.querySelectorAll('.shm-thumb.mr-selected').forEach(function (b) { markBlock(b, false); });
    sel.clear();
    sync();
  }

  // Tab switching
  document.querySelectorAll('.mr-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.mr-tab').forEach(function (t) { t.classList.remove('mr-tab--active'); });
      document.querySelectorAll('.mr-panel').forEach(function (p) { p.classList.remove('mr-panel--active'); });
      tab.classList.add('mr-tab--active');
      document.getElementById('mr-panel-' + tab.dataset.tab).classList.add('mr-panel--active');
    });
  });

  document.getElementById('mr-activate').addEventListener('click', activate);
  document.getElementById('mr-confirm').addEventListener('change', sync);
  document.getElementById('mr-tags-input').addEventListener('input', sync);
  document.getElementById('mr-select-all').addEventListener('click', selectAll);
  document.getElementById('mr-deselect').addEventListener('click', deselectAll);
})();
</script>
HTML;

        Ctx::$page->add_block(new Block('Mass Action', rawHTML($html), 'left', 50));
    }

    // ── Results page ─────────────────────────────────────────────────────────

    public function display_action_results(string $action, int $applied, int $attempted, string $tags = ''): void
    {
        Ctx::$page->set_title('Mass Action');
        Ctx::$page->set_heading('Mass Action');

        $skipped = $attempted - $applied;
        $noun    = $applied === 1 ? 'post' : 'posts';

        if ($action === 'delete') {
            $summary  = "Deleted <strong>{$applied}</strong> {$noun}.";
            $skip_msg = $skipped > 0 ? "{$skipped} skipped (already deleted or not found)" : '';
        } elseif ($action === 'tag_add') {
            $tags_esc = htmlspecialchars($tags, ENT_QUOTES, 'UTF-8');
            $summary  = "Added tags <code>{$tags_esc}</code> to <strong>{$applied}</strong> {$noun}.";
            $skip_msg = $skipped > 0 ? "{$skipped} skipped (not found or would result in zero tags)" : '';
        } else {
            $tags_esc = htmlspecialchars($tags, ENT_QUOTES, 'UTF-8');
            $summary  = "Removed tags <code>{$tags_esc}</code> from <strong>{$applied}</strong> {$noun}.";
            $skip_msg = $skipped > 0 ? "{$skipped} skipped (not found or would result in zero tags)" : '';
        }

        $skip_html = $skip_msg !== ''
            ? "<p style=\"font-size:.82rem;color:var(--mb-text-3,#94A3B8);margin:0 0 1.25rem;\">{$attempted} selected &nbsp;·&nbsp; {$skip_msg}</p>"
            : "<p style=\"font-size:.82rem;color:var(--mb-text-3,#94A3B8);margin:0 0 1.25rem;\">{$attempted} selected</p>";

        $html = <<<HTML
<div style="background:#fff;border:1px solid var(--mb-border,#E2E8F0);border-radius:12px;
            padding:1.75rem 2rem;max-width:460px;">
  <p style="font-size:1rem;margin:0 0 .5rem;">{$summary}</p>
  {$skip_html}
  <a href="?q=post/list"
     style="display:inline-block;background:var(--mb-accent,#6366F1);color:#fff;
            padding:.45rem 1.25rem;border-radius:8px;text-decoration:none;
            font-weight:600;font-size:.875rem;">
    &larr; Back to Gallery
  </a>
</div>
HTML;

        Ctx::$page->add_block(new Block(null, rawHTML($html), 'main', 10));
    }
}
