<x-layouts::app :title="$mode === 'create' ? 'Create Campaign' : 'Edit Campaign'">
    <div class="mx-auto w-full max-w-[1500px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            :title="$mode === 'create' ? 'Create Campaign' : 'Edit Campaign'"
            description="Configure campaign targeting, objective, attribution window, and send-window guardrails."
            hint-title="Campaign setup notes"
            hint-text="Campaign status controls orchestration readiness. Recipient preparation runs separately so consent and eligibility checks are explicit."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6 space-y-5">
            <x-admin.help-hint tone="neutral" title="Attribution and send windows">
                Attribution window controls later conversion crediting. Send window and quiet hour values are planning inputs only in Stage 4.
            </x-admin.help-hint>

            <form method="POST" action="{{ $mode === 'create' ? route('marketing.campaigns.store') : route('marketing.campaigns.update', $campaign) }}" class="space-y-5">
                @csrf
                @if($mode === 'edit')
                    @method('PATCH')
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Campaign Name</label>
                        <input type="text" name="name" required value="{{ old('name', $campaign->name) }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Status</label>
                        <select name="status" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            @foreach(['draft', 'ready_for_review', 'active', 'paused', 'completed', 'archived'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $campaign->status) === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Description</label>
                    <textarea name="description" rows="2" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">{{ old('description', $campaign->description) }}</textarea>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Channel</label>
                        <select name="channel" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            @foreach(['sms', 'email'] as $channel)
                                <option value="{{ $channel }}" @selected(old('channel', $campaign->channel) === $channel)>{{ strtoupper($channel) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Objective</label>
                        <select name="objective" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="">None</option>
                            @foreach(['winback', 'repeat_purchase', 'event_followup', 'consent_capture', 'review_request'] as $objective)
                                <option value="{{ $objective }}" @selected(old('objective', $campaign->objective) === $objective)>{{ $objective }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Segment</label>
                        <select name="segment_id" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="">Unlinked</option>
                            @foreach($segments as $segment)
                                <option value="{{ $segment->id }}" @selected((int) old('segment_id', $campaign->segment_id) === (int) $segment->id)>{{ $segment->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Groups (Optional)</label>
                    <select name="group_ids[]" multiple size="6" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        @php
                            $oldGroupIds = collect((array) old('group_ids', $selectedGroupIds ?? []))->map(fn($id) => (int) $id)->all();
                        @endphp
                        @foreach($groups as $group)
                            <option value="{{ $group->id }}" @selected(in_array((int) $group->id, $oldGroupIds, true))>
                                {{ $group->name }}{{ $group->is_internal ? ' (internal)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-white/55">Campaign audience can union segment matches, selected group members, and manual profile additions.</p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Attribution Window (days)</label>
                        <input type="number" name="attribution_window_days" min="1" max="60" value="{{ old('attribution_window_days', $campaign->attribution_window_days ?: 7) }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Coupon Code</label>
                        <input type="text" name="coupon_code" value="{{ old('coupon_code', $campaign->coupon_code) }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Send Window</div>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <input type="time" name="send_window_start" value="{{ old('send_window_start', data_get($campaign->send_window_json, 'start')) }}" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                            <input type="time" name="send_window_end" value="{{ old('send_window_end', data_get($campaign->send_window_json, 'end')) }}" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                        </div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Quiet Hours Override</div>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <input type="time" name="quiet_hours_start" value="{{ old('quiet_hours_start', data_get($campaign->quiet_hours_override_json, 'start')) }}" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                            <input type="time" name="quiet_hours_end" value="{{ old('quiet_hours_end', data_get($campaign->quiet_hours_override_json, 'end')) }}" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                        {{ $mode === 'create' ? 'Create Campaign' : 'Save Campaign' }}
                    </button>
                    @if($mode === 'edit')
                        <a href="{{ route('marketing.campaigns.show', $campaign) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">Back to Campaign</a>
                    @else
                        <a href="{{ route('marketing.campaigns') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/85">Back to Campaigns</a>
                    @endif
                </div>
            </form>
        </section>
    </div>
</x-layouts::app>
