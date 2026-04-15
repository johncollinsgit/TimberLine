<x-layouts::app :title="'Campaign Detail'">
    <div class="mx-auto w-full max-w-[1850px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Campaign Detail"
            description="Campaign orchestration, approval queue execution, delivery visibility, and conversion attribution rollups."
            hint-title="Approval-first send flow"
            hint-text="Approved recipients are still re-validated at send time for consent, phone availability, and send-window guardrails before Twilio execution."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-2xl font-semibold text-zinc-950">{{ $campaign->name }}</h2>
                    <div class="mt-1 text-sm text-zinc-600">{{ $campaign->description ?: 'No campaign description.' }}</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-zinc-600">
                        <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1">Status: {{ $campaign->status }}</span>
                        <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1">Channel: {{ strtoupper($campaign->channel) }}</span>
                        <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1">Objective: {{ $campaign->objective ?: '—' }}</span>
                        <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1">Segment: {{ $campaign->segment?->name ?: 'Unlinked' }}</span>
                        <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1">
                            Groups: {{ $campaign->groups->isNotEmpty() ? $campaign->groups->pluck('name')->join(', ') : 'Unlinked' }}
                        </span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('marketing.campaigns.edit', $campaign) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">Edit Campaign</a>
                    <a href="{{ route('marketing.campaigns') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">Back to Campaigns</a>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-7">
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Recipients</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ array_sum($recipientSummary) }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Approved</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int) ($recipientSummary['approved'] ?? 0) }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Sent</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int) (($recipientSummary['sent'] ?? 0) + ($recipientSummary['sending'] ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Delivered</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int) ($recipientSummary['delivered'] ?? 0) }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Failed / Undelivered</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int) (($recipientSummary['failed'] ?? 0) + ($recipientSummary['undelivered'] ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Conversions</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int) ($conversionSummary['count'] ?? 0) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Revenue: ${{ number_format((float) ($conversionSummary['revenue'] ?? 0), 2) }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Campaign Rewards</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ (int) ($rewardIssuanceSummary['issued_count'] ?? 0) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">
                        Source: {{ $rewardIssuanceSummary['source_id'] ?? '—' }}
                    </div>
                </article>
            </div>

            <x-admin.help-hint tone="neutral" title="Send queue behavior">
                Approved does not mean sent yet. Send actions execute Twilio attempts, and delivery states may update later from callbacks. Retries preserve attempt history.
            </x-admin.help-hint>

            @php
                $emailReadinessStatus = $emailReadiness['status'] ?? 'not_configured';
                $emailCanSend = (bool) ($emailReadiness['can_send'] ?? false);
                $emailDryRun = (bool) ($emailReadiness['dry_run'] ?? false);
                $readinessLabel = match ($emailReadinessStatus) {
                    'ready' => $emailDryRun ? 'Email ready (dry run mode)' : 'Email ready for live send',
                    'unsupported' => 'Provider unsupported for runtime sends',
                    'incomplete' => 'Email setup incomplete',
                    'error' => 'Email readiness error',
                    default => 'Email not configured',
                };
                $readinessTone = match ($emailReadinessStatus) {
                    'ready' => $emailDryRun ? 'warning' : 'success',
                    'unsupported' => 'critical',
                    'incomplete' => 'critical',
                    'error' => 'critical',
                    default => 'warning',
                };
                $readinessSubtitle = match ($emailReadinessStatus) {
                    'ready' => (bool) ($emailReadiness['using_fallback_config'] ?? false)
                        ? 'Ready using fallback global configuration because tenant-specific settings are not present.'
                        : ($emailDryRun
                            ? 'Provider is configured, but global dry-run mode is enabled.'
                            : 'Provider is configured for tenant-scoped runtime sending.'),
                    'unsupported' => (string) (data_get($emailReadiness, 'notes.0') ?? 'Selected provider does not support app-driven runtime sends yet.'),
                    'incomplete' => 'Provider settings are incomplete for this tenant.',
                    'error' => (string) (data_get($emailReadiness, 'missing_requirements.0') ?? 'Provider validation returned an error.'),
                    default => 'Email is disabled or not configured for this tenant.',
                };
                $missingReasons = $emailReadiness['missing_requirements'] ?? $emailReadiness['missing_reasons'] ?? [];
            @endphp

            @if($campaign->channel === 'email')
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="text-xs uppercase tracking-[0.3em] text-zinc-500">Email readiness</div>
                            <div class="mt-1 text-base font-semibold text-zinc-950">{{ $readinessLabel }}</div>
                            <div class="mt-1 text-xs text-zinc-600">{{ $readinessSubtitle }}</div>
                        </div>
                        <span class="inline-flex items-center rounded-full border border-zinc-300 px-3 py-1 text-xs font-semibold text-zinc-900">
                            {{ strtoupper($readinessTone) }}
                        </span>
                    </div>
                    @if($missingReasons)
                        <div class="mt-3 space-y-1">
                            @foreach($missingReasons as $reason)
                                <div class="text-xs text-zinc-500">- {{ $reason }}</div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endif

            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('marketing.campaigns.prepare-recipients', $campaign) }}">
                    @csrf
                    <input type="hidden" name="limit" value="1000" />
                    <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">Prepare Recipients</button>
                </form>
                @if($campaign->channel === 'sms')
                    <form method="POST" action="{{ route('marketing.campaigns.issue-subscriber-reward', $campaign) }}" class="inline-flex items-center gap-2">
                        @csrf
                        <input type="hidden" name="amount" value="5" />
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/50 bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-900">
                            Grant $5 Candle Cash
                        </button>
                        <label class="inline-flex items-center gap-1 text-xs text-zinc-600">
                            <input type="checkbox" name="dry_run" value="1" class="rounded border-zinc-300 bg-zinc-50" /> Dry run
                        </label>
                    </form>
                @endif
                <form method="POST" action="{{ route('marketing.campaigns.recommendations.generate', $campaign) }}">
                    @csrf
                    <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-800">Generate Recommendations</button>
                </form>
                <form method="POST" action="{{ $campaign->channel === 'email' ? route('marketing.campaigns.send-approved-email', $campaign) : route('marketing.campaigns.send-approved-sms', $campaign) }}" class="inline-flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="limit" value="500" />
                    @if($campaign->channel === 'email')
                        @php
                            $statusKey = $emailReadinessStatus;
                            $buttonText = match ($statusKey) {
                                'ready' => $emailDryRun ? 'Run Dry Run for Approved Email' : 'Send Approved Email',
                                'unsupported' => 'Provider unsupported',
                                'incomplete' => 'Email setup incomplete',
                                'error' => 'Readiness error',
                                default => 'Email not configured',
                            };
                            $buttonDisabled = ! $emailCanSend;
                            $includeDryRunInput = $statusKey === 'ready' && $emailDryRun;
                        @endphp
                        <button type="submit" class="inline-flex rounded-full border border-sky-300/40 bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-900 disabled:bg-zinc-100 disabled:text-slate-300" {{ $buttonDisabled ? 'disabled' : '' }}>
                            {{ $buttonText }}
                        </button>
                        @if($includeDryRunInput)
                            <input type="hidden" name="dry_run" value="1" />
                        @else
                            <label class="inline-flex items-center gap-1 text-xs text-zinc-600">
                                <input type="checkbox" name="dry_run" value="1" class="rounded border-zinc-300 bg-zinc-50" /> Dry run
                            </label>
                        @endif
                    @else
                        <button type="submit" class="inline-flex rounded-full border border-sky-300/40 bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-900">
                            Send Approved {{ strtoupper($campaign->channel) }}
                        </button>
                    @endif
                </form>
            </div>

            @if($campaign->channel === 'email')
                @php
                    $diag = $diagnostics ?? [];
                    $tracking = (array) ($diag['recipient_tracking'] ?? []);
                    $webhookHealth = (array) ($diag['webhook_health'] ?? []);
                    $providerContext = (array) ($diag['provider_context'] ?? []);
                    $providerResolutionRows = collect((array) ($providerContext['by_resolution_source'] ?? []));
                    $providerReadinessRows = collect((array) ($providerContext['by_readiness_status'] ?? []));
                    $providerRuntimeRows = collect((array) ($providerContext['by_runtime_path'] ?? []));
                    $rows = collect((array) ($diag['deliveries'] ?? []))->take(14);
                    $status = $diag['overall_status'] ?? 'ready';
                    $statusLabel = match ($status) {
                        'ready' => 'Ready',
                        'needs_config' => 'Needs config',
                        'awaiting_webhook' => 'Send attempted, awaiting webhook',
                        'webhook_received' => 'Webhook received',
                        'error' => 'Error / needs review',
                        default => 'Unknown',
                    };
                    $statusClass = match ($status) {
                        'ready' => 'border-sky-300/40 bg-sky-100 text-sky-900',
                        'needs_config' => 'border-slate-300/40 bg-slate-500/20 text-slate-100',
                        'awaiting_webhook' => 'border-amber-300/40 bg-amber-100 text-amber-900',
                        'webhook_received' => 'border-emerald-300/40 bg-emerald-100 text-emerald-900',
                        'error' => 'border-rose-300/40 bg-rose-100 text-rose-900',
                        default => 'border-zinc-300 bg-zinc-50 text-zinc-700',
                    };
                    $healthIndicator = $webhookHealth['indicator'] ?? 'healthy';
                    $healthLabel = match ($healthIndicator) {
                        'healthy' => 'Healthy',
                        'delayed' => 'Delayed',
                        'missing_events' => 'Missing events',
                        'failures_detected' => 'Failures detected',
                        default => 'Unknown',
                    };
                    $healthClass = match ($healthIndicator) {
                        'healthy' => 'border-zinc-300 bg-emerald-100 text-emerald-900',
                        'delayed' => 'border-amber-300/35 bg-amber-100 text-amber-900',
                        'missing_events' => 'border-sky-300/35 bg-sky-100 text-sky-900',
                        'failures_detected' => 'border-rose-300/35 bg-rose-100 text-rose-900',
                        default => 'border-zinc-300 bg-zinc-50 text-zinc-700',
                    };
                    $smokeConfigured = (bool) ($diag['smoke_test_configured'] ?? false);
                    $smokeRecipient = (string) ($diag['smoke_test_recipient'] ?? '');
                    $summaryCards = [
                        ['label' => 'Smoke test configured', 'value' => $smokeConfigured ? 'Yes' : 'Missing'],
                        ['label' => 'Last smoke test', 'value' => optional($diag['last_smoke_test_attempt_at'] ?? null)->format('Y-m-d H:i') ?? 'Never'],
                        ['label' => 'Last webhook', 'value' => optional($diag['last_webhook_at'] ?? null)->format('Y-m-d H:i') ?? 'None'],
                        ['label' => 'Last live send', 'value' => optional($diag['last_live_send_at'] ?? null)->format('Y-m-d H:i') ?? 'Never'],
                        ['label' => 'Smoke recipient', 'value' => $smokeConfigured ? $smokeRecipient : 'Configure env'],
                        ['label' => 'Fallback-path sends', 'value' => (string) (int) $providerResolutionRows->where('key', 'fallback')->sum('attempted')],
                    ];
                @endphp

                <section class="mt-5 rounded-3xl border border-zinc-200 bg-gradient-to-b from-white/[0.12] via-white/[0.04] to-black/20 p-5 sm:p-6 space-y-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold tracking-wide text-zinc-950">Delivery Diagnostics</h3>
                            <p class="text-xs text-zinc-500">Readiness, smoke verification, provider acceptance, webhook health, and recipient outcomes in one view.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
                            <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $healthClass }}">{{ $healthLabel }}</span>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        @foreach($summaryCards as $card)
                            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-600">
                                <div class="text-[0.65rem] uppercase tracking-[0.3em] text-zinc-500">{{ $card['label'] }}</div>
                                <div class="mt-1 text-base font-semibold text-zinc-950">{{ $card['value'] }}</div>
                            </article>
                        @endforeach
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
                        <div class="font-semibold text-zinc-950">Operator hint</div>
                        <div class="mt-1 text-xs text-zinc-600">{{ $diag['overall_hint'] ?? 'No diagnostics hint available yet.' }}</div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="flex items-center justify-between">
                                <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Recipient-level Tracking</div>
                                <div class="text-xs text-zinc-500">{{ (int) ($tracking['total_deliveries'] ?? 0) }} deliveries</div>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-zinc-700">
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Delivered: <span class="font-semibold text-zinc-950">{{ (int) ($tracking['delivered_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Opened: <span class="font-semibold text-zinc-950">{{ (int) ($tracking['open_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Clicked: <span class="font-semibold text-zinc-950">{{ (int) ($tracking['click_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Failures: <span class="font-semibold text-zinc-950">{{ (int) ($tracking['failure_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Bounce/Drop/Deferred: <span class="font-semibold text-zinc-950">{{ (int) ($tracking['bounce_drop_deferred_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Unsub/Spam: <span class="font-semibold text-zinc-950">{{ (int) (($tracking['unsubscribe_count'] ?? 0) + ($tracking['spam_report_count'] ?? 0)) }}</span></div>
                            </div>
                        </article>

                        <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Webhook Health</div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-zinc-700">
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Last webhook: <span class="font-semibold text-zinc-950">{{ optional($webhookHealth['last_webhook_at'] ?? null)->format('Y-m-d H:i') ?? 'None' }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Recent webhook events: <span class="font-semibold text-zinc-950">{{ (int) ($webhookHealth['recent_webhook_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">With ID but no events: <span class="font-semibold text-zinc-950">{{ (int) ($webhookHealth['deliveries_with_message_id_no_events'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Awaiting too long: <span class="font-semibold text-zinc-950">{{ (int) ($webhookHealth['deliveries_awaiting_webhook_overdue'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 col-span-2">Failure events: <span class="font-semibold text-zinc-950">{{ (int) ($webhookHealth['failure_event_count'] ?? 0) }}</span></div>
                            </div>
                            <p class="mt-3 text-xs text-zinc-500">{{ $webhookHealth['hint'] ?? 'No webhook diagnostics available yet.' }}</p>
                        </article>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-3">
                        <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Resolution Source</div>
                            <div class="mt-3 space-y-2 text-xs text-zinc-700">
                                @forelse($providerResolutionRows as $row)
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="font-semibold text-zinc-950">{{ $row['label'] ?? 'Legacy / unavailable' }}</div>
                                        <div class="mt-1 text-zinc-600">
                                            Attempted {{ (int) ($row['attempted'] ?? 0) }}
                                            · Sent {{ (int) ($row['sent'] ?? 0) }}
                                            · Failed {{ (int) ($row['failed'] ?? 0) }}
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-zinc-500">No provider-resolution context rows yet.</div>
                                @endforelse
                            </div>
                        </article>

                        <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Readiness at Attempt Time</div>
                            <div class="mt-3 space-y-2 text-xs text-zinc-700">
                                @forelse($providerReadinessRows as $row)
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="font-semibold text-zinc-950">{{ $row['label'] ?? 'Legacy / unavailable' }}</div>
                                        <div class="mt-1 text-zinc-600">
                                            Attempted {{ (int) ($row['attempted'] ?? 0) }}
                                            · Unsupported {{ (int) ($row['unsupported'] ?? 0) }}
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-zinc-500">No readiness-context rows yet.</div>
                                @endforelse
                            </div>
                        </article>

                        <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Runtime Path</div>
                            <div class="mt-3 space-y-2 text-xs text-zinc-700">
                                @forelse($providerRuntimeRows as $row)
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <div class="font-semibold text-zinc-950">{{ $row['label'] ?? 'Legacy / unavailable' }}</div>
                                        <div class="mt-1 text-zinc-600">Attempted {{ (int) ($row['attempted'] ?? 0) }}</div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-zinc-500">No runtime-path rows yet.</div>
                                @endforelse
                            </div>
                        </article>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('marketing.campaigns.send-smoke-test-email', $campaign) }}">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex rounded-full border border-amber-300/50 bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-900 disabled:cursor-not-allowed disabled:border-zinc-300 disabled:bg-zinc-100 disabled:text-zinc-500"
                                {{ $smokeConfigured ? '' : 'disabled' }}
                            >
                                {{ $smokeConfigured ? 'Send Smoke Test to ' . $smokeRecipient : 'Smoke Test Unavailable' }}
                            </button>
                        </form>
                        @if(! $smokeConfigured)
                            <span class="text-xs text-zinc-500">Set <code class="font-mono text-[0.7rem]">MARKETING_EMAIL_SMOKE_TEST_RECIPIENT</code> to enable staging-safe smoke tests.</span>
                        @else
                            <span class="text-xs text-zinc-500">Smoke tests only target the configured recipient and never send to the full campaign audience.</span>
                        @endif
                    </div>

                    <div class="space-y-2">
                        @if($rows->isEmpty())
                            <p class="text-sm text-zinc-500">No deliveries yet. Run a smoke test or approved send to populate diagnostics.</p>
                        @else
                            <div class="overflow-hidden rounded-2xl border border-zinc-300 bg-zinc-50">
                                <table class="min-w-full text-left text-xs text-zinc-600">
                                    <thead>
                                        <tr class="border-b border-zinc-200 bg-zinc-100 text-[0.65rem] uppercase tracking-[0.2em] text-zinc-500">
                                            <th class="px-3 py-2">Recipient</th>
                                            <th class="px-3 py-2">Mode</th>
                                            <th class="px-3 py-2">Status</th>
                                            <th class="px-3 py-2">Sent At</th>
                                            <th class="px-3 py-2">Provider Context</th>
                                            <th class="px-3 py-2">Provider ID</th>
                                            <th class="px-3 py-2">Webhook</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rows as $row)
                                            @php
                                                $statusTone = match ($row['status_tone'] ?? 'neutral') {
                                                    'success' => 'border-zinc-300 bg-emerald-100 text-emerald-900',
                                                    'warning' => 'border-amber-300/35 bg-amber-100 text-amber-900',
                                                    'danger' => 'border-rose-300/35 bg-rose-100 text-rose-900',
                                                    default => 'text-zinc-500',
                                                };
                                            @endphp
                                            <tr class="border-b border-zinc-200 text-[0.78rem]">
                                                <td class="px-3 py-2">
                                                    <div class="font-semibold text-zinc-950">{{ $row['recipient_email'] }}</div>
                                                    <div class="text-[0.65rem] text-zinc-500">{{ $row['recipient_phone'] ?: 'No phone on profile' }}</div>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <span class="inline-flex items-center gap-1 rounded-full border border-zinc-300 bg-zinc-50 px-2 py-0.5 text-[0.65rem] uppercase tracking-[0.2em] text-zinc-700">
                                                        {{ $row['mode_label'] }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[0.7rem] font-semibold {{ $statusTone }}">{{ $row['status_label'] }}</span>
                                                </td>
                                                <td class="px-3 py-2 text-[0.7rem] text-zinc-500">
                                                    {{ optional($row['sent_at'] ?? null)->format('Y-m-d H:i') ?? 'Pending' }}
                                                </td>
                                                <td class="px-3 py-2 text-[0.7rem] text-zinc-500">
                                                    <div class="font-semibold text-zinc-800">{{ strtoupper((string) ($row['provider'] ?? 'unknown')) }}</div>
                                                    <div class="text-[0.65rem] text-zinc-500">
                                                        {{ $row['provider_resolution_source_label'] ?? 'Legacy / unavailable' }}
                                                        · {{ $row['provider_readiness_status_label'] ?? 'Legacy / unavailable' }}
                                                    </div>
                                                    @if(($row['provider_using_fallback_config'] ?? false) === true)
                                                        <div class="text-[0.65rem] text-amber-200">Using fallback config</div>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-[0.7rem] text-zinc-500">
                                                    @if($row['provider_message_id'])
                                                        <span title="{{ $row['provider_message_id'] }}">{{ $row['provider_message_id_short'] }}</span>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-[0.7rem] text-zinc-500">
                                                    @if($row['last_webhook_event'])
                                                        <span class="font-semibold text-zinc-800">{{ \Illuminate\Support\Str::headline((string) $row['last_webhook_event']) }}</span>
                                                        <div class="text-[0.65rem] text-zinc-500">{{ optional($row['last_webhook_at'] ?? null)->format('Y-m-d H:i') ?? '—' }}</div>
                                                    @else
                                                        none
                                                    @endif
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="7" class="bg-zinc-50 px-3 py-2 text-[0.65rem] text-zinc-500">
                                                    <details>
                                                        <summary class="cursor-pointer text-zinc-600">Details</summary>
                                                        <div class="mt-1 space-y-1">
                                                            <div>Delivery ID: {{ $row['id'] }}</div>
                                                            <div>Mode: {{ $row['mode_label'] }}</div>
                                                            <div>Provider path: {{ $row['provider_runtime_path_label'] ?? 'Legacy / unavailable' }}</div>
                                                            <div>Provider accepted: {{ $row['provider_accepted'] ? 'Yes' : 'No' }}</div>
                                                            <div>Provider message ID: {{ $row['provider_message_id'] ?: ($row['sendgrid_message_id'] ?: 'none') }}</div>
                                                            <div>Webhook events stored: {{ (int) ($row['webhook_event_count'] ?? 0) }}</div>
                                                            <div>Last webhook event: {{ $row['last_webhook_event'] ? \Illuminate\Support\Str::headline((string) $row['last_webhook_event']) : 'none' }}</div>
                                                            <div>Engagement: delivered {{ $row['delivered'] ? 'yes' : 'no' }}, opened {{ $row['opened'] ? 'yes' : 'no' }}, clicked {{ $row['clicked'] ? 'yes' : 'no' }}</div>
                                                            @if($row['hint'])
                                                                <div class="text-amber-200">{{ $row['hint'] }}</div>
                                                            @endif
                                                        </div>
                                                    </details>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </section>
            @endif
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-zinc-950">Campaign Variants</h3>
                </div>

                <x-admin.help-hint tone="neutral" title="Variant testing">
                    Maintain at least two active variants when practical. Use control + weighted variants for staged testing and recommendation feedback.
                </x-admin.help-hint>

                <div class="space-y-3">
                    @forelse($campaign->variants as $variant)
                        <form method="POST" action="{{ route('marketing.campaigns.variants.update', [$campaign, $variant]) }}" class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-3">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-2 md:grid-cols-2">
                                <input type="text" name="name" value="{{ $variant->name }}" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                                <select name="template_id" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                                    <option value="">No Template</option>
                                    @foreach($templates as $template)
                                        <option value="{{ $template->id }}" @selected((int) $variant->template_id === (int) $template->id)>{{ $template->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <textarea name="message_text" rows="2" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">{{ $variant->message_text }}</textarea>
                            <div class="grid gap-2 md:grid-cols-5">
                                <input type="text" name="variant_key" value="{{ $variant->variant_key }}" placeholder="Key" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                                <input type="number" name="weight" min="1" max="1000" value="{{ $variant->weight }}" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                                <select name="status" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                                    @foreach(['draft', 'active', 'paused'] as $status)
                                        <option value="{{ $status }}" @selected($variant->status === $status)>{{ $status }}</option>
                                    @endforeach
                                </select>
                                <label class="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700">
                                    <input type="checkbox" name="is_control" value="1" @checked($variant->is_control) class="rounded border-zinc-300 bg-zinc-50" />
                                    Control
                                </label>
                                <button type="submit" class="rounded-xl border border-zinc-300 bg-zinc-100 px-3 py-2 text-xs font-semibold text-zinc-800">Save Variant</button>
                            </div>
                            <textarea name="notes" rows="1" placeholder="Notes" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700">{{ $variant->notes }}</textarea>
                        </form>
                    @empty
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">No variants yet.</div>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('marketing.campaigns.variants.store', $campaign) }}" class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-4 space-y-3">
                    @csrf
                    <div class="text-sm font-semibold text-zinc-950">Add Variant</div>
                    <div class="grid gap-2 md:grid-cols-2">
                        <input type="text" name="name" required placeholder="Variant name" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        <select name="template_id" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            <option value="">No Template</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <textarea name="message_text" rows="2" required placeholder="Message text with variables like first_name" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950"></textarea>
                    <div class="grid gap-2 md:grid-cols-5">
                        <input type="text" name="variant_key" placeholder="A/B key" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        <input type="number" name="weight" min="1" max="1000" value="100" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        <select name="status" class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            @foreach(['draft', 'active', 'paused'] as $status)
                                <option value="{{ $status }}">{{ $status }}</option>
                            @endforeach
                        </select>
                        <label class="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700">
                            <input type="checkbox" name="is_control" value="1" class="rounded border-zinc-300 bg-zinc-50" />
                            Control
                        </label>
                        <button type="submit" class="rounded-xl border border-zinc-300 bg-emerald-100 px-3 py-2 text-xs font-semibold text-zinc-950">Add</button>
                    </div>
                    <textarea name="notes" rows="1" placeholder="Notes" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-700"></textarea>
                </form>
            </article>

            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
                <h3 class="text-sm font-semibold text-zinc-950">Recent Recommendations</h3>
                <div class="space-y-2">
                    @forelse($campaign->recommendations as $recommendation)
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                            <div class="text-sm font-semibold text-zinc-950">{{ $recommendation->title }}</div>
                            <div class="mt-1 text-xs text-zinc-600">{{ $recommendation->summary }}</div>
                            <div class="mt-1 text-xs text-zinc-500">Type: {{ $recommendation->type }} · Status: {{ $recommendation->status }} · Confidence: {{ $recommendation->confidence !== null ? number_format((float) $recommendation->confidence, 2) : '—' }}</div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-600">No recommendations generated for this campaign yet.</div>
                    @endforelse
                </div>

                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-sm font-semibold text-zinc-950">Conversion Summary</div>
                    <x-admin.help-hint tone="neutral" title="Attribution types">
                        `code_based` uses coupon matches, `last_touch` uses most recent eligible message in-window, and `assisted` records additional recent touches.
                    </x-admin.help-hint>
                    <div class="mt-2 text-xs text-zinc-600">
                        Conversions: {{ (int) ($conversionSummary['count'] ?? 0) }} · Revenue: ${{ number_format((float) ($conversionSummary['revenue'] ?? 0), 2) }}
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach((array) ($conversionSummary['types'] ?? []) as $type => $count)
                            <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2 py-0.5 text-[11px] text-zinc-600">{{ $type }}: {{ (int) $count }}</span>
                        @endforeach
                    </div>
                    <div class="mt-2 text-xs text-zinc-600">
                        Reward-assisted conversions: {{ (int) ($rewardConversionSummary['assisted_count'] ?? 0) }}
                    </div>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        @foreach((array) ($rewardConversionSummary['by_platform'] ?? []) as $platform => $count)
                            <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2 py-0.5 text-[11px] text-zinc-600">{{ strtoupper((string) $platform) }}: {{ (int) $count }}</span>
                        @endforeach
                    </div>
                </article>

                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-sm font-semibold text-zinc-950">Performance Snapshot</div>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-zinc-600">
                        <div>Sent: {{ (int) ($performanceSummary['sent'] ?? 0) }}</div>
                        <div>Delivered: {{ (int) ($performanceSummary['delivered'] ?? 0) }}</div>
                        <div>Opened: {{ (int) ($performanceSummary['opened'] ?? 0) }}</div>
                        <div>Clicked: {{ (int) ($performanceSummary['clicked'] ?? 0) }}</div>
                        <div>Converted: {{ (int) ($performanceSummary['converted'] ?? 0) }}</div>
                        <div>Revenue: ${{ number_format((float) ($performanceSummary['revenue'] ?? 0), 2) }}</div>
                    </div>
                    <div class="mt-2 text-xs text-zinc-500">
                        These metrics are derived from real delivery + conversion outcomes and feed recommendation scoring.
                    </div>
                </article>

                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-sm font-semibold text-zinc-950">Timing Insight</div>
                    <x-admin.help-hint tone="neutral" title="Send-time optimization">
                        Timing suggestions are rule-based and advisory. Applying them still requires explicit admin choice and does not auto-send.
                    </x-admin.help-hint>
                    @if($timingInsight)
                        <div class="mt-2 text-sm text-zinc-700">
                            Suggested hour: <span class="font-semibold text-zinc-950">{{ str_pad((string) (int) ($timingInsight->recommended_hour ?? 13), 2, '0', STR_PAD_LEFT) }}:00</span>
                            · {{ $timingInsight->recommended_daypart ?: 'daypart n/a' }}
                        </div>
                        <div class="mt-1 text-xs text-zinc-500">Confidence: {{ number_format((float) ($timingInsight->confidence ?? 0), 2) }}</div>
                    @else
                        <div class="mt-2 text-xs text-zinc-500">No timing insight available yet for this campaign context.</div>
                    @endif
                </article>
            </article>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-zinc-950">Variant Performance Comparison</h3>
            <x-admin.help-hint tone="neutral" title="Learning model scope">
                Variant comparisons are computed from observed sends, engagement, and conversions. Variants are never auto-promoted or auto-paused.
            </x-admin.help-hint>
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Variant</th>
                            <th class="px-4 py-3 text-left">Channel</th>
                            <th class="px-4 py-3 text-left">Sent</th>
                            <th class="px-4 py-3 text-left">Delivered</th>
                            <th class="px-4 py-3 text-left">Opened</th>
                            <th class="px-4 py-3 text-left">Clicked</th>
                            <th class="px-4 py-3 text-left">Converted</th>
                            <th class="px-4 py-3 text-left">Conversion Rate</th>
                            <th class="px-4 py-3 text-left">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse((array) ($performanceSummary['variant_rows'] ?? []) as $row)
                            @php
                                $variantName = 'No variant';
                                if (!empty($row['variant_id'])) {
                                    $found = $campaign->variants->firstWhere('id', (int) $row['variant_id']);
                                    $variantName = $found?->name ?: ('Variant #' . (int) $row['variant_id']);
                                }
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-zinc-700">{{ $variantName }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ strtoupper((string) ($row['channel'] ?? $campaign->channel)) }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ (int) ($row['sent_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ (int) ($row['delivered_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ (int) ($row['opened_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ (int) ($row['clicked_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ (int) ($row['converted_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ number_format(((float) ($row['conversion_rate'] ?? 0)) * 100, 1) }}%</td>
                                <td class="px-4 py-3 text-zinc-600">${{ number_format((float) ($row['attributed_revenue'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-zinc-500">No variant performance data available yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-zinc-950">Recipient Approval + Send Queue</h3>
            <x-admin.help-hint tone="neutral" title="Queue controls">
                Approvals are separate from execution by design. Sends can be run in dry-run mode, and failed recipients can be retried individually without deleting prior attempts.
            </x-admin.help-hint>

            <form method="POST" action="{{ $campaign->channel === 'email' ? route('marketing.campaigns.send-selected-email', $campaign) : route('marketing.campaigns.send-selected-sms', $campaign) }}" class="space-y-3">
                @csrf
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-zinc-500">Select approved recipients below for targeted send execution.</div>
                    <div class="inline-flex items-center gap-2">
                        <label class="inline-flex items-center gap-1 text-xs text-zinc-600">
                            <input type="checkbox" name="dry_run" value="1" class="rounded border-zinc-300 bg-zinc-50" /> Dry run
                        </label>
                        <button type="submit" class="inline-flex rounded-full border border-sky-300/40 bg-sky-100 px-3 py-1.5 text-xs font-semibold text-sky-900">Send Selected Approved</button>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600">
                            <tr>
                                <th class="px-4 py-3 text-left">Select</th>
                                <th class="px-4 py-3 text-left">Profile</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">Variant</th>
                                <th class="px-4 py-3 text-left">Reason Codes</th>
                                <th class="px-4 py-3 text-left">Last Delivery</th>
                                <th class="px-4 py-3 text-left">Message Preview</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200">
                            @forelse($approvalQueue as $recipient)
                                <tr>
                                    <td class="px-4 py-3">
                                        @if($recipient->status === 'approved')
                                            <input type="checkbox" name="recipient_ids[]" value="{{ $recipient->id }}" class="rounded border-zinc-300 bg-zinc-50" />
                                        @else
                                            <span class="text-xs text-zinc-500">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-zinc-700">
                                        {{ trim((string) ($recipient->profile?->first_name . ' ' . $recipient->profile?->last_name)) ?: ('Profile #' . $recipient->marketing_profile_id) }}
                                        <div class="text-xs text-zinc-500">{{ $recipient->profile?->email ?: $recipient->profile?->phone ?: '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-700">{{ $recipient->status }}</td>
                                    <td class="px-4 py-3 text-zinc-700">{{ $recipient->variant?->name ?: '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach((array) $recipient->reason_codes as $reason)
                                                <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2 py-0.5 text-[11px] text-zinc-600">{{ $reason }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-600">
                                        @if($campaign->channel === 'email')
                                            @if($recipient->latestEmailDelivery)
                                                {{ $recipient->latestEmailDelivery->status }}
                                                <div class="text-xs text-zinc-500">{{ optional($recipient->latestEmailDelivery->sent_at)->format('Y-m-d H:i') ?: optional($recipient->latestEmailDelivery->created_at)->format('Y-m-d H:i') }}</div>
                                                @php
                                                    $latestProviderMessageId = $recipient->latestEmailDelivery->provider_message_id
                                                        ?: $recipient->latestEmailDelivery->sendgrid_message_id;
                                                    $latestProviderLabel = strtoupper(trim((string) ($recipient->latestEmailDelivery->provider ?: 'provider')));
                                                @endphp
                                                <div class="text-xs text-zinc-500">{{ $latestProviderMessageId ? ($latestProviderLabel . ' ID: ' . $latestProviderMessageId) : 'No provider ID' }}</div>
                                            @else
                                                —
                                            @endif
                                        @elseif($recipient->latestDelivery)
                                            {{ $recipient->latestDelivery->send_status }}
                                            <div class="text-xs text-zinc-500">{{ optional($recipient->latestDelivery->sent_at)->format('Y-m-d H:i') ?: optional($recipient->latestDelivery->created_at)->format('Y-m-d H:i') }}</div>
                                            <div class="text-xs text-zinc-500">{{ $recipient->latestDelivery->provider_message_id ?: 'No SID' }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-zinc-600">{{ \Illuminate\Support\Str::limit((string) ($recipient->variant?->message_text ?: data_get($recipient->recommendation_snapshot, 'suggested_message', '')), 110) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="inline-flex flex-wrap justify-end gap-2">
                                            @if($recipient->status === 'queued_for_approval')
                                                <form method="POST" action="{{ route('marketing.campaigns.recipients.approve', [$campaign, $recipient]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-3 py-1 text-xs font-semibold text-zinc-950">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('marketing.campaigns.recipients.reject', [$campaign, $recipient]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex rounded-full border border-amber-300/35 bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">Reject</button>
                                                </form>
                                            @endif
                                            @if(in_array($recipient->status, ['failed', 'undelivered'], true))
                                                <form method="POST" action="{{ $campaign->channel === 'email' ? route('marketing.campaigns.recipients.retry-email', [$campaign, $recipient]) : route('marketing.campaigns.recipients.retry-sms', [$campaign, $recipient]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex rounded-full border border-rose-300/35 bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-900">Retry</button>
                                                </form>
                                            @endif
                                            <a href="{{ route('marketing.customers.show', $recipient->profile) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">Profile</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-zinc-500">No recipients queued yet. Run "Prepare Recipients" first.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </form>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-zinc-950">{{ strtoupper($campaign->channel) }} Delivery Log</h3>
            <x-admin.help-hint tone="neutral" title="Delivery state updates">
                Provider callback events can arrive after initial send. Delivery statuses are updated idempotently and retained for auditability.
            </x-admin.help-hint>

            @if($campaign->channel === 'email')
                <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600">
                            <tr>
                                <th class="px-4 py-3 text-left">Recipient</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">Provider ID</th>
                                <th class="px-4 py-3 text-left">Sent</th>
                                <th class="px-4 py-3 text-left">Delivered</th>
                                <th class="px-4 py-3 text-left">Opened</th>
                                <th class="px-4 py-3 text-left">Clicked</th>
                                <th class="px-4 py-3 text-left">Failed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200">
                            @forelse($emailDeliveryLog as $delivery)
                                <tr>
                                    <td class="px-4 py-3 text-zinc-700">
                                        {{ trim((string) ($delivery->profile?->first_name . ' ' . $delivery->profile?->last_name)) ?: ('Profile #' . $delivery->marketing_profile_id) }}
                                        <div class="text-xs text-zinc-500">{{ $delivery->email ?: ($delivery->profile?->email ?: '—') }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-700">{{ $delivery->status }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ $delivery->provider_message_id ?: $delivery->sendgrid_message_id ?: '—' }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ optional($delivery->sent_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ optional($delivery->delivered_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ optional($delivery->opened_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ optional($delivery->clicked_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ optional($delivery->failed_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-zinc-500">No email delivery attempts logged for this campaign yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600">
                            <tr>
                                <th class="px-4 py-3 text-left">Recipient</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">Attempt</th>
                                <th class="px-4 py-3 text-left">Provider SID</th>
                                <th class="px-4 py-3 text-left">Sent</th>
                                <th class="px-4 py-3 text-left">Delivered</th>
                                <th class="px-4 py-3 text-left">Failure</th>
                                <th class="px-4 py-3 text-left">Message</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200">
                            @forelse($deliveryLog as $delivery)
                                <tr>
                                    <td class="px-4 py-3 text-zinc-700">
                                        {{ trim((string) ($delivery->profile?->first_name . ' ' . $delivery->profile?->last_name)) ?: ('Profile #' . $delivery->marketing_profile_id) }}
                                        <div class="text-xs text-zinc-500">{{ $delivery->to_phone ?: ($delivery->profile?->phone ?: '—') }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-zinc-700">{{ $delivery->send_status }}</td>
                                    <td class="px-4 py-3 text-zinc-700">#{{ (int) $delivery->attempt_number }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ $delivery->provider_message_id ?: '—' }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ optional($delivery->sent_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-zinc-600">{{ optional($delivery->delivered_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-zinc-600">
                                        @if($delivery->error_code || $delivery->error_message)
                                            <div>{{ $delivery->error_code ?: 'error' }}</div>
                                            <div class="text-xs text-zinc-500">{{ \Illuminate\Support\Str::limit((string) $delivery->error_message, 80) }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-zinc-600">{{ \Illuminate\Support\Str::limit((string) $delivery->rendered_message, 95) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-zinc-500">No delivery attempts logged for this campaign yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts::app>
