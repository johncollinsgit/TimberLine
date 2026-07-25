<?php

namespace App\Services\Search\Providers;

use App\Models\Tenant;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\LandlordSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class LandlordTenantsSearchProvider implements LandlordSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        if (! Schema::hasTable('tenants')) {
            return [];
        }

        $normalized = trim($query);
        $relations = [];
        if (Schema::hasTable('tenant_access_profiles')) {
            $relations[] = 'accessProfile:id,tenant_id,plan_key,operating_mode,metadata';
        }
        if (Schema::hasTable('tenant_setup_statuses')) {
            $relations[] = 'setupStatus:id,tenant_id,business_profile_status,import_path,shopify_connection_status,plan_interest,billing_lane_interest,landlord_review_status';
        }

        $rows = Tenant::query()
            ->select(['id', 'name', 'slug', 'created_at', 'updated_at'])
            ->with($relations)
            ->when($normalized !== '', function (Builder $builder) use ($normalized): void {
                $like = '%'.$normalized.'%';
                $builder->where(function (Builder $search) use ($like): void {
                    $search->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like);

                    if (Schema::hasTable('tenant_access_profiles')) {
                        $search->orWhereHas('accessProfile', fn (Builder $profile) => $profile
                            ->where('plan_key', 'like', $like)
                            ->orWhere('operating_mode', 'like', $like));
                    }

                    if (Schema::hasTable('tenant_setup_statuses')) {
                        $search->orWhereHas('setupStatus', fn (Builder $setup) => $setup
                            ->where('business_profile_status', 'like', $like)
                            ->orWhere('import_path', 'like', $like)
                            ->orWhere('shopify_connection_status', 'like', $like)
                            ->orWhere('plan_interest', 'like', $like)
                            ->orWhere('billing_lane_interest', 'like', $like)
                            ->orWhere('landlord_review_status', 'like', $like));
                    }
                });
            })
            ->latest('updated_at')
            ->limit($normalized === '' ? 4 : 8)
            ->get();

        return $rows->map(function (Tenant $tenant) use ($normalized): array {
            $plan = trim((string) ($tenant->accessProfile?->plan_key ?? $tenant->setupStatus?->plan_interest ?? ''));
            $setup = trim((string) ($tenant->setupStatus?->landlord_review_status ?? $tenant->setupStatus?->business_profile_status ?? ''));
            $mode = trim((string) data_get($tenant->accessProfile?->metadata, 'account_mode', 'production'));
            $subtitle = collect([
                (string) $tenant->slug,
                $plan !== '' ? str($plan)->headline()->toString().' plan' : null,
                $setup !== '' ? str($setup)->headline()->toString() : null,
                $mode !== 'production' ? str($mode)->headline()->toString() : null,
            ])->filter()->implode(' • ');

            return $this->result([
                'type' => 'workspace',
                'subtype' => 'tenant_control_plane',
                'title' => (string) $tenant->name,
                'subtitle' => $subtitle,
                'url' => route('landlord.tenants.show', ['tenant' => $tenant->id]),
                'badge' => 'Workspace',
                'score' => $this->matchScore($normalized, [
                    $tenant->name,
                    $tenant->slug,
                    $plan,
                    $setup,
                    $mode,
                    $tenant->setupStatus?->import_path,
                    $tenant->setupStatus?->billing_lane_interest,
                ], 340),
                'icon' => 'building-office-2',
                'meta' => [
                    'tenant_id' => (int) $tenant->id,
                    'control_plane_only' => true,
                ],
            ]);
        })->all();
    }
}
