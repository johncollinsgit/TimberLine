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
            gap: 22px;
            margin-top: 4px;
        }

        .rewards-note {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            padding: 18px 20px;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.8);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.04);
        }

        .rewards-placeholder {
            border-radius: 12px;
            padding: 22px;
            background: #ffffff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.04);
        }

        .rewards-placeholder h2 {
            margin-top: 0;
            font-size: 1.6rem;
            font-weight: 640;
            color: #0f172a;
        }

        .rewards-placeholder p {
            margin: 12px 0 0;
            color: rgba(15, 23, 42, 0.7);
            line-height: 1.65;
            font-size: 15px;
        }
    </style>

    <section class="rewards-root">
        @if(filled($setupNote))
            <div class="rewards-note">{{ $setupNote }}</div>
        @endif

        @yield('rewards-content')
    </section>
</x-shopify-embedded-shell>
