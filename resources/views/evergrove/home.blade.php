@php
    $content = is_array($content ?? null) ? $content : [];
    $positioning = is_array($content['positioning'] ?? null) ? $content['positioning'] : [];
    $tools = is_array($tools ?? null) ? $tools : [];
    $brandAssets = (array) ($content['brand_assets'] ?? []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eg3');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/evergrove-logo.png')).'?v='.$assetVersion;
    $contactEmail = (string) ($content['contact_email'] ?? 'hello@evergrovesoftware.com');
    $appBaseUrl = rtrim((string) config('app.url', url('/')), '/');
    $loginUrl = $appBaseUrl.'/login';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Evergrove Software',
        'title' => 'Evergrove Software | Practical systems for owner-led businesses',
        'description' => 'Evergrove builds practical apps, portals, automations, and products for small businesses that need the work to be clearer.',
        'brand_assets' => $brandAssets,
    ])
</head>
<body class="eg-public-body eg-public-body--showcase">
    @include('evergrove.partials.nav')

    <main>
        <section class="eg-showcase-hero" aria-label="Evergrove services">
            <div class="eg-showcase-hero__copy">
                <img src="{{ $lockup }}" alt="Evergrove Software" class="eg-hero-logo" />
                <p class="eg-kicker">Parent software studio</p>
                <h1>{{ $positioning['headline'] ?? 'We build the software small businesses wish already existed.' }}</h1>
                <p class="eg-lede">Evergrove turns scattered work into practical apps, portals, automations, and products your team can actually use.</p>
                <div class="eg-actions">
                    <a href="{{ route('evergrove.contact') }}" class="eg-button eg-button-primary">Start with a workflow audit</a>
                    <a href="#eg-story" class="eg-button eg-button-secondary">See what we build</a>
                    <a href="{{ $loginUrl }}" class="eg-button eg-button-secondary">Client portal</a>
                </div>
            </div>

            <aside class="eg-live-board" aria-label="Evergrove software example">
                <div class="eg-live-board__top">
                    <span>Studio workflow</span>
                    <strong>From messy process to working app</strong>
                </div>
                <div class="eg-live-board__grid">
                    <div class="eg-work-note eg-work-note--mist">Customer notes</div>
                    <div class="eg-work-note eg-work-note--amber">Job photos</div>
                    <div class="eg-work-note eg-work-note--coral">Follow-up gaps</div>
                    <div class="eg-work-note eg-work-note--moss">Material questions</div>
                </div>
                <div class="eg-live-board__app">
                    <span>Evergrove build</span>
                    <strong>Customer + work + next step dashboard</strong>
                    <p>Practical software shaped around the way the business already works.</p>
                </div>
            </aside>
        </section>

        <section id="eg-story" class="eg-story-shell" data-public-tabs aria-label="Evergrove story tabs">
            <div class="eg-story-tabs" role="tablist" aria-label="Evergrove overview">
                <a id="eg-tab-problem" href="#eg-story" class="is-active" role="tab" aria-selected="true" aria-controls="eg-panel-problem" data-public-tab-trigger="problem">Problem</a>
                <a id="eg-tab-build" href="#eg-story" role="tab" aria-selected="false" aria-controls="eg-panel-build" data-public-tab-trigger="build">What We Build</a>
                <a id="eg-tab-plan" href="#eg-story" role="tab" aria-selected="false" aria-controls="eg-panel-plan" data-public-tab-trigger="plan">How It Works</a>
                <a id="eg-tab-examples" href="#eg-story" role="tab" aria-selected="false" aria-controls="eg-panel-examples" data-public-tab-trigger="examples">Examples</a>
                <a id="eg-tab-contact" href="#eg-story" role="tab" aria-selected="false" aria-controls="eg-panel-contact" data-public-tab-trigger="contact">Contact</a>
            </div>

            <div class="eg-story-panels">
                <article id="eg-panel-problem" class="eg-story-panel is-active" role="tabpanel" aria-labelledby="eg-tab-problem" data-public-tab-panel="problem">
                    <div class="eg-story-copy">
                        <p class="eg-kicker">The Problem</p>
                        <h2>The work is real. The system is duct tape.</h2>
                        <p>Most owner-led businesses are not short on effort. They are short on one clear place for customers, jobs, decisions, files, follow-ups, and money.</p>
                    </div>
                    <div class="eg-problem-visual" aria-label="Scattered business work">
                        <span>Texts</span>
                        <span>Spreadsheets</span>
                        <span>Notebooks</span>
                        <span>Photos</span>
                        <span>Invoices</span>
                        <strong>Owner memory</strong>
                    </div>
                </article>

                <article id="eg-panel-build" class="eg-story-panel" role="tabpanel" aria-labelledby="eg-tab-build" data-public-tab-panel="build" hidden>
                    <div class="eg-story-copy">
                        <p class="eg-kicker">What We Build</p>
                        <h2>Practical software, not another pile of tools.</h2>
                        <p>Evergrove builds the right-sized system: a portal, internal app, workflow dashboard, automation, Shopify connector, or product lane.</p>
                    </div>
                    <div class="eg-build-visual" data-evergrove-visual-demo aria-label="Software Evergrove builds">
                        <article><span>01</span><strong>Customer systems</strong><p>Keep people, notes, requests, and follow-ups together.</p></article>
                        <article><span>02</span><strong>Work dashboards</strong><p>See jobs, materials, tasks, photos, and handoffs.</p></article>
                        <article><span>03</span><strong>Portals</strong><p>Give customers or teams one clean place to act.</p></article>
                        <article><span>04</span><strong>Automations</strong><p>Move repeated admin out of the owner's head.</p></article>
                    </div>
                </article>

                <article id="eg-panel-plan" class="eg-story-panel" role="tabpanel" aria-labelledby="eg-tab-plan" data-public-tab-panel="plan" hidden>
                    <div class="eg-story-copy">
                        <p class="eg-kicker">How It Works</p>
                        <h2>A short path before anyone builds the wrong thing.</h2>
                        <p>Start with the messy workflow. Leave with the clearest next system.</p>
                    </div>
                    <ol class="eg-plan-visual" aria-label="Evergrove workflow audit plan">
                        <li><span>Map</span><strong>Show us the messy version</strong><p>Texts, spreadsheets, photos, tools, and repeated admin.</p></li>
                        <li><span>Shape</span><strong>Choose the useful system</strong><p>App, portal, automation, product, or simpler process.</p></li>
                        <li><span>Build</span><strong>Launch the first working version</strong><p>Small enough to use. Clear enough to improve.</p></li>
                    </ol>
                </article>

                <article id="eg-panel-examples" class="eg-story-panel" role="tabpanel" aria-labelledby="eg-tab-examples" data-public-tab-panel="examples" hidden>
                    <div class="eg-story-copy">
                        <p class="eg-kicker">Examples</p>
                        <h2>Pick the problem you keep explaining twice.</h2>
                        <p>These are starting points for a workflow audit, not boxed promises.</p>
                    </div>
                    <div class="eg-example-switcher" aria-label="Evergrove example systems">
                        <details data-clickable-details-card open>
                            <summary><span>Retail &amp; product brands</span><strong>Wholesale portals, customer systems, inventory questions, and event workflows.</strong></summary>
                            <p>Modern Forestry is the proof ground: retail, wholesale, inventory, approvals, and operations living closer together.</p>
                        </details>
                        <details data-clickable-details-card>
                            <summary><span>Trades &amp; field teams</span><strong>Job dashboards, estimate tracking, crew steps, parts requests, and customer updates.</strong></summary>
                            <p>Useful for electricians, plumbers, service teams, and crews who need job context without digging through texts.</p>
                        </details>
                        <details data-clickable-details-card>
                            <summary><span>Construction &amp; project teams</span><strong>Approvals, documents, materials, change requests, punch lists, and portals.</strong></summary>
                            <p>Give each project a visible home for decisions, tasks, and what changed.</p>
                        </details>
                        <details data-clickable-details-card>
                            <summary><span>Custom operations</span><strong>When off-the-shelf software almost fits, Evergrove can build the missing middle.</strong></summary>
                            <p>The best build is often small: one workflow made clear enough for the whole team to use.</p>
                        </details>
                    </div>
                </article>

                <article id="eg-panel-contact" class="eg-story-panel" role="tabpanel" aria-labelledby="eg-tab-contact" data-public-tab-panel="contact" hidden>
                    <div class="eg-story-copy">
                        <p class="eg-kicker">Contact</p>
                        <h2>Start with a workflow audit.</h2>
                        <p>Bring the problem you keep patching by hand. We will help decide what should become software.</p>
                    </div>
                    <div class="eg-contact-mini">
                        <a href="{{ route('evergrove.contact') }}" class="eg-button eg-button-primary">Start with a workflow audit</a>
                        <a href="mailto:{{ $contactEmail }}" class="eg-button eg-button-secondary">{{ $contactEmail }}</a>
                    </div>
                </article>
            </div>
        </section>

        <section id="eg-tools" class="eg-tool-strip" aria-label="Choose your problem">
            <div>
                <p class="eg-kicker">Quick planning tools</p>
                <h2>Choose the problem, then estimate the shape.</h2>
            </div>
            <div class="eg-tool-buttons">
                @foreach($tools as $key => $tool)
                    @php
                        $routeName = match ((string) $key) {
                            'ai_roi' => 'evergrove.tools.ai-roi',
                            'automation_savings' => 'evergrove.tools.automation-savings',
                            default => 'evergrove.tools.project-estimate',
                        };
                    @endphp
                    <a href="{{ route($routeName) }}">
                        <span>{{ $tool['title'] ?? 'Calculator' }}</span>
                        <small>{{ $tool['summary'] ?? 'Open planning tool' }}</small>
                    </a>
                @endforeach
            </div>
        </section>

        <section id="everbranch" class="eg-product-bridge eg-product-bridge--showcase" aria-label="Everbranch relationship">
            <div>
                <p class="eg-kicker">Everbranch</p>
                <h2>Everbranch is one product created by Evergrove.</h2>
                <p>Everbranch is the small-business operating app for customers, tasks, notes, follow-ups, messages, and daily work.</p>
            </div>
            <div class="eg-actions">
                <a href="{{ route('platform.promo') }}" class="eg-button eg-button-secondary">See Everbranch</a>
                <a href="{{ route('evergrove.contact') }}" class="eg-button eg-button-primary">Talk through a system</a>
            </div>
        </section>
    </main>
</body>
</html>
