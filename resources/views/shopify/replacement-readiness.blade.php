<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions ?? []"
>
    @php
        $payload = is_array($readinessPayload ?? null) ? $readinessPayload : [];
        $modules = (array) ($payload['modules'] ?? []);
        $embeddedUrl = static fn (string $url): string => app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class)->append($url, request());
    @endphp

    <style>
        .rr-shell{display:grid;gap:16px}.rr-summary,.rr-card{border:1px solid rgba(15,23,42,.12);border-radius:16px;background:#fff;padding:16px;box-shadow:0 12px 28px rgba(15,23,42,.05)}
        .rr-summary{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}.rr-summary h2,.rr-card h3{margin:0;color:#17211c}.rr-summary p,.rr-card p{margin:0;color:#536159;font-size:13px;line-height:1.55}.rr-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .rr-card{display:grid;gap:12px}.rr-head{display:flex;gap:10px;align-items:flex-start;justify-content:space-between}.rr-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;background:#eef2ef;color:#405149}.rr-pill--ready{background:#daf3e7;color:#146143}.rr-pill--blocked{background:#fee9e7;color:#991b1b}.rr-meter{height:8px;border-radius:999px;background:#e6ebe8;overflow:hidden}.rr-meter span{display:block;height:100%;background:#267457}.rr-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.rr-meta div{padding:9px;border-radius:10px;background:#f6f8f7}.rr-meta strong,.rr-meta span{display:block}.rr-meta strong{font-size:14px;color:#17211c}.rr-meta span{font-size:10px;color:#66736b;text-transform:uppercase;letter-spacing:.06em;margin-top:2px}
        .rr-checks{display:grid;gap:6px}.rr-check{display:flex;gap:8px;align-items:center;font-size:12px;color:#48564e}.rr-check i{width:9px;height:9px;border-radius:50%;background:#c7d0ca}.rr-check[data-state="passed"] i,.rr-check[data-state="verified"] i,.rr-check[data-state="not_applicable"] i{background:#1f8a5b}.rr-check[data-state="failed"] i,.rr-check[data-state="blocked"] i{background:#c83b32}.rr-actions{display:flex;gap:8px;flex-wrap:wrap}.rr-button{border:1px solid #aebbb4;border-radius:999px;background:#fff;color:#244237;padding:9px 13px;font-size:12px;font-weight:750;cursor:pointer}.rr-button--primary{background:#1d664c;border-color:#1d664c;color:#fff}.rr-button:disabled{cursor:not-allowed;opacity:.5}.rr-result{min-height:18px;font-size:12px;color:#536159}.rr-blockers{margin:0;padding-left:18px;color:#7c2d2d;font-size:12px;line-height:1.5}.rr-danger{border-color:#f1b9b4;background:#fff8f7}.rr-note{font-size:12px;color:#7a4b10;background:#fff8e8;border:1px solid #f1ddb3;padding:10px;border-radius:10px}@media(max-width:900px){.rr-grid{grid-template-columns:1fr}.rr-meta{grid-template-columns:1fr 1fr}}
    </style>

    <section class="rr-shell" data-readiness-root data-refresh-base="{{ $readinessEndpoints['refresh_base'] ?? '' }}" data-activate-base="{{ $readinessEndpoints['activate_base'] ?? '' }}">
        @if(!$authorized)
            <div class="rr-summary rr-danger"><div><h2>Open from Shopify Admin</h2><p>A verified Shopify store context is required.</p></div></div>
        @else
            <div class="rr-summary">
                <div><h2>Store-scoped cutover queue</h2><p>Each replacement advances independently. Refreshing checks does not publish content, change rates, bill customers, or disable a vendor.</p></div>
                <div><strong>${{ number_format((float) ($payload['projected_savings_per_year'] ?? 0), 2) }}/year</strong><p>${{ number_format((float) ($payload['confirmed_savings_per_year'] ?? 0), 2) }} confirmed + ${{ number_format((float) ($payload['incremental_savings_per_year'] ?? 0), 2) }} pending</p></div>
            </div>
            @if(!($payload['activation_enabled'] ?? false))
                <p class="rr-note">Production activation release gate is off. Imports, previews, and evidence collection remain available.</p>
            @endif
            <div class="rr-grid">
                @foreach($modules as $module)
                    @php
                        $ready = in_array($module['status'] ?? '', ['armed','armed_for_pilot','live_verified','active'], true);
                        $blocked = ($module['failed_checks'] ?? 0) > 0 || ($module['status'] ?? '') === 'blocked';
                    @endphp
                    <article class="rr-card {{ $blocked ? 'rr-danger' : '' }}" data-module-card="{{ $module['id'] }}">
                        <div class="rr-head"><div><h3>{{ $module['label'] }}</h3><p>{{ $module['source_app'] }} → {{ $module['replacement_name'] }}</p></div><span class="rr-pill {{ $ready ? 'rr-pill--ready' : ($blocked ? 'rr-pill--blocked' : '') }}" data-status>{{ str_replace('_',' ', $module['status']) }}</span></div>
                        <div class="rr-meter" aria-label="{{ $module['progress_percent'] }} percent ready"><span style="width:{{ $module['progress_percent'] }}%"></span></div>
                        <div class="rr-meta">
                            <div><strong>{{ $module['passing_checks'] }}/{{ $module['required_checks'] }}</strong><span>checks green</span></div>
                            <div><strong>{{ $module['latest_import']['imported_count'] ?? 0 }}/{{ $module['latest_import']['source_count'] ?? ($module['expected_records'] ?? '—') }}</strong><span>imported</span></div>
                            <div><strong>${{ number_format((float) ($module['savings_per_year'] ?? 0), 2) }}</strong><span>annual savings</span></div>
                        </div>
                        <details><summary>Readiness evidence</summary><div class="rr-checks">
                            @foreach($module['checks'] as $check)<div class="rr-check" data-state="{{ $check['status'] }}"><i></i><span>{{ $check['label'] }}: {{ str_replace('_',' ', $check['status']) }}@if($check['message']) — {{ $check['message'] }}@endif</span></div>@endforeach
                        </div></details>
                        @if(($module['activation_blockers'] ?? []) !== [])<details><summary>Activation blockers ({{ count($module['activation_blockers']) }})</summary><ul class="rr-blockers">@foreach($module['activation_blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></details>@endif
                        @if(($module['module_key'] ?? '') === 'recharge')<p class="rr-note">Recharge remains protected. This control cannot start billing or a pilot from the readiness page.</p>@endif
                        <div class="rr-actions">
                            <a class="rr-button" href="{{ $embeddedUrl(route('shopify.app.replacements.show', ['module' => $module['id']], false)) }}">Open admin preview</a>
                            <button class="rr-button" type="button" data-action="refresh" data-module="{{ $module['id'] }}" {{ !$module['store_matches_context'] ? 'disabled' : '' }}>Re-run readiness gates</button>
                            <button class="rr-button rr-button--primary" type="button" data-action="activate" data-module="{{ $module['id'] }}" {{ !($module['can_activate'] ?? false) || ($module['module_key'] ?? '') === 'recharge' ? 'disabled' : '' }}>Activate {{ $module['label'] }}</button>
                        </div>
                        <p class="rr-result" aria-live="polite" data-result></p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <script>
        (() => {
            const root = document.querySelector('[data-readiness-root]'); if (!root) return;
            async function authHeaders(){ const resolver=window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders; if(typeof resolver!=='function') throw new Error('Shopify authorization is not ready.'); return {...await resolver(), 'Content-Type':'application/json','Accept':'application/json'}; }
            root.addEventListener('click', async (event) => {
                const button=event.target.closest('button[data-action]'); if(!button || button.disabled) return;
                const card=button.closest('[data-module-card]'); const result=card.querySelector('[data-result]'); const action=button.dataset.action; const module=button.dataset.module;
                button.disabled=true; const original=button.textContent; button.textContent=action==='activate'?'Activating and checking…':'Rechecking…'; result.textContent='';
                try { const base=action==='activate'?root.dataset.activateBase:root.dataset.refreshBase; const response=await fetch(`${base}/${module}/${action}`,{method:'POST',credentials:'same-origin',headers:await authHeaders(),body:JSON.stringify(action==='activate'?{idempotency_key:(crypto.randomUUID?.()||`${Date.now()}-${module}`)}:{})}); const payload=await response.json(); if(!response.ok||!payload.ok) throw new Error(payload.message||'Action failed.'); result.textContent=payload.message; if(action==='refresh') window.setTimeout(()=>window.location.reload(),600); }
                catch(error){ result.textContent=error?.message||'Action failed.'; button.disabled=false; }
                finally{ button.textContent=original; }
            });
        })();
    </script>
</x-shopify-embedded-shell>
