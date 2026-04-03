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
    /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
    $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
    $embeddedContext = $embeddedUrls->contextQuery(
        request(),
        filled($host) ? (string) $host : null
    );
    $embeddedNavUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
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
    $navItems = is_array($appNavigation['items'] ?? null) ? $appNavigation['items'] : [];
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
            @foreach($navItems as $item)
                @php
                    $itemHref = trim((string) ($item['href'] ?? ''));
                    $itemLabel = trim((string) ($item['label'] ?? ''));
                    $itemKey = trim((string) ($item['key'] ?? ''));
                @endphp
                @continue($itemHref === '' || $itemLabel === '')
                <s-link
                    href="{{ $embeddedNavUrl($itemHref) }}"
                    @if($itemKey === 'home') rel="home" @endif
                >{{ $itemLabel }}</s-link>
            @endforeach
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

    <script>
        (function () {
            if (window.__fbEmbeddedLinkPrefetchBound) {
                return;
            }
            window.__fbEmbeddedLinkPrefetchBound = true;

            const MAX_CONCURRENT = 2;
            const TTL_MS = 120000;
            const HIGH_PRIORITY = "high";
            const NORMAL_PRIORITY = "normal";
            const seen = new Map();
            const queue = [];
            const inFlight = new Set();

            function now() {
                return Date.now();
            }

            function normalizeTargetUrl(rawHref) {
                if (!rawHref) {
                    return null;
                }

                let url;
                try {
                    url = new URL(rawHref, window.location.origin);
                } catch (error) {
                    return null;
                }

                if (url.origin !== window.location.origin) {
                    return null;
                }

                if (!url.pathname.startsWith("/shopify/app")) {
                    return null;
                }

                return url.toString();
            }

            function shouldSkip(url) {
                const lastSeen = seen.get(url);
                if (typeof lastSeen !== "number") {
                    return false;
                }

                return (now() - lastSeen) < TTL_MS;
            }

            function pumpQueue() {
                if (inFlight.size >= MAX_CONCURRENT || queue.length === 0) {
                    return;
                }

                queue.sort((a, b) => {
                    const ap = a.priority === HIGH_PRIORITY ? 2 : (a.priority === NORMAL_PRIORITY ? 1 : 0);
                    const bp = b.priority === HIGH_PRIORITY ? 2 : (b.priority === NORMAL_PRIORITY ? 1 : 0);

                    return bp - ap;
                });

                const next = queue.shift();
                if (!next || inFlight.has(next.url) || shouldSkip(next.url)) {
                    pumpQueue();
                    return;
                }

                inFlight.add(next.url);
                fetch(next.url, {
                    method: "GET",
                    credentials: "same-origin",
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                        "X-Forestry-Prefetch": "1"
                    }
                })
                    .catch(() => {
                        // Best-effort only.
                    })
                    .finally(() => {
                        inFlight.delete(next.url);
                        seen.set(next.url, now());
                        pumpQueue();
                    });
            }

            function schedulePrefetch(href, priority = NORMAL_PRIORITY) {
                const url = normalizeTargetUrl(href);
                if (!url || inFlight.has(url) || shouldSkip(url) || queue.some((item) => item.url === url)) {
                    return;
                }

                queue.push({ url, priority });
                pumpQueue();
            }

            function linkPriority(link) {
                const raw = (link.dataset.prefetchPriority || NORMAL_PRIORITY).toLowerCase();
                if (raw === HIGH_PRIORITY || raw === NORMAL_PRIORITY || raw === "low") {
                    return raw;
                }

                return NORMAL_PRIORITY;
            }

            function resolveLinkFromEventTarget(target) {
                if (!(target instanceof Element)) {
                    return null;
                }

                const link = target.closest('a[data-embedded-prefetch-link="1"]');
                return link instanceof HTMLAnchorElement ? link : null;
            }

            ["mouseenter", "focusin", "touchstart"].forEach((eventName) => {
                document.addEventListener(eventName, (event) => {
                    const link = resolveLinkFromEventTarget(event.target);
                    if (!link) {
                        return;
                    }

                    schedulePrefetch(link.href, linkPriority(link));
                }, { passive: true });
            });

            function warmVisibleNavLinks() {
                const candidates = Array.from(document.querySelectorAll(
                    '.app-topbar-nav a[data-embedded-prefetch-link="1"], .app-topbar-subnav a[data-embedded-prefetch-link="1"]'
                ));

                candidates.forEach((link) => {
                    if (!(link instanceof HTMLAnchorElement)) {
                        return;
                    }

                    const rect = link.getBoundingClientRect();
                    const visible = rect.bottom >= 0
                        && rect.right >= 0
                        && rect.top <= (window.innerHeight || document.documentElement.clientHeight)
                        && rect.left <= (window.innerWidth || document.documentElement.clientWidth);
                    if (!visible) {
                        return;
                    }

                    schedulePrefetch(link.href, linkPriority(link));
                });
            }

            window.setTimeout(warmVisibleNavLinks, 100);
            window.addEventListener("load", warmVisibleNavLinks, { once: true });
        })();
    </script>
</body>
</html>
