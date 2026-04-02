<x-layouts::app :title="$mode === 'create' ? 'Create Segment' : 'Edit Segment'">
    <div class="mx-auto w-full max-w-[1500px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            :title="$mode === 'create' ? 'Create Segment' : 'Edit Segment'"
            description="Build explainable segment rules with field/operator/value conditions."
            hint-title="Rule builder tips"
            hint-text="Use conservative conditions and preview before using a segment in campaigns. Segments are live views over current profile data."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
            <form method="POST" action="{{ $mode === 'create' ? route('marketing.segments.store') : route('marketing.segments.update', $segment) }}" class="space-y-5">
                @csrf
                @if($mode === 'edit')
                    @method('PATCH')
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Name</label>
                        <input type="text" name="name" value="{{ old('name', $segment->name) }}" required class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Status</label>
                        <select name="status" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            @foreach(['draft', 'active', 'paused', 'archived'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $segment->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Channel Scope</label>
                        <select name="channel_scope" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            @foreach(['any', 'sms', 'email'] as $scope)
                                <option value="{{ $scope }}" @selected(old('channel_scope', $segment->channel_scope ?: 'any') === $scope)>{{ strtoupper($scope) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Rule Logic</label>
                        <select name="rule_logic" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            @php
                                $logic = old('rule_logic', data_get($segment->rules_json, 'logic', 'and'));
                            @endphp
                            <option value="and" @selected($logic === 'and')>AND (all conditions)</option>
                            <option value="or" @selected($logic === 'or')>OR (any condition)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Description</label>
                    <textarea name="description" rows="2" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">{{ old('description', $segment->description) }}</textarea>
                </div>

                @php
                    $conditionRows = old('conditions', data_get($segment->rules_json, 'conditions', []));
                    if (!is_array($conditionRows) || count($conditionRows) === 0) {
                        $conditionRows = [['field' => 'total_orders', 'operator' => 'gt', 'value' => 1]];
                    }
                @endphp

                <div>
                    <div class="mb-2 text-xs uppercase tracking-[0.2em] text-zinc-500">Conditions</div>
                    <x-admin.help-hint tone="neutral" title="Supported fields">
                        `total_spent`, `total_orders`, `days_since_last_order`, `source_channel`, `has_email_consent`, `has_sms_consent`, `purchased_at_event`, `purchased_event_name`, `last_event_name`, `profile_source`, `has_square_link`, `has_shopify_link`, `wishlist_active_count`, `wishlist_recent_additions_30d`, `wishlist_product_handle`, `wishlist_product_id`
                    </x-admin.help-hint>

                    <div id="segment-condition-list" class="mt-3 space-y-2">
                        @foreach($conditionRows as $idx => $row)
                            <div class="grid gap-2 md:grid-cols-12 segment-condition-row">
                                <div class="md:col-span-4">
                                    <input type="text" name="conditions[{{ $idx }}][field]" value="{{ $row['field'] ?? '' }}" placeholder="field" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                                </div>
                                <div class="md:col-span-3">
                                    <select name="conditions[{{ $idx }}][operator]" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                                        @foreach(['eq', 'neq', 'contains', 'gt', 'gte', 'lt', 'lte'] as $op)
                                            <option value="{{ $op }}" @selected(($row['operator'] ?? '') === $op)>{{ $op }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="md:col-span-4">
                                    <input type="text" name="conditions[{{ $idx }}][value]" value="{{ is_scalar($row['value'] ?? null) ? (string) $row['value'] : '' }}" placeholder="value" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                                </div>
                                <div class="md:col-span-1 flex items-center">
                                    <button type="button" class="segment-remove-condition rounded-lg border border-zinc-300 bg-zinc-50 px-2 py-1 text-xs text-zinc-700">Remove</button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <button type="button" id="segment-add-condition" class="mt-3 inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs text-zinc-700">
                        Add Condition
                    </button>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                        {{ $mode === 'create' ? 'Create Segment' : 'Save Segment' }}
                    </button>
                    <a href="{{ route('marketing.segments') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-800">Back</a>
                </div>
            </form>
        </section>
    </div>

    <script>
        (() => {
            const list = document.getElementById('segment-condition-list');
            const addBtn = document.getElementById('segment-add-condition');
            if (!list || !addBtn) return;

            const rowHtml = (idx) => `
                <div class="grid gap-2 md:grid-cols-12 segment-condition-row">
                    <div class="md:col-span-4"><input type="text" name="conditions[${idx}][field]" placeholder="field" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" /></div>
                    <div class="md:col-span-3">
                        <select name="conditions[${idx}][operator]" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            <option value="eq">eq</option><option value="neq">neq</option><option value="contains">contains</option><option value="gt">gt</option><option value="gte">gte</option><option value="lt">lt</option><option value="lte">lte</option>
                        </select>
                    </div>
                    <div class="md:col-span-4"><input type="text" name="conditions[${idx}][value]" placeholder="value" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" /></div>
                    <div class="md:col-span-1 flex items-center"><button type="button" class="segment-remove-condition rounded-lg border border-zinc-300 bg-zinc-50 px-2 py-1 text-xs text-zinc-700">Remove</button></div>
                </div>
            `;

            addBtn.addEventListener('click', () => {
                const idx = list.querySelectorAll('.segment-condition-row').length;
                list.insertAdjacentHTML('beforeend', rowHtml(idx));
            });

            list.addEventListener('click', (event) => {
                const target = event.target;
                if (target instanceof HTMLElement && target.classList.contains('segment-remove-condition')) {
                    const row = target.closest('.segment-condition-row');
                    if (row) row.remove();
                }
            });
        })();
    </script>
</x-layouts::app>
