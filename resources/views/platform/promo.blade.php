@php
    $content = is_array($promo ?? null) ? $promo : [];
    $cta = is_array($content['ctas'] ?? null) ? $content['ctas'] : [];
    $planCards = is_array($plan_cards ?? null) ? $plan_cards : [];
    $previewProfiles = is_array($content['preview_profiles'] ?? null) ? $content['preview_profiles'] : [];
    $previewFlow = is_array($content['preview_flow'] ?? null) ? $content['preview_flow'] : [];
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
    $brandMarkPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg');
    $productName = (string) config('everbranch.product_name', 'Everbranch');
    $companyName = (string) config('everbranch.company_name', 'Evergrove');
    $headline = (string) ($content['headline'] ?? 'All of your business, in one place');
    $summary = (string) ($content['summary'] ?? 'Everbranch brings customers, work, money, materials, communication, and next steps into one intelligent app.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => $productName.' | The Future of AI-Powered Small Business',
        'description' => $summary,
    ])
</head>
<body class="fb-public-body fb-public-body--splash" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <div class="fb-public-shell fb-public-shell--wide">
        <div class="fb-site-nav-wrap">
            <nav class="fb-site-nav fb-site-nav--premium" aria-label="Primary navigation">
                <a href="#splash" class="fb-site-brand fb-site-brand--lockup">
                    <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" />
                </a>
                <div class="fb-site-links" role="tablist" aria-label="Public sections">
                    <a id="tab-product" href="#everbranch-public" class="is-active" role="tab" aria-selected="true" aria-controls="panel-product" data-public-tab-trigger="product">What it does</a>
                    <a id="tab-workflows" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-workflows" data-public-tab-trigger="workflows">Daily work</a>
                    <a id="tab-customers" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-customers" data-public-tab-trigger="customers">Who it helps</a>
                    <a id="tab-privacy" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-privacy" data-public-tab-trigger="privacy">Privacy</a>
                </div>
                <div class="fb-hero-cta fb-hero-cta--nav">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                    @if(is_array($cta['start_client'] ?? null) && filled($cta['start_client']['href'] ?? null))
                        <a href="{{ $cta['start_client']['href'] }}" class="fb-btn fb-btn-primary">{{ $cta['start_client']['label'] ?? 'Start as a client' }}</a>
                    @else
                        <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Start as a client</a>
                    @endif
                </div>
            </nav>
        </div>

        <main id="everbranch-public" class="fb-public-main" tabindex="-1">
            <section class="fb-public-tabs" aria-label="Everbranch overview tabs" data-public-tabs data-reveal>
                <div class="fb-public-tabs__panels">
                    <article id="panel-product" class="fb-public-tab-panel is-active" role="tabpanel" aria-labelledby="tab-product" data-public-tab-panel="product">
                        <header id="splash" class="fb-splash" aria-label="Everbranch entry">
                            <div class="fb-splash__field" aria-hidden="true">
                                <span class="fb-splash__branch fb-splash__branch--one"></span>
                                <span class="fb-splash__branch fb-splash__branch--two"></span>
                                <span class="fb-splash__branch fb-splash__branch--three"></span>
                                <span class="fb-splash__node fb-splash__node--one"></span>
                                <span class="fb-splash__node fb-splash__node--two"></span>
                                <span class="fb-splash__node fb-splash__node--three"></span>
                                <span class="fb-splash__node fb-splash__node--four"></span>
                                <span class="fb-splash__node fb-splash__node--five"></span>
                            </div>

                            <div class="fb-splash__content" data-reveal>
                                <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" class="fb-splash__mark" />
                                <p class="fb-section-kicker">{{ $content['eyebrow'] ?? 'Built for busy small businesses' }}</p>
                                <h1>{{ $headline }}</h1>
                                <p>
                                    {{ $summary }}
                                </p>
                                <a href="#everbranch-public" class="fb-splash__button" data-splash-cta>
                                    The Future of AI-Powered Small Business
                                </a>
                            </div>
                        </header>

                        <div class="fb-public-hero">
                            <div class="fb-public-hero__copy">
                                <p class="fb-section-kicker">What it does</p>
                                <h2>One place to see what is happening in your business.</h2>
                                <p>
                                    Everbranch helps you keep track of customers, jobs, orders, money, materials,
                                    messages, and follow-ups so fewer things slip through the cracks.
                                </p>
                                <div class="fb-hero-cta">
                                    <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Request access</a>
                                    <a href="{{ route('platform.demo') }}" class="fb-btn fb-btn-secondary">View a demo</a>
                                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                                </div>
                            </div>
                            <aside class="fb-orbit-panel" data-premium-surface aria-label="Everbranch focus areas">
                                <div class="fb-orbit-panel__center">
                                    <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                    <span>{{ $productName }}</span>
                                </div>
                                <div class="fb-orbit-panel__list">
                                    <span>Customers</span>
                                    <span>Work</span>
                                    <span>Money</span>
                                    <span>Materials</span>
                                    <span>Communication</span>
                                    <span>Next steps</span>
                                </div>
                            </aside>
                        </div>

                        <div class="fb-section fb-section--public" aria-label="What Everbranch does">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">What Everbranch does</p>
                                <h2>It helps you see the day clearly.</h2>
                                <p>Everbranch is for teams who need a simple way to know what happened, what is happening, and what needs attention next.</p>
                            </div>
                            <div class="fb-grid fb-grid-4">
                                <article class="fb-card fb-card--public">
                                    <h3>Keep customer details together</h3>
                                    <p>See who called, bought, asked, requested work, or needs a follow-up.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>Track the work</h3>
                                    <p>Follow jobs, orders, batches, service calls, or visits with words that fit your business.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>Understand the money</h3>
                                    <p>See revenue, costs, materials, and effort before the numbers turn into a guessing game.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>See what needs attention</h3>
                                    <p>Know which customer, job, message, or task needs the next move.</p>
                                </article>
                            </div>
                        </div>
                    </article>

                    <article id="panel-workflows" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-workflows" data-public-tab-panel="workflows" hidden>
                        <div class="fb-section fb-section--public" aria-label="Daily work">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Daily work</p>
                                <h2>Start with the parts of your business you want help with first.</h2>
                                <p>You do not have to set everything up at once. Start with customers, jobs, orders, materials, or follow-ups, then add more when it makes sense.</p>
                            </div>
                            <div class="fb-grid fb-grid-3">
                                @foreach((array) ($content['how_it_works'] ?? []) as $step)
                                    <article class="fb-card fb-card--public">
                                        <h3>{{ $step['title'] ?? 'Step' }}</h3>
                                        <p>{{ $step['description'] ?? '' }}</p>
                                    </article>
                                @endforeach
                            </div>
                        </div>

                        @if($previewFlow !== [])
                            <div class="fb-section fb-section--public" aria-label="What happens next">
                                <div class="fb-section-header">
                                    <p class="fb-section-kicker">Next steps</p>
                                    <h2>From interested to set up, without guessing.</h2>
                                </div>
                                <div class="fb-grid fb-grid-3">
                                    @foreach($previewFlow as $step)
                                        <article class="fb-card fb-card--public" data-premium-surface>
                                            <h3>{{ $step['title'] ?? 'Step' }}</h3>
                                            <p>{{ $step['description'] ?? '' }}</p>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </article>

                    <article id="panel-customers" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-customers" data-public-tab-panel="customers" hidden>
                        <div class="fb-section fb-section--public" aria-label="Who Everbranch helps">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Who it helps</p>
                                <h2>Built for real small businesses, not software teams.</h2>
                                <p>Everbranch can fit service businesses, local crews, shops, makers, and teams that currently rely on texts, spreadsheets, notebooks, and memory.</p>
                            </div>
                            @if($previewProfiles !== [])
                                <div class="fb-grid fb-grid-3">
                                    @foreach($previewProfiles as $profile)
                                        <article class="fb-card fb-card--public" data-premium-surface>
                                            <h3>{{ $profile['label'] ?? 'Business type' }}</h3>
                                            <p>{{ $profile['summary'] ?? '' }}</p>
                                            @if(! empty($profile['signals']))
                                                <ul>
                                                    @foreach((array) $profile['signals'] as $signal)
                                                        <li>{{ $signal }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </article>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>

                    <article id="panel-privacy" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-privacy" data-public-tab-panel="privacy" hidden>
                        <div class="fb-section fb-section--public" aria-label="Privacy and business information">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Privacy</p>
                                <h2>Bring in your information without losing control.</h2>
                                <p>Start with Shopify, a spreadsheet, manual entry, or a guided setup. The goal is to stop retyping the same details in five different places while keeping important changes reviewed first.</p>
                            </div>
                            <div class="fb-path-strip" data-premium-surface>
                                <span>Shopify store</span>
                                <span>Spreadsheet</span>
                                <span>Manual entry</span>
                                <span>Guided setup</span>
                                <span>More connections later</span>
                            </div>
                            <div class="fb-grid fb-grid-3">
                                <article class="fb-card fb-card--public">
                                    <h3>The right people see the right things</h3>
                                    <p>Your team gets access based on what they need to do, not everything by default.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>Setup is checked first</h3>
                                    <p>Important setup choices are reviewed before they change how your business runs.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>No surprise charges</h3>
                                    <p>Browsing the site or asking for help does not start a payment from this page.</p>
                                </article>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </main>

        <footer class="fb-footer fb-footer--public" aria-label="Footer">
            <div>
                <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" />
                <span>{{ $productName }} by {{ $companyName }}</span>
            </div>
            <nav aria-label="Footer navigation">
                <a href="{{ route('platform.contact') }}">Contact</a>
                <a href="{{ route('login') }}">Login</a>
            </nav>
        </footer>
    </div>
</body>
</html>
