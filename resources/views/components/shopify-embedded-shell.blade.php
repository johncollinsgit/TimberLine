@props([
    'authorized' => false,
    'shopifyApiKey' => null,
    'shopDomain' => null,
    'host' => null,
    'storeLabel' => null,
    'headline' => null,
    'subheadline' => null,
    'appNavigation' => [],
    'pageActions' => [],
])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if($authorized && filled($shopifyApiKey))
        <meta name="shopify-api-key" content="{{ $shopifyApiKey }}">
    @endif
    @if($authorized && filled($shopDomain))
        <meta name="shopify-shop-domain" content="{{ $shopDomain }}">
    @endif
    @if($authorized && filled($host))
        <meta name="shopify-host" content="{{ $host }}">
    @endif

    @if($authorized && filled($shopifyApiKey) && filled($host))
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    @endif

    <style>
        body {
            margin: 0;
            font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            background: #eef1ec;
            min-height: 100vh;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }
    </style>
</head>
<body>
    <x-app-shell
        :navigation="$appNavigation"
        :page-title="$headline"
        :page-subtitle="$subheadline"
        :actions="$pageActions"
        :store-label="$storeLabel"
    >
        {{ $slot }}
    </x-app-shell>
</body>
</html>
