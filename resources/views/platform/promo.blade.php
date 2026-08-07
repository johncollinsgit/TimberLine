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
                <a href="#customer-loop">Customer Loop</a>
                <a class="eb-studio-nav__demo-link" href="{{ route('platform.industry-demo', ['discipline' => 'field']) }}">Explore a system</a>
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

        <section id="industries" class="eb-studio-industries" aria-labelledby="industries-title">
            <div class="eb-studio-container">
                <div class="eb-studio-section-heading">
                    <p class="eb-studio-eyebrow">Built around real work</p>
                    <h2 id="industries-title">A system that can meet your business where it is.</h2>
                    <p>Open a fictional website and operations-workspace example for a business like yours. Every person, request, message, and campaign is demo content.</p>
                </div>
                <div class="eb-studio-industry-grid" data-industry-options>
                    <a class="eb-studio-industry-card" href="{{ route('platform.industry-demo', ['discipline' => 'retail']) }}" data-industry-option="retail"><img src="{{ asset('images/public-site/everbranch-industry-retail.jpg') }}" alt="" /><span><small>01 · Wholesale · loyalty · follow-ups</small><strong>Retail &amp; product brands</strong><em>Keep buyer questions, customer context, events, and reorders moving.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></a>
                    <a class="eb-studio-industry-card" href="{{ route('platform.industry-demo', ['discipline' => 'field']) }}" data-industry-option="field"><img src="{{ asset('images/public-site/everbranch-industry-field-service.jpg') }}" alt="" /><span><small>02 · Jobs · schedules · customer updates</small><strong>Field &amp; service teams</strong><em>Give office and field teams one living record for every job.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></a>
                    <a class="eb-studio-industry-card" href="{{ route('platform.industry-demo', ['discipline' => 'projects']) }}" data-industry-option="projects"><img src="{{ asset('images/public-site/everbranch-industry-projects.jpg') }}" alt="" /><span><small>03 · Projects · files · handoffs</small><strong>Project work</strong><em>Bring approvals, materials, notes, and next steps out of the cracks.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></a>
                    <a class="eb-studio-industry-card" href="{{ route('platform.industry-demo', ['discipline' => 'studio']) }}" data-industry-option="studio"><img src="{{ asset('images/public-site/everbranch-industry-studios.jpg') }}" alt="" /><span><small>04 · Clients · tasks · messages</small><strong>Independent studios</strong><em>Make room for the craft without losing the business behind it.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></a>
                    <a class="eb-studio-industry-card" href="{{ route('platform.industry-demo', ['discipline' => 'practice']) }}" data-industry-option="practice"><img src="{{ asset('images/public-site/everbranch-field-owner-office.jpg') }}" alt="" /><span><small>05 · Consultations · records · guidance</small><strong>Professional practices</strong><em>Make the first conversation, preparation, and follow-up feel considered.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></a>
                    <a class="eb-studio-industry-card" href="{{ route('platform.industry-demo', ['discipline' => 'community']) }}" data-industry-option="community"><img src="{{ asset('images/public-site/everbranch-field-team.jpg') }}" alt="" /><span><small>06 · Programs · people · invitations</small><strong>Community teams</strong><em>Keep people, gatherings, and the next useful message connected.</em><b>Explore the example <i aria-hidden="true">↗</i></b></span></a>
                </div>
            </div>
        </section>

        <section id="customer-loop" class="eb-studio-loop" aria-labelledby="customer-loop-title">
            <div class="eb-studio-container eb-studio-loop__grid">
                <div>
                    <p class="eb-studio-eyebrow eb-studio-eyebrow--light">Customer Loop</p>
                    <h2 id="customer-loop-title">Good work should make the next relationship easier.</h2>
                    <p>Everbranch turns a real customer moment into a clear human next step: a follow-up, review request, text, email, or social draft. Your team reviews every message before it goes out.</p>
                    <a class="eb-studio-button eb-studio-button--light" href="{{ $startClientCta['href'] }}">Plan your Customer Loop <span aria-hidden="true">↗</span></a>
                </div>
                <ol class="eb-studio-loop__steps">
                    <li><span>01</span><div><strong>Something real happens</strong><p>A job wraps, an order arrives, a customer asks, or a great result is worth sharing.</p></div></li>
                    <li><span>02</span><div><strong>Everbranch prepares the next step</strong><p>Start from useful templates or shape your own if/then flow in Workflow Studio.</p></div></li>
                    <li><span>03</span><div><strong>A person stays in control</strong><p>Review the draft, adjust it, and decide whether to send or publish. Nothing leaves on autopilot.</p></div></li>
                </ol>
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
                    <div class="eb-studio-product-frame__topbar"><img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" /><span>Everbranch workspace</span><span class="eb-studio-product-frame__presence">3 teammates online</span></div>
                    <div class="eb-studio-product-frame__body"><aside aria-label="Example workspace navigation"><span class="is-active">Overview</span><span>Customers</span><span>Work</span><span>Messages</span><span>Files</span></aside><div class="eb-studio-product-frame__canvas"><div class="eb-studio-product-frame__label" data-studio-frame-label>Customer question</div><div class="eb-studio-product-frame__headline" data-studio-frame-headline>“Can we get this ready for the fall market?”</div><div class="eb-studio-product-frame__person"><span>MR</span><div><strong data-studio-frame-name>Maple &amp; Reed</strong><small data-studio-frame-subtitle>Wholesale buyer · first order</small></div></div><div class="eb-studio-product-frame__cards"><article><small data-studio-card-one-label>REQUEST</small><strong data-studio-card-one>Line sheet + delivery question</strong><span data-studio-card-one-meta>Received just now</span></article><article><small data-studio-card-two-label>NEXT STEP</small><strong data-studio-card-two>Reply with current collection</strong><span data-studio-card-two-meta>Assigned to Jordan</span></article></div><div class="eb-studio-product-frame__activity" data-studio-frame-activity><span></span>Customer, context, and next step are already in the same place.</div></div></div>
                </div>
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
