@php
    $content = is_array($content ?? null) ? $content : [];
    $planCards = is_array($plan_cards ?? null) ? $plan_cards : (is_array($planCards ?? null) ? $planCards : []);
    $addonCards = is_array($addon_cards ?? null) ? $addon_cards : (is_array($addonCards ?? null) ? $addonCards : []);
    $recommendedPlanKey = (string) ($recommended_plan_key ?? 'growth');
    $promo = (array) config('product_surfaces.promo', []);
    $ctas = is_array($promo['ctas'] ?? null) ? (array) $promo['ctas'] : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => 'Plans'])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <main class="fb-public-shell fb-contact-shell">
        <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary fb-contact-back">Back to homepage</a>

        <section class="fb-card fb-contact-overview" aria-label="Plans overview" data-reveal data-premium-surface>
            <p class="fb-section-kicker">Plans</p>
            <h1 class="fb-contact-title">{{ $content['headline'] ?? 'Plans & Add-ons' }}</h1>
            <p class="fb-contact-summary">{{ $content['subtitle'] ?? 'Choose a tier, then layer add-ons as needed.' }}</p>
        </section>

        <section class="fb-section" aria-label="Tier plans" data-reveal>
            <div class="fb-grid fb-grid-3">
                @foreach($planCards as $card)
                    @php
                        $key = (string) ($card['plan_key'] ?? '');
                        $isRecommended = $key !== '' && $key === $recommendedPlanKey;
                    @endphp
                    <article class="fb-card" data-premium-surface style="{{ $isRecommended ? 'border-color: rgba(18, 60, 67, 0.28); background: rgba(30, 90, 99, 0.06);' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-[var(--fb-text-primary)]">{{ $card['label'] ?? 'Plan' }}</h2>
                                <div class="mt-1 text-sm text-[var(--fb-text-secondary)]">{{ $card['price_display'] ?? '' }}</div>
                            </div>
                            @if($isRecommended)
                                <span class="fb-chip fb-chip--quiet">Recommended</span>
                            @endif
                        </div>
                        <p class="mt-3 text-sm text-[var(--fb-text-secondary)]">{{ $card['summary'] ?? '' }}</p>
                        @if(! empty($card['highlights']))
                            <ul class="mt-3 text-sm text-[var(--fb-text-secondary)] space-y-1">
                                @foreach((array) $card['highlights'] as $line)
                                    <li>• {{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if(is_array($ctas['start_client'] ?? null) && filled($ctas['start_client']['href'] ?? null))
                                <a class="fb-btn fb-btn-primary" href="{{ $ctas['start_client']['href'] }}">{{ $ctas['start_client']['label'] ?? 'Start as a client' }}</a>
                            @endif
                            @if(is_array($ctas['demo'] ?? null) && filled($ctas['demo']['href'] ?? null))
                                <a class="fb-btn fb-btn-secondary" href="{{ $ctas['demo']['href'] }}">{{ $ctas['demo']['label'] ?? 'See a live demo' }}</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="fb-section" aria-label="Add-ons" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">Add-ons</p>
                <h2>Layer additional modules when you need them.</h2>
                <p class="text-sm text-[var(--fb-text-secondary)]">Add-ons are shown separately from tiers. Roadmap modules remain clearly labeled.</p>
            </div>
            <div class="fb-grid fb-grid-3">
                @foreach($addonCards as $addon)
                    <article class="fb-card" data-premium-surface>
                        <h3 class="text-base font-semibold text-[var(--fb-text-primary)]">{{ $addon['label'] ?? 'Add-on' }}</h3>
                        <div class="mt-1 text-sm text-[var(--fb-text-secondary)]">{{ $addon['price_display'] ?? '' }}</div>
                        <p class="mt-3 text-sm text-[var(--fb-text-secondary)]">{{ $addon['summary'] ?? '' }}</p>
                        @if(! empty($addon['modules']))
                            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                                @foreach((array) $addon['modules'] as $module)
                                    @php $isSoon = (bool) ($module['coming_soon'] ?? false); @endphp
                                    <span class="fb-module-pill {{ $isSoon ? 'fb-module-pill--accent' : '' }}">
                                        {{ $module['label'] ?? $module['module_key'] ?? 'Module' }}{{ $isSoon ? ' · coming soon' : '' }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        @if(filled($content['billing_note'] ?? null))
            <section class="fb-section" aria-label="Billing note" data-reveal>
                <div class="fb-card" data-premium-surface>
                    <h2 class="text-base font-semibold text-[var(--fb-text-primary)]">Billing</h2>
                    <p class="mt-2 text-sm text-[var(--fb-text-secondary)]">{{ $content['billing_note'] }}</p>
                </div>
            </section>
        @endif
    </main>
</body>
</html>

