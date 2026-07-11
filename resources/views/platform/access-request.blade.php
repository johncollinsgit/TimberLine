@php
    $content = is_array($surface ?? null) ? $surface : [];
    $planComparison = is_array($content['plan_comparison'] ?? null) ? $content['plan_comparison'] : [];
    $comparePlans = is_array($planComparison['plans'] ?? null) ? $planComparison['plans'] : [];
    $compareFeatures = is_array($planComparison['features'] ?? null) ? $planComparison['features'] : [];
    $comparePlanKeys = array_keys($comparePlans);
    $recommendedComparePlan = (string) ($planComparison['recommended'] ?? '');
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
    $importPaths = is_array($formOptions['import_paths'] ?? null) ? $formOptions['import_paths'] : [];
    $mobileInterests = is_array($formOptions['mobile_interests'] ?? null) ? $formOptions['mobile_interests'] : [];
    $selectedBusinessType = (string) old('business_type', '');
    $selectedTeamSize = (string) old('team_size', '');
    $selectedTimeline = (string) old('timeline', '');
    $selectedImportPath = (string) old('import_path', 'undecided');
    $selectedMobileInterest = (string) old('mobile_interest', 'undecided');
    $showAdvancedDetails = (bool) array_filter([
        old('company'),
        old('website'),
        old('business_type'),
        old('team_size'),
        old('timeline'),
        old('import_path'),
        old('mobile_interest'),
        old('requested_tenant_slug'),
        old('message'),
        old('preferred_plan_key'),
        old('addons_interest'),
    ], static fn ($value): bool => ! empty($value));
    $heroHeadline = (string) ($content['headline'] ?? "Simplify your life,\nGet more time with your family.");
    $heroHeadlineLines = array_values(array_filter(
        array_map('trim', preg_split("/\r\n|\r|\n/", $heroHeadline) ?: []),
        static fn (string $line): bool => $line !== ''
    ));

    if ($heroHeadlineLines === []) {
        $heroHeadlineLines = ['Simplify your life,', 'Get more time with your family.'];
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => ($content['headline'] ?? 'Request access')])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <main class="fb-public-shell fb-contact-shell fb-start-shell">
        <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary fb-contact-back">Back to homepage</a>

        <section class="fb-card fb-contact-overview fb-start-hero" aria-label="Request overview" data-reveal data-premium-surface>
            @if($planComparison !== [] && $comparePlans !== [])
                <div class="fb-start-tabs" role="tablist" aria-label="Start sections">
                    <a id="tab-start-overview" href="#tab-start-overview" class="fb-start-tab is-active" role="tab" aria-selected="true" aria-controls="panel-start-overview" data-public-tab-trigger="overview">Overview</a>
                    <a id="tab-start-pricing" href="#tab-start-pricing" class="fb-start-tab" role="tab" aria-selected="false" tabindex="-1" aria-controls="panel-start-pricing" data-public-tab-trigger="pricing">Pricing</a>
                </div>

                <div class="fb-start-tabpanels" data-public-tabs>
                    <div id="panel-start-overview" class="fb-public-tab-panel is-active" role="tabpanel" aria-labelledby="tab-start-overview" data-public-tab-panel="overview">
                        <h1 class="fb-start-hero__title">
                            @foreach($heroHeadlineLines as $heroLine)
                                <span>{{ $heroLine }}</span>
                            @endforeach
                        </h1>
                    </div>

                    <div id="panel-start-pricing" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-start-pricing" data-public-tab-panel="pricing" hidden>
                        <div class="fb-plan-compare">
                            @if(filled($planComparison['eyebrow'] ?? null))
                                <p class="fb-section-kicker">{{ $planComparison['eyebrow'] }}</p>
                            @endif
                            <h2 class="fb-start-hero__title fb-plan-compare__title">{{ $planComparison['title'] ?? 'Pricing' }}</h2>
                            @if(filled($planComparison['subtitle'] ?? null))
                                <p class="fb-plan-compare__subtitle">{{ $planComparison['subtitle'] }}</p>
                            @endif

                            <div class="fb-plan-compare__table" style="--fb-plan-count: {{ count($comparePlans) }}">
                                <div class="fb-plan-compare__row fb-plan-compare__row--head">
                                    <span class="fb-plan-compare__feature"></span>
                                    @foreach($comparePlans as $planKey => $plan)
                                        <span class="fb-plan-compare__plan {{ $planKey === $recommendedComparePlan ? 'is-recommended' : '' }}">
                                            @if(filled($plan['badge'] ?? null))
                                                <span class="fb-plan-compare__badge">{{ $plan['badge'] }}</span>
                                            @endif
                                            <span class="fb-plan-compare__plan-name">{{ $plan['label'] ?? $planKey }}</span>
                                            @if(filled($plan['descriptor'] ?? null))
                                                <span class="fb-plan-compare__descriptor">{{ $plan['descriptor'] }}</span>
                                            @endif
                                            <span class="fb-plan-compare__price">{{ $plan['price'] ?? '' }}<span class="fb-plan-compare__cadence">{{ $plan['cadence'] ?? '' }}</span></span>
                                        </span>
                                    @endforeach
                                </div>

                                @foreach($compareFeatures as $feature)
                                    <div class="fb-plan-compare__row">
                                        <span class="fb-plan-compare__feature">{{ $feature['label'] ?? '' }}</span>
                                        @foreach($comparePlanKeys as $planKey)
                                            <span class="fb-plan-compare__value {{ $planKey === $recommendedComparePlan ? 'is-recommended' : '' }}">{{ $feature[$planKey] ?? '' }}</span>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>

                            @if(filled($planComparison['savings_note'] ?? null))
                                <p class="fb-plan-compare__savings">{{ $planComparison['savings_note'] }}</p>
                            @endif

                            <div class="fb-start-actions fb-plan-compare__actions">
                                <a href="#start-access-form" class="fb-btn fb-btn-primary">Become a launch partner</a>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <h1 class="fb-start-hero__title">
                    @foreach($heroHeadlineLines as $heroLine)
                        <span>{{ $heroLine }}</span>
                    @endforeach
                </h1>
            @endif
        </section>

        <section id="start-access-form" class="fb-start-layout" aria-label="Access request form" data-reveal>
            <div class="fb-card fb-start-form-card" data-premium-surface>
                @if (session('status'))
                    <div class="fb-state fb-state--success mb-4">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('platform.access-request') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="intent" value="{{ $intentValue }}" />

                    <div class="fb-start-form-grid fb-start-form-grid--single">
                        <div>
                            <label class="fb-form-label" for="name">Full name</label>
                            <input id="name" name="name" type="text" class="fb-input fb-start-field mt-2" value="{{ old('name') }}" required />
                            @error('name') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="fb-start-form-grid fb-start-form-grid--2">
                        <div>
                            <label class="fb-form-label" for="company">Company name</label>
                            <input id="company" name="company" type="text" class="fb-input fb-start-field mt-2" value="{{ old('company') }}" />
                            @error('company') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="fb-form-label" for="email">Email</label>
                            <input id="email" name="email" type="email" class="fb-input fb-start-field mt-2" value="{{ old('email') }}" required />
                            @error('email') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="fb-start-form-grid fb-start-form-grid--2">
                        <div>
                            <label class="fb-form-label" for="business_type">Business type</label>
                            <select id="business_type" name="business_type" class="fb-input fb-select fb-start-field mt-2">
                                <option value="">Select one</option>
                                @foreach($businessTypes as $key => $label)
                                    <option value="{{ $key }}" @selected($selectedBusinessType === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('business_type') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="fb-form-label" for="team_size">Team size</label>
                            <select id="team_size" name="team_size" class="fb-input fb-select fb-start-field mt-2">
                                <option value="">Select one</option>
                                @foreach($teamSizes as $key => $label)
                                    <option value="{{ $key }}" @selected($selectedTeamSize === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('team_size') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <details class="fb-start-details" @if($showAdvancedDetails) open @endif>
                        <summary>
                            <span>
                                <strong>More details</strong>
                            </span>
                        </summary>
                        <div class="fb-start-details__body">
                            <div class="fb-start-form-grid fb-start-form-grid--2">
                                <div>
                                    <label class="fb-form-label" for="requested_tenant_slug">Workspace address</label>
                                    <input
                                        id="requested_tenant_slug"
                                        name="requested_tenant_slug"
                                        type="text"
                                        class="fb-input fb-start-field mt-2"
                                        value="{{ old('requested_tenant_slug') }}"
                                        @if($intentValue === 'demo') disabled @endif
                                    />
                                    @php
                                        $canonicalTenantDomain = strtolower(trim((string) config('tenancy.domains.canonical.base_domain', 'theeverbranch.com')));
                                    @endphp
                                    <div class="fb-help">
                                        This becomes your team’s workspace URL after approval, like `your-workspace.{{ $canonicalTenantDomain }}`.
                                    </div>
                                    @error('requested_tenant_slug') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                                <div>
                                    <label class="fb-form-label" for="message">Notes</label>
                                    <textarea id="message" name="message" rows="4" class="fb-input fb-start-field fb-start-textarea mt-2">{{ old('message') }}</textarea>
                                    @error('message') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="fb-start-form-grid fb-start-form-grid--2">
                                <div>
                                    <label class="fb-form-label" for="timeline">Timeline</label>
                                    <select id="timeline" name="timeline" class="fb-input fb-select fb-start-field mt-2">
                                        <option value="">Select one</option>
                                        @foreach($timelines as $key => $label)
                                            <option value="{{ $key }}" @selected($selectedTimeline === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('timeline') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                                <div>
                                    <label class="fb-form-label" for="website">Website</label>
                                    <input id="website" name="website" type="url" class="fb-input fb-start-field mt-2" value="{{ old('website') }}" placeholder="https://example.com" />
                                    @error('website') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="fb-start-form-grid fb-start-form-grid--2">
                                <div>
                                    <label class="fb-form-label" for="import_path">Primary setup/import path</label>
                                    <select id="import_path" name="import_path" class="fb-input fb-select fb-start-field mt-2">
                                        @foreach($importPaths as $key => $label)
                                            <option value="{{ $key }}" @selected($selectedImportPath === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('import_path') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                                <div>
                                    <label class="fb-form-label" for="mobile_interest">Mobile app interest</label>
                                    <select id="mobile_interest" name="mobile_interest" class="fb-input fb-select fb-start-field mt-2">
                                        @foreach($mobileInterests as $key => $label)
                                            <option value="{{ $key }}" @selected($selectedMobileInterest === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('mobile_interest') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            @if($intentValue === 'production')
                                <div class="fb-start-details__subsection">
                                    <div class="fb-start-details__subsection-title">Commercial interest</div>

                                    <div class="fb-start-form-grid fb-start-form-grid--2 fb-start-form-grid--compact">
                                        <div>
                                            <label class="fb-form-label" for="preferred_plan_key">Preferred tier</label>
                                            <select id="preferred_plan_key" name="preferred_plan_key" class="fb-input fb-select fb-start-field mt-2">
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
                        </div>
                    </details>

                    <div class="fb-start-actions">
                        <button type="submit" class="fb-btn fb-btn-primary fb-start-submit">
                            {{ $content['submit_label'] ?? 'Submit request' }}
                        </button>
                        <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary fb-start-secondary">Already have access? Sign in</a>
                    </div>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
