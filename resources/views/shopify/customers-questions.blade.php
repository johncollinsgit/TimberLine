<x-shopify.customers-layout
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :customer-subnav="$pageSubnav"
    :page-actions="$pageActions"
    :merchant-journey="$merchantJourney ?? []"
>
    @php
        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];

        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
    @endphp

    <section class="customers-surface">
        <h2>Customer Questions</h2>
        <p>Keep support answers consistent so your team can respond quickly and confidently across customer channels.</p>
        <div class="start-here-meta">
            <span class="start-here-pill">Import · {{ $importSummary['label'] ?? 'Not started' }}</span>
            <span class="start-here-pill">Goal · Faster support replies</span>
        </div>
    </section>

    <section class="plans-addon-grid" aria-label="Customer questions guidance cards">
        <article class="plans-card">
            <h3>Most common customer questions</h3>
            <p>Use this section for recurring questions about enrollment, rewards balance, redemptions, and eligibility.</p>
            <a class="plans-link" href="{{ $embeddedUrl(route('shopify.app.customers.manage', [], false)) }}">Open customer profiles</a>
        </article>

        <article class="plans-card">
            <h3>Response playbooks</h3>
            <p>Keep templated answer paths for common situations so support outcomes stay consistent.</p>
            <a class="plans-link" href="{{ $embeddedUrl(route('shopify.app.start', [], false)) }}">Review setup checklist</a>
        </article>

        <article class="plans-card">
            <h3>Escalation path</h3>
            <p>Define when customer issues should move from standard support to a deeper profile review.</p>
            <a class="plans-link" href="{{ $embeddedUrl(route('shopify.app.customers.activity', [], false)) }}">Open activity workspace</a>
        </article>
    </section>
</x-shopify.customers-layout>
