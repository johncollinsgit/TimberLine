<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">New Scent Wizard</div>
        <h1 class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Governed Scent Authoring Flow</h1>
        <p class="mt-2 max-w-3xl text-sm text-emerald-50/75">
          Match to existing first. Only create new canonical scents when no governed match exists.
        </p>
      </div>
      <a
        href="{{ $returnTo }}"
        wire:navigate
        class="inline-flex h-10 items-center justify-center rounded-full border border-white/10 bg-white/5 px-4 text-xs font-semibold text-white/85 hover:bg-white/10"
      >
        Cancel
      </a>
    </div>

    <div class="mt-5 grid gap-2 sm:grid-cols-5">
      @foreach([1 => 'Identify', 2 => 'Identity', 3 => 'Aliases', 4 => 'Review', 5 => 'Complete'] as $i => $label)
        <button
          type="button"
          wire:click="jumpToStep({{ $i }})"
          class="rounded-xl border px-3 py-2 text-left transition {{ $step === $i ? 'border-emerald-300/40 bg-emerald-500/20 text-white' : ($step > $i ? 'border-emerald-300/25 bg-emerald-500/10 text-emerald-50/85' : 'border-white/10 bg-black/20 text-white/70') }}"
        >
          <div class="text-[10px] uppercase tracking-[0.22em]">Step {{ $i }}</div>
          <div class="mt-1 text-sm font-medium">{{ $label }}</div>
        </button>
      @endforeach
    </div>
  </section>

  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
    <div class="grid gap-3 md:grid-cols-7">
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Incoming Name</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['raw_name'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Variant</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['raw_variant'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Account</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['account_name'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Store</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['store_key'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Source</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['source_context'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Channel Hint</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['channel_hint'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-white/10 bg-black/20 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">Product Form</div>
        <div class="mt-1 text-sm text-white/90">{{ $context['product_form_hint'] ?: '—' }}</div>
      </div>
    </div>
  </section>

  @if($step === 1)
    <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
      <div class="text-sm font-semibold text-white">Step 1: Identify what this is</div>
      <p class="mt-1 text-sm text-emerald-50/75">Choose intent, search likely existing matches, and prefer mapping before creating duplicates.</p>

      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <label class="rounded-xl border border-white/10 bg-black/20 p-3">
          <div class="flex items-start gap-2">
            <input type="radio" wire:model.live="intent" value="map_existing" class="mt-1 rounded border-white/20 bg-white/10" />
            <div>
              <div class="text-sm font-medium text-white">Map to existing scent</div>
              <div class="text-xs text-white/70">Best for unresolved names that already have canonical matches.</div>
            </div>
          </div>
        </label>
        <label class="rounded-xl border border-white/10 bg-black/20 p-3">
          <div class="flex items-start gap-2">
            <input type="radio" wire:model.live="intent" value="new_scent" class="mt-1 rounded border-white/20 bg-white/10" />
            <div>
              <div class="text-sm font-medium text-white">Create new scent</div>
              <div class="text-xs text-white/70">Use when no suitable existing scent or alias is found.</div>
            </div>
          </div>
        </label>
        <label class="rounded-xl border border-white/10 bg-black/20 p-3">
          <div class="flex items-start gap-2">
            <input type="radio" wire:model.live="intent" value="customer_alias" class="mt-1 rounded border-white/20 bg-white/10" />
            <div>
              <div class="text-sm font-medium text-white">Customer alias for existing scent</div>
              <div class="text-xs text-white/70">Map account-specific naming to canonical scent without creating duplicates.</div>
            </div>
          </div>
        </label>
        <label class="rounded-xl border border-white/10 bg-black/20 p-3">
          <div class="flex items-start gap-2">
            <input type="radio" wire:model.live="intent" value="blend_template_placeholder" class="mt-1 rounded border-white/20 bg-white/10" />
            <div>
              <div class="text-sm font-medium text-white">Blend-template path (placeholder)</div>
              <div class="text-xs text-white/70">Future governed blend-template workflow. Not fully implemented in this block.</div>
            </div>
          </div>
        </label>
      </div>
      @error('intent') <div class="mt-2 text-xs text-red-300">{{ $message }}</div> @enderror

      <div class="mt-5">
        <label class="text-xs text-emerald-100/70">Search existing scents / aliases</label>
        <input
          type="text"
          wire:model.live.debounce.250ms="search"
          placeholder="Search by name, alias, abbreviation, oil ref..."
          class="mt-1 h-11 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-white/90"
        />
      </div>

      <div class="mt-3 rounded-xl border border-white/10 bg-black/20">
        @if($matches->isNotEmpty())
          <div class="divide-y divide-white/5">
            @foreach($matches as $candidate)
              <button
                type="button"
                wire:click="selectExistingScent({{ (int) $candidate['id'] }})"
                class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left transition {{ (int) $selectedExistingScentId === (int) $candidate['id'] ? 'bg-emerald-500/20' : 'hover:bg-white/10' }}"
              >
                <div class="min-w-0">
                  <div class="truncate text-sm font-medium text-white">{{ $candidate['name'] }}</div>
                  <div class="truncate text-[11px] text-emerald-100/70">{{ $candidate['why'] ?? 'Matched existing records' }}</div>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-[11px]">
                  <span class="text-emerald-100/75">{{ $candidate['mapping_type'] }}</span>
                  <span class="text-white/70">{{ $candidate['score'] }}%</span>
                </div>
              </button>
            @endforeach
          </div>
        @else
          <div class="px-3 py-2 text-sm text-white/70">No matches yet. Keep typing or continue with new scent creation.</div>
        @endif
      </div>

      <div class="mt-3 rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white/80">
        <span class="text-emerald-100/70">Selected:</span>
        @if($selectedScent)
          <span class="font-medium text-white">{{ $selectedScent->display_name ?: $selectedScent->name }}</span>
          <span class="text-white/60">#{{ $selectedScent->id }}</span>
        @else
          <span>None</span>
        @endif
      </div>
      @error('selectedExistingScentId') <div class="mt-2 text-xs text-red-300">{{ $message }}</div> @enderror

      <div class="mt-5 flex items-center justify-end gap-2">
        <button
          type="button"
          wire:click="nextStep"
          class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-white"
        >
          Continue
        </button>
      </div>
    </section>
  @endif

  @if($step === 2)
    <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
      <div class="text-sm font-semibold text-white">Step 2: Scent identity</div>
      <p class="mt-1 text-sm text-emerald-50/75">Define canonical scent metadata, lifecycle defaults, and channel/form availability.</p>

      <div class="mt-4 grid gap-3 md:grid-cols-6">
        <div class="md:col-span-2">
          <flux:input wire:model.defer="form.name" label="Name" />
          @error('form.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="md:col-span-2">
          <flux:input wire:model.defer="form.display_name" label="Display Name" />
          @error('form.display_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div>
          <flux:input wire:model.defer="form.abbreviation" label="Abbrev" />
          @error('form.abbreviation') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="md:col-span-1 rounded-xl border border-white/10 bg-black/20 p-3">
          <div class="text-xs text-emerald-100/70">Recipe type</div>
          <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <label class="flex items-start gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/85">
              <input type="radio" wire:model.live="form.recipe_type" value="single_oil" class="mt-1 rounded border-white/20 bg-white/10" />
              <span>Single oil</span>
            </label>
            <label class="flex items-start gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/85">
              <input type="radio" wire:model.live="form.recipe_type" value="blend_backed" class="mt-1 rounded border-white/20 bg-white/10" />
              <span>Blend-backed</span>
            </label>
          </div>
          <div class="mt-2 text-[11px] text-emerald-100/65">Recipe components must use existing oils or blend templates.</div>
          @error('form.recipe_type') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="md:col-span-3">
          <label class="text-xs text-emerald-100/70">Notes</label>
          <textarea wire:model.defer="form.notes" rows="3" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/90"></textarea>
          @error('form.notes') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="md:col-span-3">
          <label class="text-xs text-emerald-100/70">Lifecycle default</label>
          <select wire:model.defer="form.lifecycle_status" class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-white/90">
            @foreach($lifecycleStatuses as $status)
              <option value="{{ $status }}">{{ ucfirst($status) }}</option>
            @endforeach
          </select>
          @error('form.lifecycle_status') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        @if(($form['recipe_type'] ?? 'single_oil') === 'single_oil')
          <div class="md:col-span-3 rounded-xl border border-white/10 bg-black/20 p-3">
            <label class="text-xs text-emerald-100/70">Primary oil</label>
            <div class="mt-2">
              <livewire:components.base-oil-combobox
                wire:model.live="form.base_oil_id"
                placeholder="Search and select existing oil..."
                :limit="25"
                :include-inactive="false"
                wire:key="wizard-single-oil-selector"
              />
            </div>
            <div class="mt-2 text-[11px] text-white/65">
              No oil match?
              <a href="{{ route('admin.index', ['tab' => 'master-data', 'resource' => 'base-oils']) }}" wire:navigate class="text-emerald-200 hover:text-emerald-100 underline decoration-dotted">Create missing oil in Master Data</a>.
            </div>
            @error('form.base_oil_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            @error('form.oil_reference_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
          </div>
        @else
          <div class="md:col-span-6 rounded-xl border border-white/10 bg-black/20 p-3">
            <div class="flex items-center justify-between gap-2">
              <div>
                <div class="text-xs text-emerald-100/70">Blend-backed recipe components</div>
                <div class="text-[11px] text-white/65">Add oils and blend templates using governed selectors only.</div>
              </div>
              <button type="button" wire:click="addRecipeComponent" class="rounded-full border border-emerald-300/35 bg-emerald-500/15 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-100 hover:bg-emerald-500/25">Add Component</button>
            </div>

            <div class="mt-3 space-y-3">
              @foreach($form['recipe_components'] ?? [] as $index => $row)
                <div class="grid gap-2 rounded-xl border border-white/10 bg-white/5 p-3 md:grid-cols-12">
                  <div class="md:col-span-3">
                    <label class="text-[11px] text-emerald-100/70">Component type</label>
                    <select wire:model.live="form.recipe_components.{{ $index }}.component_type" class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-white/90">
                      <option value="oil">Oil</option>
                      <option value="blend_template">Blend template</option>
                    </select>
                  </div>

                  <div class="md:col-span-5">
                    @if(($row['component_type'] ?? 'oil') === 'blend_template')
                      <label class="text-[11px] text-emerald-100/70">Blend template</label>
                      <div class="mt-1">
                        <livewire:components.blend-template-combobox
                          wire:model.live="form.recipe_components.{{ $index }}.blend_template_id"
                          placeholder="Search blend templates..."
                          :limit="25"
                          :include-inactive="false"
                          wire:key="wizard-blend-template-selector-{{ $index }}"
                        />
                      </div>
                      @error("form.recipe_components.$index.blend_template_id") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                    @else
                      <label class="text-[11px] text-emerald-100/70">Oil</label>
                      <div class="mt-1">
                        <livewire:components.base-oil-combobox
                          wire:model.live="form.recipe_components.{{ $index }}.base_oil_id"
                          placeholder="Search oils..."
                          :limit="25"
                          :include-inactive="false"
                          wire:key="wizard-recipe-oil-selector-{{ $index }}"
                        />
                      </div>
                      @error("form.recipe_components.$index.base_oil_id") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                    @endif
                  </div>

                  <div class="md:col-span-2">
                    <label class="text-[11px] text-emerald-100/70">Parts</label>
                    <input type="number" min="0.01" step="0.01" wire:model.defer="form.recipe_components.{{ $index }}.parts" class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-white/90" />
                    @error("form.recipe_components.$index.parts") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                  </div>

                  <div class="md:col-span-2">
                    <label class="text-[11px] text-emerald-100/70">% (optional)</label>
                    <input type="number" min="0.01" max="100" step="0.01" wire:model.defer="form.recipe_components.{{ $index }}.percentage" class="mt-1 h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-white/90" />
                    <div class="mt-2 flex items-center justify-between">
                      @error("form.recipe_components.$index.percentage") <div class="text-xs text-red-300">{{ $message }}</div> @enderror
                      <button type="button" wire:click="removeRecipeComponent({{ $index }})" class="ml-auto rounded-full border border-red-300/30 bg-red-500/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-red-100 hover:bg-red-500/20">Remove</button>
                    </div>
                  </div>
                </div>
              @endforeach
            </div>

            @error('form.recipe_components') <div class="mt-2 text-xs text-red-300">{{ $message }}</div> @enderror

            <div class="mt-2 text-[11px] text-white/65">
              Missing a source record?
              <a href="{{ route('admin.index', ['tab' => 'master-data', 'resource' => 'base-oils']) }}" wire:navigate class="text-emerald-200 hover:text-emerald-100 underline decoration-dotted">Create oils in Master Data</a>
              or
              <a href="{{ route('admin.index', ['tab' => 'blends']) }}" wire:navigate class="text-emerald-200 hover:text-emerald-100 underline decoration-dotted">maintain blend templates</a>.
            </div>
          </div>
        @endif

        <div class="md:col-span-6 rounded-xl border border-white/10 bg-black/20 p-3">
          <div class="text-xs uppercase tracking-[0.22em] text-emerald-100/60">Availability</div>
          <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
            @foreach(['retail' => 'Retail', 'wholesale' => 'Wholesale', 'candle_club' => 'Candle Club', 'room_spray' => 'Room Spray', 'wax_melt' => 'Wax Melt'] as $key => $label)
              <label class="flex items-center gap-2 text-sm text-white/85">
                <input type="checkbox" wire:model.defer="form.availability.{{ $key }}" class="rounded border-white/20 bg-white/10" />
                <span>{{ $label }}</span>
              </label>
            @endforeach
          </div>
        </div>

        <div class="flex items-center gap-2">
          <input type="checkbox" wire:model.defer="form.is_wholesale_custom" class="rounded border-white/20 bg-white/10" />
          <span class="text-sm text-white/80">Wholesale custom scent</span>
        </div>
        <div class="flex items-center gap-2">
          <input type="checkbox" wire:model.defer="form.is_candle_club" class="rounded border-white/20 bg-white/10" />
          <span class="text-sm text-white/80">Candle Club scent</span>
        </div>
      </div>

      <div class="mt-5 flex items-center justify-between gap-2">
        <button type="button" wire:click="previousStep" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/80 hover:bg-white/10">Back</button>
        <button type="button" wire:click="nextStep" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-white">Continue</button>
      </div>
    </section>
  @endif

  @if($step === 3)
    <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
      <div class="text-sm font-semibold text-white">Step 3: Alias / mapping setup</div>
      <p class="mt-1 text-sm text-emerald-50/75">Add global and/or customer-scoped aliases. Save incoming unresolved names as aliases when appropriate.</p>

      <div class="mt-4 space-y-3">
        <div class="rounded-xl border border-white/10 bg-black/20 p-3">
          <label class="flex items-start gap-2">
            <input type="checkbox" wire:model.defer="alias.create_global_alias" class="mt-1 rounded border-white/20 bg-white/10" />
            <span class="text-sm text-white/85">Create global alias</span>
          </label>
          @if($alias['create_global_alias'] ?? false)
            <div class="mt-2">
              <flux:input wire:model.defer="alias.global_alias" label="Global alias" />
              @error('alias.global_alias') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
          @endif
        </div>

        <div class="rounded-xl border border-white/10 bg-black/20 p-3">
          <label class="flex items-start gap-2">
            <input type="checkbox" wire:model.defer="alias.create_customer_alias" class="mt-1 rounded border-white/20 bg-white/10" />
            <span class="text-sm text-white/85">Create customer-scoped alias</span>
          </label>
          <div class="mt-1 text-xs text-emerald-100/65">Account context: {{ $context['account_name'] ?: 'none' }}</div>
          @if($alias['create_customer_alias'] ?? false)
            <div class="mt-2">
              <flux:input wire:model.defer="alias.customer_alias" label="Customer alias" />
              @error('alias.customer_alias') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            </div>
          @endif
          @error('alias.create_customer_alias') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="rounded-xl border border-white/10 bg-black/20 p-3">
          <label class="flex items-start gap-2">
            <input type="checkbox" wire:model.defer="alias.save_raw_as_alias" class="mt-1 rounded border-white/20 bg-white/10" />
            <span class="text-sm text-white/85">Save unresolved incoming name as alias</span>
          </label>
          <div class="mt-1 text-xs text-emerald-100/65">Incoming: {{ $context['raw_name'] ?: 'none' }}</div>
        </div>
      </div>

      <div class="mt-5 flex items-center justify-between gap-2">
        <button type="button" wire:click="previousStep" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/80 hover:bg-white/10">Back</button>
        <button type="button" wire:click="nextStep" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-white">Continue</button>
      </div>
    </section>
  @endif

  @if($step === 4)
    <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
      <div class="text-sm font-semibold text-white">Step 4: Review</div>
      <p class="mt-1 text-sm text-emerald-50/75">Confirm whether this run maps to an existing scent or creates a new canonical scent.</p>

      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <div class="rounded-xl border border-white/10 bg-black/20 p-3">
          <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/60">Action</div>
          <div class="mt-1 text-sm text-white">
            @if($intent === 'new_scent') Create new canonical scent
            @elseif($intent === 'customer_alias') Map to existing scent + customer alias
            @elseif($intent === 'blend_template_placeholder') Blend-template placeholder path
            @else Map to existing scent
            @endif
          </div>
        </div>

        <div class="rounded-xl border border-white/10 bg-black/20 p-3">
          <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/60">Target scent</div>
          <div class="mt-1 text-sm text-white">
            @if($intent === 'new_scent')
              {{ $form['display_name'] ?: $form['name'] ?: '—' }}
            @else
              {{ $selectedScent?->display_name ?: ($selectedScent?->name ?: 'None selected') }}
            @endif
          </div>
        </div>
      </div>

      @if($intent === 'new_scent')
        <div class="mt-3 rounded-xl border border-white/10 bg-black/20 p-3 text-sm text-white/85 space-y-1">
          @php($recipeType = (string) ($form['recipe_type'] ?? 'single_oil'))
          @php($baseOilLookup = collect($baseOils ?? [])->keyBy('id'))
          @php($blendLookup = collect($blends ?? [])->keyBy('id'))
          <div><span class="text-emerald-100/70">Name:</span> {{ $form['name'] ?: '—' }}</div>
          <div><span class="text-emerald-100/70">Display:</span> {{ $form['display_name'] ?: '—' }}</div>
          <div><span class="text-emerald-100/70">Abbrev:</span> {{ $form['abbreviation'] ?: '—' }}</div>
          <div><span class="text-emerald-100/70">Recipe type:</span> {{ $recipeType === 'blend_backed' ? 'Blend-backed' : 'Single oil' }}</div>
          @if($recipeType === 'single_oil')
            @php($selectedOil = $baseOilLookup->get((int) ($form['base_oil_id'] ?? 0)))
            <div><span class="text-emerald-100/70">Primary oil:</span> {{ $selectedOil->name ?? '—' }}</div>
          @else
            <div>
              <span class="text-emerald-100/70">Recipe components:</span>
              @php($rows = $form['recipe_components'] ?? [])
              @if(empty($rows))
                —
              @else
                <ul class="mt-1 list-disc pl-5 space-y-1">
                  @foreach($rows as $row)
                    @php($type = (string) ($row['component_type'] ?? 'oil'))
                    @php($parts = $row['parts'] ?? null)
                    @php($percentage = $row['percentage'] ?? null)
                    <li class="text-xs">
                      @if($type === 'blend_template')
                        {{ $blendLookup->get((int) ($row['blend_template_id'] ?? 0))->name ?? 'Unknown blend template' }}
                      @else
                        {{ $baseOilLookup->get((int) ($row['base_oil_id'] ?? 0))->name ?? 'Unknown oil' }}
                      @endif
                      @if($parts) · {{ $parts }} parts @endif
                      @if($percentage) · {{ $percentage }}% @endif
                    </li>
                  @endforeach
                </ul>
              @endif
            </div>
          @endif
          <div><span class="text-emerald-100/70">Lifecycle:</span> {{ ucfirst((string) ($form['lifecycle_status'] ?? 'draft')) }}</div>
          <div><span class="text-emerald-100/70">Availability:</span>
            @php
              $enabled = collect($form['availability'] ?? [])->filter()->keys()->map(fn ($k) => str_replace('_', ' ', $k))->all();
            @endphp
            {{ $enabled ? implode(', ', $enabled) : 'none selected' }}
          </div>
        </div>
      @endif

      <div class="mt-3 rounded-xl border border-white/10 bg-black/20 p-3 text-sm text-white/85 space-y-1">
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-100/60">Aliases to create</div>
        <div>Global alias: {{ ($alias['create_global_alias'] ?? false) ? ($alias['global_alias'] ?: '—') : 'No' }}</div>
        <div>Customer alias: {{ ($alias['create_customer_alias'] ?? false) ? ($alias['customer_alias'] ?: '—') : 'No' }}</div>
        <div>Save incoming raw alias: {{ ($alias['save_raw_as_alias'] ?? false) ? (($context['raw_name'] ?: '—')) : 'No' }}</div>
      </div>

      @if(!empty($reviewWarnings))
        <div class="mt-3 space-y-2">
          @foreach($reviewWarnings as $warning)
            <div class="rounded-xl border border-amber-300/30 bg-amber-500/15 px-3 py-2 text-xs text-amber-50">
              {{ $warning['message'] ?? '' }}
            </div>
          @endforeach
        </div>
      @endif

      <div class="mt-5 flex items-center justify-between gap-2">
        <button type="button" wire:click="previousStep" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/80 hover:bg-white/10">Back</button>
        <button type="button" wire:click="complete" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-white">Complete</button>
      </div>
    </section>
  @endif

  @if($step === 5)
    <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
      <div class="text-sm font-semibold text-white">Step 5: Complete</div>
      <p class="mt-1 text-sm text-emerald-50/75">{{ $completion['message'] ?? 'Wizard completed.' }}</p>

      <div class="mt-4 rounded-xl border border-white/10 bg-black/20 p-3 text-sm text-white/85 space-y-1">
        <div><span class="text-emerald-100/70">Result:</span> {{ $completion['mode'] ?? '—' }}</div>
        <div><span class="text-emerald-100/70">Scent:</span> {{ $completion['scent_name'] ?? '—' }} @if(!empty($completion['scent_id'])) <span class="text-white/60">#{{ $completion['scent_id'] }}</span>@endif</div>
        <div><span class="text-emerald-100/70">Aliases applied:</span> {{ isset($completion['aliases']) ? count($completion['aliases']) : 0 }}</div>
        <div><span class="text-emerald-100/70">Wholesale mapping:</span> {{ !empty($completion['wholesale_mapping_created']) ? 'updated' : 'not needed' }}</div>
      </div>

      <div class="mt-5 flex items-center justify-end gap-2">
        <button type="button" wire:click="finish" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-white">Return to source</button>
      </div>
    </section>
  @endif
</div>
