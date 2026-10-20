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
    @include('platform.partials.premium-motion')
    @include('platform.partials.studio-support-nav')
    <main class="fb-public-shell fb-contact-shell eb-studio-support-shell">
        <section class="fb-contact-overview" aria-label="Plans overview">
            <p class="fb-section-kicker">One simple plan</p>
            <h1 class="fb-contact-title">The tools you need. Room to grow.</h1>
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
                <h2 id="included-branches-title">Choose what works for your business.</h2>
                <p>Start with a few branches. Add more included branches as you need them. We help you connect and set them up.</p>
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
                <h2 id="premium-software-title">More advanced software, when you need it.</h2>
                <p>These products have separate pricing and an agreement before activation.</p>
            </div>
            <div class="fb-grid fb-grid-3">
                @foreach($premiumProducts as $product)
                    <article class="fb-card">
                        <h3>{{ $product['name'] }}</h3>
                        <p class="mt-2">Separate pricing</p>
                        <a class="fb-btn fb-btn-secondary mt-3" href="{{ route('platform.contact', ['intent' => 'sales']) }}">Talk about pricing</a>
                    </article>
                @endforeach
            </div>
        </section>
        <section class="fb-section" aria-label="Getting started">
            <div class="fb-card">
                <h2 class="text-xl font-semibold">A clear next step.</h2>
                <ol class="mt-3 space-y-2">
                    <li>1. Tell us about your business and choose your branches.</li>
                    <li>2. Review your setup, usage terms, and any premium software with us.</li>
                    <li>3. Receive access and follow the setup steps for your workspace.</li>
                </ol>
                <p class="mt-4">{{ $content['billing_note'] ?? '' }}</p>
            </div>
        </section>
    </main>
</body>
</html>
