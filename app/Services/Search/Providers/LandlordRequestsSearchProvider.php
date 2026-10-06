<?php

namespace App\Services\Search\Providers;

use App\Models\TenantModuleAccessRequest;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\LandlordSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LandlordRequestsSearchProvider implements LandlordSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        if (! Schema::hasTable('tenant_module_access_requests')) {
            return [];
        }

        $normalized = trim($query);
        $rows = TenantModuleAccessRequest::query()
            ->with('tenant:id,name,slug')
            ->select(['id', 'tenant_id', 'module_key', 'status', 'source', 'requested_at'])
            ->when($normalized !== '', function (Builder $builder) use ($normalized): void {
                $like = '%'.$normalized.'%';
                $builder->where(function (Builder $search) use ($like): void {
                    $search->where('module_key', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhere('source', 'like', $like)
                        ->orWhereHas('tenant', fn (Builder $tenant) => $tenant
                            ->where('name', 'like', $like)
                            ->orWhere('slug', 'like', $like));
                });
            })
            ->latest('requested_at')
            ->limit($normalized === '' ? 2 : 6)
            ->get();

        return $rows->map(function (TenantModuleAccessRequest $request) use ($normalized): array {
            $moduleLabel = (string) config(
                'module_catalog.modules.'.$request->module_key.'.display_name',
                Str::headline((string) $request->module_key)
            );
            $tenantName = (string) ($request->tenant?->name ?? 'Workspace');

            return $this->result([
                'type' => 'request',
                'subtype' => 'module_access',
                'title' => $moduleLabel.' request',
                'subtitle' => $tenantName.' • '.Str::headline((string) $request->status),
                'url' => route('landlord.tenants.show', ['tenant' => $request->tenant_id]).'#applications',
                'badge' => 'Access request',
                'score' => $this->matchScore($normalized, [
                    $moduleLabel,
                    $request->module_key,
                    $request->status,
                    $tenantName,
                    $request->tenant?->slug,
                ], 265),
                'icon' => 'inbox',
                'meta' => [
                    'request_id' => (int) $request->id,
                    'tenant_id' => (int) $request->tenant_id,
                    'control_plane_only' => true,
                ],
            ]);
        })->all();
    }
}
