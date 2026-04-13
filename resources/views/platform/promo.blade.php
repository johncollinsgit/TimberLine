@php
    $content = is_array($promo ?? null) ? $promo : [];
    $cta = is_array($content['ctas'] ?? null) ? $content['ctas'] : [];
    $planCards = is_array($plan_cards ?? null) ? $plan_cards : [];
    $moduleShowcase = is_array($module_showcase ?? null) ? $module_showcase : [];
    $availableNowModules = is_array($moduleShowcase['available_now'] ?? null) ? $moduleShowcase['available_now'] : [];
    $unlockNextModules = is_array($moduleShowcase['unlock_next'] ?? null) ? $moduleShowcase['unlock_next'] : [];
    $comingSoonModules = is_array($moduleShowcase['coming_soon'] ?? null) ? $moduleShowcase['coming_soon'] : [];
    $previewProfiles = is_array($content['preview_profiles'] ?? null) ? $content['preview_profiles'] : [];
    $previewFlow = is_array($content['preview_flow'] ?? null) ? $content['preview_flow'] : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => 'Forestry Backstage'])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <div class="fb-announcement-bar" role="note" aria-label="Platform status">
        <strong>Live now:</strong>
        Production, shipping, customer growth, and Shopify workflows in one place.
    </div>

    <div class="fb-public-shell">
        <div class="fb-site-nav-wrap">
            <nav class="fb-site-nav" aria-label="Primary navigation">
                <a href="#top" class="fb-site-brand fb-site-brand--lockup">
                    <img src="{{ asset('brand/forestry-backstage-lockup.svg') }}?v=fb2" alt="Forestry Backstage" />
                </a>
                <div class="fb-site-links">
                    <a href="#product">Product</a>
                    <a href="#workflows">Workflows</a>
                    <a href="#outcomes">Outcomes</a>
                    <a href="#security">Security</a>
                    <a href="#faq">FAQ</a>
                </div>
                <div class="fb-hero-cta">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Log in</a>
                    @if(is_array($cta['plans'] ?? null) && filled($cta['plans']['href'] ?? null))
                        <a href="{{ $cta['plans']['href'] }}" class="fb-btn fb-btn-secondary">{{ $cta['plans']['label'] ?? 'Compare plans' }}</a>
                    @endif
                    @if(is_array($cta['start_client'] ?? null) && filled($cta['start_client']['href'] ?? null))
                        <a href="{{ $cta['start_client']['href'] }}" class="fb-btn fb-btn-secondary">{{ $cta['start_client']['label'] ?? 'Start as a client' }}</a>
                    @endif
                    @if(is_array($cta['install'] ?? null) && filled($cta['install']['href'] ?? null))
                        <a href="{{ $cta['install']['href'] }}" class="fb-btn fb-btn-primary">{{ $cta['install']['label'] ?? 'Install on Shopify' }}</a>
                    @endif
                </div>
            </nav>
        </div>

        <header id="top" class="fb-hero" aria-label="Hero section" data-reveal>
            <div data-depth="10">
                <h1>Customers, shipping, and wholesale in one place.</h1>
                <p>
                    Track orders, inventory, fulfillment, and customer growth from one place built for real operations.
                </p>
                <div class="fb-hero-cta" aria-label="Primary calls to action">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-primary">Sign in</a>
                    @if(is_array($cta['plans'] ?? null) && filled($cta['plans']['href'] ?? null))
                        <a href="{{ $cta['plans']['href'] }}" class="fb-btn fb-btn-secondary">{{ $cta['plans']['label'] ?? 'Compare plans' }}</a>
                    @endif
                    @if(is_array($cta['demo'] ?? null) && filled($cta['demo']['href'] ?? null))
                        <a href="{{ $cta['demo']['href'] }}" class="fb-btn fb-btn-secondary">{{ $cta['demo']['label'] ?? 'See a live demo' }}</a>
                    @endif
                    @if(is_array($cta['start_client'] ?? null) && filled($cta['start_client']['href'] ?? null))
                        <a href="{{ $cta['start_client']['href'] }}" class="fb-btn fb-btn-secondary">{{ $cta['start_client']['label'] ?? 'Start as a client' }}</a>
                    @endif
                </div>
            </div>

            <aside class="fb-hero-kpis" aria-label="Platform highlights" data-depth="16">
                <article class="fb-kpi" data-premium-surface>
                    <span class="fb-kpi-label">Shopify Embedded</span>
                    <span class="fb-kpi-value">Live</span>
                </article>
                <article class="fb-kpi" data-premium-surface>
                    <span class="fb-kpi-label">Rewards</span>
                    <span class="fb-kpi-value">Rewards</span>
                </article>
                <article class="fb-kpi" data-premium-surface>
                    <span class="fb-kpi-label">Plans</span>
                    <span class="fb-kpi-value">Starter / Growth / Pro</span>
                </article>
                <article class="fb-kpi" data-premium-surface>
                    <span class="fb-kpi-label">Deployment Model</span>
                    <span class="fb-kpi-value">Public + Landlord + Tenant</span>
                </article>
            </aside>
        </header>

        <section class="fb-section" aria-label="Trust and social proof" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">Built for Real Operations</p>
                <h2>Reliable from login to fulfillment.</h2>
                <p>
                    Tenant-aware access, role-based routing, and stable customer identity keep teams aligned
                    as order volume grows.
                </p>
            </div>
            <div class="fb-grid fb-grid-4">
                <article class="fb-card"><h3>Tenant-aware access</h3><p>Sign-in and redirects stay scoped so each team lands in the right workspace.</p></article>
                <article class="fb-card"><h3>Unified customer identity</h3><p>Customer records stay connected across marketing, rewards, and support workflows.</p></article>
                <article class="fb-card"><h3>Shopify-safe embedded routes</h3><p>Embedded surfaces keep context validation and frame safety intact.</p></article>
                <article class="fb-card"><h3>Plan and module controls</h3><p>Starter, Growth, Pro, and add-ons stay clear for operators and leadership.</p></article>
            </div>
        </section>

        <section id="product" class="fb-section" aria-label="Product overview" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">Product Overview</p>
                <h2>One platform for operations and customer growth.</h2>
                <p>
                    Run production, shipping, wholesale, marketing, and retention from one connected place.
                </p>
            </div>
            <div class="fb-grid fb-grid-3">
                <article class="fb-card">
                    <h3>Production and Fulfillment</h3>
                    <ul>
                        <li>Pouring queues, stack views, and timelines</li>
                        <li>Shipping room workflows and order visibility</li>
                        <li>Events and markets planning workflows</li>
                    </ul>
                </article>
                <article class="fb-card">
                    <h3>Marketing and Customer Growth</h3>
                    <ul>
                        <li>Customers, campaigns, messages, and segments</li>
                        <li>Channel readiness and connection diagnostics</li>
                        <li>Consent-aware lifecycle outreach</li>
                    </ul>
                </article>
                <article class="fb-card">
                    <h3>Rewards and Retention</h3>
                    <ul>
                        <li>Rewards operations and balance rules</li>
                        <li>Reviews and birthday workflows</li>
                        <li>Shopify embedded customer workflows</li>
                    </ul>
                </article>
            </div>
        </section>

        @if($previewProfiles !== [])
            <section class="fb-section" aria-label="Industry preview personas" data-reveal>
                <div class="fb-section-header">
                    <p class="fb-section-kicker">Public Preview</p>
                    <h2>Illustrative preview states by business type.</h2>
                    <p>These are guided sample views so teams can evaluate fit quickly before requesting demo or production access.</p>
                </div>
                <div class="fb-grid fb-grid-3">
                    @foreach($previewProfiles as $profile)
                        <article class="fb-card" data-premium-surface>
                            <h3>{{ $profile['label'] ?? 'Business type' }}</h3>
                            <p>{{ $profile['summary'] ?? '' }}</p>
                            @if(! empty($profile['signals']))
                                <ul class="mt-3 space-y-1 text-sm text-[var(--fb-text-secondary)]">
                                    @foreach((array) $profile['signals'] as $signal)
                                        <li>• {{ $signal }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if($previewFlow !== [])
            <section class="fb-section" aria-label="Customer journey flow" data-reveal>
                <div class="fb-section-header">
                    <p class="fb-section-kicker">Journey</p>
                    <h2>One flow from public preview to workspace activation.</h2>
                </div>
                <div class="fb-grid fb-grid-3">
                    @foreach($previewFlow as $step)
                        <article class="fb-card" data-premium-surface>
                            <h3>{{ $step['title'] ?? 'Step' }}</h3>
                            <p>{{ $step['description'] ?? '' }}</p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="fb-section" aria-label="Feature grid" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">Capabilities</p>
                <h2>Clear modules your team can scan quickly.</h2>
            </div>
            <div class="fb-grid fb-grid-4">
                <article class="fb-card"><h3>Dashboard</h3><p>See what needs attention across operations and customer activity.</p></article>
                <article class="fb-card"><h3>Start Here</h3><p>Set up live modules first and track what is still pending.</p></article>
                <article class="fb-card"><h3>Plans and Add-ons</h3><p>View plan scope, locked modules, and upgrade options.</p></article>
                <article class="fb-card"><h3>Integrations</h3><p>Track connector status and use fallback paths when needed.</p></article>
                <article class="fb-card"><h3>Customers</h3><p>Manage identity, notes, consent, and reward actions in one place.</p></article>
                <article class="fb-card"><h3>Rewards</h3><p>Run rewards settings and daily reward operations.</p></article>
                <article class="fb-card"><h3>Birthdays</h3><p>Manage birthday campaigns, timing, and reporting.</p></article>
                <article class="fb-card"><h3>Admin Controls</h3><p>Manage imports, catalog data, and operational settings.</p></article>
            </div>
        </section>

        @if($availableNowModules !== [] || $unlockNextModules !== [] || $comingSoonModules !== [])
            <section class="fb-section" aria-label="Module status overview" data-reveal>
                <div class="fb-section-header">
                    <p class="fb-section-kicker">Module Access</p>
                    <h2>Available now, unlock next, and coming soon.</h2>
                    <p>Module visibility is canonical and entitlement-aware. Roadmap modules stay visible but are clearly marked.</p>
                </div>

                <div class="fb-grid fb-grid-3">
                    <article class="fb-card" data-premium-surface>
                        <h3>Available now</h3>
                        @if($availableNowModules !== [])
                            <ul class="mt-3 space-y-2 text-sm text-[var(--fb-text-secondary)]">
                                @foreach(array_slice($availableNowModules, 0, 6) as $module)
                                    <li>
                                        <span class="font-semibold text-[var(--fb-text-primary)]">{{ $module['label'] ?? 'Module' }}</span>
                                        @if(filled($module['description'] ?? null))
                                            <div>{{ $module['description'] }}</div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-3 text-sm text-[var(--fb-text-secondary)]">No public modules are currently marked as available.</p>
                        @endif
                    </article>

                    <article class="fb-card" data-premium-surface>
                        <h3>Unlock next</h3>
                        @if($unlockNextModules !== [])
                            <ul class="mt-3 space-y-2 text-sm text-[var(--fb-text-secondary)]">
                                @foreach(array_slice($unlockNextModules, 0, 6) as $module)
                                    <li>
                                        <span class="font-semibold text-[var(--fb-text-primary)]">{{ $module['label'] ?? 'Module' }}</span>
                                        <div>{{ $module['description'] ?? 'Add-on module.' }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-3 text-sm text-[var(--fb-text-secondary)]">No add-on modules are highlighted right now.</p>
                        @endif
                    </article>

                    <article class="fb-card" data-premium-surface>
                        <h3>Coming soon</h3>
                        @if($comingSoonModules !== [])
                            <ul class="mt-3 space-y-2 text-sm text-[var(--fb-text-secondary)]">
                                @foreach(array_slice($comingSoonModules, 0, 6) as $module)
                                    <li>
                                        <span class="font-semibold text-[var(--fb-text-primary)]">{{ $module['label'] ?? 'Module' }}</span>
                                        <div>{{ $module['description'] ?? 'Roadmap-visible module.' }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-3 text-sm text-[var(--fb-text-secondary)]">Roadmap modules are not currently listed for this surface.</p>
                        @endif
                    </article>
                </div>
            </section>
        @endif

        <section id="workflows" class="fb-section" aria-label="Workflow and process section" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">How It Works</p>
                <h2>From setup to daily execution.</h2>
            </div>
            <div class="fb-grid fb-grid-3">
                @foreach((array) ($content['how_it_works'] ?? []) as $step)
                    <article class="fb-card">
                        <h3>{{ $step['title'] ?? 'Step' }}</h3>
                        <p>{{ $step['description'] ?? '' }}</p>
                    </article>
                @endforeach
                <article class="fb-card">
                    <h3>Run Daily Operations</h3>
                    <p>
                        Move from production to shipping to customer follow-up without losing context
                        or switching between disconnected tools.
                    </p>
                </article>
            </div>
        </section>

        <section class="fb-section" aria-label="Product preview section" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">Product Preview</p>
                <h2>A clear interface built for operators.</h2>
                <p>
                    Consistent titles, tabs, and actions make pages faster to scan and easier to use.
                </p>
            </div>
            <article class="fb-card">
                <h3>What Teams See</h3>
                <p>
                    Public pages, login, backstage, and Shopify embedded views now use the same language
                    and structure so teams can move faster with less training overhead.
                </p>
                <ul>
                    <li>Shared visual language across public, auth, admin, and embedded views</li>
                    <li>Clear page titles, tab names, and action labels</li>
                    <li>Built-in helper copy for key operator pages</li>
                </ul>
            </article>
        </section>

        <section id="outcomes" class="fb-section" aria-label="Outcomes and ROI section" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">Outcomes</p>
                <h2>Fewer handoffs. Faster execution.</h2>
            </div>
            <div class="fb-grid fb-grid-3">
                <article class="fb-card"><h3>One place for daily work</h3><p>Run operations, fulfillment, and customer programs from one workspace.</p></article>
                <article class="fb-card"><h3>Clear status before action</h3><p>See plan scope, module state, and readiness before making changes.</p></article>
                <article class="fb-card"><h3>Faster onboarding</h3><p>New team members can start quickly with clear modules and guidance.</p></article>
            </div>
        </section>

        <section class="fb-section" aria-label="Testimonials and proof section" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">Proof</p>
                <h2>Built for teams that ship every day.</h2>
            </div>
            <div class="fb-grid fb-grid-2">
                <article class="fb-card">
                    <p class="fb-proof-quote">“We can see production, shipping, and customer workflows without bouncing between tools.”</p>
                    <p class="fb-proof-meta">Operations Lead · Multi-channel retail team</p>
                </article>
                <article class="fb-card">
                    <p class="fb-proof-quote">“Our Shopify workflows stayed stable while operations became much easier to manage.”</p>
                    <p class="fb-proof-meta">Ecommerce Manager · Embedded app user</p>
                </article>
            </div>
        </section>

        <section id="security" class="fb-section" aria-label="Security and reliability section" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">Security and Reliability</p>
                <h2>Operational controls you can trust.</h2>
            </div>
            <div class="fb-grid fb-grid-3">
                <article class="fb-card"><h3>Host-aware routing</h3><p>Public, landlord, and tenant routes are explicit and controlled.</p></article>
                <article class="fb-card"><h3>Safe sync patterns</h3><p>Import and sync flows are designed for stable reruns and identity consistency.</p></article>
                <article class="fb-card"><h3>Role-based routing</h3><p>Each role lands in the right workspace after sign-in.</p></article>
            </div>
        </section>

        <section id="faq" class="fb-section" aria-label="FAQ section" data-reveal>
            <div class="fb-section-header">
                <p class="fb-section-kicker">FAQ</p>
                <h2>Common rollout questions.</h2>
            </div>
            <div class="fb-card">
                <article class="fb-faq-item">
                    <h3>Will this replace current Shopify embedded workflows?</h3>
                    <p>No. Existing embedded routes and context checks remain intact.</p>
                </article>
                <article class="fb-faq-item">
                    <h3>Can we keep tenant-aware login and role redirects?</h3>
                    <p>Yes. Login stays on dedicated routes with Fortify, Socialite, and existing redirect rules.</p>
                </article>
                <article class="fb-faq-item">
                    <h3>Do these plans match the live commercial model?</h3>
                    <p>Yes. Starter, Growth, Pro, and add-ons map directly to current configuration.</p>
                </article>
            </div>
        </section>

        @if($planCards !== [])
            <section class="fb-section" aria-label="Plan summary section" data-reveal>
                <div class="fb-section-header">
                    <p class="fb-section-kicker">Plans</p>
                    <h2>Plans built around operating scope.</h2>
                </div>
                <div class="fb-grid fb-grid-3">
                    @foreach($planCards as $card)
                        <article class="fb-card" data-plan-key="{{ $card['plan_key'] ?? 'plan' }}">
                            <h3>{{ $card['label'] ?? 'Plan' }}</h3>
                            <p><strong>{{ $card['price_display'] ?? '' }}</strong></p>
                            <p>{{ $card['summary'] ?? '' }}</p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="fb-section" aria-label="Final call to action" data-reveal>
            <div class="fb-final-cta" data-premium-surface>
                <h2>Run operations from one place.</h2>
                <p>Keep Shopify workflows strong while your team scales production, fulfillment, and customer growth.</p>
                <div class="fb-hero-cta">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Log in</a>
                    @if(is_array($cta['contact'] ?? null) && filled($cta['contact']['href'] ?? null))
                        <a href="{{ $cta['contact']['href'] }}" class="fb-btn fb-btn-primary">{{ $cta['contact']['label'] ?? 'Talk to sales' }}</a>
                    @endif
                </div>
            </div>
        </section>

        <footer class="fb-footer" aria-label="Footer">
            <div>Forestry Backstage · Operations software for production, shipping, and customer growth.</div>
        </footer>
    </div>
</body>
</html>
