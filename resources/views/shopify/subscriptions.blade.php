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
        $access = is_array($subscriptionsAccess ?? null) ? $subscriptionsAccess : [];
        $payload = is_array($subscriptionsPayload ?? null) ? $subscriptionsPayload : [];
        $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
        $tabs = [
            'Home',
            'Revenue',
            'Upcoming orders',
            'Order errors',
            'Subscriptions',
            'Customers',
            'Products & Plans',
            'Discounts & Gifts',
            'Churn Tools',
            'Settings',
            'Migration',
        ];
    @endphp

    <style>
        .eg-subscriptions {
            max-width: 1320px;
            margin: 0 auto;
            display: grid;
            gap: 16px;
        }

        .eg-subscriptions-tabs {
            display: flex;
            gap: 18px;
            overflow-x: auto;
            border-bottom: 1px solid rgba(15, 23, 42, 0.12);
            white-space: nowrap;
        }

        .eg-subscriptions-tabs span {
            padding: 10px 0 12px;
            font-size: 13px;
            font-weight: 650;
            color: rgba(15, 23, 42, 0.66);
        }

        .eg-subscriptions-tabs span:first-child {
            color: #111827;
            border-bottom: 2px solid #2563eb;
        }

        .eg-subscriptions-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .eg-subscriptions-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 8px;
            background: #fff;
            padding: 16px;
            display: grid;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
        }

        .eg-subscriptions-card h2,
        .eg-subscriptions-card h3,
        .eg-subscriptions-card p {
            margin: 0;
        }

        .eg-subscriptions-kicker {
            font-size: 11px;
            line-height: 1.2;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.52);
            font-weight: 700;
        }

        .eg-subscriptions-value {
            font-size: 32px;
            line-height: 1.05;
            font-weight: 650;
            color: #111827;
        }

        .eg-subscriptions-muted {
            color: rgba(15, 23, 42, 0.64);
            font-size: 13px;
            line-height: 1.5;
        }

        .eg-subscriptions-band {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
            gap: 14px;
        }

        .eg-subscriptions-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .eg-subscriptions-table th,
        .eg-subscriptions-table td {
            text-align: left;
            padding: 9px 8px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        }

        .eg-subscriptions-table th {
            color: rgba(15, 23, 42, 0.56);
            font-size: 11px;
            text-transform: uppercase;
        }

        .eg-subscriptions-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 3px 8px;
            background: rgba(20, 83, 45, 0.1);
            color: #14532d;
            font-size: 11px;
            font-weight: 700;
        }

        .eg-subscriptions-scent-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .eg-subscriptions-scent-image {
            width: 100%;
            aspect-ratio: 4 / 3;
            border-radius: 8px;
            object-fit: cover;
            background: #f4f1ea;
        }

        .eg-subscriptions-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .eg-subscriptions-actions button {
            border: 1px solid rgba(15, 23, 42, 0.14);
            border-radius: 6px;
            background: #fff;
            padding: 7px 10px;
            font-size: 12px;
            font-weight: 650;
        }

        @media (max-width: 900px) {
            .eg-subscriptions-grid,
            .eg-subscriptions-band,
            .eg-subscriptions-scent-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <section class="eg-subscriptions">
        @if(! $authorized)
            <article class="eg-subscriptions-card">
                <h2>Open from Shopify Admin</h2>
                <p class="eg-subscriptions-muted">Evergrove needs Shopify context before it can show subscription data.</p>
            </article>
        @elseif(! (bool) ($access['enabled'] ?? false))
            <article class="eg-subscriptions-card">
                <h2>Subscriptions is locked</h2>
                <p class="eg-subscriptions-muted">{{ $access['message'] ?? 'This tenant does not have the Subscriptions module enabled.' }}</p>
            </article>
        @else
            <nav class="eg-subscriptions-tabs" aria-label="Subscription sections">
                @foreach($tabs as $tab)
                    <span>{{ $tab }}</span>
                @endforeach
            </nav>

            <div class="eg-subscriptions-grid">
                <article class="eg-subscriptions-card">
                    <p class="eg-subscriptions-kicker">Active subscribers</p>
                    <p class="eg-subscriptions-value">{{ number_format((int) ($metrics['active_subscribers'] ?? 0)) }}</p>
                    <p class="eg-subscriptions-muted">Shopify contracts mirrored locally.</p>
                </article>
                <article class="eg-subscriptions-card">
                    <p class="eg-subscriptions-kicker">Active Candle Club</p>
                    <p class="eg-subscriptions-value">{{ number_format((int) ($metrics['active_candle_club'] ?? 0)) }}</p>
                    <p class="eg-subscriptions-muted">Eligible for active voting windows.</p>
                </article>
                <article class="eg-subscriptions-card">
                    <p class="eg-subscriptions-kicker">Upcoming orders</p>
                    <p class="eg-subscriptions-value">{{ number_format((int) ($metrics['upcoming_orders'] ?? 0)) }}</p>
                    <p class="eg-subscriptions-muted">Due in the next 31 days.</p>
                </article>
                <article class="eg-subscriptions-card">
                    <p class="eg-subscriptions-kicker">Order errors</p>
                    <p class="eg-subscriptions-value">{{ number_format((int) ($metrics['failed_payment_attempts'] ?? 0)) }}</p>
                    <p class="eg-subscriptions-muted">Failed billing attempts needing recovery.</p>
                </article>
            </div>

            <article class="eg-subscriptions-card">
                <h2>Active Candle Club customers</h2>
                <table class="eg-subscriptions-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Next bill</th>
                            <th>Months left</th>
                            <th>Pauses left</th>
                            <th>Payment</th>
                            <th>Latest action</th>
                            <th>Admin actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse((array) ($payload['active_candle_club_customers'] ?? []) as $customer)
                            @php($commitment = is_array($customer['commitment'] ?? null) ? $customer['commitment'] : [])
                            @php($payment = is_array($customer['payment_method'] ?? null) ? $customer['payment_method'] : [])
                            @php($latest = is_array($customer['latest_event'] ?? null) ? $customer['latest_event'] : [])
                            <tr>
                                <td>
                                    <strong>{{ $customer['customer_name'] ?? 'Candle Club member' }}</strong><br>
                                    <span class="eg-subscriptions-muted">{{ $customer['customer_email'] ?? $customer['shopify_customer_gid'] ?? 'No email mirrored' }}</span>
                                </td>
                                <td>{{ $customer['next_billing_date'] ?? 'Not set' }}</td>
                                <td>{{ $commitment['months_remaining'] ?? 0 }}</td>
                                <td>{{ $commitment['pauses_remaining'] ?? 0 }} / {{ $commitment['allowed_pauses'] ?? 2 }}</td>
                                <td>{{ ($payment['brand'] ?? null) ? ($payment['brand'].' ending '.($payment['last_digits'] ?? '')) : ($payment['status'] ?? 'Not mirrored') }}</td>
                                <td>{{ $latest['event_type'] ?? 'None yet' }}</td>
                                <td>
                                    <div class="eg-subscriptions-actions">
                                        <button type="button">Pause</button>
                                        <button type="button">Swap</button>
                                        <button type="button">Address</button>
                                        <button type="button">Payment email</button>
                                        <button type="button">Cancel</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">No active Candle Club customers are mirrored yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </article>

            <article class="eg-subscriptions-card">
                <h2>Monthly Candle Club scents</h2>
                <p class="eg-subscriptions-muted">Chosen monthly scents are mirrored as draft Shopify products, kept private until you publish them, and can collect private-first Candle Club feedback.</p>
                <div class="eg-subscriptions-scent-grid">
                    @forelse((array) ($payload['monthly_scents'] ?? []) as $scent)
                        <section class="eg-subscriptions-card">
                            @if(! empty($scent['photo_url']))
                                <img class="eg-subscriptions-scent-image" src="{{ $scent['photo_url'] }}" alt="">
                            @else
                                <div class="eg-subscriptions-scent-image"></div>
                            @endif
                            <p class="eg-subscriptions-kicker">{{ $scent['month_label'] ?? 'Candle Club month' }}</p>
                            <h3>{{ $scent['title'] ?? 'Untitled scent' }}</h3>
                            <p class="eg-subscriptions-muted">{{ $scent['body'] ?? 'No scent notes yet.' }}</p>
                            <p class="eg-subscriptions-muted">
                                Reviews: {{ $scent['review_count'] ?? 0 }}
                                @if(isset($scent['average_rating']))
                                    · Avg {{ $scent['average_rating'] }}
                                @endif
                            </p>
                            <p><span class="eg-subscriptions-pill">{{ $scent['product_status'] ?? 'draft' }}</span></p>
                            <div class="eg-subscriptions-actions">
                                <button type="button">View scent</button>
                                <button type="button">Export reviews</button>
                                <button type="button">Publish product</button>
                            </div>
                        </section>
                    @empty
                        <p class="eg-subscriptions-muted">No monthly Candle Club scent cards are mirrored yet.</p>
                    @endforelse
                </div>
            </article>

            <div class="eg-subscriptions-band">
                <article class="eg-subscriptions-card">
                    <h2>Upcoming orders</h2>
                    <table class="eg-subscriptions-table">
                        <thead>
                            <tr>
                                <th>Contract</th>
                                <th>Status</th>
                                <th>Next bill</th>
                                <th>Cycles</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse((array) ($payload['upcoming'] ?? []) as $contract)
                                <tr>
                                    <td>{{ \Illuminate\Support\Str::limit((string) ($contract['shopify_subscription_contract_gid'] ?? 'Contract'), 34) }}</td>
                                    <td>{{ $contract['status'] ?? 'active' }}</td>
                                    <td>{{ $contract['next_billing_date'] ?? 'Not set' }}</td>
                                    <td>{{ $contract['completed_cycles'] ?? 0 }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4">No upcoming subscription orders are mirrored yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </article>

                <article class="eg-subscriptions-card">
                    <h2>Candle Club</h2>
                    @php($settings = is_array($payload['candle_club'] ?? null) ? $payload['candle_club'] : [])
                    <p class="eg-subscriptions-muted">Commitment: {{ $settings['commitment_months'] ?? 6 }} months</p>
                    <p class="eg-subscriptions-muted">Allowed pauses: {{ $settings['allowed_pauses_per_commitment'] ?? 1 }}</p>
                    <p class="eg-subscriptions-muted">First gift: {{ $settings['first_gift_label'] ?? 'Free 8oz Coffeehouse candle' }}</p>
                    <p class="eg-subscriptions-muted">Renewal gift: {{ $settings['renewal_gift_label'] ?? 'Free renewal candle' }}</p>
                    @if(is_array($payload['active_poll'] ?? null))
                        <p class="eg-subscriptions-muted">Open poll: {{ $payload['active_poll']['title'] }}</p>
                        <p class="eg-subscriptions-muted">Votes: {{ $payload['recent_votes'] ?? 0 }}</p>
                    @else
                        <p class="eg-subscriptions-muted">No Candle Club poll is open.</p>
                    @endif
                </article>
            </div>

            <div class="eg-subscriptions-band">
                <article class="eg-subscriptions-card">
                    <h2>Migration</h2>
                    @if(is_array($payload['latest_migration'] ?? null))
                        <p class="eg-subscriptions-muted">Latest batch: #{{ $payload['latest_migration']['id'] }} / {{ $payload['latest_migration']['status'] }}</p>
                        <p class="eg-subscriptions-muted">Mode: {{ $payload['latest_migration']['mode'] }}</p>
                        <p class="eg-subscriptions-muted">Recharge paused: {{ $payload['latest_migration']['recharge_billing_paused_confirmed'] ? 'yes' : 'no' }}</p>
                    @else
                        <p class="eg-subscriptions-muted">No Recharge migration dry-run has been created yet.</p>
                    @endif
                </article>

                <article class="eg-subscriptions-card">
                    <h2>Payment recovery</h2>
                    @forelse((array) ($payload['errors'] ?? []) as $error)
                        <p class="eg-subscriptions-muted">{{ $error['message'] ?? 'Payment failed.' }}</p>
                    @empty
                        <p class="eg-subscriptions-muted">No failed billing attempts are mirrored yet.</p>
                    @endforelse
                </article>
            </div>
        @endif
    </section>
</x-shopify-embedded-shell>
