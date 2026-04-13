@php
    $content = is_array($surface ?? null) ? $surface : [];
    $intentValue = in_array(($intent ?? ''), ['demo', 'production'], true) ? (string) $intent : 'production';
    $planCards = is_array($plan_cards ?? null) ? $plan_cards : [];
    $addonCards = is_array($addon_cards ?? null) ? $addon_cards : [];
    $recommendedPlanKey = (string) ($recommended_plan_key ?? 'growth');
    $selectedPlanKey = old('preferred_plan_key', $recommendedPlanKey);
    $selectedAddons = (array) old('addons_interest', []);
    $formOptions = is_array($form_options ?? null) ? $form_options : [];
    $businessTypes = is_array($formOptions['business_types'] ?? null) ? $formOptions['business_types'] : [];
    $teamSizes = is_array($formOptions['team_sizes'] ?? null) ? $formOptions['team_sizes'] : [];
    $timelines = is_array($formOptions['timelines'] ?? null) ? $formOptions['timelines'] : [];
    $selectedBusinessType = (string) old('business_type', '');
    $selectedTeamSize = (string) old('team_size', '');
    $selectedTimeline = (string) old('timeline', '');
    $intentHeadline = $intentValue === 'demo'
        ? 'Demo access request'
        : 'Production access request';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => ($content['headline'] ?? 'Request access')])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <main class="fb-public-shell fb-contact-shell">
        <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary fb-contact-back">Back to homepage</a>

        <section class="fb-card fb-contact-overview" aria-label="Request overview" data-reveal data-premium-surface>
            <p class="fb-section-kicker">{{ $content['eyebrow'] ?? 'Access' }}</p>
            <h1 class="fb-contact-title">{{ $content['headline'] ?? 'Request access' }}</h1>
            <p class="fb-contact-summary">{{ $content['summary'] ?? 'Submit your details and we will follow up shortly.' }}</p>
            @if(filled($content['intent_note'] ?? null))
                <p class="mt-2 text-sm text-[var(--fb-text-secondary)]">{{ $content['intent_note'] }}</p>
            @endif
            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                <span class="fb-module-pill {{ $intentValue === 'demo' ? 'fb-module-pill--accent' : '' }}">Demo = evaluate safely</span>
                <span class="fb-module-pill {{ $intentValue === 'production' ? 'fb-module-pill--accent' : '' }}">Production = apply + activate</span>
            </div>
        </section>

        <section class="fb-section" aria-label="Access request form" data-reveal>
            <div class="grid gap-6 lg:grid-cols-[1.6fr_minmax(0,1fr)]">
                <div class="fb-card p-6" data-premium-surface>
                @if (session('status'))
                    <div class="fb-state fb-state--success mb-4">{{ session('status') }}</div>
                @endif

                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-[var(--fb-text-primary)]">{{ $intentHeadline }}</h2>
                            <p class="text-sm text-[var(--fb-text-secondary)]">Complete the essentials once. We keep demo and production routing separate.</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('platform.access-request') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="intent" value="{{ $intentValue }}" />

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="fb-form-label" for="name">Name</label>
                                <input id="name" name="name" type="text" class="fb-input mt-2" value="{{ old('name') }}" required />
                                @error('name') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label class="fb-form-label" for="email">Email</label>
                                <input id="email" name="email" type="email" class="fb-input mt-2" value="{{ old('email') }}" required />
                                @error('email') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="fb-form-label" for="company">Company (optional)</label>
                                <input id="company" name="company" type="text" class="fb-input mt-2" value="{{ old('company') }}" />
                                @error('company') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label class="fb-form-label" for="website">Website (optional)</label>
                                <input id="website" name="website" type="url" class="fb-input mt-2" value="{{ old('website') }}" placeholder="https://example.com" />
                                @error('website') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-3">
                            <div>
                                <label class="fb-form-label" for="business_type">Business type</label>
                                <select id="business_type" name="business_type" class="fb-input mt-2">
                                    <option value="">Select one</option>
                                    @foreach($businessTypes as $key => $label)
                                        <option value="{{ $key }}" @selected($selectedBusinessType === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('business_type') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label class="fb-form-label" for="team_size">Team size</label>
                                <select id="team_size" name="team_size" class="fb-input mt-2">
                                    <option value="">Select one</option>
                                    @foreach($teamSizes as $key => $label)
                                        <option value="{{ $key }}" @selected($selectedTeamSize === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('team_size') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label class="fb-form-label" for="timeline">Timeline</label>
                                <select id="timeline" name="timeline" class="fb-input mt-2">
                                    <option value="">Select one</option>
                                    @foreach($timelines as $key => $label)
                                        <option value="{{ $key }}" @selected($selectedTimeline === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('timeline') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="fb-form-label" for="requested_tenant_slug">Tenant slug (optional)</label>
                                <input
                                    id="requested_tenant_slug"
                                    name="requested_tenant_slug"
                                    type="text"
                                    class="fb-input mt-2"
                                    value="{{ old('requested_tenant_slug') }}"
                                    @if($intentValue === 'demo') disabled @endif
                                />
                                <div class="fb-help">Used to route you to `&lt;slug&gt;.forestrybackstage.com` after approval.</div>
                                @error('requested_tenant_slug') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label class="fb-form-label" for="message">Notes (optional)</label>
                                <textarea id="message" name="message" rows="4" class="fb-input mt-2">{{ old('message') }}</textarea>
                                @error('message') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        @if($intentValue === 'production')
                            <div class="rounded-2xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4">
                                <div class="text-sm font-semibold text-[var(--fb-text-primary)]">Commercial interest (optional)</div>
                                <div class="mt-1 text-xs text-[var(--fb-text-secondary)]">This does not trigger billing writes. It helps route the right tier and add-ons after approval.</div>

                                <div class="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="fb-form-label" for="preferred_plan_key">Preferred tier</label>
                                        <select id="preferred_plan_key" name="preferred_plan_key" class="fb-input mt-2">
                                            <option value="">No preference</option>
                                            @foreach($planCards as $plan)
                                                @php
                                                    $key = (string) ($plan['plan_key'] ?? '');
                                                    $label = (string) ($plan['label'] ?? $key);
                                                @endphp
                                                @if($key !== '')
                                                    <option value="{{ $key }}" @selected($selectedPlanKey === $key)>
                                                        {{ $label }}{{ $key === $recommendedPlanKey ? ' (recommended)' : '' }}
                                                    </option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <div class="fb-form-label">Add-ons of interest</div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach($addonCards as $addon)
                                                @php
                                                    $addonKey = (string) ($addon['addon_key'] ?? '');
                                                    $addonLabel = (string) ($addon['label'] ?? $addonKey);
                                                @endphp
                                                @if($addonKey !== '')
                                                    <label class="fb-module-pill cursor-pointer">
                                                        <input
                                                            type="checkbox"
                                                            class="mr-2"
                                                            name="addons_interest[]"
                                                            value="{{ $addonKey }}"
                                                            @checked(in_array($addonKey, $selectedAddons, true))
                                                        />
                                                        {{ $addonLabel }}
                                                    </label>
                                                @endif
                                            @endforeach
                                        </div>
                                        @error('addons_interest') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-3 pt-2">
                            <button type="submit" class="fb-btn fb-btn-primary">
                                {{ $content['submit_label'] ?? 'Submit request' }}
                            </button>
                            <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Already have access? Sign in</a>
                        </div>

                        @if(filled($content['footnote'] ?? null))
                            <p class="mt-4 text-xs text-[var(--fb-text-secondary)]">{{ $content['footnote'] }}</p>
                        @endif
                    </form>
                </div>

                <aside class="space-y-4">
                    <article class="fb-card p-5" data-premium-surface>
                        <h2 class="text-base font-semibold text-[var(--fb-text-primary)]">What happens next</h2>
                        <ol class="mt-3 space-y-2 text-sm text-[var(--fb-text-secondary)] list-decimal pl-4">
                            <li>We review your {{ $intentValue === 'demo' ? 'demo' : 'production' }} request.</li>
                            <li>If approved, you receive one activation email with a password setup link.</li>
                            <li>Your first login lands in tenant-aware Start Here with clear next steps.</li>
                        </ol>
                    </article>

                    @if($intentValue === 'production')
                        <article class="fb-card p-5" data-premium-surface>
                            <h2 class="text-base font-semibold text-[var(--fb-text-primary)]">Production path</h2>
                            <p class="mt-2 text-sm text-[var(--fb-text-secondary)]">Production onboarding keeps approval, activation, and billing handoff truthful and tenant-safe.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="{{ route('platform.plans') }}" class="fb-btn fb-btn-secondary">Compare plans</a>
                                <a href="{{ route('platform.contact', ['intent' => 'sales']) }}" class="fb-btn fb-btn-secondary">Talk to sales</a>
                            </div>
                        </article>
                    @else
                        <article class="fb-card p-5" data-premium-surface>
                            <h2 class="text-base font-semibold text-[var(--fb-text-primary)]">Demo path</h2>
                            <p class="mt-2 text-sm text-[var(--fb-text-secondary)]">Demo access uses a safe sample workspace. Production activation is handled separately.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-secondary">Start as a client</a>
                                <a href="{{ route('platform.plans') }}" class="fb-btn fb-btn-secondary">Compare plans</a>
                            </div>
                        </article>
                    @endif
                </aside>
            </div>
        </section>
    </main>
</body>
</html>
