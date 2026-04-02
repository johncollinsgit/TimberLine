@props([
    'section',
    'sections',
])

@php
    $sectionGroups = \App\Support\Marketing\CandleCashSectionRegistry::groupNavigationItems($sections);
    $tenantId = request()?->attributes->get('current_tenant_id');
    $resolvedTenantId = is_numeric($tenantId) ? (int) $tenantId : null;
    $resolvedLabels = app(\App\Services\Tenancy\TenantDisplayLabelResolver::class)->resolve($resolvedTenantId);
    $displayLabels = is_array($resolvedLabels['labels'] ?? null) ? (array) $resolvedLabels['labels'] : [];
    $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards'));
    if ($rewardsLabel === '') {
        $rewardsLabel = 'Rewards';
    }
    $accentStyles = [
        'amber' => [
            'dot' => 'bg-amber-600',
            'panel' => 'border-zinc-200 bg-zinc-50',
            'pill_current' => 'border-amber-300 bg-amber-100 text-amber-800',
        ],
        'emerald' => [
            'dot' => 'bg-emerald-600',
            'panel' => 'border-zinc-200 bg-zinc-50',
            'pill_current' => 'border-emerald-300 bg-emerald-100 text-emerald-800',
        ],
        'sky' => [
            'dot' => 'bg-sky-600',
            'panel' => 'border-zinc-200 bg-zinc-50',
            'pill_current' => 'border-sky-300 bg-sky-100 text-sky-800',
        ],
    ];
@endphp

<section class="rounded-[2rem] border border-zinc-200 bg-white p-5 sm:p-6 shadow-sm">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.32em] text-zinc-500">{{ $rewardsLabel }}</div>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-950">{{ $section['label'] }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-zinc-600">{{ $section['description'] }}</p>
        </div>
        <a href="{{ route('marketing.candle-cash') }}" wire:navigate class="inline-flex items-center rounded-full border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">
            Open {{ $rewardsLabel }}
        </a>
    </div>
</section>

<section class="rounded-[2rem] border border-zinc-200 bg-white p-4 sm:p-5 shadow-sm">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.28em] text-zinc-500">Pages</div>
            <div class="mt-1 text-sm text-zinc-600">Start with the overview, then move into the live earn and redeem rows when you need detail.</div>
        </div>
        <div class="text-[11px] uppercase tracking-[0.22em] text-zinc-500">Menu</div>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach($sectionGroups as $group)
            @php($accent = $accentStyles[$group['accent']] ?? $accentStyles['amber'])
            <article class="rounded-[1.55rem] border p-3 sm:p-4 shadow-sm {{ $accent['panel'] }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.28em] text-zinc-500">{{ $group['label'] }}</div>
                        <p class="mt-1 text-xs leading-5 text-zinc-600">{{ $group['description'] }}</p>
                    </div>
                    <span class="mt-1 h-2.5 w-2.5 rounded-full {{ $accent['dot'] }}"></span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($group['items'] as $item)
                        <a href="{{ $item['href'] }}" wire:navigate class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $item['current'] ? $accent['pill_current'] : 'border-zinc-300 bg-white text-zinc-700 hover:border-zinc-400 hover:bg-zinc-100' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>
