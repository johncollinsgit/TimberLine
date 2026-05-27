@php
    $content = is_array($content ?? null) ? $content : [];
    $positioning = is_array($content['positioning'] ?? null) ? $content['positioning'] : [];
    $services = is_array($content['services'] ?? null) ? $content['services'] : [];
    $tools = is_array($tools ?? null) ? $tools : [];
    $businessSizes = is_array($content['business_sizes'] ?? null) ? $content['business_sizes'] : [];
    $timelines = is_array($content['timeline_options'] ?? null) ? $content['timeline_options'] : [];
    $budgetRanges = is_array($content['budget_ranges'] ?? null) ? $content['budget_ranges'] : [];
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
        'title' => 'Evergrove Software | AI Systems and Custom Software',
        'description' => $positioning['summary'] ?? 'Evergrove builds practical AI systems and custom software for small and medium businesses.',
        'brand_assets' => $brandAssets,
    ])
</head>
<body class="eg-public-body">
    @include('evergrove.partials.nav')

    <main>
        <section class="eg-hero" aria-label="Evergrove services">
            <div class="eg-hero-copy">
                <img src="{{ $lockup }}" alt="Evergrove Software" class="eg-hero-logo" />
                <p class="eg-kicker">{{ $positioning['eyebrow'] ?? 'AI systems and custom software' }}</p>
                <h1>AI systems and custom software for small and medium businesses.</h1>
                <p class="eg-lede">Turn scattered operations into useful software. Evergrove plans, builds, and maintains practical AI workflows, Laravel applications, customer portals, and business systems that are designed around how your company actually works.</p>
                <div class="eg-actions">
                    <a href="#contact" class="eg-button eg-button-primary">Start a project</a>
                    <a href="#tools" class="eg-button eg-button-secondary">Use the calculators</a>
                    <a href="{{ $loginUrl }}" class="eg-button eg-button-secondary">Client portal</a>
                </div>
            </div>

            <aside class="eg-portal-preview" aria-label="Client portal preview">
                <div class="eg-preview-head">
                    <div>
                        <p class="eg-preview-label">Client portal</p>
                        <h2>Progress, scope, and requests in one place.</h2>
                    </div>
                    <span>On track</span>
                </div>
                <div class="eg-preview-grid">
                    <div>
                        <p>Phase</p>
                        <strong>Build</strong>
                    </div>
                    <div>
                        <p>Next milestone</p>
                        <strong>Homepage review</strong>
                    </div>
                    <div>
                        <p>Open requests</p>
                        <strong>3</strong>
                    </div>
                </div>
                <div class="eg-request-stack">
                    <article>
                        <span>Feature request</span>
                        <strong>Quote calculator refinements</strong>
                        <p>Scope, tasks, reference links, and client decisions stay attached to the project.</p>
                    </article>
                    <article>
                        <span>Project task</span>
                        <strong>Connect inquiry payload</strong>
                        <p>Evergrove and the customer can see what is waiting, what is approved, and what changed.</p>
                    </article>
                </div>
            </aside>
        </section>

        <section class="eg-proof-strip" aria-label="Evergrove proof points">
            <div>
                <span>15 years</span>
                <p>technical proficiency and operating experience</p>
            </div>
            <div>
                <span>Modern Forestry</span>
                <p>real business systems, inventory, orders, customers, and automation</p>
            </div>
            <div>
                <span>Laravel + AI</span>
                <p>custom portals, workflow tools, integrations, and practical agents</p>
            </div>
        </section>

        <section id="services" class="eg-section">
            <div class="eg-section-head">
                <p class="eg-kicker">Services</p>
                <h2>Clean software for the work your business repeats every week.</h2>
                <p>Evergrove starts with the operational pain, then chooses the smallest useful system that can improve the week.</p>
            </div>
            <div class="eg-card-grid eg-card-grid-4">
                @foreach($services as $service)
                    <article class="eg-card">
                        <h3>{{ $service['title'] ?? 'Service' }}</h3>
                        <p>{{ $service['summary'] ?? '' }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section id="work" class="eg-section eg-section-contrast">
            <div class="eg-section-head">
                <p class="eg-kicker">Work</p>
                <h2>Operator-led, not agency theater.</h2>
                <p>Modern Forestry is the proof point: the software judgment comes from building systems for an actual business with real customers, orders, inventory, fulfillment, and marketing pressure.</p>
            </div>
            <div class="eg-split">
                <article class="eg-card">
                    <h3>What changes</h3>
                    <p>Less retyping, fewer mystery handoffs, clearer project decisions, and dashboards that show the work instead of hiding it in messages and spreadsheets.</p>
                </article>
                <article class="eg-card">
                    <h3>How it is built</h3>
                    <p>Laravel applications, customer portals, Shopify and email integrations, AI-assisted workflows, structured data imports, and long-term maintenance.</p>
                </article>
            </div>
        </section>

        <section id="tools" class="eg-section">
            <div class="eg-section-head">
                <p class="eg-kicker">Example Tools</p>
                <h2>Start with a planning range, then talk through the real business case.</h2>
                <p>These calculators are lead magnets and scoping tools. They help frame the conversation before a project becomes a proposal.</p>
            </div>
            <div class="eg-card-grid eg-card-grid-3">
                @foreach($tools as $key => $tool)
                    @php
                        $routeName = match ((string) $key) {
                            'ai_roi' => 'evergrove.tools.ai-roi',
                            'automation_savings' => 'evergrove.tools.automation-savings',
                            default => 'evergrove.tools.project-estimate',
                        };
                    @endphp
                    <article class="eg-card">
                        <h3>{{ $tool['title'] ?? 'Calculator' }}</h3>
                        <p>{{ $tool['summary'] ?? '' }}</p>
                        <a href="{{ route($routeName) }}" class="eg-text-link">Open calculator</a>
                    </article>
                @endforeach
            </div>
        </section>

        <section id="pricing" class="eg-section eg-section-contrast">
            <div class="eg-section-head">
                <p class="eg-kicker">Pricing</p>
                <h2>Useful budget anchors before anyone gets on a call.</h2>
                <p>Exact scope still depends on the business, but most Evergrove work fits one of these lanes.</p>
            </div>
            <div class="eg-card-grid eg-card-grid-3">
                <article class="eg-card eg-price-card">
                    <p>Audit and blueprint</p>
                    <h3>$750-$2,500</h3>
                    <span>Workflow map, AI opportunities, system plan, and build priorities.</span>
                </article>
                <article class="eg-card eg-price-card">
                    <p>Automation or portal build</p>
                    <h3>$2,500-$15,000</h3>
                    <span>Focused Laravel, AI, integration, calculator, or customer visibility projects.</span>
                </article>
                <article class="eg-card eg-price-card">
                    <p>Ongoing systems care</p>
                    <h3>Monthly</h3>
                    <span>Maintenance, improvements, monitoring, new feature requests, and workflow support.</span>
                </article>
            </div>
        </section>

        <section id="everbranch" class="eg-section">
            <div class="eg-product-bridge">
                <div>
                    <p class="eg-kicker">Everbranch</p>
                    <h2>Everbranch is software made by Evergrove.</h2>
                    <p>Evergrove is the company. Everbranch is the product and client workspace: project progress, customer requests, deliverables, onboarding, and future business tools under one login.</p>
                </div>
                <div class="eg-actions">
                    <a href="{{ route('platform.promo') }}" class="eg-button eg-button-secondary">See Everbranch</a>
                    <a href="{{ $loginUrl }}" class="eg-button eg-button-primary">Client portal</a>
                </div>
            </div>
        </section>

        <section id="contact" class="eg-section">
            <div class="eg-contact-layout">
                <div class="eg-section-head">
                    <p class="eg-kicker">Start Project</p>
                    <h2>Bring the messy version of the problem.</h2>
                    <p>Share what is slow, repetitive, unclear, or expensive. Evergrove will help decide whether the right answer is AI, custom software, automation, or a simpler process.</p>
                    <p>Email: <a class="eg-text-link" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></p>
                </div>

                <form method="POST" action="{{ route('evergrove.inquiries.store') }}" class="eg-form-card">
                    @csrf
                    <input type="hidden" name="source_page" value="evergrove_home" />

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
                        Current tools
                        <input name="current_tools" type="text" value="{{ old('current_tools') }}" class="fb-input" placeholder="Shopify, spreadsheets, QuickBooks, email, Asana..." />
                    </label>

                    <label>
                        What should be easier?
                        <textarea name="pain_point" rows="5" class="fb-input">{{ old('pain_point') }}</textarea>
                        @error('pain_point') <span>{{ $message }}</span> @enderror
                    </label>

                    <button type="submit" class="eg-button eg-button-primary">Send project notes</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
