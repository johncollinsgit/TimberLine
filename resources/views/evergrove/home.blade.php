@php
    $content = is_array($content ?? null) ? $content : [];
    $positioning = is_array($content['positioning'] ?? null) ? $content['positioning'] : [];
    $services = is_array($content['services'] ?? null) ? $content['services'] : [];
    $tools = is_array($tools ?? null) ? $tools : [];
    $businessSizes = is_array($content['business_sizes'] ?? null) ? $content['business_sizes'] : [];
    $timelines = is_array($content['timeline_options'] ?? null) ? $content['timeline_options'] : [];
    $budgetRanges = is_array($content['budget_ranges'] ?? null) ? $content['budget_ranges'] : [];
    $contactEmail = (string) ($content['contact_email'] ?? 'hello@theevergrove.com');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Evergrove',
        'title' => 'Evergrove | AI Systems and Custom Software',
        'description' => $positioning['summary'] ?? 'Evergrove builds practical AI systems and custom software for small and medium businesses.',
    ])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <div class="fb-public-shell fb-public-shell--wide">
        <div class="fb-site-nav-wrap">
            <nav class="fb-site-nav fb-site-nav--premium" aria-label="Primary navigation">
                <a href="/" class="fb-site-brand">
                    <span class="text-lg font-semibold text-[var(--fb-text-primary)]">Evergrove</span>
                </a>
                <div class="fb-site-links" aria-label="Public sections">
                    <a href="#services">Services</a>
                    <a href="#tools">Tools</a>
                    <a href="#proof">Proof</a>
                    <a href="#everbranch">Everbranch</a>
                    <a href="#contact">Contact</a>
                </div>
                <div class="fb-hero-cta fb-hero-cta--nav">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                    <a href="#contact" class="fb-btn fb-btn-primary">Start a project</a>
                </div>
            </nav>
        </div>

        <header class="min-h-[76vh] rounded-none border-b border-[var(--fb-border)] bg-[var(--fb-surface)] py-12 md:py-16" aria-label="Evergrove services">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,0.95fr)_minmax(420px,1.05fr)] lg:items-center">
                <div class="max-w-3xl">
                    <p class="fb-section-kicker">{{ $positioning['eyebrow'] ?? 'AI systems and custom software' }}</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-tight text-[var(--fb-text-primary)] md:text-6xl">
                        {{ $positioning['headline'] ?? 'Turn scattered operations into useful software.' }}
                    </h1>
                    <p class="mt-5 text-lg leading-8 text-[var(--fb-text-secondary)]">
                        {{ $positioning['summary'] ?? '' }}
                    </p>
                    <div class="mt-7 flex flex-wrap gap-3">
                        <a href="#contact" class="fb-btn fb-btn-primary">Start a project</a>
                        <a href="#tools" class="fb-btn fb-btn-secondary">Use the calculators</a>
                        <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary">See Everbranch</a>
                    </div>
                    <dl class="mt-8 grid gap-3 sm:grid-cols-3">
                        <div class="border-l border-[var(--fb-border)] pl-4">
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--fb-text-muted)]">Operator led</dt>
                            <dd class="mt-1 text-sm font-semibold text-[var(--fb-text-primary)]">15 years building and running systems</dd>
                        </div>
                        <div class="border-l border-[var(--fb-border)] pl-4">
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--fb-text-muted)]">Stack</dt>
                            <dd class="mt-1 text-sm font-semibold text-[var(--fb-text-primary)]">Laravel, AI workflows, integrations</dd>
                        </div>
                        <div class="border-l border-[var(--fb-border)] pl-4">
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--fb-text-muted)]">Best fit</dt>
                            <dd class="mt-1 text-sm font-semibold text-[var(--fb-text-primary)]">Small to medium businesses</dd>
                        </div>
                    </dl>
                </div>

                <aside class="relative overflow-hidden border border-[var(--fb-border)] bg-[var(--fb-surface-subtle)] p-5 shadow-sm" aria-label="Evergrove project visibility preview">
                    <div class="flex items-center justify-between border-b border-[var(--fb-border)] pb-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--fb-text-muted)]">Client progress</p>
                            <h2 class="mt-1 text-xl font-semibold text-[var(--fb-text-primary)]">Website + AI workflow build</h2>
                        </div>
                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">On track</span>
                    </div>
                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        <div class="border border-[var(--fb-border)] bg-white p-3">
                            <p class="text-xs text-[var(--fb-text-muted)]">Phase</p>
                            <p class="mt-1 font-semibold">Build</p>
                        </div>
                        <div class="border border-[var(--fb-border)] bg-white p-3">
                            <p class="text-xs text-[var(--fb-text-muted)]">Next review</p>
                            <p class="mt-1 font-semibold">Friday</p>
                        </div>
                        <div class="border border-[var(--fb-border)] bg-white p-3">
                            <p class="text-xs text-[var(--fb-text-muted)]">Open items</p>
                            <p class="mt-1 font-semibold">3 decisions</p>
                        </div>
                    </div>
                    <div class="mt-5 space-y-3">
                        @foreach ([
                            ['label' => 'Discovery', 'width' => '100%', 'tone' => 'bg-emerald-500'],
                            ['label' => 'Architecture', 'width' => '100%', 'tone' => 'bg-emerald-500'],
                            ['label' => 'Build', 'width' => '62%', 'tone' => 'bg-[var(--fb-accent)]'],
                            ['label' => 'QA and launch', 'width' => '18%', 'tone' => 'bg-zinc-400'],
                        ] as $row)
                            <div>
                                <div class="mb-1 flex justify-between text-xs font-semibold text-[var(--fb-text-secondary)]">
                                    <span>{{ $row['label'] }}</span>
                                    <span>{{ $row['width'] }}</span>
                                </div>
                                <div class="h-2 bg-white">
                                    <div class="h-2 {{ $row['tone'] }}" style="width: {{ $row['width'] }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-5 border border-[var(--fb-border)] bg-white p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--fb-text-muted)]">Latest update</p>
                        <p class="mt-2 text-sm text-[var(--fb-text-secondary)]">Prototype screens are ready for review. Next step is connecting the estimate calculator to the inquiry workflow.</p>
                    </div>
                </aside>
            </div>
        </header>

        <main class="fb-public-main">
            <section id="services" class="fb-section fb-section--public" aria-label="Services" data-reveal>
                <div class="fb-section-header">
                    <p class="fb-section-kicker">Services</p>
                    <h2>Practical systems for businesses that have already outgrown duct tape.</h2>
                    <p>Evergrove starts with the operational pain, then chooses the smallest useful build that can improve the week.</p>
                </div>
                <div class="fb-grid fb-grid-4">
                    @foreach($services as $service)
                        <article class="fb-card fb-card--public">
                            <h3>{{ $service['title'] ?? 'Service' }}</h3>
                            <p>{{ $service['summary'] ?? '' }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section id="tools" class="fb-section fb-section--public" aria-label="Example calculators" data-reveal>
                <div class="fb-section-header">
                    <p class="fb-section-kicker">Example Tools</p>
                    <h2>Start with a number, then talk through the real business case.</h2>
                    <p>These calculators are intentionally lightweight. They help frame scope before a project becomes a proposal.</p>
                </div>
                <div class="fb-grid fb-grid-3">
                    @foreach($tools as $key => $tool)
                        @php
                            $routeName = match ((string) $key) {
                                'ai_roi' => 'evergrove.tools.ai-roi',
                                'automation_savings' => 'evergrove.tools.automation-savings',
                                default => 'evergrove.tools.project-estimate',
                            };
                        @endphp
                        <article class="fb-card fb-card--public" data-premium-surface>
                            <h3>{{ $tool['title'] ?? 'Calculator' }}</h3>
                            <p>{{ $tool['summary'] ?? '' }}</p>
                            <a href="{{ route($routeName) }}" class="mt-4 inline-flex text-sm font-semibold text-[var(--fb-brand)]">Open calculator</a>
                        </article>
                    @endforeach
                </div>
            </section>

            <section id="proof" class="fb-section fb-section--public" aria-label="Founder proof" data-reveal>
                <div class="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
                    <div class="fb-section-header">
                        <p class="fb-section-kicker">Founder Story</p>
                        <h2>Built by someone who has had to run the business after the software shipped.</h2>
                        <p>Evergrove comes from 15 years of technical proficiency and the operator experience behind Modern Forestry. The work is grounded in real inventory, orders, customer programs, fulfillment, marketing, and systems that have to keep working on ordinary busy days.</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <article class="fb-card fb-card--public">
                            <h3>Operator judgment</h3>
                            <p>Recommendations account for workflow friction, staff adoption, cost, maintenance, and whether AI is actually the right tool.</p>
                        </article>
                        <article class="fb-card fb-card--public">
                            <h3>Technical depth</h3>
                            <p>Laravel, Shopify, data imports, dashboards, customer systems, automation, deployment, and long-term iteration.</p>
                        </article>
                    </div>
                </div>
            </section>

            <section id="everbranch" class="fb-section fb-section--public" aria-label="Everbranch relationship" data-reveal>
                <div class="fb-final-cta fb-final-cta--public" data-premium-surface>
                    <div>
                        <p class="fb-section-kicker">Everbranch</p>
                        <h2>Everbranch is the productized workspace behind the consulting.</h2>
                        <p>Evergrove handles strategy and custom implementation. Everbranch gives clients a clean login for progress, onboarding, project visibility, and future business workspace features.</p>
                    </div>
                    <div class="fb-hero-cta">
                        <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary">View Everbranch</a>
                        <a href="{{ route('login') }}" class="fb-btn fb-btn-primary">Client login</a>
                    </div>
                </div>
            </section>

            <section id="contact" class="fb-section fb-section--public" aria-label="Contact Evergrove" data-reveal>
                <div class="grid gap-6 lg:grid-cols-[0.8fr_1.2fr]">
                    <div class="fb-section-header">
                        <p class="fb-section-kicker">Contact</p>
                        <h2>Bring the messy version of the problem.</h2>
                        <p>Share what is slow, repetitive, unclear, or expensive. Evergrove will help decide whether the right answer is AI, custom software, automation, or a simpler process.</p>
                        <p class="mt-3 text-sm text-[var(--fb-text-secondary)]">Email: <a class="font-semibold text-[var(--fb-brand)]" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></p>
                    </div>

                    <form method="POST" action="{{ route('evergrove.inquiries.store') }}" class="fb-card fb-card--public space-y-4" data-premium-surface>
                        @csrf
                        <input type="hidden" name="source_page" value="evergrove_home" />

                        @if (session('status'))
                            <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
                        @endif

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                Name
                                <input name="name" type="text" value="{{ old('name') }}" required class="fb-input mt-2" />
                                @error('name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                            </label>
                            <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                Email
                                <input name="email" type="email" value="{{ old('email') }}" required class="fb-input mt-2" />
                                @error('email') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                            </label>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                Company
                                <input name="company" type="text" value="{{ old('company') }}" class="fb-input mt-2" />
                            </label>
                            <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                Website
                                <input name="website" type="url" value="{{ old('website') }}" class="fb-input mt-2" placeholder="https://example.com" />
                                @error('website') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                            </label>
                        </div>

                        <div class="grid gap-4 md:grid-cols-3">
                            <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                Business size
                                <select name="business_size" class="fb-input mt-2">
                                    <option value="">Select one</option>
                                    @foreach($businessSizes as $key => $label)
                                        <option value="{{ $key }}" @selected(old('business_size') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                Timeline
                                <select name="timeline" class="fb-input mt-2">
                                    <option value="">Select one</option>
                                    @foreach($timelines as $key => $label)
                                        <option value="{{ $key }}" @selected(old('timeline') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                Budget range
                                <select name="budget_range" class="fb-input mt-2">
                                    <option value="">Select one</option>
                                    @foreach($budgetRanges as $key => $label)
                                        <option value="{{ $key }}" @selected(old('budget_range') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            Current tools
                            <input name="current_tools" type="text" value="{{ old('current_tools') }}" class="fb-input mt-2" placeholder="Shopify, spreadsheets, QuickBooks, email, Asana..." />
                        </label>

                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            What should be easier?
                            <textarea name="pain_point" rows="5" class="fb-input mt-2">{{ old('pain_point') }}</textarea>
                            @error('pain_point') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <button type="submit" class="fb-btn fb-btn-primary">Send project notes</button>
                    </form>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
