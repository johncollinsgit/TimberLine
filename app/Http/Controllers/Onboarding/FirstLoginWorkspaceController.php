<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Onboarding\FirstLoginWorkspaceProvisioner;
use App\Services\Tenancy\TenantBlueprintProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

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

        // New personable, popup-based flow (feature-flagged); falls back to the
        // original full-page guide while the flag is off.
        if (config('features.first_login_modal')) {
            return response()->view('onboarding.first-login-workspace', [
                'workspaceName' => $this->defaultWorkspaceName($user),
                'businessTypes' => $this->businessTypeCards($blueprintService),
                'teamSizes' => $this->teamSizeOptions(),
                'hardestParts' => $this->hardestPartOptions(),
                'toolOptions' => $this->moduleOptions(),
                'recommendedTools' => $this->toolRecommendationMap(),
            ]);
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

    public function store(Request $request, FirstLoginWorkspaceProvisioner $provisioner): RedirectResponse
    {
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
            'owner_need' => ['nullable', 'array', 'max:4'],
            'owner_need.*' => ['string', Rule::in(array_keys($this->ownerNeedOptions()))],
            'start_path' => ['required', 'string', Rule::in(['guided', 'self'])],
            'appointment_slot' => [
                'nullable',
                'string',
                Rule::in(array_keys($this->appointmentSlots())),
                Rule::notIn($this->bookedAppointmentSlots()),
            ],
            'appointment_name' => ['nullable', 'string', 'max:120'],
            'appointment_email' => ['nullable', 'email', 'max:255'],
            'appointment_phone' => ['nullable', 'string', 'max:40'],
            'module_choices' => ['nullable', 'array', 'max:20'],
            'module_choices.*' => ['string', Rule::in(array_keys($this->moduleOptions()))],
        ]);

        $workspaceName = trim((string) $validated['workspace_name']);
        $selectedModuleKeys = $this->normalizeSelectedModules((array) ($validated['module_choices'] ?? []));
        $guideAnswers = $this->guideAnswerPayload($validated, $selectedModuleKeys);

        try {
            $result = $provisioner->provision(
                $user,
                $workspaceName,
                (string) $validated['template_key'],
                $selectedModuleKeys,
                $guideAnswers
            );
        } catch (RuntimeException) {
            return back()
                ->withErrors(['workspace_name' => 'Workspace creation did not complete. Please try again.'])
                ->withInput();
        }

        $request->session()->put('tenant_id', $result['tenant_id']);

        return redirect()
            ->to(route('dashboard', ['tenant' => $result['tenant_slug']], false))
            ->with('status', 'Workspace created.');
    }

    /**
     * Domain-neutral business types for the popup, sourced from config-driven
     * blueprint templates (candle maker, landscaping, electrician, law, apparel,
     * generic, custom) so it works for any business without code changes.
     *
     * @return array<int,array{key:string,label:string,blurb:string}>
     */
    protected function businessTypeCards(TenantBlueprintProfileService $blueprintService): array
    {
        $blurbs = [
            'generic' => 'A clean, flexible workspace for any small business.',
            'candle_maker' => 'Orders, batches, products, and the makers behind them.',
            'landscaping' => 'Jobs, crews, properties, and seasonal work.',
            'electrician' => 'Jobs, estimates, parts, and scheduling for the field.',
            'law' => 'Clients, matters, and the work that moves them forward.',
            'apparel' => 'Products, orders, and the customers who love them.',
            'custom' => 'Not sure yet — we will shape it around how you work.',
        ];

        $cards = [];
        foreach ($blueprintService->templateOptions() as $key => $label) {
            $cards[] = [
                'key' => (string) $key,
                'label' => (string) $label,
                'blurb' => (string) ($blurbs[$key] ?? 'Shaped around how your business works.'),
            ];
        }

        return $cards;
    }

    /**
     * Light per-business-type "popular starting point" highlight for the tool
     * picker. Display-only guidance; picks are recorded as interests, not enabled.
     *
     * @return array<string,array<int,string>>
     */
    protected function toolRecommendationMap(): array
    {
        $common = ['customers', 'billing', 'messaging', 'reporting'];

        return [
            'generic' => $common,
            'custom' => $common,
            'candle_maker' => ['customers', 'billing', 'shopify', 'campaigns', 'reporting'],
            'apparel' => ['customers', 'billing', 'shopify', 'campaigns', 'reporting'],
            'landscaping' => ['customers', 'field_service', 'billing', 'messaging', 'reporting'],
            'electrician' => ['customers', 'field_service', 'billing', 'messaging', 'reporting'],
            'law' => ['customers', 'billing', 'messaging', 'reporting'],
        ];
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
     * Accepts both the original trades presets and the config-driven blueprint
     * template keys (+ their aliases), so the popup and the fallback both validate.
     *
     * @return array<int,string>
     */
    protected function allowedTemplateKeys(): array
    {
        $legacy = ['trades', 'trades_electrical', 'trades_plumbing', 'home_residential', 'electrician'];

        $blueprintKeys = [];
        foreach ((array) config('tenant_blueprints.templates', []) as $key => $definition) {
            $blueprintKeys[] = (string) $key;
            foreach ((array) data_get($definition, 'aliases', []) as $alias) {
                $blueprintKeys[] = Str::slug(strtolower(trim((string) $alias)), '_');
            }
        }

        return array_values(array_unique(array_filter(array_merge($legacy, $blueprintKeys))));
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
        $ownerNeeds = array_values(array_map('strval', (array) ($validated['owner_need'] ?? [])));
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
}
