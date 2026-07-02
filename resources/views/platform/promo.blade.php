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
        'title' => $productName.' | Small-Business Operating Workspace',
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
                                    See Everbranch in action
                                </a>
                            </div>
                        </header>

                        <div class="fb-public-hero">
                            <div class="fb-public-hero__copy">
                                <p class="fb-section-kicker">What it does</p>
                                <h2>Give your company an app your team will actually use.</h2>
                                <p>
                                    Everbranch gives customers, tasks, notes, follow-ups, messages, and next steps
                                    one simple place to live, so your team does not have to hunt through texts,
                                    spreadsheets, notebooks, and memory.
                                </p>
                                <div class="fb-hero-cta">
                                    <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Request access</a>
                                    <a href="{{ route('platform.demo') }}" class="fb-btn fb-btn-secondary">See Everbranch in action</a>
                                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                                </div>
                            </div>
                            <aside class="fb-product-demo" data-demo-mode="problem" data-premium-surface data-public-product-demo aria-label="Everbranch workflow example">
                                <div class="fb-product-demo__tabs" role="tablist" aria-label="Small business examples">
                                    <button
                                        type="button"
                                        class="is-active"
                                        role="tab"
                                        aria-selected="true"
                                        data-product-demo-scenario="retail"
                                        data-demo-customer="Pine &amp; Porch"
                                        data-demo-type="Retail buyer"
                                        data-demo-primary="Wholesale request"
                                        data-demo-note="Asked for event bestsellers, case pricing, and a fall reorder reminder."
                                        data-demo-task="Send line sheet and approve wholesale access"
                                        data-demo-owner="Sarah"
                                        data-demo-followup="Reorder follow-up ready for next Friday"
                                        data-demo-feed-one="Buyer details captured"
                                        data-demo-feed-two="Task assigned to Sarah"
                                        data-demo-feed-three="Follow-up reminder queued"
                                    >
                                        <span>Retail</span>
                                        <strong>Wholesale request → task → reorder follow-up</strong>
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected="false"
                                        data-product-demo-scenario="trades"
                                        data-demo-customer="Monroe Ave Service Call"
                                        data-demo-type="Electrical &amp; plumbing"
                                        data-demo-primary="Job note"
                                        data-demo-note="Breaker panel photo came in with a parts question and a customer timing note."
                                        data-demo-task="Confirm parts and assign crew next step"
                                        data-demo-owner="Eli"
                                        data-demo-followup="Customer update due before 3 PM"
                                        data-demo-feed-one="Job note saved"
                                        data-demo-feed-two="Parts question added"
                                        data-demo-feed-three="Crew next step assigned"
                                    >
                                        <span>Trades</span>
                                        <strong>Job note → parts question → crew next step</strong>
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected="false"
                                        data-product-demo-scenario="construction"
                                        data-demo-customer="Maple Street Remodel"
                                        data-demo-type="Construction project"
                                        data-demo-primary="Approval needed"
                                        data-demo-note="Client approved the fixture change, but material timing and punch-list items need one place."
                                        data-demo-task="Update materials and owner punch-list"
                                        data-demo-owner="Maya"
                                        data-demo-followup="Punch-list item ready for Friday review"
                                        data-demo-feed-one="Approval captured"
                                        data-demo-feed-two="Material note organized"
                                        data-demo-feed-three="Punch-list item assigned"
                                    >
                                        <span>Projects</span>
                                        <strong>Approval → material note → punch-list item</strong>
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected="false"
                                        data-product-demo-scenario="service"
                                        data-demo-customer="Northline Maintenance"
                                        data-demo-type="Service business"
                                        data-demo-primary="Client record"
                                        data-demo-note="Recurring appointment, open question, and handoff note are tied to the same customer."
                                        data-demo-task="Schedule visit and send reminder"
                                        data-demo-owner="Jordan"
                                        data-demo-followup="Reminder ready for Monday morning"
                                        data-demo-feed-one="Client record updated"
                                        data-demo-feed-two="Appointment added"
                                        data-demo-feed-three="Reminder prepared"
                                    >
                                        <span>Service</span>
                                        <strong>Client record → appointment → reminder</strong>
                                    </button>
                                </div>

                                <div class="fb-product-demo__frame" aria-live="polite">
                                    <div class="fb-product-demo__topbar">
                                        <span class="fb-product-demo__dot"></span>
                                        <span class="fb-product-demo__dot"></span>
                                        <span class="fb-product-demo__dot"></span>
                                        <strong>{{ $productName }} workspace</strong>
                                        <em data-product-demo-field="type">Retail buyer</em>
                                    </div>
                                    <div class="fb-product-demo__mode" role="tablist" aria-label="Problem and solution view">
                                        <button type="button" class="is-active" role="tab" aria-selected="true" data-product-demo-mode="problem">
                                            <span>Problem</span>
                                            Details are scattered
                                        </button>
                                        <button type="button" role="tab" aria-selected="false" data-product-demo-mode="solution">
                                            <span>Solution</span>
                                            Everbranch gives it a home
                                        </button>
                                    </div>
                                    <div class="fb-product-demo__mess" data-product-demo-problem aria-label="Scattered work example">
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--text">Text: “Can you resend pricing?”</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--sheet">Spreadsheet row missing follow-up</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--note">Notebook: call back Friday</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--photo">Photo + parts question</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--memory">Someone remembers the next step</span>
                                    </div>
                                    <div class="fb-product-demo__workspace">
                                        <nav class="fb-product-demo__sidebar" aria-label="Example workspace sections">
                                            <span class="is-active">Customers</span>
                                            <span>Jobs</span>
                                            <span>Tasks</span>
                                            <span>Notes</span>
                                            <span>Follow-ups</span>
                                        </nav>
                                        <section class="fb-product-demo__record" aria-label="Example record">
                                            <div class="fb-product-demo__record-head">
                                                <div>
                                                    <p>Open record</p>
                                                    <h3 data-product-demo-field="customer">Pine &amp; Porch</h3>
                                                </div>
                                                <span data-product-demo-field="primary">Wholesale request</span>
                                            </div>
                                            <p data-product-demo-field="note">Asked for event bestsellers, case pricing, and a fall reorder reminder.</p>
                                            <div class="fb-product-demo__task-card">
                                                <span>Next step</span>
                                                <strong data-product-demo-field="task">Send line sheet and approve wholesale access</strong>
                                                <small>Assigned to <b data-product-demo-field="owner">Sarah</b></small>
                                            </div>
                                        </section>
                                        <section class="fb-product-demo__activity" aria-label="Example activity">
                                            <p>Activity</p>
                                            <ul>
                                                <li data-product-demo-feed="one">Buyer details captured</li>
                                                <li data-product-demo-feed="two">Task assigned to Sarah</li>
                                                <li data-product-demo-feed="three">Follow-up reminder queued</li>
                                            </ul>
                                        </section>
                                    </div>
                                    <div class="fb-product-demo__workflow" aria-label="Example workflow progress">
                                        <ol>
                                            <li class="is-active" data-product-demo-step="0">Detail captured</li>
                                            <li data-product-demo-step="1">Work organized</li>
                                            <li data-product-demo-step="2">Next step assigned</li>
                                            <li data-product-demo-step="3">Follow-up ready</li>
                                        </ol>
                                        <p data-product-demo-field="followup">Reorder follow-up ready for next Friday</p>
                                    </div>
                                </div>

                                <p class="fb-product-demo__motion-note">Motion-safe version: detail captured, work organized, next step assigned, follow-up ready.</p>
                            </aside>
                        </div>

                        <div class="fb-section fb-section--public" aria-label="What Everbranch does">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">What Everbranch does</p>
                                <h2>It gives daily work a home.</h2>
                                <p>Everbranch is for owners and teams who need a simple way to know what happened, what matters now, and what needs attention next.</p>
                            </div>
                            <div class="fb-grid fb-grid-4">
                                <article class="fb-card fb-card--public">
                                    <h3>Keep customer details together</h3>
                                    <p>See who called, bought, asked, requested work, or needs a follow-up before it gets forgotten.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>Track the work</h3>
                                    <p>Follow jobs, orders, batches, service calls, tasks, or visits with words that fit your business.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>Keep notes and messages close</h3>
                                    <p>Put the context next to the customer or job instead of leaving it scattered across the team.</p>
                                </article>
                                <article class="fb-card fb-card--public">
                                    <h3>See what needs attention</h3>
                                    <p>Know which customer, job, message, or task needs the next move.</p>
                                </article>
                            </div>
                        </div>

                        <div class="fb-section fb-section--public" aria-label="Industry fit">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Industry fit</p>
                                <h2>Built for the messy middle of small business.</h2>
                                <p>Whether you sell products, schedule jobs, manage crews, or coordinate projects, Everbranch gives your team one place for the details that usually live in texts, spreadsheets, notebooks, and memory.</p>
                            </div>
                            <div class="fb-industry-switcher" aria-label="Everbranch industry examples">
                                <details class="fb-industry-card" open>
                                    <summary>
                                        <span>Retail &amp; product brands</span>
                                        <strong>Customer notes, wholesale requests, inventory questions, event prep, reorders, and follow-ups.</strong>
                                    </summary>
                                    <div class="fb-industry-preview">
                                        <p>Capture the customer or buyer request, organize the order or event details, assign the next step, and keep follow-up from disappearing after the sale.</p>
                                        <div>
                                            <span>Wholesale request</span>
                                            <span>Event prep</span>
                                            <span>Reorder follow-up</span>
                                        </div>
                                    </div>
                                </details>
                                <details class="fb-industry-card">
                                    <summary>
                                        <span>Electrical &amp; plumbing</span>
                                        <strong>Job details, estimates, parts, customer messages, scheduling notes, and crew next steps.</strong>
                                    </summary>
                                    <div class="fb-industry-preview">
                                        <p>Keep the visit details, estimate context, parts notes, and next crew action together so the customer does not have to explain the same thing twice.</p>
                                        <div>
                                            <span>Job note</span>
                                            <span>Parts question</span>
                                            <span>Crew next step</span>
                                        </div>
                                    </div>
                                </details>
                                <details class="fb-industry-card">
                                    <summary>
                                        <span>Construction &amp; project work</span>
                                        <strong>Project notes, approvals, materials, change requests, subcontractor updates, and punch-list items.</strong>
                                    </summary>
                                    <div class="fb-industry-preview">
                                        <p>Put project context, approvals, material questions, and punch-list items where the team can see what changed and what needs a decision.</p>
                                        <div>
                                            <span>Approval needed</span>
                                            <span>Material note</span>
                                            <span>Punch-list item</span>
                                        </div>
                                    </div>
                                </details>
                                <details class="fb-industry-card">
                                    <summary>
                                        <span>Service businesses</span>
                                        <strong>Client records, appointments, recurring work, task handoffs, and follow-up reminders.</strong>
                                    </summary>
                                    <div class="fb-industry-preview">
                                        <p>Give client details, appointments, recurring work, and handoffs a single place so the next person knows what happened and what to do.</p>
                                        <div>
                                            <span>Client record</span>
                                            <span>Recurring work</span>
                                            <span>Reminder</span>
                                        </div>
                                    </div>
                                </details>
                            </div>
                            <p class="fb-industry-close">Everbranch does not replace the way your business works. It gives that work a home.</p>
                        </div>

                        <div class="fb-section fb-section--public" aria-label="Evergrove relationship">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">{{ $companyName }}</p>
                                <h2>Built by Evergrove Software.</h2>
                                <p>{{ $productName }} is built by {{ $companyName }} Software, a practical software company focused on tools for real operators, owner-led teams, and the work that needs a clearer home.</p>
                            </div>
                        </div>
                    </article>

                    <article id="panel-workflows" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-workflows" data-public-tab-panel="workflows" hidden>
                        <div class="fb-section fb-section--public" aria-label="Daily work">
                            <div class="fb-section-header">
                                <p class="fb-section-kicker">Daily work</p>
                                <h2>Start with the work that keeps slipping through the cracks.</h2>
                                <p>You do not have to set everything up at once. Start with customers, jobs, tasks, notes, messages, materials, or follow-ups, then add more when it makes sense.</p>
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
                                <p>Everbranch should make your business easier to see, not harder to trust. You choose what to bring in, who can see it, and what gets reviewed before anything important changes.</p>
                            </div>

                            <div class="fb-privacy-panel" data-premium-surface>
                                <div class="fb-privacy-panel__copy">
                                    <p class="fb-mini-kicker">Start with what you have</p>
                                    <h3>Use what you already have.</h3>
                                    <p>
                                        Start with Shopify, a spreadsheet, manual entry, or a guided setup. The goal is to stop
                                        retyping customer details, job notes, materials, and follow-ups in five different places.
                                    </p>
                                    <div class="fb-privacy-sources" aria-label="Supported starting points">
                                        <span>Shopify store</span>
                                        <span>Spreadsheet</span>
                                        <span>Manual entry</span>
                                        <span>Guided setup</span>
                                        <span>More connections later</span>
                                    </div>
                                </div>
                                <aside class="fb-privacy-control" aria-label="Privacy controls">
                                    <div class="fb-privacy-control__header">
                                        <span>Built around control</span>
                                        <strong>No all-or-nothing setup</strong>
                                    </div>
                                    <ol class="fb-privacy-steps">
                                        <li>
                                            <span>1</span>
                                            <div>
                                                <strong>You decide what comes in</strong>
                                                <p>Start small, then add more information when it is useful.</p>
                                            </div>
                                        </li>
                                        <li>
                                            <span>2</span>
                                            <div>
                                                <strong>Your team sees what they need</strong>
                                                <p>Access is based on the work someone actually does.</p>
                                            </div>
                                        </li>
                                        <li>
                                            <span>3</span>
                                            <div>
                                                <strong>Big changes are checked first</strong>
                                                <p>Important setup choices are reviewed before they affect the business.</p>
                                            </div>
                                        </li>
                                    </ol>
                                </aside>
                            </div>

                            <div class="fb-privacy-card-grid">
                                <article class="fb-privacy-card">
                                    <span>People</span>
                                    <h3>The right people see the right things</h3>
                                    <p>Your team gets access based on what they need to do, not everything by default.</p>
                                </article>
                                <article class="fb-privacy-card">
                                    <span>Setup</span>
                                    <h3>Setup is checked first</h3>
                                    <p>Important setup choices are reviewed before they change how your business runs.</p>
                                </article>
                                <article class="fb-privacy-card">
                                    <span>Payments</span>
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
