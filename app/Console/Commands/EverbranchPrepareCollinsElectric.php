<?php

namespace App\Console\Commands;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceReminderSetting;
use App\Models\Tenant;
use App\Models\TenantSetupStatus;
use App\Models\User;
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
        {--seed-demo-job : Create a sample electrician job when none exist}';

    protected $description = 'Prepare the guided Collins Electric launch workspace and attach John for mobile testing.';

    public function handle(
        LandlordCommercialConfigService $commercialService,
        TenantSetupStatusService $setupStatusService,
        TenantBlueprintProfileService $blueprintService,
        TenantOnboardingBlueprintStore $blueprintStore
    ): int {
        $johnEmail = strtolower(trim((string) $this->option('john-email')));
        if (! filter_var($johnEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid --john-email value.');

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

            FieldServiceReminderSetting::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id],
                [
                    'enabled' => false,
                    'channel' => 'sms',
                    'cadence' => 'daily',
                    'send_time' => '08:00',
                    'timezone' => 'America/New_York',
                    'provider_status' => 'not_verified',
                    'customer_copy' => 'Reminder: we have upcoming electrical work scheduled with Collins Electric.',
                    'internal_notes' => 'Do not enable SMS sends until Twilio/provider readiness and consent are verified.',
                ]
            );

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
            ];
        });

        $this->line('tenant_id='.$result['tenant_id']);
        $this->line('tenant_slug='.$result['tenant_slug']);
        $this->line('user_id='.$result['user_id']);
        $this->line('user_email='.$result['user_email']);
        $this->line('role=admin');
        $this->line('sms_provider_status=not_verified');

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    protected function setupStatusPayload(TenantSetupStatus $status): array
    {
        $requiredModules = ['customers', 'field_service', 'billing', 'messaging', 'reporting', 'uploads', 'quickbooks'];
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
            'billing_lane_interest' => 'manual_invoice',
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
