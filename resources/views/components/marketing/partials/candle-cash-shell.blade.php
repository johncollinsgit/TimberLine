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
    $accentClass = static function (string $accent): string {
        return match (strtolower(trim($accent))) {
            'sky' => 'fb-accent-sky',
            'emerald' => 'fb-accent-emerald',
            default => 'fb-accent-amber',
        };
    };
@endphp

<section class="fb-page-surface p-5 sm:p-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="fb-kpi-label">{{ $rewardsLabel }}</div>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-950">{{ $section['label'] }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-zinc-600">{{ $section['description'] }}</p>
        </div>
        <a href="{{ route('marketing.candle-cash') }}" wire:navigate class="fb-btn-soft fb-link-soft">
            Open {{ $rewardsLabel }}
        </a>
    </div>
</section>

<section class="fb-page-surface fb-page-surface--muted p-4 sm:p-5">
    <div class="flex items-end justify-between gap-4">
        <div class="fb-kpi-label">Pages</div>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach($sectionGroups as $group)
            @php($groupAccent = $accentClass((string) ($group['accent'] ?? 'amber')))
            <article class="fb-surface-inset p-3 sm:p-4 {{ $groupAccent }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="fb-kpi-label">{{ $group['label'] }}</div>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">{{ $group['description'] }}</p>
                    </div>
                    <span class="fb-accent-dot" aria-hidden="true"></span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($group['items'] as $item)
                        <a href="{{ $item['href'] }}" wire:navigate class="fb-chip {{ $item['current'] ? 'fb-chip--active' : 'fb-chip--quiet' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>
