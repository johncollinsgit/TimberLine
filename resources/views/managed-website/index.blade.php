<x-layouts::app.sidebar title="Website">
    <flux:main>
        <div class="mx-auto max-w-[1440px] space-y-6 pb-10">
            @if(session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-950" role="status">{{ session('status') }}</div>@endif

            @if(! $site)
                <section class="rounded-3xl border border-zinc-200 bg-white p-8 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-[.16em] text-emerald-800">Everbranch Website</p>
                    <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-950">Create your website as a safe draft.</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-zinc-600">Choose a theme, build pages in the live editor, and publish only when your workspace is approved. This never changes an existing Shopify store, checkout, order, or customer record.</p>
                    @if($isEditorEnabled)<form method="POST" action="{{ route('managed-website.create') }}" class="mt-6">@csrf<button class="fb-btn fb-btn-primary" type="submit">Create website</button></form>@else
                        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">Website access is held behind the rollout gate. No public site or commerce data has been created.</div>
                    @endif
                </section>
            @else
                @php
                    $draftTheme = $site->draftSiteVersion;
                    $previewPage = $pages->firstWhere('slug', '/') ?: $pages->first();
                @endphp
                <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div><p class="text-xs font-bold uppercase tracking-[.15em] text-emerald-800">Website</p><h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-950">Your business website</h1><p class="mt-1 text-sm text-zinc-600">Build a clear, mobile-ready site with editable pages, menus, images, and lead forms.</p></div>
                    <div class="flex flex-wrap gap-2">@if($site->status === 'published' && $isPublicRenderEnabled)<a class="fb-btn fb-btn-secondary" target="_blank" rel="noopener" href="{{ url('/') }}">View live site</a>@endif</div>
                </header>

                <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm">
                    <div class="grid min-h-[360px] place-items-center bg-[#f6f7f6] p-5 lg:p-10">
                        @if($draftTheme?->thumbnail_path)
                            <div class="w-full max-w-5xl overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-[0_18px_42px_-34px_rgba(20,35,39,.75)]">
                                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 text-xs"><strong>{{ data_get($draftTheme?->settings, 'theme_name', 'Everbranch Website') }}</strong><span class="text-zinc-500">Draft screenshot</span></div>
                                <img class="h-[310px] w-full object-cover object-top" src="{{ route('managed-website.thumbnails.show', ['siteVersion' => $draftTheme]) }}" alt="Current website draft screenshot">
                            </div>
                        @elseif($previewPage)
                            <div class="w-full max-w-5xl overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-[0_18px_42px_-34px_rgba(20,35,39,.75)]">
                                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 text-xs"><strong>{{ data_get($draftTheme?->settings, 'theme_name', data_get($site->settings, 'theme_name', 'Everbranch Website')) }}</strong><span class="text-zinc-500">Live draft preview</span></div>
                                <iframe class="h-[310px] w-full border-0 bg-white" title="Website draft preview" src="{{ route('managed-website.editor.preview', ['page' => $previewPage]) }}"></iframe>
                            </div>
                        @else
                            <p class="text-sm text-zinc-500">Your real draft preview will appear after you add a page.</p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-5 border-t border-zinc-200 p-6 sm:flex-row sm:items-center sm:justify-between">
                        <div><div class="flex items-center gap-3"><h2 class="text-xl font-bold text-zinc-950">{{ data_get($draftTheme?->settings, 'theme_name', data_get($site->settings, 'theme_name', 'Website draft')) }}</h2><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $site->status === 'published' ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900' }}">{{ $site->status === 'published' ? 'Live' : 'Draft' }}</span></div><p class="mt-1 text-sm text-zinc-500">This is your actual draft rendered by the live-site engine. Immutable versions keep rollback safe.</p></div>
                        <div class="flex gap-2"><a class="fb-btn fb-btn-secondary" href="{{ route('managed-website.editor', ['page' => $pages->first()]) }}">Edit theme</a>@if($isEditorEnabled && $isPublishingEnabled)<form method="POST" action="{{ route('managed-website.publish') }}">@csrf<button class="fb-btn fb-btn-primary" type="submit">Publish changes</button></form>@endif</div>
                    </div>
                </section>

                <section><div class="mb-4 flex items-end justify-between"><div><h2 class="text-xl font-bold text-zinc-950">Theme library</h2><p class="mt-1 text-sm text-zinc-600">Every theme starts as an editable draft. Applying a theme never changes the public site until you publish.</p></div></div>
                    <div class="grid gap-4 lg:grid-cols-3">@foreach($themes as $theme)
                        <article class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm"><div class="aspect-[16/7] overflow-hidden bg-zinc-100"><img class="h-full w-full object-cover" src="{{ $theme['thumbnail'] }}" alt="{{ $theme['name'] }} starter theme preview"></div><div class="p-5"><p class="text-xs font-bold uppercase tracking-[.15em]" style="color:{{ data_get($theme, 'palette.brand') }}">{{ $theme['eyebrow'] }}</p><h3 class="mt-2 font-bold text-zinc-950">{{ $theme['name'] }}</h3><p class="mt-2 min-h-12 text-sm leading-5 text-zinc-600">{{ $theme['description'] }}</p><form class="mt-4" method="POST" action="{{ route('managed-website.themes.apply') }}">@csrf<input type="hidden" name="theme_key" value="{{ $theme['key'] }}"><button class="fb-btn fb-btn-secondary w-full justify-center" type="submit">Apply as draft</button></form></div></article>
                    @endforeach</div>
                </section>

                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-bold text-zinc-950">Pages</h2><p class="mt-1 text-sm text-zinc-600">Each page has its own draft and published version history.</p></div></div><div class="mt-4 divide-y divide-zinc-100 border-y border-zinc-100">@foreach($pages as $websitePage)<div class="flex items-center justify-between gap-4 py-3"><div><strong class="text-sm text-zinc-950">{{ $websitePage->title }}</strong><span class="ml-2 text-xs text-zinc-500">{{ $websitePage->slug }}</span></div><div class="flex gap-2"><a class="text-xs font-bold text-emerald-800 hover:underline" href="{{ route('managed-website.editor', ['page' => $websitePage]) }}">Edit</a>@if($websitePage->slug !== '/')<form method="POST" action="{{ route('managed-website.pages.destroy', ['page' => $websitePage]) }}" onsubmit="return confirm('Remove this page?')">@csrf @method('DELETE')<button class="text-xs font-bold text-rose-700 hover:underline" type="submit">Delete</button></form>@endif</div></div>@endforeach</div>@if($isEditorEnabled)<form method="POST" action="{{ route('managed-website.pages.store') }}" class="mt-5 grid gap-3 md:grid-cols-[1fr_1fr_180px_auto]">@csrf<input required name="title" class="rounded-lg border-zinc-300 text-sm" placeholder="Page title"><input required name="slug" class="rounded-lg border-zinc-300 text-sm" placeholder="page-address"><select name="page_type" class="rounded-lg border-zinc-300 text-sm"><option value="landing">Landing page</option><option value="services">Services</option><option value="about">About</option><option value="contact">Contact</option><option value="faq">FAQ</option></select><button class="fb-btn fb-btn-secondary justify-center" type="submit">Add page</button></form>@endif</section>
            @endif
        </div>
    </flux:main>
</x-layouts::app.sidebar>
