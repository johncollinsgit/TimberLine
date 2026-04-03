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
    :page-actions="$pageActions ?? []"
>
    @php
        $payload = is_array($moduleStorePayload ?? null) ? $moduleStorePayload : [];
        $currentPlan = is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : ['label' => 'Unknown', 'operating_mode' => 'shopify'];
        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $storeSections = [
            'active' => 'Active now',
            'available' => 'Add now',
            'upgrade' => 'Upgrade path',
            'request' => 'Request or sales assist',
        ];
        $focusModule = strtolower(trim((string) request('module', '')));
        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
        $contextTokenValue = trim((string) ($contextToken ?? ''));
    @endphp

    <style>
        .module-store-shell {
            display: grid;
            gap: 20px;
        }

        .module-store-summary,
        .module-store-panel,
        .module-store-card {
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.06);
        }

        .module-store-summary,
        .module-store-panel {
            padding: 18px 20px;
        }

        .module-store-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }

        .module-store-card {
            padding: 16px;
            display: grid;
            gap: 12px;
        }

        .module-store-card[data-focused="true"] {
            border-color: rgba(14, 116, 144, 0.38);
            box-shadow: 0 18px 40px rgba(14, 116, 144, 0.14);
        }

        .module-store-meta,
        .module-store-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .module-store-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 700;
            color: rgba(15, 23, 42, 0.7);
            background: rgba(248, 250, 252, 0.95);
        }

        .module-store-copy {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.72);
        }

        .module-store-button,
        .module-store-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.16);
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            color: rgba(15, 23, 42, 0.86);
            background: rgba(255, 255, 255, 0.96);
        }

        .module-store-button--primary {
            border-color: rgba(15, 23, 42, 0.92);
            background: rgba(15, 23, 42, 0.94);
            color: white;
        }
    </style>

    <section class="module-store-shell" data-module-store="shopify">
        <article class="module-store-summary">
            <h2 class="plans-title">Tenant Module Catalog</h2>
            <p class="plans-copy">Plan {{ $currentPlan['label'] ?? 'Unknown' }} · mode {{ strtoupper((string) ($currentPlan['operating_mode'] ?? 'shopify')) }}. This view only shows safe, tenant-visible modules from the canonical catalog.</p>
            <div class="module-store-meta">
                <span class="module-store-pill">Active {{ count((array) ($sections['active'] ?? [])) }}</span>
                <span class="module-store-pill">Available {{ count((array) ($sections['available'] ?? [])) }}</span>
                <span class="module-store-pill">Upgrade {{ count((array) ($sections['upgrade'] ?? [])) }}</span>
                <span class="module-store-pill">Request {{ count((array) ($sections['request'] ?? [])) }}</span>
            </div>
        </article>

        @foreach($storeSections as $sectionKey => $sectionLabel)
            @php
                $modules = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : [];
            @endphp

            @if($modules === [])
                @continue
            @endif

            <article class="module-store-panel">
                <h2 class="plans-title">{{ $sectionLabel }}</h2>
                <div class="module-store-grid">
                    @foreach($modules as $module)
                        @php
                            $moduleState = is_array($module['module_state'] ?? null) ? $module['module_state'] : [];
                            $cta = (string) ($moduleState['cta'] ?? 'none');
                            $moduleKey = (string) ($module['module_key'] ?? '');
                            $isFocused = $focusModule !== '' && $focusModule === $moduleKey;
                            $ctaHref = trim((string) ($moduleState['cta_href'] ?? ''));
                        @endphp
                        <article class="module-store-card" data-module-key="{{ $moduleKey }}" data-focused="{{ $isFocused ? 'true' : 'false' }}">
                            <header class="tenant-module-state-card__head">
                                <div>
                                    <h3 class="tenant-module-state-card__title">{{ $module['display_name'] ?? $moduleKey }}</h3>
                                    <p class="module-store-copy">{{ $module['description'] ?? '' }}</p>
                                </div>
                                <x-tenancy.module-state-badge :module-state="$moduleState" size="sm" />
                            </header>

                            <div class="module-store-meta">
                                <span class="module-store-pill">{{ strtoupper(str_replace('_', ' ', (string) ($module['billing_mode'] ?? 'unavailable'))) }}</span>
                                @foreach((array) ($module['included_in_plans'] ?? []) as $planKey)
                                    <span class="module-store-pill">{{ strtoupper((string) $planKey) }}</span>
                                @endforeach
                            </div>

                            <p class="module-store-copy">{{ $moduleState['reason_description'] ?? $moduleState['description'] ?? '' }}</p>

                            <div class="module-store-actions">
                                @if($cta === 'add')
                                    <form method="POST" action="{{ $embeddedUrl(route('shopify.app.store.activate', ['moduleKey' => $moduleKey], false)) }}">
                                        @csrf
                                        @if($contextTokenValue !== '')
                                            <input type="hidden" name="context_token" value="{{ $contextTokenValue }}">
                                        @endif
                                        <button type="submit" class="module-store-button module-store-button--primary">{{ $moduleState['cta_label'] ?? 'Add module' }}</button>
                                    </form>
                                @elseif($cta === 'request')
                                    <form method="POST" action="{{ $embeddedUrl(route('shopify.app.store.request', ['moduleKey' => $moduleKey], false)) }}">
                                        @csrf
                                        @if($contextTokenValue !== '')
                                            <input type="hidden" name="context_token" value="{{ $contextTokenValue }}">
                                        @endif
                                        <button type="submit" class="module-store-button">{{ $moduleState['cta_label'] ?? 'Request access' }}</button>
                                    </form>
                                @elseif($cta === 'upgrade' && $ctaHref !== '')
                                    <a class="module-store-link" href="{{ $embeddedUrl($ctaHref) }}">{{ $moduleState['cta_label'] ?? 'Upgrade plan' }}</a>
                                @elseif($ctaHref !== '')
                                    <a class="module-store-link" href="{{ $embeddedUrl($ctaHref) }}">{{ $moduleState['cta_label'] ?? 'Open module catalog' }}</a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>
</x-shopify-embedded-shell>
