<?php

namespace App\Console\Commands;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTimeSession;
use App\Models\FieldServiceVehicle;
use App\Models\FieldServiceWorkShift;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\FleetTrackingPolicyAcknowledgement;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantDiscoveryProfile;
use App\Models\TenantFleetTrackingSetting;
use App\Models\TenantWorkforceSetting;
use App\Models\User;
use App\Services\Tenancy\LandlordCommercialConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EverbranchPreparePestControlDemo extends Command
{
    public const OWNER_EMAIL = 'demo@greenshieldpest.example';

    public const DEFAULT_PASSWORD = 'DemoPest!2026';

    protected $signature = 'everbranch:prepare-pest-control-demo
        {--password='.self::DEFAULT_PASSWORD.' : Fictional demo login password}
        {--force-production : Allow the explicitly requested production demo fixture}';

    protected $description = 'Create or refresh the fictional Green Shield Pest Control vehicle-tracking demonstration workspace.';

    public function handle(LandlordCommercialConfigService $commercial): int
    {
        if (app()->environment('production') && ! $this->option('force-production')) {
            $this->error('Refusing to create the fictional demonstration workspace in production without --force-production.');

            return self::FAILURE;
        }

        $password = trim((string) $this->option('password'));
        if (strlen($password) < 12) {
            $this->error('Provide a fictional demo password of at least 12 characters.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($commercial, $password): array {
            $tenant = Tenant::query()->updateOrCreate(['slug' => 'green-shield-pest-control'], ['name' => 'Green Shield Pest Control']);
            $owner = User::query()->firstOrNew(['email' => self::OWNER_EMAIL]);
            $owner->forceFill([
                'name' => 'Dana Green',
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
                'requested_via' => 'fictional_pest_control_demo',
                'approved_at' => now(),
            ])->save();
            $technician = User::query()->firstOrNew(['email' => 'miles@greenshieldpest.example']);
            $technician->forceFill([
                'name' => 'Miles Carter',
                'password' => Hash::make(Str::password(32)),
                'role' => 'member',
                'is_active' => true,
                'email_verified_at' => now(),
                'requested_via' => 'fictional_pest_control_demo',
                'approved_at' => now(),
            ])->save();

            // These identities are deliberately isolated: public demonstration
            // credentials must never retain an accidental real-tenant membership.
            $owner->tenants()->sync([(int) $tenant->id => ['role' => 'admin', 'membership_active' => true]]);
            $technician->tenants()->sync([(int) $tenant->id => ['role' => 'member', 'membership_active' => true]]);

            $additionalTechnicians = [];
            foreach ([
                'maya@greenshieldpest.example' => 'Maya Ortiz',
                'eli@greenshieldpest.example' => 'Eli Turner',
            ] as $email => $name) {
                $member = User::query()->firstOrNew(['email' => $email]);
                $member->forceFill([
                    'name' => $name,
                    'password' => Hash::make(Str::password(32)),
                    'role' => 'member',
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'requested_via' => 'fictional_pest_control_demo',
                    'approved_at' => now(),
                ])->save();
                $member->tenants()->sync([(int) $tenant->id => ['role' => 'member', 'membership_active' => true]]);
                $additionalTechnicians[$email] = $member;
            }

            $commercial->assignTenantPlan((int) $tenant->id, 'base', 'direct', 'fictional_pest_control_demo', (int) $owner->id);
            foreach (['field_service', 'time_tracking', 'fleet', 'fleet_tracking'] as $moduleKey) {
                $commercial->setTenantModuleState((int) $tenant->id, $moduleKey, true, 'configured', (int) $owner->id);
                $commercial->setTenantModuleEntitlement((int) $tenant->id, $moduleKey, [
                    'availability_status' => 'available',
                    'enabled_status' => 'enabled',
                    'billing_status' => 'demo',
                    'entitlement_source' => 'fictional_pest_control_demo',
                    'notes' => 'Fictional sales demonstration only. Never treat this workspace or its location points as production evidence.',
                    'metadata' => ['demo' => true, 'fictional_data_only' => true],
                ], (int) $owner->id);
            }

            TenantDiscoveryProfile::query()->updateOrCreate(['tenant_id' => (int) $tenant->id], [
                'primary_brand_name' => 'Green Shield Pest Control',
                'short_brand_summary' => 'Fictional neighborhood pest-control operations demo.',
                'long_form_description' => 'A fictional Everbranch workspace demonstrating scheduled service, timecards, and separately sourced company-vehicle location tracking.',
                'support_email' => self::OWNER_EMAIL,
                'support_phone' => '(704) 555-0148',
                'brand_keywords' => ['pest control', 'termite inspection', 'mosquito service', 'fictional demo'],
                'geography' => ['city' => 'Charlotte', 'state' => 'NC'],
                'is_active' => true,
            ]);
            TenantWorkforceSetting::query()->updateOrCreate(['tenant_id' => (int) $tenant->id], [
                'enforce_scheduled_clocking' => true,
                'clock_early_minutes' => 15,
                'clock_late_minutes' => 15,
                'updated_by_user_id' => (int) $owner->id,
            ]);
            TenantFleetTrackingSetting::query()->updateOrCreate(['tenant_id' => (int) $tenant->id], [
                'phone_tracking_enabled' => true,
                'bouncie_tracking_enabled' => true,
                'policy_version' => 'green-shield-demo-v1',
                'policy_sha256' => hash('sha256', 'Fictional Green Shield policy: company vans and active scheduled shifts only.'),
                'counsel_review_reference' => 'Fictional demonstration policy review — no real legal advice or employment policy.',
                'legal_reviewed_at' => now(),
                'legal_reviewed_by_user_id' => (int) $owner->id,
                'retention_days' => 30,
            ]);
            FleetTrackingPolicyAcknowledgement::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $technician->id,
                'policy_version' => 'green-shield-demo-v1',
            ], [
                'policy_sha256' => hash('sha256', 'Fictional Green Shield policy: company vans and active scheduled shifts only.'),
                'accepted_at' => now()->subDays(2),
                'acceptance_source' => 'demo_fixture',
                'device_context' => ['platform' => 'ios', 'demo' => true],
            ]);

            $customer = MarketingProfile::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'normalized_email' => 'lena.brooks@example.com',
            ], [
                'first_name' => 'Lena',
                'last_name' => 'Brooks',
                'email' => 'lena.brooks@example.com',
                'normalized_email' => 'lena.brooks@example.com',
                'phone' => '+17045550189',
                'normalized_phone' => '+17045550189',
                'address_line_1' => '412 Hawthorne Lane',
                'city' => 'Charlotte',
                'state' => 'NC',
                'postal_code' => '28205',
                'source_channels' => ['fictional_demo'],
                'notes' => 'Fictional customer used solely for the Green Shield demonstration.',
            ]);
            $customers = ['lena' => $customer];
            foreach ([
                'marisol' => ['Marisol', 'Nguyen', 'marisol.nguyen@example.com', '+17045550192', '907 Oakview Drive', '28209'],
                'david' => ['David', 'Kim', 'david.kim@example.com', '+17045550193', '1834 Brookstone Way', '28211'],
                'riley' => ['Riley', 'Morgan', 'riley.morgan@example.com', '+17045550196', '66 Meadow Run Court', '28207'],
                'priya' => ['Priya', 'Shah', 'priya.shah@example.com', '+17045550198', '2910 Cedar Lane', '28204'],
            ] as $key => [$firstName, $lastName, $email, $phone, $address, $postalCode]) {
                $customers[$key] = MarketingProfile::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'normalized_email' => $email,
                ], [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'normalized_email' => $email,
                    'phone' => $phone,
                    'normalized_phone' => $phone,
                    'address_line_1' => $address,
                    'city' => 'Charlotte',
                    'state' => 'NC',
                    'postal_code' => $postalCode,
                    'source_channels' => ['fictional_demo'],
                    'notes' => 'Fictional customer used solely for the Green Shield demonstration.',
                ]);
            }
            $job = FieldServiceJob::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'external_source' => 'fictional_pest_control_demo',
                'external_id' => 'termite-inspection-412-hawthorne',
            ], [
                'marketing_profile_id' => (int) $customer->id,
                'assigned_user_id' => (int) $technician->id,
                'title' => 'Termite inspection and perimeter treatment',
                'status' => 'scheduled',
                'operational_status' => 'active',
                'priority' => 'high',
                'customer_name' => 'Lena Brooks',
                'customer_email' => 'lena.brooks@example.com',
                'customer_phone' => '+17045550189',
                'service_address_line_1' => '412 Hawthorne Lane',
                'service_city' => 'Charlotte',
                'service_state' => 'NC',
                'service_postal_code' => '28205',
                'description' => 'Fictional inspection and treatment visit for demonstration only.',
                'scheduled_for' => now()->startOfHour()->addHour(),
                'scheduled_end_at' => now()->startOfHour()->addHours(3),
                'metadata' => ['fictional_demo' => true],
            ]);
            $job->participants()->syncWithoutDetaching([(int) $owner->id => ['tenant_id' => $tenant->id, 'role' => 'dispatcher', 'following' => true]]);
            $shift = FieldServiceWorkShift::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $technician->id,
                'field_service_job_id' => (int) $job->id,
            ], [
                'created_by_user_id' => (int) $owner->id,
                'status' => 'scheduled',
                'starts_at' => now()->startOfHour(),
                'ends_at' => now()->startOfHour()->addHours(3),
                'unpaid_break_minutes' => 0,
                'notes' => 'Fictional demo shift. The 15-minute approved clock window is enabled.',
            ]);
            foreach (['Review inspection history', 'Complete perimeter treatment', 'Log fictional service summary'] as $sortOrder => $taskTitle) {
                FieldServiceTask::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_job_id' => (int) $job->id,
                    'title' => $taskTitle,
                ], [
                    'assigned_user_id' => (int) $technician->id,
                    'created_by_user_id' => (int) $owner->id,
                    'description' => 'Fictional demonstration task.',
                    'status' => 'open',
                    'priority' => 'high',
                    'due_at' => now()->startOfHour()->addHours(3),
                    'sort_order' => $sortOrder,
                ]);
            }
            FieldServiceJobNote::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'field_service_job_id' => (int) $job->id,
                'body' => 'Fictional demo dispatch note — route and service details are examples only.',
            ], [
                'created_by_user_id' => (int) $owner->id,
                'status_update' => 'active',
                'noted_at' => now()->subMinutes(30),
                'metadata' => ['fictional_demo' => true],
            ]);
            $van = FieldServiceVehicle::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'identifier' => 'GSP-17',
            ], [
                'name' => 'Green Shield Van 17',
                'status' => 'active',
                'notes' => 'Fictional company vehicle for the Everbranch vehicle-tracking demonstration.',
            ]);
            $job->vehicles()->syncWithoutDetaching([(int) $van->id => ['tenant_id' => $tenant->id, 'assigned_by_user_id' => $owner->id]]);
            $secondVan = FieldServiceVehicle::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'identifier' => 'GSP-24',
            ], [
                'name' => 'Green Shield Van 24',
                'status' => 'active',
                'notes' => 'Fictional company vehicle for the Green Shield demonstration.',
            ]);

            $workStart = now()->startOfHour();
            $sampleJobs = [
                [
                    'external_id' => 'mosquito-service-oakview', 'customer' => 'marisol', 'technician' => $technician, 'vehicle' => $van,
                    'title' => 'Mosquito barrier service', 'status' => 'scheduled', 'operational_status' => 'scheduled', 'priority' => 'normal',
                    'start' => $workStart->copy()->addHours(4), 'end' => $workStart->copy()->addHours(5),
                    'description' => 'Fictional recurring mosquito barrier application for the demonstration.',
                    'tasks' => ['Confirm gate access', 'Apply backyard perimeter treatment'],
                ],
                [
                    'external_id' => 'rodent-assessment-brookstone', 'customer' => 'david', 'technician' => $additionalTechnicians['maya@greenshieldpest.example'], 'vehicle' => $secondVan,
                    'title' => 'Rodent exclusion assessment', 'status' => 'scheduled', 'operational_status' => 'scheduled', 'priority' => 'high',
                    'start' => $workStart->copy()->addDay()->setTime(9, 0), 'end' => $workStart->copy()->addDay()->setTime(10, 30),
                    'description' => 'Fictional inspection of attic and exterior entry points for the demonstration.',
                    'tasks' => ['Inspect exterior entry points', 'Prepare fictional exclusion estimate'],
                ],
                [
                    'external_id' => 'ant-inspection-meadow-run', 'customer' => 'riley', 'technician' => $additionalTechnicians['eli@greenshieldpest.example'], 'vehicle' => $secondVan,
                    'title' => 'Carpenter ant inspection', 'status' => 'quote', 'operational_status' => 'quote', 'priority' => 'normal',
                    'start' => null, 'end' => null,
                    'description' => 'Fictional inspection opportunity awaiting customer approval.',
                    'tasks' => ['Review inspection notes', 'Send fictional treatment options'],
                ],
                [
                    'external_id' => 'quarterly-service-cedar-lane', 'customer' => 'priya', 'technician' => $additionalTechnicians['maya@greenshieldpest.example'], 'vehicle' => $van,
                    'title' => 'Quarterly exterior service', 'status' => 'complete', 'operational_status' => 'complete', 'priority' => 'normal',
                    'start' => $workStart->copy()->subDay()->setTime(14, 0), 'end' => $workStart->copy()->subDay()->setTime(15, 0),
                    'description' => 'Fictional completed quarterly exterior service.',
                    'tasks' => ['Complete exterior treatment', 'Leave fictional service summary'],
                ],
            ];

            foreach ($sampleJobs as $sample) {
                $sampleCustomer = $customers[$sample['customer']];
                $sampleJob = FieldServiceJob::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'external_source' => 'fictional_pest_control_demo',
                    'external_id' => $sample['external_id'],
                ], [
                    'marketing_profile_id' => (int) $sampleCustomer->id,
                    'assigned_user_id' => (int) $sample['technician']->id,
                    'title' => $sample['title'],
                    'status' => $sample['status'],
                    'operational_status' => $sample['operational_status'],
                    'status_source' => 'demo_fixture',
                    'priority' => $sample['priority'],
                    'customer_name' => $sampleCustomer->first_name.' '.$sampleCustomer->last_name,
                    'customer_email' => $sampleCustomer->email,
                    'customer_phone' => $sampleCustomer->phone,
                    'service_address_line_1' => $sampleCustomer->address_line_1,
                    'service_city' => $sampleCustomer->city,
                    'service_state' => $sampleCustomer->state,
                    'service_postal_code' => $sampleCustomer->postal_code,
                    'description' => $sample['description'],
                    'scheduled_for' => $sample['start'],
                    'scheduled_end_at' => $sample['end'],
                    'completed_at' => $sample['operational_status'] === 'complete' ? $sample['end'] : null,
                    'last_financial_activity_at' => $sample['start'] ?? now(),
                    'metadata' => ['fictional_demo' => true],
                ]);
                $sampleJob->participants()->syncWithoutDetaching([
                    (int) $owner->id => ['tenant_id' => $tenant->id, 'role' => 'dispatcher', 'following' => true],
                    (int) $sample['technician']->id => ['tenant_id' => $tenant->id, 'role' => 'technician', 'following' => true],
                ]);
                $sampleJob->vehicles()->syncWithoutDetaching([(int) $sample['vehicle']->id => ['tenant_id' => $tenant->id, 'assigned_by_user_id' => $owner->id]]);

                foreach ($sample['tasks'] as $sortOrder => $taskTitle) {
                    FieldServiceTask::query()->updateOrCreate([
                        'tenant_id' => (int) $tenant->id,
                        'field_service_job_id' => (int) $sampleJob->id,
                        'title' => $taskTitle,
                    ], [
                        'assigned_user_id' => (int) $sample['technician']->id,
                        'created_by_user_id' => (int) $owner->id,
                        'description' => 'Fictional demonstration task.',
                        'status' => $sample['operational_status'] === 'complete' ? 'complete' : 'open',
                        'priority' => $sample['priority'],
                        'due_at' => $sample['end'] ?? $workStart->copy()->addDays(2),
                        'completed_by_user_id' => $sample['operational_status'] === 'complete' ? (int) $sample['technician']->id : null,
                        'completed_at' => $sample['operational_status'] === 'complete' ? $sample['end'] : null,
                        'sort_order' => $sortOrder,
                    ]);
                }
                FieldServiceJobNote::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_job_id' => (int) $sampleJob->id,
                    'body' => 'Fictional demo note — no real customer, employee, or service activity.',
                ], [
                    'created_by_user_id' => (int) $owner->id,
                    'status_update' => $sample['status'],
                    'noted_at' => $sample['start'] ?? now(),
                    'metadata' => ['fictional_demo' => true],
                ]);

                if ($sample['start'] !== null && $sample['operational_status'] !== 'complete') {
                    FieldServiceWorkShift::query()->updateOrCreate([
                        'tenant_id' => (int) $tenant->id,
                        'user_id' => (int) $sample['technician']->id,
                        'field_service_job_id' => (int) $sampleJob->id,
                    ], [
                        'created_by_user_id' => (int) $owner->id,
                        'status' => 'scheduled',
                        'starts_at' => $sample['start'],
                        'ends_at' => $sample['end'],
                        'unpaid_break_minutes' => 0,
                        'notes' => 'Fictional scheduled shift for the Green Shield demonstration.',
                    ]);
                }
            }
            $device = FleetTrackingDevice::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'field_service_vehicle_id' => (int) $van->id,
            ], [
                'provider' => 'bouncie',
                'external_device_id' => 'DEMO-GSP-17',
                'label' => 'Van 17 · fictional Bouncie feed',
                'status' => 'active',
                'installed_at' => now()->subMonth(),
            ]);
            $session = FieldServiceTimeSession::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $technician->id,
                'client_uuid' => '00000000-0000-4000-8000-000000007217',
            ], [
                'field_service_job_id' => (int) $job->id,
                'active_user_key' => null,
                'status' => 'submitted',
                'clocked_in_at' => now()->subMinutes(55),
                'clocked_out_at' => now()->subMinutes(10),
                'break_seconds' => 0,
                'duration_seconds' => 2700,
                'clock_out_notes' => 'Fictional completed demo timer.',
                'source' => 'demo_fixture',
                'device_context' => ['demo' => true],
            ]);
            foreach ([
                ['bouncie', 'bouncie-van-1', 35.2286, -80.8431, now()->subMinutes(28), (int) $device->id, (int) $van->id, null],
                ['mobile', 'mobile-tech-1', 35.2292, -80.8414, now()->subMinutes(24), null, null, (int) $technician->id],
                ['bouncie', 'bouncie-van-2', 35.2307, -80.8390, now()->subMinutes(18), (int) $device->id, (int) $van->id, null],
                ['mobile', 'mobile-tech-2', 35.2314, -80.8379, now()->subMinutes(14), null, null, (int) $technician->id],
                ['bouncie', 'bouncie-van-3', 35.2330, -80.8352, now()->subMinutes(8), (int) $device->id, (int) $van->id, null],
            ] as [$source, $key, $latitude, $longitude, $recordedAt, $deviceId, $vehicleId, $userId]) {
                FleetLocationPoint::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'source' => $source,
                    'event_key' => $key,
                ], [
                    'fleet_tracking_device_id' => $deviceId,
                    'field_service_vehicle_id' => $vehicleId,
                    'user_id' => $userId,
                    'field_service_time_session_id' => $source === 'mobile' ? (int) $session->id : null,
                    'event_type' => $source === 'bouncie' ? 'location' : null,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'accuracy_meters' => $source === 'mobile' ? 12 : null,
                    'recorded_at' => $recordedAt,
                    'received_at' => $recordedAt->copy()->addSeconds(4),
                    'safe_payload' => ['fictional_demo' => true],
                ]);
            }

            return compact('tenant', 'owner', 'technician', 'job', 'shift', 'van');
        });

        $this->info('Fictional Green Shield Pest Control demonstration workspace is ready.');
        $this->line('workspace='.$result['tenant']->slug);
        $this->line('demo_email='.$result['owner']->email);
        $this->line('demo_password='.$password);
        $this->line('vehicle='.$result['van']->name);
        $this->line('job_id='.$result['job']->id);
        $this->warn('All data is fictional. The fleet page remains unavailable until the global FLEET_TRACKING_ENABLED rollout switch is intentionally enabled.');

        return self::SUCCESS;
    }
}
