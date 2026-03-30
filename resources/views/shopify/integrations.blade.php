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
        $payload = is_array($integrationsPayload ?? null) ? $integrationsPayload : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : ['label' => 'Unknown', 'track' => 'shopify', 'operating_mode' => 'shopify'];
        $categories = is_array($payload['categories'] ?? null) ? $payload['categories'] : [];
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : ['total' => 0, 'connected' => 0, 'setup_needed' => 0, 'locked' => 0, 'coming_soon' => 0];
        $upgradeCta = is_array($content['upgrade_cta'] ?? null) ? $content['upgrade_cta'] : [];
        $contactCta = is_array($content['contact_cta'] ?? null) ? $content['contact_cta'] : [];
        $journey = is_array($merchantJourney ?? null) ? $merchantJourney : [];
        $importSummary = is_array($journey['import_summary'] ?? null) ? $journey['import_summary'] : [];
        $importCta = is_array($importSummary['cta'] ?? null) ? $importSummary['cta'] : ['label' => 'Import Customers', 'href' => route('shopify.app.integrations', [], false)];
        $activeNow = is_array($journey['active_now'] ?? null) ? $journey['active_now'] : [];
        $availableNext = is_array($journey['available_next'] ?? null) ? $journey['available_next'] : [];
        $purchasable = is_array($journey['purchasable'] ?? null) ? $journey['purchasable'] : [];

        $embeddedContext = \App\Support\Shopify\ShopifyEmbeddedContextQuery::fromRequest(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static function (string $url) use ($embeddedContext): string {
            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                return $url;
            }

            return \App\Support\Shopify\ShopifyEmbeddedContextQuery::appendToUrl($url, $embeddedContext);
        };
    @endphp

    <style>
        .integrations-shell {
            display: grid;
            gap: 14px;
        }

        .integrations-panel {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .integrations-title {
            margin: 0;
            font-size: 1.02rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.86);
            font-weight: 700;
        }

        .integrations-copy {
            margin: 0;
            font-size: 13px;
            line-height: 1.58;
            color: rgba(15, 23, 42, 0.7);
        }

        .integrations-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .integrations-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            min-height: 28px;
            padding: 0 10px;
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.68);
            font-weight: 700;
            background: rgba(248, 250, 252, 0.95);
        }

        .integrations-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .integration-card {
            border-radius: 13px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.95);
            padding: 12px;
            display: grid;
            gap: 8px;
            cursor: pointer;
            transition: border-color 140ms ease, box-shadow 140ms ease, background 140ms ease;
        }

        .integration-card:focus-visible {
            outline: 2px solid rgba(15, 118, 110, 0.45);
            outline-offset: 2px;
        }

        .integration-card:hover {
            border-color: rgba(15, 118, 110, 0.22);
            box-shadow: 0 10px 18px rgba(15, 23, 42, 0.06);
        }

        .integration-card__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .integration-card__title {
            margin: 0;
            font-size: 14px;
            color: rgba(15, 23, 42, 0.88);
            font-weight: 700;
        }

        .integration-card__description {
            margin: 0;
            font-size: 12px;
            line-height: 1.55;
            color: rgba(15, 23, 42, 0.7);
        }

        .integration-card__state {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            min-height: 22px;
            padding: 0 9px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
            border: 1px solid rgba(15, 23, 42, 0.15);
            background: rgba(248, 250, 252, 0.95);
            color: rgba(15, 23, 42, 0.78);
        }

        .integration-card__state--connected {
            border-color: rgba(15, 118, 110, 0.32);
            background: rgba(15, 118, 110, 0.12);
            color: #0f766e;
        }

        .integration-card__state--setup_needed {
            border-color: rgba(180, 83, 9, 0.28);
            background: rgba(180, 83, 9, 0.1);
            color: #92400e;
        }

        .integration-card__state--locked {
            border-color: rgba(190, 24, 93, 0.28);
            background: rgba(190, 24, 93, 0.1);
            color: #9f1239;
        }

        .integration-card__state--coming_soon {
            border-color: rgba(3, 105, 161, 0.28);
            background: rgba(3, 105, 161, 0.1);
            color: #075985;
        }

        .integration-card__meta {
            display: grid;
            gap: 5px;
        }

        .integration-card__meta-line {
            margin: 0;
            font-size: 11px;
            line-height: 1.45;
            color: rgba(15, 23, 42, 0.66);
        }

        .integration-card__link {
            display: inline-flex;
            width: fit-content;
            color: #0f766e;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
        }

        .integration-card__link:hover {
            text-decoration: underline;
        }

        .integration-card__detail-hint {
            margin: 0;
            font-size: 11px;
            color: rgba(15, 118, 110, 0.86);
            font-weight: 700;
        }

        .integration-drawer-backdrop {
            position: fixed;
            inset: 0;
            z-index: 70;
            background: rgba(15, 23, 42, 0.4);
        }

        .integration-drawer {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: min(92vw, 520px);
            z-index: 80;
            display: block;
            pointer-events: none;
        }

        .integration-drawer__frame {
            height: 100%;
            border-left: 1px solid rgba(15, 23, 42, 0.14);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: -24px 0 60px rgba(15, 23, 42, 0.18);
            display: grid;
            grid-template-rows: auto 1fr;
            pointer-events: auto;
        }

        .integration-drawer__top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.1);
        }

        .integration-drawer__top h2 {
            margin: 0;
            font-size: 0.98rem;
            color: rgba(15, 23, 42, 0.9);
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .integration-drawer__close {
            appearance: none;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: rgba(248, 250, 252, 0.95);
            color: rgba(15, 23, 42, 0.74);
            border-radius: 10px;
            min-height: 34px;
            padding: 0 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .integration-drawer__body {
            overflow-y: auto;
            padding: 14px 16px 20px;
            display: grid;
            gap: 12px;
        }

        .integration-drawer__content {
            display: grid;
            gap: 11px;
        }

        .integration-drawer__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .integration-drawer__title {
            margin: 0;
            font-size: 16px;
            color: rgba(15, 23, 42, 0.9);
            font-weight: 700;
            line-height: 1.35;
        }

        .integration-drawer__description {
            margin: 5px 0 0;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.69);
            line-height: 1.55;
        }

        .integration-drawer__fallback-banner {
            margin: 0;
            border-radius: 12px;
            border: 1px solid rgba(15, 118, 110, 0.2);
            background: rgba(236, 253, 245, 0.78);
            color: rgba(15, 118, 110, 0.94);
            font-size: 12px;
            line-height: 1.5;
            padding: 8px 10px;
            font-weight: 600;
        }

        .integration-drawer__section {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.95);
            padding: 10px 11px;
            display: grid;
            gap: 8px;
        }

        .integration-drawer__section h3 {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            color: rgba(15, 23, 42, 0.84);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .integration-drawer__list {
            margin: 0;
            padding-left: 18px;
            display: grid;
            gap: 4px;
        }

        .integration-drawer__list li {
            font-size: 12px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.7);
        }

        .integration-drawer__cta {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.95);
            padding: 10px 11px;
            display: grid;
            gap: 8px;
        }

        .integration-drawer__cta-copy {
            margin: 0;
            font-size: 12px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.68);
        }

        .integration-drawer__button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            min-height: 34px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(15, 118, 110, 0.12);
            color: #0f766e;
            font-size: 12px;
            font-weight: 700;
            padding: 0 12px;
            text-decoration: none;
        }

        .integration-drawer__button--upgrade {
            border-color: rgba(190, 24, 93, 0.25);
            background: rgba(190, 24, 93, 0.1);
            color: #9f1239;
        }

        .integration-drawer__button--coming-soon {
            border-color: rgba(3, 105, 161, 0.24);
            background: rgba(3, 105, 161, 0.1);
            color: #075985;
        }

        .integration-drawer__button[disabled] {
            opacity: 0.65;
            cursor: not-allowed;
        }

        @media (max-width: 840px) {
            .integrations-grid {
                grid-template-columns: 1fr;
            }

            .integration-drawer {
                width: 100%;
            }
        }
    </style>

    <section class="integrations-shell" data-integrations-surface="true">
        <article class="integrations-panel" aria-label="Integrations overview">
            <h2 class="integrations-title">{{ $content['headline'] ?? 'Integrations' }}</h2>
            <p class="integrations-copy">Connect customer data sources so import and customer workflows stay reliable.</p>
            <p class="integrations-copy">{{ $importSummary['description'] ?? 'Import customers first to unlock customer management and lifecycle actions.' }}</p>
            <div class="integrations-meta">
                <span class="integrations-pill">Plan · {{ $plan['label'] ?? 'Unknown' }}</span>
                <span class="integrations-pill">Import · {{ $importSummary['label'] ?? 'Not started' }}</span>
                <span class="integrations-pill">Connected · {{ (int) ($counts['connected'] ?? 0) }}</span>
                <span class="integrations-pill">Setup Needed · {{ (int) ($counts['setup_needed'] ?? 0) }}</span>
                <span class="integrations-pill">Locked · {{ (int) ($counts['locked'] ?? 0) }}</span>
                <span class="integrations-pill">Coming Soon · {{ (int) ($counts['coming_soon'] ?? 0) }}</span>
            </div>
            <p class="integrations-copy">{{ $importSummary['progress_note'] ?? 'No import has run yet for this store context.' }}</p>
            <p class="integrations-copy">Active now: {{ count($activeNow) }} · Setup next: {{ count($availableNext) }} · Upgrade opportunities: {{ count($purchasable) }}</p>
            <div class="integrations-meta">
                <a class="integration-card__link" href="{{ $embeddedUrl((string) ($importCta['href'] ?? route('shopify.app.integrations', [], false))) }}">{{ $importCta['label'] ?? 'Import Customers' }}</a>
                <a class="integration-card__link" href="{{ $embeddedUrl(route('shopify.app.start', [], false)) }}">Open Setup Checklist</a>
                @if(is_array($upgradeCta) && filled($upgradeCta['href'] ?? null))
                    <a class="integration-card__link" href="{{ $embeddedUrl($upgradeCta['href']) }}">{{ $upgradeCta['label'] ?? 'Upgrade to unlock' }}</a>
                @endif
                @if(is_array($contactCta) && filled($contactCta['href'] ?? null))
                    <a class="integration-card__link" href="{{ $contactCta['href'] }}">{{ $contactCta['label'] ?? 'Talk to sales' }}</a>
                @endif
            </div>
        </article>

        @foreach($categories as $category)
            @php
                $cards = is_array($category['cards'] ?? null) ? $category['cards'] : [];
            @endphp
            @continue($cards === [])

            <article class="integrations-panel" data-integration-category="{{ $category['key'] ?? 'other' }}" aria-label="{{ $category['label'] ?? 'Category' }}">
                <h3 class="integrations-title">{{ $category['label'] ?? 'Category' }}</h3>
                <div class="integrations-grid">
                    @foreach($cards as $card)
                        @php
                            $state = (string) ($card['state'] ?? 'setup_needed');
                            $fallback = is_array($card['fallback'] ?? null) ? $card['fallback'] : [];
                            $cta = is_array($card['cta'] ?? null) ? $card['cta'] : [];
                            $setup = is_array($card['setup'] ?? null) ? $card['setup'] : [];
                            $statusRegistry = is_array($card['status_registry'] ?? null) ? $card['status_registry'] : [];
                            $setupSteps = is_array($setup['setup_steps'] ?? null) ? $setup['setup_steps'] : [];
                            $requiredFields = is_array($setup['required_fields'] ?? null) ? $setup['required_fields'] : [];
                            $fallbackOptions = is_array($setup['fallback_options'] ?? null) ? $setup['fallback_options'] : [];
                            $notes = is_array($setup['notes'] ?? null) ? $setup['notes'] : [];
                            $upgradeMessage = trim((string) ($setup['upgrade_message'] ?? ''));
                        @endphp
                        <section
                            class="integration-card"
                            data-integration-key="{{ $card['key'] ?? 'integration' }}"
                            data-integration-state="{{ $state }}"
                            data-integration-open="{{ $card['key'] ?? 'integration' }}"
                            tabindex="0"
                            role="button"
                            aria-haspopup="dialog"
                            aria-controls="integration-setup-drawer"
                            aria-expanded="false"
                        >
                            <header class="integration-card__head">
                                <h4 class="integration-card__title">{{ $card['title'] ?? 'Integration' }}</h4>
                                <span class="integration-card__state integration-card__state--{{ $state }}">{{ $card['state_label'] ?? 'State' }}</span>
                            </header>

                            <p class="integration-card__description">{{ $card['description'] ?? '' }}</p>

                            <div class="integration-card__meta">
                                @if(filled($card['plan_requirement'] ?? null))
                                    <p class="integration-card__meta-line">Plan requirement: {{ str_replace('_', ' ', (string) $card['plan_requirement']) }}</p>
                                @endif

                                @if((bool) ($fallback['available'] ?? false))
                                    <p class="integration-card__meta-line">
                                        Fallback: {{ $fallback['label'] ?? 'Manual fallback' }}
                                        @if(filled($fallback['href'] ?? null))
                                            · <a class="integration-card__link" href="{{ $embeddedUrl((string) $fallback['href']) }}">Open fallback</a>
                                        @endif
                                    </p>
                                @else
                                    <p class="integration-card__meta-line">Fallback: None configured.</p>
                                @endif

                                @if($statusRegistry !== [])
                                    <p class="integration-card__meta-line" data-integration-card-status="{{ $card['key'] ?? 'integration' }}">
                                        Connection status: {{ $statusRegistry['status_label'] ?? 'Unknown' }}
                                    </p>
                                    <p class="integration-card__meta-line" data-integration-card-source="{{ $card['key'] ?? 'integration' }}">
                                        Data path: {{ $statusRegistry['source_label'] ?? 'Unspecified source' }}
                                    </p>
                                    <p class="integration-card__meta-line" data-integration-card-setup-mode="{{ $card['key'] ?? 'integration' }}">
                                        Setup mode: {{ strtoupper((string) ($statusRegistry['setup_mode'] ?? 'guided')) }}
                                        @if((bool) ($statusRegistry['is_mocked'] ?? false))
                                            · Preview status
                                        @endif
                                    </p>
                                    @if(filled($statusRegistry['last_checked_at'] ?? null))
                                        <p class="integration-card__meta-line" data-integration-card-last-checked="{{ $card['key'] ?? 'integration' }}">
                                            Last checked: {{ $statusRegistry['last_checked_at'] }}
                                        </p>
                                    @endif
                                @endif
                            </div>

                            @if(filled($cta['href'] ?? null))
                                <a class="integration-card__link" href="{{ $embeddedUrl((string) $cta['href']) }}">{{ $cta['label'] ?? 'Open' }}</a>
                            @endif

                            <p class="integration-card__detail-hint">Open setup details</p>
                        </section>

                        <template id="integration-setup-template-{{ $card['key'] ?? 'integration' }}" data-integration-setup-template="{{ $card['key'] ?? 'integration' }}">
                            <div class="integration-drawer__content" data-integration-setup-block="{{ $card['key'] ?? 'integration' }}">
                                <header class="integration-drawer__header">
                                    <div>
                                        <h3 class="integration-drawer__title">{{ $card['title'] ?? 'Integration' }}</h3>
                                        <p class="integration-drawer__description">{{ $card['description'] ?? '' }}</p>
                                    </div>
                                    <span class="integration-card__state integration-card__state--{{ $state }}">{{ $card['state_label'] ?? 'State' }}</span>
                                </header>

                                <p class="integration-drawer__fallback-banner">You can still use this system without this integration.</p>

                                @if($statusRegistry !== [])
                                    <section class="integration-drawer__section" data-integration-drawer-status="{{ $card['key'] ?? 'integration' }}">
                                        <h3>Connection Status</h3>
                                        <ul class="integration-drawer__list">
                                            <li data-integration-drawer-status-label="{{ $card['key'] ?? 'integration' }}">
                                                Connection status: {{ $statusRegistry['status_label'] ?? 'Unknown' }}
                                            </li>
                                            <li data-integration-drawer-source-label="{{ $card['key'] ?? 'integration' }}">
                                                Data path: {{ $statusRegistry['source_label'] ?? 'Unspecified source' }}
                                            </li>
                                            <li data-integration-drawer-setup-mode="{{ $card['key'] ?? 'integration' }}">
                                                Setup mode: {{ strtoupper((string) ($statusRegistry['setup_mode'] ?? 'guided')) }}
                                            </li>
                                            @if(filled($statusRegistry['last_checked_at'] ?? null))
                                                <li data-integration-drawer-last-checked="{{ $card['key'] ?? 'integration' }}">
                                                    Last checked: {{ $statusRegistry['last_checked_at'] }}
                                                </li>
                                            @endif
                                            @if(filled($statusRegistry['summary'] ?? null))
                                                <li data-integration-drawer-summary="{{ $card['key'] ?? 'integration' }}">
                                                    {{ $statusRegistry['summary'] }}
                                                </li>
                                            @endif
                                        </ul>
                                    </section>
                                @endif

                                <section class="integration-drawer__section" data-integration-setup-steps="{{ $card['key'] ?? 'integration' }}">
                                    <h3>Setup Steps</h3>
                                    <ol class="integration-drawer__list">
                                        @foreach($setupSteps as $step)
                                            <li>{{ $step }}</li>
                                        @endforeach
                                    </ol>
                                </section>

                                <section class="integration-drawer__section" data-integration-required-fields="{{ $card['key'] ?? 'integration' }}">
                                    <h3>Required Fields</h3>
                                    <ul class="integration-drawer__list">
                                        @foreach($requiredFields as $field)
                                            <li>{{ $field }}</li>
                                        @endforeach
                                    </ul>
                                </section>

                                <section class="integration-drawer__section" data-integration-fallback-options="{{ $card['key'] ?? 'integration' }}">
                                    <h3>Fallback Options</h3>
                                    <ul class="integration-drawer__list">
                                        @foreach($fallbackOptions as $option)
                                            <li>{{ $option }}</li>
                                        @endforeach
                                    </ul>
                                </section>

                                @if($notes !== [])
                                    <section class="integration-drawer__section" data-integration-notes="{{ $card['key'] ?? 'integration' }}">
                                        <h3>Notes</h3>
                                        <ul class="integration-drawer__list">
                                            @foreach($notes as $note)
                                                <li>{{ $note }}</li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif

                                <section class="integration-drawer__cta" data-integration-cta-state="{{ $state }}">
                                    @if($state === 'setup_needed')
                                        <button type="button" class="integration-drawer__button" data-integration-placeholder-action="continue_setup">Continue setup</button>
                                        <p class="integration-drawer__cta-copy">Setup actions are guidance-only right now. Use this flow to plan setup and import safely.</p>
                                    @elseif($state === 'locked')
                                        <a href="{{ $embeddedUrl(route('shopify.app.plans', [], false)) }}" class="integration-drawer__button integration-drawer__button--upgrade">Upgrade to unlock</a>
                                        <p class="integration-drawer__cta-copy">{{ $upgradeMessage !== '' ? $upgradeMessage : 'This integration is locked by the current access profile.' }}</p>
                                    @elseif($state === 'coming_soon')
                                        <button type="button" class="integration-drawer__button integration-drawer__button--coming-soon" disabled>Coming soon</button>
                                        <p class="integration-drawer__cta-copy">{{ $upgradeMessage !== '' ? $upgradeMessage : 'This integration is currently roadmap-visible only.' }}</p>
                                    @else
                                        <a href="{{ $embeddedUrl(route('shopify.app.integrations', ['integration' => $card['key']], false)) }}" class="integration-drawer__button">View details</a>
                                        <p class="integration-drawer__cta-copy">Use these details to confirm setup and data-readiness before relying on this integration in daily workflows.</p>
                                    @endif
                                </section>
                            </div>
                        </template>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>

    <div class="integration-drawer-backdrop" data-integration-drawer-backdrop hidden></div>
    <aside
        class="integration-drawer"
        id="integration-setup-drawer"
        data-integration-drawer="true"
        role="dialog"
        aria-modal="true"
        aria-label="Integration setup detail"
        hidden
    >
        <div class="integration-drawer__frame">
            <header class="integration-drawer__top">
                <h2>Setup Detail</h2>
                <button type="button" class="integration-drawer__close" data-integration-drawer-close>Close</button>
            </header>
            <div class="integration-drawer__body" data-integration-drawer-body>
                <p class="integration-drawer__cta-copy">Select an integration card to view setup guidance.</p>
            </div>
        </div>
    </aside>

    <script>
        (function () {
            const drawer = document.querySelector('[data-integration-drawer]');
            const backdrop = document.querySelector('[data-integration-drawer-backdrop]');
            const body = document.querySelector('[data-integration-drawer-body]');
            const cards = Array.from(document.querySelectorAll('[data-integration-open]'));

            if (!drawer || !backdrop || !body || cards.length === 0) {
                return;
            }

            let activeCard = null;

            function setCardExpanded(card, expanded) {
                if (!card) return;
                card.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }

            function openDrawer(card) {
                const key = card.getAttribute('data-integration-open');
                if (!key) return;

                const template = document.getElementById(`integration-setup-template-${key}`);
                if (!(template instanceof HTMLTemplateElement)) return;

                body.innerHTML = '';
                body.appendChild(template.content.cloneNode(true));

                drawer.hidden = false;
                backdrop.hidden = false;
                setCardExpanded(activeCard, false);
                activeCard = card;
                setCardExpanded(activeCard, true);
            }

            function closeDrawer() {
                drawer.hidden = true;
                backdrop.hidden = true;
                setCardExpanded(activeCard, false);

                if (activeCard && typeof activeCard.focus === 'function') {
                    activeCard.focus();
                }

                activeCard = null;
            }

            cards.forEach((card) => {
                card.addEventListener('click', (event) => {
                    const target = event.target;
                    if (target instanceof Element && target.closest('a')) {
                        return;
                    }

                    openDrawer(card);
                });

                card.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }

                    event.preventDefault();
                    openDrawer(card);
                });
            });

            document.querySelectorAll('[data-integration-drawer-close]').forEach((btn) => {
                btn.addEventListener('click', closeDrawer);
            });

            backdrop.addEventListener('click', closeDrawer);

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !drawer.hidden) {
                    closeDrawer();
                }
            });
        })();
    </script>
</x-shopify-embedded-shell>
