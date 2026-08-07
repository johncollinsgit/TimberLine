@php
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
    $brandMarkPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg');
    $productName = (string) config('everbranch.product_name', 'Everbranch');
    $cta = is_array($cta ?? null) ? $cta : [];
    $startClientCta = is_array($cta['start_client'] ?? null) && filled($cta['start_client']['href'] ?? null)
        ? $cta['start_client']
        : ['href' => route('platform.start'), 'label' => 'Become a launch partner'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => $productName.' | One place to run your business',
        'description' => 'Everbranch brings customers, work, follow-ups, and the next right action into one calm operating system for small businesses.',
    ])
</head>
<body class="fb-public-body eb-studio-body" data-premium-motion="public">
    <a class="eb-skip-link" href="#main-content">Skip to main content</a>

    <header class="eb-studio-nav-wrap">
        <nav class="eb-studio-nav" aria-label="Primary navigation">
            <a class="eb-studio-brand" href="#top" aria-label="{{ $productName }} home">
                <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" />
            </a>
            <div class="eb-studio-nav__links" aria-label="Explore Everbranch">
                <a href="#how-it-works">How it works</a>
                <a href="#industries">Who it helps</a>
                <a href="#modules">Modules</a>
                <a href="{{ route('platform.plans') }}">Plans</a>
            </div>
            <div class="eb-studio-nav__actions">
                <a class="eb-studio-text-link" href="{{ route('login') }}">Log in</a>
                <a class="eb-studio-button eb-studio-button--dark" href="{{ $startClientCta['href'] }}">{{ $startClientCta['label'] }}</a>
            </div>
        </nav>
    </header>

    <main id="main-content">
        <section id="top" class="eb-studio-hero" aria-labelledby="hero-title" data-studio-hero>
            <div class="eb-studio-hero__media" aria-hidden="true">
                <img class="eb-studio-hero__slide is-active" data-studio-hero-slide src="{{ asset('images/public-site/everbranch-hvac-electrical-hero.jpg') }}" alt="" fetchpriority="high" decoding="async" />
                <img class="eb-studio-hero__slide eb-studio-hero__slide--field" data-studio-hero-slide src="{{ asset('images/public-site/everbranch-hvac-electrical-field.jpg') }}" alt="" decoding="async" />
                <img class="eb-studio-hero__slide eb-studio-hero__slide--owner" data-studio-hero-slide src="{{ asset('images/public-site/everbranch-field-owner-office.jpg') }}" alt="" decoding="async" />
            </div>
            <div class="eb-studio-hero__shade"></div>
            <div class="eb-studio-container eb-studio-hero__content" data-studio-reveal>
                <p class="eb-studio-eyebrow eb-studio-eyebrow--light">A calmer way to run the work</p>
                <h1 id="hero-title">Your business has a rhythm.<br>Everbranch helps you keep it.</h1>
                <p class="eb-studio-hero__lede">Customers, work, messages, files, and the next thing that needs attention, connected in one place that feels like it was made for how your team actually operates.</p>
                <div class="eb-studio-hero__actions">
                    <a class="eb-studio-button eb-studio-button--light" href="{{ $startClientCta['href'] }}">Become a launch partner <span aria-hidden="true">↗</span></a>
                    <button class="eb-studio-play" type="button" data-studio-film-open aria-haspopup="dialog">
                        <span class="eb-studio-play__icon" aria-hidden="true">▶</span>
                        <span>See the Everbranch story</span>
                    </button>
                </div>
                <p class="eb-studio-hero__note">One flat price for the business. No per-user fees.</p>
            </div>
        </section>

        <section class="eb-studio-manifesto" aria-labelledby="manifesto-title">
            <div class="eb-studio-container eb-studio-manifesto__grid">
                <div class="eb-studio-manifesto__media">
                    <p class="eb-studio-eyebrow">Built for the messy middle</p>
                    <figure>
                        <img src="{{ asset('images/public-site/everbranch-hvac-electrical-field.jpg') }}" alt="An HVAC and electrical technician documenting a service visit" loading="lazy" />
                    </figure>
                </div>
                <div>
                    <h2 id="manifesto-title">The work is personal. The system behind it should be human, too.</h2>
                    <p>Everbranch brings the useful context together without making your business feel like a spreadsheet. It gives the office, the field, and the owner a shared place to see what is true and what comes next.</p>
                    <a class="eb-studio-inline-link" href="#how-it-works">See how the pieces connect <span aria-hidden="true">↓</span></a>
                </div>
            </div>
        </section>

        <section id="how-it-works" class="eb-studio-story" aria-labelledby="story-title" data-studio-story>
            <div class="eb-studio-container eb-studio-story__grid">
                <div class="eb-studio-story__copy">
                    <p class="eb-studio-eyebrow">One clear picture</p>
                    <h2 id="story-title">From a new question to a finished job, nothing has to disappear between people.</h2>
                    <p>Choose a moment in the day to see how Everbranch holds the customer, the work, and the follow-up together.</p>
                    <div class="eb-studio-story__steps" aria-label="Everbranch workflow moments">
                        <button class="is-active" type="button" aria-pressed="true" data-studio-step="inbox">01 <span>A customer asks</span></button>
                        <button type="button" aria-pressed="false" data-studio-step="work">02 <span>Your team moves</span></button>
                        <button type="button" aria-pressed="false" data-studio-step="followup">03 <span>The relationship continues</span></button>
                    </div>
                    <p class="eb-studio-sr-status" data-studio-step-status aria-live="polite">Choose a workflow moment.</p>
                </div>
                <div class="eb-studio-product-frame" aria-live="polite" data-studio-frame>
                    <div class="eb-studio-product-frame__topbar">
                        <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                        <span>Everbranch workspace</span>
                        <span class="eb-studio-product-frame__presence">3 teammates online</span>
                    </div>
                    <div class="eb-studio-product-frame__body">
                        <aside aria-label="Example workspace navigation">
                            <span class="is-active">Overview</span><span>Customers</span><span>Work</span><span>Messages</span><span>Files</span>
                        </aside>
                        <div class="eb-studio-product-frame__canvas">
                            <div class="eb-studio-product-frame__label" data-studio-frame-label>Customer question</div>
                            <div class="eb-studio-product-frame__headline" data-studio-frame-headline>“Can we get this ready for the fall market?”</div>
                            <div class="eb-studio-product-frame__person"><span>MR</span><div><strong data-studio-frame-name>Maple &amp; Reed</strong><small data-studio-frame-subtitle>Wholesale buyer · first order</small></div></div>
                            <div class="eb-studio-product-frame__cards">
                                <article><small data-studio-card-one-label>REQUEST</small><strong data-studio-card-one>Line sheet + delivery question</strong><span data-studio-card-one-meta>Received just now</span></article>
                                <article><small data-studio-card-two-label>NEXT STEP</small><strong data-studio-card-two>Reply with current collection</strong><span data-studio-card-two-meta>Assigned to Jordan</span></article>
                            </div>
                            <div class="eb-studio-product-frame__activity" data-studio-frame-activity><span></span>Customer, context, and next step are already in the same place.</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="eb-studio-proof" aria-label="Everbranch outcomes">
            <div class="eb-studio-container">
                <div class="eb-studio-proof__heading">
                    <p class="eb-studio-eyebrow">Calm is operational</p>
                    <h2>Less context switching. More things actually getting done.</h2>
                </div>
                <div class="eb-studio-proof__grid">
                    <article><strong>One place</strong><span>for the customer record, open work, and conversation.</span></article>
                    <article><strong>Built to grow</strong><span>from a clear daily rhythm into the modules your business needs.</span></article>
                    <article><strong>Made for people</strong><span>so the owner, office, and team can work from the same truth.</span></article>
                </div>
            </div>
        </section>

        <section id="industries" class="eb-studio-industries" aria-labelledby="industries-title">
            <div class="eb-studio-container">
                <div class="eb-studio-section-heading">
                    <p class="eb-studio-eyebrow">Built around real work</p>
                    <h2 id="industries-title">A system that can meet your business where it is.</h2>
                    <p>Every business has a different rhythm. Everbranch starts with the work you do now and grows only where it helps.</p>
                </div>
                <div class="eb-studio-industry-grid" data-industry-options>
                    <button class="eb-studio-industry-card" type="button" data-industry-option="retail" aria-controls="industry-demo" aria-pressed="false"><img src="{{ asset('images/public-site/everbranch-industry-retail.jpg') }}" alt="" /><span><small>01 · Wholesale · loyalty · follow-ups</small><strong>Retail &amp; product brands</strong><em>Keep buyer questions, customer context, events, and reorders moving.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></button>
                    <button class="eb-studio-industry-card" type="button" data-industry-option="field" aria-controls="industry-demo" aria-pressed="false"><img src="{{ asset('images/public-site/everbranch-industry-field-service.jpg') }}" alt="" /><span><small>02 · Jobs · schedules · customer updates</small><strong>Field &amp; service teams</strong><em>Give office and field teams one living record for every job.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></button>
                    <button class="eb-studio-industry-card" type="button" data-industry-option="projects" aria-controls="industry-demo" aria-pressed="false"><img src="{{ asset('images/public-site/everbranch-industry-projects.jpg') }}" alt="" /><span><small>03 · Projects · files · handoffs</small><strong>Project work</strong><em>Bring approvals, materials, notes, and next steps out of the cracks.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></button>
                    <button class="eb-studio-industry-card" type="button" data-industry-option="studio" aria-controls="industry-demo" aria-pressed="false"><img src="{{ asset('images/public-site/everbranch-industry-studios.jpg') }}" alt="" /><span><small>04 · Clients · tasks · messages</small><strong>Independent studios</strong><em>Make room for the craft without losing the business behind it.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></button>
                </div>
                <section id="industry-demo" class="eb-studio-industry-demo" data-industry-demo aria-labelledby="industry-demo-title" hidden tabindex="-1">
                    <div class="eb-studio-industry-demo__intro">
                        <div><p class="eb-studio-eyebrow">A connected example</p><h3 id="industry-demo-title" data-industry-demo-title>Explore a business in motion.</h3></div>
                        <p>Start with the public website, then step into the shared context behind it. Everything here is fictional demo data.</p>
                    </div>
                    <div class="eb-studio-industry-demo__tabs" role="tablist" aria-label="Example software views">
                        <button type="button" role="tab" id="industry-demo-website-tab" aria-controls="industry-demo-website" aria-selected="true" data-industry-view="website">Website</button>
                        <button type="button" role="tab" id="industry-demo-workspace-tab" aria-controls="industry-demo-workspace" aria-selected="false" data-industry-view="workspace">Everbranch workspace</button>
                    </div>
                    <p class="eb-studio-industry-demo__status" aria-live="polite" data-industry-status></p>
                    <div class="eb-studio-demo-frame" data-industry-frame>
                        <div class="eb-studio-demo-cursor" data-industry-cursor aria-hidden="true"><span></span></div>
                        <section id="industry-demo-website" class="eb-studio-demo-pane is-active" role="tabpanel" aria-labelledby="industry-demo-website-tab" data-industry-pane="website">
                            <div class="eb-studio-demo-site">
                                <header><strong data-industry-site-brand>Everbranch example</strong><nav aria-label="Example website navigation"><button type="button" data-industry-site-nav>Services</button><button type="button" data-industry-site-nav>About</button><button type="button" data-industry-site-nav>Contact</button></nav><button type="button" class="eb-studio-demo-site__admin" data-industry-admin>Manage business <span aria-hidden="true">↗</span></button></header>
                                <div class="eb-studio-demo-site__hero"><img data-industry-site-image alt="" /><div><p data-industry-site-kicker>Made for real work</p><h4 data-industry-site-title>Your website, built around your business.</h4><p data-industry-site-copy>Show the right next step without losing the relationship behind it.</p><div><button type="button" class="eb-studio-demo-site__primary" data-industry-site-action>Start a request</button><button type="button" class="eb-studio-demo-site__secondary" data-industry-site-nav>See the details</button></div></div></div>
                                <footer><span data-industry-site-proof>A thoughtful public front door, connected to the work behind it.</span><b data-industry-site-result>Fictional website example</b></footer>
                            </div>
                        </section>
                        <section id="industry-demo-workspace" class="eb-studio-demo-pane" role="tabpanel" aria-labelledby="industry-demo-workspace-tab" data-industry-pane="workspace" hidden>
                            <div class="eb-studio-demo-workspace">
                                <header><span aria-hidden="true">◒</span><strong data-industry-workspace-brand>Everbranch workspace</strong><small>Demo workspace</small></header>
                                <div class="eb-studio-demo-workspace__body">
                                    <nav aria-label="Example workspace navigation"><button type="button" data-industry-workspace-nav="inbox" aria-pressed="true">Inbox</button><button type="button" data-industry-workspace-nav="customers" aria-pressed="false">Customers</button><button type="button" data-industry-workspace-nav="work" aria-pressed="false">Work</button><button type="button" data-industry-workspace-nav="messages" aria-pressed="false">Messages</button><button type="button" data-industry-workspace-nav="marketing" aria-pressed="false">Marketing</button><button type="button" data-industry-workspace-nav="followup" aria-pressed="false" data-industry-workspace-fifth>Follow-up</button></nav>
                                    <div class="eb-studio-demo-workspace__canvas" aria-live="polite"><p data-industry-workspace-label>Inbox</p><h4 data-industry-workspace-title>A customer question is already in context.</h4><div class="eb-studio-demo-workspace__cards"><article><small data-industry-card-one-label>REQUEST</small><strong data-industry-card-one>New question from a customer</strong><span data-industry-card-one-meta>Ready for the team</span></article><article><small data-industry-card-two-label>NEXT STEP</small><strong data-industry-card-two>Reply with the right detail</strong><span data-industry-card-two-meta>Assigned to the owner</span></article></div><div class="eb-studio-demo-message" data-industry-workspace-message><span aria-hidden="true"></span><p data-industry-message>Message activity appears here.</p><button type="button" data-industry-message-action>Open conversation</button></div></div>
                                </div>
                            </div>
                        </section>
                    </div>
                </section>
            </div>
        </section>

        <section id="modules" class="eb-studio-modules" aria-labelledby="modules-title">
            <div class="eb-studio-container eb-studio-modules__grid">
                <figure class="eb-studio-photo-card">
                    <img src="{{ asset('images/public-site/everbranch-hvac-electrical-field.jpg') }}" alt="An HVAC and electrical technician documenting a service visit" loading="lazy" />
                    <figcaption>Work follows the team, not the other way around.</figcaption>
                </figure>
                <div class="eb-studio-modules__copy">
                    <p class="eb-studio-eyebrow">A platform that expands with you</p>
                    <h2 id="modules-title">Start with a steadier day. Add what makes it stronger.</h2>
                    <p>Everbranch is intentionally modular. Your workspace can become more capable without becoming harder to understand.</p>
                    <div class="eb-studio-module-list">
                        <a href="{{ route('platform.modules.explore') }}#customers"><span>Customers</span><small>Keep every relationship in context</small><b>↗</b></a>
                        <a href="{{ route('platform.modules.explore') }}#work"><span>Work</span><small>Turn the next step into shared action</small><b>↗</b></a>
                        <a href="{{ route('platform.modules.explore') }}#marketing"><span>Growth</span><small>Keep the right customers close</small><b>↗</b></a>
                    </div>
                    <a class="eb-studio-inline-link" href="{{ route('platform.modules.explore') }}">Explore the module library <span aria-hidden="true">↗</span></a>
                </div>
            </div>
        </section>

        <section class="eb-studio-pricing" aria-labelledby="pricing-title">
            <div class="eb-studio-container eb-studio-pricing__card">
                <div><p class="eb-studio-eyebrow eb-studio-eyebrow--light">Launch with intention</p><h2 id="pricing-title">One flat business price.<br>No seat-count surprises.</h2></div>
                <div><p>We start with your actual operating rhythm, set up the pieces that matter, and leave the rest out of the way.</p><a class="eb-studio-button eb-studio-button--light" href="{{ $startClientCta['href'] }}">Become a launch partner <span aria-hidden="true">↗</span></a><a class="eb-studio-pricing__link" href="{{ route('platform.plans') }}">View plans and add-ons</a></div>
            </div>
        </section>
    </main>

    <footer class="eb-studio-footer">
        <div class="eb-studio-container eb-studio-footer__grid">
            <div><img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" /><p>One place to run the work that matters.</p></div>
            <div><a href="#how-it-works">How it works</a><a href="{{ route('platform.modules.explore') }}">Modules</a><a href="{{ route('platform.plans') }}">Plans</a></div>
            <div><a href="{{ route('platform.contact') }}">Contact</a><a href="{{ route('legal.privacy') }}">Privacy</a><a href="{{ route('legal.terms') }}">Terms</a></div>
        </div>
    </footer>

    <dialog class="eb-studio-film" data-studio-film aria-labelledby="film-title">
        <button class="eb-studio-film__close" type="button" data-studio-film-close aria-label="Close Everbranch story">×</button>
        <div class="eb-studio-film__frame">
            <img src="{{ asset('images/public-site/everbranch-hvac-electrical-hero.jpg') }}" alt="Two HVAC and electrical technicians reviewing work beside a service panel" />
                <div class="eb-studio-film__copy"><p class="eb-studio-eyebrow eb-studio-eyebrow--light">The Everbranch story</p><h2 id="film-title">A better business day begins when the next thing is clear.</h2><p>Everbranch is built to make the day more connected, not more complicated. A full sound-on product film and transcript can be dropped into this accessible film frame when approved production footage is ready.</p></div>
        </div>
    </dialog>
</body>
</html>
