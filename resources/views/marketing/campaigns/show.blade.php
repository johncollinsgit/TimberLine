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

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-2xl font-semibold text-white">{{ $campaign->name }}</h2>
                    <div class="mt-1 text-sm text-white/65">{{ $campaign->description ?: 'No campaign description.' }}</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-white/70">
                        <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2.5 py-1">Status: {{ $campaign->status }}</span>
                        <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2.5 py-1">Channel: {{ strtoupper($campaign->channel) }}</span>
                        <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2.5 py-1">Objective: {{ $campaign->objective ?: '—' }}</span>
                        <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2.5 py-1">Segment: {{ $campaign->segment?->name ?: 'Unlinked' }}</span>
                        <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2.5 py-1">
                            Groups: {{ $campaign->groups->isNotEmpty() ? $campaign->groups->pluck('name')->join(', ') : 'Unlinked' }}
                        </span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('marketing.campaigns.edit', $campaign) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Edit Campaign</a>
                    <a href="{{ route('marketing.campaigns') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Back to Campaigns</a>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-6">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Recipients</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ array_sum($recipientSummary) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Approved</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ (int) ($recipientSummary['approved'] ?? 0) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Sent</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ (int) (($recipientSummary['sent'] ?? 0) + ($recipientSummary['sending'] ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Delivered</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ (int) ($recipientSummary['delivered'] ?? 0) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Failed / Undelivered</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ (int) (($recipientSummary['failed'] ?? 0) + ($recipientSummary['undelivered'] ?? 0)) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Conversions</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ (int) ($conversionSummary['count'] ?? 0) }}</div>
                    <div class="mt-1 text-xs text-white/55">Revenue: ${{ number_format((float) ($conversionSummary['revenue'] ?? 0), 2) }}</div>
                </article>
            </div>

            <x-admin.help-hint tone="neutral" title="Send queue behavior">
                Approved does not mean sent yet. Send actions execute Twilio attempts, and delivery states may update later from callbacks. Retries preserve attempt history.
            </x-admin.help-hint>

            @php
                $emailReadinessStatus = $emailReadiness['status'] ?? 'disabled';
                $readinessLabel = match ($emailReadinessStatus) {
                    'ready_for_live_send' => 'Email ready for live send',
                    'dry_run_only' => 'Email configured (dry run mode)',
                    'misconfigured' => 'Email misconfigured',
                    default => 'Email sending disabled',
                };
                $readinessTone = match ($emailReadinessStatus) {
                    'ready_for_live_send' => 'success',
                    'dry_run_only' => 'warning',
                    default => 'critical',
                };
                $readinessSubtitle = match ($emailReadinessStatus) {
                    'ready_for_live_send' => 'SendGrid API key, sender name, and address are configured.',
                    'dry_run_only' => 'SendGrid is configured but MARKETING_EMAIL_DRY_RUN is on. Sends will not reach recipients.',
                    'misconfigured' => 'SendGrid sender info or API key is missing.',
                    default => 'MARKETING_EMAIL_ENABLED is off.',
                };
                $missingReasons = $emailReadiness['missing_reasons'] ?? [];
            @endphp

            @if($campaign->channel === 'email')
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="text-xs uppercase tracking-[0.3em] text-white/50">Email readiness</div>
                            <div class="mt-1 text-base font-semibold text-white">{{ $readinessLabel }}</div>
                            <div class="mt-1 text-xs text-white/65">{{ $readinessSubtitle }}</div>
                        </div>
                        <span class="inline-flex items-center rounded-full border border-white/15 px-3 py-1 text-xs font-semibold text-white/90">
                            {{ strtoupper($readinessTone) }}
                        </span>
                    </div>
                    @if($missingReasons)
                        <div class="mt-3 space-y-1">
                            @foreach($missingReasons as $reason)
                                <div class="text-xs text-white/60">- {{ $reason }}</div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endif

            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('marketing.campaigns.prepare-recipients', $campaign) }}">
                    @csrf
                    <input type="hidden" name="limit" value="1000" />
                    <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">Prepare Recipients</button>
                </form>
                <form method="POST" action="{{ route('marketing.campaigns.recommendations.generate', $campaign) }}">
                    @csrf
                    <button type="submit" class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">Generate Recommendations</button>
                </form>
                <form method="POST" action="{{ $campaign->channel === 'email' ? route('marketing.campaigns.send-approved-email', $campaign) : route('marketing.campaigns.send-approved-sms', $campaign) }}" class="inline-flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="limit" value="500" />
                    @if($campaign->channel === 'email')
                        @php
                            $statusKey = $emailReadinessStatus;
                            $buttonText = match ($statusKey) {
                                'ready_for_live_send' => 'Send Approved Email',
                                'dry_run_only' => 'Run Dry Run for Approved Email',
                                'misconfigured' => 'Email misconfigured',
                                default => 'Email disabled',
                            };
                            $buttonDisabled = in_array($statusKey, ['disabled', 'misconfigured'], true);
                            $includeDryRunInput = $statusKey === 'dry_run_only';
                        @endphp
                        <button type="submit" class="inline-flex rounded-full border border-sky-300/40 bg-sky-500/15 px-4 py-2 text-sm font-semibold text-sky-100 disabled:bg-white/10 disabled:text-slate-300" {{ $buttonDisabled ? 'disabled' : '' }}>
                            {{ $buttonText }}
                        </button>
                        @if($includeDryRunInput)
                            <input type="hidden" name="dry_run" value="1" />
                        @else
                            <label class="inline-flex items-center gap-1 text-xs text-white/70">
                                <input type="checkbox" name="dry_run" value="1" class="rounded border-white/20 bg-white/5" /> Dry run
                            </label>
                        @endif
                    @else
                        <button type="submit" class="inline-flex rounded-full border border-sky-300/40 bg-sky-500/15 px-4 py-2 text-sm font-semibold text-sky-100">
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
                        'ready' => 'border-sky-300/40 bg-sky-500/15 text-sky-100',
                        'needs_config' => 'border-slate-300/40 bg-slate-500/20 text-slate-100',
                        'awaiting_webhook' => 'border-amber-300/40 bg-amber-500/15 text-amber-100',
                        'webhook_received' => 'border-emerald-300/40 bg-emerald-500/15 text-emerald-100',
                        'error' => 'border-rose-300/40 bg-rose-500/15 text-rose-100',
                        default => 'border-white/20 bg-white/5 text-white/80',
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
                        'healthy' => 'border-emerald-300/35 bg-emerald-500/10 text-emerald-100',
                        'delayed' => 'border-amber-300/35 bg-amber-500/10 text-amber-100',
                        'missing_events' => 'border-sky-300/35 bg-sky-500/10 text-sky-100',
                        'failures_detected' => 'border-rose-300/35 bg-rose-500/10 text-rose-100',
                        default => 'border-white/20 bg-white/5 text-white/80',
                    };
                    $smokeConfigured = (bool) ($diag['smoke_test_configured'] ?? false);
                    $smokeRecipient = (string) ($diag['smoke_test_recipient'] ?? '');
                    $summaryCards = [
                        ['label' => 'Smoke test configured', 'value' => $smokeConfigured ? 'Yes' : 'Missing'],
                        ['label' => 'Last smoke test', 'value' => optional($diag['last_smoke_test_attempt_at'] ?? null)->format('Y-m-d H:i') ?? 'Never'],
                        ['label' => 'Last webhook', 'value' => optional($diag['last_webhook_at'] ?? null)->format('Y-m-d H:i') ?? 'None'],
                        ['label' => 'Last live send', 'value' => optional($diag['last_live_send_at'] ?? null)->format('Y-m-d H:i') ?? 'Never'],
                        ['label' => 'Smoke recipient', 'value' => $smokeConfigured ? $smokeRecipient : 'Configure env'],
                    ];
                @endphp

                <section class="mt-5 rounded-3xl border border-white/10 bg-gradient-to-b from-white/[0.12] via-white/[0.04] to-black/20 p-5 sm:p-6 space-y-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold tracking-wide text-white">Delivery Diagnostics</h3>
                            <p class="text-xs text-white/60">Readiness, smoke verification, SendGrid acceptance, webhook health, and recipient outcomes in one view.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
                            <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $healthClass }}">{{ $healthLabel }}</span>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        @foreach($summaryCards as $card)
                            <article class="rounded-2xl border border-white/5 bg-white/5 p-3 text-sm text-white/70">
                                <div class="text-[0.65rem] uppercase tracking-[0.3em] text-white/50">{{ $card['label'] }}</div>
                                <div class="mt-1 text-base font-semibold text-white">{{ $card['value'] }}</div>
                            </article>
                        @endforeach
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-black/20 px-4 py-3 text-sm text-white/80">
                        <div class="font-semibold text-white">Operator hint</div>
                        <div class="mt-1 text-xs text-white/65">{{ $diag['overall_hint'] ?? 'No diagnostics hint available yet.' }}</div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="flex items-center justify-between">
                                <div class="text-xs uppercase tracking-[0.2em] text-white/55">Recipient-level Tracking</div>
                                <div class="text-xs text-white/60">{{ (int) ($tracking['total_deliveries'] ?? 0) }} deliveries</div>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-white/75">
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Delivered: <span class="font-semibold text-white">{{ (int) ($tracking['delivered_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Opened: <span class="font-semibold text-white">{{ (int) ($tracking['open_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Clicked: <span class="font-semibold text-white">{{ (int) ($tracking['click_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Failures: <span class="font-semibold text-white">{{ (int) ($tracking['failure_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Bounce/Drop/Deferred: <span class="font-semibold text-white">{{ (int) ($tracking['bounce_drop_deferred_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Unsub/Spam: <span class="font-semibold text-white">{{ (int) (($tracking['unsubscribe_count'] ?? 0) + ($tracking['spam_report_count'] ?? 0)) }}</span></div>
                            </div>
                        </article>

                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="text-xs uppercase tracking-[0.2em] text-white/55">Webhook Health</div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-white/75">
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Last webhook: <span class="font-semibold text-white">{{ optional($webhookHealth['last_webhook_at'] ?? null)->format('Y-m-d H:i') ?? 'None' }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Recent webhook events: <span class="font-semibold text-white">{{ (int) ($webhookHealth['recent_webhook_count'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">With ID but no events: <span class="font-semibold text-white">{{ (int) ($webhookHealth['deliveries_with_message_id_no_events'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2">Awaiting too long: <span class="font-semibold text-white">{{ (int) ($webhookHealth['deliveries_awaiting_webhook_overdue'] ?? 0) }}</span></div>
                                <div class="rounded-xl border border-white/10 bg-black/25 px-3 py-2 col-span-2">Failure events: <span class="font-semibold text-white">{{ (int) ($webhookHealth['failure_event_count'] ?? 0) }}</span></div>
                            </div>
                            <p class="mt-3 text-xs text-white/60">{{ $webhookHealth['hint'] ?? 'No webhook diagnostics available yet.' }}</p>
                        </article>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('marketing.campaigns.send-smoke-test-email', $campaign) }}">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex rounded-full border border-amber-300/50 bg-amber-500/15 px-4 py-2 text-sm font-semibold text-amber-50 disabled:cursor-not-allowed disabled:border-white/20 disabled:bg-white/10 disabled:text-white/45"
                                {{ $smokeConfigured ? '' : 'disabled' }}
                            >
                                {{ $smokeConfigured ? 'Send Smoke Test to ' . $smokeRecipient : 'Smoke Test Unavailable' }}
                            </button>
                        </form>
                        @if(! $smokeConfigured)
                            <span class="text-xs text-white/60">Set <code class="font-mono text-[0.7rem]">MARKETING_EMAIL_SMOKE_TEST_RECIPIENT</code> to enable staging-safe smoke tests.</span>
                        @else
                            <span class="text-xs text-white/60">Smoke tests only target the configured recipient and never send to the full campaign audience.</span>
                        @endif
                    </div>

                    <div class="space-y-2">
                        @if($rows->isEmpty())
                            <p class="text-sm text-white/60">No deliveries yet. Run a smoke test or approved send to populate diagnostics.</p>
                        @else
                            <div class="overflow-hidden rounded-2xl border border-white/15 bg-white/5">
                                <table class="min-w-full text-left text-xs text-white/70">
                                    <thead>
                                        <tr class="border-b border-white/10 bg-white/10 text-[0.65rem] uppercase tracking-[0.2em] text-white/50">
                                            <th class="px-3 py-2">Recipient</th>
                                            <th class="px-3 py-2">Mode</th>
                                            <th class="px-3 py-2">Status</th>
                                            <th class="px-3 py-2">Sent At</th>
                                            <th class="px-3 py-2">SendGrid ID</th>
                                            <th class="px-3 py-2">Webhook</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rows as $row)
                                            @php
                                                $statusTone = match ($row['status_tone'] ?? 'neutral') {
                                                    'success' => 'border-emerald-300/35 bg-emerald-500/10 text-emerald-100',
                                                    'warning' => 'border-amber-300/35 bg-amber-500/10 text-amber-100',
                                                    'danger' => 'border-rose-300/35 bg-rose-500/10 text-rose-100',
                                                    default => 'text-white/60',
                                                };
                                            @endphp
                                            <tr class="border-b border-white/10 text-[0.78rem]">
                                                <td class="px-3 py-2">
                                                    <div class="font-semibold text-white">{{ $row['recipient_email'] }}</div>
                                                    <div class="text-[0.65rem] text-white/50">{{ $row['recipient_phone'] ?: 'No phone on profile' }}</div>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <span class="inline-flex items-center gap-1 rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[0.65rem] uppercase tracking-[0.2em] text-white/75">
                                                        {{ $row['mode_label'] }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[0.7rem] font-semibold {{ $statusTone }}">{{ $row['status_label'] }}</span>
                                                </td>
                                                <td class="px-3 py-2 text-[0.7rem] text-white/60">
                                                    {{ optional($row['sent_at'] ?? null)->format('Y-m-d H:i') ?? 'Pending' }}
                                                </td>
                                                <td class="px-3 py-2 text-[0.7rem] text-white/60">
                                                    @if($row['sendgrid_message_id'])
                                                        <span title="{{ $row['sendgrid_message_id'] }}">{{ $row['sendgrid_message_id_short'] }}</span>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-[0.7rem] text-white/60">
                                                    @if($row['last_webhook_event'])
                                                        <span class="font-semibold text-white/85">{{ \Illuminate\Support\Str::headline((string) $row['last_webhook_event']) }}</span>
                                                        <div class="text-[0.65rem] text-white/45">{{ optional($row['last_webhook_at'] ?? null)->format('Y-m-d H:i') ?? '—' }}</div>
                                                    @else
                                                        none
                                                    @endif
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="6" class="bg-white/5 px-3 py-2 text-[0.65rem] text-white/60">
                                                    <details>
                                                        <summary class="cursor-pointer text-white/70">Details</summary>
                                                        <div class="mt-1 space-y-1">
                                                            <div>Delivery ID: {{ $row['id'] }}</div>
                                                            <div>Mode: {{ $row['mode_label'] }}</div>
                                                            <div>Provider accepted: {{ $row['provider_accepted'] ? 'Yes' : 'No' }}</div>
                                                            <div>SendGrid message ID: {{ $row['sendgrid_message_id'] ?: 'none' }}</div>
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
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-white">Campaign Variants</h3>
                </div>

                <x-admin.help-hint tone="neutral" title="Variant testing">
                    Maintain at least two active variants when practical. Use control + weighted variants for staged testing and recommendation feedback.
                </x-admin.help-hint>

                <div class="space-y-3">
                    @forelse($campaign->variants as $variant)
                        <form method="POST" action="{{ route('marketing.campaigns.variants.update', [$campaign, $variant]) }}" class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-3">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-2 md:grid-cols-2">
                                <input type="text" name="name" value="{{ $variant->name }}" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                                <select name="template_id" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                                    <option value="">No Template</option>
                                    @foreach($templates as $template)
                                        <option value="{{ $template->id }}" @selected((int) $variant->template_id === (int) $template->id)>{{ $template->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <textarea name="message_text" rows="2" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">{{ $variant->message_text }}</textarea>
                            <div class="grid gap-2 md:grid-cols-5">
                                <input type="text" name="variant_key" value="{{ $variant->variant_key }}" placeholder="Key" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                                <input type="number" name="weight" min="1" max="1000" value="{{ $variant->weight }}" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                                <select name="status" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                                    @foreach(['draft', 'active', 'paused'] as $status)
                                        <option value="{{ $status }}" @selected($variant->status === $status)>{{ $status }}</option>
                                    @endforeach
                                </select>
                                <label class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/80">
                                    <input type="checkbox" name="is_control" value="1" @checked($variant->is_control) class="rounded border-white/20 bg-white/5" />
                                    Control
                                </label>
                                <button type="submit" class="rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-xs font-semibold text-white/85">Save Variant</button>
                            </div>
                            <textarea name="notes" rows="1" placeholder="Notes" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/80">{{ $variant->notes }}</textarea>
                        </form>
                    @empty
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-white/65">No variants yet.</div>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('marketing.campaigns.variants.store', $campaign) }}" class="rounded-2xl border border-dashed border-white/20 bg-white/5 p-4 space-y-3">
                    @csrf
                    <div class="text-sm font-semibold text-white">Add Variant</div>
                    <div class="grid gap-2 md:grid-cols-2">
                        <input type="text" name="name" required placeholder="Variant name" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                        <select name="template_id" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            <option value="">No Template</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <textarea name="message_text" rows="2" required placeholder="Message text with variables like first_name" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white"></textarea>
                    <div class="grid gap-2 md:grid-cols-5">
                        <input type="text" name="variant_key" placeholder="A/B key" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                        <input type="number" name="weight" min="1" max="1000" value="100" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                        <select name="status" class="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            @foreach(['draft', 'active', 'paused'] as $status)
                                <option value="{{ $status }}">{{ $status }}</option>
                            @endforeach
                        </select>
                        <label class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/80">
                            <input type="checkbox" name="is_control" value="1" class="rounded border-white/20 bg-white/5" />
                            Control
                        </label>
                        <button type="submit" class="rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-3 py-2 text-xs font-semibold text-white">Add</button>
                    </div>
                    <textarea name="notes" rows="1" placeholder="Notes" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-white/80"></textarea>
                </form>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <h3 class="text-sm font-semibold text-white">Recent Recommendations</h3>
                <div class="space-y-2">
                    @forelse($campaign->recommendations as $recommendation)
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                            <div class="text-sm font-semibold text-white">{{ $recommendation->title }}</div>
                            <div class="mt-1 text-xs text-white/65">{{ $recommendation->summary }}</div>
                            <div class="mt-1 text-xs text-white/55">Type: {{ $recommendation->type }} · Status: {{ $recommendation->status }} · Confidence: {{ $recommendation->confidence !== null ? number_format((float) $recommendation->confidence, 2) : '—' }}</div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-3 text-sm text-white/65">No recommendations generated for this campaign yet.</div>
                    @endforelse
                </div>

                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">Conversion Summary</div>
                    <x-admin.help-hint tone="neutral" title="Attribution types">
                        `code_based` uses coupon matches, `last_touch` uses most recent eligible message in-window, and `assisted` records additional recent touches.
                    </x-admin.help-hint>
                    <div class="mt-2 text-xs text-white/65">
                        Conversions: {{ (int) ($conversionSummary['count'] ?? 0) }} · Revenue: ${{ number_format((float) ($conversionSummary['revenue'] ?? 0), 2) }}
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach((array) ($conversionSummary['types'] ?? []) as $type => $count)
                            <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/70">{{ $type }}: {{ (int) $count }}</span>
                        @endforeach
                    </div>
                    <div class="mt-2 text-xs text-white/65">
                        Reward-assisted conversions: {{ (int) ($rewardConversionSummary['assisted_count'] ?? 0) }}
                    </div>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        @foreach((array) ($rewardConversionSummary['by_platform'] ?? []) as $platform => $count)
                            <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/70">{{ strtoupper((string) $platform) }}: {{ (int) $count }}</span>
                        @endforeach
                    </div>
                </article>

                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">Performance Snapshot</div>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-white/70">
                        <div>Sent: {{ (int) ($performanceSummary['sent'] ?? 0) }}</div>
                        <div>Delivered: {{ (int) ($performanceSummary['delivered'] ?? 0) }}</div>
                        <div>Opened: {{ (int) ($performanceSummary['opened'] ?? 0) }}</div>
                        <div>Clicked: {{ (int) ($performanceSummary['clicked'] ?? 0) }}</div>
                        <div>Converted: {{ (int) ($performanceSummary['converted'] ?? 0) }}</div>
                        <div>Revenue: ${{ number_format((float) ($performanceSummary['revenue'] ?? 0), 2) }}</div>
                    </div>
                    <div class="mt-2 text-xs text-white/55">
                        These metrics are derived from real delivery + conversion outcomes and feed recommendation scoring.
                    </div>
                </article>

                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">Timing Insight</div>
                    <x-admin.help-hint tone="neutral" title="Send-time optimization">
                        Timing suggestions are rule-based and advisory. Applying them still requires explicit admin choice and does not auto-send.
                    </x-admin.help-hint>
                    @if($timingInsight)
                        <div class="mt-2 text-sm text-white/75">
                            Suggested hour: <span class="font-semibold text-white">{{ str_pad((string) (int) ($timingInsight->recommended_hour ?? 13), 2, '0', STR_PAD_LEFT) }}:00</span>
                            · {{ $timingInsight->recommended_daypart ?: 'daypart n/a' }}
                        </div>
                        <div class="mt-1 text-xs text-white/60">Confidence: {{ number_format((float) ($timingInsight->confidence ?? 0), 2) }}</div>
                    @else
                        <div class="mt-2 text-xs text-white/60">No timing insight available yet for this campaign context.</div>
                    @endif
                </article>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">Variant Performance Comparison</h3>
            <x-admin.help-hint tone="neutral" title="Learning model scope">
                Variant comparisons are computed from observed sends, engagement, and conversions. Variants are never auto-promoted or auto-paused.
            </x-admin.help-hint>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
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
                    <tbody class="divide-y divide-white/10">
                        @forelse((array) ($performanceSummary['variant_rows'] ?? []) as $row)
                            @php
                                $variantName = 'No variant';
                                if (!empty($row['variant_id'])) {
                                    $found = $campaign->variants->firstWhere('id', (int) $row['variant_id']);
                                    $variantName = $found?->name ?: ('Variant #' . (int) $row['variant_id']);
                                }
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $variantName }}</td>
                                <td class="px-4 py-3 text-white/75">{{ strtoupper((string) ($row['channel'] ?? $campaign->channel)) }}</td>
                                <td class="px-4 py-3 text-white/70">{{ (int) ($row['sent_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-white/70">{{ (int) ($row['delivered_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-white/70">{{ (int) ($row['opened_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-white/70">{{ (int) ($row['clicked_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-white/70">{{ (int) ($row['converted_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-white/70">{{ number_format(((float) ($row['conversion_rate'] ?? 0)) * 100, 1) }}%</td>
                                <td class="px-4 py-3 text-white/70">${{ number_format((float) ($row['attributed_revenue'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-white/55">No variant performance data available yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">Recipient Approval + Send Queue</h3>
            <x-admin.help-hint tone="neutral" title="Queue controls">
                Approvals are separate from execution by design. Sends can be run in dry-run mode, and failed recipients can be retried individually without deleting prior attempts.
            </x-admin.help-hint>

            <form method="POST" action="{{ $campaign->channel === 'email' ? route('marketing.campaigns.send-selected-email', $campaign) : route('marketing.campaigns.send-selected-sms', $campaign) }}" class="space-y-3">
                @csrf
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-white/60">Select approved recipients below for targeted send execution.</div>
                    <div class="inline-flex items-center gap-2">
                        <label class="inline-flex items-center gap-1 text-xs text-white/70">
                            <input type="checkbox" name="dry_run" value="1" class="rounded border-white/20 bg-white/5" /> Dry run
                        </label>
                        <button type="submit" class="inline-flex rounded-full border border-sky-300/40 bg-sky-500/15 px-3 py-1.5 text-xs font-semibold text-sky-100">Send Selected Approved</button>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-white/65">
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
                        <tbody class="divide-y divide-white/10">
                            @forelse($approvalQueue as $recipient)
                                <tr>
                                    <td class="px-4 py-3">
                                        @if($recipient->status === 'approved')
                                            <input type="checkbox" name="recipient_ids[]" value="{{ $recipient->id }}" class="rounded border-white/20 bg-white/5" />
                                        @else
                                            <span class="text-xs text-white/40">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-white/80">
                                        {{ trim((string) ($recipient->profile?->first_name . ' ' . $recipient->profile?->last_name)) ?: ('Profile #' . $recipient->marketing_profile_id) }}
                                        <div class="text-xs text-white/55">{{ $recipient->profile?->email ?: $recipient->profile?->phone ?: '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-white/75">{{ $recipient->status }}</td>
                                    <td class="px-4 py-3 text-white/75">{{ $recipient->variant?->name ?: '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach((array) $recipient->reason_codes as $reason)
                                                <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/70">{{ $reason }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-white/65">
                                        @if($campaign->channel === 'email')
                                            @if($recipient->latestEmailDelivery)
                                                {{ $recipient->latestEmailDelivery->status }}
                                                <div class="text-xs text-white/50">{{ optional($recipient->latestEmailDelivery->sent_at)->format('Y-m-d H:i') ?: optional($recipient->latestEmailDelivery->created_at)->format('Y-m-d H:i') }}</div>
                                                <div class="text-xs text-white/45">{{ $recipient->latestEmailDelivery->sendgrid_message_id ?: 'No SendGrid ID' }}</div>
                                            @else
                                                —
                                            @endif
                                        @elseif($recipient->latestDelivery)
                                            {{ $recipient->latestDelivery->send_status }}
                                            <div class="text-xs text-white/50">{{ optional($recipient->latestDelivery->sent_at)->format('Y-m-d H:i') ?: optional($recipient->latestDelivery->created_at)->format('Y-m-d H:i') }}</div>
                                            <div class="text-xs text-white/45">{{ $recipient->latestDelivery->provider_message_id ?: 'No SID' }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-white/65">{{ \Illuminate\Support\Str::limit((string) ($recipient->variant?->message_text ?: data_get($recipient->recommendation_snapshot, 'suggested_message', '')), 110) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="inline-flex flex-wrap justify-end gap-2">
                                            @if($recipient->status === 'queued_for_approval')
                                                <form method="POST" action="{{ route('marketing.campaigns.recipients.approve', [$campaign, $recipient]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-white">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('marketing.campaigns.recipients.reject', [$campaign, $recipient]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/15 px-3 py-1 text-xs font-semibold text-amber-100">Reject</button>
                                                </form>
                                            @endif
                                            @if(in_array($recipient->status, ['failed', 'undelivered'], true))
                                                <form method="POST" action="{{ $campaign->channel === 'email' ? route('marketing.campaigns.recipients.retry-email', [$campaign, $recipient]) : route('marketing.campaigns.recipients.retry-sms', [$campaign, $recipient]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex rounded-full border border-rose-300/35 bg-rose-500/15 px-3 py-1 text-xs font-semibold text-rose-100">Retry</button>
                                                </form>
                                            @endif
                                            <a href="{{ route('marketing.customers.show', $recipient->profile) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Profile</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-white/55">No recipients queued yet. Run "Prepare Recipients" first.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </form>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">{{ strtoupper($campaign->channel) }} Delivery Log</h3>
            <x-admin.help-hint tone="neutral" title="Delivery state updates">
                Provider callback events can arrive after initial send. Delivery statuses are updated idempotently and retained for auditability.
            </x-admin.help-hint>

            @if($campaign->channel === 'email')
                <div class="overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-white/65">
                            <tr>
                                <th class="px-4 py-3 text-left">Recipient</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">SendGrid ID</th>
                                <th class="px-4 py-3 text-left">Sent</th>
                                <th class="px-4 py-3 text-left">Delivered</th>
                                <th class="px-4 py-3 text-left">Opened</th>
                                <th class="px-4 py-3 text-left">Clicked</th>
                                <th class="px-4 py-3 text-left">Failed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @forelse($emailDeliveryLog as $delivery)
                                <tr>
                                    <td class="px-4 py-3 text-white/80">
                                        {{ trim((string) ($delivery->profile?->first_name . ' ' . $delivery->profile?->last_name)) ?: ('Profile #' . $delivery->marketing_profile_id) }}
                                        <div class="text-xs text-white/55">{{ $delivery->email ?: ($delivery->profile?->email ?: '—') }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-white/75">{{ $delivery->status }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ $delivery->sendgrid_message_id ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ optional($delivery->sent_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ optional($delivery->delivered_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ optional($delivery->opened_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ optional($delivery->clicked_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ optional($delivery->failed_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-white/55">No email delivery attempts logged for this campaign yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="overflow-x-auto rounded-2xl border border-white/10">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/5 text-white/65">
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
                        <tbody class="divide-y divide-white/10">
                            @forelse($deliveryLog as $delivery)
                                <tr>
                                    <td class="px-4 py-3 text-white/80">
                                        {{ trim((string) ($delivery->profile?->first_name . ' ' . $delivery->profile?->last_name)) ?: ('Profile #' . $delivery->marketing_profile_id) }}
                                        <div class="text-xs text-white/55">{{ $delivery->to_phone ?: ($delivery->profile?->phone ?: '—') }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-white/75">{{ $delivery->send_status }}</td>
                                    <td class="px-4 py-3 text-white/75">#{{ (int) $delivery->attempt_number }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ $delivery->provider_message_id ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ optional($delivery->sent_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/65">{{ optional($delivery->delivered_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-white/65">
                                        @if($delivery->error_code || $delivery->error_message)
                                            <div>{{ $delivery->error_code ?: 'error' }}</div>
                                            <div class="text-xs text-white/50">{{ \Illuminate\Support\Str::limit((string) $delivery->error_message, 80) }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-white/65">{{ \Illuminate\Support\Str::limit((string) $delivery->rendered_message, 95) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-white/55">No delivery attempts logged for this campaign yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts::app>
