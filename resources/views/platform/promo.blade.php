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
                <p class="fb-section-kicker">{{ $content['eyebrow'] ?? 'AI-Powered Small Business Workspace' }}</p>
                <h1>{{ $headline }}</h1>
                <p>
                    {{ $summary }}
                </p>
                <a href="#everbranch-public" class="fb-splash__button" data-splash-cta>
                    The Future of AI-Powered Small Business
                </a>
            </div>
        </header>

        <div class="fb-site-nav-wrap">
            <nav class="fb-site-nav fb-site-nav--premium" aria-label="Primary navigation">
                <a href="#splash" class="fb-site-brand fb-site-brand--lockup">
                    <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" />
                </a>
                <div class="fb-site-links" role="tablist" aria-label="Public sections">
                    <a id="tab-product" href="#everbranch-public" class="is-active" role="tab" aria-selected="true" aria-controls="panel-product" data-public-tab-trigger="product">Product</a>
                    <a id="tab-workflows" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-workflows" data-public-tab-trigger="workflows">Workflows</a>
                    <a id="tab-customers" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-customers" data-public-tab-trigger="customers">Customers</a>
                    <a id="tab-integrations" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-integrations" data-public-tab-trigger="integrations">Integrations</a>
                    <a id="tab-security" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-security" data-public-tab-trigger="security">Security</a>
                    <a id="tab-plans" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-plans" data-public-tab-trigger="plans">Plans</a>
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
                        <div class="fb-public-hero">
                            <div class="fb-public-hero__copy">
                                <p class="fb-section-kicker">Product</p>
                                <h2>One intelligent app for the messy middle of small business.</h2>
                                <p>
                                    Know your customers, track the work, understand what made money, and see what needs
                                    attention next. Everbranch gives your team one calm place to make sense of the day.
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
                                <p class="fb-section-kicker">What Everbranch Does</p>
                                <h2>It turns scattered business signals into a clear next action.</h2>
                                <p>Everbranch is built for founders and teams who need one place to understand what happened, what is happening, and what should happen next.</p>
                            </div>
                            <div class="fb-grid fb-grid-4">
                                <article class="fb-card fb-card--public">
                                    <h3>Know your customers</h3>
                                    <p>See who bought, asked, returned, requested, or needs follow-up without losing the human story.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>Track the work</h3>
                                    <p>Follow orders, jobs, batches, matters, projects, or service visits with labels that fit your business.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>Understand the money</h3>
                                    <p>Plan around revenue, cost, margin, materials, and effort before the numbers become a mystery.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>See what needs attention</h3>
                                    <p>Turn setup status, requested features, and daily signals into the next move your team can trust.</p>
                                </article>
                            </div>
                        </div>
                    </article>

                    <article id="panel-workflows" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-workflows" data-public-tab-panel="workflows" hidden>
                        <div class="fb-section fb-section--public" aria-label="Workflow paths">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Workflows</p>
                                <h2>Start with the door that matches your business.</h2>
                                <p>Every path is reviewed before advanced access or billing is considered. No checkout or payment flow starts from this page.</p>
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
                                    <p class="fb-section-kicker">Next Steps</p>
                                    <h2>A calm path from curiosity to a working workspace.</h2>
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
                        <div class="fb-section fb-section--public" aria-label="Who Everbranch is for">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Who It Is For</p>
                                <h2>Built around the business you have, not a generic template.</h2>
                                <p>Shopify can be the starting point, but Everbranch also supports direct, CSV, manual, and requested setup paths.</p>
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

                    <article id="panel-integrations" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-integrations" data-public-tab-panel="integrations" hidden>
                        <div class="fb-section fb-section--public" aria-label="Integrations and setup paths">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Integrations</p>
                                <h2>Shopify is supported. It is not the whole product.</h2>
                                <p>Bring a Shopify store, request CSV/manual setup, or ask Everbranch to shape a direct workspace around your business.</p>
                            </div>
                            <div class="fb-path-strip" data-premium-surface>
                                <span>Shopify</span>
                                <span>CSV / Spreadsheet</span>
                                <span>Manual setup</span>
                                <span>Direct workspace</span>
                                <span>Requested connectors</span>
                            </div>
                        </div>
                    </article>

                    <article id="panel-security" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-security" data-public-tab-panel="security" hidden>
                        <div class="fb-section fb-section--public" aria-label="Security and trust">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Security</p>
                                <h2>Quiet safeguards before big moves.</h2>
                                <p>Everbranch keeps access, setup, plan interest, and requested features review-controlled until the right evidence and approvals are in place.</p>
                            </div>
                            <div class="fb-grid fb-grid-3">
                                <article class="fb-card fb-card--public">
                                    <h3>Clear access</h3>
                                    <p>People land in the right workspace based on their role and business context.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>Reviewed setup</h3>
                                    <p>Requested paths are captured safely before features, connectors, or plan changes move forward.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>No surprise checkout</h3>
                                    <p>Public plan interest is a conversation starter. Billing activation is not part of this public page.</p>
                                </article>
                            </div>
                        </div>
                    </article>

                    <article id="panel-plans" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-plans" data-public-tab-panel="plans" hidden>
                        <div class="fb-section fb-section--public" aria-label="Plans and access">
                            <div class="fb-final-cta fb-final-cta--public" data-premium-surface>
                                <div>
                                    <p class="fb-section-kicker">Plans</p>
                                    <h2>Ready to see where Everbranch fits?</h2>
                                    <p>
                                        Compare plan direction, request a demo, or start as a client. Everbranch will review the right setup path before anything sensitive changes.
                                    </p>
                                </div>
                                <div class="fb-hero-cta">
                                    <a href="{{ route('platform.plans') }}" class="fb-btn fb-btn-secondary">View plans</a>
                                    <a href="{{ route('platform.demo') }}" class="fb-btn fb-btn-secondary">View a demo</a>
                                    <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Start as a client</a>
                                </div>
                            </div>

                            @if($planCards !== [])
                                <div class="fb-plan-teaser" aria-label="Plan preview">
                                    @foreach(array_slice($planCards, 0, 3) as $card)
                                        <article class="fb-card fb-card--public">
                                            <h3>{{ $card['label'] ?? 'Plan' }}</h3>
                                            <p><strong>{{ $card['price_display'] ?? 'Review with Everbranch' }}</strong></p>
                                            <p>{{ $card['summary'] ?? 'A reviewed plan direction for your workspace.' }}</p>
                                        </article>
                                    @endforeach
                                </div>
                            @endif
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
                <a href="{{ route('platform.plans') }}">Plans</a>
                <a href="{{ route('login') }}">Login</a>
            </nav>
        </footer>
    </div>
</body>
</html>
