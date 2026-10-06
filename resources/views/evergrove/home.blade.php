@php
    $content = is_array($content ?? null) ? $content : [];
    $positioning = is_array($content['positioning'] ?? null) ? $content['positioning'] : [];
    $tools = is_array($tools ?? null) ? $tools : [];
    $businessSizes = is_array($content['business_sizes'] ?? null) ? $content['business_sizes'] : [];
    $timelines = is_array($content['timeline_options'] ?? null) ? $content['timeline_options'] : [];
    $budgetRanges = is_array($content['budget_ranges'] ?? null) ? $content['budget_ranges'] : [];
    $brandAssets = (array) ($content['brand_assets'] ?? []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eg3');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/evergrove-logo.png')).'?v='.$assetVersion;
    $everbranchAssets = (array) config('everbranch.brand_assets', []);
    $everbranchAssetVersion = (string) ($everbranchAssets['cache_tag'] ?? 'eb1');
    $everbranchLockup = asset((string) ($everbranchAssets['lockup'] ?? 'brand/everbranch-lockup.svg')).'?v='.$everbranchAssetVersion;
    $contactEmail = (string) ($content['contact_email'] ?? 'hello@evergrovesoftware.com');
    $appBaseUrl = rtrim((string) config('app.url', url('/')), '/');
    $loginUrl = $appBaseUrl.'/login';
    $everbranchStartUrl = config('tenancy.domains.canonical.scheme', 'https').'://'
        .config('tenancy.domains.canonical.public_host', 'theeverbranch.com').'/platform/start';
    $planComparison = (array) config('product_surfaces.start_client.plan_comparison', []);
    $comparePlans = is_array($planComparison['plans'] ?? null) ? $planComparison['plans'] : [];
    $compareFeatures = is_array($planComparison['features'] ?? null) ? $planComparison['features'] : [];
    $recommendedPlanKey = (string) ($planComparison['recommended'] ?? '');
    $partnerTerms = is_array($planComparison['partner_terms'] ?? null) ? $planComparison['partner_terms'] : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Evergrove Software',
        'title' => 'Evergrove Software | Custom software, websites, and automation',
        'description' => $positioning['summary'] ?? 'Evergrove builds custom websites, internal apps, portals, automation, and proven software products for owner-led businesses.',
        'brand_assets' => $brandAssets,
    ])
</head>
<body class="eg-public-body eg-public-body--launch" data-premium-motion="public">
    @include('platform.partials.premium-motion')
    @include('evergrove.partials.nav')

    <main>
        <section class="eg-hero eg-hero--product" aria-label="Evergrove Software">
            <div class="eg-hero-copy" data-reveal>
                <img src="{{ $lockup }}" alt="Evergrove Software" class="eg-hero-logo" />
                <p class="eg-kicker"><span></span>Custom software for real-world work</p>
                <h1>Software built around the way your business actually works.</h1>
                <p class="eg-lede">Evergrove designs and builds custom websites, internal apps, client portals, and automation for owner-led businesses ready to replace scattered tools with something that fits.</p>
                <div class="eg-actions">
                    <a href="#contact" class="eg-button eg-button-primary">Start a project</a>
                    <a href="#services" class="eg-button eg-button-secondary">What we build</a>
                </div>
                <div class="eg-hero-metrics" aria-label="Evergrove focus areas">
                    <div>
                        <strong>Websites</strong>
                        <span>built to move business</span>
                    </div>
                    <div>
                        <strong>Custom apps</strong>
                        <span>built around your team</span>
                    </div>
                    <div>
                        <strong>Automation</strong>
                        <span>less manual work</span>
                    </div>
                </div>
            </div>

            <div class="eg-phone-stage" data-depth="10" data-reveal>
                <div class="eg-orbit-note eg-orbit-note--one">Estimate approved</div>
                <div class="eg-orbit-note eg-orbit-note--two">Parts waiting</div>
                <div class="eg-phone-shell" data-public-phone-demo data-active-phone-tab="home" aria-label="Everbranch mobile app preview">
                    <div class="eg-phone-top">
                        <span>9:41</span>
                        <span></span>
                        <span>5G</span>
                    </div>
                    <div class="eg-phone-screen eg-phone-screen--everbranch">
                        <div class="eg-mobile-topbar">
                            <span class="eg-mobile-icon">≡</span>
                            <div class="eg-mobile-brand">
                                <img src="{{ $everbranchLockup }}" alt="Everbranch" />
                                <small>Apex Electrical</small>
                            </div>
                            <span class="eg-mobile-avatar">JC</span>
                        </div>

                        <div class="eg-mobile-scroll">
                            <section id="eg-phone-home" class="eg-mobile-panel is-active" data-phone-panel="home" role="tabpanel" aria-label="Everbranch home preview">
                                <div class="eg-mobile-heading">
                                    <div>
                                        <p>Today</p>
                                        <h2>Field work</h2>
                                    </div>
                                    <span>↻</span>
                                </div>

                                <section class="eg-mobile-hero-metric">
                                    <strong>12</strong>
                                    <span>Jobs moving today</span>
                                </section>

                                <section class="eg-mobile-metrics" aria-label="Everbranch mobile summary">
                                    <div>
                                        <span>Open quotes</span>
                                        <strong>7</strong>
                                        <small>3 need a call</small>
                                    </div>
                                    <div>
                                        <span>Customers</span>
                                        <strong>184</strong>
                                        <small>12 active this month</small>
                                    </div>
                                    <div>
                                        <span>Messages</span>
                                        <strong>9</strong>
                                        <small>2 unread</small>
                                    </div>
                                    <div>
                                        <span>Updates</span>
                                        <strong>31</strong>
                                        <small>last 30 days</small>
                                    </div>
                                </section>

                                <div class="eg-mobile-section-title">
                                    <h3>Workspace pulse</h3>
                                </div>
                                <section class="eg-mobile-pulse">
                                    <div><strong>6</strong><span>Team members</span></div>
                                    <div><strong>4</strong><span>Active users</span></div>
                                    <div><strong>5</strong><span>Active Branches</span></div>
                                    <div><strong>31</strong><span>Work updates</span></div>
                                </section>
                            </section>

                            <section id="eg-phone-work" class="eg-mobile-panel" data-phone-panel="work" role="tabpanel" aria-label="Everbranch work preview" hidden>
                                <div class="eg-mobile-heading">
                                    <div>
                                        <p>Work</p>
                                        <h2>Job board</h2>
                                    </div>
                                    <span>✓</span>
                                </div>
                                <div class="eg-mobile-job-grid">
                                    <article class="eg-mobile-work-card">
                                        <span>In process</span>
                                        <strong>Panel upgrade</strong>
                                        <p>$6,840 scheduled today</p>
                                    </article>
                                    <article class="eg-mobile-work-card">
                                        <span>Quoting</span>
                                        <strong>Lighting package</strong>
                                        <p>$4,200 waiting on approval</p>
                                    </article>
                                    <article class="eg-mobile-work-card">
                                        <span>Contract signed</span>
                                        <strong>Maintenance plan</strong>
                                        <p>$9,600 monthly service</p>
                                    </article>
                                    <article class="eg-mobile-work-card">
                                        <span>Finished</span>
                                        <strong>Breaker replacement</strong>
                                        <p>$1,180 invoice ready</p>
                                    </article>
                                </div>
                                <div class="eg-mobile-action-row">
                                    <span>1</span>
                                    <p>Message customer</p>
                                </div>
                                <div class="eg-mobile-action-row">
                                    <span>2</span>
                                    <p>Mark job complete</p>
                                </div>
                                <div class="eg-mobile-complete">
                                    <span>✓</span>
                                    <strong>Job complete</strong>
                                </div>
                            </section>

                            <section id="eg-phone-branches" class="eg-mobile-panel" data-phone-panel="branches" role="tabpanel" aria-label="Everbranch branches preview" hidden>
                                <div class="eg-mobile-heading">
                                    <div>
                                        <p>Branches</p>
                                        <h2>Growth tools</h2>
                                    </div>
                                    <span class="phone-tree-icon" aria-hidden="true"></span>
                                </div>
                                <div class="eg-mobile-finance-grid">
                                    <article>
                                        <span>Supplies used this month</span>
                                        <strong>$3,842.19</strong>
                                        <p>tracked from completed work</p>
                                    </article>
                                    <article>
                                        <span>Employee spend</span>
                                        <strong>$12,960.00</strong>
                                        <p>28% of gross revenue</p>
                                    </article>
                                </div>
                                <div class="eg-mobile-branch-grid">
                                    <article>
                                        <span>Rewards</span>
                                        <strong>1,250</strong>
                                        <p>points issued</p>
                                    </article>
                                    <article>
                                        <span>Birthday</span>
                                        <strong>24</strong>
                                        <p>offers queued</p>
                                    </article>
                                    <article>
                                        <span>Marketing</span>
                                        <strong>$4.2k</strong>
                                        <p>influenced</p>
                                    </article>
                                    <article>
                                        <span>Reviews</span>
                                        <strong>8</strong>
                                        <p>asks ready</p>
                                    </article>
                                    <article>
                                        <span>Supplies</span>
                                        <strong>42</strong>
                                        <p>items logged</p>
                                    </article>
                                    <article>
                                        <span>Employees</span>
                                        <strong>28%</strong>
                                        <p>labor ratio</p>
                                    </article>
                                    <article>
                                        <span>Invoices</span>
                                        <strong>$7.8k</strong>
                                        <p>ready to send</p>
                                    </article>
                                    <article>
                                        <span>Follow-ups</span>
                                        <strong>13</strong>
                                        <p>next best calls</p>
                                    </article>
                                </div>
                                <div class="eg-mobile-branches">
                                    <span class="phone-tree-icon phone-tree-icon--inverted" aria-hidden="true"></span>
                                    <div>
                                        <strong>Branches</strong>
                                        <small>Close analogs of add-on growth surfaces</small>
                                    </div>
                                </div>
                            </section>

                            <section id="eg-phone-account" class="eg-mobile-panel" data-phone-panel="account" role="tabpanel" aria-label="Everbranch account preview" hidden>
                                <div class="eg-mobile-heading">
                                    <div>
                                        <p>Account</p>
                                        <h2>Apex Electrical</h2>
                                    </div>
                                    <span>◎</span>
                                </div>
                                <div class="eg-mobile-account-card">
                                    <span>Plan</span>
                                    <strong>Launch Partner</strong>
                                    <p>Core app live. Rewards, birthday, and marketing branches ready.</p>
                                </div>
                                <div class="eg-mobile-setting-row">
                                    <span>Job-complete text</span>
                                    <strong>On</strong>
                                </div>
                                <div class="eg-mobile-setting-row">
                                    <span>Birthday campaigns</span>
                                    <strong>On</strong>
                                </div>
                                <div class="eg-mobile-setting-row">
                                    <span>Team seats</span>
                                    <strong>6</strong>
                                </div>
                                <div class="eg-mobile-setting-row">
                                    <span>Supplies tracking</span>
                                    <strong>On</strong>
                                </div>
                                <div class="eg-mobile-setting-row">
                                    <span>Review requests</span>
                                    <strong>Auto</strong>
                                </div>
                            </section>
                        </div>

                        <div class="eg-mobile-tabbar" role="tablist" aria-label="Everbranch phone preview tabs">
                            <button type="button" class="is-active" data-phone-tab="home" role="tab" aria-selected="true" aria-controls="eg-phone-home">
                                <span aria-hidden="true">⌂</span>
                                Home
                            </button>
                            <button type="button" data-phone-tab="work" role="tab" aria-selected="false" aria-controls="eg-phone-work" tabindex="-1">
                                <span aria-hidden="true">▤</span>
                                Work
                            </button>
                            <button type="button" data-phone-tab="branches" role="tab" aria-selected="false" aria-controls="eg-phone-branches" tabindex="-1">
                                <span class="phone-tree-icon" aria-hidden="true"></span>
                                Branches
                            </button>
                            <button type="button" data-phone-tab="account" role="tab" aria-selected="false" aria-controls="eg-phone-account" tabindex="-1">
                                <span aria-hidden="true">◎</span>
                                Account
                            </button>
                        </div>
                    </div>
                </div>
                <div class="eg-floating-panel" data-premium-surface>
                    <strong>Built by Evergrove</strong>
                    <span>Everbranch: jobs, notes, customers, follow-ups, approvals</span>
                </div>
            </div>
        </section>

        <section id="problem" class="eg-proof-strip eg-proof-strip--tight" aria-label="Evergrove positioning">
            <div>
                <span>Built for business</span>
                <p>clear enough to use every day</p>
            </div>
            <div>
                <span>Built around people</span>
                <p>not a process someone else invented</p>
            </div>
            <div>
                <span>Built to last</span>
                <p>custom where it matters, simple where it should be</p>
            </div>
        </section>

        <section id="services" class="eg-section eg-section--compact">
            <div class="eg-section-head" data-reveal>
                <p class="eg-kicker">What we build</p>
                <h2>Digital tools with a job to do.</h2>
                <p>Start with the business problem, then build the right website, workflow, or product around it.</p>
            </div>
            <div class="eg-card-grid eg-card-grid-3 eg-outcome-grid">
                <article class="eg-card" data-premium-surface data-reveal>
                    <span class="eg-card-number">01</span>
                    <h3>Websites that create momentum.</h3>
                    <p>Clear, credible sites and storefronts that make it easier for the right customer to understand, trust, and contact you.</p>
                </article>
                <article class="eg-card" data-premium-surface data-reveal>
                    <span class="eg-card-number">02</span>
                    <h3>Operations that stop living in messages.</h3>
                    <p>Internal apps and client portals that put the customer, work, approvals, and next step where the team can find them.</p>
                </article>
                <article class="eg-card" data-premium-surface data-reveal>
                    <span class="eg-card-number">03</span>
                    <h3>Systems that talk to each other.</h3>
                    <p>Useful integrations and focused automation that remove repeated admin without taking people out of the loop.</p>
                </article>
            </div>
        </section>

        <section id="everbranch" class="eg-section eg-product-section">
            <div class="eg-product-bridge eg-product-bridge--premium" data-premium-surface data-reveal>
                <div>
                    <p class="eg-kicker">Built by Evergrove: Everbranch</p>
                    <h2>A real operating app, not a mockup.</h2>
                    <p>Everbranch is software we built for teams that need customers, jobs, notes, approvals, and follow-ups in one place. It is a fit for some businesses; for others, it shows the kind of focused product work Evergrove can create.</p>
                </div>
                <div class="eg-actions">
                    <a href="{{ $everbranchStartUrl }}" class="eg-button eg-button-primary">Explore Everbranch</a>
                    <a href="#contact" class="eg-button eg-button-secondary">Build something custom</a>
                </div>
            </div>
        </section>

        @if($comparePlans !== [] && $compareFeatures !== [])
            <section id="pricing" class="eg-section eg-section--compact eg-pricing-section">
                <div class="eg-section-head" data-reveal>
                    <p class="eg-kicker">{{ $planComparison['eyebrow'] ?? 'Launch partner pricing' }}</p>
                    <h2>{{ $planComparison['title'] ?? 'Launch partner pricing' }}</h2>
                    <p>{{ $planComparison['subtitle'] ?? 'Starter includes everything. Growth gives you more capacity.' }}</p>
                </div>
                <div class="eg-pricing-grid" data-reveal>
                    @foreach($comparePlans as $planKey => $plan)
                        @php $isRecommended = (string) $planKey === $recommendedPlanKey; @endphp
                        <article class="eg-pricing-card {{ $isRecommended ? 'is-featured' : '' }}" data-premium-surface>
                            @if(filled($plan['badge'] ?? null))
                                <span class="eg-pricing-badge">{{ $plan['badge'] }}</span>
                            @endif
                            <p>{{ $plan['descriptor'] ?? '' }}</p>
                            <h3>{{ $plan['label'] ?? $planKey }}</h3>
                            <strong>{{ $plan['price'] ?? '' }}<span>{{ $plan['cadence'] ?? '' }}</span></strong>
                            <ul>
                                @foreach($compareFeatures as $feature)
                                    @if(filled($feature[$planKey] ?? null))
                                        <li>
                                            <span>{{ $feature['label'] ?? '' }}</span>
                                            <b>{{ $feature[$planKey] }}</b>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>
                @if(filled($planComparison['savings_note'] ?? null) || $partnerTerms !== [])
                    <div class="eg-partner-note" data-premium-surface data-reveal>
                        <div>
                            <strong>{{ $planComparison['savings_note'] ?? 'Launch partner pricing is limited.' }}</strong>
                            <span>Designed for the first businesses helping shape Everbranch in the field.</span>
                        </div>
                        @if($partnerTerms !== [])
                            <ul>
                                @foreach($partnerTerms as $term)
                                    <li>{{ $term }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </section>
        @endif

        <section id="examples" class="eg-section eg-section--compact">
            <div class="eg-section-head" data-reveal>
                <p class="eg-kicker">Where custom work helps</p>
                <h2>The software starts where the work breaks.</h2>
            </div>
            <div class="eg-industry-showcase eg-fix-showcase" aria-label="Workflow problems Evergrove can fix">
                <details class="eg-industry-card" data-clickable-details-card open data-reveal>
                    <summary>
                        <span>Job notes live in texts</span>
                        <strong>Move field notes, photos, customer context, and decisions into one job timeline.</strong>
                    </summary>
                    <p>Give the office and the person doing the work one shared view, without asking either of them to rebuild the same information.</p>
                </details>
                <details class="eg-industry-card" data-clickable-details-card data-reveal>
                    <summary>
                        <span>Quotes need babysitting</span>
                        <strong>Track open estimates, customer replies, approvals, and next follow-ups without rebuilding a spreadsheet.</strong>
                    </summary>
                    <p>A lightweight workflow can surface the estimate that needs attention today instead of hoping someone remembers.</p>
                </details>
                <details class="eg-industry-card" data-clickable-details-card data-reveal>
                    <summary>
                        <span>Materials slow the crew down</span>
                        <strong>Keep parts requests, job requirements, and status visible before the truck rolls.</strong>
                    </summary>
                    <p>The goal is not more admin. It is fewer surprise gaps, cleaner handoffs, and a team that can keep work moving.</p>
                </details>
            </div>
        </section>

        <section id="work" class="eg-section eg-section--compact eg-studio-section">
            <div class="eg-split eg-studio-split">
                <div class="eg-section-head" data-reveal>
                    <p class="eg-kicker">Evergrove Studio</p>
                    <h2>From practical website to working product.</h2>
                    <p>We can start with a focused website, a better handoff, or a full internal tool. Everbranch is our proof that we can take a messy operational need all the way to a usable product.</p>
                </div>
                <div class="eg-mini-tools" data-reveal>
                    @foreach($tools as $key => $tool)
                        @php
                            $routeName = match ((string) $key) {
                                'ai_roi' => 'evergrove.tools.ai-roi',
                                'automation_savings' => 'evergrove.tools.automation-savings',
                                default => 'evergrove.tools.project-estimate',
                            };
                        @endphp
                        <a href="{{ route($routeName) }}" class="eg-mini-tool" data-premium-surface>
                            <span>{{ $tool['title'] ?? 'Planning tool' }}</span>
                            <strong>Open</strong>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="contact" class="eg-section eg-section--compact eg-contact-section">
            <div class="eg-contact-layout">
                <div class="eg-section-head" data-reveal>
                    <p class="eg-kicker">Start a project</p>
                    <h2>Bring the messy version.</h2>
                    <p>Tell me what gets missed, repeated, delayed, or retyped. We will find the smallest useful place to start, whether that is a website, a custom app, an integration, or Everbranch.</p>
                    <p>Email: <a class="eg-text-link" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></p>
                </div>

                <form method="POST" action="{{ route('evergrove.inquiries.store') }}" class="eg-form-card" data-premium-surface data-reveal>
                    @csrf
                    <input type="hidden" name="source_page" value="evergrove_contact" />

                    @if (session('status'))
                        <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
                    @endif

                    <div class="eg-form-grid">
                        <label>
                            Name
                            <input name="name" type="text" value="{{ old('name') }}" required class="fb-input" />
                            @error('name') <span>{{ $message }}</span> @enderror
                        </label>
                        <label>
                            Email
                            <input name="email" type="email" value="{{ old('email') }}" required class="fb-input" />
                            @error('email') <span>{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <div class="eg-form-grid">
                        <label>
                            Company
                            <input name="company" type="text" value="{{ old('company') }}" class="fb-input" />
                        </label>
                        <label>
                            Website
                            <input name="website" type="url" value="{{ old('website') }}" class="fb-input" placeholder="https://example.com" />
                            @error('website') <span>{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <div class="eg-form-grid eg-form-grid-3">
                        <label>
                            Business size
                            <select name="business_size" class="fb-input">
                                <option value="">Select one</option>
                                @foreach($businessSizes as $key => $label)
                                    <option value="{{ $key }}" @selected(old('business_size') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            Timeline
                            <select name="timeline" class="fb-input">
                                <option value="">Select one</option>
                                @foreach($timelines as $key => $label)
                                    <option value="{{ $key }}" @selected(old('timeline') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            Budget range
                            <select name="budget_range" class="fb-input">
                                <option value="">Select one</option>
                                @foreach($budgetRanges as $key => $label)
                                    <option value="{{ $key }}" @selected(old('budget_range') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <label>
                        What should be easier?
                        <textarea name="pain_point" rows="5" class="fb-input" placeholder="Example: quotes fall through the cracks, job notes live in texts, parts are hard to track...">{{ old('pain_point') }}</textarea>
                        @error('pain_point') <span>{{ $message }}</span> @enderror
                    </label>

                    <button type="submit" class="eg-button eg-button-primary">Send workflow notes</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
