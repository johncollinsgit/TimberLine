<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Onboarding\TenantOnboardingBlueprintStore;
use App\Services\Onboarding\TenantSetupStatusService;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\TenantBlueprintProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FirstLoginWorkspaceController extends Controller
{
    public function show(TenantBlueprintProfileService $blueprintService): Response|RedirectResponse
    {
        $user = request()->user();
        abort_unless($user instanceof User, 403);

        if ($user->tenants()->exists()) {
            return redirect()->to(route('dashboard', absolute: false));
        }

        if (strtolower(trim((string) $user->role)) === 'platform_admin') {
            return redirect()->to(route('landlord.dashboard', absolute: false));
        }

        $options = $blueprintService->formOptions();

        return response()->view('onboarding.first-login', [
            'authTenantPresentation' => [
                'tenant_label' => 'Everbranch Work',
                'hero_title' => 'Tell us what your business actually needs',
                'hero_subtitle' => 'A guided setup for owners who have enough tabs open already.',
                'hero_tagline' => 'Small business setup',
            ],
            'workspaceName' => $this->defaultWorkspaceName($user),
            'templateOptions' => $this->templateCards($options['business_templates'] ?? []),
            'defaultTemplateKey' => 'trades_electrical',
            'guideQuestions' => $this->guideQuestions(),
            'guideSlides' => $this->guideSlides(),
            'moduleOptions' => $this->moduleOptions(),
            'appointmentSlots' => $this->appointmentSlots(),
            'bookedAppointmentSlots' => $this->bookedAppointmentSlots(),
        ]);
    }

    public function store(
        Request $request,
        TenantBlueprintProfileService $blueprintService,
        LandlordCommercialConfigService $commercialService,
        TenantSetupStatusService $setupStatusService,
        TenantOnboardingBlueprintStore $blueprintStore
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if ($user->tenants()->exists()) {
            return redirect()->to(route('dashboard', absolute: false));
        }

        if (strtolower(trim((string) $user->role)) === 'platform_admin') {
            return redirect()->to(route('landlord.dashboard', absolute: false));
        }

        $validated = $request->validate([
            'workspace_name' => ['required', 'string', 'max:120'],
            'template_key' => ['required', 'string', Rule::in($this->allowedTemplateKeys())],
            'hardest_part' => ['required', 'string', Rule::in(array_keys($this->hardestPartOptions()))],
            'team_size' => ['required', 'string', Rule::in(array_keys($this->teamSizeOptions()))],
            'owner_need' => ['required', 'array', 'min:1', 'max:4'],
            'owner_need.*' => ['required', 'string', Rule::in(array_keys($this->ownerNeedOptions()))],
            'start_path' => ['required', 'string', Rule::in(['guided', 'self'])],
            'appointment_slot' => [
                'nullable',
                'required_if:start_path,guided',
                'string',
                Rule::in(array_keys($this->appointmentSlots())),
                Rule::notIn($this->bookedAppointmentSlots()),
            ],
            'appointment_name' => ['nullable', 'required_if:start_path,guided', 'string', 'max:120'],
            'appointment_email' => ['nullable', 'required_if:start_path,guided', 'email', 'max:255'],
            'appointment_phone' => ['nullable', 'string', 'max:40'],
            'module_choices' => ['nullable', 'array', 'max:20'],
            'module_choices.*' => ['required', 'string', Rule::in(array_keys($this->moduleOptions()))],
        ]);

        $workspaceName = trim((string) $validated['workspace_name']);
        $selectedModuleKeys = $this->normalizeSelectedModules((array) ($validated['module_choices'] ?? []));
        $guideAnswers = $this->guideAnswerPayload($validated, $selectedModuleKeys);
        $templateKey = $blueprintService->blueprintFromInput([
            'business_template' => (string) $validated['template_key'],
        ])['business_template'] ?? (string) $validated['template_key'];

        $tenantId = null;
        $tenantSlug = null;

        DB::transaction(function () use (
            $user,
            $workspaceName,
            $templateKey,
            $blueprintService,
            $commercialService,
            $setupStatusService,
            $blueprintStore,
            &$tenantId,
            &$tenantSlug,
            $guideAnswers,
            $selectedModuleKeys
        ): void {
            if (! in_array((string) $user->role, ['admin', 'manager', 'marketing_manager', 'platform_admin'], true)) {
                $user->forceFill([
                    'role' => 'admin',
                    'is_active' => true,
                    'approved_at' => $user->approved_at ?? now(),
                ])->save();
            }

            if (Schema::hasColumn('users', 'onboarding_guide_answers')) {
                $user->forceFill([
                    'onboarding_guide_answers' => $guideAnswers,
                ])->save();
            }

            $tenant = Tenant::query()->create([
                'name' => $workspaceName,
                'slug' => $this->uniqueTenantSlug($workspaceName),
            ]);
            $tenantId = (int) $tenant->id;
            $tenantSlug = (string) $tenant->slug;

            $tenant->users()->syncWithoutDetaching([
                (int) $user->id => [
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $profile = $commercialService->assignTenantPlan(
                tenantId: $tenantId,
                planKey: 'base',
                operatingMode: 'direct',
                source: 'self_serve_first_login',
                actorId: (int) $user->id
            );

            $blueprint = $blueprintService->blueprintFromInput([
                'business_template' => $templateKey,
                'operating_mode' => 'direct',
                'data_source_preference' => 'manual',
            ]);

            $blueprint['blueprint_review_status'] = 'reviewed';
            $blueprint['blueprint_review_status_label'] = 'Reviewed';
            $blueprint['blueprint_reviewed_by'] = (int) $user->id;
            $blueprint['blueprint_reviewed_at'] = now()->toIso8601String();

            $setupStatus = $setupStatusService->forTenant($tenant);
            $blueprintService->applyBlueprint($tenant, $profile->refresh(), $setupStatus, $blueprint, 'production', true);

            $starterModules = array_values((array) ($blueprint['starter_modules'] ?? []));
            if (in_array('messaging', $starterModules, true)) {
                $commercialService->setTenantModuleState(
                    tenantId: $tenantId,
                    moduleKey: 'messaging',
                    enabledOverride: true,
                    setupStatus: 'configured',
                    actorId: (int) $user->id
                );
            }

            foreach ($selectedModuleKeys as $moduleKey) {
                if (is_array(config('module_catalog.modules.'.$moduleKey))) {
                    $commercialService->setTenantModuleState(
                        tenantId: $tenantId,
                        moduleKey: $moduleKey,
                        enabledOverride: true,
                        setupStatus: 'not_started',
                        actorId: (int) $user->id
                    );
                }
            }

            $finalPayload = $this->finalBlueprintPayload($tenant, $templateKey, $blueprint, $guideAnswers, $selectedModuleKeys);
            $blueprintStore->finalize($tenantId, $finalPayload, (int) $user->id, [
                'source' => 'self_serve_first_login',
            ]);
        });

        if (! is_int($tenantId) || $tenantId <= 0) {
            return back()->withErrors(['workspace_name' => 'Workspace creation did not complete. Please try again.'])->withInput();
        }

        $request->session()->put('tenant_id', $tenantId);

        return redirect()
            ->to(route('dashboard', ['tenant' => $tenantSlug], false))
            ->with('status', 'Workspace created.');
    }

    /**
     * @param  array<string,string>  $options
     * @return array<int,array{key:string,label:string,description:string}>
     */
    protected function templateCards(array $options): array
    {
        return [
            [
                'key' => 'trades',
                'label' => (string) ($options['trades'] ?? 'Trades'),
                'description' => 'General trades workspace',
            ],
            [
                'key' => 'trades_electrical',
                'label' => (string) ($options['trades_electrical'] ?? 'Electrical'),
                'description' => 'Electrical preset',
            ],
            [
                'key' => 'trades_plumbing',
                'label' => (string) ($options['trades_plumbing'] ?? 'Plumbing'),
                'description' => 'Plumbing preset',
            ],
            [
                'key' => 'home_residential',
                'label' => (string) ($options['home_residential'] ?? 'Home / Residential'),
                'description' => 'Home jobs and follow-up',
            ],
        ];
    }

    protected function defaultWorkspaceName(User $user): string
    {
        $name = trim((string) $user->name);

        return $name !== '' ? $name : 'My workspace';
    }

    /**
     * @return array<int,string>
     */
    protected function allowedTemplateKeys(): array
    {
        return ['trades', 'trades_electrical', 'trades_plumbing', 'home_residential', 'electrician'];
    }

    /**
     * @param  array<int,mixed>  $selectedModules
     * @return array<int,string>
     */
    protected function normalizeSelectedModules(array $selectedModules): array
    {
        $allowed = array_keys($this->moduleOptions());

        return array_values(array_intersect(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $selectedModules
        ))), $allowed));
    }

    /**
     * @param  array<string,mixed>  $validated
     * @param  array<int,string>  $selectedModuleKeys
     * @return array<string,mixed>
     */
    protected function guideAnswerPayload(array $validated, array $selectedModuleKeys): array
    {
        $hardestPart = (string) $validated['hardest_part'];
        $teamSize = (string) $validated['team_size'];
        $ownerNeeds = array_values(array_map('strval', (array) $validated['owner_need']));
        $startPath = (string) $validated['start_path'];
        $appointmentSlot = trim((string) ($validated['appointment_slot'] ?? ''));
        $appointmentSlots = $this->appointmentSlots();

        return [
            'version' => 1,
            'completed_at' => now()->toIso8601String(),
            'questions' => [
                'hardest_part' => [
                    'value' => $hardestPart,
                    'label' => (string) data_get($this->hardestPartOptions(), $hardestPart.'.label', $hardestPart),
                ],
                'team_size' => [
                    'value' => $teamSize,
                    'label' => (string) data_get($this->teamSizeOptions(), $teamSize, $teamSize),
                ],
                'owner_need' => collect($ownerNeeds)
                    ->map(fn (string $need): array => [
                        'value' => $need,
                        'label' => (string) data_get($this->ownerNeedOptions(), $need.'.label', $need),
                    ])
                    ->values()
                    ->all(),
            ],
            'start_path' => $startPath,
            'appointment' => $startPath === 'guided' ? [
                'slot' => $appointmentSlot,
                'slot_label' => (string) ($appointmentSlots[$appointmentSlot] ?? $appointmentSlot),
                'name' => trim((string) ($validated['appointment_name'] ?? '')),
                'email' => strtolower(trim((string) ($validated['appointment_email'] ?? ''))),
                'phone' => trim((string) ($validated['appointment_phone'] ?? '')),
            ] : null,
            'selected_modules' => collect($selectedModuleKeys)
                ->map(fn (string $moduleKey): array => [
                    'key' => $moduleKey,
                    'label' => (string) data_get($this->moduleOptions(), $moduleKey.'.label', Str::headline($moduleKey)),
                    'description' => (string) data_get($this->moduleOptions(), $moduleKey.'.description', ''),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,array{label:string,description:string}>
     */
    protected function hardestPartOptions(): array
    {
        return [
            'too_many_apps' => [
                'label' => 'Too many apps, tabs, and passwords',
                'description' => 'The classic "which login did I use for that?" situation.',
            ],
            'keeping_up_with_customers' => [
                'label' => 'Keeping up with customers',
                'description' => 'Messages, follow-ups, notes, and the occasional "I swear I replied."',
            ],
            'billing_and_cash_flow' => [
                'label' => 'Billing and cash flow',
                'description' => 'Invoices, payments, subscriptions, and knowing what is actually owed.',
            ],
            'team_and_work_tracking' => [
                'label' => 'Team and work tracking',
                'description' => 'Who is doing what, when, and whether the sticky note survived.',
            ],
            'marketing_and_growth' => [
                'label' => 'Marketing and growth',
                'description' => 'Finding the right customers without turning into a full-time ad manager.',
            ],
            'reports_and_decisions' => [
                'label' => 'Reports and decisions',
                'description' => 'Getting answers without building a spreadsheet monument.',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function teamSizeOptions(): array
    {
        return [
            'solo' => 'Just me',
            '2_5' => '2-5 people',
            '6_15' => '6-15 people',
            '16_50' => '16-50 people',
            '50_plus' => '50+ people',
            'depends_on_the_day' => 'Depends who actually shows up today',
        ];
    }

    /**
     * @return array<string,array{label:string,description:string}>
     */
    protected function ownerNeedOptions(): array
    {
        return [
            'one_dashboard' => [
                'label' => 'One dashboard',
                'description' => 'Put the scattered branches of the business in one place.',
            ],
            'custom_app' => [
                'label' => 'A custom app',
                'description' => 'Something built around how the business already works.',
            ],
            'customer_followup' => [
                'label' => 'Customer follow-up',
                'description' => 'Keep leads, replies, reminders, and notes from drifting away.',
            ],
            'billing_help' => [
                'label' => 'Billing help',
                'description' => 'Invoices, payments, plans, and less billing fog.',
            ],
            'team_visibility' => [
                'label' => 'Team visibility',
                'description' => 'Know what work is moving and what needs a nudge.',
            ],
            'less_software_chaos' => [
                'label' => 'Less software chaos',
                'description' => 'Fewer mystery tools, fewer mystery charges, fewer mystery passwords.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function guideQuestions(): array
    {
        return [
            'hardest_parts' => $this->hardestPartOptions(),
            'team_sizes' => $this->teamSizeOptions(),
            'owner_needs' => $this->ownerNeedOptions(),
        ];
    }

    /**
     * @return array<int,array{headline:string,body:string,visual:string}>
     */
    protected function guideSlides(): array
    {
        return [
            [
                'headline' => 'We know the 10-app shuffle.',
                'body' => 'We are small business owners too. Ten apps, ten bills, ten logins, and somehow one more thing to check before lunch.',
                'visual' => '🧾',
            ],
            [
                'headline' => 'Business got complicated. Tools should not.',
                'body' => 'Everbranch simplifies the mess, but not by forcing you into a generic box. We shape tools around how your business actually runs.',
                'visual' => '🧭',
            ],
            [
                'headline' => 'All your branches, one dashboard.',
                'body' => 'Invoicing, supplies, customers, employees, messaging, and the daily work all come back to one simple operating view.',
                'visual' => '🌿',
            ],
            [
                'headline' => 'Stop auditioning apps that almost fit.',
                'body' => 'Instead of trying another tool that solves 70% of the problem, let us design the missing 30% with you.',
                'visual' => '🛠',
            ],
            [
                'headline' => 'Custom does not have to mean confusing.',
                'body' => 'We start with useful modules, then adapt labels, workflows, and dashboards to match the way your team talks and works.',
                'visual' => '📌',
            ],
            [
                'headline' => 'Click what matters. We will map the first build.',
                'body' => 'Choose the apps and workflows that fit your business now. Your selections become the starting point for your Everbranch setup.',
                'visual' => '🚀',
            ],
        ];
    }

    /**
     * @return array<string,array{label:string,description:string,icon:string}>
     */
    protected function moduleOptions(): array
    {
        return [
            'website' => ['label' => 'Website', 'description' => 'Public pages, forms, and service info.', 'icon' => '🌐'],
            'mobile_connection' => ['label' => 'Mobile app', 'description' => 'Phone-friendly workflows for owners and field teams.', 'icon' => '📱'],
            'lead_capture' => ['label' => 'Sales conversations', 'description' => 'Lead capture, intake, and follow-up.', 'icon' => '💬'],
            'billing' => ['label' => 'Billing', 'description' => 'Invoices, subscriptions, and payment handoffs.', 'icon' => '💳'],
            'customers' => ['label' => 'Customers', 'description' => 'Profiles, notes, contact history, and context.', 'icon' => '👥'],
            'field_service' => ['label' => 'Jobs / work orders', 'description' => 'Jobs, tasks, materials, photos, and vehicles.', 'icon' => '🧰'],
            'messaging' => ['label' => 'Messaging', 'description' => 'Direct and group customer messaging.', 'icon' => '✉️'],
            'email' => ['label' => 'Email', 'description' => 'Email channel setup and templates.', 'icon' => '📨'],
            'sms' => ['label' => 'Text messaging', 'description' => 'SMS reminders, outreach, and replies.', 'icon' => '💬'],
            'reporting' => ['label' => 'Reporting', 'description' => 'Numbers you can actually act on.', 'icon' => '📊'],
            'integrations' => ['label' => 'Integrations', 'description' => 'Connectors and sync readiness.', 'icon' => '🔌'],
            'quickbooks' => ['label' => 'QuickBooks', 'description' => 'Accounting sync planning.', 'icon' => '📒'],
            'shopify' => ['label' => 'Shopify', 'description' => 'Storefront, customer, and order workflows.', 'icon' => '🛍'],
            'square' => ['label' => 'Square', 'description' => 'POS and commerce intake.', 'icon' => '◼'],
            'workflow_automations' => ['label' => 'Automations', 'description' => 'Trigger/action workflows for repeated work.', 'icon' => '⚙️'],
            'ai' => ['label' => 'AI assistant', 'description' => 'Guided opportunities and drafted next steps.', 'icon' => '✨'],
            'uploads' => ['label' => 'Imports', 'description' => 'CSV and manual data import.', 'icon' => '⬆️'],
            'notifications' => ['label' => 'Notifications', 'description' => 'Alerts, reminders, and status nudges.', 'icon' => '🔔'],
            'campaigns' => ['label' => 'Campaigns', 'description' => 'Audience and lifecycle marketing.', 'icon' => '📣'],
            'settings' => ['label' => 'Settings', 'description' => 'Business-specific labels and configuration.', 'icon' => '🎛'],
        ];
    }

    protected function uniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'workspace';
        $slug = $base;
        $index = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index;
            $index++;
        }

        return $slug;
    }

    /**
     * @return array<string,string>
     */
    protected function appointmentSlots(): array
    {
        $slots = [];
        $start = now()->addDay()->startOfDay();

        for ($day = 1; $day <= 8; $day++) {
            $date = $start->copy()->addDays($day);

            if ($date->isWeekend()) {
                continue;
            }

            foreach ([10, 14, 16] as $hour) {
                $slot = $date->copy()->setTime($hour, 0);
                $key = $slot->format('Y-m-d\TH:i');
                $slots[$key] = $slot->format('D, M j \a\t g:i A');
            }
        }

        return array_slice($slots, 0, 12, true);
    }

    /**
     * @return array<int,string>
     */
    protected function bookedAppointmentSlots(): array
    {
        if (! Schema::hasColumn('users', 'onboarding_guide_answers')) {
            return [];
        }

        return User::query()
            ->whereNotNull('onboarding_guide_answers')
            ->get(['id', 'onboarding_guide_answers'])
            ->map(function (User $user): string {
                $answers = is_array($user->onboarding_guide_answers) ? $user->onboarding_guide_answers : [];

                return trim((string) data_get($answers, 'appointment.slot', ''));
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @return array<string,mixed>
     */
    protected function finalBlueprintPayload(Tenant $tenant, string $templateKey, array $blueprint, array $guideAnswers, array $selectedModuleKeys): array
    {
        $selectedModules = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($blueprint['starter_modules'] ?? [])
        ))));
        $selectedModules = array_values(array_unique(array_merge($selectedModules, $selectedModuleKeys)));
        $selectedModules = array_values(array_intersect($selectedModules, [
            'customers',
            'field_service',
            'reporting',
            'messaging',
            'lead_capture',
            'email',
            'sms',
            'integrations',
            'uploads',
            'shopify',
            'quickbooks',
            'square',
            'workflow_automations',
            'ai',
            'notifications',
            'campaigns',
            'settings',
            'mobile_connection',
        ]));

        return [
            'rail' => 'direct',
            'account_mode' => 'production',
            'template_key' => $templateKey,
            'desired_outcome_first' => (string) ($blueprint['primary_outcome'] ?? 'Launch the workspace'),
            'selected_modules' => $selectedModules,
            'data_source' => 'manual',
            'setup_preferences' => [
                'label_overrides' => [],
                'client_brand' => [
                    'display_name' => (string) $tenant->name,
                    'logo_alt' => (string) $tenant->name,
                ],
            ],
            'mobile_intent' => [
                'needs_mobile_access' => true,
                'mobile_roles_needed' => ['field_staff'],
                'mobile_jobs_requested' => ['prioritize_work', 'update_production_progress', 'photos_uploads', 'quick_notes'],
                'mobile_priority' => 'high',
            ],
            'first_login_guide' => $guideAnswers,
        ];
    }
}
