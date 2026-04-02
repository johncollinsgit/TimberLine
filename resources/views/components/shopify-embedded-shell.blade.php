@props([
    'authorized' => false,
    'shopifyApiKey' => null,
    'shopDomain' => null,
    'host' => null,
    'storeLabel' => null,
    'headline' => null,
    'subheadline' => null,
    'appNavigation' => [],
    'pageSubnav' => [],
    'pageActions' => [],
])

@php
    $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
        request(),
        filled($host) ? (string) $host : null
    );
    $embeddedNavUrl = static fn (string $url): string => \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
    $moduleStates = is_array($appNavigation['moduleStates'] ?? null) ? $appNavigation['moduleStates'] : [];
    $displayLabels = is_array($appNavigation['displayLabels'] ?? null) ? $appNavigation['displayLabels'] : [];
    $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards'));
    if ($rewardsLabel === '') {
        $rewardsLabel = 'Rewards';
    }
    $moduleChecklist = \App\Support\Tenancy\TenantModuleUi::checklist($moduleStates);
    $title = filled($headline) ? (string) $headline : 'Forestry Backstage';
    $workspaceLabel = trim((string) ($appNavigation['workspaceLabel'] ?? 'Commerce'));
    if ($workspaceLabel === '') {
        $workspaceLabel = 'Commerce';
    }
    $commandSearchEndpoint = $appNavigation['commandSearchEndpoint'] ?? null;
    $commandSearchPlaceholder = trim((string) ($appNavigation['commandSearchPlaceholder'] ?? 'Search customers, rewards, and settings'));
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => $title])
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
</head>
<body>
    @if($authorized && filled($shopifyApiKey) && filled($host))
        <s-app-nav>
            <s-link href="{{ $embeddedNavUrl(route('shopify.app', [], false)) }}" rel="home">Home</s-link>
            <s-link href="{{ $embeddedNavUrl(route('shopify.app.customers.manage', [], false)) }}">Customers</s-link>
            <s-link href="{{ $embeddedNavUrl(route('shopify.app.rewards', [], false)) }}">{{ $rewardsLabel }}</s-link>
            <s-link href="{{ $embeddedNavUrl(route('shopify.app.settings', [], false)) }}">Settings</s-link>
        </s-app-nav>
    @endif

    <x-app-shell
        :navigation="[]"
        :page-title="$headline"
        :page-subtitle="$subheadline"
        :subnav="$pageSubnav"
        :actions="$pageActions"
        :store-label="$storeLabel"
        :host="$host"
        :show-sidebar="false"
        :workspace-label="$workspaceLabel"
        :command-search-endpoint="$commandSearchEndpoint"
        :command-search-placeholder="$commandSearchPlaceholder"
    >
        {{ $slot }}
    </x-app-shell>

    @if(is_array($appNavigation) && $moduleStates !== [])
        <script id="tenant-module-access-bootstrap" type="application/json">
            {!! json_encode([
                'tenant_id' => $appNavigation['tenantId'] ?? null,
                'modules' => $moduleStates,
                'checklist' => $moduleChecklist,
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
        </script>
    @endif
</body>
</html>
