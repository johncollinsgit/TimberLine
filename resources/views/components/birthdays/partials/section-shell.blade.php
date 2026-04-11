@props([
    'section',
    'sections',
    'title' => null,
    'description' => null,
    'suppressDescription' => false,
])

@php
    $sectionGroups = \App\Support\Birthdays\BirthdaySectionRegistry::groupNavigationItems($sections);
    $accentClass = static function (string $accent): string {
        return match (strtolower(trim($accent))) {
            'amber' => 'fb-accent-amber',
            'sky' => 'fb-accent-sky',
            default => 'fb-accent-rose',
        };
    };

    $resolvedTitle = trim((string) ($title ?: data_get($section, 'label', 'Birthdays')));
    $resolvedDescription = trim((string) ($description ?: data_get($section, 'description', '')));
@endphp

<section class="fb-page-surface">
    <div class="fb-birthdays-shell-head">
        <div class="fb-birthdays-shell-title">
            <div class="fb-birthdays-shell-eyebrow">Birthdays</div>
            <h1 class="fb-birthdays-shell-h1">{{ $resolvedTitle }}</h1>
            @if(! $suppressDescription && $resolvedDescription !== '')
                <p class="fb-birthdays-shell-subtitle">{{ $resolvedDescription }}</p>
            @endif
        </div>
        <a href="{{ route('birthdays.customers') }}" wire:navigate class="fb-btn-soft fb-link-soft">
            Open Customers
        </a>
    </div>

    <div class="fb-birthdays-shell-pages">
        <div class="fb-birthdays-shell-pages-head">
            <div class="fb-birthdays-shell-pages-label">Pages</div>
        </div>

        <div class="fb-birthdays-shell-groups">
            @foreach($sectionGroups as $group)
                @php($groupAccent = $accentClass((string) ($group['accent'] ?? 'rose')))
                <article class="fb-birthdays-nav-group fb-page-surface fb-page-surface--subtle {{ $groupAccent }}">
                    <div class="fb-birthdays-nav-group-head">
                        <div class="fb-birthdays-nav-group-text">
                            <div class="fb-birthdays-nav-group-label">{{ $group['label'] }}</div>
                            @if(! empty($group['description']))
                                <p class="fb-birthdays-nav-group-desc">{{ $group['description'] }}</p>
                            @endif
                        </div>
                        <span class="fb-accent-dot" aria-hidden="true"></span>
                    </div>

                    <div class="fb-birthdays-nav-group-items">
                        @foreach($group['items'] as $item)
                            <a
                                href="{{ $item['href'] }}"
                                wire:navigate
                                class="fb-chip {{ $item['current'] ? 'fb-chip--active' : 'fb-chip--quiet' }}"
                            >
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
