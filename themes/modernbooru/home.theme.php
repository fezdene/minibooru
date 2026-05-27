<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\emptyHTML;
use MicroHTML\HTMLElement;

/**
 * Standalone, full-viewport homepage in the style of a modern SaaS landing
 * page (dark radial-gradient background, large bold headline, search CTA).
 * Layout is completely decoupled from the modernbooru sidebar/topbar shell so
 * the page works as a real landing page.  All visual parameters are editable
 * via Board Config → Home Page.
 */
class ModernbooruHomeTheme extends HomeTheme
{
    private string      $cachedSitename = '';
    private int         $cachedPosts    = 0;
    private int         $cachedTags     = 0;
    private string      $cachedLinks    = '';
    private string      $cachedText     = '';

    // ── Called by Home::onPageRequest() ──────────────────────────────────────

    public function display_page(string $sitename, HTMLElement $body): void
    {
        // build_body() has already been called by get_body() before this, so
        // the cached counts are ready.  Render a complete standalone document.
        Ctx::$page->set_data(MimeType::HTML, $this->render_standalone($sitename));
    }

    public function build_body(
        string $sitename,
        HTMLElement $main_links,
        ?string $main_text,
        ?string $contact_link,
        int $post_count,
    ): HTMLElement {
        $this->cachedSitename = $sitename;
        $this->cachedPosts    = $post_count;
        $this->cachedTags     = (int)Ctx::$database->get_one(
            "SELECT COUNT(*) FROM tags WHERE count > 0"
        );
        $this->cachedLinks    = (string)$main_links;
        $this->cachedText     = (string)($main_text ?? '');
        return emptyHTML();
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    private function render_standalone(string $sitename): string
    {
        $cfg = Ctx::$config;

        $tagline     = trim((string)($cfg->get(HomeConfig::TAGLINE) ?? ''));
        $tagline     = $tagline !== '' ? $tagline : $sitename;
        $subtitle    = htmlspecialchars((string)($cfg->get(HomeConfig::SUBTITLE) ?? 'A curated digital media archive.'), ENT_QUOTES);
        $grad_dark   = $this->safe_color((string)($cfg->get(HomeConfig::GRAD_DARK)  ?? '#050a1a'));
        $grad_mid    = $this->safe_color((string)($cfg->get(HomeConfig::GRAD_MID)   ?? '#0d1b3e'));
        $grad_glow   = $this->safe_color((string)($cfg->get(HomeConfig::GRAD_GLOW)  ?? '#1d6ae5'));
        $cta_text    = htmlspecialchars((string)($cfg->get(HomeConfig::CTA_TEXT) ?? 'Search'), ENT_QUOTES);
        $show_recent = (bool)($cfg->get(HomeConfig::SHOW_RECENT, ConfigType::BOOL) ?? true);

        $tagline_esc = htmlspecialchars($tagline, ENT_QUOTES);
        $post_fmt    = number_format($this->cachedPosts);
        $tag_fmt     = number_format($this->cachedTags);

        $base        = (string)Url::base();
        $list_url    = (string)make_link('post/list');
        $tags_url    = (string)make_link('tags/popularity');

        // ── Navbar ────────────────────────────────────────────────────────────
        $nav_links = $this->nav_links_html();
        $auth_html = $this->auth_html();

        // ── Search form ───────────────────────────────────────────────────────
        // SHM_FORM adds the hidden q= input required for Shimmie2 routing.
        $form_obj = SHM_FORM(
            action: make_link('post/list'),
            method: "GET",
            children: [
                \MicroHTML\INPUT([
                    "type"        => "search",
                    "name"        => "search",
                    "placeholder" => "Try: book, nature, video, art…",
                    "class"       => "sp-search-input autocomplete_tags",
                    "autofocus"   => true,
                ]),
                \MicroHTML\BUTTON(["type" => "submit", "class" => "sp-search-btn"], $cta_text),
            ]
        );
        $form_html = (string)$form_obj;

        // ── Recent posts grid (optional) ──────────────────────────────────────
        $recent_html = '';
        if ($show_recent) {
            $recent_html = $this->recent_grid_html($list_url);
        }

        // ── How it works tutorial ─────────────────────────────────────────────
        $ingest_url  = (string)make_link('upload');
        $network_url = (string)make_link('network');
        $howto_html  = $this->howto_html($ingest_url, $network_url);

        // ── About project hover panel (subtitle + page text + page links) ───────
        $about_inner = '';
        if ($subtitle !== '') {
            $about_inner .= "<p class=\"sp-about-item\">{$subtitle}</p>";
        }
        if ($this->cachedText !== '') {
            $text_esc     = htmlspecialchars($this->cachedText, ENT_QUOTES);
            $about_inner .= "<p class=\"sp-about-item\">{$text_esc}</p>";
        }

        $custom_links_raw = trim((string)($cfg->get(HomeConfig::LINKS) ?? ''));
        $config_links = '';
        if ($custom_links_raw !== '' && trim(strip_tags($this->cachedLinks)) !== '') {
            $config_links = $this->process_page_links($this->cachedLinks);
        }
        $links_row_html = '<div class="sp-about-links">'
            . $config_links
            . '<a href="https://github.com/fezdene" target="_blank" rel="noopener noreferrer">github.com/fezdene</a>'
            . '</div>';

        $about_panel = $about_inner !== ''
            ? "<div class=\"sp-about-panel\" role=\"region\" aria-label=\"About this project\">{$about_inner}</div>"
            : '';
        $chevron = '<svg class="sp-about-chevron" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2,4 6,8 10,4"/></svg>';
        $about_links_html = <<<HTML
<div class="sp-meta-row">
  <div class="sp-about-wrap">
    <button class="sp-about-trigger" aria-expanded="false"
      onclick="var w=this.closest('.sp-about-wrap');var open=w.classList.toggle('open');this.setAttribute('aria-expanded',open)">
      About project {$chevron}
    </button>
    {$about_panel}
  </div>
  {$links_row_html}
</div>
HTML;

        // ── Fonts & initscript (autocomplete needs the JS bundle) ─────────────
        $init_script = $this->init_script_tag($base);

        // ── CSS ───────────────────────────────────────────────────────────────
        $css = $this->page_css($grad_dark, $grad_mid, $grad_glow);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$tagline_esc}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Inter:wght@400;500;600;700;800;900&family=Anonymous+Pro&display=swap" rel="stylesheet">
<link rel="icon" type="image/x-icon" href="{$base}favicon.ico">
{$init_script}
<style>{$css}</style>
</head>
<body>

<div class="sp-bg" aria-hidden="true"></div>
<div class="sp-char-bg" aria-hidden="true"></div>

<nav class="sp-nav">
  <a href="{$base}" class="sp-nav-brand">{$tagline_esc}</a>
  <div class="sp-nav-links">{$nav_links}</div>
  <div class="sp-nav-auth">{$auth_html}</div>
</nav>

<main>
  <section class="sp-hero">
    <h1 class="sp-hero-title">{$tagline_esc}</h1>
    <div class="sp-typewriter" aria-label="404 new era : anti link rot">
      <span class="cursor typewriter-animation">404 new era : anti link rot</span>
    </div>
    <div class="sp-search-wrap">
      {$form_html}
    </div>
    <div class="sp-chips">
      <a href="{$list_url}" class="sp-chip">{$post_fmt} posts</a>
      <a href="{$tags_url}" class="sp-chip">{$tag_fmt} tags</a>
    </div>
    {$about_links_html}
    <div class="sp-scroll-hint" aria-hidden="true">
      <span class="sp-scroll-arrow"></span>
    </div>
  </section>

  {$recent_html}
  {$howto_html}
</main>

<script>
(function(){
  var bg=document.querySelector('.sp-char-bg');
  if(!bg)return;
  var chars='01';
  var size=window.innerWidth*0.02;
  var cols=Math.ceil(window.innerWidth/size)+1;
  var rows=Math.ceil(window.innerHeight/size)+1;
  var frag=document.createDocumentFragment();
  for(var i=0;i<cols*rows;i++){
    var s=document.createElement('span');
    s.textContent=chars[Math.floor(Math.random()*2)];
    frag.appendChild(s);
  }
  bg.appendChild(frag);
  var spans=Array.from(bg.children);

  /* Ambient character flipping */
  setInterval(function(){
    spans[Math.floor(Math.random()*spans.length)].textContent=chars[Math.floor(Math.random()*2)];
  },80);

  /* Circular mask follows cursor */
  document.addEventListener('mousemove',function(e){
    var mask='radial-gradient(circle 230px at '+e.clientX+'px '+e.clientY+'px,#000 0%,rgba(0,0,0,.5) 55%,transparent 100%)';
    bg.style.webkitMaskImage=mask;
    bg.style.maskImage=mask;
  });
})();
</script>
</body>
</html>
HTML;
    }

    // ── Sub-renderers ─────────────────────────────────────────────────────────

    private function nav_links_html(): string
    {
        $links = [
            'post/list'    => 'Posts',
            'comment/list' => 'Comments',
            'tags'         => 'Tags',
            'help'         => 'Help',
        ];
        $out = '';
        foreach ($links as $route => $label) {
            $url = (string)make_link($route);
            $out .= "<a href=\"{$url}\" class=\"sp-nav-link\">{$label}</a>";
        }
        return $out;
    }

    private function auth_html(): string
    {
        $user = Ctx::$user;
        if ($user->is_anonymous()) {
            $login    = (string)make_link('user_admin/login');
            $register = (string)make_link('user_admin/create');
            return "<a href=\"{$login}\" class=\"sp-btn-ghost\">Log in</a>"
                 . "<a href=\"{$register}\" class=\"sp-btn-primary\">Register</a>";
        }
        $profile = (string)make_link('user/' . rawurlencode($user->name));
        $name    = htmlspecialchars($user->name, ENT_QUOTES);
        return "<a href=\"{$profile}\" class=\"sp-btn-primary\">{$name}</a>";
    }

    private function recent_grid_html(string $list_url): string
    {
        $posts = Search::find_posts(0, 12, []);
        if (empty($posts)) {
            return '';
        }
        $thumbs = '';
        foreach ($posts as $post) {
            $post_url  = (string)make_link("post/view/{$post->id}");
            $thumb_url = htmlspecialchars((string)$post->get_thumb_link(), ENT_QUOTES);
            $alt       = htmlspecialchars("Post #{$post->id}", ENT_QUOTES);
            $thumbs   .= "<a href=\"{$post_url}\" class=\"sp-thumb\">"
                       . "<img src=\"{$thumb_url}\" alt=\"{$alt}\" loading=\"lazy\">"
                       . "</a>";
        }
        return <<<HTML
<section class="sp-recent">
  <div class="sp-recent-header">
    <span class="sp-recent-title">Recent Posts</span>
    <a href="{$list_url}" class="sp-recent-all">Browse all →</a>
  </div>
  <div class="sp-grid">{$thumbs}</div>
</section>
HTML;
    }

    private function howto_html(string $ingest_url, string $network_url): string
    {
        $steps = [
            [
                'num'   => '01',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
                'title' => 'Search the archive',
                'desc'  => 'Type any tag into the search bar and press Search. Combine multiple tags with spaces to narrow down results. Click any thumbnail to view the full post.',
                'link'  => '',
                'cta'   => '',
            ],
            [
                'num'   => '02',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
                'title' => 'Archive any URL',
                'desc'  => 'Paste a URL into the Ingest page. The system tries gallery-dl → yt-dlp → SingleFile in order — images, videos, and full webpages are all supported.',
                'link'  => $ingest_url,
                'cta'   => 'Open Ingest →',
            ],
            [
                'num'   => '03',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
                'title' => 'SHA-256 integrity',
                'desc'  => 'Every file is hashed with SHA-256 on upload. Mirror nodes audit all files every 5 minutes and automatically pull a clean copy from the master if corruption is detected.',
                'link'  => '',
                'cta'   => '',
            ],
            [
                'num'   => '04',
                'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                'title' => 'Network replication',
                'desc'  => 'The master node pushes all content to both mirror nodes every 30 seconds over RSYNC/SSH. All three nodes stay in sync automatically — no manual action needed.',
                'link'  => $network_url,
                'cta'   => 'View network →',
            ],
        ];

        $cards = '';
        foreach ($steps as $s) {
            $cta = $s['link'] !== ''
                ? "<a href=\"{$s['link']}\" class=\"sp-howto-cta\">{$s['cta']}</a>"
                : '';
            $cards .= <<<HTML
    <div class="sp-howto-card">
      <div class="sp-howto-top">
        <span class="sp-howto-num">{$s['num']}</span>
        <span class="sp-howto-icon">{$s['icon']}</span>
      </div>
      <h3 class="sp-howto-heading">{$s['title']}</h3>
      <p class="sp-howto-desc">{$s['desc']}</p>
      {$cta}
    </div>
HTML;
        }

        return <<<HTML
<section class="sp-howto">
  <div class="sp-howto-inner">
    <h2 class="sp-howto-title">How it works</h2>
    <p class="sp-howto-sub">Everything you need to preserve and access content — in four steps.</p>
    <div class="sp-howto-grid">
{$cards}
    </div>
  </div>
</section>
HTML;
    }

    private function process_page_links(string $html): string
    {
        // 1. format_text() wraps untagged URLs in <span class='bbcode'>URL</span>
        //    instead of <a> tags. Convert those spans to proper anchors.
        $html = preg_replace_callback(
            '/<span[^>]*bbcode[^>]*>(https?:\/\/[^\s<]+)<\/span>/i',
            function (array $m): string {
                $url   = htmlspecialchars($m[1], ENT_QUOTES);
                $host  = (string)(parse_url($m[1], PHP_URL_HOST) ?? $m[1]);
                $host  = preg_replace('/^www\./', '', $host) ?? $host;
                $label = htmlspecialchars($host, ENT_QUOTES);
                return "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener noreferrer\">{$label}</a>";
            },
            $html
        ) ?? $html;

        // 2. When a BBCode [url=...]...[/url] tag renders with the full URL as its
        //    label (user wrote [url=X]X[/url] or similar), shorten to hostname.
        $html = preg_replace_callback(
            '/<a\b([^>]*)>(https?:\/\/[^<\s]+)<\/a>/i',
            function (array $m): string {
                $attrs = $m[1];
                preg_match('/href=["\']([^"\']*)["\']/', $attrs, $hm);
                $href  = $hm[1] ?? $m[2];
                $host  = (string)(parse_url($href, PHP_URL_HOST) ?? $href);
                $host  = preg_replace('/^www\./', '', $host) ?? $host;
                $label = htmlspecialchars($host, ENT_QUOTES);
                return "<a{$attrs}>{$label}</a>";
            },
            $html
        ) ?? $html;

        // 3. Add target="_blank" + rel to external <a> tags that don't have it yet.
        return preg_replace_callback(
            '/<a\b([^>]*)>/i',
            function (array $m): string {
                $attrs = $m[1];
                if (preg_match('/href=["\']https?:\/\//i', $attrs)
                    && !str_contains($attrs, 'target=')) {
                    return "<a{$attrs} target=\"_blank\" rel=\"noopener noreferrer\">";
                }
                return "<a{$attrs}>";
            },
            $html
        ) ?? $html;
    }

    private function init_script_tag(string $base): string
    {
        $files = array_filter(
            glob("data/cache/script/modernbooru.*.js") ?: [],
            fn($f) => !str_ends_with($f, '.map')
        );
        if (!empty($files)) {
            $file = basename(reset($files));
            return "<script defer src=\"{$base}data/cache/script/{$file}\"></script>";
        }
        return '';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function safe_color(string $color): string
    {
        // Allow only hex colours and basic named colours to prevent CSS injection.
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            return $color;
        }
        if (preg_match('/^[a-zA-Z]+$/', $color)) {
            return $color;
        }
        return '#050a1a';
    }

    private function page_css(string $dark, string $mid, string $glow): string
    {
        return <<<CSS
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:'Google Sans','Inter',system-ui,-apple-system,sans-serif;background:{$dark};color:#fff;min-height:100vh;overflow-x:hidden}

/* ── Background glow ── */
.sp-bg{position:fixed;inset:0;z-index:0;background:{$dark}}

/* ── Character grid background ── */
.sp-char-bg{position:fixed;inset:0;z-index:0;display:flex;flex-wrap:wrap;
  align-content:flex-start;overflow:hidden;pointer-events:none;line-height:1;
  -webkit-mask-image:radial-gradient(circle 230px at -600px -600px,#000 0%,transparent 100%);
  mask-image:radial-gradient(circle 230px at -600px -600px,#000 0%,transparent 100%)}
.sp-char-bg span{display:block;width:2vmax;height:2vmax;font-size:1.75vmax;
  color:rgba(96,165,250,.4);text-align:center;font-family:'Courier New',Courier,monospace;
  line-height:1;user-select:none;
  text-shadow:0 0 8px rgba(147,197,253,.25)}

/* ── Navbar ── */
.sp-nav{position:fixed;top:0;left:0;right:0;z-index:100;display:flex;align-items:center;gap:1rem;
  padding:.875rem 2rem;background:rgba(5,10,26,.55);backdrop-filter:blur(14px);
  border-bottom:1px solid rgba(255,255,255,.07)}
.sp-nav-brand{font-size:1.0625rem;font-weight:800;color:#fff;text-decoration:none;flex-shrink:0;
  letter-spacing:-.02em}
.sp-nav-links{display:flex;gap:.125rem;flex:1;justify-content:center}
.sp-nav-link{color:rgba(255,255,255,.7);font-size:.875rem;font-weight:500;text-decoration:none;
  padding:.375rem .75rem;border-radius:.375rem;transition:color .15s,background .15s}
.sp-nav-link:hover{color:#fff;background:rgba(255,255,255,.09)}
.sp-nav-auth{display:flex;gap:.5rem;align-items:center;flex-shrink:0}
.sp-btn-ghost{color:rgba(255,255,255,.8);font-size:.875rem;font-weight:500;text-decoration:none;
  padding:.375rem .875rem;border-radius:.375rem;transition:color .15s}
.sp-btn-ghost:hover{color:#fff}
.sp-btn-primary{background:#2563eb;color:#fff;font-size:.875rem;font-weight:600;text-decoration:none;
  padding:.4rem 1rem;border-radius:.375rem;transition:background .15s}
.sp-btn-primary:hover{background:#1d4ed8}

/* ── Hero ── */
.sp-hero{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column;
  align-items:center;justify-content:center;text-align:center;padding:7rem 1.5rem 5rem}
.sp-hero-title{font-family:'Inter',system-ui,-apple-system,sans-serif;font-size:clamp(2.75rem,8vw,5.5rem);font-weight:900;line-height:1.04;
  letter-spacing:-.04em;color:#fff;max-width:14ch;margin-bottom:1.125rem}
.sp-hero-sub{display:none}

/* ── Search bar ── */
.sp-search-wrap{width:100%;max-width:530px;margin-bottom:1.625rem}
.sp-search-wrap form{display:flex;align-items:center;
  background:rgba(255,255,255,.96);border:1.5px solid rgba(255,255,255,.25);
  border-radius:.75rem;overflow:hidden;padding:.2rem .2rem .2rem .875rem;
  box-shadow:0 6px 40px rgba(0,0,0,.45)}
.sp-search-input{flex:1;border:none;background:transparent;font-size:.9375rem;color:#0f172a;
  outline:none;padding:.5rem 0;font-family:inherit;min-width:0}
.sp-search-input::placeholder{color:#94a3b8}
.sp-search-btn{background:#2563eb;color:#fff;border:none;border-radius:.55rem;
  padding:.6rem 1.375rem;font-size:.9375rem;font-weight:700;cursor:pointer;
  font-family:inherit;white-space:nowrap;flex-shrink:0;transition:background .15s}
.sp-search-btn:hover{background:#1d4ed8}

/* ── Stat chips ── */
.sp-chips{display:flex;gap:.625rem;justify-content:center;flex-wrap:wrap}
.sp-chip{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.13);
  color:rgba(255,255,255,.75);font-size:.8125rem;font-weight:500;
  padding:.3em 1em;border-radius:9999px;text-decoration:none;transition:background .15s,color .15s}
.sp-chip:hover{background:rgba(255,255,255,.2);color:#fff}

/* ── Typewriter ── */
.sp-typewriter{display:flex;justify-content:center;margin-bottom:1.75rem}
.cursor{display:inline-block;border-right:2px solid rgba(255,255,255,.75);
  font-family:'Anonymous Pro',monospace;font-size:1.0625rem;
  color:rgba(255,255,255,.6);white-space:nowrap;overflow:hidden;letter-spacing:.04em}
.typewriter-animation{
  animation:
    typewriter 3s steps(30) 0.5s 1 normal both,
    blinkingCursor 500ms steps(50) infinite normal}
@keyframes typewriter{from{width:0}to{width:100%}}
@keyframes blinkingCursor{
  from{border-right-color:rgba(255,255,255,.75)}
  to{border-right-color:transparent}}

/* ── About project hover panel ── */
.sp-meta-row{display:flex;align-items:center;gap:.75rem;justify-content:center;
  flex-wrap:wrap;margin-top:1.625rem}
.sp-about-wrap{position:relative}
.sp-about-trigger{display:inline-flex;align-items:center;gap:.375rem;
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);
  color:rgba(255,255,255,.8);font-family:inherit;font-size:.875rem;font-weight:500;
  padding:.4rem 1rem;border-radius:.5rem;cursor:pointer;
  transition:background .15s,border-color .15s,color .15s}
.sp-about-trigger:hover,.sp-about-wrap.open .sp-about-trigger{
  background:rgba(255,255,255,.13);border-color:rgba(255,255,255,.3);color:#fff}
.sp-about-chevron{width:12px;height:12px;flex-shrink:0;
  transition:transform .25s ease}
.sp-about-wrap.open .sp-about-chevron{transform:rotate(180deg)}
.sp-about-panel{position:absolute;bottom:calc(100% + .625rem);left:50%;
  transform:translateX(-50%) translateY(6px);
  width:300px;background:rgba(10,20,50,.94);
  border:1px solid rgba(255,255,255,.11);border-radius:.875rem;
  padding:1.125rem 1.25rem;backdrop-filter:blur(18px);
  opacity:0;pointer-events:none;
  transition:opacity .22s ease,transform .22s ease;z-index:60;text-align:left}
.sp-about-wrap:hover .sp-about-panel,.sp-about-wrap.open .sp-about-panel{
  opacity:1;pointer-events:auto;transform:translateX(-50%) translateY(0)}
.sp-about-item{font-size:.875rem;color:rgba(255,255,255,.65);line-height:1.7}
.sp-about-item+.sp-about-item{margin-top:.625rem;padding-top:.625rem;
  border-top:1px solid rgba(255,255,255,.08)}
.sp-about-links{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.sp-about-links a{color:rgba(255,255,255,.7);font-size:.875rem;font-weight:500;
  text-decoration:none;padding:.4rem 1rem;border-radius:.5rem;
  border:1px solid rgba(255,255,255,.14);
  transition:color .15s,border-color .15s,background .15s}
.sp-about-links a:hover{color:#fff;border-color:rgba(255,255,255,.4);background:rgba(255,255,255,.07)}

/* ── Recent posts ── */
.sp-recent{position:relative;z-index:1;padding:0 1.5rem 5rem;max-width:1200px;margin:0 auto}
.sp-recent-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.125rem}
.sp-recent-title{font-size:1.125rem;font-weight:700;color:rgba(255,255,255,.9)}
.sp-recent-all{color:#60a5fa;font-size:.875rem;font-weight:500;text-decoration:none}
.sp-recent-all:hover{color:#93c5fd}
.sp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:.75rem}
.sp-thumb{aspect-ratio:1;border-radius:.5rem;overflow:hidden;background:rgba(255,255,255,.05);
  display:block;text-decoration:none;transition:transform .2s,box-shadow .2s}
.sp-thumb:hover{transform:scale(1.04);box-shadow:0 10px 28px rgba(0,0,0,.55)}
.sp-thumb img{width:100%;height:100%;object-fit:cover;display:block}

/* ── Scroll hint ── */
.sp-scroll-hint{margin-top:2.5rem;display:flex;justify-content:center;opacity:.45}
.sp-scroll-arrow{display:block;width:22px;height:22px;border-right:2px solid #fff;border-bottom:2px solid #fff;
  transform:rotate(45deg);animation:scrollBounce 1.4s ease-in-out infinite}
@keyframes scrollBounce{0%,100%{transform:rotate(45deg) translateY(0)}50%{transform:rotate(45deg) translateY(6px)}}

/* ── How it works ── */
.sp-howto{position:relative;z-index:1;padding:5rem 1.5rem 6rem}
.sp-howto-inner{max-width:1100px;margin:0 auto}
.sp-howto-title{font-size:clamp(1.5rem,3.5vw,2.25rem);font-weight:700;color:#fff;text-align:center;margin-bottom:.625rem}
.sp-howto-sub{font-size:.9375rem;color:rgba(255,255,255,.5);text-align:center;margin-bottom:3rem}
.sp-howto-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem}
.sp-howto-card{background:#0b1628;border:1px solid rgba(255,255,255,.1);
  border-radius:1rem;padding:1.625rem 1.5rem 1.75rem;display:flex;flex-direction:column;gap:.75rem;
  transition:background .2s,border-color .2s}
.sp-howto-card:hover{background:#112038;border-color:rgba(255,255,255,.2)}
.sp-howto-top{display:flex;align-items:center;justify-content:space-between}
.sp-howto-num{font-size:.75rem;font-weight:700;color:rgba(255,255,255,.25);letter-spacing:.08em}
.sp-howto-icon{width:2rem;height:2rem;color:rgba(99,179,250,.85)}
.sp-howto-icon svg{width:100%;height:100%}
.sp-howto-heading{font-size:1rem;font-weight:600;color:#fff;line-height:1.3}
.sp-howto-desc{font-size:.875rem;color:rgba(255,255,255,.55);line-height:1.7;flex:1}
.sp-howto-cta{display:inline-block;margin-top:.25rem;font-size:.8125rem;font-weight:600;
  color:#60a5fa;text-decoration:none;transition:color .15s}
.sp-howto-cta:hover{color:#93c5fd}

/* ── Mobile ── */
@media(max-width:1024px){.sp-howto-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  .sp-nav-links{display:none}
  .sp-nav{padding:.75rem 1.25rem}
  .sp-hero{padding:5.5rem 1.25rem 3.5rem}
  .sp-howto-grid{grid-template-columns:1fr}
}
@media(max-width:480px){
  .sp-search-wrap form{padding:.15rem .15rem .15rem .625rem}
  .sp-grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))}
}
CSS;
    }
}
