@php
    $tenantName = (string) ($tenant->name ?? $tenant->slug ?? 'Tenant');
    $journey = is_array($journey ?? null) ? $journey : [];
    $plan = is_array($journey['plan'] ?? null) ? (array) $journey['plan'] : [];
    $activeNow = is_array($journey['active_now'] ?? null) ? (array) $journey['active_now'] : [];
    $availableNext = is_array($journey['available_next'] ?? null) ? (array) $journey['available_next'] : [];
    $purchasable = is_array($journey['purchasable'] ?? null) ? (array) $journey['purchasable'] : [];
    $billingInterest = is_array($journey['billing_interest'] ?? null) ? (array) $journey['billing_interest'] : [];
    $billingNextStep = is_array($journey['billing_next_step'] ?? null) ? (array) $journey['billing_next_step'] : [];
    $commercialSummary = is_array($journey['commercial_summary'] ?? null) ? (array) $journey['commercial_summary'] : [];
    $setupStatus = is_array($setupStatus ?? null) ? $setupStatus : [];
    $setupOptions = is_array($setupOptions ?? null) ? $setupOptions : [];
    $businessProfileStatuses = is_array($setupOptions['business_profile_statuses'] ?? null) ? $setupOptions['business_profile_statuses'] : [];
    $importPaths = is_array($setupOptions['import_paths'] ?? null) ? $setupOptions['import_paths'] : [];
    $squareStatuses = is_array($setupOptions['square_statuses'] ?? null) ? $setupOptions['square_statuses'] : [];
    $csvManualStatuses = is_array($setupOptions['csv_manual_statuses'] ?? null) ? $setupOptions['csv_manual_statuses'] : [];
    $mobileInterests = is_array($setupOptions['mobile_interests'] ?? null) ? $setupOptions['mobile_interests'] : [];
    $planInterests = is_array($setupOptions['plan_interests'] ?? null) ? $setupOptions['plan_interests'] : [];
    $billingLaneInterests = is_array($setupOptions['billing_lane_interests'] ?? null) ? $setupOptions['billing_lane_interests'] : [];
    $moduleInterestOptions = is_array($setupOptions['module_interests'] ?? null) ? $setupOptions['module_interests'] : [];
    $selectedModuleInterests = array_values((array) ($setupStatus['module_interests'] ?? []));
    $inactiveCapabilities = array_values((array) ($setupStatus['inactive_capabilities'] ?? []));
    $tenantBlueprint = is_array($setupStatus['tenant_blueprint'] ?? null) ? (array) $setupStatus['tenant_blueprint'] : [];
    $starterModuleLabels = array_values((array) ($tenantBlueprint['starter_module_labels'] ?? []));
    $workManagementIntentLabels = array_values((array) ($tenantBlueprint['work_management_intent_labels'] ?? []));
    $workManagementModuleLabels = array_values((array) ($tenantBlueprint['work_management_module_labels'] ?? []));
    $blueprintModuleRecommendations = is_array($blueprintModuleRecommendations ?? null) ? $blueprintModuleRecommendations : [];
    $blueprintRecommendationRows = array_values((array) ($blueprintModuleRecommendations['rows'] ?? []));
    $blueprintRecommendationSummary = is_array($blueprintModuleRecommendations['summary'] ?? null) ? $blueprintModuleRecommendations['summary'] : [];
    $plansPayload = is_array($plans ?? null) ? $plans : [];
    $billingNote = (string) data_get($plansPayload, 'content.billing_note', '');

    $planCatalog = (array) config('module_catalog.plans', []);
    $addonCatalog = (array) config('module_catalog.addons', []);
    $planLabelByKey = collect($planCatalog)
        ->filter(fn ($definition) => is_array($definition))
        ->mapWithKeys(fn ($definition, $key) => [strtolower(trim((string) $key)) => (string) ($definition['display_name'] ?? $definition['label'] ?? $key)])
        ->all();
    $addonLabelByKey = collect($addonCatalog)
        ->filter(fn ($definition) => is_array($definition))
        ->mapWithKeys(fn ($definition, $key) => [strtolower(trim((string) $key)) => (string) ($definition['display_name'] ?? $definition['label'] ?? $key)])
        ->all();

    $billingReturn = strtolower(trim((string) request()->query('billing', '')));
    $onboardingComplete = (bool) ($onboardingComplete ?? false);
    $showElectricianTutorial = (bool) ($showElectricianTutorial ?? false);
    $showGuidedFirstLogin = $showElectricianTutorial && ! $onboardingComplete;
    $completionRedirectUrl = (string) ($completionRedirectUrl ?? route('dashboard', absolute: false));
    $brandAssetVersion = (string) config('everbranch.brand_assets.cache_tag', 'eb1');
    $brandMark = asset((string) config('everbranch.brand_assets.mark', 'brand/everbranch-mark.svg')).'?v='.$brandAssetVersion;
@endphp

<x-layouts::app.sidebar title="Start Here">
    <flux:main>
        <div class="fb-workflow-shell">
            @if($showGuidedFirstLogin)
                <section class="mb-6 grid gap-6 xl:grid-cols-[minmax(0,_0.82fr)_minmax(0,_1.18fr)]" data-first-login-welcome="true">
                    <article class="overflow-hidden rounded-[32px] border border-zinc-200 bg-[radial-gradient(circle_at_top_left,_rgba(236,247,244,0.98),_rgba(255,255,255,1)_48%,_rgba(247,249,250,1))] p-6 shadow-[0_36px_80px_-54px_rgba(15,23,42,0.28)] md:p-8">
                        <div class="flex items-center gap-4">
                            <div class="flex size-14 shrink-0 items-center justify-center rounded-[20px] border border-emerald-200 bg-white shadow-[0_18px_42px_-28px_rgba(16,185,129,0.55)]">
                                <img src="{{ $brandMark }}" alt="" class="size-8" />
                            </div>
                            <div>
                                <div class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-800">Everbranch</div>
                                <h1 class="mt-1 text-3xl font-semibold tracking-[-0.04em] text-zinc-950 md:text-4xl">One clear first step.</h1>
                            </div>
                        </div>

                        <p class="mt-6 max-w-xl text-base leading-7 text-zinc-600">
                            Fresh login should lead with one guided setup flow, not two competing introductions. Finish the guided setup on the right, then use the full setup status below as your ongoing source of truth.
                        </p>

                        <div class="mt-6 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl border border-zinc-200 bg-white/90 px-4 py-3">
                                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-zinc-500">Plan</div>
                                <div class="mt-2 text-lg font-semibold text-zinc-950">{{ $plan['label'] ?? 'Unknown' }}</div>
                            </div>
                            <div class="rounded-2xl border border-zinc-200 bg-white/90 px-4 py-3">
                                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-zinc-500">Setup next</div>
                                <div class="mt-2 text-lg font-semibold text-zinc-950">{{ count($availableNext) }}</div>
                            </div>
                            <div class="rounded-2xl border border-zinc-200 bg-white/90 px-4 py-3">
                                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-zinc-500">Access review</div>
                                <div class="mt-2 text-lg font-semibold text-zinc-950">{{ $setupStatus['landlord_review_label'] ?? 'Pending review' }}</div>
                            </div>
                        </div>

                        <div class="mt-6 space-y-3 text-sm leading-6 text-zinc-600">
                            <div class="rounded-2xl border border-zinc-200 bg-white/80 px-4 py-3">
                                Pick a starting template, keep only the safe modules you need now, and confirm how Everbranch should guide the workspace.
                            </div>
                            <div class="rounded-2xl border border-zinc-200 bg-white/80 px-4 py-3">
                                Billing, paid unlocks, and connector automation still stay review-driven. This first-login flow only captures setup intent safely.
                            </div>
                        </div>

                        <div class="mt-6 flex flex-wrap items-center gap-3">
                            <a href="#setup-status" class="fb-btn-soft fb-link-soft">View full setup status</a>
                            <span class="text-xs text-zinc-500">You can always come back to the setup details after the guided flow.</span>
                        </div>
                    </article>

                    <div class="min-w-0">
                        @livewire('onboarding.wizard', ['surface' => 'first-login', 'completionRedirectUrl' => $completionRedirectUrl], key('onboarding-first-login-'.$tenant->id))
                    </div>
                </section>
            @else
                <header class="fb-workflow-header">
                    <div class="fb-eyebrow">Start Here</div>
                    <h1 class="fb-title-xl">{{ $tenantName }}</h1>
                    <p class="fb-subtitle">
                        @if($showElectricianTutorial && $onboardingComplete)
                            Your first-login setup is complete. Use this page to review status, current guidance, and the next reviewed steps for your workspace.
                        @else
                            Use this page to track setup status, current guidance, and the next reviewed steps for your workspace.
                        @endif
                    </p>

                    <div class="fb-metric-grid">
                        <div class="fb-metric">
                            <div class="fb-metric-label">Plan</div>
                            <div class="fb-metric-value">{{ $plan['label'] ?? 'Unknown' }}</div>
                        </div>
                        <div class="fb-metric">
                            <div class="fb-metric-label">Active now</div>
                            <div class="fb-metric-value">{{ count($activeNow) }}</div>
                        </div>
                        <div class="fb-metric">
                            <div class="fb-metric-label">Setup next</div>
                            <div class="fb-metric-value">{{ count($availableNext) }}</div>
                        </div>
                        <div class="fb-metric">
                            <div class="fb-metric-label">Unlock next</div>
                            <div class="fb-metric-value">{{ count($purchasable) }}</div>
                        </div>
                    </div>
                </header>
            @endif

            <section id="setup-status" class="fb-panel mb-6" data-everbranch-setup-status="true">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">Setup status</div>
                        <div class="fb-panel-copy">
                            This page explains where setup stands today. Shopify is the flagship integration path, but Square, CSV, manual setup, direct workspaces, features, and mobile interest are captured for planning and Everbranch review.
                        </div>
                    </div>
                    <div class="fb-state text-xs">
                        Stage: {{ $setupStatus['setup_phase_label'] ?? 'Choosing setup path' }}
                    </div>
                </div>

                @if (session('status'))
                    <div class="mx-5 mt-4 fb-state fb-state--success text-sm">{{ session('status') }}</div>
                @endif

                <div class="fb-panel-body space-y-5">
                    <div class="fb-state text-sm" data-tenant-blueprint-profile="true">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-zinc-950">{{ $tenantBlueprint['business_template_label'] ?? 'Generic' }} setup profile</div>
                                <p class="mt-2 text-zinc-600">
                                    Everbranch uses one shared workspace structure across customers, work, money, materials/resources, and stages. Your template changes labels and setup recommendations; it does not create a separate app or activate paid modules.
                                </p>
                            </div>
                            <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">
                                {{ $tenantBlueprint['operating_mode_label'] ?? 'Not sure yet' }}
                            </span>
                        </div>

                        <dl class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                            <div class="rounded-xl border border-zinc-200 bg-white px-3 py-2">
                                <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Customer</dt>
                                <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['customer_label'] ?? 'Customer' }}</dd>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-white px-3 py-2">
                                <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Work</dt>
                                <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['work_label'] ?? 'Work' }}</dd>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-white px-3 py-2">
                                <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Money</dt>
                                <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['money_label'] ?? 'Revenue' }}</dd>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-white px-3 py-2">
                                <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Resources</dt>
                                <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['material_label'] ?? 'Resources' }}</dd>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-white px-3 py-2">
                                <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Stage</dt>
                                <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['stage_label'] ?? 'Stage' }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Data source path</div>
                                <p class="mt-1 font-semibold text-zinc-950">{{ $tenantBlueprint['data_source_preference_label'] ?? 'Not sure yet' }}</p>
                                <p class="mt-1 text-xs text-zinc-600">{{ $tenantBlueprint['primary_outcome'] ?? 'Understand customers, work, revenue, costs, and next operational steps.' }}</p>
                            </div>
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Starter recommendations</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @forelse ($starterModuleLabels as $label)
                                        <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">{{ $label }}</span>
                                    @empty
                                        <span class="text-xs text-zinc-500">No starter recommendations yet.</span>
                                    @endforelse
                                </div>
                                <p class="mt-2 text-xs text-zinc-600">Recommendations are planning guidance only. They do not install features or change access.</p>
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl border border-zinc-200 bg-white p-4" data-tenant-work-management-setup="true">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-zinc-950">Work Management Setup</div>
                                    <p class="mt-1 text-xs text-zinc-600">
                                        Project, task, assignment, communication, upload, and mobile field capture needs are requested/planned setup guidance only. Everbranch will coordinate future module activation separately.
                                    </p>
                                </div>
                                <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">
                                    {{ ($tenantBlueprint['has_work_management_intent'] ?? false) ? 'Requested' : 'Not active yet' }}
                                </span>
                            </div>

                            <dl class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                                <div>
                                    <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Project/work</dt>
                                    <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['project_label'] ?? 'Project' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Task</dt>
                                    <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['task_label'] ?? 'Task' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Assignee</dt>
                                    <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['assignee_label'] ?? 'Assignee' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Communication</dt>
                                    <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['communication_label'] ?? 'Updates' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-[0.16em] text-zinc-500">Uploads</dt>
                                    <dd class="mt-1 font-semibold text-zinc-900">{{ $tenantBlueprint['upload_label'] ?? 'Files / Photos' }}</dd>
                                </div>
                            </dl>

                            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Requested/planned</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @forelse ($workManagementIntentLabels as $label)
                                            <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">{{ $label }} requested</span>
                                        @empty
                                            <span class="text-xs text-zinc-500">No work-management setup requested yet.</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Future modules</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @forelse ($workManagementModuleLabels as $label)
                                            <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">{{ $label }}</span>
                                        @empty
                                            <span class="text-xs text-zinc-500">No future module recommendations yet.</span>
                                        @endforelse
                                    </div>
                                </div>
                            </div>

                            @if(! empty($tenantBlueprint['work_management_notes']))
                                <p class="mt-3 text-xs text-zinc-600">{{ $tenantBlueprint['work_management_notes'] }}</p>
                            @endif

                            <p class="mt-3 text-xs text-zinc-600">Not active yet: projects, tasks, assignments, comments, messaging, photo/file uploads, mobile capture, storage uploads, and notifications.</p>
                        </div>

                        @if($blueprintRecommendationRows !== [])
                            <div class="mt-4 rounded-xl border border-zinc-200 bg-white p-4" data-tenant-blueprint-module-recommendations="true">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-zinc-950">Setup feature guidance</div>
                                        <p class="mt-1 text-xs text-zinc-600">
                                            Recommended, requested, planned, and not-active-yet module families based on this setup profile.
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">{{ (int) ($blueprintRecommendationSummary['recommended'] ?? 0) }} recommended</span>
                                        <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">{{ (int) ($blueprintRecommendationSummary['requested'] ?? 0) }} requested</span>
                                        <span class="rounded-full border border-zinc-300 bg-white px-3 py-1 text-xs font-semibold text-zinc-700">{{ (int) ($blueprintRecommendationSummary['planned_or_future'] ?? 0) }} planned/future</span>
                                    </div>
                                </div>

                                <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                    @foreach(array_slice($blueprintRecommendationRows, 0, 9) as $row)
                                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                                            <div class="flex items-start justify-between gap-2">
                                                <span class="text-sm font-semibold text-zinc-900">{{ $row['label'] ?? Str::headline((string) ($row['key'] ?? 'module')) }}</span>
                                                <span class="rounded-full border border-zinc-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-zinc-600">{{ $row['display_state_label'] ?? 'Planned' }}</span>
                                            </div>
                                            <p class="mt-1 text-xs text-zinc-600">{{ $row['reason'] ?? 'Setup recommendation only.' }}</p>
                                        </div>
                                    @endforeach
                                </div>

                                <p class="mt-3 text-xs text-zinc-600">These states are display-only. They do not install features, start billing, grant access, run imports, enable uploads, activate messaging, or create mobile APIs.</p>
                            </div>
                        @endif
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <article class="fb-state text-sm">
                            <div class="fb-kpi-label">Business profile</div>
                            <div class="mt-1 font-semibold text-zinc-950">{{ $setupStatus['business_profile_label'] ?? 'Not started' }}</div>
                        </article>
                        <article class="fb-state text-sm">
                            <div class="fb-kpi-label">Import path</div>
                            <div class="mt-1 font-semibold text-zinc-950">{{ $setupStatus['import_path_label'] ?? 'Undecided' }}</div>
                        </article>
                        <article class="fb-state text-sm">
                            <div class="fb-kpi-label">Shopify connection</div>
                            <div class="mt-1 font-semibold text-zinc-950">{{ $setupStatus['shopify_connection_label'] ?? 'Not connected' }}</div>
                        </article>
                        <article class="fb-state text-sm">
                            <div class="fb-kpi-label">Mobile interest</div>
                            <div class="mt-1 font-semibold text-zinc-950">{{ $setupStatus['mobile_interest_label'] ?? 'Undecided' }}</div>
                        </article>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <article class="fb-state text-sm">
                            <div class="font-semibold text-zinc-950">Your setup path</div>
                            <p class="mt-2 text-zinc-600">{{ $setupStatus['import_path_guidance'] ?? 'Choose a primary setup path or wait for Everbranch review.' }}</p>
                        </article>
                        <article class="fb-state text-sm">
                            <div class="font-semibold text-zinc-950">Import and connection status</div>
                            <p class="mt-2 text-zinc-600">{{ $setupStatus['shopify_connection_guidance'] ?? 'Shopify status is derived from existing store connections.' }}</p>
                            <p class="mt-2 text-xs text-zinc-500">Square: {{ $setupStatus['square_label'] ?? 'Not requested' }} · CSV/manual: {{ $setupStatus['csv_manual_label'] ?? 'Not started' }}</p>
                        </article>
                        <article class="fb-state text-sm">
                            <div class="font-semibold text-zinc-950">Feature interests</div>
                            <p class="mt-2 text-zinc-600">{{ $setupStatus['module_interest_guidance'] ?? 'Feature interests do not enable or bill features by themselves.' }}</p>
                            <p class="mt-2 text-xs text-zinc-500">Selected: {{ $setupStatus['module_interest_summary'] ?? 'No feature interests have been selected yet.' }}</p>
                        </article>
                        <article class="fb-state text-sm">
                            <div class="font-semibold text-zinc-950">Mobile interest</div>
                            <p class="mt-2 text-zinc-600">{{ $setupStatus['mobile_interest_guidance'] ?? 'Mobile companion needs are captured as future planning only.' }}</p>
                        </article>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2" data-everbranch-commercial-intent="true">
                        <article class="fb-state text-sm">
                            <div class="font-semibold text-zinc-950">Plan interest</div>
                            <p class="mt-2 text-zinc-600">{{ $setupStatus['plan_selection_guidance'] ?? 'Plan interest is a planning signal only.' }}</p>
                            <p class="mt-2 text-xs text-zinc-500">Selected: {{ $setupStatus['plan_interest_label'] ?? 'Undecided' }}</p>
                        </article>
                        <article class="fb-state text-sm">
                            <div class="font-semibold text-zinc-950">Billing lane interest</div>
                            <p class="mt-2 text-zinc-600">{{ $setupStatus['billing_lane_guidance'] ?? 'Billing lane is undecided and will be reviewed by Everbranch.' }}</p>
                            <p class="mt-2 text-xs text-zinc-500">{{ $setupStatus['implementation_help_label'] ?? 'No implementation help requested' }}</p>
                        </article>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="fb-state text-sm">
                            <div class="font-semibold text-zinc-950">What Everbranch is reviewing</div>
                            <p class="mt-2 text-zinc-600">{{ $setupStatus['everbranch_review_guidance'] ?? 'Everbranch has not completed setup review yet.' }}</p>
                            <p class="mt-2 text-xs text-zinc-500">Review status: {{ $setupStatus['landlord_review_label'] ?? 'Pending review' }}</p>
                        </div>
                        <div class="fb-state text-sm">
                            <div class="font-semibold text-zinc-950">Next recommended action</div>
                            <p class="mt-2 text-zinc-600">{{ $setupStatus['next_recommended_action'] ?? 'Choose a primary setup path.' }}</p>
                            <p class="mt-2 text-xs text-zinc-500">Commercial next action: {{ $setupStatus['commercial_next_action'] ?? 'Capture plan interest without activating billing.' }}</p>
                        </div>
                    </div>

                    <div class="fb-state text-sm">
                        <div class="font-semibold text-zinc-950">What is not active yet</div>
                        <ul class="mt-2 space-y-1 text-zinc-600">
                            @forelse($inactiveCapabilities as $inactiveCapability)
                                <li>{{ $inactiveCapability }}</li>
                            @empty
                                <li>Self-service checkout, connector automation, and generic mobile app access are not active from this setup page.</li>
                            @endforelse
                        </ul>
                    </div>

                    <form method="POST" action="{{ route('app.setup-status.update', ['tenant' => (string) ($tenant->slug ?? '')]) }}" class="space-y-4">
                        @csrf
                        <div class="grid gap-4 lg:grid-cols-3">
                            <label class="block text-sm text-zinc-700">
                                Business profile status
                                <select name="business_profile_status" class="fb-input mt-2">
                                    @foreach($businessProfileStatuses as $key => $label)
                                        <option value="{{ $key }}" @selected(($setupStatus['business_profile_status'] ?? 'not_started') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Primary import path
                                <select name="import_path" class="fb-input mt-2">
                                    @foreach($importPaths as $key => $label)
                                        <option value="{{ $key }}" @selected(($setupStatus['import_path'] ?? 'undecided') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Future mobile companion
                                <select name="mobile_interest" class="fb-input mt-2">
                                    @foreach($mobileInterests as $key => $label)
                                        <option value="{{ $key }}" @selected(($setupStatus['mobile_interest'] ?? 'undecided') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-3">
                            <label class="block text-sm text-zinc-700">
                                Plan interest
                                <select name="plan_interest" class="fb-input mt-2">
                                    @foreach($planInterests as $key => $label)
                                        <option value="{{ $key }}" @selected(($setupStatus['plan_interest'] ?? 'undecided') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-xs text-zinc-500">Plan selection is intent only. It does not start billing or enable modules.</span>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Billing lane interest
                                <select name="billing_lane_interest" class="fb-input mt-2">
                                    @foreach($billingLaneInterests as $key => $label)
                                        <option value="{{ $key }}" @selected(($setupStatus['billing_lane_interest'] ?? 'undecided') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-xs text-zinc-500">Shopify Billing, Stripe, and manual invoice lanes are not active checkout flows here.</span>
                            </label>
                            <label class="flex items-start gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-sm text-zinc-700">
                                <input type="checkbox" name="implementation_help_interest" value="1" class="mt-1" @checked((bool) ($setupStatus['implementation_help_interest'] ?? false)) />
                                <span>
                                    <span class="block font-semibold text-zinc-950">Implementation help interest</span>
                                    <span class="mt-1 block text-xs text-zinc-500">Request planning help without creating quotes, invoices, subscriptions, or payment links.</span>
                                </span>
                            </label>
                        </div>

                        <label class="block text-sm text-zinc-700">
                            Commercial notes or questions
                            <textarea name="commercial_notes" rows="3" class="fb-input mt-2" placeholder="Anything Everbranch should know before reviewing your plan or billing path interest?">{{ $setupStatus['commercial_notes'] ?? '' }}</textarea>
                            <span class="mt-1 block text-xs text-zinc-500">These notes are reviewed manually. They do not activate checkout, billing, subscriptions, quotes, invoices, or feature access.</span>
                        </label>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="block text-sm text-zinc-700">
                                Square status
                                <select name="square_status" class="fb-input mt-2">
                                    @foreach($squareStatuses as $key => $label)
                                        <option value="{{ $key }}" @selected(($setupStatus['square_status'] ?? 'not_requested') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-xs text-zinc-500">Square is captured as interest/manual setup unless a later connector flow is implemented.</span>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                CSV/manual status
                                <select name="csv_manual_status" class="fb-input mt-2">
                                    @foreach($csvManualStatuses as $key => $label)
                                        <option value="{{ $key }}" @selected(($setupStatus['csv_manual_status'] ?? 'not_started') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-xs text-zinc-500">CSV and manual import remain safe fallback/setup paths.</span>
                            </label>
                        </div>

                        @if($moduleInterestOptions !== [])
                            <fieldset class="rounded-2xl border border-zinc-200 bg-white p-4">
                                <legend class="px-1 text-sm font-semibold text-zinc-950">Feature interests</legend>
                                <p class="mt-1 text-xs text-zinc-500">
                                    These are planning signals only. Selecting a feature here does not install it, enable access, or start paid feature activation.
                                </p>
                                <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                    @foreach($moduleInterestOptions as $key => $label)
                                        <label class="flex items-center gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-700">
                                            <input type="checkbox" name="module_interests[]" value="{{ $key }}" @checked(in_array($key, $selectedModuleInterests, true)) />
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-zinc-500">Billing checkout is not active from this setup status. Everbranch review remains manual.</p>
                            <button type="submit" class="fb-btn fb-btn-primary">Save setup status</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="fb-panel mb-6">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">What happens next</div>
                        <div class="fb-panel-copy">{{ $billingNote !== '' ? $billingNote : 'Billing status, plan interest, and setup guidance are unified here.' }}</div>
                    </div>
                </div>
                <div class="fb-panel-body">
                    <x-tenancy.commercial-lifecycle-summary
                        :commercial-summary="$commercialSummary"
                        :billing-interest="$billingInterest"
                        :billing-next-step="$billingNextStep"
                        :plan-label-by-key="$planLabelByKey"
                        :addon-label-by-key="$addonLabelByKey"
                        :billing-return="$billingReturn"
                    >
                        <div class="flex flex-wrap gap-2">
                            @if(is_array($billingNextStep['cta_route'] ?? null) && filled($billingNextStep['cta_route']['name'] ?? null))
                                <form method="POST" action="{{ route((string) $billingNextStep['cta_route']['name']) }}">
                                    @csrf
                                    <button type="submit" class="fb-btn fb-btn-primary">
                                        {{ $billingNextStep['cta_label'] ?? 'Continue' }}
                                    </button>
                                </form>
                            @elseif(filled($billingNextStep['cta_url'] ?? null))
                                <a href="{{ (string) $billingNextStep['cta_url'] }}" class="fb-btn fb-btn-primary">
                                    {{ $billingNextStep['cta_label'] ?? 'Continue' }}
                                </a>
                            @endif
                            <a href="{{ route('platform.plans') }}" class="fb-btn fb-btn-secondary">Compare plans</a>
                            <a href="{{ route('platform.contact', ['intent' => 'billing']) }}" class="fb-btn fb-btn-secondary">Talk to sales</a>
                        </div>
                    </x-tenancy.commercial-lifecycle-summary>
                </div>
            </section>

            <div class="fb-workflow-grid">
                <section class="min-w-0">
                    <div class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Available Now</div>
                                <div class="fb-panel-copy">Modules you can use today.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-2">
                            @forelse(array_slice($activeNow, 0, 8) as $module)
                                <x-tenancy.module-state-card :module-state="$module" />
                            @empty
                                <div class="fb-state text-sm">No active modules are visible yet.</div>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="min-w-0">
                    <div class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Setup Next</div>
                                <div class="fb-panel-copy">Included modules that still need setup.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-2">
                            @forelse(array_slice($availableNext, 0, 8) as $module)
                                <x-tenancy.module-state-card :module-state="$module" />
                            @empty
                                <div class="fb-state text-sm">Included modules are already configured.</div>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="min-w-0">
                    <div class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Unlock Next</div>
                                <div class="fb-panel-copy">Available upgrades based on your current plan.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-2">
                            @forelse(array_slice($purchasable, 0, 8) as $module)
                                <x-tenancy.module-upgrade-prompt
                                    :module-state="$module"
                                    store-route="marketing.modules"
                                    plans-route="platform.plans"
                                    contact-route="platform.contact"
                                />
                            @empty
                                <div class="fb-state text-sm">No upgrade candidates are currently highlighted.</div>
                            @endforelse
                        </div>
                    </div>
                </section>
            </div>

        </div>
    </flux:main>
</x-layouts::app.sidebar>
