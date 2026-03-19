<x-shopify.customers-layout
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :customer-subnav="$pageSubnav"
    :page-actions="$pageActions"
>
    @php
        $summary = (array) ($detail['summary'] ?? []);
        $statuses = (array) ($detail['statuses'] ?? []);
        $activity = (array) ($detail['activity'] ?? []);
        $externalProfiles = $detail['external_profiles'] ?? collect();
        $consent = (array) ($detail['consent'] ?? []);
        $messaging = (array) ($detail['messaging'] ?? []);
        $smsInfo = (array) ($messaging['sms'] ?? []);
        $smsSupported = (bool) ($smsInfo['supported'] ?? false);
        $smsHasPhone = (bool) ($smsInfo['has_phone'] ?? false);
        $smsConsented = (bool) ($smsInfo['consented'] ?? false);
        $smsPhoneDisplay = (string) ($smsInfo['phone_display'] ?? 'No phone on file');
        $smsConsentLabel = (string) ($smsInfo['consent_label'] ?? 'Consent needed');
        $smsSenders = (array) ($smsInfo['senders'] ?? []);
        $smsDefaultSenderKey = (string) ($smsInfo['default_sender_key'] ?? '');
        $selectedSmsSenderKey = old('sender_key', $smsDefaultSenderKey);
        $notice = session('customer_detail_notice');
        $actionUrlGenerator = app(\App\Services\Shopify\ShopifyEmbeddedCustomerActionUrlGenerator::class);
        $mutationBootstrap = (array) ($customerMutationBootstrap ?? []);
    @endphp

    <style>
        .customers-detail-header {
            display: grid;
            gap: 14px;
        }

        .customers-detail-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }

        .customers-detail-back {
            font-size: 12px;
            text-decoration: none;
            color: rgba(15, 23, 42, 0.6);
        }

        .customers-detail-back:hover {
            color: rgba(15, 23, 42, 0.9);
        }

        .customers-detail-name {
            margin: 6px 0 0;
            font-size: 1.4rem;
            font-weight: 650;
            letter-spacing: -0.02em;
            color: #0f172a;
        }

        .customers-detail-meta {
            margin-top: 6px;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.65);
        }

        .customers-detail-summary {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .customers-detail-metric {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.94);
            padding: 12px 14px;
        }

        .customers-detail-metric h4 {
            margin: 0;
            font-size: 11px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .customers-detail-metric p {
            margin: 8px 0 0;
            font-size: 16px;
            font-weight: 630;
            color: #0f172a;
        }

        .customers-detail-grid {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }

        .customers-detail-card {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
        }

        .customers-detail-card h3 {
            margin: 0;
            font-size: 12px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.52);
            font-weight: 650;
        }

        .customers-detail-card h3 + * {
            margin-top: 12px;
        }

        .customers-detail-card p {
            margin: 8px 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.7);
            line-height: 1.6;
        }

        .customers-detail-card .text-small {
            margin-top: 8px;
            color: rgba(15, 23, 42, 0.6);
        }

        .customers-detail-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 620;
            border: 1px solid rgba(15, 23, 42, 0.12);
        }

        .customers-detail-pill.is-yes {
            border-color: rgba(15, 143, 97, 0.3);
            background: rgba(15, 143, 97, 0.12);
            color: #0d6f4d;
        }

        .customers-detail-pill.is-no {
            border-color: rgba(148, 163, 184, 0.25);
            background: rgba(148, 163, 184, 0.08);
            color: #475569;
        }

        .customers-detail-table {
            width: 100%;
            border-collapse: collapse;
        }

        .customers-detail-table th {
            text-align: left;
            padding: 10px 12px;
            font-size: 10px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(247, 250, 246, 0.9);
        }

        .customers-detail-table td {
            padding: 10px 12px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.72);
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            white-space: nowrap;
        }

        .customers-detail-form {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .customers-detail-form input {
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            padding: 8px 10px;
            font-size: 12px;
        }

        .customers-detail-form textarea {
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            padding: 8px 10px;
            font-size: 12px;
            font-family: inherit;
            resize: vertical;
        }

        .customers-detail-form select {
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            padding: 8px 10px;
            font-size: 12px;
            background: #fff;
        }

        .customers-detail-form label {
            font-size: 11px;
            font-weight: 600;
            color: rgba(15, 23, 42, 0.6);
        }

        .customers-detail-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: rgba(255, 255, 255, 0.95);
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 620;
            cursor: pointer;
            color: rgba(15, 23, 42, 0.8);
        }

        .customers-detail-button.is-primary {
            border-color: rgba(15, 143, 97, 0.32);
            background: rgba(15, 143, 97, 0.14);
            color: #0d6f4d;
        }

        .customers-detail-button[disabled] {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .customers-detail-notice {
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 12px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255, 255, 255, 0.9);
        }

        .customers-detail-notice.is-success {
            border-color: rgba(15, 143, 97, 0.25);
            color: #0d6f4d;
        }

        .customers-detail-notice.is-warning {
            border-color: rgba(217, 119, 6, 0.25);
            color: #b45309;
        }

        .customers-detail-notice[hidden] {
            display: none;
        }

        .customers-detail-field-error {
            min-height: 14px;
            margin: -2px 0 0;
            font-size: 11px;
            color: #b45309;
        }

        .customers-detail-form [aria-invalid="true"] {
            border-color: rgba(217, 119, 6, 0.45);
            box-shadow: 0 0 0 1px rgba(217, 119, 6, 0.18);
        }
    </style>

    @if(! $authorized)
        <section class="customers-surface customers-detail-header">
            <div class="customers-detail-header-row">
                <div>
                    <h2 class="customers-detail-name">Customer detail unavailable</h2>
                    <div class="customers-detail-meta">
                        Shopify context status: {{ str_replace('_', ' ', $status ?? 'unknown') }}
                    </div>
                </div>
            </div>
        </section>

        @if(is_array($notice))
            <div class="customers-detail-notice {{ ($notice['style'] ?? 'success') === 'warning' ? 'is-warning' : 'is-success' }}">
                {{ $notice['message'] ?? 'Update saved.' }}
            </div>
        @endif

        <section class="customers-detail-card" aria-label="Customer detail unavailable">
            <h3>Context Required</h3>
            <p>This customer page cannot render actionable widgets until Shopify Admin context is verified.</p>
            <p>Reopen this customer from Manage customers inside the embedded Shopify Admin app.</p>
            @if(($status ?? null) === 'invalid_hmac')
                <p>The signed Shopify query on this request could not be verified, so the page intentionally suppressed every customer widget and form.</p>
            @elseif(($status ?? null) === 'open_from_shopify')
                <p>This route was opened without the signed Shopify query or embedded session needed to power the customer widgets.</p>
            @endif
        </section>
    @else
        <div
            id="shopify-customer-detail"
            data-identity-endpoint="{{ $mutationBootstrap['identityEndpoint'] ?? '' }}"
            data-adjustment-endpoint="{{ $mutationBootstrap['adjustmentEndpoint'] ?? '' }}"
            data-send-candle-cash-endpoint="{{ $mutationBootstrap['sendCandleCashEndpoint'] ?? '' }}"
        >
            <section class="customers-surface customers-detail-header">
                <div class="customers-detail-header-row">
                    <div>
                        <a class="customers-detail-back" href="{{ $actionUrlGenerator->url('customers.manage', [], request()) }}">Back to Manage customers</a>
                        <h2 class="customers-detail-name" data-customer-display-name>{{ $customerDisplayName }}</h2>
                        <div class="customers-detail-meta">
                            <span data-customer-email-display>{{ $marketingProfile->email ?: 'Email not set' }}</span>
                            · ID {{ $marketingProfile->id }}
                        </div>
                    </div>
                    <div class="customers-detail-meta">
                        Last activity: {{ $summary['last_activity_display'] ?? '—' }}
                    </div>
                </div>
            </section>

            @if(is_array($notice))
                <div class="customers-detail-notice {{ ($notice['style'] ?? 'success') === 'warning' ? 'is-warning' : 'is-success' }}">
                    {{ $notice['message'] ?? 'Update saved.' }}
                </div>
            @endif

            <section class="customers-detail-summary" aria-label="Customer summary">
                <article class="customers-detail-metric">
                    <h4>Candle Cash</h4>
                    <p data-customer-balance-display>{{ $summary['candle_cash_display'] ?? '0' }}</p>
                </article>
                <article class="customers-detail-metric">
                    <h4>Candle Club</h4>
                    <p>{{ ! empty($summary['candle_club_active']) ? 'Active' : 'Not active' }}</p>
                </article>
                <article class="customers-detail-metric">
                    <h4>Rewards Actions</h4>
                    <p>{{ number_format((int) ($summary['rewards_actions_count'] ?? 0)) }}</p>
                </article>
                <article class="customers-detail-metric">
                    <h4>Birthday</h4>
                    <p>{{ ! empty($summary['birthday_tracked']) ? 'Tracked' : 'Not tracked' }}</p>
                </article>
                <article class="customers-detail-metric">
                    <h4>Wholesale</h4>
                    <p>{{ ! empty($summary['wholesale_eligible']) ? 'Eligible' : 'Not eligible' }}</p>
                </article>
            </section>

            <section class="customers-detail-grid" aria-label="Customer detail sections">
                <article class="customers-detail-card">
                    <h3>Identity</h3>
                    <p>
                        <span data-customer-display-name>{{ $customerDisplayName }}</span><br>
                        <span data-customer-email-display>{{ $marketingProfile->email ?: 'Email not set' }}</span><br>
                        <span data-customer-phone-display>{{ $marketingProfile->phone ?: 'Phone not set' }}</span><br>
                        Created: {{ optional($marketingProfile->created_at)->format('Y-m-d H:i') ?: '—' }}<br>
                        Updated: <span data-customer-updated-display>{{ optional($marketingProfile->updated_at)->format('Y-m-d H:i') ?: '—' }}</span><br>
                        Marketing profile ID: {{ $marketingProfile->id }}
                    </p>

                    <form
                        method="POST"
                        action="{{ $customerFormActions['update'] ?? '#' }}"
                        class="customers-detail-form"
                        data-embedded-mutation-form
                        data-api-endpoint="{{ $mutationBootstrap['identityEndpoint'] ?? '' }}"
                        data-api-method="PATCH"
                    >
                        @csrf
                        @method('PATCH')
                        <input type="text" name="first_name" value="{{ old('first_name', $marketingProfile->first_name) }}" placeholder="First name" />
                        <p class="customers-detail-field-error" data-error-for="first_name">{{ $errors->first('first_name') }}</p>
                        <input type="text" name="last_name" value="{{ old('last_name', $marketingProfile->last_name) }}" placeholder="Last name" />
                        <p class="customers-detail-field-error" data-error-for="last_name">{{ $errors->first('last_name') }}</p>
                        <input type="email" name="email" value="{{ old('email', $marketingProfile->email) }}" placeholder="Email" />
                        <p class="customers-detail-field-error" data-error-for="email">{{ $errors->first('email') }}</p>
                        <input type="text" name="phone" value="{{ old('phone', $marketingProfile->phone) }}" placeholder="Phone" />
                        <p class="customers-detail-field-error" data-error-for="phone">{{ $errors->first('phone') }}</p>
                        <button type="submit" class="customers-detail-button is-primary">Save identity</button>
                        <div
                            class="customers-detail-notice {{ ($errors->has('first_name') || $errors->has('last_name') || $errors->has('email') || $errors->has('phone')) ? 'is-warning' : 'is-success' }}"
                            data-form-feedback
                            @if(! ($errors->has('first_name') || $errors->has('last_name') || $errors->has('email') || $errors->has('phone'))) hidden @endif
                        >
                            {{ $errors->first('first_name') ?: $errors->first('last_name') ?: $errors->first('email') ?: $errors->first('phone') }}
                        </div>
                    </form>
                </article>

                <article class="customers-detail-card">
                    <h3>Loyalty Profile</h3>
                    <p>
                        Candle Cash balance: <span data-customer-balance-display>{{ $summary['candle_cash_display'] ?? '0' }}</span><br>
                        Candle Club: {{ ! empty($statuses['candle_club']) ? 'Active' : 'Not active' }}<br>
                        Wholesale: {{ ! empty($statuses['wholesale']) ? 'Eligible' : 'Not eligible' }}<br>
                        Birthday tracked: {{ ! empty($summary['birthday_tracked']) ? 'Yes' : 'No' }}
                    </p>
                </article>

                <article class="customers-detail-card">
                    <h3>Candle Cash Adjustment</h3>
                    <p>
                        Current balance: <span data-customer-balance-display>{{ $summary['candle_cash_display'] ?? '0' }}</span><br>
                        Manual adjustments are recorded in the activity log and require a reason.<br>
                        Positive additions automatically text a Candle Cash rewards link when SMS consent and a phone number are available.
                    </p>

                    <form
                        method="POST"
                        action="{{ $customerFormActions['candle_cash_adjust'] ?? '#' }}"
                        class="customers-detail-form"
                        data-embedded-mutation-form
                        data-api-endpoint="{{ $mutationBootstrap['adjustmentEndpoint'] ?? '' }}"
                        data-reset-on-success="true"
                    >
                        @csrf
                        <label>Adjustment type</label>
                        <select name="direction">
                            <option value="add" @selected(old('direction') === 'add')>Add Candle Cash</option>
                            <option value="subtract" @selected(old('direction') === 'subtract')>Subtract Candle Cash</option>
                        </select>
                        <p class="customers-detail-field-error" data-error-for="direction">{{ $errors->first('direction') }}</p>
                        <label>Amount (Candle Cash)</label>
                        <input type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" placeholder="Amount" />
                        <p class="customers-detail-field-error" data-error-for="amount">{{ $errors->first('amount') }}</p>
                        <label>Reason</label>
                        <input type="text" name="reason" value="{{ old('reason') }}" placeholder="Reason for adjustment" />
                        <p class="customers-detail-field-error" data-error-for="reason">{{ $errors->first('reason') }}</p>
                        <button type="submit" class="customers-detail-button is-primary">Apply adjustment</button>
                        <div
                            class="customers-detail-notice is-warning"
                            data-form-feedback
                            @if(! ($errors->has('direction') || $errors->has('amount') || $errors->has('reason'))) hidden @endif
                        >
                            {{ $errors->first('direction') ?: $errors->first('amount') ?: $errors->first('reason') }}
                        </div>
                    </form>
                </article>

                <article class="customers-detail-card">
                    <h3>Send Candle Cash</h3>
                    <p>
                        Send Candle Cash to the customer as a reward action. This is distinct from a manual adjustment and will be labeled separately in activity.
                    </p>

                    <form
                        method="POST"
                        action="{{ $customerFormActions['candle_cash_send'] ?? '#' }}"
                        class="customers-detail-form"
                        data-embedded-mutation-form
                        data-api-endpoint="{{ $mutationBootstrap['sendCandleCashEndpoint'] ?? '' }}"
                        data-reset-on-success="true"
                    >
                        @csrf
                        @php
                            $giftIntentOptions = $giftIntentOptions ?? [];
                            $giftOriginOptions = $giftOriginOptions ?? [];
                        @endphp
                        <label>Amount (Candle Cash)</label>
                        <input type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" placeholder="Amount" />
                        <p class="customers-detail-field-error" data-error-for="amount">{{ $errors->first('amount') }}</p>
                        <label>Reason</label>
                        <input type="text" name="reason" value="{{ old('reason') }}" placeholder="Reason for sending" />
                        <p class="customers-detail-field-error" data-error-for="reason">{{ $errors->first('reason') }}</p>
                        <label>Gift intent (optional)</label>
                        <select name="gift_intent">
                            <option value="">Select an intent</option>
                            @foreach($giftIntentOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('gift_intent') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="customers-detail-field-error" data-error-for="gift_intent">{{ $errors->first('gift_intent') }}</p>
                        <label>Gift origin (optional)</label>
                        <select name="gift_origin">
                            <option value="">Select an origin</option>
                            @foreach($giftOriginOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('gift_origin') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="customers-detail-field-error" data-error-for="gift_origin">{{ $errors->first('gift_origin') }}</p>
                        <label>Campaign key (optional)</label>
                        <input type="text" name="campaign_key" value="{{ old('campaign_key') }}" placeholder="Campaign key" />
                        <p class="customers-detail-field-error" data-error-for="campaign_key">{{ $errors->first('campaign_key') }}</p>
                        <label>SMS sender</label>
                        <select name="sender_key">
                            @foreach($smsSenders as $sender)
                                <option value="{{ $sender['key'] }}" @selected($selectedSmsSenderKey === $sender['key']) @disabled(empty($sender['sendable']))>
                                    {{ $sender['label'] }} · {{ $sender['type'] }} · {{ $sender['status'] }}{{ empty($sender['sendable']) ? ' (not sendable yet)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="customers-detail-field-error" data-error-for="sender_key">{{ $errors->first('sender_key') }}</p>
                        <label>Optional message (SMS)</label>
                        <textarea name="message" rows="3" placeholder="Optional message to send after crediting Candle Cash">{{ old('message') }}</textarea>
                        <p class="customers-detail-field-error" data-error-for="message">{{ $errors->first('message') }}</p>
                        @if(! $smsSupported)
                            <div class="customers-detail-meta">SMS messaging is disabled in this environment.</div>
                        @elseif(! $smsHasPhone)
                            <div class="customers-detail-meta">SMS message will not send because there is no phone on file.</div>
                        @elseif(! $smsConsented)
                            <div class="customers-detail-meta">SMS message will not send because the customer has not consented.</div>
                        @endif
                        <button type="submit" class="customers-detail-button is-primary">Send Candle Cash</button>
                        <div
                            class="customers-detail-notice is-warning"
                            data-form-feedback
                            @if(! ($errors->has('amount') || $errors->has('reason') || $errors->has('message') || $errors->has('gift_intent') || $errors->has('gift_origin') || $errors->has('campaign_key') || $errors->has('sender_key'))) hidden @endif
                        >
                            {{ $errors->first('amount')
                                ?: $errors->first('reason')
                                ?: $errors->first('gift_intent')
                                ?: $errors->first('gift_origin')
                                ?: $errors->first('campaign_key')
                                ?: $errors->first('sender_key')
                                ?: $errors->first('message') }}
                        </div>
                    </form>
                </article>

                <article class="customers-detail-card">
                    <h3>Reward Completion</h3>
                    <div class="customers-detail-meta">
                        <span class="customers-detail-pill {{ ! empty($statuses['candle_club']) ? 'is-yes' : 'is-no' }}">Candle Club</span>
                        <span class="customers-detail-pill {{ ! empty($statuses['referral']) ? 'is-yes' : 'is-no' }}">Referral</span>
                        <span class="customers-detail-pill {{ ! empty($statuses['review']) ? 'is-yes' : 'is-no' }}">Review</span>
                        <span class="customers-detail-pill {{ ! empty($statuses['birthday']) ? 'is-yes' : 'is-no' }}">Birthday</span>
                        <span class="customers-detail-pill {{ ! empty($statuses['wholesale']) ? 'is-yes' : 'is-no' }}">Wholesale</span>
                    </div>
                </article>

                <article class="customers-detail-card">
                    <h3>Consent</h3>
                    @php
                        $emailConsent = (array) ($consent['email'] ?? []);
                        $smsConsent = (array) ($consent['sms'] ?? []);
                    @endphp
                    <p>
                        Email: {{ $emailConsent['label'] ?? 'Not consented' }}
                        @if(! empty($emailConsent['last_event']['occurred_at_display'] ?? null))
                            · Updated {{ $emailConsent['last_event']['occurred_at_display'] }}
                        @endif
                        @if(! empty($emailConsent['opted_out_at'] ?? null))
                            · Opted out {{ $emailConsent['opted_out_at'] }}
                        @endif
                        <br>
                        SMS: {{ $smsConsent['label'] ?? 'Not consented' }}
                        @if(! empty($smsConsent['last_event']['occurred_at_display'] ?? null))
                            · Updated {{ $smsConsent['last_event']['occurred_at_display'] }}
                        @endif
                        @if(! empty($smsConsent['opted_out_at'] ?? null))
                            · Opted out {{ $smsConsent['opted_out_at'] }}
                        @endif
                    </p>

                    <form method="POST" action="{{ $actionUrlGenerator->url('customers.update-consent', ['marketingProfile' => $marketingProfile->id], request()) }}" class="customers-detail-form">
                        @csrf
                        <label>Channel</label>
                        <select name="channel">
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                            <option value="both">Email + SMS</option>
                        </select>
                        <label>Consent state</label>
                        <select name="consented">
                            <option value="1">Consented</option>
                            <option value="0">Not consented</option>
                        </select>
                        <input type="text" name="notes" placeholder="Notes (optional)" />
                        <button type="submit" class="customers-detail-button is-primary">Save consent</button>
                    </form>
                </article>

                <article class="customers-detail-card">
                    <h3>Message Customer</h3>
                    <p>
                        SMS: {{ $smsPhoneDisplay }}<br>
                        Consent: {{ $smsConsentLabel }}
                        @if($smsSenders !== [])
                            <br>Senders:
                            @foreach($smsSenders as $index => $sender)
                                {{ $index > 0 ? ' · ' : ' ' }}{{ $sender['label'] }} ({{ $sender['type'] }}, {{ $sender['status'] }}){{ !empty($sender['is_default']) ? ' default' : '' }}
                            @endforeach
                        @endif
                        @if(! $smsSupported)
                            <br>SMS sending is not enabled in this environment.
                        @elseif(! $smsHasPhone)
                            <br>Add a phone number to send SMS.
                        @elseif(! $smsConsented)
                            <br>SMS consent is required before messages can be sent.
                        @endif
                    </p>

                    <form method="POST" action="{{ $actionUrlGenerator->url('customers.message', ['marketingProfile' => $marketingProfile->id], request()) }}" class="customers-detail-form">
                        @csrf
                        <label>Channel</label>
                        <select name="channel">
                            <option value="sms" @selected(old('channel', 'sms') === 'sms')>SMS</option>
                        </select>
                        <label>SMS sender</label>
                        <select name="sender_key">
                            @foreach($smsSenders as $sender)
                                <option value="{{ $sender['key'] }}" @selected($selectedSmsSenderKey === $sender['key']) @disabled(empty($sender['sendable']))>
                                    {{ $sender['label'] }} · {{ $sender['type'] }} · {{ $sender['status'] }}{{ empty($sender['sendable']) ? ' (not sendable yet)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <label>Message</label>
                        <textarea name="message" rows="3" placeholder="Write a direct message">{{ old('message') }}</textarea>
                        <button type="submit" class="customers-detail-button is-primary" @disabled(! $smsSupported || ! $smsHasPhone)>Send message</button>
                        @if($errors->has('channel') || $errors->has('message'))
                            <div class="customers-detail-notice is-warning">
                                {{ $errors->first('channel') ?: $errors->first('message') }}
                            </div>
                        @endif
                    </form>
                </article>
            </section>

            <section class="customers-detail-card" aria-label="Recent activity">
                <h3>Recent Activity</h3>
                <div style="overflow-x: auto; margin-top: 12px;">
                    <table class="customers-detail-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Label</th>
                                <th>Candle Cash</th>
                                <th>Actor</th>
                                <th>Status</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($activity as $row)
                                <tr>
                                    <td>{{ $row['occurred_at_display'] ?? '—' }}</td>
                                    <td>{{ $row['type'] ?? '—' }}</td>
                                    <td>{{ $row['label'] ?? '—' }}</td>
                                    <td>
                                        @if($row['candle_cash_display'] !== null)
                                            {{ $row['candle_cash_display'] }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $row['actor'] ?? '—' }}</td>
                                    <td>{{ $row['status'] ?? '—' }}</td>
                                    <td>{{ $row['detail'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" style="text-align: center; color: rgba(15, 23, 42, 0.6); padding: 18px;">
                                        No recent activity recorded yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="customers-detail-card" aria-label="External profiles">
                <h3>External Profiles</h3>
                <div style="overflow-x: auto; margin-top: 12px;">
                    <table class="customers-detail-table">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Integration</th>
                                <th>Store</th>
                                <th>External ID</th>
                                <th>Last Activity</th>
                                <th>Legacy Growave Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($externalProfiles as $externalProfile)
                                <tr>
                                    <td>{{ $externalProfile->provider ?: '—' }}</td>
                                    <td>{{ $externalProfile->integration ?: '—' }}</td>
                                    <td>{{ $externalProfile->store_key ?: '—' }}</td>
                                    <td>{{ $externalProfile->external_customer_id ?: '—' }}</td>
                                    <td>{{ optional($externalProfile->last_activity_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td>{{ $externalProfile->points_balance !== null ? number_format((int) $externalProfile->points_balance) : '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" style="text-align: center; color: rgba(15, 23, 42, 0.6); padding: 18px;">
                                        No external profiles linked yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <script>
            (() => {
                const root = document.getElementById("shopify-customer-detail");
                if (!root) {
                    return;
                }

                function setText(selector, value) {
                    root.querySelectorAll(selector).forEach((node) => {
                        node.textContent = value;
                    });
                }

                function updateCustomer(customer) {
                    if (!customer || typeof customer !== "object") {
                        return;
                    }

                    if (typeof customer.display_name === "string") {
                        setText("[data-customer-display-name]", customer.display_name);
                    }

                    if (typeof customer.email_display === "string") {
                        setText("[data-customer-email-display]", customer.email_display);
                    }

                    if (typeof customer.phone_display === "string") {
                        setText("[data-customer-phone-display]", customer.phone_display);
                    }

                    if (typeof customer.updated_at_display === "string") {
                        setText("[data-customer-updated-display]", customer.updated_at_display);
                    }
                }

                function updateBalance(balanceDisplay) {
                    if (typeof balanceDisplay !== "string") {
                        return;
                    }

                    setText("[data-customer-balance-display]", balanceDisplay);
                }

                function clearFieldErrors(form) {
                    form.querySelectorAll("[data-error-for]").forEach((node) => {
                        node.textContent = "";
                    });

                    form.querySelectorAll("[aria-invalid=\"true\"]").forEach((node) => {
                        node.removeAttribute("aria-invalid");
                    });
                }

                function setFieldErrors(form, errors = {}) {
                    clearFieldErrors(form);

                    Object.entries(errors).forEach(([field, messages]) => {
                        const value = Array.isArray(messages) ? String(messages[0] || "") : String(messages || "");
                        const input = form.querySelector(`[name="${field}"]`);
                        if (input) {
                            input.setAttribute("aria-invalid", "true");
                        }

                        const target = form.querySelector(`[data-error-for="${field}"]`);
                        if (target) {
                            target.textContent = value;
                        }
                    });
                }

                function setFormFeedback(form, tone, message) {
                    const feedback = form.querySelector("[data-form-feedback]");
                    if (!feedback) {
                        return;
                    }

                    if (!message) {
                        feedback.hidden = true;
                        feedback.textContent = "";
                        feedback.classList.remove("is-success", "is-warning");
                        return;
                    }

                    feedback.hidden = false;
                    feedback.textContent = message;
                    feedback.classList.toggle("is-success", tone === "success");
                    feedback.classList.toggle("is-warning", tone !== "success");
                }

                function firstErrorMessage(errors) {
                    if (!errors || typeof errors !== "object") {
                        return null;
                    }

                    for (const value of Object.values(errors)) {
                        if (Array.isArray(value) && value.length > 0) {
                            return value[0];
                        }
                    }

                    return null;
                }

                function serializeForm(form) {
                    const payload = {};
                    const formData = new FormData(form);

                    for (const [key, value] of formData.entries()) {
                        if (key === "_token" || key === "_method") {
                            continue;
                        }

                        payload[key] = typeof value === "string" ? value : value;
                    }

                    return payload;
                }

                function setButtonBusy(form, busy) {
                    const button = form.querySelector('button[type="submit"]');
                    if (!button) {
                        return;
                    }

                    if (!button.dataset.originalLabel) {
                        button.dataset.originalLabel = button.textContent.trim();
                    }

                    button.disabled = busy;
                    button.textContent = busy ? "Saving..." : button.dataset.originalLabel;
                }

                function authFailureMessage(status, fallbackMessage) {
                    const messages = {
                        missing_api_auth: "Shopify Admin verification is unavailable. Reload this customer from Shopify Admin and try again.",
                        invalid_session_token: "Shopify Admin verification failed. Reload this customer from Shopify Admin and try again.",
                        expired_session_token: "Your Shopify Admin session expired. Reload this customer from Shopify Admin and try again.",
                    };

                    return messages[status] || fallbackMessage || null;
                }

                async function resolveEmbeddedAuthHeaders() {
                    if (!window.shopify || typeof window.shopify.idToken !== "function") {
                        throw new Error(
                            authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable."),
                        );
                    }

                    const headers = {
                        "Accept": "application/json",
                        "Content-Type": "application/json",
                    };

                    let sessionToken = null;

                    try {
                        sessionToken = await Promise.race([
                            Promise.resolve(window.shopify.idToken()),
                            new Promise((resolve) => window.setTimeout(() => resolve(null), 1500)),
                        ]);
                    } catch (error) {
                        throw new Error(
                            authFailureMessage("invalid_session_token", "Shopify Admin verification failed."),
                        );
                    }

                    if (typeof sessionToken !== "string" || sessionToken.trim() === "") {
                        throw new Error(
                            authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable."),
                        );
                    }

                    headers.Authorization = `Bearer ${sessionToken.trim()}`;

                    return headers;
                }

                async function submitMutationForm(event) {
                    event.preventDefault();

                    const form = event.currentTarget;
                    const endpoint = form.dataset.apiEndpoint;
                    const method = (form.dataset.apiMethod || "POST").toUpperCase();

                    if (!endpoint) {
                        form.submit();
                        return;
                    }

                    clearFieldErrors(form);
                    setFormFeedback(form, "success", "");
                    setButtonBusy(form, true);

                    try {
                        const headers = await resolveEmbeddedAuthHeaders();
                        const response = await fetch(endpoint, {
                            method,
                            headers,
                            credentials: "same-origin",
                            body: JSON.stringify(serializeForm(form)),
                        });

                        const payload = await response.json().catch(() => ({
                            ok: false,
                            message: "Unexpected response from Backstage.",
                        }));

                        if (!response.ok || !payload.ok) {
                            const authMessage = authFailureMessage(payload.status, payload.message);
                            if (payload.errors) {
                                setFieldErrors(form, payload.errors);
                            }

                            const message = authMessage || firstErrorMessage(payload.errors) || payload.message || "Request failed.";
                            setFormFeedback(form, "warning", message);
                            if (authMessage) {
                                window.ForestryEmbeddedApp?.showToast?.(message, "error");
                            }
                            return;
                        }

                        if (payload.data?.customer) {
                            updateCustomer(payload.data.customer);
                        }

                        if (payload.data?.balance_display) {
                            updateBalance(payload.data.balance_display);
                        }

                        if (form.dataset.resetOnSuccess === "true") {
                            form.reset();
                        }

                        const tone = payload.notice_style === "warning" ? "warning" : "success";
                        setFormFeedback(form, tone, payload.message || "Saved.");
                        window.ForestryEmbeddedApp?.showToast?.(payload.message || "Saved.", tone === "success" ? "success" : "error");
                    } catch (error) {
                        const message = error instanceof Error ? error.message : "Request failed.";
                        setFormFeedback(form, "warning", message);
                        if (message !== "Request failed.") {
                            window.ForestryEmbeddedApp?.showToast?.(message, "error");
                        }
                    } finally {
                        setButtonBusy(form, false);
                    }
                }

                root.querySelectorAll("[data-embedded-mutation-form]").forEach((form) => {
                    form.addEventListener("submit", submitMutationForm);
                });
            })();
        </script>
    @endif
</x-shopify.customers-layout>
