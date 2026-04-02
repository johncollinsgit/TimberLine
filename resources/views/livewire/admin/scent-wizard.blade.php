<div class="space-y-6">
  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">New Scent Wizard</div>
        <h1 class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Governed Scent Authoring Flow</h1>
        <p class="mt-2 max-w-3xl text-sm text-zinc-600">
          Match to existing first. Only create new canonical scents when no governed match exists.
        </p>
      </div>
      <a
        href="{{ $returnTo }}"
        wire:navigate
        class="inline-flex h-10 items-center justify-center rounded-full border border-zinc-200 bg-zinc-50 px-4 text-xs font-semibold text-zinc-800 hover:bg-zinc-100"
      >
        Cancel
      </a>
    </div>

    <div class="mt-5 grid gap-2 sm:grid-cols-4">
      @foreach([1 => 'Identify', 2 => 'Identity', 3 => 'Review', 4 => 'Complete'] as $i => $label)
        <button
          type="button"
          wire:click="jumpToStep({{ $i }})"
          class="rounded-xl border px-3 py-2 text-left transition {{ $step === $i ? 'border-emerald-300/40 bg-emerald-100 text-zinc-950' : ($step > $i ? 'border-emerald-300/25 bg-emerald-100 text-zinc-600' : 'border-zinc-200 bg-zinc-50 text-zinc-600') }}"
        >
          <div class="text-[10px] uppercase tracking-[0.22em]">Step {{ $i }}</div>
          <div class="mt-1 text-sm font-medium">{{ $label }}</div>
        </button>
      @endforeach
    </div>
  </section>

  <section class="rounded-3xl border border-zinc-200 bg-white p-6">
    <div class="grid gap-3 md:grid-cols-7">
      <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Incoming Name</div>
        <div class="mt-1 text-sm text-zinc-900">{{ $context['raw_name'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Variant</div>
        <div class="mt-1 text-sm text-zinc-900">{{ $context['raw_variant'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Account</div>
        <div class="mt-1 text-sm text-zinc-900">{{ $context['account_name'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Store</div>
        <div class="mt-1 text-sm text-zinc-900">{{ $context['store_key'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Source</div>
        <div class="mt-1 text-sm text-zinc-900">{{ $context['source_context'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Channel Hint</div>
        <div class="mt-1 text-sm text-zinc-900">{{ $context['channel_hint'] ?: '—' }}</div>
      </div>
      <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
        <div class="text-[10px] uppercase tracking-[0.22em] text-emerald-800">Product Form</div>
        <div class="mt-1 text-sm text-zinc-900">{{ $context['product_form_hint'] ?: '—' }}</div>
      </div>
    </div>
  </section>

  @if($step === 1)
    <section class="rounded-3xl border border-zinc-200 bg-white p-6">
      <div class="text-sm font-semibold text-zinc-950">Step 1: Identify what this is</div>
      <p class="mt-1 text-sm text-zinc-600">Choose intent, search likely existing matches, and prefer mapping before creating duplicates.</p>

      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <label class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="flex items-start gap-2">
            <input type="radio" wire:model.live="intent" value="map_existing" class="mt-1 rounded border-zinc-300 bg-zinc-100" />
            <div>
              <div class="text-sm font-medium text-zinc-950">Map to existing scent</div>
              <div class="text-xs text-zinc-600">Best for unresolved names that already have canonical matches.</div>
            </div>
          </div>
        </label>
        <label class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="flex items-start gap-2">
            <input type="radio" wire:model.live="intent" value="new_scent" class="mt-1 rounded border-zinc-300 bg-zinc-100" />
            <div>
              <div class="text-sm font-medium text-zinc-950">Create new scent</div>
              <div class="text-xs text-zinc-600">Use when no suitable existing scent or alias is found.</div>
            </div>
          </div>
        </label>
        <label class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="flex items-start gap-2">
            <input type="radio" wire:model.live="intent" value="customer_alias" class="mt-1 rounded border-zinc-300 bg-zinc-100" />
            <div>
              <div class="text-sm font-medium text-zinc-950">Customer alias for existing scent</div>
              <div class="text-xs text-zinc-600">Map account-specific naming to canonical scent without creating duplicates.</div>
            </div>
          </div>
        </label>
      </div>
      @error('intent') <div class="mt-2 text-xs text-red-300">{{ $message }}</div> @enderror

      <div class="mt-5">
        <label class="text-xs text-emerald-800">Search existing scents / aliases</label>
        <input
          type="text"
          wire:model.live.debounce.250ms="search"
          placeholder="Search by name, alias, abbreviation, oil ref..."
          class="mt-1 h-11 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-900"
        />
      </div>

      <div class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50">
        @if($matches->isNotEmpty())
          <div class="divide-y divide-zinc-200">
            @foreach($matches as $candidate)
              <button
                type="button"
                wire:click="selectExistingScent({{ (int) $candidate['id'] }})"
                class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left transition {{ (int) $selectedExistingScentId === (int) $candidate['id'] ? 'bg-emerald-100' : 'hover:bg-zinc-100' }}"
              >
                <div class="min-w-0">
                  <div class="truncate text-sm font-medium text-zinc-950">{{ $candidate['name'] }}</div>
                  <div class="truncate text-[11px] text-emerald-800">{{ $candidate['why'] ?? 'Matched existing records' }}</div>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-[11px]">
                  <span class="text-emerald-800">{{ $candidate['mapping_type'] }}</span>
                  <span class="text-zinc-600">{{ $candidate['score'] }}%</span>
                </div>
              </button>
            @endforeach
          </div>
        @else
          <div class="px-3 py-2 text-sm text-zinc-600">No matches yet. Keep typing or continue with new scent creation.</div>
        @endif
      </div>

      <div class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
        <span class="text-emerald-800">Selected:</span>
        @if($selectedScent)
          <span class="font-medium text-zinc-950">{{ $selectedScent->display_name ?: $selectedScent->name }}</span>
          <span class="text-zinc-500">#{{ $selectedScent->id }}</span>
        @else
          <span>None</span>
        @endif
      </div>
      @error('selectedExistingScentId') <div class="mt-2 text-xs text-red-300">{{ $message }}</div> @enderror

      <div class="mt-5 flex items-center justify-end gap-2">
        <button
          type="button"
          wire:click="nextStep"
          class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-zinc-950"
        >
          Continue
        </button>
      </div>
    </section>
  @endif

  @if($step === 2)
    <section class="rounded-3xl border border-zinc-200 bg-white p-6">
      <div class="text-sm font-semibold text-zinc-950">Step 2: Scent identity</div>
      <p class="mt-1 text-sm text-zinc-600">Define canonical scent metadata, lifecycle defaults, and channel/form availability.</p>

      <div class="mt-4 grid gap-4 lg:grid-cols-12">
        <div class="lg:col-span-4">
          <flux:input wire:model.defer="form.name" label="Name" />
          @error('form.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="lg:col-span-4">
          <flux:input wire:model.defer="form.display_name" label="Display Name" />
          @error('form.display_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="lg:col-span-2">
          <flux:input wire:model.defer="form.abbreviation" label="Abbrev" />
          @error('form.abbreviation') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="lg:col-span-2">
          <label class="text-xs text-emerald-800">Lifecycle default</label>
          <select wire:model.defer="form.lifecycle_status" class="mt-1 h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-zinc-900">
            @foreach($lifecycleStatuses as $status)
              <option value="{{ $status }}">{{ ucfirst($status) }}</option>
            @endforeach
          </select>
          @error('form.lifecycle_status') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="lg:col-span-6 rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="text-xs text-emerald-800">Recipe type</div>
          <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <label class="flex min-h-[56px] items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm leading-tight text-zinc-800">
              <input type="radio" wire:model.live="form.recipe_type" value="single_oil" class="mt-0.5 rounded border-zinc-300 bg-zinc-100" />
              <span>Single oil</span>
            </label>
            <label class="flex min-h-[56px] items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm leading-tight text-zinc-800">
              <input type="radio" wire:model.live="form.recipe_type" value="blend_backed" class="mt-0.5 rounded border-zinc-300 bg-zinc-100" />
              <span>Blend-backed</span>
            </label>
          </div>
          <div class="mt-2 text-[11px] leading-relaxed text-emerald-800">Recipe components must use existing oils or blend templates.</div>
          @error('form.recipe_type') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        <div class="lg:col-span-6">
          <label class="text-xs text-emerald-800">Notes</label>
          <textarea wire:model.defer="form.notes" rows="3" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900"></textarea>
          @error('form.notes') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        @if(($form['recipe_type'] ?? 'single_oil') === 'single_oil')
          <div class="lg:col-span-8 rounded-xl border border-zinc-200 bg-zinc-50 p-3">
            <label class="text-xs text-emerald-800">Primary oil</label>
            <div class="mt-2">
              <livewire:components.base-oil-combobox
                wire:model.live="form.base_oil_id"
                placeholder="Search and select existing oil..."
                :limit="25"
                :include-inactive="false"
                wire:key="wizard-single-oil-selector"
              />
            </div>
            <div class="mt-2 text-[11px] text-zinc-600">
              No oil match?
              <a href="{{ route('admin.index', ['tab' => 'master-data', 'resource' => 'base-oils']) }}" wire:navigate class="text-emerald-200 hover:text-emerald-900 underline decoration-dotted">Create missing oil in Master Data</a>.
            </div>
            @error('form.base_oil_id') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
            @error('form.oil_reference_name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
          </div>
        @else
          <div class="lg:col-span-12 rounded-xl border border-zinc-200 bg-zinc-50 p-3">
            <div class="flex items-center justify-between gap-2">
              <div>
                <div class="text-xs text-emerald-800">Blend-backed recipe components</div>
                <div class="text-[11px] text-zinc-600">Add oils and blend templates using governed selectors only.</div>
              </div>
              <button type="button" wire:click="addRecipeComponent" class="rounded-full border border-zinc-300 bg-emerald-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-emerald-900 hover:bg-emerald-500/25">Add Component</button>
            </div>

            <div class="mt-3 space-y-3">
              @foreach($form['recipe_components'] ?? [] as $index => $row)
                <div class="grid gap-2 rounded-xl border border-zinc-200 bg-zinc-50 p-3 md:grid-cols-12">
                  <div class="md:col-span-3">
                    <label class="text-[11px] text-emerald-800">Component type</label>
                    <select wire:model.live="form.recipe_components.{{ $index }}.component_type" class="mt-1 h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-900">
                      <option value="oil">Oil</option>
                      <option value="blend_template">Blend template</option>
                    </select>
                  </div>

                  <div class="md:col-span-5">
                    @if(($row['component_type'] ?? 'oil') === 'blend_template')
                      <label class="text-[11px] text-emerald-800">Blend template</label>
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
                      <label class="text-[11px] text-emerald-800">Oil</label>
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
                    <label class="text-[11px] text-emerald-800">Parts</label>
                    <input type="number" min="0.01" step="0.01" wire:model.defer="form.recipe_components.{{ $index }}.parts" class="mt-1 h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-900" />
                    @error("form.recipe_components.$index.parts") <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
                  </div>

                  <div class="md:col-span-2">
                    <label class="text-[11px] text-emerald-800">% (optional)</label>
                    <input type="number" min="0.01" max="100" step="0.01" wire:model.defer="form.recipe_components.{{ $index }}.percentage" class="mt-1 h-10 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-900" />
                    <div class="mt-2 flex items-center justify-between">
                      @error("form.recipe_components.$index.percentage") <div class="text-xs text-red-300">{{ $message }}</div> @enderror
                      <button type="button" wire:click="removeRecipeComponent({{ $index }})" class="ml-auto rounded-full border border-red-300/30 bg-red-500/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-red-100 hover:bg-red-500/20">Remove</button>
                    </div>
                  </div>
                </div>
              @endforeach
            </div>

            @error('form.recipe_components') <div class="mt-2 text-xs text-red-300">{{ $message }}</div> @enderror

            <div class="mt-2 text-[11px] text-zinc-600">
              Missing a source record?
              <a href="{{ route('admin.index', ['tab' => 'master-data', 'resource' => 'base-oils']) }}" wire:navigate class="text-emerald-200 hover:text-emerald-900 underline decoration-dotted">Create oils in Master Data</a>
              or
              <a href="{{ route('admin.index', ['tab' => 'blends']) }}" wire:navigate class="text-emerald-200 hover:text-emerald-900 underline decoration-dotted">maintain blend templates</a>.
            </div>
          </div>
        @endif

        <div class="lg:col-span-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="text-xs uppercase tracking-[0.22em] text-emerald-800">Classification</div>
          <div class="mt-2 space-y-2">
            <label class="flex items-center gap-2 text-sm text-zinc-800">
              <input type="checkbox" wire:model.defer="form.is_wholesale_custom" class="rounded border-zinc-300 bg-zinc-100" />
              <span>Wholesale custom scent</span>
            </label>
            <label class="flex items-center gap-2 text-sm text-zinc-800">
              <input type="checkbox" wire:model.defer="form.is_candle_club" class="rounded border-zinc-300 bg-zinc-100" />
              <span>Candle Club scent</span>
            </label>
          </div>
        </div>

        <div class="lg:col-span-8 rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="text-xs uppercase tracking-[0.22em] text-emerald-800">Availability</div>
          <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
            @foreach(['retail' => 'Retail', 'wholesale' => 'Wholesale', 'candle_club' => 'Candle Club', 'room_spray' => 'Room Spray', 'wax_melt' => 'Wax Melt'] as $key => $label)
              <label class="flex items-center gap-2 text-sm text-zinc-800">
                <input type="checkbox" wire:model.defer="form.availability.{{ $key }}" class="rounded border-zinc-300 bg-zinc-100" />
                <span>{{ $label }}</span>
              </label>
            @endforeach
          </div>
        </div>
      </div>

      <div class="mt-5 flex items-center justify-between gap-2">
        <button type="button" wire:click="previousStep" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Back</button>
        <button type="button" wire:click="nextStep" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-zinc-950">Continue</button>
      </div>
    </section>
  @endif

  @if($step === 3)
    <section class="rounded-3xl border border-zinc-200 bg-white p-6">
      <div class="text-sm font-semibold text-zinc-950">Step 3: Review</div>
      <p class="mt-1 text-sm text-zinc-600">Confirm whether this run maps to an existing scent or creates a new canonical scent.</p>

      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-800">Action</div>
          <div class="mt-1 text-sm text-zinc-950">
            @if($intent === 'new_scent') Create new canonical scent
            @elseif($intent === 'customer_alias') Map to existing scent + customer alias
            @else Map to existing scent
            @endif
          </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
          <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-800">Target scent</div>
          <div class="mt-1 text-sm text-zinc-950">
            @if($intent === 'new_scent')
              {{ $form['display_name'] ?: $form['name'] ?: '—' }}
            @else
              {{ $selectedScent?->display_name ?: ($selectedScent?->name ?: 'None selected') }}
            @endif
          </div>
        </div>
      </div>

      @if($intent === 'new_scent')
        <div class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-800 space-y-1">
          @php($recipeType = (string) ($form['recipe_type'] ?? 'single_oil'))
          @php($baseOilLookup = collect($baseOils ?? [])->keyBy('id'))
          @php($blendLookup = collect($blends ?? [])->keyBy('id'))
          <div><span class="text-emerald-800">Name:</span> {{ $form['name'] ?: '—' }}</div>
          <div><span class="text-emerald-800">Display:</span> {{ $form['display_name'] ?: '—' }}</div>
          <div><span class="text-emerald-800">Abbrev:</span> {{ $form['abbreviation'] ?: '—' }}</div>
          <div><span class="text-emerald-800">Recipe type:</span> {{ $recipeType === 'blend_backed' ? 'Blend-backed' : 'Single oil' }}</div>
          @if($recipeType === 'single_oil')
            @php($selectedOil = $baseOilLookup->get((int) ($form['base_oil_id'] ?? 0)))
            <div><span class="text-emerald-800">Primary oil:</span> {{ $selectedOil?->name ?? '—' }}</div>
          @else
            <div>
              <span class="text-emerald-800">Recipe components:</span>
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
                        {{ $blendLookup->get((int) ($row['blend_template_id'] ?? 0))?->name ?? 'Unknown blend template' }}
                      @else
                        {{ $baseOilLookup->get((int) ($row['base_oil_id'] ?? 0))?->name ?? 'Unknown oil' }}
                      @endif
                      @if($parts) · {{ $parts }} parts @endif
                      @if($percentage) · {{ $percentage }}% @endif
                    </li>
                  @endforeach
                </ul>
              @endif
            </div>
          @endif
          <div><span class="text-emerald-800">Lifecycle:</span> {{ ucfirst((string) ($form['lifecycle_status'] ?? 'draft')) }}</div>
          <div><span class="text-emerald-800">Availability:</span>
            @php($enabledAvailability = collect($form['availability'] ?? [])->filter()->keys()->map(fn ($k) => str_replace('_', ' ', $k))->all())
            {{ $enabledAvailability ? implode(', ', $enabledAvailability) : 'none selected' }}
          </div>
        </div>
      @endif

      <div class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-800 space-y-1">
        <div class="text-[11px] uppercase tracking-[0.22em] text-emerald-800">Alias behavior</div>
        @if($intent === 'new_scent')
          <div>No alias will be created during new scent creation.</div>
        @elseif($intent === 'customer_alias')
          <div>Incoming name <span class="text-zinc-950">{{ $context['raw_name'] ?: '—' }}</span> will be saved as a customer-scoped alias for account <span class="text-zinc-950">{{ $context['account_name'] ?: '—' }}</span>.</div>
        @else
          <div>Incoming name <span class="text-zinc-950">{{ $context['raw_name'] ?: '—' }}</span> will be saved as alias in scopes: <span class="text-zinc-950">{{ implode(', ', $plannedAliasScopes ?? []) ?: 'global' }}</span>.</div>
        @endif
      </div>

      @if(!empty($reviewWarnings))
        <div class="mt-3 space-y-2">
          @foreach($reviewWarnings as $warning)
            <div class="rounded-xl border border-amber-300/30 bg-amber-100 px-3 py-2 text-xs text-amber-900">
              {{ $warning['message'] ?? '' }}
            </div>
          @endforeach
        </div>
      @endif

      <div class="mt-5 flex items-center justify-between gap-2">
        <button type="button" wire:click="previousStep" class="rounded-full border border-zinc-200 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Back</button>
        <button type="button" wire:click="complete" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-zinc-950">Complete</button>
      </div>
    </section>
  @endif

  @if($step === 4)
    <section class="rounded-3xl border border-zinc-200 bg-white p-6">
      <div class="text-sm font-semibold text-zinc-950">Step 4: Complete</div>
      <p class="mt-1 text-sm text-zinc-600">{{ $completion['message'] ?? 'Wizard completed.' }}</p>

      <div class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-800 space-y-1">
        <div><span class="text-emerald-800">Result:</span> {{ $completion['mode'] ?? '—' }}</div>
        <div><span class="text-emerald-800">Scent:</span> {{ $completion['scent_name'] ?? '—' }} @if(!empty($completion['scent_id'])) <span class="text-zinc-500">#{{ $completion['scent_id'] }}</span>@endif</div>
        <div><span class="text-emerald-800">Aliases applied:</span> {{ isset($completion['aliases']) ? count($completion['aliases']) : 0 }}</div>
        <div><span class="text-emerald-800">Wholesale mapping:</span> {{ !empty($completion['wholesale_mapping_created']) ? 'updated' : 'not needed' }}</div>
      </div>

      <div class="mt-5 flex items-center justify-end gap-2">
        <button type="button" wire:click="finish" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-5 py-2 text-xs font-semibold text-zinc-950">Return to source</button>
      </div>
    </section>
  @endif
</div>
