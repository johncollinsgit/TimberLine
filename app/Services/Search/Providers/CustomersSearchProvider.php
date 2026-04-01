<?php

namespace App\Services\Search\Providers;

use App\Models\MarketingProfile;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Support\Facades\Schema;

class CustomersSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        $user = $context['user'] ?? null;
        if (! $user || ! method_exists($user, 'canAccessMarketing') || ! $user->canAccessMarketing()) {
            return [];
        }

        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        if ($tenantId === null || ! Schema::hasTable('marketing_profiles')) {
            return [];
        }

        $normalized = trim($query);
        $rows = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->select(['id', 'first_name', 'last_name', 'email', 'phone'])
            ->when($normalized !== '', function ($builder) use ($normalized): void {
                $builder->where(function ($query) use ($normalized): void {
                    $like = '%'.$normalized.'%';
                    $query->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            })
            ->limit(5)
            ->get();

        return $rows->map(function (MarketingProfile $profile) use ($normalized): array {
            $fullName = trim($profile->first_name.' '.$profile->last_name);
            $title = $fullName !== '' ? $fullName : ($profile->email ?: 'Customer');
            $subtitle = trim(implode(' • ', array_filter([
                $profile->email,
                $profile->phone,
            ])));

            return $this->result([
                'type' => 'customer',
                'subtype' => 'profile',
                'title' => $title,
                'subtitle' => $subtitle,
                'url' => route('marketing.customers.show', ['marketingProfile' => $profile->id]),
                'badge' => 'Customer',
                'score' => $this->matchScore($normalized, [$title, $profile->email, $profile->phone], 320),
                'icon' => 'users',
                'meta' => [
                    'profile_id' => (int) $profile->id,
                ],
            ]);
        })->all();
    }
}
