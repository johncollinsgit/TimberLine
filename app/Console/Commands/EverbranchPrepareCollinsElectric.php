<?php

namespace App\Console\Commands;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceReminderSetting;
use App\Models\Tenant;
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
            $setupStatus->forceFill([
                'business_profile_status' => 'in_progress',
                'import_path' => 'csv',
                'csv_manual_status' => 'requested',
                'module_interests' => ['customers', 'field_service', 'billing', 'messaging', 'reporting', 'uploads', 'quickbooks'],
                'mobile_interest' => 'ios',
                'plan_interest' => 'starter',
                'billing_lane_interest' => 'manual_invoice',
                'implementation_help_interest' => true,
                'commercial_review_status' => 'waiting_on_everbranch',
                'landlord_review_status' => 'waiting_on_everbranch',
                'next_recommended_action' => 'Map QuickBooks export, Apple photo workflow, SMS readiness, and electrician field-service calendar before client handoff.',
                'commercial_next_action' => 'Guided launch partner setup: no billing and no SMS sends until verified.',
                'internal_notes' => trim(implode("\n", [
                    'Collins Electric guided launch workspace.',
                    'QuickBooks is concierge CSV/XLSX import, not live OAuth sync.',
                    'Apple Photos starts as manual job photo import/upload.',
                    'SMS reminders are setup intent only until provider/delivery smoke test passes.',
                ])),
            ])->save();

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
            $setupStatus->refresh()->forceFill([
                'business_profile_status' => 'in_progress',
                'import_path' => 'csv',
                'csv_manual_status' => 'requested',
                'module_interests' => ['customers', 'field_service', 'billing', 'messaging', 'reporting', 'uploads', 'quickbooks'],
                'mobile_interest' => 'ios',
                'plan_interest' => 'starter',
                'billing_lane_interest' => 'manual_invoice',
                'implementation_help_interest' => true,
                'commercial_review_status' => 'waiting_on_everbranch',
                'landlord_review_status' => 'waiting_on_everbranch',
                'next_recommended_action' => 'Map QuickBooks export, Apple photo workflow, SMS readiness, and electrician field-service calendar before client handoff.',
                'commercial_next_action' => 'Guided launch partner setup: no billing and no SMS sends until verified.',
                'internal_notes' => trim(implode("\n", [
                    'Collins Electric guided launch workspace.',
                    'QuickBooks is concierge CSV/XLSX import, not live OAuth sync.',
                    'Apple Photos starts as manual job photo import/upload.',
                    'SMS reminders are setup intent only until provider/delivery smoke test passes.',
                ])),
            ])->save();

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
}
