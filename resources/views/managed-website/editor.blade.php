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
    <div id="managed-website-editor-root"
         data-page='@json(['id' => $page->id, 'title' => $page->draftVersion?->title ?: $page->title, 'slug' => $page->slug, 'blocks' => $page->draftVersion?->blocks ?: [], 'seo' => $page->draftVersion?->seo ?: []])'
         data-pages='@json($pages->map(fn ($item) => ['id' => $item->id, 'title' => $item->title, 'slug' => $item->slug])->values())'
         data-site='@json(['name' => data_get($site->settings, 'theme_name', $tenant->brandProfile?->display_name ?: $tenant->name), 'status' => $site->status, 'preview_url' => $site->status === 'published' ? url('/') : null])'
         data-save-url="{{ route('managed-website.editor.save', ['page' => $page]) }}"
         data-publish-url="{{ route('managed-website.editor.publish') }}"
         data-index-url="{{ route('managed-website.index') }}"
         data-publishing-enabled="{{ $isPublishingEnabled ? 'true' : 'false' }}"></div>
</body>
</html>
