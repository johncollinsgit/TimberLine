<?php

namespace App\Console\Commands;

use App\Models\ClientProject;
use App\Models\ClientProjectTicket;
use App\Models\ClientProjectTicketTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClientProjects\ProjectChecklistService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ImportClientProjectChecklist extends Command
{
    protected $signature = 'client-projects:import-checklist {tenant} {file} {--apply} {--actor= : Authorized operator user ID}';

    protected $description = 'Preview or add a tenant launch checklist; preserve existing tasks and progress on replay.';

    public function handle(ProjectChecklistService $checklists): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->firstOrFail();
        $data = json_decode(file_get_contents($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);
        Validator::make($data, [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'title' => ['required', 'string', 'max:190'],
            'summary' => ['required', 'string', 'max:5000'],
            'client_label' => ['required', 'string', 'max:100'],
            'provider_label' => ['required', 'string', 'max:100'],
            'groups' => ['required', 'array', 'min:1', 'max:30'],
            'groups.*.key' => ['required', 'string', 'distinct', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'groups.*.title' => ['required', 'string', 'max:190'],
            'groups.*.tasks' => ['required', 'array', 'min:1', 'max:100'],
            'groups.*.tasks.*.key' => ['required', 'string', 'distinct', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'groups.*.tasks.*.title' => ['required', 'string', 'max:190'],
            'groups.*.tasks.*.details' => ['required', 'string', 'max:5000'],
            'groups.*.tasks.*.owner' => ['required', Rule::in(['client', 'evergrove'])],
            'groups.*.tasks.*.complete' => ['required', 'boolean'],
        ])->validate();
        $this->info($tenant->name.': '.$data['title'].' — '.collect($data['groups'])->sum(fn ($group) => count($group['tasks'])).' tasks');
        if (! $this->option('apply')) {
            $this->line('Preview only. Use --apply --actor=<operator ID> to add missing records.');

            return self::SUCCESS;
        }
        $actor = User::query()->findOrFail($this->option('actor'));
        abort_unless($checklists->isOperator($actor), 403, 'An authorized Evergrove operator is required.');
        $checklists->assertMembership($actor, $tenant);
        $project = DB::transaction(function () use ($tenant, $actor, $data): ClientProject {
            // Serialize imports for this tenant, including the first import.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $project = ClientProject::query()->forTenantId($tenant->id)->where('metadata->checklist_key', $data['key'])->first();
            $project ??= ClientProject::query()->create([
                'tenant_id' => $tenant->id, 'title' => $data['title'], 'summary' => $data['summary'],
                'status' => 'in_progress', 'health' => 'on_track', 'sort_order' => 0,
                'metadata' => ['checklist_key' => $data['key'], 'checklist_enabled' => true,
                    'client_checklist_label' => $data['client_label'], 'provider_checklist_label' => $data['provider_label']],
            ]);
            $added = 0;
            foreach ($data['groups'] as $group) {
                $ticket = $project->tickets()->where('tenant_id', $tenant->id)->where('metadata->checklist_group_key', $group['key'])->first();
                $ticket ??= ClientProjectTicket::query()->create([
                    'tenant_id' => $tenant->id, 'client_project_id' => $project->id,
                    'type' => 'change_request', 'title' => $group['title'], 'problem_summary' => $data['summary'],
                    'status' => 'in_progress', 'customer_visible' => true,
                    'requested_by_user_id' => $actor->id, 'metadata' => ['checklist_group_key' => $group['key']],
                ]);
                $metadata = $ticket->metadata ?? [];
                $keys = $metadata['checklist_task_keys'] ?? [];
                foreach ($group['tasks'] as $position => $item) {
                    if (isset($keys[$item['key']]) && $ticket->tasks()->where('tenant_id', $tenant->id)->whereKey($keys[$item['key']])->exists()) {
                        continue;
                    }
                    $task = ClientProjectTicketTask::query()->create([
                        'tenant_id' => $tenant->id, 'client_project_ticket_id' => $ticket->id,
                        'title' => $item['title'], 'details' => $item['details'], 'owner_type' => $item['owner'],
                        'status' => $item['complete'] ? 'done' : 'open',
                        'completed_at' => $item['complete'] ? now() : null, 'sort_order' => $position,
                    ]);
                    $keys[$item['key']] = $task->id;
                    $added++;
                }
                $ticket->update(['metadata' => array_merge($metadata, ['checklist_task_keys' => $keys])]);
            }
            app(LandlordOperatorActionAuditService::class)->record(
                tenantId: $tenant->id, actorUserId: $actor->id, actionType: 'client_project.checklist_imported',
                targetType: 'client_project', targetId: $project->id,
                result: ['tasks_added' => $added, 'checklist_key' => $data['key']],
            );

            return $project;
        });
        $this->info('Checklist ready for project '.$project->id.'. Existing progress preserved.');

        return self::SUCCESS;
    }
}
