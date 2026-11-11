<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Services\Pos\PosException;
use Illuminate\Support\Str;

/** Shared, read-only lookup. Callers must authorize the current tenant and customer capability. */
class TenantCustomerLookup
{
    public function __construct(private CanonicalMarketingProfileResolver $identities) {}

    public function search(int $tenant, string $query, ?array $profileIds = null, int $page = 1): array
    {
        abort_unless($tenant > 0 && $page > 0 && $page <= 100000, 422);
        if ($profileIds === null && mb_strlen(trim($query)) < 2) {
            throw new PosException('customer_query_required', 422);
        }
        $matches = MarketingProfile::query()->forTenantId($tenant)->whereNull('archived_at');
        if ($profileIds !== null) {
            $matches->whereIn('id', $profileIds);
        }
        $digits = preg_replace('/[^0-9]/', '', $query);
        if (preg_match('/^[+()0-9.\s-]+$/D', trim($query)) && strlen($digits) >= 7) {
            $phone = app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->normalizePhone($query);
            if ($phone !== null && (clone $matches)->where('normalized_phone', $phone)->exists()) {
                $matches->where('normalized_phone', $phone);
            } else {
                $matches->where(function ($q) use ($digits): void {
                    $q->where('normalized_phone', 'like', '%'.$digits.'%')
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '') LIKE ?", ['%'.$digits.'%']);
                });
            }
        } elseif (str_contains(trim($query), '@') && (clone $matches)->where('normalized_email', Str::lower(trim($query)))->exists()) {
            $matches->where('normalized_email', Str::lower(trim($query)));
        } elseif (trim($query) !== '') {
            foreach (preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($part)).'%';
                $matches->where(function ($q) use ($pattern): void {
                    foreach (['first_name', 'last_name', 'email', 'phone', 'normalized_email', 'normalized_phone'] as $column) {
                        $q->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$pattern]);
                    }
                });
            }
        }
        $found = $matches->orderBy('id')->skip(($page - 1) * 20)->limit(21)->get();
        $canonical = $this->identities->canonicalCollection($found, $tenant);
        if ($canonical === null) {
            throw new PosException('customer_identity_review_required');
        }

        return ['customers' => $canonical->filter(fn ($p) => $p->archived_at === null)->take(20)
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (trim($p->first_name.' '.$p->last_name) ?: ($p->email ?: $p->phone ?: 'Customer')), 'email' => $p->email, 'phone' => $p->phone])->values()->all(), 'has_more' => $found->count() > 20, 'page' => $page];
    }

    public function details(int $tenant, int $id): array
    {
        $profile = $this->identities->byId($tenant, $id);
        abort_unless($profile && $profile->archived_at === null, 404);

        return ['customer' => ['id' => $profile->id, 'name' => (trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: $profile->phone ?: 'Customer')), 'email' => $profile->email, 'phone' => $profile->phone],
            'commerce' => app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant)];
    }
}
