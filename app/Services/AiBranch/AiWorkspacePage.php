<?php

namespace App\Services\AiBranch;

use App\Models\MarketingProfile;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiWorkspacePage
{
    public function descriptor(Request $request): array
    {
        $parameters = match ($request->route()?->getName()) {
            'field-service.jobs.show' => ['job', 'job'],
            'marketing.customers.show' => ['customer', 'marketingProfile'],
            'class-scheduling.show' => ['class', 'scheduledClass'],
            default => null,
        };
        if (! $parameters) {
            return ['kind' => 'workspace'];
        }
        $record = $request->route($parameters[1]);
        $id = $record instanceof \Illuminate\Database\Eloquent\Model ? $record->getKey() : $record;

        return is_numeric($id) ? ['kind' => $parameters[0], 'id' => (int) $id] : ['kind' => 'workspace'];
    }

    public function launcherUrl(Request $request, Tenant $tenant): string
    {
        return route('ai-branch.index', ['tenant' => $tenant->slug,
            ...(app(AiWorkspaceContext::class)->enabled($tenant) ? ['origin' => $this->descriptor($request)] : []),
        ]);
    }

    public function normalize(array $origin): array
    {
        if ($origin === []) {
            return ['kind' => 'workspace'];
        }
        $data = Validator::make(['origin' => $origin], [
            'origin' => ['array:kind,id'], 'origin.kind' => ['required', Rule::in(['workspace', 'job', 'task', 'customer', 'class', 'followup'])],
            'origin.id' => ['required_unless:origin.kind,workspace', 'integer', 'min:1'],
        ])->validate()['origin'];
        abort_if($data['kind'] === 'workspace' && isset($data['id']), 422);

        return ['kind' => $data['kind'], ...(isset($data['id']) ? ['id' => (int) $data['id']] : [])];
    }

    public function resolve(Tenant $tenant, User $user, array $origin): array
    {
        $origin = $this->normalize($origin);
        if ($origin['kind'] === 'workspace') {
            return ['page' => ['kind' => 'workspace', 'title' => 'Workspace'], 'record' => null];
        }
        $permissions = app(AiWorkspaceContext::class)->permissions($tenant, $user);
        $sources = app(AiWorkspaceSources::class);
        $kind = $origin['kind'];
        $id = $origin['id'];
        abort_unless($permissions[match ($kind) {
            'job' => 'jobs', 'task' => 'tasks', 'customer' => 'customers', 'class' => 'scheduling', 'followup' => 'followups'
        }], 403);
        $record = match ($kind) {
            'job' => $sources->jobRecord($sources->jobsQuery($tenant, $user)->findOrFail($id), $tenant),
            'task' => $sources->taskRecord($sources->tasksQuery($tenant, $user)->whereKey($id)->firstOrFail(), $tenant),
            'customer' => $sources->customerRecord(MarketingProfile::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id')->select(['id', 'first_name', 'last_name'])->findOrFail($id), $tenant),
            'class' => $sources->classRecord(ScheduledClass::query()->where('tenant_id', $tenant->id)->where('status', 'published')->select(['id', 'title', 'status', 'starts_at'])->findOrFail($id), $tenant),
            'followup' => $sources->followupRecord($sources->followupsQuery($tenant, $user)->findOrFail($id), $tenant),
        };

        return ['page' => ['kind' => $kind, 'id' => $id, 'title' => $record['title'], 'url' => $record['url']], 'record' => $record];
    }

    public function assertHistory(Tenant $tenant, User $user, array $messages): void
    {
        $sources = app(AiWorkspaceSources::class);
        foreach (collect($messages)->flatMap(fn ($message) => $message['sources'] ?? [])->unique('ref') as $source) {
            $id = (int) $source['id'];
            $valid = match ($source['type'] ?? 'job') {
                'job' => $sources->jobsQuery($tenant, $user)->whereKey($id)->exists(),
                'task' => $sources->tasksQuery($tenant, $user, false)->whereKey($id)->exists(),
                'followup' => $sources->followupsQuery($tenant, $user, false)->whereKey($id)->exists(),
                'customer' => MarketingProfile::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id')->whereKey($id)->exists(),
                'class' => ScheduledClass::query()->where('tenant_id', $tenant->id)->where('status', 'published')->whereKey($id)->exists(),
                'connection' => \App\Models\IntegrationConnection::query()->where('tenant_id', $tenant->id)->whereKey($id)->exists(),
                'task_activity' => \App\Models\FieldServiceTaskEvent::query()->where('tenant_id', $tenant->id)->whereKey($id)
                    ->whereHas('task', fn ($task) => $task->where('tenant_id', $tenant->id)->whereHas('job', fn ($job) => $job->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('metadata->recycled_at')->notGeneratedQuickBooksInvoice()))->exists(),
                'customer_activity' => \App\Models\CustomerLoopActivity::query()->where('tenant_id', $tenant->id)->whereKey($id)
                    ->whereHas('profile', fn ($profile) => $profile->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id'))->exists(),
                default => false,
            };
            abort_unless($valid, 409, 'A source is no longer accessible. Start a new chat.');
        }
    }
}
