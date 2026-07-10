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
        $supportAlerts = is_array($messagingSupportAlerts ?? null) ? $messagingSupportAlerts : [];
        $supportAlertPhone = trim((string) ($supportAlerts['support_alert_phone'] ?? ''));
        $supportAlertSaveEndpoint = trim((string) ($supportAlerts['save_endpoint'] ?? ''));
        $trackingMissingRequestedScopes = array_values((array) ($trackingScopeState['missing_requested'] ?? []));
        $trackingEndpoints = is_array($messageAnalyticsTrackingEndpoints ?? null) ? $messageAnalyticsTrackingEndpoints : [];
        $platformSetup = is_array($messagingPlatformSetup ?? null) ? $messagingPlatformSetup : [];

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
            border-radius: 8px;
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
            border-radius: 8px;
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
            border-radius: 6px;
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
            border-radius: 8px;
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
                <p class="message-setup-muted">Open this page from Shopify Admin so Everbranch can verify the store session and workspace access.</p>
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
                    description="Visibility and access follow workspace setup and module access rules."
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
                                    Storefront tracking ships as an Everbranch theme app embed plus a Shopify web pixel. Deploy extensions, enable the Everbranch embed in Theme Editor, then verify tagged storefront visits against <code>{{ (string) ($trackingProxy['health_path'] ?? '/apps/forestry/health') }}</code>.
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

                        <div class="message-setup-empty" aria-label="Mobile support alert routing">
                            <h4>Mobile app support alerts</h4>
                            <p class="message-setup-muted">When a customer sends a support message from the app, Everbranch will text this number with the message body so your team sees it right away.</p>
                            <label class="message-setup-muted" for="support-alert-phone-input">Support alert phone number</label>
                            <div class="message-setup-actions">
                                <input
                                    id="support-alert-phone-input"
                                    type="tel"
                                    value="{{ $supportAlertPhone }}"
                                    placeholder="+18646165468"
                                    data-support-alert-phone-input
                                    style="min-width: 220px; min-height: 36px; border-radius: 6px; border: 1px solid rgba(15, 23, 42, 0.14); padding: 0 12px; font-size: 13px;"
                                >
                                <button
                                    type="button"
                                    class="message-setup-button message-setup-button--primary"
                                    data-save-support-alert-phone
                                    data-endpoint="{{ $supportAlertSaveEndpoint }}"
                                >
                                    Save support number
                                </button>
                            </div>
                        </div>

                        <p class="message-setup-inline-status" id="message-setup-inline-status" hidden></p>
                    </div>
                </x-tenancy.module-state-card>
            @endif

            <article class="message-setup-card" aria-labelledby="sending-identities-title">
                <div>
                    <h2 id="sending-identities-title">Sending identities and usage</h2>
                    <p class="message-setup-muted">Each company gets separate provider resources. Customers see your verified sender, and replies follow the inbox choice shown below.</p>
                </div>
                @foreach(['email_account' => 'Email', 'sms_account' => 'Text messaging'] as $accountKey => $accountLabel)
                    @php $account = is_array($platformSetup[$accountKey] ?? null) ? $platformSetup[$accountKey] : []; @endphp
                    <div class="message-setup-guide">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h4>{{ $accountLabel }}</h4>
                            <span class="message-setup-status">{{ str((string) ($account['status'] ?? 'not configured'))->replace('_', ' ')->title() }}</span>
                        </div>
                        @if($account === [])
                            <p class="message-setup-muted">Next: ask your Everbranch administrator to start the isolated provider setup for this company.</p>
                        @elseif($accountKey === 'email_account')
                            <p class="message-setup-muted">Provider: {{ str((string) ($account['provider'] ?? ''))->replace('_', ' ')->title() }} · domain: {{ $account['authenticated_domain'] ?? 'waiting for domain' }}</p>
                            @if(!empty($account['dns_records']))
                                <p class="message-setup-muted">Next: add these DNS records with your domain host. Keep every host and value exactly as shown.</p>
                                <div class="overflow-x-auto">
                                    <table class="w-full border-collapse text-left text-xs">
                                        <thead><tr class="border-b border-zinc-200"><th class="p-2">Type</th><th class="p-2">Host</th><th class="p-2">Value</th></tr></thead>
                                        <tbody>
                                            @foreach((array) $account['dns_records'] as $record)
                                                <tr class="border-b border-zinc-100"><td class="p-2 font-medium">{{ $record['type'] ?? '' }}</td><td class="break-all p-2">{{ $record['host'] ?? '' }}</td><td class="break-all p-2">{{ $record['value'] ?? '' }}</td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if(($platformSetup['verification_refresh_enabled'] ?? false) && ($account['status'] ?? '') !== 'ready')
                                    <button type="button" class="message-setup-button" data-refresh-domain-verification data-endpoint="{{ $platformSetup['verification_refresh_endpoint'] ?? '' }}">Check verification</button>
                                @endif
                            @endif
                        @else
                            <p class="message-setup-muted">Number: {{ $account['sender_identifier'] ?? 'assigned after registration' }}</p>
                            @if(($account['status'] ?? '') !== 'ready')<p class="message-setup-muted">Next: complete the customer profile, brand, campaign, Messaging Service, and number registration. Sending stays blocked until all five are approved.</p>@endif
                        @endif
                    </div>
                @endforeach

                <div class="message-setup-guide">
                    <h4>Email send-as and replies</h4>
                    @forelse((array) ($platformSetup['sender_profiles'] ?? []) as $profile)
                        <div class="border-t border-zinc-200 pt-2 text-sm">
                            <strong>{{ $profile['display_name'] }} &lt;{{ $profile['from_email'] }}&gt;</strong>
                            <p class="message-setup-muted">{{ ($profile['reply_mode'] ?? '') === 'direct_inbox' ? 'Replies go directly to '.$profile['reply_to_email'].'.' : 'Replies stay in the Everbranch shared inbox.' }} {{ !empty($profile['is_default']) ? 'Default sender.' : '' }}</p>
                        </div>
                    @empty
                        <p class="message-setup-muted">Next: verify your sending domain, then add the first From address and choose whether replies go to your mailbox or stay in Everbranch.</p>
                    @endforelse
                    @if(is_array($platformSetup['email_account'] ?? null))
                        <div class="grid gap-2 border-t border-zinc-200 pt-3 sm:grid-cols-2" data-sender-profile-form>
                            <input class="rounded-md border-zinc-300 text-sm" name="label" placeholder="Label, such as Support">
                            <input class="rounded-md border-zinc-300 text-sm" name="display_name" placeholder="Name customers see">
                            <input class="rounded-md border-zinc-300 text-sm" name="from_email" type="email" placeholder="support@yourcompany.com">
                            <input class="rounded-md border-zinc-300 text-sm" name="reply_to_email" type="email" placeholder="Mailbox for direct replies">
                            <select class="rounded-md border-zinc-300 text-sm" name="reply_mode"><option value="direct_inbox">Reply to my inbox</option><option value="everbranch_inbox">Keep replies in Everbranch</option></select>
                            <label class="flex items-center gap-2 text-sm text-zinc-700"><input type="checkbox" name="is_default" value="1" checked> Use as the default sender</label>
                            <button type="button" class="message-setup-button message-setup-button--primary sm:col-span-2" data-save-sender-profile data-endpoint="{{ $platformSetup['sender_save_endpoint'] ?? '' }}">Save sender</button>
                        </div>
                    @endif
                    @if(collect((array) ($platformSetup['sender_profiles'] ?? []))->where('verification_status', 'verified')->isNotEmpty())
                        <div class="grid gap-2 border-t border-zinc-200 pt-3 sm:grid-cols-[1fr_1fr_auto]" data-sender-test-form>
                            <select class="rounded-md border-zinc-300 text-sm" name="sender_profile_id">
                                @foreach((array) ($platformSetup['sender_profiles'] ?? []) as $profile)
                                    @if(($profile['verification_status'] ?? '') === 'verified')<option value="{{ $profile['id'] }}">{{ $profile['label'] }} · {{ $profile['from_email'] }}</option>@endif
                                @endforeach
                            </select>
                            <input class="rounded-md border-zinc-300 text-sm" name="to_email" type="email" placeholder="Where should the test go?">
                            <button type="button" class="message-setup-button" data-test-sender-profile data-endpoint="{{ $platformSetup['sender_test_endpoint'] ?? '' }}">Send test</button>
                        </div>
                    @endif
                </div>

                <div class="message-setup-guide">
                    <h4>Monthly usage and prepaid credit</h4>
                    <p class="message-setup-muted">Email: {{ number_format((int) data_get($platformSetup, 'email_usage.used_units', 0)) }} of {{ number_format((int) data_get($platformSetup, 'email_usage.included_units', 0)) }} included. Text: {{ number_format((int) data_get($platformSetup, 'sms_usage.used_units', 0)) }} of {{ number_format((int) data_get($platformSetup, 'sms_usage.included_units', 0)) }} included segments.</p>
                    <p class="message-setup-muted">Available prepaid credit: ${{ number_format(((int) data_get($platformSetup, 'email_usage.credit_available_micros', 0)) / 1000000, 2) }}.</p>
                    @if($platformSetup['credit_checkout_enabled'] ?? false)
                        <div class="message-setup-actions">
                            @foreach((array) ($platformSetup['credit_packs_cents'] ?? []) as $packCents)
                                <form method="POST" action="{{ $platformSetup['credit_checkout_endpoint'] }}">@csrf<input type="hidden" name="pack_cents" value="{{ $packCents }}"><button class="message-setup-button" type="submit">Add ${{ number_format($packCents / 100, 0) }}</button></form>
                            @endforeach
                        </div>
                    @else
                        <p class="message-setup-muted">Credit checkout will appear here after the administrator enables Stripe one-time payments.</p>
                    @endif
                </div>
            </article>
        @endif
    </section>

    @if($authorized && $messagingEnabled)
        <script>
            (function () {
                const setupCompleteButton = document.querySelector('[data-mark-setup-complete]');
                const connectPixelButton = document.querySelector('[data-connect-storefront-pixel]');
                const saveSupportAlertButton = document.querySelector('[data-save-support-alert-phone]');
                const supportAlertPhoneInput = document.querySelector('[data-support-alert-phone-input]');
                const senderProfileForm = document.querySelector('[data-sender-profile-form]');
                const saveSenderButton = document.querySelector('[data-save-sender-profile]');
                const senderTestForm = document.querySelector('[data-sender-test-form]');
                const testSenderButton = document.querySelector('[data-test-sender-profile]');
                const refreshVerificationButton = document.querySelector('[data-refresh-domain-verification]');
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

                async function postJson(url, body = null) {
                    const headers = await resolveEmbeddedAuthHeaders();
                    const hasBody = body !== null;
                    const response = await fetch(url, {
                        method: 'POST',
                        headers,
                        credentials: 'same-origin',
                        body: hasBody ? JSON.stringify(body) : null,
                    });

                    const payload = await response.json().catch(() => ({
                        ok: false,
                        message: 'Unexpected response from Everbranch.',
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

                if (saveSupportAlertButton && supportAlertPhoneInput) {
                    saveSupportAlertButton.addEventListener('click', async () => {
                        const endpoint = String(saveSupportAlertButton.getAttribute('data-endpoint') || '').trim();
                        if (endpoint === '') {
                            return;
                        }

                        saveSupportAlertButton.disabled = true;
                        supportAlertPhoneInput.disabled = true;
                        setSetupStatus('Saving support alert number…');

                        try {
                            const payload = await postJson(endpoint, {
                                support_alert_phone: String(supportAlertPhoneInput.value || '').trim(),
                            });
                            supportAlertPhoneInput.value = String(payload?.data?.support_alert_phone || '').trim();
                            setSetupStatus(payload?.message || 'Support alert number saved.', 'success');
                        } catch (error) {
                            const message = error instanceof Error ? error.message : 'Could not save the support alert number.';
                            setSetupStatus(message, 'error');
                        } finally {
                            saveSupportAlertButton.disabled = false;
                            supportAlertPhoneInput.disabled = false;
                        }
                    });
                }

                if (saveSenderButton && senderProfileForm) {
                    saveSenderButton.addEventListener('click', async () => {
                        saveSenderButton.disabled = true;
                        setSetupStatus('Saving sender…');
                        const value = (name) => senderProfileForm.querySelector(`[name="${name}"]`)?.value || '';
                        try {
                            const payload = await postJson(saveSenderButton.dataset.endpoint, {
                                label: value('label'),
                                display_name: value('display_name'),
                                from_email: value('from_email'),
                                reply_to_email: value('reply_to_email'),
                                reply_mode: value('reply_mode'),
                                is_default: Boolean(senderProfileForm.querySelector('[name="is_default"]')?.checked),
                            });
                            setSetupStatus(payload?.message || 'Sender saved. Reloading…', 'success');
                            window.setTimeout(() => window.location.reload(), 700);
                        } catch (error) {
                            setSetupStatus(error instanceof Error ? error.message : 'Could not save sender.', 'error');
                            saveSenderButton.disabled = false;
                        }
                    });
                }

                if (testSenderButton && senderTestForm) {
                    testSenderButton.addEventListener('click', async () => {
                        testSenderButton.disabled = true;
                        setSetupStatus('Sending test email…');
                        try {
                            const payload = await postJson(testSenderButton.dataset.endpoint, {
                                sender_profile_id: senderTestForm.querySelector('[name="sender_profile_id"]')?.value,
                                to_email: senderTestForm.querySelector('[name="to_email"]')?.value,
                            });
                            setSetupStatus(payload?.message || 'Test email sent.', 'success');
                        } catch (error) {
                            setSetupStatus(error instanceof Error ? error.message : 'Test email failed.', 'error');
                        } finally {
                            testSenderButton.disabled = false;
                        }
                    });
                }

                if (refreshVerificationButton) {
                    refreshVerificationButton.addEventListener('click', async () => {
                        refreshVerificationButton.disabled = true;
                        setStatus('Checking DNS verification…');
                        try {
                            const payload = await postJson(refreshVerificationButton.dataset.endpoint, {});
                            setStatus(payload.message || 'Verification checked.', payload.ok ? 'success' : 'error');
                            if (payload.ok && payload.data?.verified) window.location.reload();
                        } catch (error) {
                            setStatus(error.message || 'Could not check verification.', 'error');
                        } finally {
                            refreshVerificationButton.disabled = false;
                        }
                    });
                }
            })();
        </script>
    @endif
</x-shopify-embedded-shell>
