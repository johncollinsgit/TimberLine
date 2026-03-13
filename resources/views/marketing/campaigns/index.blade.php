<x-layouts::app :title="'Marketing Campaigns'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Campaigns"
            description="Campaign orchestration for segment targeting, variants, recipient preparation, and approval tracking."
            hint-title="Campaign lifecycle"
            hint-text="Campaigns are prepared and approved here, then executed through Twilio SMS or SendGrid email with delivery tracking."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-white">Campaign Workspace</h2>
                <a href="{{ route('marketing.campaigns.create') }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                    Create Campaign
                </a>
            </div>

            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Name</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Channel</th>
                            <th class="px-4 py-3 text-left">Objective</th>
                            <th class="px-4 py-3 text-left">Segment</th>
                            <th class="px-4 py-3 text-left">Recipients</th>
                            <th class="px-4 py-3 text-left">Launched</th>
                            <th class="px-4 py-3 text-left">Updated</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($campaigns as $campaign)
                            <tr class="hover:bg-white/5">
                                <td class="px-4 py-3 text-white/85">
                                    {{ $campaign->name }}
                                    <div class="text-xs text-white/55">{{ $campaign->description ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/75">{{ $campaign->status }}</td>
                                <td class="px-4 py-3 text-white/75">{{ strtoupper($campaign->channel) }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $campaign->objective ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $campaign->segment?->name ?: 'Unlinked' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ number_format((int) $campaign->recipients_count) }}</td>
                                <td class="px-4 py-3 text-white/65">{{ optional($campaign->launched_at)->format('Y-m-d') ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/65">{{ optional($campaign->updated_at)->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('marketing.campaigns.show', $campaign) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Open</a>
                                        <a href="{{ route('marketing.campaigns.edit', $campaign) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-white/55">No campaigns created yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-3">{{ $campaigns->links() }}</div>
        </section>
    </div>
</x-layouts::app>
