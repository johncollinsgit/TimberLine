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
        $deferredBootstrap = (array) ($customerDetailDeferredBootstrap ?? []);
        $emailConsent = (array) ($consent['email'] ?? []);
        $smsConsent = (array) ($consent['sms'] ?? []);
        $emailDisplay = $marketingProfile->email ?: 'Email not set';
        $phoneDisplay = $marketingProfile->phone ?: 'Phone not set';
        $emailMissing = blank($marketingProfile->email);
        $phoneMissing = blank($marketingProfile->phone);
        $activityCount = count($activity);
        $externalProfilesCount = $externalProfiles instanceof \Illuminate\Support\Collection ? $externalProfiles->count() : count((array) $externalProfiles);
        $lastActivityDisplay = (string) ($summary['last_activity_display'] ?? 'Loading recent activity…');
        $activitySummary = $activityCount > 0
            ? number_format($activityCount) . ' recent item' . ($activityCount === 1 ? '' : 's') . ' across rewards, adjustments, and messaging activity.'
            : 'Loading recent items across rewards, adjustments, and messaging activity.';
        $externalProfilesSummary = $externalProfilesCount > 0
            ? number_format($externalProfilesCount) . ' linked provider profile' . ($externalProfilesCount === 1 ? '' : 's') . ' currently attached to this customer.'
            : 'Loading linked source records…';
    @endphp

    <style>
        .customers-detail-shell {
            --detail-surface: rgba(255, 255, 255, 0.92);
            --detail-border: rgba(15, 23, 42, 0.08);
            --detail-muted: rgba(15, 23, 42, 0.56);
            --detail-soft: rgba(241, 245, 249, 0.82);
            display: grid;
            gap: 24px;
            padding: 12px 0 28px;
        }

        .customers-detail-section {
            background: var(--detail-surface);
            border: 1px solid var(--detail-border);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }

        .customers-detail-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .customers-detail-eyebrow {
            margin: 0 0 10px;
            font-size: 12px;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .customers-detail-title {
            margin: 0;
            font-size: clamp(2rem, 4vw, 2.85rem);
            line-height: 1;
            letter-spacing: -0.05em;
            font-weight: 700;
            color: #172036;
        }

        .customers-detail-subtitle {
            margin: 12px 0 0;
            max-width: 74ch;
            font-size: 1.08rem;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.62);
        }

        .customers-detail-hero {
            display: grid;
            gap: 22px;
            grid-template-columns: minmax(0, 1.7fr) minmax(280px, 0.95fr);
            align-items: end;
            background:
                radial-gradient(circle at top right, rgba(15, 143, 97, 0.08), transparent 34%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(247, 249, 252, 0.98));
        }

        .customers-detail-hero-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .customers-detail-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .customers-detail-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.85);
            color: #243047;
            font-size: 12px;
            font-weight: 600;
        }

        .customers-detail-chip.is-muted {
            color: rgba(15, 23, 42, 0.52);
            background: rgba(255, 255, 255, 0.65);
        }

        .customers-detail-chip.is-positive {
            border-color: rgba(15, 143, 97, 0.22);
            background: rgba(15, 143, 97, 0.12);
            color: #0d6f4d;
        }

        .customers-detail-chip.is-caution {
            border-color: rgba(217, 119, 6, 0.22);
            background: rgba(245, 158, 11, 0.12);
            color: #b45309;
        }

        .customers-detail-sidecard {
            border-radius: 22px;
            padding: 20px 20px 22px;
            background: rgba(250, 251, 253, 0.9);
            border: 1px solid rgba(15, 23, 42, 0.08);
            display: grid;
            gap: 16px;
        }

        .customers-detail-kicker {
            margin: 0;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.22em;
            color: rgba(15, 23, 42, 0.48);
        }

        .customers-detail-sidegrid {
            display: grid;
            gap: 14px;
        }

        .customers-detail-sideitem {
            display: grid;
            gap: 4px;
        }

        .customers-detail-sideitem-label {
            font-size: 12px;
            font-weight: 600;
            color: rgba(15, 23, 42, 0.52);
        }

        .customers-detail-sideitem-value {
            font-size: 15px;
            line-height: 1.45;
            color: #152033;
        }

        .customers-detail-action-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 999px;
            text-decoration: none;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255, 255, 255, 0.92);
            color: #172036;
            font-size: 14px;
            font-weight: 650;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .customers-detail-action-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 24px rgba(15, 23, 42, 0.08);
            border-color: rgba(15, 23, 42, 0.18);
        }

        .customers-detail-stat-strip {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .customers-detail-stat {
            background: rgba(255, 255, 255, 0.82);
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: 20px;
            padding: 18px 18px 16px;
            min-height: 128px;
            display: grid;
            gap: 10px;
            align-content: start;
        }

        .customers-detail-stat-label {
            margin: 0;
            font-size: 11px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.48);
        }

        .customers-detail-stat-value {
            margin: 0;
            font-size: 1.9rem;
            line-height: 1;
            letter-spacing: -0.05em;
            font-weight: 700;
            color: #172036;
        }

        .customers-detail-stat-detail {
            margin: 0;
            font-size: 13px;
            line-height: 1.55;
            color: rgba(15, 23, 42, 0.62);
        }

        .customers-detail-main {
            display: grid;
            gap: 24px;
            grid-template-columns: minmax(0, 1.35fr) minmax(320px, 0.92fr);
            align-items: start;
        }

        .customers-detail-stack {
            display: grid;
            gap: 24px;
        }

        .customers-detail-grid {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .customers-detail-card {
            border-radius: 22px;
            border: 1px solid rgba(15, 23, 42, 0.07);
            background: rgba(255, 255, 255, 0.86);
            padding: 22px;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.04);
        }

        .customers-detail-card.is-compact {
            padding: 18px 20px;
        }

        .customers-detail-card.is-full {
            grid-column: 1 / -1;
        }

        .customers-detail-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 14px;
        }

        .customers-detail-card-title {
            margin: 0;
            font-size: 1rem;
            letter-spacing: -0.02em;
            color: #172036;
            font-weight: 650;
        }

        .customers-detail-card-copy {
            margin: 6px 0 0;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.58);
        }

        .customers-detail-mini-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .customers-detail-mini-item {
            display: grid;
            gap: 6px;
            padding: 14px 16px;
            border-radius: 16px;
            background: var(--detail-soft);
            border: 1px solid rgba(15, 23, 42, 0.05);
        }

        .customers-detail-mini-label {
            font-size: 11px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.46);
        }

        .customers-detail-mini-value {
            font-size: 15px;
            line-height: 1.45;
            color: #172036;
            font-weight: 600;
        }

        .customers-detail-flow {
            display: grid;
            gap: 18px;
        }

        .customers-detail-form {
            display: grid;
            gap: 14px;
            margin-top: 14px;
        }

        .customers-detail-form-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .customers-detail-form-field {
            display: grid;
            gap: 7px;
        }

        .customers-detail-form-field.is-full {
            grid-column: 1 / -1;
        }

        .customers-detail-form label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .customers-detail-form input,
        .customers-detail-form textarea,
        .customers-detail-form select {
            width: 100%;
            box-sizing: border-box;
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255, 255, 255, 0.98);
            padding: 12px 14px;
            font-size: 14px;
            color: rgba(15, 23, 42, 0.8);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .customers-detail-form input:focus,
        .customers-detail-form textarea:focus,
        .customers-detail-form select:focus {
            outline: none;
            border-color: rgba(15, 143, 97, 0.32);
            box-shadow: 0 0 0 4px rgba(15, 143, 97, 0.08);
        }

        .customers-detail-form-helper {
            margin: 0;
            font-size: 12px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.52);
        }

        .customers-detail-button-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .customers-detail-meta {
            margin-top: 6px;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.65);
        }

        .customers-detail-card strong {
            font-weight: 600;
        }

        .customers-detail-table {
            width: 100%;
            border-collapse: collapse;
        }

        .customers-detail-table th {
            text-align: left;
            padding: 12px 14px;
            font-size: 10px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(244, 247, 250, 0.9);
        }

        .customers-detail-table td {
            padding: 14px;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.72);
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            white-space: nowrap;
        }

        .customers-detail-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 650;
            cursor: pointer;
            color: rgba(15, 23, 42, 0.8);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
        }

        .customers-detail-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(15, 23, 42, 0.08);
        }

        .customers-detail-button.is-primary {
            border-color: rgba(15, 143, 97, 0.32);
            background: linear-gradient(180deg, rgba(15, 143, 97, 0.16), rgba(15, 143, 97, 0.11));
            color: #0d6f4d;
        }

        .customers-detail-button[disabled] {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .customers-detail-notice {
            border-radius: 16px;
            padding: 12px 14px;
            font-size: 13px;
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

        .customers-detail-table-wrap {
            overflow-x: auto;
            margin-top: 10px;
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.06);
            background: rgba(255, 255, 255, 0.9);
        }

        .customers-detail-deferred-section {
            position: relative;
            min-height: 320px;
        }

        .customers-detail-deferred-section.is-loading::after,
        .customers-detail-deferred-section.is-refreshing::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0));
        }

        .customers-detail-deferred-shell {
            display: grid;
            gap: 16px;
        }

        .customers-detail-deferred-placeholder {
            display: grid;
            gap: 12px;
            min-height: 252px;
            align-content: start;
        }

        .customers-detail-skeleton-row {
            height: 46px;
            border-radius: 16px;
            background: linear-gradient(90deg, rgba(241, 245, 249, 0.88) 0%, rgba(255, 255, 255, 0.98) 48%, rgba(241, 245, 249, 0.88) 100%);
            background-size: 220% 100%;
            animation: customers-detail-skeleton 1.2s ease-in-out infinite;
        }

        .customers-detail-skeleton-row.is-short {
            max-width: 46%;
        }

        .customers-detail-skeleton-row.is-mid {
            max-width: 72%;
        }

        .customers-detail-deferred-error {
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .customers-detail-deferred-error[hidden] {
            display: none !important;
        }

        .customers-detail-empty {
            text-align: center;
            color: rgba(15, 23, 42, 0.58);
            padding: 22px;
        }

        .customers-detail-sideitem-value.is-loading,
        .customers-detail-card-copy.is-loading {
            color: rgba(15, 23, 42, 0.46);
        }

        @keyframes customers-detail-skeleton {
            0% { background-position: 100% 0; }
            100% { background-position: -100% 0; }
        }

        @media (max-width: 1100px) {
            .customers-detail-hero,
            .customers-detail-main {
                grid-template-columns: 1fr;
            }

            .customers-detail-stat-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 840px) {
            .customers-detail-grid,
            .customers-detail-form-grid,
            .customers-detail-mini-grid {
                grid-template-columns: 1fr;
            }

            .customers-detail-stat-strip {
                grid-template-columns: 1fr;
            }

            .customers-detail-shell {
                gap: 18px;
                padding: 8px 0 20px;
            }

            .customers-detail-section,
            .customers-detail-card {
                padding: 18px;
                border-radius: 20px;
            }
        }
    </style>

    @if(! $authorized)
        <div class="customers-detail-shell">
            <section class="customers-detail-section">
                <p class="customers-detail-eyebrow">Customer Detail</p>
                <h2 class="customers-detail-title">Customer detail unavailable</h2>
                <p class="customers-detail-subtitle">
                    Shopify context status: {{ str_replace('_', ' ', $status ?? 'unknown') }}. Open this app from Shopify Admin to restore the operational tools on this page.
                </p>
            </section>

            @if(is_array($notice))
                <div class="customers-detail-notice {{ ($notice['style'] ?? 'success') === 'warning' ? 'is-warning' : 'is-success' }}">
                    {{ $notice['message'] ?? 'Update saved.' }}
                </div>
            @endif

            <section class="customers-detail-section" aria-label="Customer detail unavailable">
                <div class="customers-detail-card is-full">
                    <div class="customers-detail-card-header">
                        <div>
                            <h3 class="customers-detail-card-title">Context Required</h3>
                            <p class="customers-detail-card-copy">This customer page can only render actionable widgets once Shopify Admin context is verified.</p>
                        </div>
                    </div>
                    <div class="customers-detail-flow">
                        <p class="customers-detail-form-helper">Open this app from Shopify Admin or reopen this customer from Manage customers inside the embedded Shopify Admin app.</p>
                        @if(($status ?? null) === 'invalid_hmac')
                            <p class="customers-detail-form-helper">The signed Shopify query on this request could not be verified, so the page intentionally suppressed every customer widget and form.</p>
                        @elseif(($status ?? null) === 'open_from_shopify')
                            <p class="customers-detail-form-helper">This route was opened without the signed Shopify query or embedded session needed to power the customer widgets.</p>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    @else
        @php
            $giftIntentOptions = $giftIntentOptions ?? [];
            $giftOriginOptions = $giftOriginOptions ?? [];
            $identityHasErrors = $errors->has('first_name') || $errors->has('last_name') || $errors->has('email') || $errors->has('phone');
            $adjustmentHasErrors = $errors->has('direction') || $errors->has('amount') || $errors->has('reason');
            $consentHasErrors = $errors->has('channel') || $errors->has('consented') || $errors->has('notes');
            $sendHasErrors = $errors->has('amount') || $errors->has('reason') || $errors->has('message') || $errors->has('gift_intent') || $errors->has('gift_origin') || $errors->has('campaign_key') || $errors->has('sender_key');
            $messageHasErrors = $errors->has('channel') || $errors->has('message') || $errors->has('sender_key');
        @endphp

        <div
            id="shopify-customer-detail"
            class="customers-detail-shell"
            data-identity-endpoint="{{ $mutationBootstrap['identityEndpoint'] ?? '' }}"
            data-adjustment-endpoint="{{ $mutationBootstrap['adjustmentEndpoint'] ?? '' }}"
            data-send-candle-cash-endpoint="{{ $mutationBootstrap['sendCandleCashEndpoint'] ?? '' }}"
            data-deferred-sections-endpoint="{{ $deferredBootstrap['sectionsEndpoint'] ?? '' }}"
            data-profile-id="{{ $deferredBootstrap['profileId'] ?? '' }}"
            data-perf-debug="{{ ! empty($deferredBootstrap['perfDebug']) ? 'true' : 'false' }}"
        >
            <section class="customers-detail-section customers-detail-hero" aria-label="Customer profile header">
                <div>
                    <p class="customers-detail-eyebrow">Customer Detail</p>
                    <div class="customers-detail-section-header" style="margin-bottom: 0;">
                        <div>
                            <h2 class="customers-detail-title" data-customer-display-name>{{ $customerDisplayName }}</h2>
                            <p class="customers-detail-subtitle">
                                Customer Detail for one canonical marketing profile, with identity, loyalty, messaging, and recent source activity in one workspace.
                            </p>
                        </div>
                    </div>
                    <div class="customers-detail-chip-row customers-detail-hero-meta">
                        <span class="customers-detail-chip">Profile #{{ $marketingProfile->id }}</span>
                        <span class="customers-detail-chip {{ $emailMissing ? 'is-muted' : 'is-positive' }}" data-customer-email-display>{{ $emailDisplay }}</span>
                        <span class="customers-detail-chip {{ $phoneMissing ? 'is-muted' : 'is-positive' }}" data-customer-phone-display>{{ $phoneDisplay }}</span>
                        <span class="customers-detail-chip {{ ! empty($summary['candle_club_active']) ? 'is-positive' : 'is-muted' }}">
                            {{ ! empty($summary['candle_club_active']) ? 'Candle Club active' : 'Candle Club inactive' }}
                        </span>
                        <span class="customers-detail-chip {{ ! empty($summary['birthday_tracked']) ? 'is-positive' : 'is-muted' }}">
                            {{ ! empty($summary['birthday_tracked']) ? 'Birthday tracked' : 'Birthday missing' }}
                        </span>
                        <span class="customers-detail-chip {{ ! empty($summary['wholesale_eligible']) ? 'is-caution' : 'is-muted' }}">
                            {{ ! empty($summary['wholesale_eligible']) ? 'Wholesale eligible' : 'Retail only' }}
                        </span>
                    </div>
                </div>

                <aside class="customers-detail-sidecard">
                    <p class="customers-detail-kicker">Profile status</p>
                    <div class="customers-detail-sidegrid">
                        <div class="customers-detail-sideitem">
                            <span class="customers-detail-sideitem-label">Last activity</span>
                            <span class="customers-detail-sideitem-value {{ str_contains(strtolower($lastActivityDisplay), 'loading') ? 'is-loading' : '' }}" data-customer-last-activity-display>{{ $lastActivityDisplay }}</span>
                        </div>
                        <div class="customers-detail-sideitem">
                            <span class="customers-detail-sideitem-label">Email status</span>
                            <span class="customers-detail-sideitem-value" data-customer-email-consent-display>{{ $emailConsent['label'] ?? 'Not consented' }}</span>
                        </div>
                        <div class="customers-detail-sideitem">
                            <span class="customers-detail-sideitem-label">SMS status</span>
                            <span class="customers-detail-sideitem-value" data-customer-sms-consent-display>{{ $smsConsent['label'] ?? 'Not consented' }}</span>
                        </div>
                        <div class="customers-detail-sideitem">
                            <span class="customers-detail-sideitem-label">Updated</span>
                            <span class="customers-detail-sideitem-value" data-customer-updated-display>{{ optional($marketingProfile->updated_at)->format('Y-m-d H:i') ?: '—' }}</span>
                        </div>
                    </div>
                    <a class="customers-detail-action-link" href="{{ $actionUrlGenerator->url('customers.manage', [], request()) }}">Back to customers</a>
                </aside>
            </section>

            @if(is_array($notice))
                <div class="customers-detail-notice {{ ($notice['style'] ?? 'success') === 'warning' ? 'is-warning' : 'is-success' }}">
                    {{ $notice['message'] ?? 'Update saved.' }}
                </div>
            @endif

            <section class="customers-detail-stat-strip" aria-label="Customer snapshot metrics">
                <article class="customers-detail-stat">
                    <p class="customers-detail-stat-label">Candle Cash</p>
                    <p class="customers-detail-stat-value" data-customer-balance-display>{{ $summary['candle_cash_display'] ?? '0' }}</p>
                    <p class="customers-detail-stat-detail">Current balance available for rewards and gifting workflows.</p>
                </article>
                <article class="customers-detail-stat">
                    <p class="customers-detail-stat-label">Candle Club</p>
                    <p class="customers-detail-stat-value">{{ ! empty($summary['candle_club_active']) ? 'Active' : 'Off' }}</p>
                    <p class="customers-detail-stat-detail">Membership status for recurring loyalty participation.</p>
                </article>
                <article class="customers-detail-stat">
                    <p class="customers-detail-stat-label">Rewards actions</p>
                    <p class="customers-detail-stat-value">{{ number_format((int) ($summary['rewards_actions_count'] ?? 0)) }}</p>
                    <p class="customers-detail-stat-detail">Tracked reward-side actions and operational touches.</p>
                </article>
                <article class="customers-detail-stat">
                    <p class="customers-detail-stat-label">Birthday</p>
                    <p class="customers-detail-stat-value">{{ ! empty($summary['birthday_tracked']) ? 'Ready' : 'Missing' }}</p>
                    <p class="customers-detail-stat-detail">Birthday completion state for celebration and redemption flows.</p>
                </article>
                <article class="customers-detail-stat">
                    <p class="customers-detail-stat-label">Wholesale</p>
                    <p class="customers-detail-stat-value">{{ ! empty($summary['wholesale_eligible']) ? 'Eligible' : 'Standard' }}</p>
                    <p class="customers-detail-stat-detail">Commercial purchasing flag used across backstage operations.</p>
                </article>
            </section>

            <section class="customers-detail-main" aria-label="Customer operational workspace">
                <div class="customers-detail-stack">
                    <article class="customers-detail-section">
                        <div class="customers-detail-section-header">
                            <div>
                                <p class="customers-detail-eyebrow">Identity</p>
                                <h3 class="customers-detail-card-title">Identity and loyalty profile</h3>
                                <p class="customers-detail-card-copy">Core profile information and current rewards posture in one place.</p>
                            </div>
                        </div>

                        <div class="customers-detail-mini-grid" style="margin-bottom: 20px;">
                            <div class="customers-detail-mini-item">
                                <span class="customers-detail-mini-label">Email</span>
                                <span class="customers-detail-mini-value" data-customer-email-display>{{ $emailDisplay }}</span>
                            </div>
                            <div class="customers-detail-mini-item">
                                <span class="customers-detail-mini-label">Phone</span>
                                <span class="customers-detail-mini-value" data-customer-phone-display>{{ $phoneDisplay }}</span>
                            </div>
                            <div class="customers-detail-mini-item">
                                <span class="customers-detail-mini-label">Created</span>
                                <span class="customers-detail-mini-value">{{ optional($marketingProfile->created_at)->format('Y-m-d H:i') ?: '—' }}</span>
                            </div>
                            <div class="customers-detail-mini-item">
                                <span class="customers-detail-mini-label">Last updated</span>
                                <span class="customers-detail-mini-value" data-customer-updated-display>{{ optional($marketingProfile->updated_at)->format('Y-m-d H:i') ?: '—' }}</span>
                            </div>
                            <div class="customers-detail-mini-item">
                                <span class="customers-detail-mini-label">Candle Cash balance</span>
                                <span class="customers-detail-mini-value" data-customer-balance-display>{{ $summary['candle_cash_display'] ?? '0' }}</span>
                            </div>
                            <div class="customers-detail-mini-item">
                                <span class="customers-detail-mini-label">Profile state</span>
                                <span class="customers-detail-mini-value">
                                    {{ ! empty($statuses['candle_club']) ? 'Candle Club active' : 'Candle Club inactive' }}
                                    ·
                                    {{ ! empty($statuses['wholesale']) ? 'Wholesale eligible' : 'Retail only' }}
                                </span>
                            </div>
                        </div>

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
                            <div class="customers-detail-form-grid">
                                <div class="customers-detail-form-field">
                                    <label for="customer-first-name">First name</label>
                                    <input id="customer-first-name" type="text" name="first_name" value="{{ old('first_name', $marketingProfile->first_name) }}" placeholder="First name" />
                                    <p class="customers-detail-field-error" data-error-for="first_name">{{ $errors->first('first_name') }}</p>
                                </div>
                                <div class="customers-detail-form-field">
                                    <label for="customer-last-name">Last name</label>
                                    <input id="customer-last-name" type="text" name="last_name" value="{{ old('last_name', $marketingProfile->last_name) }}" placeholder="Last name" />
                                    <p class="customers-detail-field-error" data-error-for="last_name">{{ $errors->first('last_name') }}</p>
                                </div>
                                <div class="customers-detail-form-field">
                                    <label for="customer-email">Email</label>
                                    <input id="customer-email" type="email" name="email" value="{{ old('email', $marketingProfile->email) }}" placeholder="Email" />
                                    <p class="customers-detail-field-error" data-error-for="email">{{ $errors->first('email') }}</p>
                                </div>
                                <div class="customers-detail-form-field">
                                    <label for="customer-phone">Phone</label>
                                    <input id="customer-phone" type="text" name="phone" value="{{ old('phone', $marketingProfile->phone) }}" placeholder="Phone" />
                                    <p class="customers-detail-field-error" data-error-for="phone">{{ $errors->first('phone') }}</p>
                                </div>
                            </div>
                            <div class="customers-detail-button-row">
                                <p class="customers-detail-form-helper">Use this form to clean up identity data without leaving the customer workspace.</p>
                                <button type="submit" class="customers-detail-button is-primary">Save identity</button>
                            </div>
                            <div
                                class="customers-detail-notice {{ $identityHasErrors ? 'is-warning' : 'is-success' }}"
                                data-form-feedback
                                @if(! $identityHasErrors) hidden @endif
                            >
                                {{ $errors->first('first_name') ?: $errors->first('last_name') ?: $errors->first('email') ?: $errors->first('phone') }}
                            </div>
                        </form>
                    </article>

                    <article class="customers-detail-section">
                        <div class="customers-detail-section-header">
                            <div>
                                <p class="customers-detail-eyebrow">Operations</p>
                                <h3 class="customers-detail-card-title">Candle Cash tools</h3>
                                <p class="customers-detail-card-copy">Operational adjustments and one-off gifting live together so the balance workflow stays easy to scan.</p>
                            </div>
                        </div>

                        <div class="customers-detail-grid">
                            <div class="customers-detail-card">
                                <div class="customers-detail-card-header">
                                    <div>
                                        <h4 class="customers-detail-card-title">Candle Cash Adjustment</h4>
                                        <p class="customers-detail-card-copy">Manual changes are logged in activity and require a reason.</p>
                                    </div>
                                </div>
                                <form
                                    method="POST"
                                    action="{{ $customerFormActions['candle_cash_adjust'] ?? '#' }}"
                                    class="customers-detail-form"
                                    data-embedded-mutation-form
                                    data-api-endpoint="{{ $mutationBootstrap['adjustmentEndpoint'] ?? '' }}"
                                    data-reset-on-success="true"
                                >
                                    @csrf
                                    <div class="customers-detail-form-grid">
                                        <div class="customers-detail-form-field">
                                            <label for="adjustment-direction">Adjustment type</label>
                                            <select id="adjustment-direction" name="direction">
                                                <option value="add" @selected(old('direction') === 'add')>Add Candle Cash</option>
                                                <option value="subtract" @selected(old('direction') === 'subtract')>Subtract Candle Cash</option>
                                            </select>
                                            <p class="customers-detail-field-error" data-error-for="direction">{{ $errors->first('direction') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field">
                                            <label for="adjustment-amount">Amount</label>
                                            <input id="adjustment-amount" type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" placeholder="Amount" />
                                            <p class="customers-detail-field-error" data-error-for="amount">{{ $errors->first('amount') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field is-full">
                                            <label for="adjustment-reason">Reason</label>
                                            <input id="adjustment-reason" type="text" name="reason" value="{{ old('reason') }}" placeholder="Reason for adjustment" />
                                            <p class="customers-detail-field-error" data-error-for="reason">{{ $errors->first('reason') }}</p>
                                        </div>
                                    </div>
                                    <div class="customers-detail-button-row">
                                        <p class="customers-detail-form-helper">Positive additions can text the rewards link automatically when phone + SMS consent are present.</p>
                                        <button type="submit" class="customers-detail-button is-primary">Apply adjustment</button>
                                    </div>
                                    <div
                                        class="customers-detail-notice is-warning"
                                        data-form-feedback
                                        @if(! $adjustmentHasErrors) hidden @endif
                                    >
                                        {{ $errors->first('direction') ?: $errors->first('amount') ?: $errors->first('reason') }}
                                    </div>
                                </form>
                            </div>

                            <div class="customers-detail-card">
                                <div class="customers-detail-card-header">
                                    <div>
                                        <h4 class="customers-detail-card-title">Send Candle Cash</h4>
                                        <p class="customers-detail-card-copy">Reward-style crediting with optional campaign context and follow-up SMS.</p>
                                    </div>
                                </div>
                                <form
                                    method="POST"
                                    action="{{ $customerFormActions['candle_cash_send'] ?? '#' }}"
                                    class="customers-detail-form"
                                    data-embedded-mutation-form
                                    data-api-endpoint="{{ $mutationBootstrap['sendCandleCashEndpoint'] ?? '' }}"
                                    data-reset-on-success="true"
                                >
                                    @csrf
                                    <div class="customers-detail-form-grid">
                                        <div class="customers-detail-form-field">
                                            <label for="gift-amount">Amount</label>
                                            <input id="gift-amount" type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" placeholder="Amount" />
                                            <p class="customers-detail-field-error" data-error-for="amount">{{ $errors->first('amount') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field">
                                            <label for="gift-sender">SMS sender</label>
                                            <select id="gift-sender" name="sender_key">
                                                @foreach($smsSenders as $sender)
                                                    <option value="{{ $sender['key'] }}" @selected($selectedSmsSenderKey === $sender['key']) @disabled(empty($sender['sendable']))>
                                                        {{ $sender['label'] }} · {{ $sender['type'] }} · {{ $sender['status'] }}{{ empty($sender['sendable']) ? ' (not sendable yet)' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <p class="customers-detail-field-error" data-error-for="sender_key">{{ $errors->first('sender_key') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field is-full">
                                            <label for="gift-reason">Reason</label>
                                            <input id="gift-reason" type="text" name="reason" value="{{ old('reason') }}" placeholder="Reason for sending" />
                                            <p class="customers-detail-field-error" data-error-for="reason">{{ $errors->first('reason') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field">
                                            <label for="gift-intent">Gift intent</label>
                                            <select id="gift-intent" name="gift_intent">
                                                <option value="">Select an intent</option>
                                                @foreach($giftIntentOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected(old('gift_intent') === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <p class="customers-detail-field-error" data-error-for="gift_intent">{{ $errors->first('gift_intent') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field">
                                            <label for="gift-origin">Gift origin</label>
                                            <select id="gift-origin" name="gift_origin">
                                                <option value="">Select an origin</option>
                                                @foreach($giftOriginOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected(old('gift_origin') === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <p class="customers-detail-field-error" data-error-for="gift_origin">{{ $errors->first('gift_origin') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field is-full">
                                            <label for="gift-campaign">Campaign key</label>
                                            <input id="gift-campaign" type="text" name="campaign_key" value="{{ old('campaign_key') }}" placeholder="Campaign key" />
                                            <p class="customers-detail-field-error" data-error-for="campaign_key">{{ $errors->first('campaign_key') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field is-full">
                                            <label for="gift-message">Optional SMS message</label>
                                            <textarea id="gift-message" name="message" rows="3" placeholder="Optional message to send after crediting Candle Cash">{{ old('message') }}</textarea>
                                            <p class="customers-detail-field-error" data-error-for="message">{{ $errors->first('message') }}</p>
                                        </div>
                                    </div>
                                    @if(! $smsSupported)
                                        <p class="customers-detail-form-helper">SMS messaging is disabled in this environment.</p>
                                    @elseif(! $smsHasPhone)
                                        <p class="customers-detail-form-helper">SMS message will not send because there is no phone on file.</p>
                                    @elseif(! $smsConsented)
                                        <p class="customers-detail-form-helper">SMS message will not send because the customer has not consented.</p>
                                    @endif
                                    <div class="customers-detail-button-row">
                                        <p class="customers-detail-form-helper">This credits Candle Cash as a distinct reward action instead of a manual balance adjustment.</p>
                                        <button type="submit" class="customers-detail-button is-primary">Send Candle Cash</button>
                                    </div>
                                    <div
                                        class="customers-detail-notice is-warning"
                                        data-form-feedback
                                        @if(! $sendHasErrors) hidden @endif
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
                            </div>
                        </div>
                    </article>
                </div>

                <div class="customers-detail-stack">
                    <article class="customers-detail-section">
                        <div class="customers-detail-section-header">
                            <div>
                                <p class="customers-detail-eyebrow">Communications</p>
                                <h3 class="customers-detail-card-title">Consent and direct messaging</h3>
                                <p class="customers-detail-card-copy">Consent history and outbound messaging are grouped so compliance and communication stay aligned.</p>
                            </div>
                        </div>

                        <div class="customers-detail-flow">
                            <div class="customers-detail-card is-compact">
                                <div class="customers-detail-card-header">
                                    <div>
                                        <h4 class="customers-detail-card-title">Consent</h4>
                                        <p class="customers-detail-card-copy">Review latest state before updating opt-in preferences.</p>
                                    </div>
                                </div>
                                <div class="customers-detail-mini-grid" style="margin-bottom: 18px;">
                                    <div class="customers-detail-mini-item">
                                        <span class="customers-detail-mini-label">Email</span>
                                        <span class="customers-detail-mini-value" data-customer-email-consent-display>
                                            {{ $emailConsent['label'] ?? 'Not consented' }}
                                            @if(! empty($emailConsent['last_event']['occurred_at_display'] ?? null))
                                                · {{ $emailConsent['last_event']['occurred_at_display'] }}
                                            @endif
                                        </span>
                                    </div>
                                    <div class="customers-detail-mini-item">
                                        <span class="customers-detail-mini-label">SMS</span>
                                        <span class="customers-detail-mini-value" data-customer-sms-consent-display>
                                            {{ $smsConsent['label'] ?? 'Not consented' }}
                                            @if(! empty($smsConsent['last_event']['occurred_at_display'] ?? null))
                                                · {{ $smsConsent['last_event']['occurred_at_display'] }}
                                            @endif
                                        </span>
                                    </div>
                                </div>
                                <form
                                    method="POST"
                                    action="{{ $customerFormActions['consent'] ?? '#' }}"
                                    class="customers-detail-form"
                                    data-embedded-mutation-form
                                    data-api-endpoint="{{ $mutationBootstrap['consentEndpoint'] ?? '' }}"
                                >
                                    @csrf
                                    <div class="customers-detail-form-grid">
                                        <div class="customers-detail-form-field">
                                            <label for="consent-channel">Channel</label>
                                            <select id="consent-channel" name="channel">
                                                <option value="email">Email</option>
                                                <option value="sms">SMS</option>
                                                <option value="both">Email + SMS</option>
                                            </select>
                                            <p class="customers-detail-field-error" data-error-for="channel">{{ $errors->first('channel') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field">
                                            <label for="consent-state">Consent state</label>
                                            <select id="consent-state" name="consented">
                                                <option value="1">Consented</option>
                                                <option value="0">Not consented</option>
                                            </select>
                                            <p class="customers-detail-field-error" data-error-for="consented">{{ $errors->first('consented') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field is-full">
                                            <label for="consent-notes">Notes</label>
                                            <input id="consent-notes" type="text" name="notes" placeholder="Notes (optional)" />
                                            <p class="customers-detail-field-error" data-error-for="notes">{{ $errors->first('notes') }}</p>
                                        </div>
                                    </div>
                                    <div class="customers-detail-button-row">
                                        <p class="customers-detail-form-helper">Consent changes are intended for operational corrections and compliance cleanup.</p>
                                        <button type="submit" class="customers-detail-button is-primary">Save consent</button>
                                    </div>
                                    <div
                                        class="customers-detail-notice {{ $consentHasErrors ? 'is-warning' : 'is-success' }}"
                                        data-form-feedback
                                        @if(! $consentHasErrors) hidden @endif
                                    >
                                        {{ $errors->first('channel') ?: $errors->first('consented') ?: $errors->first('notes') }}
                                    </div>
                                </form>
                            </div>

                            <div class="customers-detail-card is-compact">
                                <div class="customers-detail-card-header">
                                    <div>
                                        <h4 class="customers-detail-card-title">Message Customer</h4>
                                        <p class="customers-detail-card-copy">Direct SMS outreach using the currently available senders and consent state.</p>
                                    </div>
                                </div>
                                <div class="customers-detail-mini-grid" style="margin-bottom: 18px;">
                                    <div class="customers-detail-mini-item">
                                        <span class="customers-detail-mini-label">SMS destination</span>
                                        <span class="customers-detail-mini-value">{{ $smsPhoneDisplay }}</span>
                                    </div>
                                    <div class="customers-detail-mini-item">
                                        <span class="customers-detail-mini-label">Message eligibility</span>
                                        <span class="customers-detail-mini-value" data-customer-sms-message-eligibility>{{ $smsConsentLabel }}</span>
                                    </div>
                                </div>
                                @if($smsSenders !== [])
                                    <p class="customers-detail-form-helper">
                                        Senders:
                                        @foreach($smsSenders as $index => $sender)
                                            {{ $index > 0 ? ' · ' : '' }}{{ $sender['label'] }} ({{ $sender['type'] }}, {{ $sender['status'] }}){{ ! empty($sender['is_default']) ? ' default' : '' }}
                                        @endforeach
                                    </p>
                                @endif
                                @if(! $smsSupported)
                                    <p class="customers-detail-form-helper">SMS sending is not enabled in this environment.</p>
                                @elseif(! $smsHasPhone)
                                    <p class="customers-detail-form-helper">Add a phone number to enable direct SMS.</p>
                                @elseif(! $smsConsented)
                                    <p class="customers-detail-form-helper">SMS consent is required before messages can be sent.</p>
                                @endif
                                <form
                                    method="POST"
                                    action="{{ $customerFormActions['message'] ?? '#' }}"
                                    class="customers-detail-form"
                                    data-embedded-mutation-form
                                    data-api-endpoint="{{ $mutationBootstrap['messageEndpoint'] ?? '' }}"
                                    data-reset-on-success="true"
                                >
                                    @csrf
                                    <div class="customers-detail-form-grid">
                                        <div class="customers-detail-form-field">
                                            <label for="message-channel">Channel</label>
                                            <select id="message-channel" name="channel">
                                                <option value="sms" @selected(old('channel', 'sms') === 'sms')>SMS</option>
                                            </select>
                                            <p class="customers-detail-field-error" data-error-for="channel">{{ $errors->first('channel') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field">
                                            <label for="message-sender">SMS sender</label>
                                            <select id="message-sender" name="sender_key">
                                                @foreach($smsSenders as $sender)
                                                    <option value="{{ $sender['key'] }}" @selected($selectedSmsSenderKey === $sender['key']) @disabled(empty($sender['sendable']))>
                                                        {{ $sender['label'] }} · {{ $sender['type'] }} · {{ $sender['status'] }}{{ empty($sender['sendable']) ? ' (not sendable yet)' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <p class="customers-detail-field-error" data-error-for="sender_key">{{ $errors->first('sender_key') }}</p>
                                        </div>
                                        <div class="customers-detail-form-field is-full">
                                            <label for="message-body">Message</label>
                                            <textarea id="message-body" name="message" rows="3" placeholder="Write a direct message">{{ old('message') }}</textarea>
                                            <p class="customers-detail-field-error" data-error-for="message">{{ $errors->first('message') }}</p>
                                        </div>
                                    </div>
                                    <div class="customers-detail-button-row">
                                        <p class="customers-detail-form-helper">Only SMS is available on this page today, and the send button respects environment support + phone presence.</p>
                                        <button type="submit" class="customers-detail-button is-primary" @disabled(! $smsSupported || ! $smsHasPhone)>Send message</button>
                                    </div>
                                    <div
                                        class="customers-detail-notice {{ $messageHasErrors ? 'is-warning' : 'is-success' }}"
                                        data-form-feedback
                                        @if(! $messageHasErrors) hidden @endif
                                    >
                                        {{ $errors->first('channel') ?: $errors->first('sender_key') ?: $errors->first('message') }}
                                    </div>
                                </form>
                            </div>
                        </div>
                    </article>

                    <article class="customers-detail-section">
                        <div class="customers-detail-section-header">
                            <div>
                                <p class="customers-detail-eyebrow">Completion</p>
                                <h3 class="customers-detail-card-title">Reward participation</h3>
                                <p class="customers-detail-card-copy">A compact read on the rewards surfaces this customer has completed or qualified for.</p>
                            </div>
                        </div>
                        <div class="customers-detail-chip-row">
                            <span class="customers-detail-chip {{ ! empty($statuses['candle_club']) ? 'is-positive' : 'is-muted' }}">Candle Club</span>
                            <span class="customers-detail-chip {{ ! empty($statuses['referral']) ? 'is-positive' : 'is-muted' }}">Referral</span>
                            <span class="customers-detail-chip {{ ! empty($statuses['review']) ? 'is-positive' : 'is-muted' }}">Review</span>
                            <span class="customers-detail-chip {{ ! empty($statuses['birthday']) ? 'is-positive' : 'is-muted' }}">Birthday</span>
                            <span class="customers-detail-chip {{ ! empty($statuses['wholesale']) ? 'is-positive' : 'is-muted' }}">Wholesale</span>
                        </div>
                    </article>
                </div>
            </section>

            <section class="customers-detail-section customers-detail-deferred-section is-loading" aria-label="Recent activity" data-customer-activity-shell>
                <div data-customer-activity-section class="customers-detail-deferred-shell">
                    <div class="customers-detail-section-header">
                        <div>
                            <p class="customers-detail-eyebrow">Recent activity</p>
                            <h3 class="customers-detail-card-title">Recent Activity</h3>
                            <p class="customers-detail-card-copy is-loading" data-customer-activity-summary>{{ $activitySummary }}</p>
                        </div>
                    </div>
                    <div class="customers-detail-deferred-placeholder" aria-hidden="true">
                        <div class="customers-detail-skeleton-row is-short"></div>
                        <div class="customers-detail-skeleton-row"></div>
                        <div class="customers-detail-skeleton-row"></div>
                        <div class="customers-detail-skeleton-row is-mid"></div>
                        <div class="customers-detail-skeleton-row"></div>
                    </div>
                </div>
                <div class="customers-detail-notice customers-detail-deferred-error is-warning" data-customer-activity-error hidden></div>
            </section>

            <section class="customers-detail-section customers-detail-deferred-section is-loading" aria-label="External profiles" data-customer-external-shell>
                <div data-customer-external-profiles-section class="customers-detail-deferred-shell">
                    <div class="customers-detail-section-header">
                        <div>
                            <p class="customers-detail-eyebrow">External profiles</p>
                            <h3 class="customers-detail-card-title">Linked source records</h3>
                            <p class="customers-detail-card-copy is-loading" data-customer-external-profiles-summary>{{ $externalProfilesSummary }}</p>
                        </div>
                    </div>
                    <div class="customers-detail-deferred-placeholder" aria-hidden="true">
                        <div class="customers-detail-skeleton-row"></div>
                        <div class="customers-detail-skeleton-row"></div>
                        <div class="customers-detail-skeleton-row is-mid"></div>
                        <div class="customers-detail-skeleton-row"></div>
                    </div>
                </div>
                <div class="customers-detail-notice customers-detail-deferred-error is-warning" data-customer-external-error hidden></div>
            </section>
        </div>

        <script>
            (() => {
                const root = document.getElementById("shopify-customer-detail");
                if (!root) {
                    return;
                }

                const deferredEndpoint = String(root.dataset.deferredSectionsEndpoint || "");
                const profileId = String(root.dataset.profileId || "");
                const perfDebug = root.dataset.perfDebug === "true";
                const activityShell = root.querySelector("[data-customer-activity-shell]");
                const activitySection = root.querySelector("[data-customer-activity-section]");
                const activityError = root.querySelector("[data-customer-activity-error]");
                const externalShell = root.querySelector("[data-customer-external-shell]");
                const externalSection = root.querySelector("[data-customer-external-profiles-section]");
                const externalError = root.querySelector("[data-customer-external-error]");
                const detailCache = (() => {
                    try {
                        return window.sessionStorage;
                    } catch (error) {
                        return null;
                    }
                })();
                const deferredCacheTtlMs = 60 * 1000;
                let sessionTokenPromise = null;
                let deferredController = null;
                let deferredRequestSequence = 0;

                function debug(message, payload = null) {
                    if (!perfDebug || typeof console === "undefined" || typeof console.debug !== "function") {
                        return;
                    }

                    if (payload === null) {
                        console.debug(`[customer-detail] ${message}`);
                        return;
                    }

                    console.debug(`[customer-detail] ${message}`, payload);
                }

                function mark(name) {
                    if (typeof window.performance?.mark === "function") {
                        window.performance.mark(name);
                    }
                }

                function measure(name, start, end) {
                    if (typeof window.performance?.measure !== "function") {
                        return;
                    }

                    try {
                        window.performance.measure(name, start, end);
                    } catch (error) {
                        // Ignore duplicate performance marks.
                    }
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

                function updateLastActivity(display) {
                    if (typeof display !== "string") {
                        return;
                    }

                    root.querySelectorAll("[data-customer-last-activity-display]").forEach((node) => {
                        node.textContent = display;
                        node.classList.remove("is-loading");
                    });
                }

                function updateBalance(balanceDisplay) {
                    if (typeof balanceDisplay !== "string") {
                        return;
                    }

                    setText("[data-customer-balance-display]", balanceDisplay);
                }

                function updateConsent(consent) {
                    if (!consent || typeof consent !== "object") {
                        return;
                    }

                    if (typeof consent.email_label === "string") {
                        setText("[data-customer-email-consent-display]", consent.email_label);
                    }

                    if (typeof consent.sms_label === "string") {
                        setText("[data-customer-sms-consent-display]", consent.sms_label);
                    }

                    if (typeof consent.sms_message_eligibility === "string") {
                        setText("[data-customer-sms-message-eligibility]", consent.sms_message_eligibility);
                    }
                }

                function activitySummaryLabel(count) {
                    const value = Number.isFinite(count) ? Number(count) : 0;
                    return value > 0
                        ? `${value.toLocaleString()} recent item${value === 1 ? "" : "s"} across rewards, adjustments, and messaging activity.`
                        : "No recent activity recorded yet.";
                }

                function externalProfilesSummaryLabel(count) {
                    const value = Number.isFinite(count) ? Number(count) : 0;
                    return value > 0
                        ? `${value.toLocaleString()} linked provider profile${value === 1 ? "" : "s"} currently attached to this customer.`
                        : "No external profiles linked yet.";
                }

                function updateDeferredSummaries(data = {}) {
                    if (typeof data.last_activity_display === "string") {
                        updateLastActivity(data.last_activity_display);
                    }

                    if (Number.isFinite(data.activity_count)) {
                        setText("[data-customer-activity-summary]", activitySummaryLabel(data.activity_count));
                        root.querySelectorAll("[data-customer-activity-summary]").forEach((node) => node.classList.remove("is-loading"));
                    }

                    if (Number.isFinite(data.external_profiles_count)) {
                        setText("[data-customer-external-profiles-summary]", externalProfilesSummaryLabel(data.external_profiles_count));
                        root.querySelectorAll("[data-customer-external-profiles-summary]").forEach((node) => node.classList.remove("is-loading"));
                    }
                }

                function deferredCacheKey(id) {
                    return `forestry:customer-detail-deferred:${id}`;
                }

                function readDeferredCache() {
                    if (!detailCache || !profileId) {
                        return null;
                    }

                    try {
                        const raw = detailCache.getItem(deferredCacheKey(profileId));
                        if (!raw) {
                            return null;
                        }

                        const parsed = JSON.parse(raw);
                        if (!parsed || typeof parsed !== "object" || typeof parsed.stored_at !== "number" || !parsed.data) {
                            return null;
                        }

                        if ((Date.now() - parsed.stored_at) > deferredCacheTtlMs) {
                            detailCache.removeItem(deferredCacheKey(profileId));
                            return null;
                        }

                        return parsed.data;
                    } catch (error) {
                        return null;
                    }
                }

                function writeDeferredCache(data) {
                    if (!detailCache || !profileId || !data || typeof data !== "object") {
                        return;
                    }

                    try {
                        detailCache.setItem(deferredCacheKey(profileId), JSON.stringify({
                            stored_at: Date.now(),
                            data,
                        }));
                    } catch (error) {
                        // Ignore storage failures in embedded/private browsing contexts.
                    }
                }

                function setDeferredShellState(shell, state) {
                    if (!shell) {
                        return;
                    }

                    shell.classList.toggle("is-loading", state === "loading");
                    shell.classList.toggle("is-refreshing", state === "refreshing");
                }

                function setDeferredError(target, message) {
                    if (!target) {
                        return;
                    }

                    if (!message) {
                        target.hidden = true;
                        target.innerHTML = "";
                        return;
                    }

                    target.hidden = false;
                    target.innerHTML = `
                        <span>${message}</span>
                        <button type="button" class="customers-detail-button" data-customer-deferred-retry>Retry</button>
                    `;
                }

                function renderDeferredSections(data = {}, { fromCache = false } = {}) {
                    if (typeof data.activity_html === "string" && activitySection) {
                        activitySection.innerHTML = data.activity_html;
                        setDeferredShellState(activityShell, fromCache ? "refreshing" : "idle");
                    }

                    if (typeof data.external_profiles_html === "string" && externalSection) {
                        externalSection.innerHTML = data.external_profiles_html;
                        setDeferredShellState(externalShell, fromCache ? "refreshing" : "idle");
                    }

                    setDeferredError(activityError, "");
                    setDeferredError(externalError, "");
                    updateDeferredSummaries(data);
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

                    if (!sessionTokenPromise) {
                        sessionTokenPromise = Promise.race([
                            Promise.resolve(window.shopify.idToken()),
                            new Promise((resolve) => window.setTimeout(() => resolve(null), 1500)),
                        ]).catch(() => {
                            sessionTokenPromise = null;
                            throw new Error(
                                authFailureMessage("invalid_session_token", "Shopify Admin verification failed."),
                            );
                        });
                    }

                    const sessionToken = await sessionTokenPromise;

                    if (typeof sessionToken !== "string" || sessionToken.trim() === "") {
                        sessionTokenPromise = null;
                        throw new Error(
                            authFailureMessage("missing_api_auth", "Shopify Admin verification is unavailable."),
                        );
                    }

                    headers.Authorization = `Bearer ${sessionToken.trim()}`;

                    return headers;
                }

                async function loadDeferredSections({ background = false } = {}) {
                    if (!deferredEndpoint) {
                        return;
                    }

                    deferredRequestSequence += 1;
                    const sequence = deferredRequestSequence;

                    if (deferredController) {
                        deferredController.abort();
                    }

                    deferredController = new AbortController();
                    setDeferredShellState(activityShell, background ? "refreshing" : "loading");
                    setDeferredShellState(externalShell, background ? "refreshing" : "loading");
                    setDeferredError(activityError, "");
                    setDeferredError(externalError, "");
                    mark("customer-detail-deferred-start");

                    try {
                        const headers = await resolveEmbeddedAuthHeaders();
                        const url = new URL(deferredEndpoint, window.location.origin);
                        if (perfDebug) {
                            url.searchParams.set("detail_perf", "1");
                        }

                        const response = await fetch(url.toString(), {
                            method: "GET",
                            headers,
                            credentials: "same-origin",
                            signal: deferredController.signal,
                        });

                        const payload = await response.json().catch(() => ({
                            ok: false,
                            message: "Customer detail sections could not be loaded.",
                        }));

                        if (!response.ok || !payload.ok || !payload.data) {
                            throw new Error(payload.message || "Customer detail sections could not be loaded.");
                        }

                        if (sequence !== deferredRequestSequence) {
                            return;
                        }

                        renderDeferredSections(payload.data);
                        writeDeferredCache(payload.data);
                        mark("customer-detail-deferred-end");
                        measure("customer-detail-deferred", "customer-detail-deferred-start", "customer-detail-deferred-end");
                        debug("deferred sections loaded", {
                            timings: payload.data.timings || null,
                            lastActivity: payload.data.last_activity_display || null,
                            activityCount: payload.data.activity_count ?? null,
                            externalProfilesCount: payload.data.external_profiles_count ?? null,
                        });
                    } catch (error) {
                        if (error && error.name === "AbortError") {
                            return;
                        }

                        const message = error instanceof Error ? error.message : "Customer detail sections could not be loaded.";
                        setDeferredShellState(activityShell, "idle");
                        setDeferredShellState(externalShell, "idle");
                        setDeferredError(activityError, message);
                        setDeferredError(externalError, message);
                        debug("deferred sections failed", { message });
                    }
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

                        if (payload.data?.consent) {
                            updateConsent(payload.data.consent);
                        }

                        if (form.dataset.resetOnSuccess === "true") {
                            form.reset();
                        }

                        const tone = payload.notice_style === "warning" ? "warning" : "success";
                        setFormFeedback(form, tone, payload.message || "Saved.");
                        window.ForestryEmbeddedApp?.showToast?.(payload.message || "Saved.", tone === "success" ? "success" : "error");
                        void loadDeferredSections({ background: true });
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

                root.addEventListener("click", (event) => {
                    const retry = event.target.closest("[data-customer-deferred-retry]");
                    if (!retry) {
                        return;
                    }

                    event.preventDefault();
                    void loadDeferredSections();
                });

                const cachedDeferred = readDeferredCache();
                if (cachedDeferred) {
                    renderDeferredSections(cachedDeferred, { fromCache: true });
                    debug("rendered deferred sections from cache");
                }

                window.requestAnimationFrame(() => {
                    void loadDeferredSections({ background: Boolean(cachedDeferred) });
                });
            })();
        </script>
    @endif
    </div>
</x-shopify.customers-layout>
