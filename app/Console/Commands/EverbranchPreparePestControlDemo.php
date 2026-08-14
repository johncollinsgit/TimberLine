<?php

namespace App\Console\Commands;

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceFinancialDocumentLine;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTimeSession;
use App\Models\FieldServiceVehicle;
use App\Models\FieldServiceWorkShift;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\FleetTrackingPolicyAcknowledgement;
use App\Models\IntegrationConnection;
use App\Models\MarketingProfile;
use App\Models\QuickBooksReportingSnapshot;
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
                'status_source' => 'manual',
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
                'metadata' => ['fictional_demo' => true, 'fictional_route' => $this->fictionalRoute(0, 'Green Shield Van 17')],
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
                ['external_id' => 'wasp-nest-larkspur', 'customer' => 'lena', 'technician' => $additionalTechnicians['eli@greenshieldpest.example'], 'vehicle' => $secondVan, 'title' => 'Wasp nest removal', 'status' => 'scheduled', 'operational_status' => 'scheduled', 'priority' => 'high', 'start' => $workStart->copy()->addDay()->setTime(13, 0), 'end' => $workStart->copy()->addDay()->setTime(14, 0), 'description' => 'Fictional wasp nest removal visit.', 'tasks' => ['Confirm exterior access', 'Remove fictional nest']],
                ['external_id' => 'cockroach-treatment-oakview', 'customer' => 'marisol', 'technician' => $technician, 'vehicle' => $van, 'title' => 'Cockroach follow-up treatment', 'status' => 'active', 'operational_status' => 'active', 'priority' => 'high', 'start' => $workStart->copy()->addHours(2), 'end' => $workStart->copy()->addHours(3), 'description' => 'Fictional follow-up treatment for the demo schedule.', 'tasks' => ['Inspect monitor placements', 'Apply fictional follow-up treatment']],
                ['external_id' => 'flea-inspection-brookstone', 'customer' => 'david', 'technician' => $additionalTechnicians['maya@greenshieldpest.example'], 'vehicle' => $secondVan, 'title' => 'Flea inspection', 'status' => 'scheduled', 'operational_status' => 'scheduled', 'priority' => 'normal', 'start' => $workStart->copy()->addDays(2)->setTime(10, 0), 'end' => $workStart->copy()->addDays(2)->setTime(11, 0), 'description' => 'Fictional flea inspection visit.', 'tasks' => ['Review treatment prep', 'Inspect affected rooms']],
                ['external_id' => 'termite-monitor-meadow-run', 'customer' => 'riley', 'technician' => $technician, 'vehicle' => $van, 'title' => 'Termite monitoring station check', 'status' => 'complete', 'operational_status' => 'complete', 'priority' => 'normal', 'start' => $workStart->copy()->subDays(2)->setTime(11, 0), 'end' => $workStart->copy()->subDays(2)->setTime(12, 0), 'description' => 'Fictional completed monitoring-station check.', 'tasks' => ['Inspect fictional stations', 'Record fictional findings']],
                ['external_id' => 'spider-sweep-cedar-lane', 'customer' => 'priya', 'technician' => $additionalTechnicians['eli@greenshieldpest.example'], 'vehicle' => $secondVan, 'title' => 'Spider exterior sweep', 'status' => 'complete', 'operational_status' => 'complete', 'priority' => 'low', 'start' => $workStart->copy()->subDays(3)->setTime(15, 0), 'end' => $workStart->copy()->subDays(3)->setTime(16, 0), 'description' => 'Fictional completed exterior sweep.', 'tasks' => ['Sweep eaves and entry points', 'Update fictional service record']],
                ['external_id' => 'bed-bug-prep-hawthorne', 'customer' => 'lena', 'technician' => $additionalTechnicians['maya@greenshieldpest.example'], 'vehicle' => $van, 'title' => 'Bed bug preparation review', 'status' => 'quote', 'operational_status' => 'quote', 'priority' => 'normal', 'start' => null, 'end' => null, 'description' => 'Fictional preparation review awaiting approval.', 'tasks' => ['Review fictional prep checklist', 'Prepare fictional service quote']],
                ['external_id' => 'fire-ant-treatment-oakview', 'customer' => 'marisol', 'technician' => $technician, 'vehicle' => $van, 'title' => 'Fire ant mound treatment', 'status' => 'scheduled', 'operational_status' => 'scheduled', 'priority' => 'normal', 'start' => $workStart->copy()->addDays(3)->setTime(9, 0), 'end' => $workStart->copy()->addDays(3)->setTime(10, 0), 'description' => 'Fictional yard treatment appointment.', 'tasks' => ['Locate fictional mounds', 'Apply treatment plan']],
                ['external_id' => 'wildlife-entry-brookstone', 'customer' => 'david', 'technician' => $additionalTechnicians['eli@greenshieldpest.example'], 'vehicle' => $secondVan, 'title' => 'Wildlife entry-point assessment', 'status' => 'needs_details', 'operational_status' => 'needs_details', 'priority' => 'high', 'start' => null, 'end' => null, 'description' => 'Fictional assessment pending access details.', 'tasks' => ['Confirm attic access', 'Document fictional entry points']],
                ['external_id' => 'mole-inspection-meadow-run', 'customer' => 'riley', 'technician' => $additionalTechnicians['maya@greenshieldpest.example'], 'vehicle' => $secondVan, 'title' => 'Mole activity inspection', 'status' => 'scheduled', 'operational_status' => 'scheduled', 'priority' => 'normal', 'start' => $workStart->copy()->addDays(4)->setTime(11, 0), 'end' => $workStart->copy()->addDays(4)->setTime(12, 0), 'description' => 'Fictional lawn inspection appointment.', 'tasks' => ['Walk lawn perimeter', 'Prepare fictional control options']],
                ['external_id' => 'silverfish-treatment-cedar-lane', 'customer' => 'priya', 'technician' => $technician, 'vehicle' => $van, 'title' => 'Silverfish treatment', 'status' => 'complete', 'operational_status' => 'complete', 'priority' => 'normal', 'start' => $workStart->copy()->subDays(4)->setTime(10, 0), 'end' => $workStart->copy()->subDays(4)->setTime(11, 0), 'description' => 'Fictional completed interior treatment.', 'tasks' => ['Inspect humidity areas', 'Complete fictional treatment']],
                ['external_id' => 'pantry-pest-hawthorne', 'customer' => 'lena', 'technician' => $additionalTechnicians['eli@greenshieldpest.example'], 'vehicle' => $secondVan, 'title' => 'Pantry pest inspection', 'status' => 'active', 'operational_status' => 'active', 'priority' => 'normal', 'start' => $workStart->copy()->addHours(6), 'end' => $workStart->copy()->addHours(7), 'description' => 'Fictional active pantry pest inspection.', 'tasks' => ['Inspect pantry shelves', 'Review fictional sanitation notes']],
                ['external_id' => 'tick-treatment-oakview', 'customer' => 'marisol', 'technician' => $additionalTechnicians['maya@greenshieldpest.example'], 'vehicle' => $van, 'title' => 'Tick yard treatment', 'status' => 'scheduled', 'operational_status' => 'scheduled', 'priority' => 'normal', 'start' => $workStart->copy()->addDays(5)->setTime(13, 0), 'end' => $workStart->copy()->addDays(5)->setTime(14, 0), 'description' => 'Fictional seasonal tick treatment.', 'tasks' => ['Review yard boundaries', 'Apply fictional treatment']],
                ['external_id' => 'rat-exclusion-brookstone', 'customer' => 'david', 'technician' => $technician, 'vehicle' => $van, 'title' => 'Rat exclusion proposal', 'status' => 'quote', 'operational_status' => 'quote', 'priority' => 'high', 'start' => null, 'end' => null, 'description' => 'Fictional exclusion proposal awaiting acceptance.', 'tasks' => ['Draft fictional exclusion scope', 'Send proposal for review']],
                ['external_id' => 'seasonal-inspection-meadow-run', 'customer' => 'riley', 'technician' => $additionalTechnicians['eli@greenshieldpest.example'], 'vehicle' => $secondVan, 'title' => 'Seasonal perimeter inspection', 'status' => 'complete', 'operational_status' => 'complete', 'priority' => 'low', 'start' => $workStart->copy()->subDays(5)->setTime(13, 0), 'end' => $workStart->copy()->subDays(5)->setTime(14, 0), 'description' => 'Fictional completed seasonal inspection.', 'tasks' => ['Inspect exterior barrier', 'Close fictional service ticket']],
                ['external_id' => 'yellowjacket-visit-cedar-lane', 'customer' => 'priya', 'technician' => $additionalTechnicians['maya@greenshieldpest.example'], 'vehicle' => $van, 'title' => 'Yellowjacket treatment visit', 'status' => 'scheduled', 'operational_status' => 'scheduled', 'priority' => 'high', 'start' => $workStart->copy()->addDays(6)->setTime(10, 0), 'end' => $workStart->copy()->addDays(6)->setTime(11, 0), 'description' => 'Fictional yellowjacket service appointment.', 'tasks' => ['Confirm nest location', 'Complete fictional treatment']],
            ];

            $demoJobs = [$job];
            foreach ($sampleJobs as $sampleIndex => $sample) {
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
                    'status_source' => 'manual',
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
                    'metadata' => ['fictional_demo' => true, 'fictional_route' => $this->fictionalRoute($sampleIndex + 1, $sample['vehicle']->name)],
                ]);
                $demoJobs[] = $sampleJob;
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

            foreach ($demoJobs as $jobIndex => $demoJob) {
                $isQuote = $demoJob->operational_status === 'quote';
                $isComplete = $demoJob->operational_status === 'complete';
                $moneyIn = 245 + ($jobIndex * 35);
                $invoice = FieldServiceFinancialDocument::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'source' => 'fictional_demo',
                    'document_type' => $isQuote ? 'estimate' : 'invoice',
                    'external_id' => 'green-shield-job-'.$demoJob->id,
                ], [
                    'marketing_profile_id' => $demoJob->marketing_profile_id,
                    'field_service_job_id' => (int) $demoJob->id,
                    'document_number' => ($isQuote ? 'EST' : 'INV').'-GSP-'.str_pad((string) ($jobIndex + 1), 3, '0', STR_PAD_LEFT),
                    'status' => $isQuote ? 'pending' : ($isComplete ? 'paid' : 'open'),
                    'transaction_date' => ($demoJob->scheduled_for ?? now())->toDateString(),
                    'due_date' => ($demoJob->scheduled_for ?? now())->copy()->addDays(15)->toDateString(),
                    'total_amount' => $moneyIn,
                    'balance' => $isComplete ? 0 : $moneyIn,
                    'currency' => 'USD',
                    'private_note' => 'Fictional demo financial record. Not QuickBooks data.',
                    'customer_memo' => 'Fictional Green Shield service record.',
                    'metadata' => ['fictional_demo' => true],
                ]);
                FieldServiceFinancialDocumentLine::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_financial_document_id' => (int) $invoice->id,
                    'source_line_id' => 'service',
                ], [
                    'sort_order' => 0,
                    'detail_type' => 'service',
                    'item_external_id' => 'fictional-service',
                    'item_name' => $demoJob->title,
                    'description' => 'Fictional demo service line.',
                    'quantity' => 1,
                    'unit_price' => $moneyIn,
                    'amount' => $moneyIn,
                    'metadata' => ['fictional_demo' => true],
                ]);

                $moneySpent = 42 + (($jobIndex % 5) * 18);
                $expense = FieldServiceFinancialDocument::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'source' => 'fictional_demo',
                    'document_type' => 'expense',
                    'external_id' => 'green-shield-job-cost-'.$demoJob->id,
                ], [
                    'marketing_profile_id' => $demoJob->marketing_profile_id,
                    'field_service_job_id' => (int) $demoJob->id,
                    'document_number' => 'COST-GSP-'.str_pad((string) ($jobIndex + 1), 3, '0', STR_PAD_LEFT),
                    'status' => 'paid',
                    'transaction_date' => ($demoJob->scheduled_for ?? now())->toDateString(),
                    'total_amount' => $moneySpent,
                    'balance' => 0,
                    'currency' => 'USD',
                    'private_note' => 'Fictional demo job cost. Not QuickBooks data.',
                    'metadata' => ['fictional_demo' => true],
                ]);
                FieldServiceFinancialDocumentLine::query()->updateOrCreate([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_financial_document_id' => (int) $expense->id,
                    'source_line_id' => 'materials',
                ], [
                    'sort_order' => 0,
                    'detail_type' => 'cost',
                    'item_external_id' => 'fictional-materials',
                    'item_name' => 'Fictional materials and travel',
                    'description' => 'Fictional demo cost line.',
                    'quantity' => 1,
                    'unit_price' => $moneySpent,
                    'amount' => $moneySpent,
                    'metadata' => ['fictional_demo' => true],
                ]);
            }

            $demoConnection = IntegrationConnection::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'provider' => 'quickbooks',
                'external_account_id' => 'fictional-green-shield-demo',
            ], [
                'external_account_label' => 'Fictional demo only — not a QuickBooks connection',
                'status' => IntegrationConnection::STATUS_DISCONNECTED,
                'metadata' => ['fictional_demo' => true],
            ]);
            foreach ([
                'today' => [now()->startOfDay(), now(), 860.00, 172.00],
                'week' => [now()->startOfWeek(), now(), 4235.00, 916.00],
                'month' => [now()->startOfMonth(), now(), 10875.00, 2480.00],
            ] as $period => [$periodStart, $periodEnd, $moneyIn, $moneySpent]) {
                $demoSnapshot = QuickBooksReportingSnapshot::query()
                    ->forTenantId((int) $tenant->id)
                    ->where('range_key', 'home:cash:'.$period)
                    ->whereDate('period_start', $periodStart->toDateString())
                    ->whereDate('period_end', $periodEnd->toDateString())
                    ->first();
                $demoSnapshot ??= new QuickBooksReportingSnapshot([
                    'tenant_id' => (int) $tenant->id,
                    'range_key' => 'home:cash:'.$period,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                ]);
                $demoSnapshot->forceFill([
                    'integration_connection_id' => (int) $demoConnection->id,
                    'metrics' => [
                        'fictional_demo' => true,
                        'accounting_method' => 'Fictional demo only',
                        'total_income' => $moneyIn,
                        'total_expenses' => $moneySpent,
                    ],
                    'observed_at' => now(),
                ])->save();
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

    /** @return array{vehicle:string,points:array<int,array{x:int,y:int}>} */
    private function fictionalRoute(int $routeIndex, string $vehicle): array
    {
        $offset = ($routeIndex % 5) * 32;

        return [
            'vehicle' => $vehicle,
            'points' => [
                ['x' => 110 + $offset, 'y' => 500 - (($routeIndex % 3) * 36)],
                ['x' => 260 + $offset, 'y' => 410 - (($routeIndex % 4) * 28)],
                ['x' => 470 + $offset, 'y' => 455 - (($routeIndex % 3) * 44)],
                ['x' => 680 + $offset, 'y' => 295 + (($routeIndex % 4) * 30)],
                ['x' => 860 - ($offset / 2), 'y' => 185 + (($routeIndex % 3) * 40)],
            ],
        ];
    }
}
