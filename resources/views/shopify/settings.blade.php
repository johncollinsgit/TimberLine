<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-actions="$pageActions"
>
    <style>
        .settings-panel {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            padding: 26px 28px;
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.04);
            max-width: 760px;
        }

        .settings-panel h2 {
            margin: 0 0 10px;
            font-size: 1.6rem;
            font-weight: 650;
            color: #111827;
        }

        .settings-panel p {
            margin: 0 0 14px;
            color: rgba(15, 23, 42, 0.72);
            line-height: 1.7;
            font-size: 15px;
        }
    </style>

    <section class="settings-panel">
        <h2>Program settings are coming into view</h2>
        <p>
            The left rail now controls the full admin surface, so we can start to surface real Candle Cash
            messaging, point rate, and active/inactive toggles right here inside Shopify.
        </p>
        <p>
            Nothing has changed on the storefront—the Laravel data and loyalty engine stay as the source of truth—but
            this tab will mirror the same settings once the embedded controls are wired.
        </p>
    </section>
</x-shopify-embedded-shell>
