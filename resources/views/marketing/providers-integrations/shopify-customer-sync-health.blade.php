<x-layouts::app :title="'Shopify Customer Sync Health'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Shopify Customer Sync Health"
            description="Operational visibility for per-store customer webhook + provisioning health."
            hint-title="How to use this page"
            hint-text="This view is first-pass diagnostics. It combines webhook subscription checks, ingestion freshness, failure signals, and identity conflict backlog so staff can spot drift quickly."
        />

        @php
            $totals = (array) ($report['totals'] ?? []);
            $stores = collect((array) ($report['stores'] ?? []));
            $recentEvents = collect((array) ($report['recent_events'] ?? []));
            $windowHours = (int) ($report['window_hours'] ?? 72);
            $requiredTopics = collect((array) ($report['required_topics'] ?? []));
            $statusCardStyles = [
                'healthy' => 'border-zinc-300 bg-emerald-100 text-emerald-900',
                'warning' => 'border-amber-300/35 bg-amber-100 text-amber-900',
                'failing' => 'border-rose-300/35 bg-rose-100 text-rose-900',
                'unknown' => 'border-slate-300/35 bg-slate-500/10 text-slate-100',
            ];
            $statusBadgeStyles = [
                'healthy' => 'border-zinc-300 bg-emerald-100 text-emerald-900',
                'warning' => 'border-amber-300/35 bg-amber-100 text-amber-900',
                'failing' => 'border-rose-300/35 bg-rose-100 text-rose-900',
                'unknown' => 'border-slate-300/35 bg-slate-500/15 text-slate-100',
            ];
            $authBadgeStyles = [
                'healthy' => 'border-zinc-300 bg-emerald-100 text-emerald-900',
                'warning' => 'border-amber-300/35 bg-amber-100 text-amber-900',
                'failing' => 'border-rose-300/35 bg-rose-100 text-rose-900',
                'unknown' => 'border-slate-300/35 bg-slate-500/10 text-slate-100',
            ];
        @endphp

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Stores Audited</div>
                <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($totals['stores'] ?? 0)) }}</div>
                <div class="mt-1 text-xs text-zinc-500">Window: last {{ $windowHours }}h</div>
            </article>
            @foreach(['healthy' => 'Healthy', 'warning' => 'Warning', 'failing' => 'Failing', 'unknown' => 'Unknown'] as $status => $label)
                <article class="rounded-2xl border p-4 {{ $statusCardStyles[$status] }}">
                    <div class="text-xs uppercase tracking-[0.2em]">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-semibold">{{ number_format((int) ($totals[$status] ?? 0)) }}</div>
                </article>
            @endforeach
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Store Diagnostics</h2>
                    <p class="mt-1 text-sm text-zinc-600">
                        Required topics:
                        <span class="text-zinc-950">{{ $requiredTopics->isNotEmpty() ? $requiredTopics->implode(', ') : 'none configured' }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('marketing.providers-integrations') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-700">
                        Back to Connections
                    </a>
                    <a href="{{ route('marketing.providers-integrations.shopify-customer-sync-health', array_merge(request()->query(), ['refresh' => 1])) }}" class="inline-flex rounded-full border border-sky-300/35 bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-900">
                        Refresh Checks
                    </a>
                </div>
            </div>

            <x-admin.help-hint tone="neutral" title="How to interpret">
                Healthy means required webhooks are aligned, auth appears valid, and no recent provisioning/ingestion failures were detected. Warning or failing stores should be reviewed before running customer identity backfills.
            </x-admin.help-hint>

            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Store</th>
                            <th class="px-4 py-3 text-left">Overall</th>
                            <th class="px-4 py-3 text-left">Webhook Subscriptions</th>
                            <th class="px-4 py-3 text-left">Last Customer Webhook</th>
                            <th class="px-4 py-3 text-left">Recent Failures ({{ $windowHours }}h)</th>
                            <th class="px-4 py-3 text-left">Identity Conflicts</th>
                            <th class="px-4 py-3 text-left">Auth</th>
                            <th class="px-4 py-3 text-left">Operator Hint</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($stores as $row)
                            @php
                                $status = (string) ($row['status'] ?? 'unknown');
                                $statusClass = $statusBadgeStyles[$status] ?? $statusBadgeStyles['unknown'];
                                $webhook = (array) ($row['webhook'] ?? []);
                                $auth = (array) ($row['auth'] ?? []);
                                $authStatus = (string) ($auth['status'] ?? 'unknown');
                                $authClass = $authBadgeStyles[$authStatus] ?? $authBadgeStyles['unknown'];
                            @endphp
                            <tr>
                                <td class="px-4 py-3 align-top text-zinc-700">
                                    <div class="font-semibold text-zinc-950">{{ $row['store_name'] }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">
                                        key={{ $row['store_key'] }} · tenant={{ $row['tenant_id'] ?? 'n/a' }}
                                    </div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $row['shop_domain'] ?: 'domain unavailable' }}</div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                                        {{ $row['status_label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 align-top text-zinc-700">
                                    <div class="font-medium text-zinc-950">{{ $webhook['label'] ?? 'Unknown' }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $webhook['summary'] ?? 'No webhook summary available.' }}</div>
                                    <div class="mt-2 text-[11px] text-zinc-500">
                                        required={{ (int) ($webhook['required_count'] ?? 0) }}
                                        · missing={{ (int) ($webhook['missing_count'] ?? 0) }}
                                        · mismatched={{ (int) ($webhook['mismatch_count'] ?? 0) }}
                                        · failed={{ (int) ($webhook['failed_count'] ?? 0) }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-zinc-600">
                                    {{ optional($row['last_customer_webhook_ingested_at'] ?? null)->format('Y-m-d H:i') ?: 'None observed' }}
                                </td>
                                <td class="px-4 py-3 align-top text-zinc-700">
                                    <div>Provisioning: <span class="font-semibold text-zinc-950">{{ number_format((int) ($row['recent_provisioning_failures'] ?? 0)) }}</span></div>
                                    <div class="mt-1">Webhook ingest: <span class="font-semibold text-zinc-950">{{ number_format((int) ($row['recent_webhook_ingestion_failures'] ?? 0)) }}</span></div>
                                    <div class="mt-1 text-xs text-zinc-500">Context unresolved: {{ number_format((int) ($row['recent_unresolved_context_failures'] ?? 0)) }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">Open warnings/errors: {{ number_format((int) ($row['open_warning_events'] ?? 0)) }}/{{ number_format((int) ($row['open_error_events'] ?? 0)) }}</div>
                                </td>
                                <td class="px-4 py-3 align-top text-zinc-700">
                                    {{ number_format((int) ($row['unresolved_identity_conflicts'] ?? 0)) }}
                                </td>
                                <td class="px-4 py-3 align-top text-zinc-700">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $authClass }}">
                                        {{ $auth['label'] ?? 'Unknown' }}
                                    </span>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $auth['hint'] ?? 'No auth signal.' }}</div>
                                </td>
                                <td class="px-4 py-3 align-top text-xs text-zinc-500">
                                    {{ $row['status_hint'] ?? 'No hint available.' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-6 text-center text-zinc-500">
                                    No installed Shopify stores were found. Connect a store first to populate sync health diagnostics.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h2 class="text-lg font-semibold text-zinc-950">Recent Persisted Shopify Sync Events</h2>
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Occurred</th>
                            <th class="px-4 py-3 text-left">Store</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Severity</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Context</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($recentEvents as $event)
                            @php
                                $severity = (string) ($event['severity'] ?? 'info');
                                $severityClass = match ($severity) {
                                    'error' => 'border-rose-300/35 bg-rose-100 text-rose-900',
                                    'warning' => 'border-amber-300/35 bg-amber-100 text-amber-900',
                                    default => 'border-slate-300/35 bg-slate-500/10 text-slate-100',
                                };
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-zinc-600">
                                    {{ optional($event['occurred_at'] ?? null)->format('Y-m-d H:i') ?: '—' }}
                                </td>
                                <td class="px-4 py-3 text-zinc-700">
                                    {{ $event['store_key'] ?? 'unknown' }}
                                    <div class="text-xs text-zinc-500">tenant={{ $event['tenant_id'] ?? 'n/a' }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ \Illuminate\Support\Str::headline((string) ($event['event_type'] ?? 'unknown')) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold {{ $severityClass }}">
                                        {{ strtoupper($severity) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ strtoupper((string) ($event['status'] ?? 'open')) }}</td>
                                <td class="px-4 py-3 text-xs text-zinc-500">
                                    @if(!empty($event['topic']))
                                        <div>topic={{ $event['topic'] }}</div>
                                    @endif
                                    @if(!empty($event['reason']))
                                        <div>reason={{ $event['reason'] }}</div>
                                    @endif
                                    @if(!empty($event['message']))
                                        <div class="max-w-[420px] truncate" title="{{ $event['message'] }}">{{ $event['message'] }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-zinc-500">No persisted Shopify sync events recorded in the selected window yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts::app>
