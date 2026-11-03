<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Str;

/** Register-authorized identities. Contact matching suggests a selection, never a merge. */
final class PosCustomerDirectory
{
    public function __construct(private PosStore $store, private PosAccess $access,
        private CanonicalMarketingProfileResolver $identities) {}

    public function search(string $register, string $query): array
    {
        $scope = $this->access->register($register, 'catalog');
        if (mb_strlen(trim($query)) < 2) {
            throw new PosException('customer_query_required', 422);
        }
        $matches = MarketingProfile::query()->forTenantId($scope['tenant_id'])->whereNull('archived_at');
        $digits = preg_replace('/[^0-9]/', '', $query);
        if (preg_match('/^[+()0-9.\s-]+$/D', trim($query)) && strlen($digits) >= 7) {
            $phone = app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->normalizePhone($query);
            $matches->where(function ($q) use ($phone, $digits): void {
                if ($phone !== null) {
                    $q->where('normalized_phone', $phone);
                }
                $q->orWhere('normalized_phone', 'like', '%'.$digits.'%')
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '') LIKE ?", ['%'.$digits.'%']);
            });
        } else {
            foreach (preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($part)).'%';
                $matches->where(function ($q) use ($pattern): void {
                    foreach (['first_name', 'last_name', 'email', 'phone', 'normalized_email', 'normalized_phone'] as $column) {
                        $q->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$pattern]);
                    }
                });
            }
        }
        $found = $matches->orderBy('id')->limit(41)->get();
        $canonical = $this->identities->canonicalCollection($found, $scope['tenant_id']);
        if ($canonical === null) {
            throw new PosException('customer_identity_review_required');
        }

        return ['customers' => $canonical->filter(fn ($p) => $p->archived_at === null)->take(20)
            ->map(fn ($p) => $this->summary($p))->values()->all(), 'has_more' => $found->count() > 20];
    }

    /** Explicitly selected customer only; this reads existing source records and never links identities. */
    public function commerce(string $register, int $profileId): array
    {
        $scope = $this->access->register($register, 'catalog');
        $profile = $this->profile($scope['tenant_id'], $profileId);

        return ['customer' => $this->summary($profile),
            'commerce' => app(\App\Services\Marketing\CustomerCommerceSnapshotService::class)->forProfile($profile, $scope['tenant_id'])];
    }

    public function create(string $register, array $input): array
    {
        $scope = $this->access->register($register, 'quote');
        $key = $this->requestKey($scope, $input['request_id']);
        $values = ['first_name' => trim($input['first_name']), 'last_name' => trim($input['last_name'] ?? ''),
            'email' => filled($input['email'] ?? null) ? Str::lower(trim($input['email'])) : null,
            'phone' => filled($input['phone'] ?? null) ? trim($input['phone']) : null];
        $hash = Contract::hash($values);
        if ($values['first_name'] === '') {
            throw new PosException('customer_name_required', 422);
        }

        return $this->store->db->transaction(function () use ($scope, $key, $values, $hash): array {
            // Tenant admission also serializes identical contacts created at different registers.
            Tenant::query()->whereKey($scope['tenant_id'])->lockForUpdate()->firstOrFail();
            if (! app(PosCustomerEnrollment::class)->canCreateCustomer($scope)) {
                throw new PosException('customer_admin_required', 403);
            }
            $prior = $this->creation($scope, $key);
            if ($prior) {
                if (! hash_equals($prior['hash'], $hash)) {
                    throw new PosException('customer_request_conflict');
                }

                return ['customer' => $this->summary($this->profile($scope['tenant_id'], $prior['profile_id']))];
            }
            foreach (['email', 'phone'] as $field) {
                if ($values[$field] !== null && MarketingProfile::query()->forTenantId($scope['tenant_id'])
                    ->where(function ($query) use ($field, $values): void {
                        $query->whereRaw('LOWER('.$field.') = ?', [Str::lower($values[$field])]);
                        $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
                        $normalized = $field === 'email' ? $normalizer->normalizeEmail($values[$field]) : $normalizer->normalizePhone($values[$field]);
                        if ($normalized !== null) {
                            $query->orWhere('normalized_'.$field, $normalized);
                        }
                    })->exists()) {
                    throw new PosException('select_existing_customer', 409);
                }
            }
            $profile = MarketingProfile::query()->create(['tenant_id' => $scope['tenant_id'], ...$values,
                'normalized_email' => $values['email'], 'normalized_phone' => app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->normalizePhone($values['phone']), 'accepts_email_marketing' => false,
                'accepts_sms_marketing' => false, 'source_channels' => ['everbranch_pay_manual']]);
            $this->store->event($scope['tenant_id'], $key, 'customer_created', ['hash' => $hash,
                'profile_id' => $profile->id, 'actor_id' => $scope['actor_id'], 'register_id' => $scope['register']->id]);

            return ['customer' => $this->summary($profile)];
        }, 3);
    }

    public function request(string $register, string $requestId): array
    {
        $scope = $this->access->register($register, 'catalog');
        $prior = $this->creation($scope, $this->requestKey($scope, $requestId));
        if (! $prior) {
            throw new PosException('customer_request_not_found', 404);
        }

        return ['customer' => $this->summary($this->profile($scope['tenant_id'], $prior['profile_id']))];
    }

    public function freeze(array $scope, int $id): array
    {
        $profile = $this->profile($scope['tenant_id'], $id);
        $result = $this->summary($profile);
        $result['shopify_customer_id'] = null;
        if ($scope['register']->catalog_source === 'shopify') {
            $settings = $this->store->decrypt($scope['register']->configuration)['shopify'] ?? [];
            $store = ShopifyStore::forTenantId($scope['tenant_id'])->find($settings['store_id'] ?? 0);
            if (! $store || $store->shop_domain !== ($settings['shop_domain'] ?? null)) {
                throw new PosException('shopify_binding_unverified', 503);
            }
            $prefix = $store->store_key.':';
            $links = MarketingProfileLink::query()->forTenantId($scope['tenant_id'])->where('source_type', 'shopify_customer')
                ->whereRaw("source_id LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%'])->get();
            $ids = [];
            foreach ($links as $link) {
                if ($this->identities->byId($scope['tenant_id'], (int) $link->marketing_profile_id)?->id === $profile->id
                    && str_starts_with($link->source_id, $prefix)) {
                    $customer = substr($link->source_id, strlen($prefix));
                    if (preg_match('/^[1-9][0-9]{0,19}$/D', $customer)) {
                        $conflicts = $links->where('source_id', $link->source_id)->contains(fn ($other) => $this->identities->byId($scope['tenant_id'], (int) $other->marketing_profile_id)?->id !== $profile->id);
                        if ($conflicts) {
                            throw new PosException('customer_identity_review_required');
                        }
                        $ids[] = 'gid://shopify/Customer/'.$customer;
                    }
                }
            }
            $ids = array_values(array_unique($ids));
            if (count($ids) > 1) {
                throw new PosException('customer_identity_review_required');
            }
            $result['shopify_customer_id'] = $ids[0] ?? null;
            $result['shopify_store_key'] = $store->store_key;
        }

        return $result;
    }

    public function profile(int $tenant, int $id): MarketingProfile
    {
        $profile = $this->identities->byId($tenant, $id);
        if (! $profile || $profile->archived_at !== null) {
            throw new PosException('customer_unavailable', 404);
        }

        return $profile;
    }

    public function linkSale(array $scope, string $sale, int $profileId): void
    {
        MarketingProfile::query()->forTenantId($scope['tenant_id'])->whereKey($profileId)->lockForUpdate()->firstOrFail();
        if ($this->profile($scope['tenant_id'], $profileId)->id !== $profileId) {
            throw new PosException('customer_selection_changed');
        }
        MarketingProfileLink::query()->create(['tenant_id' => $scope['tenant_id'], 'marketing_profile_id' => $profileId,
            'source_type' => 'pos_sale', 'source_id' => $this->store->runtime().':'.$sale,
            'match_method' => 'register_selected', 'source_meta' => ['actor_id' => $scope['actor_id'],
                'register_id' => $scope['register']->id, 'selected_at' => now()->toIso8601String()]]);
    }

    private function summary(MarketingProfile $profile): array
    {
        return ['id' => (int) $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name),
            'email' => $profile->email, 'phone' => $profile->phone];
    }

    private function requestKey(array $scope, string $request): string
    {
        return substr(Contract::hash(['customer-create', $this->store->runtime(), $scope['tenant_id'],
            $scope['register']->id, $scope['actor_id'], $scope['device_id'], strtolower($request)]), 0, 32);
    }

    private function creation(array $scope, string $key): ?array
    {
        $row = $this->store->rows('events', $scope['tenant_id'])->where('sale_id', $key)->where('code', 'customer_created')->first();

        return $row ? $this->store->decrypt($row->details) : null;
    }
}
