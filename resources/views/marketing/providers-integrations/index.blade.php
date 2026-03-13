<x-layouts::app :title="'Marketing Providers & Integrations'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Providers & Integrations"
            description="Square sync, legacy importer workflows, and event source mapping administration for marketing attribution."
            hint-title="How this page works"
            hint-text="All imports and syncs are additive. Identity merges still follow exact email/phone rules, and ambiguous matches are routed to Identity Review."
        />

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-white">Square Integration Sync</h2>
                    <p class="mt-1 text-sm text-white/65">Pull Square customers, orders, and payments into source tables and sync identities.</p>
                </div>

                <x-admin.help-hint title="Square sync behavior">
                    Syncs are idempotent and safe to rerun. Event attribution is derived from mapped tax/source values after order sync.
                </x-admin.help-hint>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Square Customers</div>
                        <div class="mt-1 text-xl font-semibold text-white">{{ number_format($squareCounts['customers']) }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Square Orders</div>
                        <div class="mt-1 text-xl font-semibold text-white">{{ number_format($squareCounts['orders']) }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Square Payments</div>
                        <div class="mt-1 text-xl font-semibold text-white">{{ number_format($squareCounts['payments']) }}</div>
                    </div>
                </div>

                <form method="POST" action="{{ route('marketing.providers-integrations.sync-square') }}" class="grid gap-3 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Sync Type</label>
                        <select name="sync_type" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="customers">Customers</option>
                            <option value="orders">Orders</option>
                            <option value="payments">Payments</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Limit</label>
                        <input type="number" name="limit" value="" min="1" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" placeholder="Leave blank for full sync" />
                        <div class="mt-1 text-[11px] text-white/45">Blank now means exhaustion mode with checkpoint-safe runs.</div>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Since (optional)</label>
                        <input type="datetime-local" name="since" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <label class="flex items-center gap-2 pt-6 text-sm text-white/75">
                        <input type="checkbox" name="dry_run" value="1" class="rounded border-white/20 bg-white/5" />
                        Dry run
                    </label>
                    <div class="sm:col-span-2">
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                            Run Square Sync
                        </button>
                    </div>
                </form>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-white">Legacy CSV Importers</h2>
                    <p class="mt-1 text-sm text-white/65">Import Yotpo and Square Marketing contacts/consent/activity summaries into the marketing layer.</p>
                </div>

                <x-admin.help-hint title="Consent precedence">
                    @foreach($consentRules as $rule)
                        <div>• {{ $rule }}</div>
                    @endforeach
                </x-admin.help-hint>

                <x-admin.help-hint tone="neutral" title="Expected CSV columns">
                    Include identity fields (`email`, `phone`, `first_name`, `last_name`), consent columns (`email_subscribed`, `sms_subscribed`, `unsubscribed_at`), and optional activity summary (`sends_count`, `opens_count`, `clicks_count`, `last_engaged_at`).
                </x-admin.help-hint>

                <form method="POST" action="{{ route('marketing.providers-integrations.import-legacy') }}" enctype="multipart/form-data" class="grid gap-3">
                    @csrf
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Import Type</label>
                        <select name="import_type" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="yotpo_contacts_import">Yotpo Contacts Export</option>
                            <option value="square_marketing_import">Square Marketing Export</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">CSV File</label>
                        <input type="file" name="file" accept=".csv,.txt" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-white/75">
                        <input type="checkbox" name="dry_run" value="1" class="rounded border-white/20 bg-white/5" />
                        Dry run
                    </label>
                    <div>
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                            Import CSV
                        </button>
                    </div>
                </form>
            </article>
        </section>

        @php
            $squareSummary = $squareAudit['summary'];
            $squareProfiles = $squareAudit['profiles'];
            $squareFilters = $squareAudit['filters'];
            $squarePayload = $squareAudit['payload_diagnostics'];
            $manualFollowUpOrders = $squareAudit['manual_follow_up_orders'];
            $overlapSummary = $sourceOverlap['summary'];
            $overlapProfiles = $sourceOverlap['profiles'];
            $overlapFilters = $sourceOverlap['filters'];
            $overlapFilter = $sourceOverlap['active_filter'];
            $overlapSearch = $sourceOverlap['search'];
            $overlapTotalProfiles = $sourceOverlap['total_profiles'];
        @endphp

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-5">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white">Square Contact Quality</h2>
                    <p class="mt-1 text-sm text-white/65">Audit Square-only profiles, contact gaps, and raw POS buyers that still need manual capture.</p>
                </div>
                <div class="rounded-2xl border border-amber-300/20 bg-amber-500/10 px-4 py-3 text-xs text-amber-100/90">
                    Current bottleneck is contact quality, not sync reliability. Square customer directory is locally resident; orders/payments still often lack `square_customer_id`.
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Profiles with Square Link</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($squareSummary['profiles_with_square_link'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-white/50">Canonical profiles linked to Square customers, orders, or payments.</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Square-only Profiles</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($squareSummary['square_only_profiles'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-white/50">Source channels indicate Square only, with no Shopify/Growave enrichment.</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Square-only Missing Contact</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($squareSummary['square_only_missing_contact'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-white/50">No email and no phone on the canonical profile.</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">No Shopify / Growave</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($squareSummary['no_shopify_or_growave'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-white/50">Square-linked profiles with no Shopify order/customer or Growave customer link.</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Identity Reviews</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($squareSummary['square_identity_reviews'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-white/50">Conflicts held for manual review instead of blind merge.</div>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr),minmax(320px,1fr)]">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-4">
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-white">Square-linked Profile Audit</h3>
                            <p class="mt-1 text-sm text-white/60">Filter canonical profiles to find the biggest contact capture gaps before doing more automation work.</p>
                        </div>
                        <div class="text-xs text-white/45">High-value threshold: ${{ number_format((float) $squareMinSpendDollars, 2) }}</div>
                    </div>

                    <form method="GET" action="{{ route('marketing.providers-integrations') }}" class="grid gap-3 lg:grid-cols-12">
                        <input type="hidden" name="search" value="{{ $search }}" />
                        <input type="hidden" name="source_system" value="{{ $sourceSystem }}" />
                        <input type="hidden" name="mapped" value="{{ $mapped }}" />
                        <div class="lg:col-span-4">
                            <label class="text-xs uppercase tracking-[0.2em] text-white/55">Audit Filter</label>
                            <select name="square_filter" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                                @foreach($squareFilters as $filterOption)
                                    <option value="{{ $filterOption['value'] }}" @selected($squareProfileFilter === $filterOption['value'])>{{ $filterOption['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="lg:col-span-4">
                            <label class="text-xs uppercase tracking-[0.2em] text-white/55">Search</label>
                            <input type="text" name="square_search" value="{{ $squareProfileSearch }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" placeholder="Name, email, phone, Square customer id" />
                        </div>
                        <div class="lg:col-span-2">
                            <label class="text-xs uppercase tracking-[0.2em] text-white/55">Min Spend</label>
                            <input type="number" step="0.01" min="0" name="square_min_spend" value="{{ $squareMinSpendDollars }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                        </div>
                        <div class="lg:col-span-2 flex items-end">
                            <button type="submit" class="w-full rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-white">Apply</button>
                        </div>
                    </form>

                    <div class="overflow-x-auto rounded-2xl border border-white/10">
                        <table class="min-w-full text-sm">
                            <thead class="bg-white/5 text-white/65">
                                <tr>
                                    <th class="px-4 py-3 text-left">Profile</th>
                                    <th class="px-4 py-3 text-left">Square Customer</th>
                                    <th class="px-4 py-3 text-left">Contact</th>
                                    <th class="px-4 py-3 text-left">Square Value</th>
                                    <th class="px-4 py-3 text-left">Linked Sources</th>
                                    <th class="px-4 py-3 text-left">Last Square Activity</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                                @forelse($squareProfiles as $profile)
                                    @php
                                        $displayName = trim((string) (($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')));
                                        $squareSpend = ((int) ($profile->square_total_spend_cents ?? 0)) / 100;
                                        $hasContact = filled($profile->email ?? null) || filled($profile->phone ?? null);
                                        $lastSquareActivity = collect([$profile->last_square_order_at ?? null, $profile->last_square_payment_at ?? null])->filter()->max();
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 align-top">
                                            <div class="font-semibold text-white">{{ $displayName !== '' ? $displayName : 'Unnamed profile' }}</div>
                                            <div class="mt-1 text-xs text-white/55">Profile #{{ $profile->id }}</div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-white/75">
                                            <div>{{ $profile->sample_square_customer_id ?: '—' }}</div>
                                            <div class="mt-1 text-xs text-white/45">{{ number_format((int) ($profile->square_customer_link_count ?? 0)) }} customer link(s)</div>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="text-white/80">{{ $profile->email ?: 'No email' }}</div>
                                            <div class="mt-1 text-white/70">{{ $profile->phone ?: 'No phone' }}</div>
                                            <div class="mt-2 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold {{ $hasContact ? 'border-emerald-300/30 bg-emerald-500/10 text-emerald-100' : 'border-amber-300/30 bg-amber-500/10 text-amber-100' }}">
                                                {{ $hasContact ? 'Contact captured' : 'Needs manual capture' }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-white/75">
                                            <div>${{ number_format($squareSpend, 2) }}</div>
                                            <div class="mt-1 text-xs text-white/45">{{ number_format((int) ($profile->square_order_count ?? 0)) }} orders / {{ number_format((int) ($profile->square_payment_count ?? 0)) }} payments</div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-white/75">
                                            <div class="flex flex-wrap gap-2">
                                                <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[11px] {{ ((int) ($profile->has_shopify_link ?? 0)) === 1 ? 'text-emerald-100' : 'text-white/45' }}">Shopify</span>
                                                <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[11px] {{ ((int) ($profile->has_growave_link ?? 0)) === 1 ? 'text-emerald-100' : 'text-white/45' }}">Growave</span>
                                                <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[11px] {{ ((int) ($profile->has_square_order_link ?? 0)) === 1 ? 'text-emerald-100' : 'text-white/45' }}">Square Order Link</span>
                                                <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[11px] {{ ((int) ($profile->has_square_payment_link ?? 0)) === 1 ? 'text-emerald-100' : 'text-white/45' }}">Square Payment Link</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-white/70">
                                            {{ $lastSquareActivity ? \Illuminate\Support\Carbon::parse($lastSquareActivity)->format('Y-m-d H:i') : 'No linked order/payment activity' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-6 text-center text-white/55">No Square-linked profiles matched this audit filter.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div>{{ $squareProfiles->links() }}</div>
                </article>

                <div class="space-y-4">
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-3">
                        <div>
                            <h3 class="text-base font-semibold text-white">Manual Follow-up Queue</h3>
                            <p class="mt-1 text-sm text-white/60">Raw Square orders that still have no customer id and no canonical order link. These are the event/POS buyers most likely to need staff follow-up.</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                            <div class="text-xs uppercase tracking-[0.2em] text-white/50">High-value unlinked orders</div>
                            <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) ($squareAudit['manual_follow_up_order_count'] ?? 0)) }}</div>
                            <div class="mt-1 text-xs text-white/45">Orders without `square_customer_id` or canonical order link, at or above ${{ number_format((float) $squareMinSpendDollars, 2) }}.</div>
                        </div>
                        <div class="space-y-3">
                            @forelse($manualFollowUpOrders as $row)
                                <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-semibold text-white">{{ $row['square_order_id'] }}</div>
                                            <div class="mt-1 text-xs text-white/50">{{ $row['source_name'] ?: 'Unknown source' }} · {{ $row['location_id'] ?: 'No location' }}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="font-semibold text-white">${{ number_format(((int) ($row['total_money_amount'] ?? 0)) / 100, 2) }}</div>
                                            <div class="mt-1 text-xs {{ ($row['is_high_value'] ?? false) ? 'text-amber-100' : 'text-white/45' }}">
                                                {{ ($row['is_high_value'] ?? false) ? 'High-value' : 'Below threshold' }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-3 grid gap-2 text-xs text-white/65">
                                        <div>Closed: {{ $row['closed_at'] ?: '—' }}</div>
                                        <div>Cardholder hint: {{ $row['cardholder_name'] ?: 'No cardholder name in payment payload' }}</div>
                                        <div>Event attribution rows: {{ number_format((int) ($row['attribution_count'] ?? 0)) }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-2xl border border-white/10 bg-black/20 p-4 text-sm text-white/55">
                                    No manual Square follow-up candidates matched the current threshold.
                                </div>
                            @endforelse
                        </div>
                    </article>

                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-4">
                        <div>
                            <h3 class="text-base font-semibold text-white">Raw Payload Diagnostics</h3>
                            <p class="mt-1 text-sm text-white/60">This shows whether Square is actually giving us alternate contact fields beyond `square_customer_id` in the stored raw payloads.</p>
                        </div>
                        <div class="grid gap-3">
                            <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                                <div class="text-xs uppercase tracking-[0.2em] text-white/50">Orders</div>
                                <div class="mt-2 space-y-1 text-sm text-white/75">
                                    <div>Total: {{ number_format((int) data_get($squarePayload, 'orders.total', 0)) }}</div>
                                    <div>No customer id: {{ number_format((int) data_get($squarePayload, 'orders.no_customer_id', 0)) }}</div>
                                    <div>`customer_details.email_address`: {{ number_format((int) data_get($squarePayload, 'orders.customer_details_email', 0)) }}</div>
                                    <div>`customer_details.phone_number`: {{ number_format((int) data_get($squarePayload, 'orders.customer_details_phone', 0)) }}</div>
                                    <div>Pickup recipient name: {{ number_format((int) data_get($squarePayload, 'orders.pickup_recipient_name', 0)) }}</div>
                                    <div>Shipment recipient name: {{ number_format((int) data_get($squarePayload, 'orders.shipment_recipient_name', 0)) }}</div>
                                    <div>Tender customer id: {{ number_format((int) data_get($squarePayload, 'orders.tender_customer_id', 0)) }}</div>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                                <div class="text-xs uppercase tracking-[0.2em] text-white/50">Payments</div>
                                <div class="mt-2 space-y-1 text-sm text-white/75">
                                    <div>Total: {{ number_format((int) data_get($squarePayload, 'payments.total', 0)) }}</div>
                                    <div>No customer id: {{ number_format((int) data_get($squarePayload, 'payments.no_customer_id', 0)) }}</div>
                                    <div>`buyer_email_address`: {{ number_format((int) data_get($squarePayload, 'payments.buyer_email', 0)) }}</div>
                                    <div>`billing_address.address_line_1`: {{ number_format((int) data_get($squarePayload, 'payments.billing_address_line_1', 0)) }}</div>
                                    <div>Recoverable payment cardholder: {{ number_format((int) data_get($squarePayload, 'payments.cardholder_name', 0)) }}</div>
                                </div>
                            </div>
                        </div>
                        <x-admin.help-hint tone="neutral" title="Operational implication">
                            Current production payloads do not expose order-level email/phone fields in a way that supports safe auto-linking. Cardholder name is present on some payments, but that is not strong enough to auto-merge identities without a captured email or phone.
                        </x-admin.help-hint>
                    </article>
                </div>
            </div>

            <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <h3 class="text-base font-semibold text-white">Recommended Operational Capture Path</h3>
                <div class="mt-3 grid gap-3 lg:grid-cols-3">
                    <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                        <div class="text-sm font-semibold text-white">At Booth / POS</div>
                        <div class="mt-2 text-sm text-white/70">Ask for phone or email at purchase with a clear loyalty claim reason: “Enter phone/email for Candle Cash.”</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                        <div class="text-sm font-semibold text-white">After Purchase</div>
                        <div class="mt-2 text-sm text-white/70">Use QR and receipt signage to route buyers into a fast claim flow for rewards instead of relying on Square to supply contact data later.</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                        <div class="text-sm font-semibold text-white">Manual Follow-up</div>
                        <div class="mt-2 text-sm text-white/70">Prioritize high-value unlinked orders first, then staff-review cardholder hints and mapped event sources where they exist.</div>
                    </div>
                </div>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-5">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white">Customer Source Overlap</h2>
                    <p class="mt-1 text-sm text-white/65">Break the canonical customer universe into Shopify, Square, and Growave overlap buckets so channel coverage and contact quality are obvious.</p>
                </div>
                <div class="rounded-2xl border border-sky-300/20 bg-sky-500/10 px-4 py-3 text-xs text-sky-100/90">
                    Uses canonical <code>marketing_profiles</code> plus existing provider links. This is reporting only; no new identity model or sync path is introduced.
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Canonical Profiles</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) $overlapTotalProfiles) }}</div>
                    <div class="mt-1 text-xs text-white/50">Total customer universe used as the overlap base.</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Square-only</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($overlapSummary, 'square_only.profile_count', 0)) }}</div>
                    <div class="mt-1 text-xs text-white/50">POS/event-heavy customers not yet linked to Shopify or Growave.</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">All 3 Sources</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($overlapSummary, 'shopify_square_growave.profile_count', 0)) }}</div>
                    <div class="mt-1 text-xs text-white/50">True multi-channel customers touching Shopify, Square, and Growave.</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Square-only Missing Contact</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($overlapSummary, 'square_only.missing_both_count', 0)) }}</div>
                    <div class="mt-1 text-xs text-white/50">Square-only profiles with no email and no phone.</div>
                </div>
            </div>

            <article class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-white">Overlap Buckets</h3>
                    <p class="mt-1 text-sm text-white/60">Each row is derived from existing source links on canonical profiles. Tracked spend reflects currently stored Shopify order totals and Square customer-linked spend where available.</p>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-white/65">
                            <tr>
                                <th class="px-4 py-3 text-left">Bucket</th>
                                <th class="px-4 py-3 text-left">Profiles</th>
                                <th class="px-4 py-3 text-left">Missing Email</th>
                                <th class="px-4 py-3 text-left">Missing Phone</th>
                                <th class="px-4 py-3 text-left">Missing Both</th>
                                <th class="px-4 py-3 text-left">Tracked Spend</th>
                                <th class="px-4 py-3 text-left">Candle Cash</th>
                                <th class="px-4 py-3 text-left">Review Coverage</th>
                                <th class="px-4 py-3 text-left"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @foreach($overlapSummary as $bucket)
                                <tr>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-white">{{ $bucket['label'] }}</div>
                                        <div class="mt-1 text-xs text-white/50">{{ $bucket['description'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-white/80">
                                        <div>{{ number_format((int) $bucket['profile_count']) }}</div>
                                        <div class="mt-1 text-xs text-white/45">{{ number_format((float) $bucket['percent_of_total'], 1) }}% of total</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-white/75">{{ number_format((int) $bucket['missing_email_count']) }}</td>
                                    <td class="px-4 py-3 align-top text-white/75">{{ number_format((int) $bucket['missing_phone_count']) }}</td>
                                    <td class="px-4 py-3 align-top text-white/75">{{ number_format((int) $bucket['missing_both_count']) }}</td>
                                    <td class="px-4 py-3 align-top text-white/75">${{ number_format(((int) $bucket['total_tracked_spend_cents']) / 100, 2) }}</td>
                                    <td class="px-4 py-3 align-top text-white/75">{{ number_format((int) $bucket['total_candle_cash_balance']) }}</td>
                                    <td class="px-4 py-3 align-top text-white/75">
                                        <div>{{ number_format((int) $bucket['review_summary_profile_count']) }} profiles</div>
                                        <div class="mt-1 text-xs text-white/45">{{ number_format((int) $bucket['total_review_count']) }} total reviews</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-right">
                                        <a
                                            href="{{ route('marketing.providers-integrations', array_merge(request()->query(), ['overlap_filter' => 'bucket:' . $bucket['key'], 'overlap_page' => null])) }}"
                                            class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80"
                                        >
                                            View Profiles
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-4">
                <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-white">Profile Drilldown</h3>
                        <p class="mt-1 text-sm text-white/60">Use this to isolate the operational buckets that matter: Square-only missing contact, Shopify without Growave, Growave without Square, or full cross-channel customers.</p>
                    </div>
                    <div class="text-xs text-white/45">Filter is applied against canonical source-link presence, not raw source channel text.</div>
                </div>

                <form method="GET" action="{{ route('marketing.providers-integrations') }}" class="grid gap-3 lg:grid-cols-12">
                    <input type="hidden" name="search" value="{{ $search }}" />
                    <input type="hidden" name="source_system" value="{{ $sourceSystem }}" />
                    <input type="hidden" name="mapped" value="{{ $mapped }}" />
                    <input type="hidden" name="square_filter" value="{{ $squareProfileFilter }}" />
                    <input type="hidden" name="square_search" value="{{ $squareProfileSearch }}" />
                    <input type="hidden" name="square_min_spend" value="{{ $squareMinSpendDollars }}" />
                    <div class="lg:col-span-4">
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Overlap Filter</label>
                        <select name="overlap_filter" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            @foreach($overlapFilters as $filterOption)
                                <option value="{{ $filterOption['value'] }}" @selected($overlapFilter === $filterOption['value'])>{{ $filterOption['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-6">
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Search</label>
                        <input type="text" name="overlap_search" value="{{ $overlapSearch }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" placeholder="Name, email, phone" />
                    </div>
                    <div class="lg:col-span-2 flex items-end">
                        <button type="submit" class="w-full rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-white">Apply</button>
                    </div>
                </form>

                <div class="overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-white/65">
                            <tr>
                                <th class="px-4 py-3 text-left">Profile</th>
                                <th class="px-4 py-3 text-left">Overlap Bucket</th>
                                <th class="px-4 py-3 text-left">Sources</th>
                                <th class="px-4 py-3 text-left">Contact</th>
                                <th class="px-4 py-3 text-left">Tracked Spend</th>
                                <th class="px-4 py-3 text-left">Candle Cash</th>
                                <th class="px-4 py-3 text-left">Reviews</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @forelse($overlapProfiles as $profile)
                                @php
                                    $displayName = trim((string) (($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')));
                                    $hasEmail = filled($profile->email ?? null);
                                    $hasPhone = filled($profile->phone ?? null);
                                    $bucket = $overlapSummary[(string) ($profile->overlap_bucket ?? 'unlinked_or_other')] ?? null;
                                @endphp
                                <tr>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-white">{{ $displayName !== '' ? $displayName : 'Unnamed profile' }}</div>
                                        <div class="mt-1 text-xs text-white/55">Profile #{{ $profile->id }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-white/75">
                                        <div>{{ $bucket['label'] ?? 'Unlinked / Other' }}</div>
                                        <div class="mt-1 text-xs text-white/45">{{ $bucket['description'] ?? 'No Shopify, Square, or Growave link present.' }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-white/75">
                                        <div class="flex flex-wrap gap-2">
                                            <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[11px] {{ ((int) ($profile->has_shopify_link ?? 0)) === 1 ? 'text-emerald-100' : 'text-white/45' }}">Shopify</span>
                                            <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[11px] {{ ((int) ($profile->has_square_link ?? 0)) === 1 ? 'text-emerald-100' : 'text-white/45' }}">Square</span>
                                            <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[11px] {{ ((int) ($profile->has_growave_link ?? 0)) === 1 ? 'text-emerald-100' : 'text-white/45' }}">Growave</span>
                                        </div>
                                        <div class="mt-2 text-xs text-white/45">
                                            {{ number_format((int) ($profile->square_customer_link_count ?? 0)) }} Square customers ·
                                            {{ number_format((int) ($profile->shopify_order_link_count ?? 0)) }} Shopify orders
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="text-white/80">{{ $profile->email ?: 'No email' }}</div>
                                        <div class="mt-1 text-white/70">{{ $profile->phone ?: 'No phone' }}</div>
                                        <div class="mt-2 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold {{ $hasEmail || $hasPhone ? 'border-emerald-300/30 bg-emerald-500/10 text-emerald-100' : 'border-amber-300/30 bg-amber-500/10 text-amber-100' }}">
                                            {{ $hasEmail || $hasPhone ? 'Reachable' : 'Missing both' }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-white/75">${{ number_format(((int) ($profile->tracked_spend_cents ?? 0)) / 100, 2) }}</td>
                                    <td class="px-4 py-3 align-top text-white/75">{{ number_format((int) ($profile->candle_cash_balance ?? 0)) }}</td>
                                    <td class="px-4 py-3 align-top text-white/75">
                                        <div>{{ number_format((int) ($profile->review_count ?? 0)) }} reviews</div>
                                        <div class="mt-1 text-xs text-white/45">{{ ((int) ($profile->has_review_summary ?? 0)) === 1 ? 'Review summary present' : 'No review summary' }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-center text-white/55">No canonical profiles matched this overlap filter.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div>{{ $overlapProfiles->links() }}</div>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h2 class="text-lg font-semibold text-white">Public Event Utilities + Shopify Endpoints</h2>
            <x-admin.help-hint title="Architecture boundary">
                Laravel public pages here are minimal event/QR utilities only. Online storefront UI remains in Shopify theme widgets, which should call the JSON endpoints below.
            </x-admin.help-hint>
            <div class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">Public event routes</div>
                    <div class="mt-2 space-y-1 text-xs text-white/70">
                        <div><code>/events/{event-slug}/optin</code> - event QR consent/profile capture</div>
                        <div><code>/events/{event-slug}/rewards</code> - event reward balance + redemption lookup</div>
                        <div><code>/rewards/lookup</code> - generic public reward lookup utility</div>
                        <div><code>/marketing/consent/confirm</code> - confirmation page for capture flows</div>
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">Shopify widget endpoints</div>
                    <div class="mt-2 space-y-1 text-xs text-white/70">
                        <div><code>GET /shopify/marketing/v1/rewards/balance</code></div>
                        <div><code>GET /shopify/marketing/v1/rewards/available</code></div>
                        <div><code>GET /shopify/marketing/v1/rewards/history</code></div>
                        <div><code>POST /shopify/marketing/v1/rewards/redeem</code></div>
                        <div><code>POST /shopify/marketing/v1/consent/optin</code></div>
                        <div><code>POST /shopify/marketing/v1/consent/confirm</code></div>
                        <div><code>GET /shopify/marketing/v1/birthday/status</code></div>
                        <div><code>POST /shopify/marketing/v1/birthday/capture</code></div>
                        <div><code>POST /shopify/marketing/v1/birthday/claim</code></div>
                        <div><code>GET /shopify/marketing/v1/customer/status</code></div>
                    </div>
                    <x-admin.help-hint tone="neutral" title="Storefront request verification">
                        Shopify-facing endpoints now require signed requests:
                        <div class="mt-1"><code>X-Marketing-Timestamp</code> + <code>X-Marketing-Signature</code> (HMAC SHA-256 over method/path/query/body).</div>
                        <div class="mt-1">App-proxy mode can also be enabled via <code>signature</code> query verification.</div>
                        <div class="mt-1">Legacy <code>X-Marketing-Token</code> support is optional and disabled by default.</div>
                    </x-admin.help-hint>
                    <div class="mt-2 text-[11px] text-white/55">
                        Contract shape: <code>{`"ok":true,"version":"v1","data":{...},"meta":{"states":[...]}`}</code> and errors as <code>{`"ok":false,"version":"v1","error":{"code":"...","states":[...],"recovery_states":[...]}`}</code>.
                    </div>
                    <div class="mt-2 rounded-xl border border-white/10 bg-black/20 p-3 text-[11px] text-white/65">
                        Widget state examples:
                        <div class="mt-1"><code>known_customer_has_balance</code>, <code>reward_available</code>, <code>already_has_active_code</code>, <code>sms_requested</code>, <code>linked_customer</code>, <code>needs_verification</code>.</div>
                        <div class="mt-1">Recovery states include <code>verification_required</code>, <code>try_again_later</code>, <code>already_redeemed</code>, and <code>contact_support</code>.</div>
                    </div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold text-white">Recent Import/Sync Runs</h2>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Source</th>
                            <th class="px-4 py-3 text-left">File</th>
                            <th class="px-4 py-3 text-left">Started</th>
                            <th class="px-4 py-3 text-left">Finished</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($recentRuns as $run)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $run->type }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $run->status }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $run->source_label ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $run->file_name ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($run->started_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($run->finished_at)->format('Y-m-d H:i') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-white/55">No import runs logged yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold text-white">Event Source Mapping</h2>
                <a href="{{ route('marketing.providers-integrations.mappings.create') }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">Create Mapping</a>
            </div>

            <x-admin.help-hint title="Mapping workflow">
                Map noisy Square tax/source values to event instances. Unmapped values remain visible so attribution can be cleaned safely instead of guessed.
            </x-admin.help-hint>

            <form method="GET" action="{{ route('marketing.providers-integrations') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-5">
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Search</label>
                    <input type="text" name="search" value="{{ $search }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" placeholder="Raw value, normalized value, notes" />
                </div>
                <div class="md:col-span-3">
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Source System</label>
                    <select name="source_system" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="all">All</option>
                        @foreach($sourceSystems as $system)
                            <option value="{{ $system }}" @selected($sourceSystem === $system)>{{ $system }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Mapping Status</label>
                    <select name="mapped" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        <option value="all" @selected($mapped === 'all')>All</option>
                        <option value="mapped" @selected($mapped === 'mapped')>Mapped</option>
                        <option value="unmapped" @selected($mapped === 'unmapped')>Unmapped</option>
                    </select>
                </div>
                <div class="md:col-span-2 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-white">Apply</button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Source System</th>
                            <th class="px-4 py-3 text-left">Raw Value</th>
                            <th class="px-4 py-3 text-left">Normalized</th>
                            <th class="px-4 py-3 text-left">Event Instance</th>
                            <th class="px-4 py-3 text-left">Confidence</th>
                            <th class="px-4 py-3 text-left">Active</th>
                            <th class="px-4 py-3 text-left">Updated</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($mappings as $mapping)
                            <tr class="cursor-pointer hover:bg-white/5" onclick="window.location='{{ route('marketing.providers-integrations.mappings.edit', $mapping) }}'">
                                <td class="px-4 py-3 text-white/80">{{ $mapping->source_system }}</td>
                                <td class="px-4 py-3 text-white/80">{{ $mapping->raw_value }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $mapping->normalized_value ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $mapping->eventInstance?->title ?: 'Unmapped' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $mapping->confidence !== null ? number_format((float) $mapping->confidence, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $mapping->is_active ? 'Yes' : 'No' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($mapping->updated_at)->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.providers-integrations.mappings.edit', $mapping) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-white/55">No mappings found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-2">{{ $mappings->links() }}</div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h2 class="text-lg font-semibold text-white">Unmapped Source Values</h2>
            <p class="text-sm text-white/65">Distinct Square values seen in source data that do not yet have an event mapping.</p>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Source System</th>
                            <th class="px-4 py-3 text-left">Raw Value</th>
                            <th class="px-4 py-3 text-left">Normalized</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($unmappedValues as $value)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $value['source_system'] }}</td>
                                <td class="px-4 py-3 text-white/80">{{ $value['raw_value'] }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $value['normalized_value'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.providers-integrations.mappings.create', ['source_system' => $value['source_system'], 'raw_value' => $value['raw_value'], 'normalized_value' => $value['normalized_value']]) }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-white">
                                        Map value
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-white/55">No unmapped values detected from recent Square order records.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts::app>
