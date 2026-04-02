<x-layouts::app :title="'Templates'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Templates"
            description="Reusable SMS/email template library for campaign variants and one-off recommendation drafts."
            hint-title="Template usage"
            hint-text="Templates support variable rendering and channel-specific copy reuse. Sending is still approval-first and execution-disabled."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-zinc-950">Template Library</h2>
                <a href="{{ route('marketing.message-templates.create') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                    Create Template
                </a>
            </div>

            <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Name</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Channel</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Objective</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Tone</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Active</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Updated</th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($templates as $template)
                            <tr class="hover:bg-zinc-50">
                                <td class="px-4 py-3 text-zinc-800">
                                    {{ $template->name }}
                                    <div class="text-xs text-zinc-500">{{ \Illuminate\Support\Str::limit($template->template_text, 90) }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-700">{{ strtoupper($template->channel) }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $template->objective ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $template->tone ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $template->is_active ? 'Yes' : 'No' }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ optional($template->updated_at)->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.message-templates.edit', $template) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-zinc-500">No templates found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-3">{{ $templates->links() }}</div>
        </section>
    </div>
</x-layouts::app>
