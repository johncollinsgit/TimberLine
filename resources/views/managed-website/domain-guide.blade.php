<details class="eb-admin-panel eb-admin-disclosure rounded-[20px]" id="website-domains" @if($errors->has('domain') || session('domain_wizard_id')) open @endif>
    <summary><span><span class="eb-admin-row-icon"><flux:icon.globe-alt class="size-4" /></span><span><strong>Domains</strong><small>{{ $siteDomains->first()?->hostname ?: parse_url($platformUrl, PHP_URL_HOST) }}</small></span></span><span><x-ui.status-badge :tone="$siteDomains->contains('status', 'active') ? 'success' : 'neutral'">{{ $siteDomains->contains('status', 'active') ? 'Connected' : 'Included' }}</x-ui.status-badge><flux:icon.chevron-right class="eb-admin-disclosure-chevron size-4" /></span></summary>
    <div class="eb-domain-settings">
        <div class="eb-domain-included"><div><strong>{{ parse_url($platformUrl, PHP_URL_HOST) }}</strong><small>Your included address. Publish whenever you are ready.</small></div>@if($site->status === 'published' && $site->public_enabled)<a class="eb-admin-button" href="{{ $platformUrl }}" target="_blank" rel="noopener">Open site</a>@endif</div>
        @error('domain')<p class="text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
        @if(! $domainsEnabled)
            <p class="eb-admin-notice">Custom domain access needs approval. Your included address is ready for publishing.</p>
        @else
            @if($siteDomains->isEmpty())
                <form method="POST" action="{{ route('managed-website.domains.request', ['tenant' => $tenant->slug]) }}" class="eb-domain-add">@csrf
                    <label for="website-domain">Your domain</label>
                    <div><input id="website-domain" name="domain" value="{{ old('domain') }}" required autocomplete="url" placeholder="www.yourbusiness.com"><button class="eb-admin-button eb-admin-button--primary" type="submit">Connect my domain</button></div>
                    <p class="text-sm text-zinc-600">Start with www for the simplest connection. Keep your domain with its current provider.</p>
                    <button class="eb-admin-button" name="assistance" value="1" type="submit">Handle this for me</button>
                </form>
            @endif
            @foreach($siteDomains as $domain)
                <article class="eb-domain-record" @if(($automaticDomains ?? false) && $domain->status !== 'active' && Route::has('managed-website.domains.status')) data-domain-status-url="{{ route('managed-website.domains.status', ['domain' => $domain, 'tenant' => $tenant->slug]) }}" @endif>
                    <div class="eb-domain-record-head"><div><strong>{{ $domain->hostname }}</strong><small>{{ $domain->status === 'active' ? 'Primary address' : ($domain->status === 'verified' ? 'Ownership confirmed' : 'Waiting for DNS') }}</small></div><x-ui.status-badge :tone="$domain->status === 'active' ? 'success' : 'warning'">{{ $domain->status === 'active' ? 'Live' : 'Connecting' }}</x-ui.status-badge></div>
                    @if($domain->status !== 'active')
                        @if($automaticDomains ?? false)<p class="my-3 text-sm" data-domain-progress role="status">Add the records once. We will check automatically and connect your published site when DNS and security are ready.</p>@endif
                        <p hidden data-domain-recovery class="my-3"><a class="eb-admin-button" href="{{ route('managed-website.index', ['tenant' => $tenant->slug]) }}"><x-everbranch.action-leaf /><span data-domain-recovery-label>Review domains</span></a></p>
                        <ol class="my-4 flex flex-wrap gap-3 text-sm" aria-label="Connection steps"><li>1. Add records</li><li>2. Check connection</li><li>3. Activate</li></ol>
                        <details class="eb-domain-steps" @if($domain->status === 'pending') open @endif><summary class="cursor-pointer font-semibold">Add DNS records</summary>
                            <p>Open DNS settings at your provider. Use DNS-only routing; leave email records unchanged.</p>
                            <div class="overflow-x-auto"><table><thead><tr><th>Type</th><th>Name</th><th>Value</th></tr></thead><tbody>
                            @foreach($domainConnectionRecords->get($domain->id, []) as $record)
                                <tr><td>{{ $record['type'] }}</td><td><code>{{ $record['name'] }}</code><button type="button" class="ml-2 underline" data-domain-copy="{{ $record['name'] }}" aria-label="Copy {{ $record['type'] }} name">Copy</button></td><td><code>{{ $record['value'] }}</code><button type="button" class="ml-2 underline" data-domain-copy="{{ $record['value'] }}" aria-label="Copy {{ $record['type'] }} value">Copy</button></td></tr>
                            @endforeach
                            </tbody></table></div>
                            <p class="text-sm">Using Cloudflare? Choose DNS only for the website CNAME. For a root domain or another proxy, ask us to handle setup.</p>
                        </details>
                        @if($domain->last_error)<p class="my-3 text-sm text-amber-900" role="status">{{ $domain->last_error }}</p>@endif
                        <div class="eb-domain-actions">
                            <form method="POST" action="{{ route('managed-website.domains.verify', ['domain' => $domain, 'tenant' => $tenant->slug]) }}">@csrf<button class="eb-admin-button" type="submit">Check connection</button></form>
                            @if($domain->status === 'verified')<form method="POST" action="{{ route('managed-website.domains.activate', ['domain' => $domain, 'tenant' => $tenant->slug]) }}">@csrf<button class="eb-admin-button eb-admin-button--primary" type="submit">Activate domain</button></form>@endif
                            <form method="POST" action="{{ route('managed-website.domains.request', ['tenant' => $tenant->slug]) }}">@csrf<input type="hidden" name="domain" value="{{ $domain->hostname }}"><button class="eb-admin-button" name="assistance" value="1" type="submit">Handle this for me</button></form>
                            <form method="POST" action="{{ route('managed-website.domains.cancel', ['domain' => $domain, 'tenant' => $tenant->slug]) }}" onsubmit="return confirm('Remove this domain setup?')">@csrf<button class="eb-admin-button" type="submit">Remove</button></form>
                        </div>
                    @else
                        <form method="POST" action="{{ route('managed-website.domains.deactivate', ['domain' => $domain, 'tenant' => $tenant->slug]) }}" onsubmit="return confirm('Disable this custom domain? Your Everbranch address will still work.')">@csrf<button class="eb-admin-danger" type="submit">Disable domain</button></form>
                    @endif
                </article>
            @endforeach
        @endif
        <p data-domain-copy-status class="text-sm" role="status" aria-live="polite"></p>
        <details class="eb-domain-safety"><summary>What changes?</summary><p>We verify ownership, website routing, and the security certificate before activation. We never need your registrar password. Your published versions and Everbranch address stay available.</p></details>
    </div>
</details>
<script>
document.querySelectorAll('[data-domain-copy]').forEach(button => button.addEventListener('click', async () => {
    const status = document.querySelector('[data-domain-copy-status]');
    try { await navigator.clipboard.writeText(button.dataset.domainCopy); status.textContent = 'Copied.'; }
    catch { status.textContent = 'Copy unavailable. Select the record text and copy it.'; }
}));
document.querySelectorAll('[data-domain-status-url]').forEach(record => {
    let stopped = false;
    const stop = (message, label = 'Review domains') => {
        stopped = true;
        const progress = record.querySelector('[data-domain-progress]');
        if (progress) progress.textContent = message;
        const recovery = record.querySelector('[data-domain-recovery]');
        if (recovery) {
            recovery.hidden = false;
            recovery.querySelector('[data-domain-recovery-label]').textContent = label;
        }
    };
    const poll = async () => {
        if (stopped || !record.isConnected) return;
        if (!document.hidden) {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 10000);
            try {
                const response = await fetch(record.dataset.domainStatusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
                if ([401,419].includes(response.status)) { stop('Sign in to check this domain. Your saved setup is unchanged.', 'Sign in'); return; }
                if (response.status === 403) { stop('Your access changed. Review Website to check this domain.'); return; }
                if (response.status === 404) { stop('This domain connection is no longer available. Review your domains.'); return; }
                if (response.status === 423) { stop('Domain setup is paused. Your saved connection is unchanged.'); return; }
                if (response.ok) {
                    const state = await response.json();
                    if (state?.status === 'active') { stopped = true; location.reload(); return; }
                    if (state?.status === 'disabled') { stop('This domain connection is disabled. Review your domains to reconnect.'); return; }
                    const progress = record.querySelector('[data-domain-progress]');
                    if (progress && typeof state?.message === 'string') progress.textContent = state.message;
                }
            } catch { /* A temporary outage leaves the same connection pending. */ }
            finally { clearTimeout(timeout); controller.abort(); }
        }
        setTimeout(poll, 15000);
    };
    setTimeout(poll, 15000);
});
</script>
