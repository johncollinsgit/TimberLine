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
        $contextTokenValue = trim((string) ($contextToken ?? ''));
        $selectedStoreId = (int) ($setting?->shopify_store_id ?? 0);
    @endphp

    <section class="plans-shell">
        <article class="plans-card">
            <h2 class="plans-title">Dedicated wholesale store</h2>
            <p class="plans-copy">Only the confirmed store will reveal wholesale customers, orders, applications, prospects, and suggestions. Retail stores on the same tenant stay unchanged.</p>

            @if($stores === [])
                <p class="plans-copy">No connected Shopify store is available. Connect Shopify through the established integration setup, then return here.</p>
            @else
                <form method="POST" action="{{ route('shopify.app.store.wholesale.configure', [], false) }}" style="display:grid;gap:16px;max-width:620px">
                    @csrf
                    @if($contextTokenValue !== '')
                        <input type="hidden" name="context_token" value="{{ $contextTokenValue }}">
                    @endif
                    <label>
                        <span class="plans-copy">Wholesale Shopify store</span>
                        <select name="shopify_store_id" required style="display:block;width:100%;margin-top:6px;padding:10px">
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}" @selected($selectedStoreId === (int) $store->id)>{{ $store->shop_domain }} · {{ ucfirst($store->store_role ?? 'retail') }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="plans-copy">
                        <input type="checkbox" name="confirm_wholesale_only" value="1" required>
                        I confirm this connected, tenant-owned store is used only for wholesale orders.
                    </label>
                    <button type="submit" class="module-store-button module-store-button--primary">Confirm and activate</button>
                </form>
            @endif
        </article>
    </section>
</x-shopify-embedded-shell>
