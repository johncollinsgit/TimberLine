<x-layouts::app :title="'Campaign Detail'">
    <div class="mx-auto w-full max-w-[1850px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Campaign Detail"
            description="Campaign orchestration, variant management, recipient preparation, recommendation review, and approval queue."
            hint-title="Why preparation is separate"
            hint-text="Recipient preparation materializes eligibility snapshots before send execution exists. This keeps consent gating and approval decisions explicit."
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

            <div class="grid gap-4 md:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Recipients</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ array_sum($recipientSummary) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Queued For Approval</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ (int) ($recipientSummary['queued_for_approval'] ?? 0) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Approved</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ (int) ($recipientSummary['approved'] ?? 0) }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Skipped</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ (int) ($recipientSummary['skipped'] ?? 0) }}</div>
                </article>
            </div>

            <x-admin.help-hint tone="neutral" title="Approval workflow">
                Approve/reject decisions are stored in `marketing_send_approvals` for auditability. No provider sends are executed in this stage.
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
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-white">Campaign Variants</h3>
                </div>

                <x-admin.help-hint tone="neutral" title="Variant testing">
                    Maintain at least two active variants when practical. Use control + weighted variants for staged testing.
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
                    <div class="text-sm font-semibold text-white">Attribution Summary</div>
                    <div class="mt-2 text-xs text-white/65">
                        Conversion attribution scaffolding is enabled for Stage 4. Send execution and outcome wiring will be added in Stage 5.
                    </div>
                </article>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-white">Recipient Approval Queue</h3>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Profile</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Variant</th>
                            <th class="px-4 py-3 text-left">Reason Codes</th>
                            <th class="px-4 py-3 text-left">Message Preview</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($approvalQueue as $recipient)
                            <tr>
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
                                        @else
                                            <span class="text-xs text-white/50">Reviewed</span>
                                        @endif
                                        <a href="{{ route('marketing.customers.show', $recipient->profile) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Profile</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-white/55">No recipients queued yet. Run "Prepare Recipients" first.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts::app>
