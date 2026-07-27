@php
    /**
     * Presentational-only managed website workspace.
     * A future controller may pass models, DTOs, or arrays; nothing here assumes
     * persistence, routes, or a particular page/version model shape.
     */
    $website = $site ?? null;
    $websiteName = (string) data_get($website, 'name', data_get($website, 'title', 'Your website'));
    $websiteHost = (string) data_get($website, 'host', data_get($website, 'subdomain', 'your-workspace.theeverbranch.com'));
    $websiteStatus = (string) data_get($website, 'status', 'draft');
    $pageItems = collect($pages ?? [])->values();
    $templateItems = collect($templates ?? [])
        ->map(function ($template, $key): array {
            return [
                'key' => is_string($key) ? $key : (string) data_get($template, 'key', 'template'),
                'name' => (string) data_get($template, 'name', data_get($template, 'label', str($key)->headline())),
                'description' => (string) data_get($template, 'description', 'A focused starting point for this page.'),
            ];
        })
        ->values();
    $editorEnabled = (bool) ($isEditorEnabled ?? false);
    $publishingEnabled = (bool) ($isPublishingEnabled ?? false);
    $firstPage = $pageItems->first();
    $firstPageName = (string) data_get($firstPage, 'title', data_get($firstPage, 'name', 'Home'));
    $firstPageSlug = (string) data_get($firstPage, 'slug', '/');
@endphp

<section
    class="eb-website-editor"
    data-managed-website-editor
    data-editor-enabled="{{ $editorEnabled ? 'true' : 'false' }}"
    data-publishing-enabled="{{ $publishingEnabled ? 'true' : 'false' }}"
    x-data="{
        section: 'overview',
        preview: 'desktop',
        selectedBlock: 'hero',
        selectedPage: @js($firstPageName),
        saveState: 'Saved just now',
        showTemplatePicker: false,
        chooseSection(next) { this.section = next; this.$nextTick(() => this.$refs[next]?.focus()); },
        selectBlock(block) { this.selectedBlock = block; this.saveState = 'Draft updated'; },
        selectPage(page) { this.selectedPage = page; this.section = 'pages'; },
    }"
    @keydown.window.ctrl.s.prevent="$event.preventDefault(); saveState = 'Draft saved just now'"
    @keydown.window.meta.s.prevent="$event.preventDefault(); saveState = 'Draft saved just now'"
>
    <style>
        .eb-website-editor, .eb-website-editor * { box-sizing: border-box; }
        .eb-website-editor { --web-ink:#142327; --web-muted:#657578; --web-line:#dbe3e2; --web-soft:#f4f7f6; --web-accent:#1e5a63; --web-success:#1f7a55; color:var(--web-ink); font-family:var(--fb-font-ui, Inter, sans-serif); margin:0 auto; max-width:1440px; }
        .eb-website-editor button, .eb-website-editor input, .eb-website-editor textarea { font:inherit; }
        .eb-website-editor button { cursor:pointer; }
        .eb-website-editor button:focus-visible, .eb-website-editor input:focus-visible, .eb-website-editor textarea:focus-visible { box-shadow:0 0 0 3px rgba(30,90,99,.26); outline:0; }
        .eb-website-editor__header { align-items:center; background:#fff; border:1px solid var(--web-line); border-radius:10px 10px 0 0; display:flex; gap:1rem; justify-content:space-between; min-height:72px; padding:.8rem 1rem; }
        .eb-website-editor__identity { min-width:0; }
        .eb-website-editor__eyebrow { color:var(--web-accent); display:block; font-size:.64rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
        .eb-website-editor__identity h1 { font-family:var(--fb-font-ui, Inter, sans-serif); font-size:1.1rem; letter-spacing:-.02em; margin:.18rem 0 0; }
        .eb-website-editor__identity p { color:var(--web-muted); font-size:.72rem; margin:.18rem 0 0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .eb-website-editor__header-actions, .eb-website-editor__preview-toggle, .eb-website-editor__action-row { align-items:center; display:flex; flex-wrap:wrap; gap:.45rem; }
        .eb-website-editor__save { color:var(--web-muted); font-size:.66rem; margin-right:.25rem; }
        .eb-website-editor__button { align-items:center; background:#fff; border:1px solid #cfdad8; border-radius:6px; color:#294247; display:inline-flex; font-size:.7rem; font-weight:750; gap:.35rem; justify-content:center; min-height:36px; padding:0 .7rem; text-decoration:none; transition:background-color 120ms ease,border-color 120ms ease,color 120ms ease; }
        .eb-website-editor__button:hover:not(:disabled) { background:#f3f7f6; border-color:#aabdb9; color:#142327; }
        .eb-website-editor__button--primary { background:var(--web-accent); border-color:var(--web-accent); color:#fff; }
        .eb-website-editor__button--primary:hover:not(:disabled) { background:#164850; border-color:#164850; color:#fff; }
        .eb-website-editor__button:disabled { cursor:not-allowed; opacity:.48; }
        .eb-website-editor__notice { align-items:flex-start; background:#fff8e5; border:1px solid #ead38f; border-radius:7px; color:#674d10; display:flex; font-size:.72rem; gap:.55rem; line-height:1.5; margin:.85rem 0; padding:.65rem .75rem; }
        .eb-website-editor__notice strong { color:#543d09; }
        .eb-website-editor__layout { background:#fff; border:1px solid var(--web-line); border-radius:10px; display:grid; grid-template-columns:220px minmax(0,1fr) 310px; min-height:690px; overflow:hidden; }
        .eb-website-editor__rail { background:#fbfcfc; border-right:1px solid var(--web-line); padding:.75rem; }
        .eb-website-editor__rail-label { color:#778789; display:block; font-size:.59rem; font-weight:800; letter-spacing:.1em; margin:.2rem .4rem .45rem; text-transform:uppercase; }
        .eb-website-editor__nav { display:grid; gap:.15rem; }
        .eb-website-editor__nav button { align-items:center; background:transparent; border:0; border-radius:6px; color:#526467; display:flex; font-size:.74rem; font-weight:700; gap:.6rem; min-height:38px; padding:0 .5rem; text-align:left; width:100%; }
        .eb-website-editor__nav button:hover { background:#edf3f2; color:#203d42; }
        .eb-website-editor__nav button[aria-selected="true"] { background:#e7f0ee; color:#174a52; }
        .eb-website-editor__nav-mark { align-items:center; border:1px solid currentColor; border-radius:4px; display:inline-flex; font-size:.58rem; height:17px; justify-content:center; opacity:.8; width:17px; }
        .eb-website-editor__rail-divider { border-top:1px solid var(--web-line); margin:.8rem 0; }
        .eb-website-editor__rail-card { background:#fff; border:1px solid var(--web-line); border-radius:7px; padding:.7rem; }
        .eb-website-editor__rail-card strong, .eb-website-editor__rail-card span { display:block; }
        .eb-website-editor__rail-card strong { font-size:.7rem; }
        .eb-website-editor__rail-card span { color:var(--web-muted); font-size:.64rem; line-height:1.45; margin-top:.22rem; }
        .eb-website-editor__workspace { background:#f5f7f7; min-width:0; padding:1rem; }
        .eb-website-editor__panel { animation:ebWebsiteEnter 150ms ease-out; }
        .eb-website-editor__panel-head { align-items:flex-end; display:flex; gap:1rem; justify-content:space-between; margin-bottom:.85rem; }
        .eb-website-editor__panel-head h2 { font-size:1rem; letter-spacing:-.015em; margin:0; }
        .eb-website-editor__panel-head p { color:var(--web-muted); font-size:.7rem; line-height:1.45; margin:.28rem 0 0; max-width:62ch; }
        .eb-website-editor__metric-grid { display:grid; gap:.65rem; grid-template-columns:repeat(3,1fr); }
        .eb-website-editor__metric { background:#fff; border:1px solid var(--web-line); border-radius:8px; padding:.8rem; }
        .eb-website-editor__metric strong { display:block; font-size:1.05rem; }
        .eb-website-editor__metric span { color:var(--web-muted); display:block; font-size:.64rem; margin-top:.15rem; }
        .eb-website-editor__content-card { background:#fff; border:1px solid var(--web-line); border-radius:8px; margin-top:.8rem; overflow:hidden; }
        .eb-website-editor__content-card-head { align-items:center; border-bottom:1px solid var(--web-line); display:flex; justify-content:space-between; padding:.7rem .8rem; }
        .eb-website-editor__content-card-head h3 { font-size:.74rem; margin:0; }
        .eb-website-editor__content-card-head span { color:var(--web-muted); font-size:.64rem; }
        .eb-website-editor__page-row { align-items:center; background:#fff; border:0; border-bottom:1px solid #e8edec; color:var(--web-ink); display:grid; font-size:.72rem; gap:.7rem; grid-template-columns:auto minmax(0,1fr) auto; min-height:53px; padding:.55rem .8rem; text-align:left; width:100%; }
        .eb-website-editor__page-row:last-child { border-bottom:0; }
        .eb-website-editor__page-row:hover { background:#f8fbfa; }
        .eb-website-editor__page-row strong, .eb-website-editor__page-row small { display:block; }
        .eb-website-editor__page-row small { color:var(--web-muted); font-size:.62rem; margin-top:.12rem; }
        .eb-website-editor__page-icon { align-items:center; background:#edf4f2; border-radius:5px; color:#28665b; display:inline-flex; font-size:.65rem; height:26px; justify-content:center; width:26px; }
        .eb-website-editor__page-state { background:#eff7f2; border:1px solid #cce5d5; border-radius:999px; color:#23704d; font-size:.58rem; font-weight:750; padding:.22rem .4rem; }
        .eb-website-editor__empty { align-items:center; color:var(--web-muted); display:flex; flex-direction:column; min-height:270px; justify-content:center; padding:1.5rem; text-align:center; }
        .eb-website-editor__empty-icon { align-items:center; background:#eaf1f0; border-radius:8px; color:var(--web-accent); display:flex; font-size:1.1rem; height:42px; justify-content:center; width:42px; }
        .eb-website-editor__empty h3 { color:var(--web-ink); font-size:.82rem; margin:.65rem 0 .2rem; }
        .eb-website-editor__empty p { font-size:.68rem; line-height:1.5; margin:0; max-width:39ch; }
        .eb-website-editor__preview { background:#dfe7e5; border:1px solid #d3dfdd; border-radius:8px; min-height:360px; padding:1rem; }
        .eb-website-editor__preview-device { background:#fff; box-shadow:0 14px 32px -24px rgba(12,35,39,.8); margin:0 auto; min-height:325px; overflow:hidden; transition:max-width 150ms ease; }
        .eb-website-editor__preview-device.is-desktop { max-width:100%; }
        .eb-website-editor__preview-device.is-mobile { border:6px solid #23383b; border-radius:16px; max-width:290px; min-height:420px; }
        .eb-website-editor__sitebar { align-items:center; border-bottom:1px solid #e5eae9; display:flex; font-size:.55rem; justify-content:space-between; padding:.5rem .65rem; }
        .eb-website-editor__sitebar strong { font-size:.58rem; }
        .eb-website-editor__sitebar span { color:#778789; }
        .eb-website-editor__hero-preview { background:#edf4f1; min-height:205px; padding:2.2rem 10%; }
        .eb-website-editor__hero-preview small { color:#2f7d6b; font-size:.55rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }
        .eb-website-editor__hero-preview h3 { font-family:var(--fb-font-display, Georgia, serif); font-size:clamp(1.1rem, 2.3vw, 1.8rem); line-height:1.05; margin:.55rem 0; max-width:15ch; }
        .eb-website-editor__hero-preview p { color:#536467; font-size:.6rem; line-height:1.45; max-width:38ch; }
        .eb-website-editor__fake-cta { background:#1e5a63; border-radius:4px; display:inline-block; height:19px; margin-top:.3rem; width:76px; }
        .eb-website-editor__preview-section { display:grid; gap:.55rem; grid-template-columns:repeat(3,1fr); padding:1rem 10%; }
        .eb-website-editor__preview-section span { background:#eef2f1; border-radius:3px; height:42px; }
        .eb-website-editor__inspector { background:#fff; border-left:1px solid var(--web-line); display:flex; flex-direction:column; min-width:0; }
        .eb-website-editor__inspector-head { border-bottom:1px solid var(--web-line); padding:.85rem; }
        .eb-website-editor__inspector-head h2 { font-size:.76rem; margin:0; }
        .eb-website-editor__inspector-head p { color:var(--web-muted); font-size:.63rem; line-height:1.45; margin:.18rem 0 0; }
        .eb-website-editor__block-list { display:grid; gap:.38rem; padding:.7rem; }
        .eb-website-editor__block { align-items:center; background:#fff; border:1px solid var(--web-line); border-radius:6px; color:#334b4f; display:grid; font-size:.68rem; font-weight:700; gap:.45rem; grid-template-columns:28px 1fr; min-height:45px; padding:.4rem; text-align:left; }
        .eb-website-editor__block:hover { background:#f7faf9; border-color:#aebfbc; }
        .eb-website-editor__block.is-selected { background:#edf5f3; border-color:#83aaa1; color:#174a52; }
        .eb-website-editor__block-mark { align-items:center; background:#edf2f1; border-radius:4px; color:#38676a; display:flex; font-size:.55rem; height:27px; justify-content:center; }
        .eb-website-editor__form { border-top:1px solid var(--web-line); margin-top:.1rem; padding:.85rem; }
        .eb-website-editor__form label { color:#536467; display:block; font-size:.62rem; font-weight:750; margin-bottom:.65rem; }
        .eb-website-editor__form input, .eb-website-editor__form textarea { background:#fff; border:1px solid #cfdad8; border-radius:5px; color:var(--web-ink); display:block; font-size:.68rem; margin-top:.25rem; min-height:34px; padding:.45rem .5rem; resize:vertical; width:100%; }
        .eb-website-editor__form textarea { min-height:72px; }
        .eb-website-editor__sticky-actions { align-items:center; background:#fff; border-top:1px solid var(--web-line); display:flex; gap:.5rem; justify-content:space-between; margin-top:auto; padding:.7rem; }
        .eb-website-editor__sticky-actions span { color:var(--web-muted); font-size:.6rem; max-width:17ch; }
        .eb-website-editor__template-grid { display:grid; gap:.65rem; grid-template-columns:repeat(2,minmax(0,1fr)); }
        .eb-website-editor__template { background:#fff; border:1px solid var(--web-line); border-radius:7px; color:var(--web-ink); min-height:120px; padding:.75rem; text-align:left; }
        .eb-website-editor__template:hover { border-color:#8dacaa; box-shadow:0 9px 20px -20px rgba(12,35,39,.8); }
        .eb-website-editor__template span, .eb-website-editor__template strong { display:block; }
        .eb-website-editor__template span { color:var(--web-accent); font-size:.58rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
        .eb-website-editor__template strong { font-size:.74rem; margin-top:.6rem; }
        .eb-website-editor__template p { color:var(--web-muted); font-size:.63rem; line-height:1.4; margin:.25rem 0 0; }
        .eb-website-editor__settings-list { display:grid; gap:.65rem; }
        .eb-website-editor__settings-row { align-items:center; background:#fff; border:1px solid var(--web-line); border-radius:7px; display:flex; gap:.7rem; justify-content:space-between; padding:.7rem .8rem; }
        .eb-website-editor__settings-row strong, .eb-website-editor__settings-row span { display:block; }
        .eb-website-editor__settings-row strong { font-size:.71rem; }
        .eb-website-editor__settings-row span { color:var(--web-muted); font-size:.63rem; margin-top:.15rem; }
        .eb-website-editor__pill { background:#edf4f2; border:1px solid #cde1da; border-radius:999px; color:#1f6f50; font-size:.58rem; font-weight:800; padding:.25rem .45rem; white-space:nowrap; }
        @keyframes ebWebsiteEnter { from { opacity:.5; transform:translateY(3px); } to { opacity:1; transform:translateY(0); } }
        @media (prefers-reduced-motion: reduce) { .eb-website-editor *, .eb-website-editor__panel { animation-duration:.01ms !important; scroll-behavior:auto !important; transition-duration:.01ms !important; } }
        @media (max-width: 1120px) { .eb-website-editor__layout { grid-template-columns:190px minmax(0,1fr); } .eb-website-editor__inspector { border-left:0; border-top:1px solid var(--web-line); grid-column:1 / -1; } .eb-website-editor__inspector > .eb-website-editor__block-list { grid-template-columns:repeat(3,1fr); } .eb-website-editor__form { display:grid; gap:.6rem; grid-template-columns:repeat(3,1fr); } .eb-website-editor__form label { margin:0; } }
        @media (max-width: 760px) { .eb-website-editor { margin:0 -.25rem; } .eb-website-editor__header { align-items:flex-start; flex-direction:column; } .eb-website-editor__header-actions { width:100%; } .eb-website-editor__save { flex:1 1 100%; } .eb-website-editor__layout { display:block; min-height:0; } .eb-website-editor__rail { border-bottom:1px solid var(--web-line); border-right:0; overflow:auto; } .eb-website-editor__nav { display:flex; gap:.35rem; min-width:max-content; } .eb-website-editor__nav button { border:1px solid transparent; min-width:max-content; } .eb-website-editor__rail-divider, .eb-website-editor__rail-card { display:none; } .eb-website-editor__workspace { min-height:490px; padding:.8rem; } .eb-website-editor__metric-grid { grid-template-columns:1fr; } .eb-website-editor__panel-head { align-items:flex-start; flex-direction:column; } .eb-website-editor__preview { margin:0 -.1rem; padding:.6rem; } .eb-website-editor__inspector > .eb-website-editor__block-list { grid-template-columns:repeat(2,1fr); } .eb-website-editor__form { grid-template-columns:1fr; } .eb-website-editor__template-grid { grid-template-columns:1fr; } .eb-website-editor__sticky-actions { bottom:0; position:sticky; z-index:2; } }
    </style>

    <header class="eb-website-editor__header">
        <div class="eb-website-editor__identity">
            <span class="eb-website-editor__eyebrow">Everbranch Managed Website</span>
            <h1>{{ $websiteName }}</h1>
            <p>{{ $websiteHost }} · {{ str($websiteStatus)->headline() }} site</p>
        </div>
        <div class="eb-website-editor__header-actions">
            <span class="eb-website-editor__save" aria-live="polite" x-text="saveState">Saved just now</span>
            <div class="eb-website-editor__preview-toggle" role="group" aria-label="Preview device">
                <button class="eb-website-editor__button" type="button" :aria-pressed="preview === 'desktop'" @click="preview = 'desktop'">Desktop</button>
                <button class="eb-website-editor__button" type="button" :aria-pressed="preview === 'mobile'" @click="preview = 'mobile'">Mobile</button>
            </div>
            <button class="eb-website-editor__button" type="button" data-website-action="preview">Preview site</button>
            <button class="eb-website-editor__button eb-website-editor__button--primary" type="button" data-website-action="publish" @disabled(! $editorEnabled || ! $publishingEnabled)>
                {{ $publishingEnabled ? 'Publish changes' : 'Publishing unavailable' }}
            </button>
        </div>
    </header>

    @if(! $editorEnabled)
        <div class="eb-website-editor__notice" role="status">
            <span aria-hidden="true">ⓘ</span>
            <span><strong>Website editing is not available for this workspace.</strong> The last published website remains unchanged. An owner can restore access after the rollout and entitlement checks are complete.</span>
        </div>
    @elseif(! $publishingEnabled)
        <div class="eb-website-editor__notice" role="status">
            <span aria-hidden="true">ⓘ</span>
            <span><strong>Publishing is temporarily paused.</strong> You can safely review the draft and prepare content; the currently published website will stay live until publishing is re-enabled.</span>
        </div>
    @endif

    <div class="eb-website-editor__layout">
        <aside class="eb-website-editor__rail" aria-label="Website workspace navigation">
            <span class="eb-website-editor__rail-label">Website</span>
            <nav class="eb-website-editor__nav" role="tablist" aria-orientation="vertical">
                @foreach(['overview' => ['⌂', 'Overview'], 'pages' => ['▤', 'Pages'], 'navigation' => ['↳', 'Navigation'], 'brand' => ['✦', 'Brand'], 'leads' => ['◌', 'Leads'], 'settings' => ['⚙', 'Settings']] as $key => [$icon, $label])
                    <button type="button" role="tab" :aria-selected="section === '{{ $key }}'" @click="chooseSection('{{ $key }}')" @keydown.right.prevent="chooseSection('{{ $key }}')">
                        <span class="eb-website-editor__nav-mark" aria-hidden="true">{{ $icon }}</span>{{ $label }}
                    </button>
                @endforeach
            </nav>
            <div class="eb-website-editor__rail-divider"></div>
            <div class="eb-website-editor__rail-card">
                <strong>Safe publishing</strong>
                <span>Every publish keeps an immutable version available for rollback.</span>
            </div>
        </aside>

        <main class="eb-website-editor__workspace">
            <div class="eb-website-editor__panel" x-show="section === 'overview'" x-ref="overview" tabindex="-1" role="tabpanel">
                <div class="eb-website-editor__panel-head">
                    <div><h2>Website overview</h2><p>Keep the essentials clear: what visitors see, where they can act, and what needs attention before you publish.</p></div>
                    <button class="eb-website-editor__button eb-website-editor__button--primary" type="button" @click="chooseSection('pages')">Edit pages</button>
                </div>
                <div class="eb-website-editor__metric-grid">
                    <div class="eb-website-editor__metric"><strong>{{ $pageItems->count() }}</strong><span>{{ str('page')->plural($pageItems->count()) }} in this website</span></div>
                    <div class="eb-website-editor__metric"><strong>{{ $websiteStatus === 'published' ? 'Live' : 'Draft' }}</strong><span>Current website status</span></div>
                    <div class="eb-website-editor__metric"><strong>0</strong><span>New form leads this week</span></div>
                </div>
                <div class="eb-website-editor__content-card">
                    <div class="eb-website-editor__content-card-head"><h3>Pages visitors can open</h3><span>{{ $websiteHost }}</span></div>
                    @forelse($pageItems->take(5) as $page)
                        @php($pageTitle = (string) data_get($page, 'title', data_get($page, 'name', 'Untitled page')))
                        <button class="eb-website-editor__page-row" type="button" @click="selectPage(@js($pageTitle))">
                            <span class="eb-website-editor__page-icon" aria-hidden="true">▤</span>
                            <span><strong>{{ $pageTitle }}</strong><small>/{{ ltrim((string) data_get($page, 'slug', ''), '/') ?: '' }}</small></span>
                            <span class="eb-website-editor__page-state">{{ data_get($page, 'published_at') ? 'Live' : 'Draft' }}</span>
                        </button>
                    @empty
                        <div class="eb-website-editor__empty"><div class="eb-website-editor__empty-icon" aria-hidden="true">▤</div><h3>Start with your first page</h3><p>Choose a proven page type, then make it sound and look like your business.</p><button class="eb-website-editor__button eb-website-editor__button--primary" type="button" @click="showTemplatePicker = true; chooseSection('pages')">Choose a page template</button></div>
                    @endforelse
                </div>
            </div>

            <div class="eb-website-editor__panel" x-show="section === 'pages'" x-ref="pages" tabindex="-1" role="tabpanel">
                <div class="eb-website-editor__panel-head">
                    <div><h2>Pages</h2><p>Build a clear path for customers. Start with the essential pages, then add focused landing pages as you need them.</p></div>
                    <button class="eb-website-editor__button eb-website-editor__button--primary" type="button" @click="showTemplatePicker = ! showTemplatePicker">Add page</button>
                </div>
                <div class="eb-website-editor__content-card" x-show="! showTemplatePicker">
                    <div class="eb-website-editor__content-card-head"><h3>All pages</h3><span x-text="selectedPage + ' selected'"></span></div>
                    @forelse($pageItems as $page)
                        @php($pageTitle = (string) data_get($page, 'title', data_get($page, 'name', 'Untitled page')))
                        <button class="eb-website-editor__page-row" type="button" :aria-current="selectedPage === @js($pageTitle) ? 'page' : null" @click="selectPage(@js($pageTitle))">
                            <span class="eb-website-editor__page-icon" aria-hidden="true">▤</span>
                            <span><strong>{{ $pageTitle }}</strong><small>{{ (string) data_get($page, 'slug', '/') }}</small></span>
                            <span class="eb-website-editor__page-state">{{ data_get($page, 'published_at') ? 'Live' : 'Draft' }}</span>
                        </button>
                    @empty
                        <div class="eb-website-editor__empty"><div class="eb-website-editor__empty-icon" aria-hidden="true">+</div><h3>No pages yet</h3><p>Pick a simple page template. You can edit every section before anyone sees it.</p><button class="eb-website-editor__button eb-website-editor__button--primary" type="button" @click="showTemplatePicker = true">Choose a template</button></div>
                    @endforelse
                </div>
                <div class="eb-website-editor__template-grid" x-show="showTemplatePicker" x-cloak>
                    @forelse($templateItems as $template)
                        <button class="eb-website-editor__template" type="button" data-website-template="{{ $template['key'] }}" @click="selectedPage = @js($template['name']); showTemplatePicker = false; saveState = 'New {{ $template['name'] }} page ready to edit'">
                            <span>Template</span><strong>{{ $template['name'] }}</strong><p>{{ $template['description'] }}</p>
                        </button>
                    @empty
                        @foreach(['Home', 'Services', 'About', 'Contact', 'FAQ', 'Landing page'] as $templateName)
                            <button class="eb-website-editor__template" type="button" data-website-template="{{ str($templateName)->slug() }}" @click="selectedPage = @js($templateName); showTemplatePicker = false; saveState = 'New {{ $templateName }} page ready to edit'">
                                <span>Template</span><strong>{{ $templateName }}</strong><p>A focused, responsive starting point you can make your own.</p>
                            </button>
                        @endforeach
                    @endforelse
                </div>
            </div>

            <div class="eb-website-editor__panel" x-show="section === 'navigation'" x-ref="navigation" tabindex="-1" role="tabpanel">
                <div class="eb-website-editor__panel-head"><div><h2>Navigation</h2><p>Keep the main menu short so visitors always know where to go next.</p></div></div>
                <div class="eb-website-editor__content-card">
                    <div class="eb-website-editor__content-card-head"><h3>Main menu</h3><button type="button" class="eb-website-editor__button" @click="saveState = 'Navigation draft saved'">Save order</button></div>
                    @forelse($pageItems as $page)
                        @php($pageTitle = (string) data_get($page, 'title', data_get($page, 'name', 'Untitled page')))
                        <button class="eb-website-editor__page-row" type="button" @click="selectPage(@js($pageTitle))"><span class="eb-website-editor__page-icon" aria-hidden="true">↕</span><span><strong>{{ $pageTitle }}</strong><small>Visible in main navigation</small></span><span aria-hidden="true">›</span></button>
                    @empty
                        <div class="eb-website-editor__empty"><div class="eb-website-editor__empty-icon" aria-hidden="true">↳</div><h3>Your menu will appear here</h3><p>Create a page first, then decide whether it belongs in the main menu.</p></div>
                    @endforelse
                </div>
            </div>

            <div class="eb-website-editor__panel" x-show="section === 'brand'" x-ref="brand" tabindex="-1" role="tabpanel">
                <div class="eb-website-editor__panel-head"><div><h2>Brand</h2><p>Use a consistent logo, color, and voice across each page. Your existing workspace brand is a safe starting point.</p></div></div>
                <div class="eb-website-editor__settings-list">
                    <div class="eb-website-editor__settings-row"><div><strong>Workspace brand</strong><span>Logo, colors, and type from your Everbranch workspace.</span></div><span class="eb-website-editor__pill">Ready</span></div>
                    <div class="eb-website-editor__settings-row"><div><strong>Social sharing image</strong><span>Choose an image that looks good when your site is shared.</span></div><button class="eb-website-editor__button" type="button">Choose image</button></div>
                </div>
            </div>

            <div class="eb-website-editor__panel" x-show="section === 'leads'" x-ref="leads" tabindex="-1" role="tabpanel">
                <div class="eb-website-editor__panel-head"><div><h2>Leads</h2><p>Website contact forms save to your workspace lead inbox. They do not create customers or send messages automatically.</p></div><button class="eb-website-editor__button" type="button" data-website-action="view-leads">Open lead inbox</button></div>
                <div class="eb-website-editor__empty"><div class="eb-website-editor__empty-icon" aria-hidden="true">◌</div><h3>No website leads yet</h3><p>When someone completes a contact form, it will appear here for your team to follow up safely.</p></div>
            </div>

            <div class="eb-website-editor__panel" x-show="section === 'settings'" x-ref="settings" tabindex="-1" role="tabpanel">
                <div class="eb-website-editor__panel-head"><div><h2>Website settings</h2><p>Review visibility and publishing controls before making your website available to customers.</p></div></div>
                <div class="eb-website-editor__settings-list">
                    <div class="eb-website-editor__settings-row"><div><strong>Website address</strong><span>{{ $websiteHost }}</span></div><span class="eb-website-editor__pill">Everbranch subdomain</span></div>
                    <div class="eb-website-editor__settings-row"><div><strong>Publishing protection</strong><span>Last published versions are kept for a safe rollback.</span></div><span class="eb-website-editor__pill">Enabled</span></div>
                    <div class="eb-website-editor__settings-row"><div><strong>Custom domain</strong><span>Available after the subdomain pilot is approved.</span></div><button class="eb-website-editor__button" type="button" disabled>Coming later</button></div>
                </div>
            </div>
        </main>

        <aside class="eb-website-editor__inspector" aria-label="Page editor">
            <div class="eb-website-editor__inspector-head"><h2>Page content</h2><p>Edit {{ $firstPageSlug === '/' ? 'the home page' : $firstPageSlug }} with structured, responsive sections.</p></div>
            <div class="eb-website-editor__block-list" aria-label="Structured sections">
                @foreach(['hero' => ['Aa', 'Hero'], 'text' => ['¶', 'Text and image'], 'services' => ['▦', 'Services'], 'testimonials' => ['❝', 'Testimonials'], 'faq' => ['?', 'FAQ'], 'form' => ['✉', 'Contact form'], 'cta' => ['→', 'External checkout or booking']] as $key => [$mark, $label])
                    <button type="button" class="eb-website-editor__block" :class="{ 'is-selected': selectedBlock === '{{ $key }}' }" @click="selectBlock('{{ $key }}')" :aria-pressed="selectedBlock === '{{ $key }}'"><span class="eb-website-editor__block-mark" aria-hidden="true">{{ $mark }}</span>{{ $label }}</button>
                @endforeach
            </div>
            <div class="eb-website-editor__form">
                <label>Section heading<input type="text" value="Welcome to {{ $websiteName }}" @input="saveState = 'Unsaved changes'" /></label>
                <label>Supporting text<textarea @input="saveState = 'Unsaved changes'">Help customers understand what you do and choose a clear next step.</textarea></label>
                <label>Action link<input type="url" placeholder="https://" @input="saveState = 'Unsaved changes'" /></label>
            </div>
            <div class="eb-website-editor__sticky-actions">
                <span>Changes save to a draft. Nothing goes live until you publish.</span>
                <button class="eb-website-editor__button" type="button" @click="saveState = 'Draft saved just now'" @disabled(! $editorEnabled)>Save draft</button>
            </div>
        </aside>
    </div>

    <section class="eb-website-editor__content-card" aria-label="Website preview" style="margin-top:.85rem;">
        <div class="eb-website-editor__content-card-head"><div><h3>Live-style preview</h3><span>Preview changes before publishing</span></div><span x-text="preview === 'desktop' ? 'Desktop' : 'Mobile'"></span></div>
        <div class="eb-website-editor__preview">
            <div class="eb-website-editor__preview-device" :class="preview === 'desktop' ? 'is-desktop' : 'is-mobile'">
                <div class="eb-website-editor__sitebar"><strong>{{ $websiteName }}</strong><span>Home &nbsp; Services &nbsp; Contact</span></div>
                <div class="eb-website-editor__hero-preview"><small>Built for your customers</small><h3 x-text="selectedBlock === 'hero' ? 'A clear, welcoming first impression.' : 'A website that makes the next step easy.'"></h3><p>Calm, useful content that guides visitors toward the right service, booking, or external checkout.</p><span class="eb-website-editor__fake-cta" aria-hidden="true"></span></div>
                <div class="eb-website-editor__preview-section"><span></span><span></span><span></span></div>
            </div>
        </div>
    </section>
</section>
