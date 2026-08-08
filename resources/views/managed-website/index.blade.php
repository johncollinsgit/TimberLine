<x-layouts::app.sidebar title="Website">
    <flux:main>
        <div class="mx-auto max-w-6xl space-y-5 pb-12">
            @if(session('status'))<div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-950" role="status">{{ session('status') }}</div>@endif
            <header class="eb-admin-page-header">
                <div class="eb-admin-page-title">
                    <span class="eb-admin-page-icon" aria-hidden="true"><flux:icon.globe-alt class="size-5" /></span>
                    <h1>Website</h1>
                </div>
                @if($isEditorEnabled)
                    <div class="eb-admin-page-actions">
                        @if($site?->status === 'published' && $isPublicRenderEnabled)<a class="eb-admin-button" target="_blank" rel="noopener" href="{{ $publicUrl }}">View site</a>@endif
                        <button class="eb-admin-button eb-admin-button--primary" type="button" onclick="document.getElementById('website-setup').showModal()">{{ $site ? 'Continue setup' : 'Set up website' }}</button>
                    </div>
                @endif
            </header>
            @if(! $isEditorEnabled)
                <section class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">Website access is still being approved for this workspace. Nothing public, billable, or connected has been created.</section>
            @else
                @php
                    $draftTheme = $site->draftSiteVersion;
                    $previewPage = $pages->firstWhere('slug', '/') ?: $pages->first();
                @endphp
                @php $siteDomains = $site->domains->where('status', '!=', 'disabled')->sortByDesc('is_primary'); @endphp

                <section class="eb-site-theme-card" aria-labelledby="active-website-heading">
                    <div class="eb-site-preview-stage">
                        @if($draftTheme?->thumbnail_path)
                            <div class="eb-site-browser-frame">
                                <div class="eb-site-browser-bar"><span></span><span></span><span></span><strong>{{ parse_url($platformUrl, PHP_URL_HOST) }}</strong></div>
                                <img src="{{ route('managed-website.thumbnails.show', ['siteVersion' => $draftTheme]) }}" alt="Current website draft screenshot">
                            </div>
                        @elseif($previewPage)
                            <div class="eb-site-browser-frame">
                                <div class="eb-site-browser-bar"><span></span><span></span><span></span><strong>{{ parse_url($platformUrl, PHP_URL_HOST) }}</strong></div>
                                <iframe title="Website draft preview" src="{{ route('managed-website.editor.preview', ['page' => $previewPage, 'overview' => 1]) }}"></iframe>
                            </div>
                        @else
                            <p class="text-sm text-zinc-500">Your real draft preview will appear after you add a page.</p>
                        @endif
                    </div>
                    <div class="eb-site-theme-footer">
                        <div><div class="eb-site-theme-name"><h2 id="active-website-heading">{{ data_get($draftTheme?->settings, 'theme_name', data_get($site->settings, 'theme_name', 'Website')) }}</h2><x-ui.status-badge :tone="$site->status === 'published' && $site->public_enabled ? 'success' : 'warning'">{{ $site->status === 'published' && $site->public_enabled ? 'Live' : 'Draft' }}</x-ui.status-badge></div><p>{{ parse_url($platformUrl, PHP_URL_HOST) }} · {{ $pages->count() }} {{ str('page')->plural($pages->count()) }}</p></div>
                        <div class="eb-admin-page-actions">@if($isEditorEnabled && $isPublishingEnabled)<form method="POST" action="{{ route('managed-website.publish') }}">@csrf<button class="eb-admin-button" type="submit">Publish</button></form>@endif @if($pages->first())<a class="eb-admin-button eb-admin-button--primary" href="{{ route('managed-website.editor', ['page' => $pages->first()]) }}">Edit website</a>@endif</div>
                    </div>
                </section>

                <section class="eb-admin-panel" aria-labelledby="website-pages-heading">
                    <div class="eb-admin-panel-heading"><h2 id="website-pages-heading">Pages</h2><span>{{ $pages->count() }}</span></div>
                    <div class="eb-admin-rows">@foreach($pages as $websitePage)<div class="eb-admin-row"><a class="eb-admin-row-main" href="{{ route('managed-website.editor', ['page' => $websitePage]) }}"><span class="eb-admin-row-icon"><flux:icon.document-text class="size-4" /></span><span><strong>{{ $websitePage->title }}</strong><small>{{ $websitePage->slug }}</small></span></a><div class="eb-admin-row-actions"><a href="{{ route('managed-website.editor', ['page' => $websitePage]) }}">Edit</a>@if($websitePage->slug !== '/')<form method="POST" action="{{ route('managed-website.pages.destroy', ['page' => $websitePage]) }}" onsubmit="return confirm('Remove this page?')">@csrf @method('DELETE')<button type="submit">Delete</button></form>@endif</div></div>@endforeach</div>
                    @if($isEditorEnabled)<details class="eb-admin-inline-create"><summary>Add page</summary><form method="POST" action="{{ route('managed-website.pages.store') }}">@csrf<input required name="title" placeholder="Page title"><input required name="slug" placeholder="page-address"><select name="page_type"><option value="landing">Landing page</option><option value="services">Services</option><option value="about">About</option><option value="contact">Contact</option><option value="faq">FAQ</option></select><button class="eb-admin-button eb-admin-button--primary" type="submit">Add page</button></form></details>@endif
                </section>

                <details class="eb-admin-panel eb-admin-disclosure" id="website-domains" @if($errors->has('domain')) open @endif>
                    <summary><span><span class="eb-admin-row-icon"><flux:icon.globe-alt class="size-4" /></span><span><strong>Domains</strong><small>{{ $siteDomains->first()?->hostname ?: parse_url($platformUrl, PHP_URL_HOST) }}</small></span></span><span><x-ui.status-badge :tone="$siteDomains->contains('status', 'active') || ($site->status === 'published' && $site->public_enabled) ? 'success' : 'neutral'">{{ $siteDomains->contains('status', 'active') ? 'Connected' : 'Included' }}</x-ui.status-badge><flux:icon.chevron-right class="eb-admin-disclosure-chevron size-4" /></span></summary>
                    <div class="eb-domain-settings">
                        <div class="eb-domain-included"><div><strong>{{ parse_url($platformUrl, PHP_URL_HOST) }}</strong><small>Included Everbranch address</small></div>@if($site->status === 'published' && $site->public_enabled)<a class="eb-admin-button" href="{{ $platformUrl }}" target="_blank" rel="noopener">Open</a>@endif</div>
                        @if(! $domainsEnabled)
                            <p class="eb-admin-notice">Custom domains are available after public-host access is approved.</p>
                        @elseif($siteDomains->isEmpty())
                            <form method="POST" action="{{ route('managed-website.domains.request') }}" class="eb-domain-add">@csrf<label for="website-domain">Connect a domain you own</label><div><input id="website-domain" name="domain" required autocomplete="url" placeholder="yourbusiness.com"><button class="eb-admin-button eb-admin-button--primary" type="submit">Connect</button></div>@error('domain')<p>{{ $message }}</p>@enderror</form>
                        @else
                            @foreach($siteDomains as $domain)
                                <article class="eb-domain-record"><div class="eb-domain-record-head"><div><strong>{{ $domain->hostname }}</strong><small>{{ $domain->is_primary && $domain->status === 'active' ? 'Primary address' : ucfirst($domain->status) }}</small></div><x-ui.status-badge :tone="$domain->status === 'active' ? 'success' : ($domain->status === 'verified' ? 'info' : 'warning')">{{ $domain->status === 'active' ? 'Live' : ucfirst($domain->status) }}</x-ui.status-badge></div>
                                    @if($domain->status === 'pending')
                                        <div class="eb-domain-steps"><strong>Add these DNS records</strong><p>Keep unrelated records unchanged, then check the connection.</p><div class="overflow-x-auto"><table><thead><tr><th>Purpose</th><th>Type</th><th>Name</th><th>Value</th></tr></thead><tbody>@foreach($domainConnectionRecords->get($domain->id, []) as $record)<tr><td>{{ $record['label'] }}</td><td>{{ $record['type'] }}</td><td><code>{{ $record['name'] }}</code></td><td><code>{{ $record['value'] }}</code></td></tr>@endforeach</tbody></table></div><div class="eb-domain-actions"><form method="POST" action="{{ route('managed-website.domains.verify', ['domain' => $domain]) }}">@csrf<button class="eb-admin-button eb-admin-button--primary" type="submit">Check connection</button></form><form method="POST" action="{{ route('managed-website.domains.cancel', ['domain' => $domain]) }}" onsubmit="return confirm('Remove this attempted domain setup?')">@csrf<button class="eb-admin-button" type="submit">Remove</button></form></div>@if($domain->last_error)<p class="text-rose-700">{{ $domain->last_error }}</p>@endif</div>
                                    @elseif($domain->status === 'verified')
                                        <div class="eb-domain-actions"><form method="POST" action="{{ route('managed-website.domains.activate', ['domain' => $domain]) }}">@csrf<button class="eb-admin-button eb-admin-button--primary" type="submit">Activate domain</button></form><form method="POST" action="{{ route('managed-website.domains.cancel', ['domain' => $domain]) }}" onsubmit="return confirm('Remove this attempted domain setup?')">@csrf<button class="eb-admin-button" type="submit">Remove</button></form></div>
                                    @elseif($domain->status === 'active')
                                        <form method="POST" action="{{ route('managed-website.domains.deactivate', ['domain' => $domain]) }}" onsubmit="return confirm('Disable this custom domain? Your published site will remain available on its Everbranch address.')">@csrf<button class="eb-admin-danger" type="submit">Disable domain</button></form>
                                    @endif
                                </article>
                            @endforeach
                        @endif
                        <details class="eb-domain-safety"><summary>Connection and rollback details</summary><p>Everbranch verifies ownership before activation and never asks for your registrar password. Disabling a domain leaves the site, pages, leads, versions, and connected business data intact.</p>@if($domainTarget)<p>Connection target: <code>{{ $domainTarget }}</code></p>@endif</details>
                    </div>
                </details>

                <section aria-labelledby="theme-library-heading"><div class="eb-section-heading"><div><h2 id="theme-library-heading">Theme library</h2><p>Apply a design to the draft, review it, then publish when ready.</p></div></div><div class="eb-theme-list">@foreach($themes as $theme)<article class="eb-theme-row"><img src="{{ $theme['thumbnail'] }}" alt="{{ $theme['name'] }} starter theme preview"><div><strong>{{ $theme['name'] }}</strong><small>{{ $theme['description'] }}</small></div><form method="POST" action="{{ route('managed-website.themes.apply') }}">@csrf<input type="hidden" name="theme_key" value="{{ $theme['key'] }}"><button class="eb-admin-button" type="submit">Add to draft</button></form></article>@endforeach</div></section>
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
