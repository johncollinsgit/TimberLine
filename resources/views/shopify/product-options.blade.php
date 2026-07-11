<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav"
    :page-actions="$pageActions"
>
    @php
        $access = is_array($productOptionsAccess ?? null) ? $productOptionsAccess : [];
        $payload = is_array($productOptionsPayload ?? null) ? $productOptionsPayload : [];
        $rulesets = is_array($payload['rulesets'] ?? null) ? $payload['rulesets'] : [];
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    @endphp

    <style>
        .po-shell { display: grid; gap: 16px; }
        .po-card { border: 1px solid rgba(15, 23, 42, .12); border-radius: 16px; background: #fff; padding: 18px; box-shadow: 0 10px 28px rgba(15, 23, 42, .05); }
        .po-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .po-title { margin: 0; color: #111827; font-size: 17px; }
        .po-copy { margin: 6px 0 0; color: #64748b; font-size: 13px; line-height: 1.5; }
        .po-badges { display: flex; flex-wrap: wrap; gap: 8px; }
        .po-badge { display: inline-flex; align-items: center; min-height: 26px; border-radius: 999px; padding: 0 10px; background: #ecfdf5; color: #047857; font-size: 11px; font-weight: 750; text-transform: uppercase; letter-spacing: .04em; }
        .po-badge--shopify { background: #eff6ff; color: #1d4ed8; }
        .po-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .po-stat { border-radius: 14px; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .po-stat strong { display: block; color: #0f172a; font-size: 22px; }
        .po-stat span { color: #64748b; font-size: 12px; }
        .po-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) 130px; gap: 14px; margin-top: 16px; }
        .po-field { display: grid; gap: 6px; }
        .po-field--full { grid-column: 1 / -1; }
        .po-label { color: #334155; font-size: 12px; font-weight: 700; }
        .po-input, .po-textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; color: #0f172a; padding: 10px 11px; font: inherit; font-size: 13px; }
        .po-textarea { min-height: 124px; resize: vertical; line-height: 1.45; }
        .po-help { color: #64748b; font-size: 11px; line-height: 1.4; }
        .po-checks { display: flex; flex-wrap: wrap; gap: 18px; grid-column: 1 / -1; }
        .po-check { display: inline-flex; align-items: center; gap: 7px; color: #334155; font-size: 12px; font-weight: 650; }
        .po-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; grid-column: 1 / -1; }
        .po-button { appearance: none; border: 1px solid #0f766e; border-radius: 10px; background: #0f766e; color: #fff; min-height: 36px; padding: 0 14px; font-size: 12px; font-weight: 750; cursor: pointer; }
        .po-button:disabled { cursor: wait; opacity: .58; }
        .po-status { color: #64748b; font-size: 12px; }
        .po-status[data-tone="error"] { color: #b91c1c; }
        .po-status[data-tone="success"] { color: #047857; }
        .po-empty { padding: 28px; text-align: center; color: #64748b; }
        @media (max-width: 760px) { .po-summary { grid-template-columns: repeat(2, 1fr); } .po-form-grid { grid-template-columns: 1fr; } .po-field--full, .po-checks, .po-actions { grid-column: 1; } }
    </style>

    <div class="po-shell" data-product-options-root
         data-create-endpoint="{{ $productOptionsEndpoints['create'] ?? '' }}"
         data-update-base="{{ $productOptionsEndpoints['update_base'] ?? '' }}">
        <section class="po-card">
            <div class="po-header">
                <div>
                    <h2 class="po-title">Everbranch Product Options</h2>
                    <p class="po-copy">Replaces Infinite Options for scent-based Shopify bundles. Each selection is attached to the cart item and carried into the Shopify order.</p>
                </div>
                <div class="po-badges">
                    <span class="po-badge po-badge--shopify">Shopify only</span>
                    <span class="po-badge">Active · Modern Forestry</span>
                </div>
            </div>
        </section>

        @if(!($access['enabled'] ?? false))
            <section class="po-card po-empty">{{ $access['message'] ?? 'Product Options is unavailable.' }}</section>
        @else
            <section class="po-summary" aria-label="Product Options summary">
                <div class="po-stat"><strong>{{ $summary['ruleset_count'] ?? 0 }}</strong><span>Rulesets</span></div>
                <div class="po-stat"><strong>{{ $summary['active_count'] ?? 0 }}</strong><span>Active</span></div>
                <div class="po-stat"><strong>{{ $summary['assigned_product_count'] ?? 0 }}</strong><span>Product assignments</span></div>
                <div class="po-stat"><strong>{{ $summary['needs_assignment_count'] ?? 0 }}</strong><span>Need a product handle</span></div>
            </section>

            @forelse($rulesets as $ruleset)
                @php
                    $handles = collect($ruleset['assignments'] ?? [])->pluck('product_handle')->filter()->implode("\n");
                    $values = collect($ruleset['allowed_values'] ?? [])->filter()->implode("\n");
                @endphp
                <section class="po-card" data-ruleset-card data-ruleset-id="{{ $ruleset['id'] }}">
                    <div class="po-header">
                        <div>
                            <h2 class="po-title">{{ $ruleset['name'] }}</h2>
                            <p class="po-copy">{{ $ruleset['option_count'] }} required scent {{ (int) $ruleset['option_count'] === 1 ? 'selection' : 'selections' }} · {{ count($ruleset['allowed_values'] ?? []) }} available scents</p>
                        </div>
                        <span class="po-badge">{{ ($ruleset['enabled'] ?? false) ? 'Active' : 'Paused' }}</span>
                    </div>
                    <form class="po-form-grid" data-ruleset-form>
                        <div class="po-field">
                            <label class="po-label">Ruleset name</label>
                            <input class="po-input" name="name" value="{{ $ruleset['name'] }}" maxlength="160" required>
                        </div>
                        <div class="po-field">
                            <label class="po-label">Number of scents</label>
                            <input class="po-input" name="option_count" type="number" min="1" max="24" value="{{ $ruleset['option_count'] }}" required>
                        </div>
                        <div class="po-field po-field--full">
                            <label class="po-label">Shopify product handles or product URLs</label>
                            <textarea class="po-textarea" name="product_handles" placeholder="three-room-sprays-for-30">{{ $handles }}</textarea>
                            <span class="po-help">One per line. You can paste a full Shopify product URL; Everbranch keeps the handle.</span>
                        </div>
                        <div class="po-field po-field--full">
                            <label class="po-label">Available scents</label>
                            <textarea class="po-textarea" name="allowed_values" required>{{ $values }}</textarea>
                            <span class="po-help">One scent per line. This list is shown in every scent selector for matching products.</span>
                        </div>
                        <div class="po-checks">
                            <label class="po-check"><input name="enabled" type="checkbox" @checked($ruleset['enabled'] ?? false)> Active on storefront</label>
                            <label class="po-check"><input name="require_distinct_values" type="checkbox" @checked($ruleset['require_distinct_values'] ?? false)> Require different scents</label>
                        </div>
                        <div class="po-actions">
                            <span class="po-status" data-form-status></span>
                            <button class="po-button" type="submit">Save ruleset</button>
                        </div>
                    </form>
                </section>
            @empty
                <section class="po-card po-empty">No product option rulesets exist yet.</section>
            @endforelse

            <section class="po-card">
                <div class="po-header">
                    <div>
                        <h2 class="po-title">Add a ruleset</h2>
                        <p class="po-copy">Create another Shopify bundle or product-specific scent list.</p>
                    </div>
                </div>
                <form class="po-form-grid" data-ruleset-form data-create-form>
                    <div class="po-field"><label class="po-label">Ruleset name</label><input class="po-input" name="name" maxlength="160" required></div>
                    <div class="po-field"><label class="po-label">Number of scents</label><input class="po-input" name="option_count" type="number" min="1" max="24" value="1" required></div>
                    <div class="po-field po-field--full"><label class="po-label">Shopify product handles or product URLs</label><textarea class="po-textarea" name="product_handles"></textarea></div>
                    <div class="po-field po-field--full"><label class="po-label">Available scents</label><textarea class="po-textarea" name="allowed_values" required></textarea></div>
                    <div class="po-checks">
                        <label class="po-check"><input name="enabled" type="checkbox" checked> Active on storefront</label>
                        <label class="po-check"><input name="require_distinct_values" type="checkbox"> Require different scents</label>
                    </div>
                    <div class="po-actions"><span class="po-status" data-form-status></span><button class="po-button" type="submit">Create ruleset</button></div>
                </form>
            </section>
        @endif
    </div>

    <script>
        (function () {
            const root = document.querySelector('[data-product-options-root]');
            if (!root) return;

            function lines(value) {
                return String(value || '').split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
            }

            function payload(form) {
                const data = new FormData(form);
                return {
                    name: String(data.get('name') || '').trim(),
                    option_count: Number(data.get('option_count') || 1),
                    product_handles: lines(data.get('product_handles')),
                    allowed_values: lines(data.get('allowed_values')),
                    enabled: form.elements.enabled.checked,
                    require_distinct_values: form.elements.require_distinct_values.checked,
                };
            }

            async function submit(form) {
                const card = form.closest('[data-ruleset-card]');
                const id = card ? card.dataset.rulesetId : null;
                const endpoint = id ? root.dataset.updateBase + '/' + id : root.dataset.createEndpoint;
                const method = id ? 'PATCH' : 'POST';
                const button = form.querySelector('button[type="submit"]');
                const status = form.querySelector('[data-form-status]');
                button.disabled = true;
                status.dataset.tone = '';
                status.textContent = 'Saving…';

                try {
                    const headers = await window.ForestryEmbeddedApp.resolveEmbeddedAuthHeaders();
                    const response = await fetch(endpoint, { method, headers, body: JSON.stringify(payload(form)) });
                    const json = await response.json();
                    if (!response.ok || !json.ok) {
                        const errors = json.errors ? Object.values(json.errors).flat().join(' ') : '';
                        throw new Error(errors || json.message || 'Ruleset could not be saved.');
                    }
                    status.dataset.tone = 'success';
                    status.textContent = json.message || 'Saved.';
                    window.setTimeout(() => window.location.reload(), 500);
                } catch (error) {
                    status.dataset.tone = 'error';
                    status.textContent = error && error.message ? error.message : 'Ruleset could not be saved.';
                } finally {
                    button.disabled = false;
                }
            }

            root.querySelectorAll('[data-ruleset-form]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    submit(form);
                });
            });
        })();
    </script>
</x-shopify-embedded-shell>
