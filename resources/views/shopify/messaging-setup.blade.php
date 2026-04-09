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

        $setupGuide = is_array($messagingSetupGuide ?? null) ? $messagingSetupGuide : [];
        $setupConfigured = strtolower(trim((string) ($setupGuide['status'] ?? 'not_started'))) === 'configured';
        $setupSteps = collect((array) ($setupGuide['steps'] ?? []))
            ->filter(fn ($step) => is_array($step) && trim((string) ($step['label'] ?? '')) !== '')
            ->values();
        $trackingSetup = is_array($setupGuide['tracking'] ?? null) ? $setupGuide['tracking'] : [];
        $trackingCommands = is_array($trackingSetup['commands'] ?? null) ? $trackingSetup['commands'] : [];
        $trackingProxy = is_array($trackingSetup['app_proxy'] ?? null) ? $trackingSetup['app_proxy'] : [];
        $trackingScopeState = is_array($trackingSetup['scope_state'] ?? null) ? $trackingSetup['scope_state'] : [];
        $trackingHealth = is_array($trackingSetup['health_summary'] ?? null) ? $trackingSetup['health_summary'] : [];
        $trackingHealthTheme = is_array($trackingHealth['theme_embed'] ?? null) ? $trackingHealth['theme_embed'] : [];
        $trackingHealthPixel = is_array($trackingHealth['web_pixel'] ?? null) ? $trackingHealth['web_pixel'] : [];
        $trackingHealthEvents = is_array($trackingHealth['events'] ?? null) ? $trackingHealth['events'] : [];
        $trackingHealthScopes = is_array($trackingHealth['scopes'] ?? null) ? $trackingHealth['scopes'] : [];
        $trackingWebPixel = is_array($trackingSetup['web_pixel'] ?? null) ? $trackingSetup['web_pixel'] : [];
        $trackingRecentEvents = is_array($trackingSetup['recent_events'] ?? null) ? $trackingSetup['recent_events'] : [];
        $trackingInventory = collect((array) ($trackingSetup['tracking_inventory'] ?? []))
            ->filter(fn ($row) => is_array($row))
            ->values();
        $trackingMissingRequestedScopes = array_values((array) ($trackingScopeState['missing_requested'] ?? []));
        $trackingEndpoints = is_array($messageAnalyticsTrackingEndpoints ?? null) ? $messageAnalyticsTrackingEndpoints : [];

        $embeddedContextQuery = collect(request()->query())
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    @endphp

    <style>
        .message-setup-root {
            display: grid;
            gap: 14px;
            width: 100%;
            max-width: 1320px;
            margin: 0 auto;
        }

        .message-setup-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            padding: 14px;
            display: grid;
            gap: 10px;
        }

        .message-setup-card h2,
        .message-setup-card p {
            margin: 0;
        }

        .message-setup-card[data-tone="error"] {
            border-color: rgba(180, 35, 24, 0.2);
            background: rgba(180, 35, 24, 0.06);
        }

        .message-setup-muted {
            color: rgba(15, 23, 42, 0.62);
            font-size: 13px;
            line-height: 1.5;
        }

        .message-setup-guide {
            border: 1px dashed rgba(15, 23, 42, 0.16);
            border-radius: 12px;
            background: rgba(248, 250, 252, 0.65);
            padding: 14px;
            display: grid;
            gap: 10px;
        }

        .message-setup-guide h4,
        .message-setup-guide p {
            margin: 0;
        }

        .message-setup-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
        }

        .message-setup-list li {
            display: grid;
            gap: 4px;
        }

        .message-setup-list li strong {
            font-size: 13px;
            line-height: 1.35;
        }

        .message-setup-list li[data-done="true"] strong {
            color: #0d8b5f;
        }

        .message-setup-list li[data-done="false"] strong {
            color: #9a3412;
        }

        .message-setup-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .message-setup-button {
            min-height: 36px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: #fff;
            color: #0f172a;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            padding: 0 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .message-setup-button--primary {
            border-color: rgba(15, 143, 97, 0.35);
            background: rgba(15, 143, 97, 0.12);
            color: #0e7a53;
        }

        .message-setup-empty {
            border: 1px dashed rgba(15, 23, 42, 0.16);
            border-radius: 12px;
            background: rgba(248, 250, 252, 0.7);
            padding: 12px;
            display: grid;
            gap: 8px;
        }

        .message-setup-links {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .message-setup-status {
            display: inline-flex;
            align-items: center;
            padding: 4px 9px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: rgba(15, 23, 42, 0.04);
            color: #1e293b;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .message-setup-inline-status {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            border-radius: 10px;
            padding: 0 10px;
            background: rgba(15, 23, 42, 0.05);
            color: #334155;
            font-size: 12px;
            font-weight: 600;
        }

        .message-setup-inline-status[data-tone="success"] {
            background: rgba(15, 143, 97, 0.14);
            color: #0f766e;
        }

        .message-setup-inline-status[data-tone="error"] {
            background: rgba(180, 35, 24, 0.14);
            color: #9a3412;
        }
    </style>

    <section class="message-setup-root">
        @if(! $authorized)
            <article class="message-setup-card">
                <h2>Messaging setup requires Shopify context</h2>
                <p class="message-setup-muted">Open this page from Shopify Admin so Backstage can verify the store session and tenant scope.</p>
            </article>
        @elseif(! $messagingEnabled)
            <article class="message-setup-card" data-tone="error">
                <h2>Messaging setup is locked</h2>
                <p class="message-setup-muted">{{ $messagingMessage !== '' ? $messagingMessage : 'Messaging is not enabled for this tenant.' }}</p>
                @if($messagingStatus !== '')
                    <p class="message-setup-muted">Status: {{ $messagingStatus }}</p>
                @endif
            </article>
        @else
            @if(is_array($messagingModuleState))
                <x-tenancy.module-state-card
                    :module-state="$messagingModuleState"
                    title="Messaging module state"
                    description="Visibility and access follow tenant entitlement + module-state conventions."
                >
                    <div class="message-setup-guide">
                        @if($setupConfigured)
                            <h4>Setup complete for this tenant</h4>
                            <p class="message-setup-muted">For new tenants, use the same sequence below and then click “Mark setup complete.”</p>
                        @else
                            <h4>How to set this up</h4>
                            <p class="message-setup-muted">Complete these steps in order, then mark the module configured.</p>
                        @endif

                        @if($setupSteps->isNotEmpty())
                            <ol class="message-setup-list" aria-label="Messaging setup checklist">
                                @foreach($setupSteps as $step)
                                    @php
                                        $stepDone = (bool) ($step['done'] ?? false);
                                    @endphp
                                    <li data-done="{{ $stepDone ? 'true' : 'false' }}">
                                        <strong>{{ $stepDone ? 'Done:' : 'Next:' }} {{ (string) ($step['label'] ?? '') }}</strong>
                                        @if(filled($step['hint'] ?? null))
                                            <span class="message-setup-muted">{{ (string) $step['hint'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        <div class="message-setup-actions">
                            <a class="message-setup-button" href="{{ route('shopify.app.settings', $embeddedContextQuery, false) }}">Open Settings</a>
                            <a class="message-setup-button" href="{{ route('shopify.app.messaging', $embeddedContextQuery, false) }}">Open Workspace</a>
                            <a class="message-setup-button" href="{{ route('shopify.app.messaging.analytics', $embeddedContextQuery, false) }}">Open Analytics</a>
                            @if(filled(data_get($setupGuide, 'actions.theme_editor_href')))
                                <a class="message-setup-button" href="{{ (string) data_get($setupGuide, 'actions.theme_editor_href') }}" target="_top" rel="noreferrer">Open Theme Editor</a>
                            @endif
                            @if(filled(data_get($setupGuide, 'actions.customer_events_href')))
                                <a class="message-setup-button" href="{{ (string) data_get($setupGuide, 'actions.customer_events_href') }}" target="_top" rel="noreferrer">Open Customer Events</a>
                            @endif
                            @if(! $setupConfigured && (bool) ($setupGuide['can_mark_complete'] ?? false))
                                <button
                                    type="button"
                                    class="message-setup-button message-setup-button--primary"
                                    data-mark-setup-complete
                                    data-endpoint="{{ (string) data_get($setupGuide, 'actions.complete_endpoint', route('shopify.app.api.messaging.setup.complete', [], false)) }}"
                                >
                                    Mark setup complete
                                </button>
                            @endif
                        </div>

                        @if($trackingSetup !== [])
                            <div class="message-setup-empty" aria-label="Storefront tracking deployment">
                                <p class="message-setup-muted">
                                    Storefront tracking ships from this repo as a Shopify theme app embed plus a Shopify web pixel. Deploy extensions, enable the Forestry embed in Theme Editor, then verify tagged storefront visits against <code>{{ (string) ($trackingProxy['health_path'] ?? '/apps/forestry/health') }}</code>.
                                </p>
                                <div class="message-setup-links">
                                    <span class="message-setup-status">Theme embed inferred: {{ (bool) ($trackingHealthTheme['inferred_enabled'] ?? false) ? 'Yes' : 'No' }}</span>
                                    <span class="message-setup-status">Recent events: {{ number_format((int) ($trackingHealthEvents['recent_count'] ?? 0)) }}</span>
                                    <span class="message-setup-status">Last event: {{ \Illuminate\Support\Str::of((string) ($trackingHealthEvents['last_event_type'] ?? 'none'))->replace('_', ' ')->title() }}</span>
                                    <span class="message-setup-status">Checkout completed recently: {{ (bool) ($trackingHealthEvents['checkout_completion_seen_recently'] ?? false) ? 'Yes' : 'No' }}</span>
                                    <span class="message-setup-status">Scope verification: {{ (bool) ($trackingHealthScopes['verified'] ?? false) ? 'Verified' : 'Pending' }}</span>
                                </div>
                                <p class="message-setup-muted">
                                    Setup inference: {{ strtolower(trim((string) ($trackingHealth['setup_inference'] ?? 'configuration_only'))) === 'recent_storefront_events' ? 'recent event data + config' : 'config only (no recent event proof yet)' }}.
                                </p>
                                @if($trackingInventory->isNotEmpty())
                                    <ol class="message-setup-list" aria-label="Storefront tracking inventory">
                                        @foreach($trackingInventory as $source)
                                            <li data-done="{{ in_array((string) ($source['status'] ?? ''), ['flow_detected', 'connected', 'events_recorded', 'enabled', 'scopes_granted'], true) ? 'true' : 'false' }}">
                                                <strong>{{ (string) ($source['source'] ?? 'Tracking source') }} ({{ (string) ($source['status'] ?? 'unknown') }})</strong>
                                                <span class="message-setup-muted">{{ (string) ($source['runs_in'] ?? '') }}</span>
                                                @if(! empty($source['known_gaps'] ?? []))
                                                    <span class="message-setup-muted">Gaps: {{ implode(' | ', (array) $source['known_gaps']) }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                                @if($trackingWebPixel !== [])
                                    <div class="message-setup-links">
                                        <span class="message-setup-status">Pixel status: {{ (string) ($trackingWebPixel['label'] ?? 'Unknown') }}</span>
                                        @if(filled($trackingWebPixel['pixel_id'] ?? null))
                                            <span class="message-setup-status">Pixel ID: {{ (string) $trackingWebPixel['pixel_id'] }}</span>
                                        @endif
                                        @if(! empty($trackingWebPixel['missing_scopes'] ?? []))
                                            <span class="message-setup-status">Missing scopes: {{ implode(', ', (array) $trackingWebPixel['missing_scopes']) }}</span>
                                        @endif
                                    </div>
                                    @if(filled($trackingWebPixel['message'] ?? null))
                                        <p class="message-setup-muted">{{ (string) $trackingWebPixel['message'] }}</p>
                                    @endif
                                @endif
                                @if($trackingMissingRequestedScopes !== [])
                                    <p class="message-setup-muted">Missing requested Shopify scopes: {{ implode(', ', $trackingMissingRequestedScopes) }}.</p>
                                @endif
                                @if($trackingRecentEvents !== [])
                                    <details class="message-setup-guide">
                                        <summary>Raw tracking diagnostics</summary>
                                        <pre class="message-setup-muted" style="white-space: pre-wrap; margin: 0;">{{ json_encode($trackingSetup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                                @if($trackingCommands !== [])
                                    <div class="message-setup-links">
                                        @if(filled($trackingCommands['info'] ?? null))
                                            <span class="message-setup-status">{{ (string) $trackingCommands['info'] }}</span>
                                        @endif
                                        @if(filled($trackingCommands['dev'] ?? null))
                                            <span class="message-setup-status">{{ (string) $trackingCommands['dev'] }}</span>
                                        @endif
                                        @if(filled($trackingCommands['deploy'] ?? null))
                                            <span class="message-setup-status">{{ (string) $trackingCommands['deploy'] }}</span>
                                        @endif
                                    </div>
                                @endif
                                @if((string) ($trackingWebPixel['status'] ?? '') === 'reauthorize_required' && filled(data_get($setupGuide, 'actions.reconnect_href')))
                                    <div class="message-setup-actions">
                                        <a
                                            class="message-setup-button message-setup-button--primary"
                                            href="{{ (string) data_get($setupGuide, 'actions.reconnect_href') }}"
                                            target="_top"
                                            rel="noreferrer"
                                        >
                                            Reconnect Shopify
                                        </a>
                                    </div>
                                @elseif((bool) ($trackingWebPixel['can_connect'] ?? false))
                                    <div class="message-setup-actions">
                                        <button
                                            type="button"
                                            class="message-setup-button message-setup-button--primary"
                                            data-connect-storefront-pixel
                                            data-endpoint="{{ (string) data_get($setupGuide, 'actions.connect_pixel_endpoint', $trackingEndpoints['connect_pixel'] ?? '') }}"
                                        >
                                            Connect Shopify Pixel
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <p class="message-setup-inline-status" id="message-setup-inline-status" hidden></p>
                    </div>
                </x-tenancy.module-state-card>
            @endif
        @endif
    </section>

    @if($authorized && $messagingEnabled)
        <script>
            (function () {
                const setupCompleteButton = document.querySelector('[data-mark-setup-complete]');
                const connectPixelButton = document.querySelector('[data-connect-storefront-pixel]');
                const setupStatusNode = document.getElementById('message-setup-inline-status');

                function setSetupStatus(message, tone = 'neutral') {
                    if (!setupStatusNode) {
                        return;
                    }

                    const text = typeof message === 'string' ? message.trim() : '';
                    if (text === '') {
                        setupStatusNode.hidden = true;
                        setupStatusNode.textContent = '';
                        setupStatusNode.removeAttribute('data-tone');
                        return;
                    }

                    setupStatusNode.hidden = false;
                    setupStatusNode.textContent = text;
                    setupStatusNode.setAttribute('data-tone', tone);
                }

                function authFailureMessage(status, fallbackMessage) {
                    const messages = {
                        missing_api_auth: 'Shopify Admin verification is unavailable. Reload from Shopify Admin and try again.',
                        invalid_session_token: 'Shopify Admin verification failed. Reload from Shopify Admin and try again.',
                        expired_session_token: 'Your Shopify Admin session expired. Reload from Shopify Admin and try again.',
                    };

                    return messages[status] || fallbackMessage || null;
                }

                async function resolveEmbeddedAuthHeaders() {
                    if (window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders) {
                        try {
                            return await window.ForestryEmbeddedApp.resolveEmbeddedAuthHeaders();
                        } catch (error) {
                            throw new Error(authFailureMessage(error?.code, error?.message || 'Shopify Admin verification is unavailable.'));
                        }
                    }

                    if (!window.shopify || typeof window.shopify.idToken !== 'function') {
                        throw new Error(authFailureMessage('missing_api_auth', 'Shopify Admin verification is unavailable.'));
                    }

                    let token = null;
                    try {
                        token = await Promise.race([
                            Promise.resolve(window.shopify.idToken()),
                            new Promise((resolve) => window.setTimeout(() => resolve(null), 6000)),
                        ]);
                    } catch (error) {
                        throw new Error(authFailureMessage('invalid_session_token', 'Shopify Admin verification failed.'));
                    }

                    if (typeof token !== 'string' || token.trim() === '') {
                        throw new Error(authFailureMessage('missing_api_auth', 'Shopify Admin verification is unavailable.'));
                    }

                    return {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        Authorization: `Bearer ${token.trim()}`,
                    };
                }

                async function postJson(url) {
                    const headers = await resolveEmbeddedAuthHeaders();
                    const response = await fetch(url, {
                        method: 'POST',
                        headers,
                        credentials: 'same-origin',
                    });

                    const payload = await response.json().catch(() => ({
                        ok: false,
                        message: 'Unexpected response from Backstage.',
                    }));

                    if (!response.ok) {
                        throw new Error(
                            authFailureMessage(payload?.status, payload?.message || 'Request failed.')
                            || payload?.message
                            || 'Request failed.'
                        );
                    }

                    return payload;
                }

                if (setupCompleteButton) {
                    setupCompleteButton.addEventListener('click', async () => {
                        const endpoint = String(setupCompleteButton.getAttribute('data-endpoint') || '').trim();
                        if (endpoint === '') {
                            return;
                        }

                        setupCompleteButton.disabled = true;
                        setSetupStatus('Marking setup complete…');

                        try {
                            await postJson(endpoint);
                            setSetupStatus('Messaging setup marked complete. Reloading…', 'success');
                            window.setTimeout(() => window.location.reload(), 500);
                        } catch (error) {
                            const message = error instanceof Error ? error.message : 'Could not mark setup complete.';
                            setSetupStatus(message, 'error');
                            setupCompleteButton.disabled = false;
                        }
                    });
                }

                if (connectPixelButton) {
                    connectPixelButton.addEventListener('click', async () => {
                        const endpoint = String(connectPixelButton.getAttribute('data-endpoint') || '').trim();
                        if (endpoint === '') {
                            return;
                        }

                        connectPixelButton.disabled = true;
                        setSetupStatus('Connecting Shopify pixel…');

                        try {
                            const payload = await postJson(endpoint);
                            const message = payload?.message || 'Shopify pixel connected. Reloading…';
                            setSetupStatus(message, 'success');
                            window.setTimeout(() => window.location.reload(), 700);
                        } catch (error) {
                            const message = error instanceof Error ? error.message : 'Could not connect the Shopify pixel.';
                            setSetupStatus(message, 'error');
                            connectPixelButton.disabled = false;
                        }
                    });
                }
            })();
        </script>
    @endif
</x-shopify-embedded-shell>
