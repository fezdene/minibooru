<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\rawHTML;

class GalleryDlIngestTheme extends Themelet
{
    public function display_ingest_form(bool $is_admin = true): void
    {
        $action    = make_link("gallerydl_ingest/submit");
        $token     = Ctx::$user->get_auth_token();
        $btn_label = $is_admin ? 'Ingest' : 'Submit for Approval';
        $user_note = $is_admin ? '' : '<div class="gdl-user-note">&#9432;&nbsp; Your request will be reviewed by an admin before processing.</div>';

        $html = <<<HTML
        <style>
        .gdl-wrap { font-size: 0.875rem; }

        /* ── Description ── */
        .gdl-desc {
            color: var(--mb-text-2, #475569);
            margin: 0 0 1.25rem;
            line-height: 1.6;
        }
        .gdl-desc a { color: var(--mb-link, #6366F1); }

        /* ── URL input row ── */
        .gdl-url-row {
            display: flex;
            gap: 0.625rem;
            align-items: stretch;
            margin-bottom: 0.75rem;
        }
        .gdl-url-row input[type="url"] {
            flex: 1;
            padding: 0.55rem 0.875rem;
            border: 2px solid var(--mb-border, #E2E8F0);
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            font-family: inherit;
            color: var(--mb-text, #1E293B);
            background: var(--mb-surface, #fff);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            min-width: 0;
        }
        .gdl-url-row input[type="url"]:focus {
            border-color: var(--mb-accent, #6366F1);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }
        .gdl-url-row input[type="url"]::placeholder { color: var(--mb-text-3, #94A3B8); }

        .gdl-submit-btn {
            padding: 0 1.375rem;
            background: var(--mb-accent, #6366F1);
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            font-family: inherit;
            transition: background 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .gdl-submit-btn:hover:not(:disabled) { background: var(--mb-accent-hover, #4F46E5); }
        .gdl-submit-btn:disabled { opacity: 0.65; cursor: not-allowed; }
        .gdl-spinner {
            display: none;
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: gdl-spin 0.7s linear infinite;
        }
        .gdl-spinner.visible { display: block; }
        @keyframes gdl-spin { to { transform: rotate(360deg); } }

        /* ── Title input row ── */
        .gdl-title-row { margin-bottom: 1.25rem; }
        .gdl-title-row input[type="text"] {
            width: 100%;
            padding: 0.55rem 0.875rem;
            border: 2px solid var(--mb-border, #E2E8F0);
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            font-family: inherit;
            color: var(--mb-text, #1E293B);
            background: var(--mb-surface, #fff);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            box-sizing: border-box;
        }
        .gdl-title-row input[type="text"]:focus {
            border-color: var(--mb-accent, #6366F1);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }
        .gdl-title-row input[type="text"]::placeholder { color: var(--mb-text-3, #94A3B8); }

        /* ── Engine selector ── */
        .gdl-engine-sel-row {
            display: flex;
            align-items: center;
            gap: .625rem;
            margin-bottom: .75rem;
            flex-wrap: wrap;
        }
        .gdl-engine-sel-label {
            font-size: .78rem;
            font-weight: 600;
            color: var(--mb-text-3, #94A3B8);
            text-transform: uppercase;
            letter-spacing: .06em;
            flex-shrink: 0;
        }
        .gdl-engine-sel-btns {
            display: flex;
            border: 1px solid var(--mb-border, #E2E8F0);
            border-radius: .45rem;
            overflow: hidden;
        }
        .gdl-engine-sel-btns input[type="radio"] { display: none; }
        .gdl-engine-sel-btns label {
            padding: .35rem .875rem;
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            color: var(--mb-text-2, #475569);
            background: var(--mb-surface, #fff);
            transition: background .12s, color .12s;
            user-select: none;
            border-right: 1px solid var(--mb-border, #E2E8F0);
            font-family: monospace;
        }
        .gdl-engine-sel-btns label:first-of-type { font-family: inherit; }
        .gdl-engine-sel-btns label:last-child { border-right: none; }
        .gdl-engine-sel-btns input[type="radio"]:checked + label {
            background: var(--mb-accent, #6366F1);
            color: #fff;
        }

        /* ── Format toggle ── */
        .gdl-format-row {
            display: flex;
            align-items: center;
            gap: .625rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .gdl-format-label {
            font-size: .78rem;
            font-weight: 600;
            color: var(--mb-text-3, #94A3B8);
            text-transform: uppercase;
            letter-spacing: .06em;
            flex-shrink: 0;
        }
        .gdl-format-btns {
            display: flex;
            border: 1px solid var(--mb-border, #E2E8F0);
            border-radius: .45rem;
            overflow: hidden;
        }
        .gdl-format-btns input[type="radio"] { display: none; }
        .gdl-format-btns label {
            padding: .35rem .875rem;
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            color: var(--mb-text-2, #475569);
            background: var(--mb-surface, #fff);
            transition: background .12s, color .12s;
            user-select: none;
            border-right: 1px solid var(--mb-border, #E2E8F0);
        }
        .gdl-format-btns label:last-child { border-right: none; }
        .gdl-format-btns input[type="radio"]:checked + label {
            background: var(--mb-accent, #6366F1);
            color: #fff;
        }
        .gdl-format-hint {
            font-size: .75rem;
            color: var(--mb-text-3, #94A3B8);
        }

        /* ── Pipeline ── */
        .gdl-pipeline {
            display: flex;
            align-items: flex-start;
            padding: 1rem 1.125rem;
            background: var(--mb-page-bg, #F1F5F9);
            border: 1px solid var(--mb-border, #E2E8F0);
            border-radius: 0.5rem 0.5rem 0 0;
            border-bottom: none;
            overflow-x: auto;
            gap: 0;
        }
        .gdl-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            flex: 1;
            min-width: 80px;
        }
        .gdl-step-num {
            width: 26px; height: 26px;
            border-radius: 50%;
            background: var(--mb-accent, #6366F1);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 0.375rem;
            flex-shrink: 0;
        }
        .gdl-step-title {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--mb-text-1, #0F172A);
            margin-bottom: 0.2rem;
            white-space: nowrap;
        }
        .gdl-step-detail {
            font-size: 0.63rem;
            color: var(--mb-text-3, #94A3B8);
            line-height: 1.5;
        }
        .gdl-arr {
            color: var(--mb-text-3, #94A3B8);
            font-size: 1rem;
            padding-top: 0.35rem;
            flex-shrink: 0;
            padding-left: 0.25rem;
            padding-right: 0.25rem;
        }

        /* ── Engines ── */
        .gdl-engines {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 0;
            border: 1px solid var(--mb-border, #E2E8F0);
            border-radius: 0 0 0.5rem 0.5rem;
            margin-bottom: 1.25rem;
            overflow: hidden;
        }
        .gdl-engine {
            padding: 0.75rem 1rem;
            background: var(--mb-surface, #fff);
        }
        .gdl-engine + .gdl-engine {
            border-left: 1px solid var(--mb-border, #E2E8F0);
        }
        .gdl-engine-hdr {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.375rem;
        }
        .gdl-engine-badge {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-radius: 9999px;
            padding: 0.15rem 0.5rem;
            flex-shrink: 0;
        }
        .gdl-badge--primary  { background: #EEF2FF; color: #4338CA; }
        .gdl-badge--fallback { background: #FFFBEB; color: #92400E; }
        .gdl-badge--last     { background: #F0FDF4; color: #166534; }
        .gdl-engine-name {
            font-size: 0.8125rem;
            font-weight: 700;
            color: var(--mb-text-1, #0F172A);
            font-family: monospace;
        }
        .gdl-engine-desc {
            font-size: 0.75rem;
            color: var(--mb-text-2, #475569);
            line-height: 1.5;
            margin-bottom: 0.35rem;
        }
        .gdl-engine-sites {
            font-size: 0.68rem;
            color: var(--mb-text-3, #94A3B8);
            line-height: 1.5;
        }
        .gdl-engine-sites a { color: var(--mb-link, #6366F1); }

        /* ── Info grid ── */
        .gdl-infobar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.875rem;
            padding: 0.875rem 1rem;
            background: var(--mb-page-bg, #F1F5F9);
            border: 1px solid var(--mb-border, #E2E8F0);
            border-radius: 0.5rem;
            margin-bottom: 1.25rem;
        }
        .gdl-info-cell { display: flex; flex-direction: column; gap: 0.3rem; }
        .gdl-info-key {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--mb-text-3, #94A3B8);
        }
        .gdl-info-val {
            font-size: 0.8125rem;
            color: var(--mb-text-2, #475569);
            line-height: 1.5;
        }
        .gdl-info-val code {
            background: var(--mb-surface, #fff);
            border: 1px solid var(--mb-border, #E2E8F0);
            border-radius: 0.25rem;
            padding: 0.1em 0.35em;
            font-size: 0.78rem;
            color: var(--mb-accent-text, #4338CA);
            white-space: nowrap;
        }

        /* ── User note (non-admin queued submission) ── */
        .gdl-user-note {
            padding: .6rem .875rem;
            background: #EFF6FF;
            border: 1px solid #BFDBFE;
            border-radius: .5rem;
            color: #1D4ED8;
            font-size: .8125rem;
            margin-bottom: .875rem;
        }

        /* ── Footer hint ── */
        .gdl-hint {
            font-size: 0.8rem;
            color: var(--mb-text-3, #94A3B8);
            margin-top: 0.625rem;
        }

        /* ── Responsive ── */
        @media (max-width: 700px) {
            .gdl-infobar { grid-template-columns: 1fr 1fr; }
            .gdl-url-row { flex-direction: column; }
            .gdl-submit-btn { width: 100%; justify-content: center; padding: 0.6rem; }
            .gdl-step-detail { display: none; }
        }
        @media (max-width: 700px) {
            .gdl-engines { grid-template-columns: 1fr; }
            .gdl-engine + .gdl-engine { border-left: none; border-top: 1px solid var(--mb-border, #E2E8F0); }
        }
        @media (max-width: 480px) {
            .gdl-infobar { grid-template-columns: 1fr; }
        }
        </style>

        <div class="gdl-wrap">

            <p class="gdl-desc">
                Triple-engine ingest pipeline: <strong>gallery-dl</strong> handles images,
                albums, and galleries from 300+ sites. <strong>yt-dlp</strong> covers video
                platforms (YouTube, Vimeo, Twitch, etc.) and 1000+ more. For any other URL,
                <strong>SingleFile</strong> archives the full webpage as a PDF. Every ingested
                file is deduplicated by SHA-256 and stored with its original source URL.
            </p>

            {$user_note}

            <form method="POST" action="{$action}" id="gdl-form">
                <input type="hidden" name="auth_token" value="{$token}">

                <div class="gdl-url-row">
                    <input
                        type="url"
                        name="ingest_url"
                        id="gdl-url"
                        placeholder="https://www.instagram.com/p/... or https://www.youtube.com/watch?v=..."
                        required
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <button type="submit" class="gdl-submit-btn" id="gdl-btn">
                        <span class="gdl-spinner" id="gdl-spinner"></span>
                        <span id="gdl-btn-label">{$btn_label}</span>
                    </button>
                </div>
                <div class="gdl-title-row">
                    <input
                        type="text"
                        name="ingest_title"
                        id="gdl-title"
                        placeholder="Post title (optional)"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
            </form>

            <!-- Engine selector -->
            <div class="gdl-engine-sel-row">
                <span class="gdl-engine-sel-label">Engine</span>
                <div class="gdl-engine-sel-btns">
                    <input type="radio" name="ingest_engine" id="eng-auto" value="auto" form="gdl-form" checked>
                    <label for="eng-auto">Auto</label>
                    <input type="radio" name="ingest_engine" id="eng-gdl" value="gallery-dl" form="gdl-form">
                    <label for="eng-gdl">gallery-dl</label>
                    <input type="radio" name="ingest_engine" id="eng-ytdlp" value="yt-dlp" form="gdl-form">
                    <label for="eng-ytdlp">yt-dlp</label>
                    <input type="radio" name="ingest_engine" id="eng-sf" value="singlefile" form="gdl-form">
                    <label for="eng-sf">singlefile</label>
                </div>
            </div>

            <!-- Archive format toggle (applies when SingleFile is used) -->
            <div class="gdl-format-row" id="gdl-format-row">
                <span class="gdl-format-label">Archive format</span>
                <div class="gdl-format-btns">
                    <input type="radio" name="ingest_format" id="fmt-pdf" value="pdf" form="gdl-form" checked>
                    <label for="fmt-pdf">PDF</label>
                    <input type="radio" name="ingest_format" id="fmt-html" value="html" form="gdl-form">
                    <label for="fmt-html">HTML page</label>
                </div>
                <span class="gdl-format-hint">Used by SingleFile when archiving webpages</span>
            </div>

            <!-- Pipeline steps -->
            <div class="gdl-pipeline">
                <div class="gdl-step">
                    <div class="gdl-step-num">1</div>
                    <div class="gdl-step-title">Validate</div>
                    <div class="gdl-step-detail">http/https<br>scheme only<br>URL sanitized</div>
                </div>
                <div class="gdl-arr">&#10142;</div>
                <div class="gdl-step">
                    <div class="gdl-step-num">2</div>
                    <div class="gdl-step-title">Download</div>
                    <div class="gdl-step-detail">gallery-dl<br>yt-dlp<br>or singlefile</div>
                </div>
                <div class="gdl-arr">&#10142;</div>
                <div class="gdl-step">
                    <div class="gdl-step-num">3</div>
                    <div class="gdl-step-title">Filter</div>
                    <div class="gdl-step-detail">MIME detected<br>metadata &amp;<br>captions skipped</div>
                </div>
                <div class="gdl-arr">&#10142;</div>
                <div class="gdl-step">
                    <div class="gdl-step-num">4</div>
                    <div class="gdl-step-title">Deduplicate</div>
                    <div class="gdl-step-detail">SHA-256 hash<br>compared against<br>existing posts</div>
                </div>
                <div class="gdl-arr">&#10142;</div>
                <div class="gdl-step">
                    <div class="gdl-step-num">5</div>
                    <div class="gdl-step-title">Archive</div>
                    <div class="gdl-step-detail">Post created<br>source URL saved<br>tags applied</div>
                </div>
            </div>

            <!-- Engines -->
            <div class="gdl-engines">
                <div class="gdl-engine">
                    <div class="gdl-engine-hdr">
                        <span class="gdl-engine-badge gdl-badge--primary">Primary</span>
                        <span class="gdl-engine-name">gallery-dl</span>
                    </div>
                    <p class="gdl-engine-desc">
                        Specialist image and gallery downloader.
                        Extracts full albums, profiles, and image boards from 300+ sites.
                        Runs first on every URL.
                    </p>
                    <div class="gdl-engine-sites">
                        Instagram &bull; DeviantArt &bull; Flickr &bull; Danbooru &bull; Reddit &bull; Twitter/X &bull; Pinterest &bull; Tumblr
                        &bull; <a href="https://github.com/mikf/gallery-dl/blob/master/docs/supportedsites.md"
                                  target="_blank" rel="noopener">300+ more</a>
                    </div>
                </div>
                <div class="gdl-engine">
                    <div class="gdl-engine-hdr">
                        <span class="gdl-engine-badge gdl-badge--fallback">Fallback</span>
                        <span class="gdl-engine-name">yt-dlp</span>
                    </div>
                    <p class="gdl-engine-desc">
                        Video platform downloader. Activates automatically when gallery-dl
                        has no extractor for the URL.
                    </p>
                    <div class="gdl-engine-sites">
                        YouTube &bull; Vimeo &bull; Twitch &bull; TikTok &bull; Bilibili &bull; Dailymotion &bull; Niconico
                        &bull; <a href="https://github.com/yt-dlp/yt-dlp/blob/master/supportedsites.md"
                                  target="_blank" rel="noopener">1000+ more</a>
                    </div>
                </div>
                <div class="gdl-engine">
                    <div class="gdl-engine-hdr">
                        <span class="gdl-engine-badge gdl-badge--last">Last resort</span>
                        <span class="gdl-engine-name">singlefile</span>
                    </div>
                    <p class="gdl-engine-desc">
                        Webpage archiver. Saves any URL as a self-contained PDF when
                        gallery-dl and yt-dlp have no extractor.
                    </p>
                    <div class="gdl-engine-sites">
                        News articles &bull; Blogs &bull; Recipe sites &bull; Wikis &bull; Forums &bull; Any public webpage
                    </div>
                </div>
            </div>

            <!-- Info grid -->
            <div class="gdl-infobar">
                <div class="gdl-info-cell">
                    <span class="gdl-info-key">Auto-applied tags</span>
                    <span class="gdl-info-val"><code>gallery-dl:ingested</code></span>
                </div>
                <div class="gdl-info-cell">
                    <span class="gdl-info-key">Source URL</span>
                    <span class="gdl-info-val">Saved on every imported post</span>
                </div>
                <div class="gdl-info-cell">
                    <span class="gdl-info-key">Duplicates</span>
                    <span class="gdl-info-val">Skipped automatically via SHA-256</span>
                </div>
                <div class="gdl-info-cell">
                    <span class="gdl-info-key">Webpage support</span>
                    <span class="gdl-info-val">Any URL archived as PDF via SingleFile + Chromium</span>
                </div>
            </div>

            <p class="gdl-hint">
                &#9432;&nbsp; Large galleries and playlists may take several minutes.
                The page will redirect automatically when the ingest is complete.
                The result flash message shows which engine was used.
            </p>

        </div>

        <script>
        (function () {
            var form       = document.getElementById('gdl-form');
            var btn        = document.getElementById('gdl-btn');
            var spinner    = document.getElementById('gdl-spinner');
            var label      = document.getElementById('gdl-btn-label');
            var formatRow  = document.getElementById('gdl-format-row');
            var urlInput   = document.getElementById('gdl-url');

            var placeholders = {
                'auto':       'https://www.instagram.com/p/... or https://www.youtube.com/watch?v=...',
                'gallery-dl': 'https://www.instagram.com/p/... or https://danbooru.donmai.us/posts/...',
                'yt-dlp':     'https://www.youtube.com/watch?v=... or https://vimeo.com/...',
                'singlefile': 'https://example.com/article/... (any webpage)'
            };

            function onEngineChange() {
                var sel = document.querySelector('input[name="ingest_engine"]:checked');
                var engine = sel ? sel.value : 'auto';
                // Show format toggle only when singlefile is relevant
                formatRow.style.display = (engine === 'auto' || engine === 'singlefile') ? '' : 'none';
                // Update placeholder
                urlInput.placeholder = placeholders[engine] || placeholders['auto'];
            }

            document.querySelectorAll('input[name="ingest_engine"]').forEach(function (input) {
                input.addEventListener('change', onEngineChange);
            });
            onEngineChange();

            // Disable button and show spinner on submit
            form.addEventListener('submit', function () {
                btn.disabled      = true;
                spinner.classList.add('visible');
                label.textContent = 'Ingesting…';
            });
        }());
        </script>
        HTML;

        Ctx::$page->add_block(new Block("Multiplatform Ingest", rawHTML($html), "main", 20));
    }

    // -------------------------------------------------------------------------
    // Notification badge — rendered in the header user area on every page
    // -------------------------------------------------------------------------

    public function display_notification_badge(int $count): void
    {
        $url = make_link("admin/gallerydl_queue");
        $label = $count === 1 ? '1 ingest request' : "{$count} ingest requests";

        $html = <<<HTML
        <style>
        .mb-ingest-notify {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .28rem .7rem;
            border-radius: 9999px;
            background: rgba(239,68,68,.1);
            color: #DC2626;
            font-size: .78rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid rgba(239,68,68,.25);
            transition: background .15s;
            white-space: nowrap;
        }
        .mb-ingest-notify:hover { background: rgba(239,68,68,.2); }
        .mb-ingest-badge {
            background: #DC2626;
            color: #fff;
            border-radius: 9999px;
            padding: .05rem .42rem;
            font-size: .68rem;
            font-weight: 700;
            min-width: 1.1em;
            text-align: center;
        }
        </style>
        <a href="{$url}" class="mb-ingest-notify" title="{$label} pending approval">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3z"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            Ingest
            <span class="mb-ingest-badge">{$count}</span>
        </a>
        HTML;

        Ctx::$page->add_block(new Block(null, rawHTML($html), "user", 5));
    }

    // -------------------------------------------------------------------------
    // Admin queue management page
    // -------------------------------------------------------------------------

    /** @param array<int,array<string,mixed>> $items */
    public function display_queue_page(array $items): void
    {
        $approve_url = make_link("admin/gallerydl_queue/approve");
        $reject_url  = make_link("admin/gallerydl_queue/reject");
        $token       = Ctx::$user->get_auth_token();

        $pending_count = count(array_filter($items, fn($r) => $r['status'] === 'pending'));
        $done_count    = count(array_filter($items, fn($r) => $r['status'] === 'done'));
        $failed_count  = count(array_filter($items, fn($r) => $r['status'] === 'failed'));
        $rejected_count = count(array_filter($items, fn($r) => $r['status'] === 'rejected'));

        $rows_html = '';
        if (empty($items)) {
            $rows_html = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--mb-text-3,#94A3B8);">No ingest requests yet.</td></tr>';
        } else {
            foreach ($items as $item) {
                $id       = (int)$item['id'];
                $username = htmlspecialchars((string)$item['username'], ENT_QUOTES, 'UTF-8');
                $url_raw  = (string)$item['url'];
                $url_esc  = htmlspecialchars($url_raw, ENT_QUOTES, 'UTF-8');
                $url_short = htmlspecialchars(
                    strlen($url_raw) > 55 ? substr($url_raw, 0, 52) . '…' : $url_raw,
                    ENT_QUOTES, 'UTF-8'
                );
                $title_esc = htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8');
                $created   = htmlspecialchars((string)$item['created_at'], ENT_QUOTES, 'UTF-8');
                $status    = (string)$item['status'];
                $result    = htmlspecialchars((string)($item['result_message'] ?? ''), ENT_QUOTES, 'UTF-8');

                $status_badge = match ($status) {
                    'pending'  => '<span class="gdl-q-badge gdl-q-badge--pending">Pending</span>',
                    'done'     => '<span class="gdl-q-badge gdl-q-badge--done">Done</span>',
                    'failed'   => '<span class="gdl-q-badge gdl-q-badge--failed">Failed</span>',
                    'rejected' => '<span class="gdl-q-badge gdl-q-badge--rejected">Rejected</span>',
                    default    => '<span class="gdl-q-badge">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>',
                };

                $actions_html = '';
                if ($status === 'pending') {
                    $actions_html = <<<ACT
                    <form method="POST" action="{$approve_url}" style="display:inline">
                        <input type="hidden" name="auth_token" value="{$token}">
                        <input type="hidden" name="queue_id" value="{$id}">
                        <button type="submit" class="gdl-q-btn gdl-q-btn--approve">Approve</button>
                    </form>
                    <form method="POST" action="{$reject_url}" style="display:inline;margin-left:.35rem">
                        <input type="hidden" name="auth_token" value="{$token}">
                        <input type="hidden" name="queue_id" value="{$id}">
                        <button type="submit" class="gdl-q-btn gdl-q-btn--reject">Reject</button>
                    </form>
                    ACT;
                } elseif ($result !== '') {
                    $actions_html = '<span class="gdl-q-result" title="' . $result . '">'
                        . (strlen($result) > 60 ? substr($result, 0, 57) . '…' : $result)
                        . '</span>';
                }

                $rows_html .= <<<ROW
                <tr class="gdl-q-row gdl-q-row--{$status}">
                    <td class="gdl-q-cell">{$username}</td>
                    <td class="gdl-q-cell"><a href="{$url_esc}" target="_blank" rel="noopener" title="{$url_esc}">{$url_short}</a></td>
                    <td class="gdl-q-cell">{$title_esc}</td>
                    <td class="gdl-q-cell gdl-q-cell--date">{$created}</td>
                    <td class="gdl-q-cell">{$status_badge}</td>
                    <td class="gdl-q-cell gdl-q-cell--actions">{$actions_html}</td>
                </tr>
                ROW;
            }
        }

        $html = <<<HTML
        <style>
        /* ── Queue stats ── */
        .gdl-q-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: .875rem;
            padding: .875rem 1rem;
            background: var(--mb-page-bg, #F1F5F9);
            border: 1px solid var(--mb-border, #E2E8F0);
            border-radius: .5rem;
            margin-bottom: 1.25rem;
            font-size: .8125rem;
        }
        .gdl-q-stat-key {
            font-size: .6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--mb-text-3, #94A3B8);
            margin-bottom: .2rem;
        }
        .gdl-q-stat-val {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--mb-text-1, #0F172A);
        }
        /* ── Table ── */
        .gdl-q-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .8125rem;
        }
        .gdl-q-table thead th {
            text-align: left;
            padding: .55rem .75rem;
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--mb-text-3, #94A3B8);
            border-bottom: 2px solid var(--mb-border, #E2E8F0);
            background: var(--mb-page-bg, #F1F5F9);
        }
        .gdl-q-cell {
            padding: .6rem .75rem;
            border-bottom: 1px solid var(--mb-border, #E2E8F0);
            vertical-align: middle;
            color: var(--mb-text-2, #475569);
        }
        .gdl-q-cell a { color: var(--mb-link, #6366F1); word-break: break-all; }
        .gdl-q-cell--date { white-space: nowrap; font-size: .75rem; }
        .gdl-q-cell--actions { white-space: nowrap; }
        .gdl-q-row--pending { background: #FFFBEB; }
        .gdl-q-row--done    { background: #F0FDF4; }
        .gdl-q-row--failed  { background: #FFF1F2; }
        /* ── Status badges ── */
        .gdl-q-badge {
            display: inline-block;
            padding: .15rem .55rem;
            border-radius: 9999px;
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .gdl-q-badge--pending  { background: #FEF3C7; color: #92400E; }
        .gdl-q-badge--done     { background: #DCFCE7; color: #166534; }
        .gdl-q-badge--failed   { background: #FFE4E6; color: #9F1239; }
        .gdl-q-badge--rejected { background: #F1F5F9; color: #64748B; }
        /* ── Action buttons ── */
        .gdl-q-btn {
            padding: .28rem .75rem;
            border: none;
            border-radius: .375rem;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: background .15s;
        }
        .gdl-q-btn--approve { background: #22C55E; color: #fff; }
        .gdl-q-btn--approve:hover { background: #16A34A; }
        .gdl-q-btn--reject  { background: #EF4444; color: #fff; }
        .gdl-q-btn--reject:hover  { background: #DC2626; }
        .gdl-q-result {
            font-size: .75rem;
            color: var(--mb-text-3, #94A3B8);
            font-style: italic;
        }
        @media (max-width: 700px) {
            .gdl-q-stats { grid-template-columns: 1fr 1fr; }
            .gdl-q-cell--date { display: none; }
        }
        </style>

        <div class="gdl-wrap">

            <div class="gdl-q-stats">
                <div><div class="gdl-q-stat-key">Pending</div><div class="gdl-q-stat-val">{$pending_count}</div></div>
                <div><div class="gdl-q-stat-key">Done</div><div class="gdl-q-stat-val">{$done_count}</div></div>
                <div><div class="gdl-q-stat-key">Failed</div><div class="gdl-q-stat-val">{$failed_count}</div></div>
                <div><div class="gdl-q-stat-key">Rejected</div><div class="gdl-q-stat-val">{$rejected_count}</div></div>
            </div>

            <table class="gdl-q-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>URL</th>
                        <th>Title</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {$rows_html}
                </tbody>
            </table>

        </div>
        HTML;

        Ctx::$page->set_title("Ingest Queue");
        Ctx::$page->add_block(new Block("Ingest Queue", rawHTML($html), "main", 10));
    }
}
