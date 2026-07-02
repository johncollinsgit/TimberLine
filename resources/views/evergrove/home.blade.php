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
        'title' => 'Evergrove Software | Practical Software for Small Businesses',
        'description' => $positioning['summary'] ?? 'Evergrove builds practical apps, portals, automations, and software products for small businesses.',
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
                <h1>{{ $positioning['headline'] ?? 'We build the software small businesses wish already existed.' }}</h1>
                <p class="eg-lede">{{ $positioning['summary'] ?? 'Evergrove creates practical apps, portals, automations, and software products for small businesses that have outgrown sticky notes, spreadsheets, and scattered tools.' }}</p>
                <div class="eg-actions">
                    <a href="#contact" class="eg-button eg-button-primary">Start with a workflow audit</a>
                    <a href="#work" class="eg-button eg-button-secondary">See what we build</a>
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
                        <p>Focus</p>
                        <strong>Workflow</strong>
                    </div>
                    <div>
                        <p>Next step</p>
                        <strong>Build plan</strong>
                    </div>
                    <div>
                        <p>Goal</p>
                        <strong>Less admin</strong>
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
                <span>Owner-led</span>
                <p>built from real small-business operating pressure, not theory</p>
            </div>
            <div>
                <span>Modern Forestry</span>
                <p>real systems for customers, orders, inventory, wholesale, and daily work</p>
            </div>
            <div>
                <span>Everbranch</span>
                <p>a focused product created by Evergrove for small-business operations</p>
            </div>
        </section>

        <section id="services" class="eg-section">
            <div class="eg-section-head">
                <p class="eg-kicker">What we build</p>
                <h2>Useful systems for the work your business repeats every week.</h2>
                <p>Evergrove starts with the real workflow, then builds the smallest practical system that gives the owner and team more clarity.</p>
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

        <section class="eg-section">
            <div class="eg-section-head">
                <p class="eg-kicker">How it works</p>
                <h2>A simple plan before anyone builds the wrong thing.</h2>
                <p>Good software starts by understanding the business, not by forcing the business into a generic tool.</p>
            </div>
            <div class="eg-card-grid eg-card-grid-3">
                <article class="eg-card">
                    <h3>1. Map the workflow</h3>
                    <p>Bring the scattered notes, spreadsheets, texts, tools, and repeated admin. We turn the messy version into a clear operating map.</p>
                </article>
                <article class="eg-card">
                    <h3>2. Build the right system</h3>
                    <p>Create the app, portal, dashboard, automation, or product lane that fits the way the business actually works.</p>
                </article>
                <article class="eg-card">
                    <h3>3. Improve it as you grow</h3>
                    <p>Keep the system useful as the team changes, the work expands, and the owner needs fewer dropped balls.</p>
                </article>
            </div>
        </section>

        <section id="work" class="eg-section eg-section-contrast">
            <div class="eg-section-head">
                <p class="eg-kicker">Why Evergrove</p>
                <h2>Small businesses deserve useful software without an enterprise budget.</h2>
                <p>Most owners do not need a giant platform. They need a thoughtful software partner who can turn operational mess into tools the team will actually use.</p>
            </div>
            <div class="eg-split">
                <article class="eg-card">
                    <h3>What gets better</h3>
                    <p>Less retyping, fewer mystery handoffs, clearer follow-ups, and dashboards that show the work instead of hiding it in messages and spreadsheets.</p>
                </article>
                <article class="eg-card">
                    <h3>What we can build</h3>
                    <p>Internal apps, customer portals, Shopify and customer systems, reporting tools, AI-assisted admin, workflow dashboards, and Everbranch implementations.</p>
                </article>
            </div>
        </section>

        <section id="tools" class="eg-section">
            <div class="eg-section-head">
                <p class="eg-kicker">Example Tools</p>
                <h2>Start with a planning range, then talk through the real workflow.</h2>
                <p>These calculators help frame the conversation before a project becomes a proposal.</p>
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
                    <h2>Everbranch is one product created by Evergrove.</h2>
                    <p>Everbranch is our small-business operating workspace, built for teams that need one place to manage customers, tasks, notes, follow-ups, messages, and daily work.</p>
                </div>
                <div class="eg-actions">
                    <a href="{{ route('platform.promo') }}" class="eg-button eg-button-secondary">Explore Everbranch</a>
                    <a href="{{ $loginUrl }}" class="eg-button eg-button-primary">Client portal</a>
                </div>
            </div>
        </section>

        <section id="contact" class="eg-section">
            <div class="eg-contact-layout">
                <div class="eg-section-head">
                    <p class="eg-kicker">Workflow audit</p>
                    <h2>Bring the messy version of the problem.</h2>
                    <p>Share what is slow, repetitive, unclear, or expensive. Evergrove will help decide whether the right answer is an app, portal, automation, AI-assisted workflow, or a simpler process.</p>
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

                    <button type="submit" class="eg-button eg-button-primary">Send workflow notes</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
