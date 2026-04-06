<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions"
>
    @php
        $messagingModuleState = is_array($messagingModuleState ?? null) ? $messagingModuleState : null;
        $messagingAccess = is_array($messagingAccess ?? null) ? $messagingAccess : [];
        $messagingEnabled = (bool) ($messagingAccess['enabled'] ?? false);
        $messagingStatus = trim((string) ($messagingAccess['status'] ?? ''));
        $messagingMessage = trim((string) ($messagingAccess['message'] ?? ''));
        $bootstrap = is_array($messagingResponsesBootstrap ?? null) ? $messagingResponsesBootstrap : [];
    @endphp

    <style>
        .sf-responses-shell {
            width: 100%;
            max-width: 1320px;
            margin: 0 auto;
            display: grid;
            gap: 14px;
        }

        .sf-responses-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .sf-responses-card h2,
        .sf-responses-card p {
            margin: 0;
        }

        .sf-responses-card[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.2);
            background: rgba(180, 35, 24, 0.06);
        }

        .sf-responses-muted {
            color: rgba(15, 23, 42, 0.62);
            font-size: 13px;
            line-height: 1.5;
        }

        .sf-responses-loading {
            min-height: 240px;
            display: grid;
            gap: 8px;
            align-content: center;
            justify-items: start;
        }

        [hidden] {
            display: none !important;
        }
    </style>

    <section class="sf-responses-shell">
        @if(is_array($messagingModuleState))
            <x-tenancy.module-state-card
                :module-state="$messagingModuleState"
                title="Messaging module state"
                description="Responses follows the same tenant entitlement and embedded access rules as the rest of Messaging."
            />
        @endif

        @if(! $authorized)
            <article class="sf-responses-card">
                <h2>Responses requires Shopify context</h2>
                <p>Open this page from Shopify Admin so Backstage can verify store access.</p>
            </article>
        @elseif(! $messagingEnabled)
            <article class="sf-responses-card" data-tone="error">
                <h2>Responses is locked</h2>
                <p>{{ $messagingMessage !== '' ? $messagingMessage : 'Messaging is not enabled for this tenant.' }}</p>
                @if($messagingStatus !== '')
                    <p class="sf-responses-muted">Status: {{ $messagingStatus }}</p>
                @endif
            </article>
        @else
            <div id="shopify-responses-root" class="sf-responses-card" aria-live="polite">
                <div class="sf-responses-loading">
                    <h2>Responses</h2>
                    <p class="sf-responses-muted">Loading unified inbox for Text and Email replies.</p>
                    <span class="sf-responses-muted">Text</span>
                    <span class="sf-responses-muted">Email</span>
                </div>
            </div>
            <script id="shopify-responses-bootstrap" type="application/json">
                {!! json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
            </script>
        @endif
    </section>
</x-shopify-embedded-shell>
