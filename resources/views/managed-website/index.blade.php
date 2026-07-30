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
                    <div class="flex flex-wrap gap-2">@if($site->status === 'published' && $isPublicRenderEnabled)<a class="fb-btn fb-btn-secondary" target="_blank" rel="noopener" href="{{ $publicUrl }}">View live site</a>@endif</div>
                </header>

                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm" aria-labelledby="website-domain-heading">
                    <div class="border-b border-zinc-200 px-6 py-5">
                        <p class="text-xs font-bold uppercase tracking-[.15em] text-emerald-800">Website address</p>
                        <h2 id="website-domain-heading" class="mt-1 text-xl font-bold tracking-tight text-zinc-950">Connect a domain you already own</h2>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-zinc-600">Use your own address without moving your business data into Everbranch. We verify ownership first; your current published site stays untouched until you activate the domain.</p>
                    </div>
                    @if(! $domainsEnabled)
                        <div class="m-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">Custom domains are available after the public-host pilot is approved for this workspace. Your existing Everbranch preview address remains unchanged.</div>
                    @else
                        @php $siteDomains = $site->domains->where('status', '!=', 'disabled')->sortByDesc('is_primary'); @endphp
                        <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,.9fr)]">
                            <div>
                                @if($siteDomains->isEmpty())
                                    <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-5">
                                        <h3 class="font-bold text-zinc-950">Step 1 — Add your domain</h3>
                                        <p class="mt-1 text-sm leading-6 text-zinc-600">Enter an address like <code class="font-semibold text-zinc-800">yourbusiness.com</code>. A full homepage URL is okay too; we will only use its domain.</p>
                                        <form method="POST" action="{{ route('managed-website.domains.request') }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                                            @csrf
                                            <label class="sr-only" for="website-domain">Your domain</label>
                                            <input id="website-domain" name="domain" required autocomplete="url" placeholder="yourbusiness.com" class="min-w-0 flex-1 rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-700 focus:ring-emerald-700">
                                            <button class="fb-btn fb-btn-primary justify-center" type="submit">Continue</button>
                                        </form>
                                        @error('domain')<p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                                    </div>
                                @else
                                    <div class="space-y-3">
                                        @foreach($siteDomains as $domain)
                                            <article class="rounded-xl border border-zinc-200 p-4">
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div><h3 class="font-bold text-zinc-950">{{ $domain->hostname }}</h3><p class="mt-1 text-sm text-zinc-600">{{ $domain->is_primary && $domain->status === 'active' ? 'Primary live address' : ucfirst($domain->status).' connection' }}</p></div>
                                                    <span class="border px-2 py-1 text-xs font-bold {{ $domain->status === 'active' ? 'border-emerald-300 bg-emerald-50 text-emerald-950' : ($domain->status === 'verified' ? 'border-sky-300 bg-sky-50 text-sky-950' : 'border-amber-300 bg-amber-50 text-amber-950') }}">{{ $domain->status === 'active' ? 'Live' : ucfirst($domain->status) }}</span>
                                                </div>
                                                @if($domain->status === 'pending')
                                                    <div class="mt-4 rounded-lg bg-zinc-50 p-4 text-sm text-zinc-700">
                                                        <p class="font-bold text-zinc-950">Step 2 — Prove you own it</p>
                                                        <p class="mt-1 leading-6">In your DNS provider, create this TXT record. Do not replace existing email or website records.</p>
                                                        <dl class="mt-3 grid gap-2 font-mono text-xs sm:grid-cols-[100px_minmax(0,1fr)]"><dt class="font-sans font-bold text-zinc-600">Name</dt><dd class="break-all select-all text-zinc-950">{{ '_everbranch-verify.'.$domain->hostname }}</dd><dt class="font-sans font-bold text-zinc-600">Value</dt><dd class="break-all select-all text-zinc-950">everbranch-site={{ $domain->verification_token }}</dd></dl>
                                                        <form class="mt-4" method="POST" action="{{ route('managed-website.domains.verify', ['domain' => $domain]) }}">@csrf<button class="fb-btn fb-btn-secondary" type="submit">Check connection</button></form>
                                                        <form class="mt-3" method="POST" action="{{ route('managed-website.domains.cancel', ['domain' => $domain]) }}" onsubmit="return confirm('Remove this attempted domain setup? You can enter a different address next.')">@csrf<button class="text-sm font-bold text-rose-700 hover:underline" type="submit">Remove this attempted address</button></form>
                                                        @if($domain->last_error)<p class="mt-3 text-xs leading-5 text-amber-900">{{ $domain->last_error }}</p>@endif
                                                    </div>
                                                @elseif($domain->status === 'verified')
                                                    <div class="mt-4 rounded-lg bg-sky-50 p-4 text-sm leading-6 text-sky-950"><p class="font-bold">Step 3 — Point the website address to Everbranch</p><p class="mt-1">Ownership is verified. The final live routing check is completed by Everbranch so your public site cannot be pointed at the wrong workspace.</p><form class="mt-3" method="POST" action="{{ route('managed-website.domains.activate', ['domain' => $domain]) }}">@csrf<button class="fb-btn fb-btn-primary" type="submit">Activate {{ $domain->hostname }}</button></form><form class="mt-3" method="POST" action="{{ route('managed-website.domains.cancel', ['domain' => $domain]) }}" onsubmit="return confirm('Remove this attempted domain setup? You can enter a different address next.')">@csrf<button class="text-sm font-bold text-rose-700 hover:underline" type="submit">Remove this attempted address</button></form></div>
                                                @elseif($domain->status === 'active')
                                                    <div class="mt-4 rounded-lg bg-emerald-50 p-4 text-sm leading-6 text-emerald-950"><p class="font-bold">This domain is live.</p><p class="mt-1">If an incident requires it, disabling this connection stops only this public host. It does not delete the site, its pages, leads, or versions.</p><form class="mt-3" method="POST" action="{{ route('managed-website.domains.deactivate', ['domain' => $domain]) }}" onsubmit="return confirm('Disable this custom domain? Your published site will remain available on its Everbranch address.')">@csrf<button class="text-sm font-bold text-rose-700 hover:underline" type="submit">Disable domain</button></form></div>
                                                @endif
                                            </article>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <aside class="rounded-xl border border-zinc-200 bg-[#f8faf9] p-5 text-sm leading-6 text-zinc-700">
                                <h3 class="font-bold text-zinc-950">How this stays safe</h3>
                                <ol class="mt-3 space-y-3"><li><strong>1. You keep control.</strong> Everbranch never asks for your registrar password or replaces unrelated DNS records.</li><li><strong>2. We verify first.</strong> A unique TXT record proves ownership before a hostname can be connected.</li><li><strong>3. No surprise swap.</strong> A published snapshot and a routing check are required before activation.</li><li><strong>4. Fast rollback.</strong> Disable a domain without deleting content or changing any Shopify, Square, Stripe, customer, order, or workflow data.</li></ol>
                                @if($domainTarget)<p class="mt-4 border-t border-zinc-200 pt-4 text-xs text-zinc-600">Connection target: <code class="font-semibold text-zinc-800">{{ $domainTarget }}</code></p>@endif
                            </aside>
                        </div>
                    @endif
                </section>

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
