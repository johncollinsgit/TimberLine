<x-layouts::app :title="$mode === 'create' ? 'Create Message Template' : 'Edit Message Template'">
    <div class="mx-auto w-full max-w-[1500px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            :title="$mode === 'create' ? 'Create Message Template' : 'Edit Message Template'"
            description="Manage reusable message copy and preview variable rendering before variant assignment."
            hint-title="Variable rendering"
            hint-text="Variables like first_name and event_name are rendered against a selected marketing profile for transparent previewing."
        />

        <section class="grid gap-4 xl:grid-cols-3">
            <article class="xl:col-span-2 rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-5">
                <form method="POST" action="{{ $mode === 'create' ? route('marketing.message-templates.store') : route('marketing.message-templates.update', $template) }}" class="space-y-5">
                    @csrf
                    @if($mode === 'edit')
                        @method('PATCH')
                    @endif

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-white/55">Template Name</label>
                            <input type="text" name="name" required value="{{ old('name', $template->name) }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-white/55">Channel</label>
                            <select name="channel" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                                @foreach(['sms', 'email'] as $channel)
                                    <option value="{{ $channel }}" @selected(old('channel', $template->channel) === $channel)>{{ strtoupper($channel) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-white/55">Objective</label>
                            <select name="objective" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                                <option value="">None</option>
                                @foreach(['winback', 'repeat_purchase', 'event_followup', 'consent_capture', 'review_request'] as $objective)
                                    <option value="{{ $objective }}" @selected(old('objective', $template->objective) === $objective)>{{ $objective }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-white/55">Tone</label>
                            <input type="text" name="tone" value="{{ old('tone', $template->tone) }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                        </div>
                        <label class="flex items-end gap-2 text-sm text-white/80">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active)) class="rounded border-white/20 bg-white/5" />
                            Active
                        </label>
                    </div>

                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Template Text</label>
                        <textarea name="template_text" rows="5" required class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">{{ old('template_text', $template->template_text) }}</textarea>
                    </div>

                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Variables (comma-separated)</label>
                        <input type="text" name="variables_raw" value="{{ old('variables_raw', is_array($template->variables_json) ? implode(', ', $template->variables_json) : '') }}" placeholder="first_name, event_name, coupon_code" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                            {{ $mode === 'create' ? 'Create Template' : 'Save Template' }}
                        </button>
                        @if($mode === 'edit')
                            <a href="{{ route('marketing.message-templates.preview', $template) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">Run Preview</a>
                        @endif
                        <a href="{{ route('marketing.message-templates') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">Back</a>
                    </div>
                </form>
            </article>

            <article class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-4">
                <x-admin.help-hint tone="neutral" title="Template variables">
                    Supported defaults include `first_name`, `event_name`, `coupon_code`, `days_since_last_order`, `total_orders`, and `total_spent`.
                </x-admin.help-hint>
                <x-admin.help-hint tone="neutral" title="Consent-capture copy guardrail">
                    Consent-capture templates should describe opt-in clearly and avoid promising rewards/features that are not yet live.
                </x-admin.help-hint>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Preview Profile</div>
                    <div class="mt-2 text-sm text-white">{{ isset($previewProfile) && $previewProfile ? trim((string) ($previewProfile->first_name . ' ' . $previewProfile->last_name)) ?: ('Profile #' . $previewProfile->id) : 'No profile selected' }}</div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Rendered Preview</div>
                    <pre class="mt-2 whitespace-pre-wrap text-sm text-white/85">{{ $previewText ?: 'Save or open preview to render variable output.' }}</pre>
                </div>

                @if($mode === 'edit')
                    <form method="GET" action="{{ route('marketing.message-templates.preview', $template) }}" class="space-y-2">
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Preview Profile ID (optional)</label>
                        <input type="number" name="profile_id" min="1" class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                        <button type="submit" class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/85">Render With Profile</button>
                    </form>
                @endif
            </article>
        </section>
    </div>
</x-layouts::app>
