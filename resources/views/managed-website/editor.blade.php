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
    @php
        // Keep the structured values outside of @json(). Blade's directive parser
        // cannot safely parse nested array literals and closures in an attribute.
        $editorPage = [
            'id' => $page->id,
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
         data-save-url="{{ route('managed-website.editor.save', ['page' => $page]) }}"
         data-theme-save-url="{{ route('managed-website.editor.theme.save') }}"
         data-publish-url="{{ route('managed-website.editor.publish') }}"
         data-media-url="{{ route('managed-website.media.index') }}"
         data-media-upload-url="{{ route('managed-website.media.store') }}"
         data-index-url="{{ route('managed-website.index') }}"
         data-publishing-enabled="{{ $isPublishingEnabled ? 'true' : 'false' }}"></div>
</body>
</html>
