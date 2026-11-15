<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Edit {{ $page->title }} · Website · Everbranch</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-zinc-100 text-zinc-900">
    @if(data_get($trial ?? [], 'state') === 'active')
        <div class="px-4 pt-4">@include('onboarding.partials.trial-status')</div>
    @endif
    @php
        // Keep the structured values outside of @json(). Blade's directive parser
        // cannot safely parse nested array literals and closures in an attribute.
        $editorPage = [
            'id' => $page->id,
            'version_id' => $page->draft_version_id,
            'title' => $page->draftVersion?->title ?: $page->title,
            'slug' => $page->slug,
            'blocks' => $page->draftVersion?->blocks ?: [],
            'seo' => $page->draftVersion?->seo ?: [],
        ];
        $editorPages = $pages->map(fn ($item) => [
            'id' => $item->id,
            'title' => $item->title,
            'slug' => $item->slug,
            'url' => route('managed-website.editor', ['page' => $item]),
        ])->values();
        $editorSite = [
            'name' => data_get(($theme ?? null)?->settings, 'theme_name', data_get($site->settings, 'theme_name', $tenant->brandProfile?->display_name ?: $tenant->name)),
            'version_id' => $site->draft_site_version_id,
            'status' => $site->status,
            'theme' => ($theme ?? null)?->settings ?: [],
            'navigation' => ($theme ?? null)?->navigation ?: [],
            'preview_url' => route('managed-website.editor.preview', ['page' => $page]),
            'preview_site_url' => route('managed-website.editor.preview.site', ['page' => $page]),
        ];
    @endphp
    <div id="managed-website-editor-root"
         data-page='@json($editorPage)'
         data-pages='@json($editorPages)'
         data-site='@json($editorSite)'
         data-related-photos='@json($relatedPhotos ?? [])'
         data-history-url="{{ route('managed-website.history.index', ['page' => $page]) }}"
         data-media-max-bytes="{{ config('managed_website.media_max_bytes', 10485760) }}"
         data-photo-help="{{ data_get($trial ?? [], 'registered_trial', false) && data_get($trial ?? [], 'state') !== 'paid' ? 'Private trial: 10 photos · 50 MB total' : '' }}"
         data-save-url="{{ route('managed-website.editor.save', ['page' => $page]) }}"
         data-theme-save-url="{{ route('managed-website.editor.theme.save') }}"
         data-publish-url="{{ route('managed-website.editor.publish') }}"
         data-media-url="{{ route('managed-website.media.index') }}"
         data-media-upload-url="{{ route('managed-website.media.store') }}"
         data-index-url="{{ route('managed-website.index') }}"
         data-private-trial="{{ data_get($trial ?? [], 'registered_trial', false) && data_get($trial ?? [], 'state') !== 'paid' ? 'true' : 'false' }}"
         data-publishing-enabled="{{ ($canPublishSite ?? $isPublishingEnabled) ? 'true' : 'false' }}"></div>
</body>
</html>
