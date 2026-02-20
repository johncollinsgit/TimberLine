@php
  $filterPills = ['all' => 'All', 'retail' => 'Retail', 'wholesale' => 'Wholesale'];
  $groupOptions = ['order' => 'Order', 'scent' => 'Product Title'];
  $sortOptions = ['most' => 'Most exceptions', 'recent' => 'Most recent', 'alpha' => 'Alphabetical'];
  $queueTabs = ['needs' => 'Needs Review', 'excluded' => 'Excluded', 'normalized' => 'Normalized'];
@endphp

<div class="space-y-6">
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Mapping Queue</div>
        <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">New Scent Intake</div>
        <div class="mt-2 text-sm text-emerald-50/70">
          This screen shows import exceptions that need human review. Fix the mapping once and future imports will be clean.
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        @foreach($queueTabs as $key => $label)
          <button type="button" wire:click="$set('queueTab','{{ $key }}')"
            class="px-3 py-1.5 rounded-full text-xs border transition
              {{ $queueTab === $key ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80 hover:bg-emerald-500/10 hover:border-emerald-300/25' }}">
            {{ $label }}
          </button>
        @endforeach
        @foreach($filterPills as $key => $label)
          <button type="button" wire:click="$set('filter','{{ $key }}')"
            class="px-3 py-1.5 rounded-full text-xs border transition
              {{ $filter === $key ? 'border-emerald-300/35 bg-emerald-400/25 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/80 hover:bg-emerald-500/10 hover:border-emerald-300/25' }}">
            {{ $label }}
          </button>
        @endforeach
      </div>
    </div>
  </section>

  {{-- Controls --}}
  <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 space-y-4">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div class="w-full lg:max-w-md">
        <label class="text-xs text-emerald-100/60">Search exceptions</label>
        <div class="mt-2 relative">
          <input type="text" wire:model.live.debounce.250ms="search"
            placeholder="Raw title, variant, order, customer…"
            class="w-full h-10 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-sm text-white/90"
          />
          @if($search !== '')
            <button type="button" wire:click="clearSearch" class="absolute right-3 top-2.5 text-xs text-emerald-100/70">Clear</button>
          @endif
        </div>
      </div>

      <div class="flex flex-wrap gap-3">
        @if($queueTab !== 'excluded' && $queueTab !== 'normalized')
          <label class="inline-flex items-center gap-2 text-xs text-emerald-50/70">
            <input type="checkbox" wire:model.live="onlyNeedsReview" class="rounded border-emerald-200/30 bg-black/30">
            Only needs review
          </label>
        @endif

        @if($queueTab !== 'normalized')
          <div class="flex items-center gap-2 text-xs text-emerald-50/70">
            Group by:
            @foreach($groupOptions as $key => $label)
              <button type="button" wire:click="$set('groupBy','{{ $key }}')"
                class="px-2.5 py-1 rounded-full border text-[11px]
                  {{ $groupBy === $key ? 'border-emerald-300/40 bg-emerald-500/20 text-emerald-50' : 'border-emerald-400/15 bg-emerald-500/5 text-white/70 hover:bg-emerald-500/10' }}">
                {{ $label }}
              </button>
            @endforeach
          </div>
        @endif

        @if($queueTab !== 'normalized')
          <div>
            <label class="text-xs text-emerald-100/60">Sort</label>
            <select wire:model.live="sort"
              class="mt-2 h-9 rounded-2xl border border-emerald-200/10 bg-black/20 px-3 text-xs text-white/90">
              @foreach($sortOptions as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
              @endforeach
            </select>
          </div>
        @endif
      </div>
    </div>

    {{-- Summary chips --}}
    <div class="flex flex-wrap gap-3 text-xs">
      <span class="inline-flex items-center px-3 py-1 rounded-full border border-emerald-200/10 bg-emerald-500/10 text-emerald-50/90">
        Total exceptions: {{ $summary['total'] ?? 0 }}
      </span>
      <span class="inline-flex items-center px-3 py-1 rounded-full border border-emerald-200/10 bg-emerald-500/10 text-emerald-50/90">
        Distinct scents: {{ $summary['scents'] ?? 0 }}
      </span>
      <span class="inline-flex items-center px-3 py-1 rounded-full border border-emerald-200/10 bg-emerald-500/10 text-emerald-50/90">
        Distinct orders: {{ $summary['orders'] ?? 0 }}
      </span>
      @if($latestRun)
        <span class="inline-flex items-center px-3 py-1 rounded-full border border-emerald-200/10 bg-emerald-500/5 text-emerald-50/70">
          Last import: #{{ $latestRun->id }} ({{ $latestRun->store_key ?? 'store' }}) · {{ optional($latestRun->finished_at ?? $latestRun->created_at)->toDateTimeString() }}
        </span>
      @endif
    </div>
  </section>

  {{-- Groups / Normalized --}}
  @if($queueTab === 'normalized')
    <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
      <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Normalized Intake</div>
      <div class="mt-2 text-sm text-emerald-50/70">Raw values received from Shopify and how they were normalized.</div>
      <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
        <div class="grid grid-cols-4 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
          <div>Field</div>
          <div>Raw</div>
          <div>Normalized</div>
          <div>Run / Channel</div>
        </div>
        <div class="divide-y divide-emerald-200/10">
          @forelse($normalizations ?? [] as $row)
            <div class="grid grid-cols-4 gap-0 px-3 py-2 text-xs text-white/80">
              <div class="text-white/70">{{ $row->field ?? '—' }}</div>
              <div class="truncate">{{ $row->raw_value ?? '—' }}</div>
              <div class="truncate text-emerald-100/80">{{ $row->normalized_value ?? '—' }}</div>
              <div class="text-white/60">{{ ucfirst($row->store_key ?? 'store') }}</div>
            </div>
          @empty
            <div class="px-3 py-3 text-xs text-white/60">No normalizations recorded yet.</div>
          @endforelse
        </div>
      </div>
      <div class="mt-4">
        {{ $normalizations?->links() }}
      </div>
    </div>
  @else
  <div class="space-y-4">
    @forelse($groups ?? [] as $group)
      @if($groupBy === 'order')
        @php
          $order = $orderIndex[$group->order_id] ?? null;
          $key = 'order:' . $group->order_id;
          $isExpanded = $expanded[$key] ?? false;
        @endphp
        <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5 cursor-pointer" wire:key="{{ $key }}"
             wire:click="toggleExpand('{{ $key }}','order','{{ $group->order_id }}')">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="pointer-events-none">
              <div class="text-white/90 font-semibold">
                #{{ $order->order_number ?? '—' }} — {{ $order->display_name ?? $order->order_label ?? $order->customer_name ?? 'Unknown' }}
              </div>
              <div class="mt-1 text-xs text-emerald-100/60">
                {{ ucfirst($order->order_type ?? 'retail') }} · {{ $group->lines_count }} exceptions ·
                First seen {{ optional($group->first_seen)->toDateString() ?? '—' }} ·
                Last seen {{ optional($group->last_seen)->toDateString() ?? '—' }}
              </div>
              @if($queueTab === 'excluded')
                <div class="mt-1 text-[11px] text-emerald-100/70">Excluded items in this order.</div>
              @endif
            </div>
            <div class="flex items-center gap-2" wire:click.stop>
              <button type="button" wire:click="toggleExpand('{{ $key }}','order','{{ $group->order_id }}')"
                class="rounded-full border border-emerald-400/35 bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-white">
                {{ $isExpanded ? 'Collapse' : 'Expand' }}
              </button>
              <button type="button" wire:click="openModalForOrder({{ (int) $group->order_id }})"
                class="rounded-full border border-emerald-300/35 bg-emerald-500/10 px-4 py-2 text-xs text-emerald-50">
                Fix Exceptions
              </button>
              @if($queueTab === 'excluded')
                <button type="button" wire:click="restoreOrder({{ (int) $group->order_id }})"
                  class="rounded-full border border-emerald-300/35 bg-emerald-500/10 px-4 py-2 text-xs text-emerald-50">
                  Restore
                </button>
              @endif
              <button type="button" wire:click="openOrderModal({{ (int) $group->order_id }})"
                class="rounded-full border border-emerald-400/20 bg-emerald-500/10 px-4 py-2 text-xs text-white/80">
                View Order
              </button>
            </div>
          </div>

          @if($isExpanded)
            @php $lines = $details[$key] ?? []; @endphp
            <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
              <div class="grid grid-cols-6 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
                <div>Order</div>
                <div>Customer</div>
                <div class="col-span-2">Raw Title / Variant</div>
                <div>Size / Wick</div>
                <div>Issue</div>
              </div>
              <div class="divide-y divide-emerald-200/10">
                @foreach($lines as $line)
                  <div class="grid grid-cols-6 gap-0 px-3 py-2 text-xs text-white/80">
                    <div>#{{ $line['order_number'] ?? '—' }}</div>
                    <div class="truncate">{{ $line['order_customer'] ?? '—' }}</div>
                    <div class="col-span-2">
                      <div class="font-semibold">{{ $line['raw_scent_name'] ?? $line['raw_title'] ?? '—' }}</div>
                      <div class="text-white/50">{{ $line['raw_variant'] ?? '—' }}</div>
                      @if(!empty($line['account_name']))
                        <div class="text-[10px] text-emerald-100/70">Account: {{ $line['account_name'] }}</div>
                      @endif
                    </div>
                    <div>
                      <div>{{ $line['size'] ?? '—' }}</div>
                      <div class="text-white/50">{{ $line['wick'] ?? '—' }}</div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                      @php
                        $issueText = count($line['status']) ? implode(', ', $line['status']) : 'unmapped';
                      @endphp
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-amber-300/30 bg-amber-400/20 text-amber-50">
                        {{ $issueText }}
                      </span>
                      <button type="button" wire:click="openModalForLine('line-{{ $line['id'] }}', {{ $line['id'] }})"
                        class="ml-auto inline-flex items-center px-2.5 py-1 rounded-full text-[10px] border border-emerald-300/30 bg-emerald-400/20 text-emerald-50">
                        Fix
                      </button>
                      @if($queueTab === 'excluded')
                        <button type="button" wire:click="restoreLine({{ $line['id'] }})"
                          class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] border border-emerald-300/35 bg-emerald-500/10 text-emerald-50">
                          Restore
                        </button>
                      @endif
                    </div>
                  </div>
                @endforeach
              </div>
            </div>
          @endif
        </div>
      @else
        @php
          $raw = $group->raw_title ?? '';
          $key = 'scent:' . sha1($raw);
          $isExpanded = $expanded[$key] ?? false;
        @endphp
        <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5" wire:key="{{ $key }}">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <div class="text-white/90 font-semibold">{{ $raw !== '' ? $raw : 'Unlabeled' }}</div>
              <div class="mt-1 text-xs text-emerald-100/60">
                {{ $group->lines_count }} line items · {{ $group->orders_count ?? 0 }} orders ·
                First seen {{ optional($group->first_seen)->toDateString() ?? '—' }} ·
                Last seen {{ optional($group->last_seen)->toDateString() ?? '—' }}
              </div>
              @if($queueTab === 'excluded')
                <div class="mt-1 text-[11px] text-emerald-100/70">Excluded exceptions for this label.</div>
              @endif
            </div>
            <div class="flex items-center gap-2">
              <button type="button" wire:click="toggleExpand('{{ $key }}','scent', @js($raw))"
                class="rounded-full border border-emerald-400/35 bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-white">
                {{ $isExpanded ? 'Collapse' : 'Expand' }}
              </button>
              <button type="button" wire:click="openModalForGroup('{{ $key }}', @js($raw))"
                class="rounded-full border border-emerald-400/35 bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-white">
                Match / Add Scent
              </button>
              @if($queueTab === 'excluded')
                <button type="button" wire:click="restoreGroup(@js($raw))"
                  class="rounded-full border border-emerald-300/35 bg-emerald-500/10 px-4 py-2 text-xs text-emerald-50">
                  Restore
                </button>
              @endif
            </div>
          </div>

          @if($isExpanded)
            @php $lines = $details[$key] ?? []; @endphp
            <div class="mt-4 rounded-2xl border border-emerald-200/10 overflow-hidden">
              <div class="grid grid-cols-6 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
                <div>Run / Channel</div>
                <div>Order</div>
                <div>Customer</div>
                <div>Raw Title / Variant</div>
                <div>Size / Wick</div>
                <div>Status</div>
              </div>
              <div class="divide-y divide-emerald-200/10">
                @foreach($lines as $line)
                  <div class="grid grid-cols-6 gap-0 px-3 py-2 text-xs text-white/80">
                    <div class="text-white/60">
                      {{ ucfirst($line['store_key'] ?? 'store') }}
                    </div>
                    <div>#{{ $line['order_number'] ?? '—' }}</div>
                    <div class="truncate">{{ $line['order_customer'] ?? '—' }}</div>
                    <div>
                      <div class="font-semibold">{{ $line['raw_scent_name'] ?? $line['raw_title'] ?? '—' }}</div>
                      <div class="text-white/50">{{ $line['raw_variant'] ?? '—' }}</div>
                      @if(!empty($line['account_name']))
                        <div class="text-[10px] text-emerald-100/70">Account: {{ $line['account_name'] }}</div>
                      @endif
                      @if(!empty($line['bundle_selections']))
                        <div class="mt-1 text-[11px] text-emerald-100/70">
                          @foreach($line['bundle_selections'] as $sel)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full border border-emerald-300/30 bg-emerald-500/10 text-emerald-50/90 mr-1 mb-1">
                              {{ $sel['scent_name'] }}
                            </span>
                          @endforeach
                        </div>
                      @endif
                    </div>
                    <div>
                      <div>{{ $line['size'] ?? '—' }}</div>
                      <div class="text-white/50">{{ $line['wick'] ?? '—' }}</div>
                    </div>
                    <div class="flex flex-wrap gap-1">
                      @foreach($line['status'] as $badge)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-amber-300/30 bg-amber-400/20 text-amber-50">
                          {{ $badge }}
                        </span>
                      @endforeach
                      @if($queueTab === 'excluded' && !empty($line['excluded_reason']))
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-white/10 bg-white/5 text-white/70">
                          {{ str_replace('_',' ', $line['excluded_reason']) }}
                        </span>
                      @endif
                      <button type="button" wire:click="openModalForLine('line-{{ $line['id'] }}', {{ $line['id'] }})"
                        class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-emerald-300/30 bg-emerald-400/20 text-emerald-50">
                        Fix
                      </button>
                      @if($queueTab === 'excluded')
                        <button type="button" wire:click="restoreLine({{ $line['id'] }})"
                          class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-emerald-300/35 bg-emerald-500/10 text-emerald-50">
                          Restore
                        </button>
                      @endif
                      <button type="button" wire:click="openOrderModal({{ (int) $line['order_id'] }}, [{{ $line['id'] }}])"
                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-emerald-300/20 bg-emerald-500/10 text-emerald-50/80">
                        View Order
                      </button>
                    </div>
                  </div>
                @endforeach
              </div>
            </div>
          @endif
        </div>
      @endif
    @empty
      <div class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 text-sm text-emerald-50/70">
        No exceptions match your filters.
      </div>
    @endforelse
  </div>

  @if($groups)
    <div>{{ $groups->links() }}</div>
  @endif
  @endif

  {{-- Mapping modal --}}
  @if($showModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-6">
      <div class="w-full max-w-2xl rounded-3xl border border-emerald-200/10 bg-[#0f1412] p-6">
        <div class="flex items-start justify-between">
          <div>
            <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Resolve Mapping</div>
            <div class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">{{ $modalRawTitle }}</div>
            <div class="mt-1 text-sm text-emerald-50/70">We’ll guide you through a few quick choices and only ask what’s missing.</div>
          </div>
          <button type="button" wire:click="closeModal" class="text-emerald-100/70">Close</button>
        </div>

        <livewire:intake.progressive-mapper :exception-ids="$modalExceptionIds" wire:key="progressive-{{ $modalKey }}" />
      </div>
    </div>
  @endif

  {{-- Order modal --}}
  @if($showOrderModal && $orderModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-6">
      <div class="w-full max-w-4xl rounded-3xl border border-emerald-200/10 bg-[#0f1412] p-6">
        <div class="flex items-start justify-between">
          <div>
            <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">Order Context</div>
            <div class="mt-2 text-2xl font-['Fraunces'] font-semibold text-white">
              #{{ $orderModal->order_number ?? '—' }} — {{ $orderModal->display_name ?? $orderModal->order_label ?? $orderModal->customer_name ?? 'Unknown' }}
            </div>
            <div class="mt-1 text-sm text-emerald-50/70">
              Created: {{ optional($orderModal->created_at)->toDateTimeString() ?? '—' }} ·
              Ship By: {{ optional($orderModal->ship_by_at)->toDateString() ?? '—' }} ·
              Bring Down: {{ optional($orderModal->due_at)->toDateString() ?? '—' }}
            </div>
          </div>
          <button type="button" wire:click="closeOrderModal" class="text-emerald-100/70">Close</button>
        </div>

        <div class="mt-6 rounded-2xl border border-emerald-200/10 overflow-hidden">
          <div class="grid grid-cols-5 gap-0 bg-black/30 text-[11px] text-white/50 px-3 py-2">
            <div>Line</div>
            <div>Raw Title</div>
            <div>Raw Variant</div>
            <div>Qty</div>
            <div>Status</div>
          </div>
          <div class="divide-y divide-emerald-200/10">
              @foreach($orderModalLines as $line)
              @php
                $isException = in_array($line->id, $orderModalLineExceptionIds ?? [], true);
              @endphp
              <div class="grid grid-cols-5 gap-0 px-3 py-2 text-xs text-white/80 {{ $isException ? 'bg-amber-500/10' : '' }}">
                <div>#{{ $line->id }}</div>
                <div class="font-semibold">{{ $line->raw_title ?? '—' }}</div>
                <div class="text-white/50">{{ $line->raw_variant ?? '—' }}</div>
                <div>{{ $line->ordered_qty ?? $line->quantity ?? 0 }}</div>
                <div>
                  @if($isException)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] border border-amber-300/30 bg-amber-400/20 text-amber-50">Exception</span>
                  @else
                    <span class="text-white/40">OK</span>
                  @endif
                </div>
              </div>
            @endforeach
          </div>
        </div>

        <details class="mt-4">
          <summary class="cursor-pointer text-xs text-emerald-100/60">Show raw payload (exception lines only)</summary>
          <pre class="mt-2 max-h-64 overflow-auto rounded-xl border border-emerald-200/10 bg-black/30 p-3 text-[11px] text-white/70">
@json($orderModalPayloads ?? [])
          </pre>
        </details>
      </div>
    </div>
  @endif
</div>
