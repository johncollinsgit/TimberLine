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
    :page-actions="$pageActions"
>
    @php
        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host) ? (string) $host : null
        );

        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);

        // NOTE: Do NOT append Shopify signed query params to the "full" link.
        // Adding extra params breaks the original HMAC signature. Instead, rely on
        // the server-stored page context established on the initial signed entry.
        $fullDashboardHref = route('shopify.app', ['full' => 1], false);
    @endphp

    <style>
        .sf-lite-shell {
            display: grid;
            gap: 16px;
        }

        .sf-lite-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.96);
            padding: 16px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.06);
        }

        .sf-lite-card h2 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .sf-lite-card p {
            margin: 6px 0 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.62);
            line-height: 1.45;
        }

        .sf-lite-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .sf-lite-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            font-size: 12px;
            font-weight: 650;
            padding: 0 12px;
            white-space: nowrap;
        }

        .sf-lite-button--primary {
            border-color: #0f766e;
            background: rgba(15, 118, 110, 0.12);
            color: #115e59;
        }

        .sf-lite-meta {
            margin-top: 12px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.58);
        }

        .sf-lite-meta a {
            color: #0f766e;
            font-weight: 650;
            text-decoration: none;
        }

        .sf-lite-meta a:hover {
            text-decoration: underline;
        }
    </style>

    <section class="sf-lite-shell" aria-label="Dashboard (Lite)">
        <article class="sf-lite-card">
            <h2>Dashboard (Lite)</h2>
            <p>
                This is a fast, lightweight overview while we optimize the full analytics dashboard.
                Use the quick links below for day-to-day work.
            </p>

            <div class="sf-lite-actions" role="navigation" aria-label="Quick links">
                <a class="sf-lite-button sf-lite-button--primary" href="{{ $embeddedUrl(route('shopify.app.customers.manage', [], false)) }}">Customers</a>
                <a class="sf-lite-button" href="{{ $embeddedUrl(route('shopify.app.rewards', [], false)) }}">Rewards</a>
                <a class="sf-lite-button" href="{{ $embeddedUrl(route('shopify.app.messaging', [], false)) }}">Messages</a>
                <a class="sf-lite-button" href="{{ $embeddedUrl(route('shopify.app.settings', [], false)) }}">Settings</a>
            </div>

            <div class="sf-lite-meta">
                Need the full dashboard? <a href="{{ $fullDashboardHref }}">Open full analytics</a>
            </div>
        </article>
    </section>
</x-shopify-embedded-shell>

