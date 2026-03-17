<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-actions="$pageActions"
>
    <style>
        .customers-panel {
            border-radius: 24px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.92);
            padding: 28px 36px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.1);
            max-width: 780px;
        }

        .customers-panel h2 {
            margin: 0 0 12px;
            font-family: "Fraunces", ui-serif, Georgia, serif;
            font-size: 2rem;
            color: #0f172a;
        }

        .customers-panel p {
            margin: 0 0 16px;
            line-height: 1.65;
            color: rgba(15, 23, 42, 0.74);
            font-size: 15px;
        }

        .customers-panel ul {
            margin: 12px 0 18px;
            padding-left: 20px;
            color: rgba(15, 23, 42, 0.6);
            line-height: 1.6;
        }

        .customers-panel ul li {
            margin-bottom: 8px;
            font-size: 14px;
        }

        .customers-panel a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            border: 1px solid rgba(15, 143, 97, 0.2);
            background: rgba(15, 143, 97, 0.1);
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            color: #0e6b4c;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .customers-panel a:hover {
            background: rgba(15, 143, 97, 0.15);
            border-color: rgba(15, 143, 97, 0.4);
        }
    </style>

    <section class="customers-panel">
        <h2>Customer intelligence is arriving</h2>
        <p>
            The new sidebar lets us surface Candle Cash membership details, lifetime balances, and referral/birthday status
            without depending on Shopify’s native menu. Clicking “Customers” in the left rail reveals the upcoming ledger view.
        </p>
        <ul>
            <li>View each shopper’s Candle Cash balance, lifetime earned, and spent points.</li>
            <li>Review recent reward activity, referrals, and birthday program participation.</li>
            <li>Open Backstage when you need the full ledger or manual adjustments that already exist.</li>
        </ul>
        <p>
            This control surface is built on the same Laravel-backed models and services you already trust, so
            future actions will stay consistent with the live storefront behavior.
        </p>
        <a href="{{ route('marketing.customers') }}" rel="noopener">Open Backstage customers</a>
    </section>
</x-shopify-embedded-shell>
