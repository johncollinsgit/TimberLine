<x-layouts::app :title="'Suggestions'">
    <div class="mx-auto w-full max-w-[1850px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Suggestions"
            description="Rule-based recommendation center and approval queue for campaign recipient and optimization suggestions."
            hint-title="Recommendation governance"
            hint-text="Suggestions are never autonomous sends. Every approval/rejection is recorded for auditability."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-zinc-950">Suggestions</h2>
                <form method="POST" action="{{ route('marketing.recommendations.generate-global') }}">
                    @csrf
                    <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                        Generate Suggestions
                    </button>
                </form>
            </div>

            <x-admin.help-hint tone="neutral" title="Suggestion types">
                Suggestions are generated from real performance outcomes plus profile/event/reward rules. They stay explainable and never send automatically.
            </x-admin.help-hint>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach((array) $typeSummary as $typeKey => $count)
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                        <div class="text-[11px] uppercase tracking-[0.18em] text-zinc-500">{{ $typeKey }}</div>
                        <div class="mt-1 text-lg font-semibold text-zinc-950">{{ (int) $count }}</div>
                    </article>
                @endforeach
            </div>

            <form method="GET" action="{{ route('marketing.recommendations') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-3">
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Status</label>
                    <select name="status" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                        @foreach(['pending', 'approved', 'rejected', 'dismissed', 'all'] as $statusOption)
                            <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Type</label>
                    <select name="type" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                        @foreach(['all', 'send_suggestion', 'copy_improvement', 'segment_opportunity', 'timing_suggestion', 'variant_recommendation', 'channel_suggestion', 'reward_opportunity'] as $typeOption)
                            <option value="{{ $typeOption }}" @selected($type === $typeOption)>{{ $typeOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm font-semibold text-zinc-800">Apply</button>
                </div>
            </form>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-zinc-950">Recent Suggestion Runs</h3>
            <x-admin.help-hint tone="neutral" title="Learning + governance">
                Learning uses observed delivery and conversion outcomes. Apply actions remain manual and auditable through approvals.
            </x-admin.help-hint>
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Run Type</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Started</th>
                            <th class="px-4 py-3 text-left">Finished</th>
                            <th class="px-4 py-3 text-left">Summary</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($latestRuns as $run)
                            <tr>
                                <td class="px-4 py-3 text-zinc-700">{{ $run->type }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $run->status }}</td>
                                <td class="px-4 py-3 text-zinc-500">{{ optional($run->started_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-500">{{ optional($run->finished_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-500">{{ \Illuminate\Support\Str::limit(json_encode($run->summary), 160) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">No recommendation runs logged yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-zinc-950">Campaign Recipient Approval Queue</h3>
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Campaign</th>
                            <th class="px-4 py-3 text-left">Profile</th>
                            <th class="px-4 py-3 text-left">Channel</th>
                            <th class="px-4 py-3 text-left">Variant</th>
                            <th class="px-4 py-3 text-left">Reason Codes</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($approvalRecipients as $recipient)
                            <tr>
                                <td class="px-4 py-3 text-zinc-700">{{ $recipient->campaign?->name ?: ('Campaign #' . $recipient->campaign_id) }}</td>
                                <td class="px-4 py-3 text-zinc-700">
                                    {{ trim((string) ($recipient->profile?->first_name . ' ' . $recipient->profile?->last_name)) ?: ('Profile #' . $recipient->marketing_profile_id) }}
                                    <div class="text-xs text-zinc-500">{{ $recipient->profile?->email ?: $recipient->profile?->phone ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ strtoupper($recipient->channel) }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $recipient->variant?->name ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach((array) $recipient->reason_codes as $reason)
                                            <span class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-2 py-0.5 text-[11px] text-zinc-600">{{ $reason }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.campaigns.show', $recipient->campaign) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-800">Open Campaign</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No recipients queued for approval.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h3 class="text-sm font-semibold text-zinc-950">Suggestions</h3>
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Title</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Campaign</th>
                            <th class="px-4 py-3 text-left">Profile</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Confidence</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($recommendations as $recommendation)
                            <tr>
                                <td class="px-4 py-3 text-zinc-800">
                                    {{ $recommendation->title }}
                                    <div class="text-xs text-zinc-500">{{ $recommendation->summary }}</div>
                                    @if(is_array($recommendation->details_json) && $recommendation->details_json !== [])
                                        <div class="mt-1 text-[11px] text-zinc-500">{{ \Illuminate\Support\Str::limit(json_encode($recommendation->details_json), 170) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ $recommendation->type }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $recommendation->campaign?->name ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-700">
                                    @if($recommendation->profile)
                                        <a href="{{ route('marketing.customers.show', $recommendation->profile) }}" wire:navigate class="text-zinc-800 hover:text-zinc-950">{{ trim((string) ($recommendation->profile->first_name . ' ' . $recommendation->profile->last_name)) ?: ('Profile #' . $recommendation->profile->id) }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ $recommendation->status }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $recommendation->confidence !== null ? number_format((float) $recommendation->confidence, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex flex-wrap justify-end gap-2">
                                        @if($recommendation->status === 'pending')
                                            <form method="POST" action="{{ route('marketing.recommendations.approve', $recommendation) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-3 py-1 text-xs font-semibold text-zinc-950">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('marketing.recommendations.reject', $recommendation) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex rounded-full border border-amber-300/35 bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">Reject</button>
                                            </form>
                                            <form method="POST" action="{{ route('marketing.recommendations.dismiss', $recommendation) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">Dismiss</button>
                                            </form>
                                        @else
                                            <span class="text-xs text-zinc-500">Reviewed</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-zinc-500">No recommendations found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-3">{{ $recommendations->links() }}</div>
        </section>
    </div>
</x-layouts::app>
