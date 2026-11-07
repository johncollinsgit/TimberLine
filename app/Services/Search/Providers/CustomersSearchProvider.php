<?php

namespace App\Services\Search\Providers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CustomerProfileAccess;
use App\Services\Marketing\TenantCustomerLookup;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Support\Facades\Schema;

class CustomersSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        $normalized = trim($query);
        if (! $user instanceof User || ! $tenant || mb_strlen($normalized) < 2
            || ! app(CustomerProfileAccess::class)->allows($user, $tenant) || ! Schema::hasTable('marketing_profiles')) {
            return [];
        }
        $rows = app(TenantCustomerLookup::class)->search($tenantId, $normalized)['customers'];

        return collect($rows)->take(5)->map(function (array $profile) use ($normalized, $tenant): array {
            $title = trim($profile['name']) ?: ($profile['email'] ?: 'Customer');
            $subtitle = trim(implode(' • ', array_filter([
                $profile['email'],
                $profile['phone'],
            ])));

            return $this->result([
                'type' => 'customer',
                'subtype' => 'profile',
                'title' => $title,
                'subtitle' => $subtitle,
                'url' => route('customers.profile', ['profile' => $profile['id'], 'tenant' => $tenant->slug]),
                'badge' => 'Customer',
                // The lookup already matched normalized phones/literal terms or an explicit alias.
                'score' => max(320, $this->matchScore($normalized, [$title, $profile['email'], $profile['phone']], 320)),
                'icon' => 'users',
                'meta' => [
                    'profile_id' => (int) $profile['id'],
                ],
            ]);
        })->all();
    }
}
