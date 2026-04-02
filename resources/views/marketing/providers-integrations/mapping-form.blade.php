<x-layouts::app :title="$formMode === 'create' ? 'Create Event Source Mapping' : 'Edit Event Source Mapping'">
    <div class="mx-auto w-full max-w-[1600px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            :title="$formMode === 'create' ? 'Create Event Source Mapping' : 'Edit Event Source Mapping'"
            description="Map raw Square source values to event instances for reliable attribution."
            hint-title="Attribution mapping guidance"
            hint-text="Use high-confidence mappings only. Leave uncertain values unmapped so they remain visible in the queue."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
            <form method="POST" action="{{ $formMode === 'create' ? route('marketing.providers-integrations.mappings.store') : route('marketing.providers-integrations.mappings.update', $mapping) }}" class="grid gap-4">
                @csrf
                @if($formMode === 'edit')
                    @method('PATCH')
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Source System</label>
                        <input type="text" name="source_system" value="{{ old('source_system', $mapping->source_system) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" required />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Raw Value</label>
                        <input type="text" name="raw_value" value="{{ old('raw_value', $mapping->raw_value) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" required />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Normalized Value</label>
                        <input type="text" name="normalized_value" value="{{ old('normalized_value', $mapping->normalized_value) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Event Instance</label>
                        <select name="event_instance_id" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            <option value="">Unmapped</option>
                            @foreach($eventInstances as $option)
                                <option value="{{ $option['id'] }}" @selected((int) old('event_instance_id', (int) $mapping->event_instance_id) === $option['id'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Confidence (0-1)</label>
                        <input type="number" name="confidence" step="0.01" min="0" max="1" value="{{ old('confidence', $mapping->confidence) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                    </div>
                </div>

                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Notes</label>
                    <textarea name="notes" rows="3" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">{{ old('notes', $mapping->notes) }}</textarea>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-zinc-700">
                    <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $mapping->is_active)) class="rounded border-zinc-300 bg-zinc-50" />
                    Mapping is active
                </label>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                        {{ $formMode === 'create' ? 'Create Mapping' : 'Update Mapping' }}
                    </button>
                    <a href="{{ route('marketing.providers-integrations') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-700">
                        Back to Integrations
                    </a>
                </div>
            </form>
        </section>
    </div>
</x-layouts::app>
