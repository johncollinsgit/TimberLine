<x-layouts::app.sidebar title="Website">
    <flux:main>
        <div class="fb-workflow-shell space-y-6">
            @if(session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status">{{ session('status') }}</div>
            @endif

            @if(! $site)
                <section class="rounded-3xl border border-zinc-200 bg-white p-8 shadow-sm">
                    <div class="max-w-2xl">
                        <p class="text-xs font-bold uppercase tracking-[.16em] text-emerald-800">Everbranch Managed Website</p>
                        <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-950">Your next customer-ready page starts as a safe draft.</h1>
                        <p class="mt-3 text-sm leading-6 text-zinc-600">Build clear pages, collect tenant-owned leads, and send visitors to the checkout or booking tools you already trust. Nothing here changes Shopify Checkout, orders, customers, rewards, or connections.</p>
                        @if($isEditorEnabled)
                            <form method="POST" action="{{ route('managed-website.create') }}" class="mt-6">@csrf
                                <button class="fb-btn fb-btn-primary" type="submit">Create website draft</button>
                            </form>
                        @else
                            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">This workspace has the Website app, but editing is still held behind the rollout gate. No public page or customer data has been created.</div>
                        @endif
                    </div>
                </section>
            @else
                @include('managed-websites.partials.editor', ['site' => $site, 'pages' => $pages, 'templates' => $templates, 'isEditorEnabled' => $isEditorEnabled, 'isPublishingEnabled' => $isPublishingEnabled])

                <section class="grid gap-5 lg:grid-cols-[1.2fr_.8fr]">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-bold text-zinc-950">Publish safely</h2><p class="mt-1 text-sm text-zinc-600">Publishing creates immutable snapshots. A rollback creates a new approved snapshot; it never overwrites history.</p></div><span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $site->status === 'published' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-zinc-200 bg-zinc-50 text-zinc-700' }}">{{ str($site->status)->headline() }}</span></div>
                        <div class="mt-4 flex flex-wrap gap-3">
                            @if($isEditorEnabled && $isPublishingEnabled)
                                <form method="POST" action="{{ route('managed-website.publish') }}">@csrf<button class="fb-btn fb-btn-primary" type="submit">Publish approved draft</button></form>
                            @endif
                            @if($site->status === 'published' && $isPublicRenderEnabled)
                                <a class="fb-btn fb-btn-secondary" target="_blank" rel="noopener" href="{{ url('/') }}">Open published site</a>
                            @endif
                        </div>
                    </div>
                    <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm text-sky-950"><h2 class="font-bold">Rollback posture</h2><p class="mt-2 leading-6">A global public-render gate, tenant editor allowlist, publishing freeze, and module availability gate can each stop this product without changing your existing applications.</p></div>
                </section>

                @if($isEditorEnabled)
                    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                        <h2 class="text-lg font-bold text-zinc-950">Add a structured page</h2>
                        <form method="POST" action="{{ route('managed-website.pages.store') }}" class="mt-4 grid gap-3 md:grid-cols-3">@csrf
                            <input required name="title" class="rounded-lg border-zinc-300 text-sm" placeholder="Page title">
                            <input required name="slug" class="rounded-lg border-zinc-300 text-sm" placeholder="services">
                            <select required name="page_type" class="rounded-lg border-zinc-300 text-sm">@foreach($templates as $template)<option value="{{ $template['key'] }}">{{ $template['label'] }}</option>@endforeach</select>
                            <button class="fb-btn fb-btn-secondary md:col-span-3 justify-center" type="submit">Add page draft</button>
                        </form>
                    </section>

                    <section class="space-y-5" aria-label="Page drafts">
                        @foreach($pages as $editorPage)
                            @php($draft = $editorPage->draftVersion)
                            @php($hero = collect((array) ($draft?->blocks ?? []))->firstWhere('type', 'hero') ?: [])
                            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm"><form method="POST" action="{{ route('managed-website.pages.update', ['page' => $editorPage]) }}">@csrf @method('PUT')
                                <input type="hidden" name="blocks[0][type]" value="hero">
                                <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-[.14em] text-emerald-800">{{ str($editorPage->page_type)->headline() }} page</p><h2 class="mt-1 text-lg font-bold text-zinc-950">{{ $editorPage->slug === '/' ? 'Home' : '/'.$editorPage->slug }}</h2></div><button class="fb-btn fb-btn-secondary" type="submit">Save draft</button></div>
                                <div class="mt-4 grid gap-4 md:grid-cols-2"><label class="text-sm font-semibold text-zinc-700">Page title<input required name="title" value="{{ $draft?->title ?: $editorPage->title }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label><label class="text-sm font-semibold text-zinc-700">Hero heading<input required name="blocks[0][heading]" value="{{ data_get($hero, 'heading') }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label><label class="text-sm font-semibold text-zinc-700 md:col-span-2">Supporting text<textarea required name="blocks[0][body]" rows="3" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">{{ data_get($hero, 'body') }}</textarea></label><label class="text-sm font-semibold text-zinc-700">Button label<input name="blocks[0][cta_label]" value="{{ data_get($hero, 'cta_label') }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label><label class="text-sm font-semibold text-zinc-700">External checkout, booking, or contact URL<input name="blocks[0][cta_url]" value="{{ data_get($hero, 'cta_url') }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" placeholder="https://"></label><label class="text-sm font-semibold text-zinc-700">SEO title<input name="seo[title]" value="{{ data_get($draft?->seo, 'title') }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label><label class="text-sm font-semibold text-zinc-700">SEO description<input name="seo[description]" value="{{ data_get($draft?->seo, 'description') }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label></div>
                            </form>
                                @php($priorPublished = $editorPage->versions()->where('status', 'published')->latest('id')->get())
                                @if($priorPublished->count() > 1)
                                    <div class="mt-4 border-t border-zinc-100 pt-4"><p class="text-xs font-semibold text-zinc-500">Published history</p><div class="mt-2 flex flex-wrap gap-2">@foreach($priorPublished->skip(1) as $historical)<form method="POST" action="{{ route('managed-website.pages.rollback', ['page' => $editorPage, 'version' => $historical]) }}">@csrf<button class="rounded-lg border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50" type="submit">Restore version {{ $historical->version_number }}</button></form>@endforeach</div></div>
                                @endif
                            </article>
                        @endforeach
                    </section>
                @endif
            @endif
        </div>
    </flux:main>
</x-layouts::app.sidebar>
