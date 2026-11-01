<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\ShopifyStore;
use App\Services\PaymentsCore\Contract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Exclusive, public, whole-order codes only. Unsupported offer semantics fail closed. */
final class PosShopifyOnlineDiscount
{
    private const CREATE = <<<'GRAPHQL'
mutation CreateBasicDiscount($input: DiscountCodeBasicInput!) {
  discountCodeBasicCreate(basicCodeDiscount: $input) {
    codeDiscountNode { id }
    userErrors { field message code }
  }
}
GRAPHQL;

    private const FIND_BY_CODE = <<<'GRAPHQL'
query DiscountByCode($code: String!) {
  codeDiscountNodeByCode(code: $code) {
    id
    codeDiscount { __typename ... on DiscountCodeBasic { title shortSummary } }
  }
}
GRAPHQL;

    private const STORE_CURRENCY = 'query DiscountStoreCurrency { shop { id currencyCode } }';

    public function __construct(private PosCommerceRuleStore $rules, private PosStore $pos) {}

    /** Prepare a single exclusive code. An owner must explicitly acknowledge public eligibility. */
    public function prepare(array $rule, string $code, string $startsAt, bool $publicEligibilityConfirmed): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $startsAt, new \DateTimeZone('UTC'));
        if (! $publicEligibilityConfirmed || ! preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/D', $code)
            || ! $date || $date->format('Y-m-d\TH:i:s\Z') !== $startsAt
            || ! is_string($rule['name'] ?? null) || trim($rule['name']) !== $rule['name'] || $rule['name'] === ''
            || ($rule['active'] ?? null) !== true || ! in_array('online', $rule['channels'] ?? [], true)
            || ($rule['scopes'] ?? null) !== ['sale'] || ! in_array($rule['kind'] ?? null, ['fixed', 'percent'], true)
            || ! is_int($rule['value'] ?? null) || $rule['value'] < 1
            || $rule['value'] > (($rule['kind'] ?? null) === 'percent' ? 10000 : 100000000)) {
            throw new PosException('shopify_online_discount_unsupported', 422);
        }
        $value = $rule['kind'] === 'percent'
            ? ['percentage' => $rule['value'] / 10000]
            : ['discountAmount' => ['amount' => sprintf('%d.%02d', intdiv($rule['value'], 100), $rule['value'] % 100), 'appliesOnEachItem' => false]];

        return ['title' => $rule['name'], 'code' => $code, 'startsAt' => $startsAt,
            'context' => ['all' => 'ALL'], 'customerGets' => ['items' => ['all' => true], 'value' => $value,
                'appliesOnSubscription' => false, 'appliesOnOneTimePurchase' => true],
            'combinesWith' => ['productDiscounts' => false, 'orderDiscounts' => false, 'shippingDiscounts' => false]];
    }

    /** Read-only owner review. No Shopify request is made. */
    public function review(array $scope, string $registerId, string $version, string $ruleId, string $code, string $startsAt, bool $publicEligibilityConfirmed): array
    {
        $review = $this->rules->reviewStatus($scope, $registerId);
        if (! hash_equals($review['version'], $version) || $review['reviewed_version'] !== $version) {
            throw new PosException('checkout_rules_review_required', 503);
        }
        $rule = collect($review['rules'])->firstWhere('id', $ruleId);
        if (! is_array($rule)) {
            throw new PosException('checkout_rule_not_found', 404);
        }
        $input = $this->prepare($rule, $code, $startsAt, $publicEligibilityConfirmed);
        $register = $this->pos->rows('registers', $scope['tenant_id'])->where('id', $registerId)->where('active', true)->firstOrFail();
        $settings = $this->pos->decrypt($register->configuration);
        $storeId = $settings['shopify']['store_id'] ?? null;
        $store = ShopifyStore::withoutGlobalScopes()->where('tenant_id', $scope['tenant_id'])->whereKey($storeId)->first();
        if (! $store || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', (string) $store->shop_domain) || ! $store->access_token) {
            throw new PosException('shopify_online_store_unavailable', 503);
        }
        $previous = DB::table('pos_shopify_discount_publications')->where('tenant_id', $scope['tenant_id'])
            ->where('shopify_store_id', $store->id)->where('rule_id', $ruleId)->first(['status', 'code', 'shopify_discount_id', 'lookup_state', 'lookup_discount_id', 'lookup_at']);

        return ['input' => $input, 'shop_domain' => $store->shop_domain, 'enabled' => config('pos_live.shopify_online_discounts_enabled') === true,
            'previous' => $previous];
    }

    /** Record provider lookup evidence without attributing a matching third-party code to us. */
    public function reconcile(array $scope, string $registerId, string $ruleId): array
    {
        if (config('pos_live.shopify_online_discounts_enabled') !== true) {
            throw new PosException('shopify_online_discount_unavailable', 503);
        }
        $this->rules->reviewStatus($scope, $registerId); // Enforces tenant-admin and register access.
        $register = $this->pos->rows('registers', $scope['tenant_id'])->where('id', $registerId)->where('active', true)->firstOrFail();
        $settings = $this->pos->decrypt($register->configuration);
        $store = ShopifyStore::withoutGlobalScopes()->where('tenant_id', $scope['tenant_id'])->whereKey($settings['shopify']['store_id'] ?? null)->first();
        if (! $store || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', (string) $store->shop_domain) || ! $store->access_token) {
            throw new PosException('shopify_online_store_unavailable', 503);
        }
        $table = DB::table('pos_shopify_discount_publications');
        $row = (clone $table)->where('tenant_id', $scope['tenant_id'])->where('shopify_store_id', $store->id)->where('rule_id', $ruleId)->first();
        if (! $row) {
            throw new PosException('shopify_online_discount_not_found', 404);
        }
        if ($row->status === 'published') {
            return ['status' => 'published', 'id' => $row->shopify_discount_id];
        }
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token, 'Accept' => 'application/json'])
                ->timeout(30)->post('https://'.$store->shop_domain.'/admin/api/2026-01/graphql.json', ['query' => self::FIND_BY_CODE, 'variables' => ['code' => $row->code]]);
            $json = $response->json();
            if (! $response->successful() || ! is_array($json) || ! empty($json['errors'])
                || ! is_array($json['data'] ?? null) || ! array_key_exists('codeDiscountNodeByCode', $json['data'])) {
                throw new \RuntimeException('Unconfirmed lookup');
            }
            $node = $json['data']['codeDiscountNodeByCode'];
            if ($node !== null && (! is_array($node) || ! is_string($node['id'] ?? null)
                || ! preg_match('/^gid:\/\/shopify\/DiscountCodeNode\/[0-9]+$/D', $node['id']))) {
                throw new \RuntimeException('Malformed lookup');
            }
            $state = $node === null ? 'not_found_unverified' : 'found_unverified';
            $id = $node['id'] ?? null;
        } catch (\Throwable) {
            $state = 'lookup_failed';
            $id = null;
        }
        (clone $table)->where('request_id', $row->request_id)->whereIn('status', ['starting', 'review'])->update(['status' => 'review', 'lookup_state' => $state,
            'lookup_discount_id' => $id, 'lookup_at' => now(), 'updated_at' => now()]);

        $latest = (clone $table)->where('request_id', $row->request_id)->first();
        if ($latest?->status === 'published') {
            return ['status' => 'published', 'id' => $latest->shopify_discount_id];
        }

        return ['status' => 'review', 'lookup_state' => $state, 'lookup_discount_id' => $id];
    }

    /** Publication stays off until channel acceptance and the owner workflow are approved. */
    public function publish(array $scope, string $registerId, string $version, string $ruleId, string $code,
        string $startsAt, string $requestId, bool $publicEligibilityConfirmed): array
    {
        if (config('pos_live.shopify_online_discounts_enabled') !== true) {
            throw new PosException('shopify_online_discount_unavailable', 503);
        }
        $cacheDriver = config('cache.stores.'.config('cache.default').'.driver');
        if (! app()->environment('testing') && (DB::connection()->getDriverName() !== 'mysql'
            || ! in_array($cacheDriver, ['database', 'redis', 'memcached', 'dynamodb'], true))) {
            throw new PosException('shopify_online_discount_shared_lock_required', 503);
        }
        if (($scope['admin'] ?? false) !== true || ! is_int($scope['tenant_id'] ?? null)
            || ! is_int($scope['actor_id'] ?? null) || ! Str::isUuid($requestId)) {
            throw new PosException('organization_admin_required', 403);
        }

        return $this->rules->withLock($scope['tenant_id'], fn () => $this->publishReviewed(
            $scope, $registerId, $version, $ruleId, $code, $startsAt, $requestId, $publicEligibilityConfirmed));
    }

    private function publishReviewed(array $scope, string $registerId, string $version, string $ruleId, string $code,
        string $startsAt, string $requestId, bool $publicEligibilityConfirmed): array
    {
        $review = $this->rules->reviewStatus($scope, $registerId);
        if (! hash_equals($review['version'], $version) || $review['reviewed_version'] !== $version) {
            throw new PosException('checkout_rules_review_required', 503);
        }
        $rule = collect($review['rules'])->firstWhere('id', $ruleId);
        if (! is_array($rule)) {
            throw new PosException('checkout_rule_not_found', 404);
        }
        $input = $this->prepare($rule, $code, $startsAt, $publicEligibilityConfirmed);
        $register = $this->pos->rows('registers', $scope['tenant_id'])->where('id', $registerId)->where('active', true)->firstOrFail();
        $settings = $this->pos->decrypt($register->configuration);
        $storeId = $settings['shopify']['store_id'] ?? null;
        $store = ShopifyStore::withoutGlobalScopes()->where('tenant_id', $scope['tenant_id'])->whereKey($storeId)->first();
        if (! $store || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', (string) $store->shop_domain) || ! $store->access_token) {
            throw new PosException('shopify_online_store_unavailable', 503);
        }
        $hash = Contract::hash(['tenant_id' => $scope['tenant_id'], 'store_id' => $store->id, 'rule_id' => $ruleId, 'version' => $version, 'input' => $input]);
        $table = DB::table('pos_shopify_discount_publications');
        $existing = (clone $table)->where('request_id', $requestId)->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $hash)) {
                throw new PosException('shopify_online_discount_request_conflict', 409);
            }
            if ($existing->status === 'published') {
                return ['id' => $existing->shopify_discount_id, 'status' => 'published'];
            }
            throw new PosException('shopify_online_discount_review_required', 409);
        }
        if ((clone $table)->where('tenant_id', $scope['tenant_id'])->where('shopify_store_id', $store->id)
            ->where(fn ($query) => $query->where('rule_id', $ruleId)->orWhere('code', $code))->exists()) {
            throw new PosException('shopify_online_discount_review_required', 409);
        }
        try {
            // POS amounts are USD cents. Never publish them to a store with an unknown or different currency.
            $currency = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token, 'Accept' => 'application/json'])
                ->timeout(30)->post('https://'.$store->shop_domain.'/admin/api/2026-01/graphql.json', ['query' => self::STORE_CURRENCY, 'variables' => (object) []]);
            $currencyData = $currency->json();
            if (! $currency->successful() || ! is_array($currencyData) || ! empty($currencyData['errors'])
                || ($currencyData['data']['shop']['currencyCode'] ?? null) !== 'USD'
                || ! preg_match('/^gid:\/\/shopify\/Shop\/[0-9]+$/D', $currencyData['data']['shop']['id'] ?? '')) {
                throw new \RuntimeException('Unverified Shopify currency');
            }
        } catch (\Throwable) {
            throw new PosException('shopify_online_currency_unverified', 503);
        }
        try {
            (clone $table)->insert(['request_id' => $requestId, 'tenant_id' => $scope['tenant_id'], 'shopify_store_id' => $store->id,
                'rule_id' => $ruleId, 'rule_version' => $version, 'code' => $code, 'request_hash' => $hash,
                'status' => 'starting', 'actor_id' => $scope['actor_id'], 'created_at' => now(), 'updated_at' => now()]);
        } catch (QueryException) {
            throw new PosException('shopify_online_discount_review_required', 409);
        }
        try {
            // A single request only: timeouts and provider errors require review, never an automatic second create.
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token, 'Accept' => 'application/json'])
                ->timeout(30)->post('https://'.$store->shop_domain.'/admin/api/2026-01/graphql.json', ['query' => self::CREATE, 'variables' => ['input' => $input]]);
            $json = $response->json();
            $result = is_array($json) ? ($json['data']['discountCodeBasicCreate'] ?? null) : null;
            $id = is_array($result) ? ($result['codeDiscountNode']['id'] ?? null) : null;
            if (! $response->successful() || ! empty($json['errors']) || ! is_array($result)
                || ! is_array($result['userErrors'] ?? null) || $result['userErrors'] !== []
                || ! is_string($id) || ! preg_match('/^gid:\/\/shopify\/DiscountCodeNode\/[0-9]+$/D', $id)) {
                throw new \RuntimeException('Unconfirmed Shopify discount publication');
            }
        } catch (\Throwable) {
            (clone $table)->where('request_id', $requestId)->update(['status' => 'review', 'updated_at' => now()]);
            throw new PosException('shopify_online_discount_review_required', 503);
        }
        (clone $table)->where('request_id', $requestId)->update(['status' => 'published', 'shopify_discount_id' => $id, 'updated_at' => now()]);

        return ['id' => $id, 'status' => 'published'];
    }
}
