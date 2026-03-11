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
                <form method="POST" action="{{ route('marketing.campaigns.send-approved-sms', $campaign) }}" class="inline-flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="limit" value="500" />
                    <button type="submit" class="inline-flex rounded-full border border-sky-300/40 bg-sky-500/15 px-4 py-2 text-sm font-semibold text-sky-100">Send Approved Recipients</button>
                    <label class="inline-flex items-center gap-1 text-xs text-white/70">
                        <input type="checkbox" name="dry_run" value="1" class="rounded border-white/20 bg-white/5" /> Dry run
                    </label>
                </form>
            </div>
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
                </article>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">Recipient Approval + Send Queue</h3>
            <x-admin.help-hint tone="neutral" title="Queue controls">
                Approvals are separate from execution by design. Sends can be run in dry-run mode, and failed recipients can be retried individually without deleting prior attempts.
            </x-admin.help-hint>

            <form method="POST" action="{{ route('marketing.campaigns.send-selected-sms', $campaign) }}" class="space-y-3">
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
                                <th class="px-4 py-3 text-left whitespace-nowrap">Select</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Profile</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Status</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Variant</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Reason Codes</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Last Delivery</th>
                                <th class="px-4 py-3 text-left whitespace-nowrap">Message Preview</th>
                                <th class="px-4 py-3 text-right whitespace-nowrap">Actions</th>
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
                                        @if($recipient->latestDelivery)
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
                                                <form method="POST" action="{{ route('marketing.campaigns.recipients.retry-sms', [$campaign, $recipient]) }}">
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
            <h3 class="text-sm font-semibold text-white">SMS Delivery Log</h3>
            <x-admin.help-hint tone="neutral" title="Delivery state updates">
                Twilio callback events can arrive after initial send. Delivery statuses are updated idempotently and appended to delivery event history.
            </x-admin.help-hint>

            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Recipient</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Status</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Attempt</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Provider SID</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Sent</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Delivered</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Failure</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Message</th>
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
        </section>
    </div>
</x-layouts::app>
