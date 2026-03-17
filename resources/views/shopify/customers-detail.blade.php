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
        $notice = session('customer_detail_notice');
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
            gap: 12px;
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
            gap: 14px;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }

        .customers-detail-card {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.95);
            padding: 16px;
        }

        .customers-detail-card h3 {
            margin: 0;
            font-size: 12px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.52);
            font-weight: 650;
        }

        .customers-detail-card p {
            margin: 10px 0 0;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.7);
            line-height: 1.55;
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
    </style>

    <section class="customers-surface customers-detail-header">
        <div class="customers-detail-header-row">
            <div>
                <a class="customers-detail-back" href="{{ route('shopify.embedded.customers.manage', [], false) }}">Back to Manage customers</a>
                <h2 class="customers-detail-name">{{ $customerDisplayName }}</h2>
                <div class="customers-detail-meta">
                    {{ $marketingProfile->email ?: 'Email not set' }}
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
            <p>{{ $summary['candle_cash_display'] ?? '0' }}</p>
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
                {{ $customerDisplayName }}<br>
                {{ $marketingProfile->email ?: 'Email not set' }}<br>
                {{ $marketingProfile->phone ?: 'Phone not set' }}<br>
                Created: {{ optional($marketingProfile->created_at)->format('Y-m-d H:i') ?: '—' }}<br>
                Updated: {{ optional($marketingProfile->updated_at)->format('Y-m-d H:i') ?: '—' }}<br>
                Marketing profile ID: {{ $marketingProfile->id }}
            </p>

            <form method="POST" action="{{ route('shopify.embedded.customers.update', ['marketingProfile' => $marketingProfile->id], false) }}" class="customers-detail-form">
                @csrf
                @method('PATCH')
                <input type="text" name="first_name" value="{{ old('first_name', $marketingProfile->first_name) }}" placeholder="First name" />
                <input type="text" name="last_name" value="{{ old('last_name', $marketingProfile->last_name) }}" placeholder="Last name" />
                <input type="email" name="email" value="{{ old('email', $marketingProfile->email) }}" placeholder="Email" />
                <input type="text" name="phone" value="{{ old('phone', $marketingProfile->phone) }}" placeholder="Phone" />
                <button type="submit" class="customers-detail-button is-primary">Save identity</button>
            </form>
        </article>

        <article class="customers-detail-card">
            <h3>Loyalty Profile</h3>
            <p>
                Candle Cash balance: {{ $summary['candle_cash_display'] ?? '0' }}<br>
                Candle Club: {{ ! empty($statuses['candle_club']) ? 'Active' : 'Not active' }}<br>
                Wholesale: {{ ! empty($statuses['wholesale']) ? 'Eligible' : 'Not eligible' }}<br>
                Birthday tracked: {{ ! empty($summary['birthday_tracked']) ? 'Yes' : 'No' }}
            </p>
        </article>

        <article class="customers-detail-card">
            <h3>Candle Cash Adjustment</h3>
            <p>
                Current balance: {{ $summary['candle_cash_display'] ?? '0' }}<br>
                Manual adjustments are recorded in the activity log and require a reason.
            </p>

            <form method="POST" action="{{ route('shopify.embedded.customers.candle-cash.adjust', ['marketingProfile' => $marketingProfile->id], false) }}" class="customers-detail-form">
                @csrf
                <label>Adjustment type</label>
                <select name="direction">
                    <option value="add" @selected(old('direction') === 'add')>Add Candle Cash</option>
                    <option value="subtract" @selected(old('direction') === 'subtract')>Subtract Candle Cash</option>
                </select>
                <label>Amount (Candle Cash)</label>
                <input type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" placeholder="Amount" />
                <label>Reason</label>
                <input type="text" name="reason" value="{{ old('reason') }}" placeholder="Reason for adjustment" />
                <button type="submit" class="customers-detail-button is-primary">Apply adjustment</button>
                @if($errors->has('direction') || $errors->has('amount') || $errors->has('reason'))
                    <div class="customers-detail-notice is-warning">
                        {{ $errors->first('direction') ?: $errors->first('amount') ?: $errors->first('reason') }}
                    </div>
                @endif
            </form>
        </article>

        <article class="customers-detail-card">
            <h3>Send Candle Cash</h3>
            <p>
                Send Candle Cash to the customer as a reward action. This is distinct from a manual adjustment and will be labeled separately in activity.
            </p>

            <form method="POST" action="{{ route('shopify.embedded.customers.candle-cash.send', ['marketingProfile' => $marketingProfile->id], false) }}" class="customers-detail-form">
                @csrf
                <label>Amount (Candle Cash)</label>
                <input type="number" name="amount" min="1" step="1" value="{{ old('amount') }}" placeholder="Amount" />
                <label>Reason</label>
                <input type="text" name="reason" value="{{ old('reason') }}" placeholder="Reason for sending" />
                <label>Optional message (SMS)</label>
                <textarea name="message" rows="3" placeholder="Optional message to send after crediting Candle Cash">{{ old('message') }}</textarea>
                @if(! $smsSupported)
                    <div class="customers-detail-meta">SMS messaging is disabled in this environment.</div>
                @elseif(! $smsHasPhone)
                    <div class="customers-detail-meta">SMS message will not send because there is no phone on file.</div>
                @elseif(! $smsConsented)
                    <div class="customers-detail-meta">SMS message will not send because the customer has not consented.</div>
                @endif
                <button type="submit" class="customers-detail-button is-primary">Send Candle Cash</button>
                @if($errors->has('amount') || $errors->has('reason') || $errors->has('message'))
                    <div class="customers-detail-notice is-warning">
                        {{ $errors->first('amount') ?: $errors->first('reason') ?: $errors->first('message') }}
                    </div>
                @endif
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

            <form method="POST" action="{{ route('shopify.embedded.customers.update-consent', ['marketingProfile' => $marketingProfile->id], false) }}" class="customers-detail-form">
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
                @if(! $smsSupported)
                    <br>SMS sending is not enabled in this environment.
                @elseif(! $smsHasPhone)
                    <br>Add a phone number to send SMS.
                @elseif(! $smsConsented)
                    <br>SMS consent is required before messages can be sent.
                @endif
            </p>

            <form method="POST" action="{{ route('shopify.embedded.customers.message', ['marketingProfile' => $marketingProfile->id], false) }}" class="customers-detail-form">
                @csrf
                <label>Channel</label>
                <select name="channel">
                    <option value="sms" @selected(old('channel', 'sms') === 'sms')>SMS</option>
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
                                @if($row['points'] !== null)
                                    {{ (int) $row['points'] > 0 ? '+' : '' }}{{ number_format((int) $row['points']) }}
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
                        <th>Points</th>
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
</x-shopify.customers-layout>
