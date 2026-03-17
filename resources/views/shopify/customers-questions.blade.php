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
>
    <style>
        .customers-questions-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }

        .customers-question-card {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            padding: 18px 18px 16px;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
        }

        .customers-question-card h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 640;
            color: rgba(15, 23, 42, 0.9);
        }

        .customers-question-card p {
            margin: 8px 0 0;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.64);
        }
    </style>

    <section class="customers-surface">
        <h2>Questions</h2>
        <p>
            Keep customer-facing rewards support organized in one place. This section is wired now so live FAQs,
            issue templates, and known resolutions can be added without changing the navigation architecture again.
        </p>
    </section>

    <section class="customers-questions-grid" aria-label="Customer questions sections">
        <article class="customers-question-card">
            <h3>Common reward questions</h3>
            <p>
                This panel will list the top operational questions customers ask about Candle Cash earning, redeeming,
                and verification.
            </p>
        </article>
        <article class="customers-question-card">
            <h3>Support workflows</h3>
            <p>
                Escalation paths and internal response playbooks will be surfaced here once the underlying data
                contracts are finalized.
            </p>
        </article>
    </section>
</x-shopify.customers-layout>
