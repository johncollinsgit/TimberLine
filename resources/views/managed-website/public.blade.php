<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ data_get($version->seo, 'title') ?: $version->title }}</title>
    @if(data_get($version->seo, 'description'))<meta name="description" content="{{ data_get($version->seo, 'description') }}">@endif
    <style>
        :root{--ink:{{ $tenant->brandProfile?->text_color ?: '#142327' }};--brand:{{ $tenant->brandProfile?->primary_color ?: '#1e5a63' }};--surface:{{ $tenant->brandProfile?->surface_color ?: '#ffffff' }}}*{box-sizing:border-box}body{background:var(--surface);color:var(--ink);font-family:ui-sans-serif,system-ui,sans-serif;margin:0}a{color:inherit}.shell{margin:auto;max-width:1120px;padding:0 1.25rem}.top{align-items:center;border-bottom:1px solid #e3e8e7;display:flex;justify-content:space-between;min-height:72px}.brand{font-weight:800;text-decoration:none}.nav{display:flex;gap:1rem}.nav a{color:#516363;font-size:.9rem;text-decoration:none}.block{padding:4.5rem 0}.block:nth-child(even){background:#f3f7f6}.hero{padding:6rem 0}.hero h1{font-family:Georgia,serif;font-size:clamp(2.4rem,6vw,4.8rem);line-height:.98;margin:0;max-width:12ch}.copy{font-size:1.1rem;line-height:1.7;max-width:60ch}.button{background:var(--brand);border-radius:.45rem;color:#fff;display:inline-block;font-weight:750;margin-top:1rem;padding:.8rem 1.1rem;text-decoration:none}.card{background:#fff;border:1px solid #dde6e4;border-radius:1rem;max-width:720px;padding:1.5rem}.field{display:block;font-size:.9rem;font-weight:700;margin:.8rem 0}.field input,.field textarea{border:1px solid #bfcfcb;border-radius:.45rem;display:block;font:inherit;margin-top:.35rem;padding:.65rem;width:100%}.field textarea{min-height:120px}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}@media(max-width:600px){.nav{display:none}.block,.hero{padding:3rem 0}}
    </style>
</head>
<body>
<header class="shell top"><a class="brand" href="/">{{ $tenant->brandProfile?->display_name ?: $tenant->name }}</a><nav class="nav">@foreach($site->pages->where('is_navigation_visible', true)->whereNotNull('published_version_id') as $navigation)<a href="{{ $navigation->slug === '/' ? '/' : '/'.$navigation->slug }}">{{ $navigation->title }}</a>@endforeach</nav></header>
@foreach((array) $version->blocks as $block)
    @php($type = (string) data_get($block, 'type'))
    <section class="{{ $type === 'hero' ? 'hero' : 'block' }}"><div class="shell @if($type === 'contact_form') card @endif">
        @if($type === 'contact_form')
            <h1>{{ data_get($block, 'heading', 'Contact us') }}</h1>@if(session('website_form_status'))<p>{{ session('website_form_status') }}</p>@endif
            <form method="POST" action="{{ route('managed-website.forms.submit', ['page' => $page]) }}">@csrf<input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off"><label class="field">Name<input required name="name"></label><label class="field">Email<input required type="email" name="email"></label><label class="field">Phone<input name="phone"></label><label class="field">How can we help?<textarea required name="message"></textarea></label><button class="button" type="submit">Send message</button></form>
        @else
            @if(data_get($block, 'heading'))<h1 @class(['hero-heading' => $type === 'hero'])>{{ data_get($block, 'heading') }}</h1>@endif
            @if(data_get($block, 'body'))<p class="copy">{{ data_get($block, 'body') }}</p>@endif
            @if(data_get($block, 'image_url'))<img src="{{ data_get($block, 'image_url') }}" alt="{{ data_get($block, 'image_alt', '') }}" style="max-width:100%;border-radius:1rem">@endif
            @if(data_get($block, 'cta_url') && data_get($block, 'cta_label'))<a class="button" href="{{ data_get($block, 'cta_url') }}">{{ data_get($block, 'cta_label') }}</a>@endif
        @endif
    </div></section>
@endforeach
</body></html>
