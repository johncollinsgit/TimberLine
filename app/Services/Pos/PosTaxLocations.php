<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockShopifyApi;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\Cache;

/** Reviewed register tax locations; no address or tax rate is accepted from a cashier. */
final class PosTaxLocations
{
    public function __construct(private PosStore $store, private StockLedger $stock, private StockShopifyApi $shopify) {}

    public function options(array $scope): array
    {
        $this->authorize($scope);

        return $this->readOptions($scope);
    }

    private function readOptions(array $scope, bool $lockEvent = false): array
    {
        $settings = app(StockPosCommerce::class)->settings($scope);
        if ($scope['register']->catalog_source !== 'shopify' || ! is_int($settings['shopify']['store_id'] ?? null)
            || ! is_string($settings['shopify']['shop_id'] ?? null)) {
            throw new PosException('tax_location_provider_unavailable', 503);
        }
        $pool = $this->stock->pool($scope['tenant_id'], $settings['inventory_pool_id']);
        $candidates = [];
        if ($pool->kind === 'show') {
            if (! preg_match('/^event:([1-9][0-9]*)$/D', (string) $pool->event_key, $match)) {
                throw new PosException('tax_event_unreviewed', 503);
            }
            $links = $this->stock->rows('event_links', $scope['tenant_id'])->where('event_id', (int) $match[1])->whereNotNull('reviewed_by')->orderBy('id');
            if ($lockEvent) {
                $links->lockForUpdate();
            }
            foreach ($links->get() as $link) {
                $candidate = $this->eventCandidate($link);
                if ($candidate) {
                    $candidates[] = $candidate;
                }
            }
        } else {
            $cursor = null;
            $seen = [];
            for ($page = 0; $page < 10; $page++) {
                $data = $this->shopify->request($scope['tenant_id'], $settings['shopify']['store_id'], StockShopifyApi::TAX_LOCATIONS, ['cursor' => $cursor]);
                if (($data['shop']['id'] ?? null) !== $settings['shopify']['shop_id']) {
                    throw new PosException('tax_location_shop_mismatch', 503);
                }
                $locations = $data['locations'] ?? null;
                if (! is_array($locations['nodes'] ?? null) || ! is_bool($locations['pageInfo']['hasNextPage'] ?? null)) {
                    throw new PosException('tax_location_provider_unavailable', 503);
                }
                foreach ($locations['nodes'] as $location) {
                    $address = $this->address(is_array($location['address'] ?? null) ? $location['address'] : []);
                    if (($location['isActive'] ?? false) === true && $address && is_string($location['id'] ?? null)
                        && preg_match('#^gid://shopify/Location/[0-9]+$#D', $location['id'])) {
                        $candidates[] = ['key' => 'shopify:'.substr($location['id'], strrpos($location['id'], '/') + 1),
                            'name' => (string) ($location['name'] ?? 'Location'), 'address' => $address,
                            'source' => 'shopify', 'valid_through' => null];
                    }
                }
                if (! $locations['pageInfo']['hasNextPage']) {
                    break;
                }
                $next = $locations['pageInfo']['endCursor'] ?? null;
                if (! is_string($next) || $next === '' || isset($seen[$next]) || $page === 9) {
                    throw new PosException('tax_location_provider_unavailable', 503);
                }
                $seen[$next] = true;
                $cursor = $next;
            }
        }
        foreach ($candidates as &$candidate) {
            $candidate['version'] = Contract::hash([$scope['tenant_id'], $scope['register']->id, $settings['inventory_pool_id'], $candidate]);
        }
        unset($candidate);

        return ['locations' => $candidates, 'current_version' => $this->currentVersion($settings),
            'enabled' => config('pos_live.tax_location_management_enabled', false) === true];
    }

    /** Check the saved review for a cashier without granting management access. */
    public function assertCurrentReview(array $scope, bool $localOnly = false): void
    {
        $settings = app(StockPosCommerce::class)->settings($scope);
        $review = $settings['tax_location_review'] ?? null;
        // Pre-existing reviewed registers keep their original policy until migrated.
        if ($review === null) {
            return;
        }
        if (! is_array($review) || ! is_string($review['key'] ?? null) || ! is_string($review['version'] ?? null)
            || empty($settings['tax_valid_until']) || strtotime($settings['tax_valid_until']) <= time()) {
            throw new PosException('tax_location_changed', 503);
        }
        if ($localOnly && ! str_starts_with($review['key'], 'event:')) {
            return;
        }
        if ($localOnly) {
            $binding = $this->stock->rows('bindings', $scope['tenant_id'])->where('channel', 'pos')
                ->where('channel_key', $this->store->runtime().':'.$scope['register']->id)->where('active', true)->lockForUpdate()->first();
            $pool = $this->stock->rows('pools', $scope['tenant_id'])->where('id', $settings['inventory_pool_id'])->where('active', true)->lockForUpdate()->first();
            if (! $binding || ! $pool || $binding->pool_id !== $pool->id) {
                throw new PosException('tax_location_changed', 503);
            }
        }
        $candidate = collect($this->readOptions($scope, $localOnly)['locations'])->firstWhere('key', $review['key']);
        if (! $candidate || ! hash_equals($candidate['version'], $review['version'])
            || Contract::hash([$candidate['address']]) !== Contract::hash([$settings['shopify']['tax_address'] ?? null])) {
            throw new PosException('tax_location_changed', 503);
        }
    }

    /** Validate a location against live Shopify tax calculation before owner review. */
    public function preview(array $scope, string $key): array
    {
        $locations = $this->options($scope);
        $candidate = collect($locations['locations'])->firstWhere('key', $key);
        if (! $candidate) {
            throw new PosException('tax_location_unavailable', 404);
        }
        $settings = app(StockPosCommerce::class)->settings($scope);
        $catalog = app(StockPosCommerce::class)->catalog($scope);
        $item = collect($catalog)->first(fn ($row) => ($row['kind'] ?? '') !== 'bundle' && ($row['unit_amount_minor'] ?? 0) > 0);
        if (! $item) {
            throw new PosException('tax_preview_product_required', 503);
        }
        $mapping = $this->stock->rows('provider_items', $scope['tenant_id'])->where('store_id', $settings['shopify']['store_id'])
            ->where('item_id', $item['item_id'])->where('active', true)->first();
        if (! $mapping || ! is_string($mapping->variant_id)) {
            throw new PosException('tax_preview_product_required', 503);
        }
        $result = $this->shopify->request($scope['tenant_id'], $settings['shopify']['store_id'], StockShopifyApi::CALCULATE,
            ['input' => ['lineItems' => [['variantId' => $mapping->variant_id, 'quantity' => 1]],
                'shippingAddress' => $candidate['address'], 'acceptAutomaticDiscounts' => false]]);
        $payload = $result['draftOrderCalculate'] ?? [];
        $quote = $payload['calculatedDraftOrder'] ?? [];
        if (! empty($payload['userErrors']) || ($quote['taxesIncluded'] ?? true) !== false
            || ($quote['totalPriceSet']['shopMoney']['currencyCode'] ?? null) !== 'USD'
            || ($quote['totalTaxSet']['shopMoney']['currencyCode'] ?? null) !== 'USD'
            || ! is_string($quote['totalPriceSet']['shopMoney']['amount'] ?? null)
            || ! is_string($quote['totalTaxSet']['shopMoney']['amount'] ?? null)) {
            throw new PosException('tax_preview_unavailable', 503);
        }
        $total = ShopifyPosCommerce::minor($quote['totalPriceSet']['shopMoney']['amount']);
        $tax = ShopifyPosCommerce::minor($quote['totalTaxSet']['shopMoney']['amount']);
        if ($total - $tax !== (int) $item['unit_amount_minor']) {
            throw new PosException('tax_preview_changed', 503);
        }

        return ['location' => $candidate, 'current_version' => $locations['current_version'],
            'example' => ['product' => $item['title'], 'subtotal_minor' => (int) $item['unit_amount_minor'], 'tax_minor' => $tax, 'total_minor' => $total],
            'enabled' => $locations['enabled']];
    }

    public function publish(array $scope, string $key, string $version, string $previousVersion, string $validUntil, bool $confirmed): array
    {
        if (config('pos_live.tax_location_management_enabled', false) !== true) {
            throw new PosException('tax_location_management_disabled', 503);
        }
        if (! $confirmed) {
            throw new PosException('tax_location_confirmation_required', 422);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $validUntil)) {
            throw new PosException('tax_location_expiry_invalid', 422);
        }
        try {
            $until = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $validUntil, 'UTC');
        } catch (\Throwable) {
            throw new PosException('tax_location_expiry_invalid', 422);
        }
        if (! $until || $until->format('Y-m-d\TH:i:s\Z') !== $validUntil) {
            throw new PosException('tax_location_expiry_invalid', 422);
        }
        if ($until->lessThanOrEqualTo(now()) || $until->greaterThan(now()->addDays(90))) {
            throw new PosException('tax_location_expiry_invalid', 422);
        }
        $preview = $this->preview($scope, $key);
        if ($preview['location']['source'] === 'event') {
            $end = $preview['location']['valid_through'];
            if (! is_string($end) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $end)) {
                throw new PosException('tax_event_unreviewed', 503);
            }
            try {
                $endAt = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $end, 'UTC')->endOfDay();
            } catch (\Throwable) {
                throw new PosException('tax_event_unreviewed', 503);
            }
            if ($endAt->format('Y-m-d') !== $end || $until->greaterThan($endAt)) {
                throw new PosException('tax_location_expiry_invalid', 422);
            }
        }
        if (! hash_equals($preview['location']['version'], $version) || ! hash_equals($preview['current_version'], $previousVersion)) {
            throw new PosException('tax_location_changed');
        }
        $lock = 'pos-start-'.hash('sha256', $this->store->runtime().':'.$scope['tenant_id'].':'.$scope['register']->id);
        try {
            return Cache::lock($lock, 180)->block(5, fn () => $this->store->db->transaction(function () use ($scope, $preview, $previousVersion, $until): array {
                $register = $this->store->rows('registers', $scope['tenant_id'])->where('id', $scope['register']->id)->where('active', true)->lockForUpdate()->first();
                if (! $register) {
                    throw new PosException('register_unavailable', 404);
                }
                $settings = $this->store->decrypt($register->configuration);
                if (! hash_equals($this->currentVersion($settings), $previousVersion)) {
                    throw new PosException('tax_location_changed');
                }
                $binding = $this->stock->rows('bindings', $scope['tenant_id'])->where('channel', 'pos')
                    ->where('channel_key', $this->store->runtime().':'.$register->id)->where('active', true)->lockForUpdate()->first();
                $pool = $this->stock->rows('pools', $scope['tenant_id'])->where('id', $settings['inventory_pool_id'] ?? null)
                    ->where('active', true)->lockForUpdate()->first();
                if (! $binding || ! $pool || $binding->pool_id !== $pool->id) {
                    throw new PosException('tax_location_changed');
                }
                if ($preview['location']['source'] === 'event') {
                    $linkId = substr($preview['location']['key'], strlen('event:'));
                    $link = $this->stock->rows('event_links', $scope['tenant_id'])->where('id', $linkId)->lockForUpdate()->first();
                    $candidate = $link ? $this->eventCandidate($link) : null;
                    if (! $candidate || ! $link->reviewed_by || $pool->kind !== 'show' || $pool->event_key !== 'event:'.$link->event_id
                        || ! hash_equals($preview['location']['version'], Contract::hash([$scope['tenant_id'], $register->id, $settings['inventory_pool_id'], $candidate]))) {
                        throw new PosException('tax_location_changed');
                    }
                } elseif ($pool->kind === 'show') {
                    throw new PosException('tax_location_changed');
                }
                if ($until->isPast()) {
                    throw new PosException('tax_location_expired', 422);
                }
                $settings['shopify']['tax_address'] = $preview['location']['address'];
                $settings['tax_valid_until'] = $until->toIso8601String();
                $settings['tax_location_review'] = ['source' => $preview['location']['source'], 'key' => $preview['location']['key'],
                    'version' => $preview['location']['version'], 'actor_id' => $scope['actor_id'], 'reviewed_at' => now()->toIso8601String()];
                $this->store->rows('registers', $scope['tenant_id'])->where('id', $register->id)->update(['configuration' => $this->store->encrypt($settings)]);
                $this->store->event($scope['tenant_id'], $register->id, 'tax_location_reviewed', ['actor_id' => $scope['actor_id'],
                    'source' => $preview['location']['source'], 'key' => $preview['location']['key'], 'version' => $preview['location']['version']]);

                return ['location' => $preview['location'], 'current_version' => $this->currentVersion($settings)];
            }, 3));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new PosException('tax_location_busy', 503);
        }
    }

    private function currentVersion(array $settings): string
    {
        return Contract::hash([$settings['shopify']['tax_address'] ?? null, $settings['tax_valid_until'] ?? null,
            $settings['inventory_pool_id'] ?? null, $settings['shopify']['store_id'] ?? null, $settings['shopify']['shop_id'] ?? null]);
    }

    private function eventCandidate(object $link): ?array
    {
        $metadata = json_decode((string) $link->metadata, true);
        if (! is_array($metadata)) {
            return null;
        }
        $address = $this->address(['address1' => $metadata['address'] ?? null, 'city' => $metadata['city'] ?? null,
            'provinceCode' => $metadata['state'] ?? null, 'countryCode' => 'US', 'zip' => $metadata['postal_code'] ?? null]);
        if (! $address) {
            return null;
        }

        return ['key' => 'event:'.$link->id, 'name' => (string) ($metadata['name'] ?? 'Event'),
            'address' => $address, 'source' => 'event', 'valid_through' => $metadata['end_on'] ?? null];
    }

    private function address(array $address): ?array
    {
        $out = [];
        foreach (['address1', 'city', 'provinceCode', 'countryCode', 'zip'] as $key) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value === '' || mb_strlen($value) > 150 || preg_match('/[\p{C}]/u', $value)) {
                return null;
            }
            $out[$key] = $value;
        }

        return $out['countryCode'] === 'US' && preg_match('/^[A-Z]{2}$/D', $out['provinceCode'])
            && preg_match('/^[0-9]{5}(?:-[0-9]{4})?$/D', $out['zip']) ? $out : null;
    }

    private function authorize(array $scope): void
    {
        if (! in_array($scope['role'] ?? null, ['owner', 'tenant_owner', 'admin'], true)
            || ! isset($scope['tenant_id'], $scope['actor_id'], $scope['register'])) {
            throw new PosException('organization_admin_required', 403);
        }
    }
}
