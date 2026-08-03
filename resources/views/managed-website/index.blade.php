<x-layouts::app.sidebar title="Website">
    <flux:main>
        <div class="mx-auto max-w-6xl space-y-6 pb-12">
            @if(session('status'))<div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-950" role="status">{{ session('status') }}</div>@endif
            <header class="flex flex-col gap-4 border-b border-zinc-200 pb-6 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-xs font-bold uppercase tracking-[.16em] text-emerald-800">Website Branch · quote-first pilot</p><h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-950">Your electrician website</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-600">Give customers a clear way to request electrical work or call your business. Payments, domains, booking, and customer systems are not part of this pilot.</p></div>
                @if($isEditorEnabled)<button class="fb-btn fb-btn-primary" type="button" onclick="document.getElementById('website-setup').showModal()">{{ $site ? 'Continue setup' : 'Set up website' }}</button>@endif
            </header>
            @if(! $isEditorEnabled)
                <section class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">Website access is still being approved for this workspace. Nothing public, billable, or connected has been created.</section>
            @else
                @php
                    $draftTheme = $site->draftSiteVersion;
                    $previewPage = $pages->firstWhere('slug', '/') ?: $pages->first();
                @endphp
                <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div><p class="text-xs font-bold uppercase tracking-[.15em] text-emerald-800">Website</p><h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-950">Your business website</h1><p class="mt-1 text-sm text-zinc-600">Build a clear, mobile-ready site with editable pages, menus, images, and lead forms.</p></div>
                    <div class="flex flex-wrap gap-2">@if($site->status === 'published' && $isPublicRenderEnabled)<a class="fb-btn fb-btn-secondary" target="_blank" rel="noopener" href="{{ $publicUrl }}">View live site</a>@endif</div>
                </header>

                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm" aria-labelledby="website-address-heading">
                    <div class="flex flex-col gap-4 border-b border-zinc-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[.15em] text-emerald-800">Included Everbranch address</p>
                            <h2 id="website-address-heading" class="mt-1 text-xl font-bold tracking-tight text-zinc-950">{{ parse_url($platformUrl, PHP_URL_HOST) }}</h2>
                            <p class="mt-1 text-sm leading-6 text-zinc-600">Reserved automatically. No DNS setup is needed; it goes live the moment this Website is published.</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $site->status === 'published' && $site->public_enabled ? 'bg-emerald-100 text-emerald-900' : 'bg-zinc-100 text-zinc-700' }}">{{ $site->status === 'published' && $site->public_enabled ? 'Live now' : 'Reserved' }}</span>
                            @if($site->status === 'published' && $site->public_enabled)<a class="fb-btn fb-btn-secondary" href="{{ $platformUrl }}" target="_blank" rel="noopener">Open site</a>@endif
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm" aria-labelledby="website-domain-heading">
                    <div class="border-b border-zinc-200 px-6 py-5">
                        <p class="text-xs font-bold uppercase tracking-[.15em] text-emerald-800">Optional custom address</p>
                        <h2 id="website-domain-heading" class="mt-1 text-xl font-bold tracking-tight text-zinc-950">Use a domain you already own</h2>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-zinc-600">Enter the address once, add the generated records in one DNS visit, and let Everbranch check the connection. Your included Everbranch address stays live throughout the change.</p>
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
                                                        <p class="font-bold text-zinc-950">Step 2 — Add these records once</p>
                                                        <p class="mt-1 leading-6">In your DNS provider, add the records below. Do not replace email records. If a website record already uses this exact name, update that record instead of creating a duplicate.</p>
                                                        <div class="mt-3 overflow-x-auto"><table class="min-w-full text-left text-xs"><thead class="text-zinc-500"><tr><th class="pb-2 pr-4">Purpose</th><th class="pb-2 pr-4">Type</th><th class="pb-2 pr-4">Name</th><th class="pb-2">Value</th></tr></thead><tbody class="divide-y divide-zinc-200 font-mono">@foreach($domainConnectionRecords->get($domain->id, []) as $record)<tr><td class="py-2 pr-4 font-sans font-semibold text-zinc-700">{{ $record['label'] }}</td><td class="py-2 pr-4 text-zinc-950">{{ $record['type'] }}</td><td class="break-all py-2 pr-4 text-zinc-950 select-all">{{ $record['name'] }}</td><td class="break-all py-2 text-zinc-950 select-all">{{ $record['value'] }}</td></tr>@endforeach</tbody></table></div>
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
    <dialog id="website-setup" class="w-[min(92vw,620px)] border border-zinc-200 bg-white p-0 shadow-2xl backdrop:bg-zinc-950/40">
        <form method="POST" action="{{ route('managed-website.setup.save') }}" class="p-6" data-wizard>@csrf
            <div class="flex items-center justify-between border-b border-zinc-200 pb-4"><div><p class="text-xs font-bold uppercase tracking-[.15em] text-emerald-800">Website setup</p><h2 class="mt-1 text-xl font-bold text-zinc-950">A few quick choices</h2></div><button type="button" class="text-xl text-zinc-500" onclick="this.closest('dialog').close()" aria-label="Close">×</button></div>
            <section data-step="1" class="space-y-4 pt-5"><h3 class="text-lg font-bold">1. Confirm your customer goal</h3><p class="text-sm leading-6 text-zinc-600">This pilot is set for an electrician who sells services and wants quote requests plus phone calls.</p><div class="border border-zinc-200 bg-zinc-50 p-4 text-sm"><strong>Electrician / trades</strong><br>Services · Request a quote · Call the business</div></section>
            <section data-step="2" class="hidden space-y-4 pt-5"><h3 class="text-lg font-bold">2. Your starting design</h3><div class="border-2 border-emerald-700 p-4"><strong>Collins Electric</strong><p class="mt-1 text-sm text-zinc-600">A clear service-first design with space for electrical work, contact details, and quote requests.</p></div></section>
            <section data-step="3" class="hidden space-y-4 pt-5"><h3 class="text-lg font-bold">3. Business details</h3><div class="grid gap-3 sm:grid-cols-2"><label class="text-sm font-semibold">Business name<input name="contact_name" value="{{ old('contact_name', $setup?->contact_name ?: $tenant->name) }}" class="mt-1 block w-full rounded border-zinc-300"></label><label class="text-sm font-semibold">Email<input name="contact_email" type="email" value="{{ old('contact_email', $setup?->contact_email) }}" class="mt-1 block w-full rounded border-zinc-300"></label><label class="text-sm font-semibold">Phone<input name="contact_phone" value="{{ old('contact_phone', $setup?->contact_phone) }}" class="mt-1 block w-full rounded border-zinc-300"></label><label class="text-sm font-semibold">Hours<input name="hours" value="{{ old('hours', $setup?->hours) }}" placeholder="Mon–Fri, 8am–5pm" class="mt-1 block w-full rounded border-zinc-300"></label><label class="text-sm font-semibold sm:col-span-2">Service area<input name="service_area" value="{{ old('service_area', $setup?->service_area) }}" placeholder="Towns, counties, or neighborhoods you serve" class="mt-1 block w-full rounded border-zinc-300"></label></div></section>
            <section data-step="4" class="hidden space-y-4 pt-5"><h3 class="text-lg font-bold">4. Add your first service</h3><p class="text-sm leading-6 text-zinc-600">Customers will be able to request a quote for this service. No checkout or deposits are enabled.</p><label class="block text-sm font-semibold">Service name<input name="service_title" placeholder="Panel upgrade" class="mt-1 block w-full rounded border-zinc-300"></label><label class="block text-sm font-semibold">Clear description<textarea name="service_description" rows="3" placeholder="Tell customers when they should request this work." class="mt-1 block w-full rounded border-zinc-300"></textarea></label></section>
            <div class="mt-6 flex justify-between border-t border-zinc-200 pt-4"><button class="fb-btn fb-btn-secondary invisible" type="button" data-back>Back</button><div class="flex gap-2"><button class="fb-btn fb-btn-secondary" type="button" onclick="this.closest('dialog').close()">Save for later</button><button class="fb-btn fb-btn-primary" type="button" data-next>Continue</button><button class="fb-btn fb-btn-primary hidden" type="submit" data-finish>Save setup</button></div></div>
        </form>
    </dialog>
    <script>document.querySelectorAll('[data-wizard]').forEach(function(w){let n=1;const render=()=>{w.querySelectorAll('[data-step]').forEach(s=>s.classList.toggle('hidden',Number(s.dataset.step)!==n));w.querySelector('[data-back]').classList.toggle('invisible',n===1);w.querySelector('[data-next]').classList.toggle('hidden',n===4);w.querySelector('[data-finish]').classList.toggle('hidden',n!==4)};w.querySelector('[data-next]').onclick=()=>{n=Math.min(4,n+1);render()};w.querySelector('[data-back]').onclick=()=>{n=Math.max(1,n-1);render()};render()})</script>
</x-layouts::app.sidebar>
