@props([
    'authorized' => false,
    'shopifyApiKey' => null,
    'shopDomain' => null,
    'host' => null,
    'headline' => null,
    'subheadline' => null,
    'appNavigation' => [],
    'pageActions' => [],
])

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
        .rewards-root {
            display: flex;
            flex-direction: column;
            gap: 24px;
            margin-top: 8px;
        }

        .rewards-note {
            border-radius: 20px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.92);
            padding: 18px 22px;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.8);
            box-shadow: 0 12px 36px rgba(15, 23, 42, 0.08);
        }
    </style>

    <section class="rewards-root">
        @if(filled($setupNote))
            <div class="rewards-note">{{ $setupNote }}</div>
        @endif

        @yield('rewards-content')
    </section>
</x-shopify-embedded-shell>
