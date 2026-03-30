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
            'dot' => 'bg-amber-300 shadow-[0_0_0_5px_rgba(252,211,77,0.12)]',
            'panel' => 'border-amber-300/15 bg-[radial-gradient(circle_at_top_left,rgba(245,158,11,0.18),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.07),rgba(255,255,255,0.04))]',
            'pill_current' => 'border-amber-300/45 bg-amber-400/15 text-amber-50 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_10px_28px_-18px_rgba(245,158,11,0.85)]',
        ],
        'emerald' => [
            'dot' => 'bg-emerald-300 shadow-[0_0_0_5px_rgba(110,231,183,0.12)]',
            'panel' => 'border-emerald-300/15 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.18),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.07),rgba(255,255,255,0.04))]',
            'pill_current' => 'border-emerald-300/45 bg-emerald-400/15 text-emerald-50 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_10px_28px_-18px_rgba(16,185,129,0.85)]',
        ],
        'sky' => [
            'dot' => 'bg-sky-300 shadow-[0_0_0_5px_rgba(125,211,252,0.12)]',
            'panel' => 'border-sky-300/15 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.18),transparent_52%),linear-gradient(180deg,rgba(255,255,255,0.07),rgba(255,255,255,0.04))]',
            'pill_current' => 'border-sky-300/45 bg-sky-400/15 text-sky-50 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_10px_28px_-18px_rgba(14,165,233,0.85)]',
        ],
    ];
@endphp

<section class="rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.12),transparent_35%),linear-gradient(180deg,rgba(255,255,255,0.09),rgba(255,255,255,0.04))] p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.6)] backdrop-blur-xl">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.32em] text-white/55">{{ $rewardsLabel }}</div>
            <h1 class="mt-2 text-2xl font-semibold text-white">{{ $section['label'] }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-white/70">{{ $section['description'] }}</p>
        </div>
        <a href="{{ route('marketing.candle-cash') }}" wire:navigate class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
            Open {{ $rewardsLabel }}
        </a>
    </div>
</section>

<section class="rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.08),transparent_48%),linear-gradient(180deg,rgba(17,24,39,0.55),rgba(10,14,24,0.78))] p-4 sm:p-5 shadow-[inset_0_1px_0_rgba(255,255,255,0.08),0_24px_60px_-44px_rgba(0,0,0,0.8)] backdrop-blur-xl">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-[11px] uppercase tracking-[0.28em] text-white/50">Pages</div>
            <div class="mt-1 text-sm text-white/68">Start with the overview, then move into the live earn and redeem rows when you need detail.</div>
        </div>
        <div class="text-[11px] uppercase tracking-[0.22em] text-white/35">Menu</div>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach($sectionGroups as $group)
            @php($accent = $accentStyles[$group['accent']] ?? $accentStyles['amber'])
            <article class="rounded-[1.55rem] border p-3 sm:p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.08),0_16px_34px_-26px_rgba(0,0,0,0.9)] backdrop-blur-xl {{ $accent['panel'] }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.28em] text-white/48">{{ $group['label'] }}</div>
                        <p class="mt-1 text-xs leading-5 text-white/60">{{ $group['description'] }}</p>
                    </div>
                    <span class="mt-1 h-2.5 w-2.5 rounded-full {{ $accent['dot'] }}"></span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($group['items'] as $item)
                        <a href="{{ $item['href'] }}" wire:navigate class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition backdrop-blur-md {{ $item['current'] ? $accent['pill_current'] : 'border-white/10 bg-white/[0.06] text-white/[0.78] hover:border-white/20 hover:bg-white/[0.12] hover:text-white' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>
