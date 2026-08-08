@php
    $themeSettings = (array) (($theme?->settings ?? null) ?: $site->settings ?? []);
    $palette = (array) data_get($themeSettings, 'theme_palette', []);
    $navigation = (array) (($theme?->navigation ?? null) ?: []);
    $isDraftPreview = (bool) ($isDraftPreview ?? false);
    $previewMode = (string) ($previewMode ?? 'public');
    $isEditorPreview = $isDraftPreview && $previewMode === 'editor';
    $isFullPreview = $isDraftPreview && $previewMode === 'site';
    $previewLinks = (array) ($previewLinks ?? []);
    $brand = data_get($palette, 'brand') ?: $tenant->brandProfile?->primary_color ?: '#1e5a63';
    $accent = data_get($palette, 'accent') ?: '#efb44b';
    $ink = data_get($palette, 'ink') ?: $tenant->brandProfile?->text_color ?: '#142327';
    $surface = data_get($palette, 'surface') ?: $tenant->brandProfile?->surface_color ?: '#ffffff';
    $soft = data_get($palette, 'soft') ?: '#edf4f1';
    $corner = ['square' => '0', 'rounded' => '1.25rem', 'soft' => '.65rem'][data_get($themeSettings, 'corners', 'soft')] ?? '.65rem';
    $font = data_get($themeSettings, 'typography') === 'serif' ? 'Georgia, ui-serif, serif' : 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    $announcement = (array) data_get($themeSettings, 'announcement', []);
    $footer = (array) data_get($themeSettings, 'footer', []);
    $link = static function (?string $value) use ($isDraftPreview, $isEditorPreview, $isFullPreview, $previewLinks): array {
        $value = trim((string) $value);
        if (! $isDraftPreview) {
            return ['href' => $value !== '' ? $value : '#', 'external' => false, 'disabled' => $value === ''];
        }
        if ($value === '' || $value === '#') {
            return ['href' => '#', 'external' => false, 'disabled' => true];
        }
        if (str_starts_with($value, '#')) {
            return ['href' => $value, 'external' => false, 'disabled' => false];
        }
        if (preg_match('/^(tel|mailto):/i', $value) === 1) {
            return ['href' => '#', 'external' => false, 'disabled' => true];
        }
        if (str_starts_with($value, '/')) {
            $path = (string) (parse_url($value, PHP_URL_PATH) ?: '/');
            $path = trim('/'.trim($path, '/'), '/');
            $path = $path === '' ? '/' : '/'.$path;
            $fragment = parse_url($value, PHP_URL_FRAGMENT);
            $suffix = is_string($fragment) && $fragment !== '' ? '#'.$fragment : '';
            if ($isFullPreview && isset($previewLinks[$path])) {
                return ['href' => $previewLinks[$path].$suffix, 'external' => false, 'disabled' => false];
            }

            return ['href' => $isEditorPreview ? '#' : ($suffix ?: '#'), 'external' => false, 'disabled' => true];
        }
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return ['href' => $isEditorPreview ? '#' : $value, 'external' => $isFullPreview, 'disabled' => $isEditorPreview];
        }

        return ['href' => '#', 'external' => false, 'disabled' => true];
    };
    $linkAttributes = static function (array $destination): string {
        $attributes = 'href="'.e((string) $destination['href']).'"';
        if ($destination['external']) {
            $attributes .= ' target="_blank" rel="noopener noreferrer"';
        }
        if ($destination['disabled']) {
            $attributes .= ' aria-disabled="true"';
        }

        return $attributes;
    };
    $homeLink = $link('/');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ data_get($version->seo, 'title') ?: $version->title }}</title>
    @if(data_get($version->seo, 'description'))<meta name="description" content="{{ data_get($version->seo, 'description') }}">@endif
    @if($isDraftPreview)<meta name="robots" content="noindex,nofollow">@endif
    <style>
        :root{--ink:{{ $ink }};--brand:{{ $brand }};--accent:{{ $accent }};--surface:{{ $surface }};--soft:{{ $soft }};--corner:{{ $corner }};--font:{{ $font }}}*{box-sizing:border-box}html{scroll-behavior:smooth}body{background:var(--surface);color:var(--ink);font-family:var(--font);margin:0}a{color:inherit}.site-shell{margin:auto;max-width:1180px;padding:0 1.35rem}.announcement{background:var(--ink);color:#fff;display:block;font-size:.83rem;font-weight:700;letter-spacing:.01em;padding:.68rem 1rem;text-align:center;text-decoration:none}.top{align-items:center;border-bottom:1px solid color-mix(in srgb,var(--ink) 12%,transparent);display:flex;gap:1rem;justify-content:space-between;min-height:76px}.brand{font-size:1.06rem;font-weight:850;letter-spacing:-.03em;text-decoration:none}.nav{align-items:center;display:flex;gap:1.2rem}.nav a{color:color-mix(in srgb,var(--ink) 72%,transparent);font-size:.9rem;font-weight:650;text-decoration:none}.nav a:hover,.nav a:focus-visible{color:var(--brand);text-decoration:underline;text-underline-offset:.28rem}.mobile-nav{display:none}.section{padding:5.5rem 0}.section--soft{background:var(--soft)}.section h1,.section h2,.hero h1{letter-spacing:-.055em;line-height:1.01;margin:0}.section h2{font-size:clamp(2rem,4vw,3.4rem);max-width:18ch}.eyebrow{color:var(--brand);font-size:.75rem;font-weight:850;letter-spacing:.14em;margin:0 0 .8rem;text-transform:uppercase}.copy{font-size:1.04rem;line-height:1.7;max-width:64ch}.button{background:var(--brand);border:1px solid var(--brand);border-radius:var(--corner);color:#fff;display:inline-block;font-size:.92rem;font-weight:800;margin-top:1.25rem;padding:.8rem 1.1rem;text-decoration:none}.button:hover,.button:focus-visible{filter:brightness(.92)}.button[aria-disabled=true]{cursor:default;opacity:.75}.hero{background:linear-gradient(100deg,color-mix(in srgb,var(--ink) 90%,transparent),color-mix(in srgb,var(--ink) 38%,transparent)),var(--hero-image) center/cover;min-height:clamp(480px,68vh,680px);padding:clamp(5rem,13vw,10rem) 0;position:relative}.hero__content{color:#fff;max-width:760px}.hero__content .eyebrow{color:var(--accent)}.hero h1{font-size:clamp(3rem,7vw,6.3rem);max-width:11ch}.hero .copy{color:color-mix(in srgb,#fff 86%,transparent);font-size:1.1rem}.hero .button{background:#fff;border-color:#fff;color:var(--ink)}.card-grid{display:grid;gap:1rem;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:2rem}.card{background:#fff;border:1px solid color-mix(in srgb,var(--ink) 10%,transparent);border-radius:var(--corner);padding:1.4rem}.card h3{font-size:1.15rem;letter-spacing:-.025em;margin:0}.card p{color:color-mix(in srgb,var(--ink) 72%,transparent);line-height:1.55}.trust{display:grid;gap:1rem;grid-template-columns:repeat(4,minmax(0,1fr));padding:1.2rem 0}.trust__item{border-left:2px solid var(--accent);font-size:.9rem;font-weight:780;padding:.4rem .8rem}.split{align-items:center;display:grid;gap:clamp(2rem,6vw,5rem);grid-template-columns:1fr 1fr}.split--reverse .split__image{order:2}.split img,.gallery img{border-radius:var(--corner);display:block;height:100%;object-fit:cover;width:100%}.split__image{min-height:330px}.gallery{display:grid;gap:1rem;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:2rem}.gallery img{aspect-ratio:1.15}.quote{border-left:4px solid var(--accent);font-size:clamp(1.35rem,2.5vw,2rem);font-style:italic;line-height:1.35;margin:1.5rem 0;max-width:34ch;padding-left:1.1rem}.faq{display:grid;gap:.7rem;margin-top:1.7rem;max-width:820px}.faq details{background:#fff;border:1px solid color-mix(in srgb,var(--ink) 11%,transparent);border-radius:var(--corner);padding:1rem 1.2rem}.faq summary{cursor:pointer;font-weight:780}.faq p{line-height:1.6}.contact-card{background:#fff;border:1px solid color-mix(in srgb,var(--ink) 11%,transparent);border-radius:var(--corner);box-shadow:0 18px 48px -34px color-mix(in srgb,var(--ink) 55%,transparent);max-width:760px;padding:clamp(1.4rem,4vw,2.5rem)}.field{display:block;font-size:.88rem;font-weight:760;margin:1rem 0}.field input,.field textarea{background:#fff;border:1px solid color-mix(in srgb,var(--ink) 25%,transparent);border-radius:calc(var(--corner) * .7);display:block;font:inherit;margin-top:.4rem;padding:.75rem;width:100%}.field textarea{min-height:130px}.site-footer{background:var(--ink);color:#fff;padding:3rem 0}.site-footer__grid{display:flex;flex-wrap:wrap;gap:1.5rem;justify-content:space-between}.site-footer p{color:color-mix(in srgb,#fff 70%,transparent);font-size:.9rem;line-height:1.6;margin:.35rem 0}.site-footer a{font-size:.88rem;font-weight:700;margin-left:1rem}.preview-toolbar{align-items:center;background:#102f36;color:#fff;display:flex;font-size:.82rem;font-weight:750;gap:1rem;justify-content:space-between;padding:.6rem max(1rem,calc((100vw - 1180px)/2 + 1.35rem))}.preview-toolbar a{background:#fff;color:#123c43;border-radius:.45rem;padding:.45rem .7rem;text-decoration:none}.preview-note{background:#fff5cf;border-bottom:1px solid #e6ce70;color:#443402;font-size:.78rem;font-weight:800;padding:.5rem 1rem;text-align:center}body[data-preview-mode=editor] [data-eb-select]{cursor:pointer;outline:2px solid transparent;outline-offset:-2px;position:relative}body[data-preview-mode=editor] [data-eb-select]:hover,body[data-preview-mode=editor] [data-eb-select]:focus{outline-color:#157bb8}body[data-preview-mode=editor] [data-eb-select]:focus{outline-style:solid}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}@media(max-width:760px){.nav{display:none}.mobile-nav{display:block;position:relative}.mobile-nav summary{cursor:pointer;font-size:.87rem;font-weight:800;list-style:none}.mobile-nav summary::-webkit-details-marker{display:none}.mobile-nav nav{background:#fff;border:1px solid color-mix(in srgb,var(--ink) 13%,transparent);box-shadow:0 14px 28px -20px color-mix(in srgb,var(--ink) 70%,transparent);display:grid;gap:.8rem;min-width:180px;padding:1rem;position:absolute;right:0;top:1.8rem;z-index:5}.section{padding:3.7rem 0}.hero{min-height:510px}.card-grid,.trust,.gallery,.split{grid-template-columns:1fr}.split--reverse .split__image{order:0}.split__image{min-height:240px}.gallery{grid-template-columns:1fr 1fr}.top{min-height:64px}.preview-toolbar{padding:.55rem 1rem}}
    </style>
</head>
<body data-testid="managed-site" data-preview-mode="{{ $previewMode }}">
@if($isFullPreview)<div class="preview-toolbar"><span>Private draft preview — changes are not public.</span><a href="{{ $editorUrl }}">Back to editor</a></div>@endif
@if($isEditorPreview)<div class="preview-note">Draft editor canvas — select anything to edit it. Forms and contact links are disabled.</div>@endif
@if(data_get($announcement, 'enabled') && data_get($announcement, 'text'))
    @php($announcementLink = $link(data_get($announcement, 'url')))
    <a class="announcement" {!! $linkAttributes($announcementLink) !!} data-eb-select="announcement" data-eb-field="announcement_text">{{ data_get($announcement, 'text') }}</a>
@endif
<header class="site-shell top" data-eb-select="header" tabindex="{{ $isEditorPreview ? '0' : '-1' }}">
    <a class="brand" {!! $linkAttributes($homeLink) !!} data-eb-select="header" data-eb-field="theme_name">{{ data_get($themeSettings, 'theme_name') ?: $tenant->brandProfile?->display_name ?: $tenant->name }}</a>
    <nav class="nav" aria-label="Main navigation">@foreach($navigation as $item)@php($itemLink = $link(data_get($item, 'url')))<a {!! $linkAttributes($itemLink) !!} data-eb-select="navigation" data-eb-navigation-index="{{ $loop->index }}" data-eb-field="navigation_label">{{ data_get($item, 'label') }}</a>@endforeach</nav>
    <details class="mobile-nav" data-eb-select="header"><summary>Menu</summary><nav aria-label="Mobile navigation">@foreach($navigation as $item)@php($itemLink = $link(data_get($item, 'url')))<a {!! $linkAttributes($itemLink) !!} data-eb-select="navigation" data-eb-navigation-index="{{ $loop->index }}" data-eb-field="navigation_label">{{ data_get($item, 'label') }}</a>@endforeach</nav></details>
</header>
@foreach((array) $version->blocks as $block)
    @continue(data_get($block, 'hidden') === 'true')
    @php($type = (string) data_get($block, 'type'))
    @php($blockAttributes = 'data-eb-select="section" data-eb-block-index="'.$loop->index.'" tabindex="'.($isEditorPreview ? '0' : '-1').'"')
    @if($type === 'hero')
        @php($ctaLink = $link(data_get($block, 'cta_url')))
        <section class="hero" style="--hero-image:url('{{ data_get($block, 'image_url') }}')" {!! $blockAttributes !!} data-eb-field="image"><div class="site-shell hero__content"><p class="eyebrow" data-eb-field="label">{{ data_get($block, 'label') }}</p><h1 data-eb-field="heading">{{ data_get($block, 'heading') }}</h1>@if(data_get($block, 'body'))<p class="copy" data-eb-field="body">{{ data_get($block, 'body') }}</p>@endif @if(data_get($block, 'cta_url') && data_get($block, 'cta_label'))<a class="button" {!! $linkAttributes($ctaLink) !!} data-eb-select="section" data-eb-block-index="{{ $loop->index }}" data-eb-field="cta_label">{{ data_get($block, 'cta_label') }}</a>@endif</div></section>
    @elseif($type === 'service_cards' || $type === 'trust_bar')
        <section class="section {{ $type === 'trust_bar' ? 'section--soft' : '' }}" {!! $blockAttributes !!}><div class="site-shell">@if(data_get($block, 'heading'))<p class="eyebrow" data-eb-field="label">{{ data_get($block, 'label') }}</p><h2 data-eb-field="heading">{{ data_get($block, 'heading') }}</h2>@endif @if(data_get($block, 'body'))<p class="copy" data-eb-field="body">{{ data_get($block, 'body') }}</p>@endif <div class="{{ $type === 'trust_bar' ? 'trust' : 'card-grid' }}">@foreach((array) data_get($block, 'items', []) as $item)<article class="{{ $type === 'trust_bar' ? 'trust__item' : 'card' }}" data-eb-select="section" data-eb-block-index="{{ $loop->parent->index }}" data-eb-item-index="{{ $loop->index }}">@if(data_get($item, 'image_url'))<img src="{{ data_get($item, 'image_url') }}" alt="{{ data_get($item, 'image_alt') }}" data-eb-field="item_image">@endif@if(data_get($item, 'heading'))<h3 data-eb-field="item_heading">{{ data_get($item, 'heading') }}</h3>@endif@if(data_get($item, 'body'))<p data-eb-field="item_body">{{ data_get($item, 'body') }}</p>@endif</article>@endforeach</div></div></section>
    @elseif($type === 'image_with_text')
        @php($ctaLink = $link(data_get($block, 'cta_url')))
        <section class="section" {!! $blockAttributes !!}><div class="site-shell split {{ data_get($block, 'image_position') === 'right' ? 'split--reverse' : '' }}"><div class="split__image">@if(data_get($block, 'image_url'))<img src="{{ data_get($block, 'image_url') }}" alt="{{ data_get($block, 'image_alt') }}" data-eb-select="section" data-eb-block-index="{{ $loop->index }}" data-eb-field="image">@endif</div><div><p class="eyebrow" data-eb-field="label">{{ data_get($block, 'label') }}</p><h2 data-eb-field="heading">{{ data_get($block, 'heading') }}</h2><p class="copy" data-eb-field="body">{{ data_get($block, 'body') }}</p>@if(data_get($block, 'cta_url') && data_get($block, 'cta_label'))<a class="button" {!! $linkAttributes($ctaLink) !!} data-eb-select="section" data-eb-block-index="{{ $loop->index }}" data-eb-field="cta_label">{{ data_get($block, 'cta_label') }}</a>@endif</div></div></section>
    @elseif($type === 'gallery')
        <section class="section section--soft" {!! $blockAttributes !!}><div class="site-shell"><p class="eyebrow" data-eb-field="label">{{ data_get($block, 'label') }}</p><h2 data-eb-field="heading">{{ data_get($block, 'heading') }}</h2><div class="gallery">@foreach((array) data_get($block, 'items', []) as $item)@if(data_get($item, 'image_url'))<img src="{{ data_get($item, 'image_url') }}" alt="{{ data_get($item, 'image_alt') }}" data-eb-select="section" data-eb-block-index="{{ $loop->parent->index }}" data-eb-item-index="{{ $loop->index }}" data-eb-field="item_image">@endif@endforeach</div></div></section>
    @elseif($type === 'faq_list')
        <section class="section" {!! $blockAttributes !!}><div class="site-shell"><p class="eyebrow" data-eb-field="label">{{ data_get($block, 'label') }}</p><h2 data-eb-field="heading">{{ data_get($block, 'heading') }}</h2><div class="faq">@foreach((array) data_get($block, 'items', []) as $item)<details data-eb-select="section" data-eb-block-index="{{ $loop->parent->index }}" data-eb-item-index="{{ $loop->index }}"><summary data-eb-field="item_heading">{{ data_get($item, 'heading') }}</summary><p data-eb-field="item_body">{{ data_get($item, 'body') }}</p></details>@endforeach</div></div></section>
    @elseif($type === 'testimonial')
        <section class="section section--soft" {!! $blockAttributes !!}><div class="site-shell"><p class="eyebrow" data-eb-field="label">{{ data_get($block, 'label', 'Customer-first service') }}</p><blockquote class="quote" data-eb-field="body">“{{ data_get($block, 'body') }}”</blockquote>@if(data_get($block, 'heading'))<strong data-eb-field="heading">{{ data_get($block, 'heading') }}</strong>@endif</div></section>
    @elseif($type === 'contact_form')
        <section class="section section--soft" id="contact" {!! $blockAttributes !!}><div class="site-shell contact-card"><p class="eyebrow" data-eb-field="label">{{ data_get($block, 'label', 'Contact') }}</p><h2 data-eb-field="heading">{{ data_get($block, 'heading', 'Contact us') }}</h2>@if(data_get($block, 'body'))<p class="copy" data-eb-field="body">{{ data_get($block, 'body') }}</p>@endif @if(session('website_form_status'))<p role="status">{{ session('website_form_status') }}</p>@endif @if($isDraftPreview)<button class="button" type="button" data-eb-select="section" data-eb-block-index="{{ $loop->index }}" data-eb-field="form">Preview form</button>@else<form method="POST" action="{{ route('managed-website.forms.submit', ['page' => $page], false) }}">@csrf<input type="text" name="website" hidden tabindex="-1" autocomplete="off"><label class="field">Name<input required name="name"></label><label class="field">Email<input required type="email" name="email"></label><label class="field">Phone<input name="phone"></label><label class="field">How can we help?<textarea required name="message"></textarea></label><button class="button" type="submit">Send message</button></form>@endif</div></section>
    @else
        @php($ctaLink = $link(data_get($block, 'cta_url')))
        <section class="section {{ $type === 'cta' ? 'section--soft' : '' }}" {!! $blockAttributes !!}><div class="site-shell">@if(data_get($block, 'label'))<p class="eyebrow" data-eb-field="label">{{ data_get($block, 'label') }}</p>@endif @if(data_get($block, 'heading'))<h2 data-eb-field="heading">{{ data_get($block, 'heading') }}</h2>@endif @if(data_get($block, 'body'))<p class="copy" data-eb-field="body">{{ data_get($block, 'body') }}</p>@endif @if(data_get($block, 'image_url'))<img src="{{ data_get($block, 'image_url') }}" alt="{{ data_get($block, 'image_alt') }}" style="border-radius:var(--corner);max-width:100%" data-eb-select="section" data-eb-block-index="{{ $loop->index }}" data-eb-field="image">@endif @if(data_get($block, 'cta_url') && data_get($block, 'cta_label'))<a class="button" {!! $linkAttributes($ctaLink) !!} data-eb-select="section" data-eb-block-index="{{ $loop->index }}" data-eb-field="cta_label">{{ data_get($block, 'cta_label') }}</a>@endif</div></section>
    @endif
@endforeach
<footer class="site-footer" data-eb-select="footer" tabindex="{{ $isEditorPreview ? '0' : '-1' }}"><div class="site-shell site-footer__grid"><div><a class="brand" {!! $linkAttributes($homeLink) !!} data-eb-select="footer" data-eb-field="theme_name">{{ data_get($themeSettings, 'theme_name') ?: $tenant->name }}</a><p data-eb-field="footer_tagline">{{ data_get($footer, 'tagline') }}</p><p data-eb-field="footer_copyright">{{ data_get($footer, 'copyright') ?: '© '.now()->year.' '.(data_get($themeSettings, 'theme_name') ?: $tenant->name) }}</p></div><div>@foreach($navigation as $item)@php($itemLink = $link(data_get($item, 'url')))<a {!! $linkAttributes($itemLink) !!} data-eb-select="navigation" data-eb-navigation-index="{{ $loop->index }}" data-eb-field="navigation_label">{{ data_get($item, 'label') }}</a>@endforeach</div></div></footer>
@if($isEditorPreview)
<script>
    (() => {
        const send = (element, source) => {
            const field = (source instanceof Element ? source.closest('[data-eb-field]')?.dataset.ebField : null) ?? element.dataset.ebField ?? null;
            const selection = {
                type: 'everbranch.website.editor.select',
                kind: element.dataset.ebSelect,
                blockIndex: element.dataset.ebBlockIndex ?? null,
                itemIndex: element.dataset.ebItemIndex ?? null,
                navigationIndex: element.dataset.ebNavigationIndex ?? null,
                field,
            };
            window.parent.postMessage(selection, window.location.origin);
        };
        document.addEventListener('click', (event) => {
            const element = event.target.closest('[data-eb-select]');
            if (!element) return;
            event.preventDefault();
            event.stopPropagation();
            send(element, event.target);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const element = event.target.closest('[data-eb-select]');
            if (!element) return;
            event.preventDefault();
            send(element, event.target);
        });
    })();
</script>
@endif
</body>
</html>
