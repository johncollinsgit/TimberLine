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
        $bootstrap = is_array($messagingBootstrap ?? null) ? $messagingBootstrap : [];
    @endphp

    <style>
        .sf-messaging-shell {
            width: 100%;
            max-width: 1260px;
            margin: 0 auto;
            display: grid;
            gap: 14px;
        }

        .sf-messaging-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
            padding: 16px;
            display: grid;
            gap: 8px;
        }

        .sf-messaging-card h2,
        .sf-messaging-card p {
            margin: 0;
        }

        .sf-messaging-card[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.2);
            background: rgba(180, 35, 24, 0.06);
        }

        .sf-messaging-muted {
            color: rgba(15, 23, 42, 0.62);
            font-size: 13px;
            line-height: 1.5;
        }

        .sf-messaging-loading {
            min-height: 220px;
            display: grid;
            gap: 6px;
            align-content: center;
            justify-items: start;
        }

        [hidden] {
            display: none !important;
        }
    </style>

    <section class="sf-messaging-shell">
        @if(is_array($messagingModuleState))
            <x-tenancy.module-state-card
                :module-state="$messagingModuleState"
                title="Messaging module state"
                description="Visibility and access follow tenant entitlement + module-state conventions."
            />
        @endif

        @if(! $authorized)
            <article class="sf-messaging-card">
                <h2>Messages requires Shopify context</h2>
                <p>Open this page from Shopify Admin so Backstage can verify store access.</p>
            </article>
        @elseif(! $messagingEnabled)
            <article class="sf-messaging-card" data-tone="error">
                <h2>Messaging is locked</h2>
                <p>{{ $messagingMessage !== '' ? $messagingMessage : 'Messaging is not enabled for this tenant.' }}</p>
                @if($messagingStatus !== '')
                    <p class="sf-messaging-muted">Status: {{ $messagingStatus }}</p>
                @endif
            </article>
        @else
            <div id="shopify-messaging-root" class="sf-messaging-card" aria-live="polite">
                <div class="sf-messaging-loading">
                    <h2>Messages Workspace</h2>
                    <p class="sf-messaging-muted">Loading messaging workspace.</p>
                    <span class="sf-messaging-muted" hidden>Audience Groups</span>
                    <span class="sf-messaging-muted" hidden>Send to group</span>
                    <div id="messages-group-editor" hidden></div>
                </div>
            </div>
            <script id="shopify-messaging-bootstrap" type="application/json">
                {!! json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
            </script>
        @endif
    </section>
</x-shopify-embedded-shell>
