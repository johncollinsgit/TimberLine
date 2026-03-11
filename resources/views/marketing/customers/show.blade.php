<x-layouts::app :title="'Marketing Customer Detail'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Customer Detail"
            description="Detailed marketing identity view with linked source records, campaign touches, consent history, and conversion context."
            hint-title="How to use this detail page"
            hint-text="This profile is a marketing-layer identity record. Source links and communication history are additive overlays on operational data, not replacements."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    @php
                        $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                    @endphp
                    <h2 class="text-xl font-semibold text-white">{{ $name !== '' ? $name : 'Unnamed profile' }}</h2>
                    <div class="mt-1 text-xs text-white/50">Marketing Profile #{{ $profile->id }}</div>
                </div>
                <a href="{{ route('marketing.customers') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/75 hover:bg-white/10">
                    Back to Customers
                </a>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Email</div>
                    <div class="mt-2 text-sm text-white">{{ $profile->email ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/45">Normalized: {{ $profile->normalized_email ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Phone</div>
                    <div class="mt-2 text-sm text-white">{{ $profile->phone ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/45">Normalized: {{ $profile->normalized_phone ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Consent Status</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] {{ $profile->accepts_email_marketing ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                            Email {{ $profile->accepts_email_marketing ? 'Opt-In' : 'Opt-Out' }}
                        </span>
                        <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] {{ $profile->accepts_sms_marketing ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                            SMS {{ $profile->accepts_sms_marketing ? 'Opt-In' : 'Opt-Out' }}
                        </span>
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Profile Meta</div>
                    <div class="mt-2 text-xs text-white/70">Created: {{ optional($profile->created_at)->format('Y-m-d H:i') }}</div>
                    <div class="mt-1 text-xs text-white/70">Updated: {{ optional($profile->updated_at)->format('Y-m-d H:i') }}</div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach((array) $profile->source_channels as $channel)
                            <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/70">{{ $channel }}</span>
                        @endforeach
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Marketing Likelihood</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ $profile->marketing_score !== null ? number_format((float) $profile->marketing_score, 0) . '%' : 'Pending' }}</div>
                    <div class="mt-1 text-xs text-white/55">Updated: {{ optional($profile->last_marketing_score_at)->format('Y-m-d H:i') ?: '—' }}</div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Linked Source Records</h2>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Source Type</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Source ID</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Match Method</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Confidence</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($profile->links as $link)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $link->source_type }}</td>
                                <td class="px-4 py-3 text-white/80">{{ $link->source_id }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $link->match_method ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $link->confidence !== null ? number_format((float) $link->confidence, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($link->created_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/50">No linked source records yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">External Profile Snapshots</h2>
            <p class="mt-1 text-sm text-white/65">Provider-level profile snapshots (including Growave loyalty metafields) mapped to this marketing profile.</p>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Provider</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Store</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">External Customer</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Email</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Points</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">VIP Tier</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Referral</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Synced</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($externalProfiles as $external)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $external->provider }} / {{ $external->integration }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $external->store_key ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">
                                    {{ $external->external_customer_id }}
                                    @if($external->external_customer_gid)
                                        <div class="mt-1 text-xs text-white/45">{{ \Illuminate\Support\Str::limit((string) $external->external_customer_gid, 38) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-white/70">{{ $external->email ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $external->points_balance !== null ? number_format((int) $external->points_balance) : '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $external->vip_tier ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">
                                    @if($external->referral_link)
                                        <a href="{{ $external->referral_link }}" target="_blank" rel="noopener" class="underline decoration-dotted">
                                            {{ \Illuminate\Support\Str::limit((string) $external->referral_link, 42) }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-white/60">{{ optional($external->synced_at)->format('Y-m-d H:i') ?: optional($external->updated_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-6 text-center text-white/50">No external snapshots linked yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Orders</h2>
            <p class="mt-1 text-sm text-white/65">Operational orders linked through marketing profile source links.</p>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Order</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Source/Channel</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Order Date</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Customer Snapshot</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Operational Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($orders as $order)
                            <tr>
                                <td class="px-4 py-3 text-white/80">
                                    {{ $order->order_number ?: ('Order #' . $order->id) }}
                                    <div class="text-xs text-white/45">ID #{{ $order->id }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/70">
                                    {{ $order->source ?: '—' }} / {{ $order->order_type ?: $order->channel }}
                                </td>
                                <td class="px-4 py-3 text-white/70">{{ optional($order->ordered_at)->toDateString() ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/65">
                                    {{ $order->customer_name ?: ($order->shipping_name ?: $order->billing_name ?: '—') }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if((auth()->user()?->isAdmin() ?? false) || (auth()->user()?->isManager() ?? false))
                                        <a href="{{ route('pouring.order', $order) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/75 hover:bg-white/10">
                                            Open
                                        </a>
                                    @else
                                        <span class="text-xs text-white/40">Restricted</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/50">No linked operational orders available yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Events Purchased At</h2>
            <x-admin.help-hint tone="neutral" title="Event attribution notes">
                Event attribution uses explicit source mappings from Square tax/source values. Unresolved values stay visible until mapped by admin.
            </x-admin.help-hint>

            @if($eventSummary !== [])
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach($eventSummary as $row)
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="text-sm font-semibold text-white">{{ $row['event_title'] }}</div>
                            <div class="mt-1 text-xs text-white/65">Date: {{ $row['event_date'] ?: '—' }}</div>
                            <div class="mt-1 text-xs text-white/65">Linked source orders: {{ $row['source_count'] }}</div>
                            <div class="mt-1 text-xs text-white/65">Confidence: {{ $row['confidence'] !== null ? number_format((float) $row['confidence'], 2) : '—' }}</div>
                            <div class="mt-1 text-xs text-white/65">Method: {{ implode(', ', $row['attribution_methods']) }}</div>
                        </article>
                    @endforeach
                </div>
            @elseif($eventOrders->isNotEmpty())
                <div class="mt-3 space-y-2">
                    @foreach($eventOrders as $order)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/75">
                            {{ $order->order_number ?: ('Order #' . $order->id) }}
                            @if($order->event)
                                · Event: {{ $order->event->display_name ?: $order->event->name }}
                            @elseif($order->order_type === 'event')
                                · Event attribution pending explicit mapping
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-sm text-white/65">No event-attributed records available yet for this profile.</p>
            @endif

            @if($unresolvedAttributionValues !== [])
                <div class="mt-4">
                    <h3 class="text-sm font-semibold text-white">Unresolved Event Source Values</h3>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($unresolvedAttributionValues as $value)
                            <span class="inline-flex rounded-full border border-amber-300/25 bg-amber-500/15 px-2.5 py-1 text-xs text-amber-100">
                                {{ $value['source_system'] }}: {{ $value['raw_value'] }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Square Linked Records</h3>
                <p class="mt-1 text-xs text-white/65">Square orders and payments linked through marketing profile links.</p>
                <div class="mt-3 space-y-2 text-sm text-white/75">
                    @if($squareOrders->isEmpty() && $squarePayments->isEmpty())
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-white/60">No Square-linked records yet.</div>
                    @endif
                    @foreach($squareOrders as $row)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            Order {{ $row->square_order_id }} · {{ optional($row->closed_at)->toDateString() ?: 'open' }} · {{ $row->source_name ?: '—' }}
                        </div>
                    @endforeach
                    @foreach($squarePayments as $row)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            Payment {{ $row->square_payment_id }} · {{ $row->status ?: '—' }} · {{ $row->amount_money !== null ? '$' . number_format(((int) $row->amount_money) / 100, 2) : '—' }}
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Imported Legacy Contact Links</h3>
                <div class="mt-3 space-y-2 text-sm text-white/75">
                    @forelse($legacyLinks as $link)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            {{ $link->source_type }} · {{ $link->source_id }}
                        </div>
                    @empty
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-white/60">No legacy contact imports linked yet.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Consent Summary</h3>
                <x-admin.help-hint tone="neutral" title="Consent precedence">
                    Consent is checked again at send time. Explicit opt-outs block outbound sends even if a recipient was previously approved.
                </x-admin.help-hint>
                <div class="mt-3 text-sm text-white/75 space-y-1">
                    <div>Email: {{ $profile->accepts_email_marketing ? 'Opt-In' : 'Opt-Out' }}</div>
                    <div>SMS: {{ $profile->accepts_sms_marketing ? 'Opt-In' : 'Opt-Out' }}</div>
                    <div>Email opted out at: {{ optional($profile->email_opted_out_at)->format('Y-m-d H:i') ?: '—' }}</div>
                    <div>SMS opted out at: {{ optional($profile->sms_opted_out_at)->format('Y-m-d H:i') ?: '—' }}</div>
                </div>

                <div class="mt-4 space-y-2">
                    <form method="POST" action="{{ route('marketing.customers.update-consent', $profile) }}" class="grid gap-2 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="channel" value="sms" />
                        <input type="hidden" name="consented" value="1" />
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1.5 text-xs font-semibold text-emerald-100">Mark SMS Consented</button>
                    </form>
                    <form method="POST" action="{{ route('marketing.customers.update-consent', $profile) }}" class="grid gap-2 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="channel" value="sms" />
                        <input type="hidden" name="consented" value="0" />
                        <button type="submit" class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/15 px-3 py-1.5 text-xs font-semibold text-amber-100">Revoke SMS Consent</button>
                    </form>
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Legacy Campaign Activity Summary</h3>
                @if($campaignStats->isNotEmpty())
                    <div class="mt-3 space-y-2 text-sm text-white/75">
                        @foreach($campaignStats as $stat)
                            <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                                {{ $stat->source_type }} · sends {{ $stat->sends_count }} · opens {{ $stat->opens_count }} · clicks {{ $stat->clicks_count }}
                                <div class="text-xs text-white/55">Last engaged: {{ optional($stat->last_engaged_at)->format('Y-m-d') ?: '—' }}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 text-xs text-white/65">No legacy campaign summaries linked yet.</p>
                @endif
            </article>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Customer Communication Timeline</h3>
                <x-admin.help-hint tone="neutral" title="Timeline behavior">
                    Delivery statuses may change after initial send as Twilio callbacks arrive. Each retry creates a separate attempt in this timeline.
                </x-admin.help-hint>
                <div class="mt-3 space-y-2">
                    @forelse($deliveries as $delivery)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2">
                            <div class="text-sm text-white">
                                {{ $delivery->campaign?->name ?: ('Campaign #' . $delivery->campaign_id) }} · {{ strtoupper($delivery->channel) }} · {{ $delivery->send_status }}
                            </div>
                            <div class="mt-1 text-xs text-white/60">
                                Attempt #{{ (int) $delivery->attempt_number }} · Sent {{ optional($delivery->sent_at)->format('Y-m-d H:i') ?: '—' }} · Delivered {{ optional($delivery->delivered_at)->format('Y-m-d H:i') ?: '—' }}
                            </div>
                            <div class="mt-1 text-xs text-white/50">SID: {{ $delivery->provider_message_id ?: '—' }}</div>
                            <div class="mt-1 text-xs text-white/55">{{ \Illuminate\Support\Str::limit((string) $delivery->rendered_message, 120) }}</div>
                            @if($delivery->error_code || $delivery->error_message)
                                <div class="mt-1 text-xs text-rose-200">{{ $delivery->error_code ?: 'error' }} · {{ \Illuminate\Support\Str::limit((string) $delivery->error_message, 90) }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/60">No SMS touches logged yet.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-white">Campaign Conversion History</h3>
                <x-admin.help-hint tone="neutral" title="Attribution summary">
                    Conversion rows can be `code_based`, `last_touch`, or `assisted`. Attribution is conservative and may be partial when source order data is incomplete.
                </x-admin.help-hint>
                <div class="mt-3 space-y-2">
                    @forelse($conversions as $conversion)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80">
                            <div>{{ $conversion->campaign?->name ?: ('Campaign #' . $conversion->campaign_id) }} · {{ $conversion->attribution_type }}</div>
                            <div class="mt-1 text-xs text-white/60">{{ $conversion->source_type }}:{{ $conversion->source_id }} · {{ optional($conversion->converted_at)->format('Y-m-d H:i') ?: '—' }}</div>
                            <div class="mt-1 text-xs text-white/60">Order total: {{ $conversion->order_total !== null ? '$' . number_format((float) $conversion->order_total, 2) : '—' }}</div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/60">No attributed conversions linked yet.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 lg:col-span-2">
                <h3 class="text-sm font-semibold text-white">Consent Event History</h3>
                <div class="mt-3 overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-white/65">
                            <tr>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Occurred</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Channel</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Event</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Source</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @forelse($consentEvents as $event)
                                <tr>
                                    <td class="px-4 py-3 text-white/75">{{ optional($event->occurred_at)->format('Y-m-d H:i') ?: optional($event->created_at)->format('Y-m-d H:i') }}</td>
                                    <td class="px-4 py-3 text-white/75">{{ strtoupper($event->channel) }}</td>
                                    <td class="px-4 py-3 text-white/75">{{ $event->event_type }}</td>
                                    <td class="px-4 py-3 text-white/60">{{ $event->source_type ?: '—' }}{{ $event->source_id ? (':' . $event->source_id) : '' }}</td>
                                    <td class="px-4 py-3 text-white/60">{{ \Illuminate\Support\Str::limit(json_encode($event->details), 100) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-white/55">No consent events recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5">
                <h3 class="text-sm font-semibold text-white">Candle Cash</h3>
                <p class="mt-2 text-xs text-white/65">Rewards balances and activity are intentionally deferred to later stages.</p>
            </article>
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 xl:col-span-2">
                <h3 class="text-sm font-semibold text-white">Marketing Likelihood</h3>
                <x-admin.help-hint tone="neutral" title="Score explainability">
                    Score is a transparent 0–100 weighted sum using recency, order frequency, spend signals, consent, source diversity, event activity, and legacy engagement.
                </x-admin.help-hint>
                <div class="mt-3 text-sm text-white/80">
                    Current score: <span class="font-semibold text-white">{{ $profile->marketing_score !== null ? number_format((float) $profile->marketing_score, 0) . '%' : 'Pending' }}</span>
                </div>
                @php
                    $scoreComponents = (array) data_get($latestScore?->reasons_json, 'components', []);
                @endphp
                @if($scoreComponents !== [])
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 text-xs text-white/70">
                        @foreach($scoreComponents as $component => $value)
                            <div class="rounded-xl border border-white/10 bg-white/5 px-2.5 py-1.5">
                                {{ str_replace('_', ' ', $component) }}: {{ $value }}
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 text-xs text-white/65">Score breakdown is not available yet.</p>
                @endif
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">Quick Actions</h3>
            <x-admin.help-hint tone="neutral" title="Action safety">
                Quick actions create recommendations or queue campaign recipients. They do not directly send provider messages.
            </x-admin.help-hint>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="space-y-3">
                    <form method="POST" action="{{ route('marketing.recommendations.create-for-profile', $profile) }}">
                        @csrf
                        <input type="hidden" name="redirect_to" value="profile" />
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                            Create One-Off Recommendation
                        </button>
                    </form>
                    <a href="{{ route('marketing.message-templates.create', ['channel' => 'sms', 'profile_id' => $profile->id]) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">
                        Open Prefilled SMS Draft
                    </a>
                    <a href="{{ route('marketing.message-templates.create', ['channel' => 'email', 'profile_id' => $profile->id]) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">
                        Open Prefilled Email Draft
                    </a>
                </div>

                <div class="space-y-3">
                    <form method="POST" action="{{ route('marketing.campaigns.add-profile', ['campaign' => ($campaignOptions->first()?->id ?? 0)]) }}" class="grid gap-2">
                        @csrf
                        <input type="hidden" name="marketing_profile_id" value="{{ $profile->id }}" />
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Add To Campaign</label>
                        <select name="campaign_id" id="campaign_select" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            @forelse($campaignOptions as $campaignOption)
                                <option value="{{ $campaignOption->id }}">{{ $campaignOption->name }} ({{ $campaignOption->status }})</option>
                            @empty
                                <option value="">No campaign options</option>
                            @endforelse
                        </select>
                        <input type="text" name="notes" placeholder="Optional note" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                        <button type="submit" @disabled($campaignOptions->isEmpty()) class="inline-flex w-fit rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85 disabled:opacity-40">
                            Add Profile To Selected Campaign
                        </button>
                    </form>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <h4 class="text-sm font-semibold text-white">Matching Segments</h4>
                    <div class="mt-2 space-y-2 text-sm text-white/80">
                        @forelse($matchingSegments as $segment)
                            <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                                <div class="font-semibold">{{ $segment['name'] }}</div>
                                <div class="mt-1 text-xs text-white/60">{{ implode(', ', $segment['reasons']) }}</div>
                            </div>
                        @empty
                            <div class="text-xs text-white/60">No active segments matched under current rules.</div>
                        @endforelse
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <h4 class="text-sm font-semibold text-white">Score Breakdown</h4>
                    <div class="mt-2 text-xs text-white/70">
                        @if($latestScore)
                            Calculated at {{ optional($latestScore->calculated_at)->format('Y-m-d H:i') }}.
                        @else
                            Score breakdown not stored yet.
                        @endif
                    </div>
                    <pre class="mt-3 whitespace-pre-wrap text-xs text-white/75">{{ json_encode(($latestScore?->reasons_json ?? $scoreResult['reasons'] ?? []), JSON_PRETTY_PRINT) }}</pre>
                </article>
            </div>
        </section>

        <script>
            (() => {
                const form = document.querySelector('form[action*="/marketing/campaigns/"][action*="/add-profile"]');
                const select = document.getElementById('campaign_select');
                if (!form || !select) return;
                const updateAction = () => {
                    const campaignId = select.value;
                    if (!campaignId) return;
                    form.action = "{{ route('marketing.campaigns.add-profile', ['campaign' => '__campaign__']) }}".replace('__campaign__', campaignId);
                };
                select.addEventListener('change', updateAction);
                updateAction();
            })();
        </script>
    </div>
</x-layouts::app>
