<?php

namespace App\Console\Commands;

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTimeEntry;
use App\Models\FieldServiceVehicle;
use App\Models\FieldServiceWorkCandidate;
use App\Models\MarketingProfile;
use App\Models\TeamChannel;
use App\Models\TeamMessage;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EverbranchPrepareAppReviewWorkspace extends Command
{
    protected $signature = 'everbranch:prepare-app-review-workspace
        {--password= : Password for the fictional App Review account; required and never printed}
        {--force-production : Allow the explicitly requested production review fixture}';

    protected $description = 'Create or refresh the fictional, tenant-isolated Everbranch App Review workspace.';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force-production')) {
            $this->error('Refusing to create the review fixture in production without --force-production.');

            return self::FAILURE;
        }

        $password = trim((string) $this->option('password'));
        if (strlen($password) < 16) {
            $this->error('Provide a unique review password of at least 16 characters with --password.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($password): array {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => 'everbranch-review'],
                ['name' => 'Everbranch Review']
            );
            $owner = User::query()->firstOrNew(['email' => 'appreview@theeverbranch.com']);
            $owner->forceFill([
                'name' => 'Everbranch Reviewer',
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
                'requested_via' => 'apple_app_review',
                'approved_at' => now(),
            ])->save();
            $employee = User::query()->firstOrCreate(
                ['email' => 'reviewtech@theeverbranch.com'],
                [
                    'name' => 'Jordan Field',
                    'password' => Hash::make(Str::password(32)),
                    'role' => 'member',
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'requested_via' => 'apple_app_review_fixture',
                    'approved_at' => now(),
                ]
            );
            $tenant->users()->syncWithoutDetaching([
                (int) $owner->id => ['role' => 'admin', 'membership_active' => true],
                (int) $employee->id => ['role' => 'member', 'membership_active' => true],
            ]);

            TenantAccessProfile::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id],
                ['plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'apple_app_review', 'metadata' => ['account_mode' => 'demo', 'fictional_data_only' => true]]
            );
            foreach (['field_service', 'time_tracking', 'team_communication', 'field_inventory', 'fleet', 'documents', 'quickbooks'] as $moduleKey) {
                TenantModuleState::query()->updateOrCreate(
                    ['tenant_id' => (int) $tenant->id, 'module_key' => $moduleKey],
                    ['enabled_override' => true, 'setup_status' => 'configured', 'setup_completed_at' => now(), 'metadata' => ['review_fixture' => true]]
                );
                TenantModuleEntitlement::query()->updateOrCreate(
                    ['tenant_id' => (int) $tenant->id, 'module_key' => $moduleKey],
                    ['availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'entitlement_source' => 'apple_app_review', 'metadata' => ['review_fixture' => true], 'created_by' => $owner->id, 'updated_by' => $owner->id]
                );
            }

            $customer = MarketingProfile::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'normalized_email' => 'alex@example.com'],
                ['first_name' => 'Alex', 'last_name' => 'Sample', 'email' => 'alex@example.com', 'normalized_email' => 'alex@example.com', 'phone' => '+18645550110', 'normalized_phone' => '+18645550110', 'address_line_1' => '100 Review Lane', 'city' => 'Easley', 'state' => 'SC', 'postal_code' => '29642', 'source_channels' => ['review_fixture'], 'notes' => 'Fictional App Review customer.']
            );
            $current = FieldServiceJob::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'external_source' => 'review_fixture', 'external_id' => 'current-panel'],
                ['marketing_profile_id' => $customer->id, 'assigned_user_id' => $employee->id, 'title' => 'Panel inspection and outlet repair', 'status' => 'scheduled', 'operational_status' => 'active', 'priority' => 'high', 'customer_name' => 'Alex Sample', 'customer_email' => 'alex@example.com', 'customer_phone' => '+18645550110', 'lock_box_code' => '2468', 'service_address_line_1' => '100 Review Lane', 'service_city' => 'Easley', 'service_state' => 'SC', 'service_postal_code' => '29642', 'description' => 'Fictional job: inspect the panel, repair the kitchen outlet, document the work, and confirm power before leaving.', 'scheduled_for' => now()->addHour(), 'metadata' => ['review_fixture' => true]]
            );
            $current->participants()->syncWithoutDetaching([
                (int) $owner->id => ['tenant_id' => $tenant->id, 'role' => 'lead', 'following' => true],
                (int) $employee->id => ['tenant_id' => $tenant->id, 'role' => 'technician', 'following' => true],
            ]);
            $past = FieldServiceJob::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'external_source' => 'review_fixture', 'external_id' => 'past-lighting'],
                ['marketing_profile_id' => $customer->id, 'assigned_user_id' => $employee->id, 'title' => 'Replace exterior lighting', 'status' => 'completed', 'operational_status' => 'complete', 'priority' => 'normal', 'customer_name' => 'Alex Sample', 'service_address_line_1' => '100 Review Lane', 'service_city' => 'Easley', 'service_state' => 'SC', 'completed_at' => now()->subDays(2), 'description' => 'Fictional completed job for App Review.', 'metadata' => ['review_fixture' => true]]
            );

            $task = FieldServiceTask::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'field_service_job_id' => (int) $current->id, 'title' => 'Photograph panel before work'],
                ['assigned_user_id' => $employee->id, 'created_by_user_id' => $owner->id, 'description' => 'Add a clear before photo to the job.', 'status' => 'open', 'priority' => 'urgent', 'due_at' => now()->addMinutes(45), 'sort_order' => 1]
            );
            $task->assignees()->sync([
                (int) $owner->id => ['tenant_id' => $tenant->id, 'assigned_by_user_id' => $owner->id],
                (int) $employee->id => ['tenant_id' => $tenant->id, 'assigned_by_user_id' => $owner->id],
            ]);
            FieldServiceMaterial::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'field_service_job_id' => (int) $current->id, 'name' => '20A GFCI outlet'],
                ['quantity' => 2, 'pulled_quantity' => 2, 'loaded_quantity' => 2, 'used_quantity' => 0, 'unit' => 'each', 'status' => 'loaded', 'notes' => 'Bring two plus a matching wall plate.']
            );
            $vehicle = FieldServiceVehicle::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'identifier' => 'REVIEW-VAN-1'],
                ['name' => 'Service Van 1', 'status' => 'active', 'notes' => 'Fictional review vehicle.']
            );
            $current->vehicles()->syncWithoutDetaching([(int) $vehicle->id => ['tenant_id' => $tenant->id, 'assigned_by_user_id' => $owner->id]]);
            FieldServiceTimeEntry::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'field_service_job_id' => (int) $current->id, 'user_id' => (int) $employee->id, 'work_date' => now()->subDay()->toDateString()],
                ['started_at' => '09:00', 'ended_at' => '10:30', 'break_minutes' => 0, 'duration_minutes' => 90, 'status' => 'approved', 'notes' => 'Fictional preparation time.', 'reviewed_by_user_id' => $owner->id, 'reviewed_at' => now()]
            );
            FieldServiceJobNote::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'field_service_job_id' => (int) $current->id, 'body' => 'Customer confirmed access. Use the side entrance and message the team when power is restored.'],
                ['created_by_user_id' => $owner->id, 'status_update' => 'ready', 'noted_at' => now()->subMinutes(20), 'metadata' => ['review_fixture' => true]]
            );
            FieldServiceFinancialDocument::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'source' => 'quickbooks', 'external_id' => 'review-invoice-1001'],
                ['marketing_profile_id' => $customer->id, 'field_service_job_id' => $current->id, 'document_type' => 'invoice', 'document_number' => '1001', 'status' => 'open', 'transaction_date' => now()->toDateString(), 'due_date' => now()->addDays(14)->toDateString(), 'total_amount' => 875, 'balance' => 875, 'currency' => 'USD', 'customer_memo' => 'Fictional QuickBooks-like review data.']
            );
            FieldServiceWorkCandidate::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'source' => 'quickbooks', 'external_id' => 'review-estimate-2001'],
                ['source_type' => 'estimate', 'status' => 'pending', 'title' => 'Garage circuit estimate', 'customer_name' => 'Taylor Example', 'amount' => 1250, 'balance' => 0, 'description' => 'Fictional estimate awaiting Create Job, Link, or Dismiss.', 'payload' => ['review_fixture' => true]]
            );
            $channel = TeamChannel::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'field_service_job_id' => (int) $current->id],
                ['created_by_user_id' => $owner->id, 'kind' => 'job', 'name' => 'Panel inspection team']
            );
            $channel->members()->syncWithoutDetaching([
                (int) $owner->id => ['tenant_id' => $tenant->id, 'last_read_at' => now()],
                (int) $employee->id => ['tenant_id' => $tenant->id, 'last_read_at' => now()->subHour()],
            ]);
            TeamMessage::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'team_channel_id' => (int) $channel->id, 'client_uuid' => '00000000-0000-4000-8000-000000002201'],
                ['created_by_user_id' => $employee->id, 'body' => 'Van is loaded and I am ready for the panel inspection.', 'mention_user_ids' => [$owner->id], 'reactions' => ['thumbs_up' => [$owner->id]]]
            );

            return ['tenant' => $tenant, 'owner' => $owner, 'current' => $current, 'past' => $past];
        });

        $this->info('Fictional Everbranch App Review workspace is ready.');
        $this->line('workspace='.$result['tenant']->slug);
        $this->line('review_email='.$result['owner']->email);
        $this->line('current_job_id='.$result['current']->id);
        $this->line('past_job_id='.$result['past']->id);
        $this->warn('The password was set but not printed. Store it in the approved secret manager and provide it only in App Review Information.');

        return self::SUCCESS;
    }
}
