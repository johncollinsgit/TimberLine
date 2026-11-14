@php
    $content = is_array($content ?? null) ? $content : [];
    $planCards = (array) ($plan_cards ?? []);
    $branches = (array) ($branch_cards ?? []);
    $premiumProducts = (array) ($premium_products ?? []);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => 'Everbranch pricing'])
</head>
<body class="fb-public-body eb-studio-support-body" data-premium-motion="public">
    @include('platform.partials.studio-support-nav')
    <main class="fb-public-shell fb-contact-shell eb-studio-support-shell">
        <section class="fb-contact-overview" aria-label="Plans overview">
            <p class="fb-section-kicker">One simple plan</p>
            <h1 class="fb-contact-title">One simple plan.</h1>
            <p class="fb-contact-summary">{{ $content['subtitle'] ?? '' }}</p>
        </section>
        <section class="fb-section" aria-label="Everbranch offer">
            @foreach($planCards as $card)
                <article class="fb-card">
                    <h2 class="text-2xl font-semibold">{{ $card['label'] }}</h2>
                    <p class="mt-2 text-xl font-semibold">{{ $card['price_display'] }}</p>
                    <p class="mt-3">{{ $card['summary'] }}</p>
                    <ul>
                        @foreach($card['highlights'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <a class="fb-btn fb-btn-primary" href="{{ route('platform.start') }}">Get started</a>
                        <a class="fb-btn fb-btn-secondary" href="{{ route('platform.contact', ['intent' => 'walkthrough']) }}">Request a walkthrough</a>
                    </div>
                </article>
            @endforeach
        </section>
        <section class="fb-section" aria-labelledby="included-branches-title">
            <div class="fb-section-header">
                <p class="fb-section-kicker">Included core branches</p>
                <h2 id="included-branches-title">Your branches, included.</h2>
                <p>Start with what you need. Add more when you are ready.</p>
            </div>
            <div class="fb-grid fb-grid-2">
                @foreach(array_slice($branches, 0, 6) as $branch)
                    <article class="fb-card">
                        <h3>{{ $branch['display_name'] }}</h3>
                        <p class="mt-2">{{ $branch['short_description'] }}</p>
                        <a class="fb-btn fb-btn-secondary mt-3" href="{{ route('platform.modules.show', ['module' => $branch['key']]) }}">See how it works</a>
                    </article>
                @endforeach
            </div>
            <a class="fb-btn fb-btn-secondary mt-5" href="{{ route('platform.modules.explore') }}">Explore all included branches</a>
        </section>
        <section class="fb-section" aria-labelledby="premium-software-title">
            <div class="fb-section-header">
                <p class="fb-section-kicker">Separate premium software</p>
                <h2 id="premium-software-title">Room to grow.</h2>
                <p>These products have separate pricing and an agreement before activation.</p>
            </div>
            <div class="fb-grid fb-grid-3">
                @foreach($premiumProducts as $product)
                    <article class="fb-card">
                        <h3>{{ $product['name'] }}</h3>
                        <p class="mt-2">Separate pricing</p>
                        <a class="fb-btn fb-btn-secondary mt-3" href="{{ route('platform.contact', ['intent' => 'sales']) }}">Request pricing</a>
                    </article>
                @endforeach
            </div>
        </section>
        <section class="fb-section" aria-label="Getting started">
            <div class="fb-card">
                <h2 class="text-xl font-semibold">Make it yours.</h2>
                <ol class="mt-3 space-y-2">
                    <li>1. Choose your branches.</li>
                    <li>2. Review your setup and costs.</li>
                    <li>3. Set up your workspace.</li>
                </ol>
                <p class="mt-4">{{ $content['billing_note'] ?? '' }}</p>
            </div>
        </section>
    </main>
    @include('platform.partials.ask-everbranch')
</body>
</html>
