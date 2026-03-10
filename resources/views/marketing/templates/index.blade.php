<x-layouts::app :title="'Marketing Message Templates'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Message Templates"
            description="Reusable SMS/email template library for campaign variants and one-off recommendation drafts."
            hint-title="Template usage"
            hint-text="Templates support variable rendering and channel-specific copy reuse. Sending is still approval-first and execution-disabled."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-white">Template Library</h2>
                <a href="{{ route('marketing.message-templates.create') }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                    Create Template
                </a>
            </div>

            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Name</th>
                            <th class="px-4 py-3 text-left">Channel</th>
                            <th class="px-4 py-3 text-left">Objective</th>
                            <th class="px-4 py-3 text-left">Tone</th>
                            <th class="px-4 py-3 text-left">Active</th>
                            <th class="px-4 py-3 text-left">Updated</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($templates as $template)
                            <tr class="hover:bg-white/5">
                                <td class="px-4 py-3 text-white/85">
                                    {{ $template->name }}
                                    <div class="text-xs text-white/55">{{ \Illuminate\Support\Str::limit($template->template_text, 90) }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/75">{{ strtoupper($template->channel) }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $template->objective ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $template->tone ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/75">{{ $template->is_active ? 'Yes' : 'No' }}</td>
                                <td class="px-4 py-3 text-white/65">{{ optional($template->updated_at)->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.message-templates.edit', $template) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/80">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-white/55">No templates found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-3">{{ $templates->links() }}</div>
        </section>
    </div>
</x-layouts::app>
