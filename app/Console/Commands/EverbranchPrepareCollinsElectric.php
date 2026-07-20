<?php

namespace App\Console\Commands;

use App\Models\Agreement;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceReminderSetting;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Onboarding\TenantOnboardingBlueprintStore;
use App\Services\Onboarding\TenantSetupStatusService;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\TenantBlueprintProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EverbranchPrepareCollinsElectric extends Command
{
    protected $signature = 'everbranch:prepare-collins-electric
        {--john-email=johncollinsemail@gmail.com : Admin user to attach for mobile testing}
        {--onboarding-price=299 : One-time completed launch foundation and onboarding price in dollars}
        {--launch-partner-price=59 : Monthly price for the first six billing cycles in dollars}
        {--standard-price=149 : Monthly price beginning with billing cycle seven in dollars}
        {--seed-demo-job : Create a sample electrician job when none exist}';

    protected $description = 'Prepare the guided Collins Electric launch workspace and attach John for mobile testing.';

    public function handle(
        LandlordCommercialConfigService $commercialService,
        TenantSetupStatusService $setupStatusService,
        TenantBlueprintProfileService $blueprintService,
        TenantOnboardingBlueprintStore $blueprintStore,
        AgreementManagementService $agreements,
    ): int {
        $johnEmail = strtolower(trim((string) $this->option('john-email')));
        if (! filter_var($johnEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid --john-email value.');

            return self::FAILURE;
        }

        $prices = collect(['onboarding' => $this->option('onboarding-price'), 'launch_partner' => $this->option('launch-partner-price'), 'standard' => $this->option('standard-price')])
            ->map(fn ($value) => is_numeric($value) && (float) $value >= 0 ? (int) round((float) $value * 100) : null);
        if ($prices->contains(null)) {
            $this->error('Agreement prices must be non-negative dollar amounts.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($johnEmail, $commercialService, $setupStatusService, $blueprintService, $blueprintStore): array {
            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => 'collins-electric'],
                ['name' => 'Collins Electric']
            );
            $tenant->forceFill(['name' => 'Collins Electric'])->save();

            $user = User::query()->firstOrCreate(
                ['email' => $johnEmail],
                [
                    'name' => 'John Collins',
                    'password' => Hash::make(Str::random(40)),
                    'role' => 'admin',
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'requested_via' => 'collins_electric_launch',
                    'approval_requested_at' => now(),
                    'approved_at' => now(),
                ]
            );

            $role = strtolower(trim((string) ($user->role ?: 'admin')));
            if (! in_array($role, ['admin', 'manager', 'marketing_manager', 'platform_admin'], true)) {
                $role = 'admin';
            }

            $user->forceFill([
                'name' => $user->name ?: 'John Collins',
                'role' => $role,
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'approved_at' => $user->approved_at ?? now(),
            ])->save();

            $tenant->users()->syncWithoutDetaching([
                (int) $user->id => [
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $profile = $commercialService->assignTenantPlan(
                tenantId: (int) $tenant->id,
                planKey: 'base',
                operatingMode: 'direct',
                source: 'collins_electric_guided_launch',
                actorId: (int) $user->id
            );
            $commercialService->setTenantModuleState((int) $tenant->id, 'equipment_maintenance', true, 'configured', (int) $user->id);
            $commercialService->setTenantModuleEntitlement((int) $tenant->id, 'equipment_maintenance', [
                'availability_status' => 'available',
                'enabled_status' => 'enabled',
                'billing_status' => 'custom_contract',
                'entitlement_source' => 'collins_electric_launch_partner',
                'notes' => 'Collins Electric generator maintenance launch module. SMS remains separately gated by verified provider and consent readiness.',
                'metadata' => ['launch_scope' => 'collins_electric', 'sms_requires_verified_readiness' => true],
            ], (int) $user->id);
            TenantAccessAddon::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'addon_key' => 'messaging_usage'],
                [
                    'enabled' => true,
                    'source' => 'collins_electric_launch_partner_agreement',
                    'metadata' => [
                        'billing_mode' => 'postpaid_invoice',
                        'included_units' => ['sms' => 250, 'email' => 1000],
                        'overage_rates_micros' => ['sms' => 50000, 'email' => 5000],
                        'pricing_version' => 'collins-electric-2026-07-20-v2',
                        'invoice_timing' => 'monthly_in_arrears',
                        'agreement_template_key' => Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES,
                        'activation_requirement' => 'accepted_collins_electric_agreement',
                    ],
                ]
            );

            $setupStatus = $setupStatusService->forTenant($tenant);
            $setupStatusPayload = $this->setupStatusPayload($setupStatus);
            $setupStatus->forceFill($setupStatusPayload)->save();

            $blueprint = $blueprintService->blueprintFromInput([
                'business_template' => 'electrician',
                'operating_mode' => 'direct',
                'data_source_preference' => 'manual',
            ]);
            $blueprint['blueprint_review_status'] = 'reviewed';
            $blueprint['blueprint_review_status_label'] = 'Reviewed';
            $blueprint['blueprint_reviewed_by'] = (int) $user->id;
            $blueprint['blueprint_reviewed_at'] = now()->toIso8601String();
            $blueprintService->applyBlueprint($tenant, $profile->refresh(), $setupStatus->refresh(), $blueprint, 'production', true);
            $setupStatus = $setupStatus->refresh();
            $setupStatus->forceFill($setupStatusPayload)->save();

            $blueprintStore->finalize((int) $tenant->id, [
                'rail' => 'direct',
                'account_mode' => 'production',
                'template_key' => 'electrician',
                'desired_outcome_first' => 'Launch Collins Electric field-service workspace',
                'selected_modules' => ['customers', 'field_service', 'messaging', 'reporting'],
                'data_source' => 'manual',
                'setup_preferences' => [
                    'client_brand' => [
                        'display_name' => 'Collins Electric',
                        'logo_alt' => 'Collins Electric',
                    ],
                ],
                'mobile_intent' => [
                    'needs_mobile_access' => true,
                    'mobile_roles_needed' => ['field_staff'],
                    'mobile_jobs_requested' => ['prioritize_work', 'update_production_progress', 'photos_uploads', 'quick_notes'],
                    'mobile_priority' => 'high',
                ],
            ], (int) $user->id, ['source' => 'collins_electric_guided_launch']);

            $reminders = FieldServiceReminderSetting::query()->firstOrCreate(
                ['tenant_id' => (int) $tenant->id],
                [
                    'enabled' => true,
                    'channel' => 'sms',
                    'cadence' => 'daily',
                    'send_time' => '08:00',
                    'timezone' => 'America/New_York',
                    'provider_status' => 'not_verified',
                    'customer_copy' => 'Reminder: we have upcoming electrical work scheduled with Collins Electric.',
                    'internal_notes' => 'SMS requested for Collins Electric. Do not enable sends until provider, sender, consent, opt-out, quiet-hours, and delivery readiness are verified.',
                ]
            );
            $reminders->forceFill([
                'enabled' => true,
                'channel' => 'sms',
                'customer_copy' => $reminders->customer_copy ?: 'Reminder: we have upcoming electrical work scheduled with Collins Electric.',
                'internal_notes' => $reminders->provider_status === 'verified' && filled($reminders->internal_notes)
                    ? (string) $reminders->internal_notes
                    : 'SMS requested for Collins Electric. Sending remains blocked until provider, sender, consent, opt-out, quiet-hours, and delivery readiness are verified.',
            ])->save();

            if ((bool) $this->option('seed-demo-job') && $tenant->fieldServiceJobs()->count() === 0) {
                FieldServiceJob::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'assigned_user_id' => (int) $user->id,
                    'title' => 'Demo panel inspection',
                    'status' => 'scheduled',
                    'customer_name' => 'Sample Customer',
                    'customer_phone' => '555-0100',
                    'lock_box_code' => '2468',
                    'service_address_line_1' => '123 Main Street',
                    'service_city' => 'Charlotte',
                    'service_state' => 'NC',
                    'description' => 'Demo job for mobile testing. Replace with real QuickBooks/customer data before client handoff.',
                    'scheduled_for' => now()->addDay()->setTime(9, 0),
                    'metadata' => ['demo' => true],
                ]);
            }

            return [
                'tenant_id' => (int) $tenant->id,
                'tenant_slug' => (string) $tenant->slug,
                'user_id' => (int) $user->id,
                'user_email' => (string) $user->email,
                'actor_id' => (int) $user->id,
                'sms_provider_status' => (string) $reminders->provider_status,
            ];
        });

        $tenant = Tenant::query()->findOrFail((int) $result['tenant_id']);
        $agreement = $agreements->prepareCollinsElectric(
            $tenant,
            (int) $result['actor_id'],
            (int) $prices['onboarding'],
            (int) $prices['launch_partner'],
            (int) $prices['standard'],
        );
        $result['agreement_id'] = (int) $agreement->id;
        $result['agreement_status'] = (string) $agreement->status;
        $result['agreement_version'] = (int) $agreement->currentVersion?->version_number;

        $this->line('tenant_id='.$result['tenant_id']);
        $this->line('tenant_slug='.$result['tenant_slug']);
        $this->line('user_id='.$result['user_id']);
        $this->line('user_email='.$result['user_email']);
        $this->line('role=admin');
        $this->line('sms_requested=enabled');
        $this->line('sms_provider_status='.$result['sms_provider_status']);
        $this->line('messaging_allowance=250_sms_segments,1000_emails_per_calendar_month');
        $this->line('messaging_overage=0.05_per_sms_segment,0.005_per_email_monthly_in_arrears');
        $this->line('equipment_maintenance=enabled');
        $this->line('agreement_id='.$result['agreement_id']);
        $this->line('agreement_status='.$result['agreement_status']);
        $this->line('agreement_version='.$result['agreement_version']);
        $this->line('agreement_recipient=collinselectric91@gmail.com');
        $this->line('agreement_delivery=draft_only_not_sent');

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    protected function setupStatusPayload(TenantSetupStatus $status): array
    {
        $requiredModules = ['customers', 'field_service', 'equipment_maintenance', 'billing', 'messaging', 'reporting', 'uploads', 'quickbooks'];
        $moduleInterests = array_values(array_unique([
            ...array_values(array_filter((array) $status->module_interests, 'is_string')),
            ...$requiredModules,
        ]));
        $landlordReviewed = (string) $status->landlord_review_status === 'reviewed';
        $commercialReviewed = (string) $status->commercial_review_status === 'reviewed';

        $defaultNextAction = 'Map QuickBooks export, Apple photo workflow, SMS readiness, and electrician field-service calendar before client handoff.';
        $defaultCommercialAction = 'Guided launch partner setup: no billing and no SMS sends until verified.';
        $defaultNotes = trim(implode("\n", [
            'Collins Electric guided launch workspace.',
            'QuickBooks is concierge CSV/XLSX import, not live OAuth sync.',
            'Apple Photos starts as manual job photo import/upload.',
            'SMS reminders are setup intent only until provider/delivery smoke test passes.',
        ]));

        return [
            'business_profile_status' => (string) $status->business_profile_status === 'ready' ? 'ready' : 'in_progress',
            'import_path' => 'csv',
            'csv_manual_status' => (string) $status->csv_manual_status === 'ready' ? 'ready' : 'requested',
            'module_interests' => $moduleInterests,
            'mobile_interest' => 'ios',
            'plan_interest' => 'starter',
            'billing_lane_interest' => 'stripe_direct',
            'implementation_help_interest' => true,
            'commercial_review_status' => $commercialReviewed ? 'reviewed' : 'waiting_on_everbranch',
            'landlord_review_status' => $landlordReviewed ? 'reviewed' : 'waiting_on_everbranch',
            'next_recommended_action' => $landlordReviewed && filled($status->next_recommended_action)
                ? (string) $status->next_recommended_action
                : $defaultNextAction,
            'commercial_next_action' => $commercialReviewed && filled($status->commercial_next_action)
                ? (string) $status->commercial_next_action
                : $defaultCommercialAction,
            'internal_notes' => ($landlordReviewed || $commercialReviewed) && filled($status->internal_notes)
                ? (string) $status->internal_notes
                : $defaultNotes,
        ];
    }
}
