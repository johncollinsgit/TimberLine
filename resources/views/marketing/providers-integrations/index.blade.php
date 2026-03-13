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
                        <input type="number" name="limit" value="200" min="1" max="1000" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
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
