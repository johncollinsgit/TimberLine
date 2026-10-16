<?php

namespace App\Services\AiBranch;

use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\CollinsClientAccessService;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Tenancy\TenantModuleAccessResolver;

class AiBranchContext
{
    public function __construct(private TenantModuleAccessResolver $modules, private FieldServiceAccessService $jobs, private CollinsClientAccessService $clients) {}

    public function scopeHash(Tenant $tenant, User $user): string
    {
        if (app(AiWorkspaceContext::class)->enabled($tenant)) {
            return app(AiWorkspaceContext::class)->stamp($tenant, $user);
        }

        return hash('sha256', json_encode([
            app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'field_service'),
            $this->clients->allows($user, $tenant), $this->jobs->role($user, $tenant),
            \App\Models\AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('expires_at', '>', now())->orderBy('id')->pluck('id')->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function sources(Tenant $tenant, User $user): array
    {
        if (! app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'field_service') || ! $this->clients->allows($user, $tenant)) {
            return [];
        }

        return $this->jobs->scopeVisibleJobs(FieldServiceJob::query()->where('tenant_id', $tenant->id), $user, $tenant)
            ->notGeneratedQuickBooksInvoice()->whereNull('archived_at')->latest('id')->limit(10)
            ->get(['id', 'title', 'status', 'scheduled_for'])->map(fn ($job) => [
                'ref' => 'job:'.$job->id, 'id' => $job->id, 'title' => mb_substr($job->title, 0, 180),
                'status' => $job->status, 'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            ])->all();
    }

    public function job(Tenant $tenant, User $user, int $id): FieldServiceJob
    {
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'field_service') && $this->clients->allows($user, $tenant), 403);
        $job = FieldServiceJob::query()->where('tenant_id', $tenant->id)->whereKey($id)->whereNull('archived_at')->firstOrFail();
        abort_unless($this->jobs->canAccessJob($user, $tenant, $job) && $this->jobs->canManageJobs($user, $tenant), 403);

        return $job;
    }
}
