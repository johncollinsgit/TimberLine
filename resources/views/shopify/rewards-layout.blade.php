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
            gap: 20px;
            margin-top: 0;
        }

        .rewards-note {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            padding: 18px 20px;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.8);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.04);
        }
    </style>

    <section class="rewards-root">
        @if(filled($setupNote))
            <div class="rewards-note">{{ $setupNote }}</div>
        @endif

        @yield('rewards-content')
    </section>
</x-shopify-embedded-shell>
