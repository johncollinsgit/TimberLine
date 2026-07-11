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
        'title' => 'Evergrove Software | Everbranch for owner-led teams',
        'description' => $positioning['summary'] ?? 'Evergrove builds Everbranch and custom software for trades, field teams, retail teams, and owner-led businesses.',
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
                <p class="eg-kicker"><span></span>Everbranch for real-world work</p>
                <h1>The app that keeps the job moving.</h1>
                <p class="eg-lede">Evergrove builds clean, custom software for owner-led teams: electricians, service shops, project crews, and product businesses that are tired of running the company from texts and spreadsheets.</p>
                <div class="eg-actions">
                    <a href="#contact" class="eg-button eg-button-primary">Get a workflow audit</a>
                    <a href="#everbranch" class="eg-button eg-button-secondary">See Everbranch</a>
                </div>
                <div class="eg-hero-metrics" aria-label="Evergrove focus areas">
                    <div>
                        <strong>Jobs</strong>
                        <span>next step visible</span>
                    </div>
                    <div>
                        <strong>Customers</strong>
                        <span>history in one place</span>
                    </div>
                    <div>
                        <strong>Follow-ups</strong>
                        <span>nothing drifts</span>
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
                                <div class="eg-mobile-work-card">
                                    <span>In progress</span>
                                    <strong>Panel upgrade</strong>
                                    <p>Customer message sent. Photos attached. Invoice ready.</p>
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
                                    <span>✦</span>
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
                                </div>
                                <div class="eg-mobile-branches">
                                    <span>✦</span>
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
                                <span aria-hidden="true">✦</span>
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
                    <strong>Now in one place</strong>
                    <span>jobs, notes, customers, follow-ups, approvals</span>
                </div>
            </div>
        </section>

        <section id="problem" class="eg-proof-strip eg-proof-strip--tight" aria-label="Evergrove positioning">
            <div>
                <span>Built for owners</span>
                <p>clear enough to trust between calls</p>
            </div>
            <div>
                <span>Made for crews</span>
                <p>fast enough to use from the field</p>
            </div>
            <div>
                <span>Designed to grow</span>
                <p>custom where it matters, simple where it should be</p>
            </div>
        </section>

        <section id="services" class="eg-section eg-section--compact">
            <div class="eg-section-head" data-reveal>
                <p class="eg-kicker">What changes</p>
                <h2>Less hunting. More doing.</h2>
                <p>Evergrove turns the daily friction into a focused app your team can actually use.</p>
            </div>
            <div class="eg-card-grid eg-card-grid-3 eg-outcome-grid">
                <article class="eg-card" data-premium-surface data-reveal>
                    <span class="eg-card-number">01</span>
                    <h3>Every job has a next step.</h3>
                    <p>See the customer, status, notes, materials, quote, and follow-up without digging through messages.</p>
                </article>
                <article class="eg-card" data-premium-surface data-reveal>
                    <span class="eg-card-number">02</span>
                    <h3>The owner gets the real picture.</h3>
                    <p>Know what is waiting, what changed, who needs a call, and what can move today.</p>
                </article>
                <article class="eg-card" data-premium-surface data-reveal>
                    <span class="eg-card-number">03</span>
                    <h3>The system fits the business.</h3>
                    <p>Start with Everbranch, then shape the workflows around how your team already works.</p>
                </article>
            </div>
        </section>

        <section id="everbranch" class="eg-section eg-product-section">
            <div class="eg-product-bridge eg-product-bridge--premium" data-premium-surface data-reveal>
                <div>
                    <p class="eg-kicker">Everbranch</p>
                    <h2>A small-business operating app, built by Evergrove.</h2>
                    <p>Customers, jobs, requests, notes, approvals, follow-ups, and the work nobody wants to lose. Clean enough for the office, simple enough for the truck.</p>
                </div>
                <div class="eg-actions">
                    <a href="{{ $everbranchStartUrl }}" class="eg-button eg-button-primary">Become a launch partner</a>
                    <a href="{{ $loginUrl }}" class="eg-button eg-button-secondary">Client portal</a>
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
                <p class="eg-kicker">Click the mess</p>
                <h2>The software starts where the work breaks.</h2>
            </div>
            <div class="eg-industry-showcase eg-fix-showcase" aria-label="Workflow problems Evergrove can fix">
                <details class="eg-industry-card" data-clickable-details-card open data-reveal>
                    <summary>
                        <span>Job notes live in texts</span>
                        <strong>Move field notes, photos, customer context, and decisions into one job timeline.</strong>
                    </summary>
                    <p>For an electrician, that means the person answering the phone and the person walking into the house can see the same truth.</p>
                </details>
                <details class="eg-industry-card" data-clickable-details-card data-reveal>
                    <summary>
                        <span>Quotes need babysitting</span>
                        <strong>Track open estimates, customer replies, approvals, and next follow-ups without rebuilding a spreadsheet.</strong>
                    </summary>
                    <p>Everbranch can surface the quote that needs a call today instead of hoping someone remembers.</p>
                </details>
                <details class="eg-industry-card" data-clickable-details-card data-reveal>
                    <summary>
                        <span>Materials slow the crew down</span>
                        <strong>Keep parts requests, job requirements, and status visible before the truck rolls.</strong>
                    </summary>
                    <p>The goal is not more admin. It is fewer wasted trips, fewer surprise gaps, and cleaner handoffs.</p>
                </details>
            </div>
        </section>

        <section id="work" class="eg-section eg-section--compact eg-studio-section">
            <div class="eg-split eg-studio-split">
                <div class="eg-section-head" data-reveal>
                    <p class="eg-kicker">Evergrove Studio</p>
                    <h2>Product taste plus practical build work.</h2>
                    <p>Use Everbranch when the product fits. Build custom when your process is the advantage. Either way, the goal is software that feels obvious after the first week.</p>
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
                    <p class="eg-kicker">Workflow audit</p>
                    <h2>Bring the messy version.</h2>
                    <p>Tell me what gets missed, repeated, delayed, or retyped. I’ll help decide whether Everbranch, a custom app, or a simpler process is the right next move.</p>
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
