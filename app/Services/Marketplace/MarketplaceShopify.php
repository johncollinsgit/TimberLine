<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Connection;
use App\Models\Marketplace\InventoryAllocation;
use App\Models\Marketplace\InventoryLevel;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Operation;
use App\Models\Marketplace\PickupLocation;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MarketplaceShopify
{
    public function graph(Connection $connection, string $query, array $variables = [], bool $storefront = false, ?string $buyerIp = null): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $connection->shop_domain)) {
            $this->fail('Invalid dedicated store domain.');
        }
        $credentials = $connection->credentials;
        $path = $storefront ? '/api/' : '/admin/api/';
        $header = $storefront ? (! empty($connection->credentials['wallet']) ? 'X-Shopify-Storefront-Access-Token' : 'Shopify-Storefront-Private-Token') : 'X-Shopify-Access-Token';
        $token = $credentials[$storefront ? 'storefront_token' : 'admin_token'] ?? '';
        if ($token === '') {
            $this->fail('Complete the dedicated store credentials first.');
        }
        try {
            $response = Http::timeout(20)->connectTimeout(8)->withoutRedirecting()->acceptJson()->withHeaders(array_filter([
                $header => $token, 'Shopify-Storefront-Buyer-IP' => $storefront ? $buyerIp : null,
            ]))->post('https://'.$connection->shop_domain.$path.config('marketplace.api_version').'/graphql.json', ['query' => $query, 'variables' => $variables === [] ? (object) [] : $variables]);
        } catch (Throwable) {
            $this->fail('Shopify did not confirm this request. Review its recorded operation before retrying.');
        }
        if (! $response->successful() || $response->json('errors') || ! is_array($response->json('data'))) {
            $this->fail('Shopify could not confirm this operation. Check the connection, scopes, and operation history.');
        }

        return $response->json('data');
    }

    public function verify(Connection $connection): void
    {
        $data = $this->graph($connection, 'query MarketplaceIdentity($publication: ID!) { shop { id myshopifyDomain currencyCode primaryDomain { host } } publication(id: $publication) { id name } }', ['publication' => $connection->publication_gid]);
        $shop = $data['shop'] ?? [];
        abort_unless(($shop['id'] ?? '') === $connection->shop_gid && ($shop['myshopifyDomain'] ?? '') === $connection->shop_domain && ($shop['currencyCode'] ?? '') === 'USD' && ($data['publication']['id'] ?? '') === $connection->publication_gid, 422, 'The dedicated store identity, publication, or USD currency did not match.');
        abort_if(Connection::query()->whereNotNull('verified_at')->where('shop_gid', '!=', $connection->shop_gid)->exists(), 422, 'Existing marketplace orders and stock remain bound to their original merchant.');
        $this->graph($connection, 'query MarketplaceStorefront { shop { name } }', [], true);
        $credentials = $connection->credentials;
        $credentials['checkout_host'] = strtolower($shop['primaryDomain']['host'] ?? $connection->shop_domain);
        $connection->forceFill(['credentials' => $credentials, 'verified_at' => now()])->save();
        app(MarketplaceAccess::class)->settings()->forceFill(['connection_id' => $connection->id, 'checkout_enabled' => false])->save();
        Audit::query()->create(['actor_user_id' => $connection->created_by, 'action' => 'connection.verified', 'subject_id' => $connection->id, 'details' => ['shop' => $connection->shop_domain, 'expected_test_mode' => $connection->test_mode]]);
    }

    public function operation(Connection $connection, string $uuid, string $kind, int $subject, array $payload, callable $call, ?int $actor = null): array
    {
        abort_unless(Str::isUuid($uuid), 422);
        $operation = Operation::query()->firstOrCreate(['uuid' => $uuid], ['connection_id' => $connection->id, 'kind' => $kind, 'subject_id' => $subject, 'status' => 'prepared', 'payload' => $payload, 'actor_user_id' => $actor]);
        abort_unless((int) $operation->connection_id === (int) $connection->id && $operation->kind === $kind && (int) $operation->subject_id === $subject && $operation->payload === $payload, 409, 'This request identifier belongs to another operation.');
        if ($operation->status === 'completed') {
            return $operation->result;
        }
        $claimed = Operation::query()->whereKey($operation->id)->where('status', 'prepared')->update(['status' => 'sending', 'updated_at' => now()]);
        if (! $claimed) {
            $this->fail('This operation is already in progress or needs reconciliation. It will not be sent again.');
        }
        try {
            $result = $call();
            foreach ($result as $root) {
                if (is_array($root) && ! empty($root['userErrors'])) {
                    $operation->forceFill(['status' => 'rejected', 'result' => ['error' => 'provider_validation']])->save();
                    $this->fail('Shopify rejected the operation. Review product, inventory, fulfillment, and permission settings before a new request.');
                }
            }
            $operation->forceFill(['status' => 'completed', 'result' => $result])->save();

            return $result;
        } catch (Throwable $error) {
            Operation::query()->whereKey($operation->id)->where('status', 'sending')->update(['status' => 'unknown', 'updated_at' => now()]);
            throw $error;
        }
    }

    public function current(): Connection
    {
        $connection = Connection::query()->find(app(MarketplaceAccess::class)->settings()->connection_id);
        if (! $connection?->verified_at) {
            $this->fail('The Markets and Makers Shopify store is not connected yet.');
        }

        return $connection;
    }

    public function publish(Listing $listing, string $uuid, int $actor): void
    {
        abort_unless($listing->status === 'approved' && $listing->maker->status === 'approved' && app(MarketplaceRates::class)->accepted($listing), 422, 'Approve this listing and its rate terms before publishing.');
        $connection = $this->current();
        $locations = array_values(array_unique(array_filter([
            $listing->shipping ? $listing->shipping_location_gid : null,
            ...PickupLocation::query()->whereIn('id', $listing->pickup_location_ids ?? [])->where('active', true)->pluck('shopify_location_gid')->all(),
        ])));
        abort_unless($locations !== [] && (! $listing->shipping || $listing->shipping_location_gid), 422, 'Configure this listing’s fulfillment locations first.');
        $variant = ['price' => self::decimal($listing->price_cents), 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true, 'requiresShipping' => true], 'sku' => 'MM-'.$listing->id, 'taxable' => true, 'optionValues' => [['optionName' => 'Title', 'name' => $listing->variant_label ?: 'Default']]];
        if ($listing->shopify_variant_gid) {
            $variant['id'] = $listing->shopify_variant_gid;
        } else {
            $variant['inventoryQuantities'] = array_map(fn ($location) => ['locationId' => $location, 'name' => 'available', 'quantity' => 0], $locations);
        }
        $input = ['handle' => 'mm-'.$listing->id, 'title' => $listing->title, 'descriptionHtml' => nl2br(e($listing->description)), 'vendor' => $listing->maker->name, 'productType' => $listing->category, 'status' => 'DRAFT', 'productOptions' => [['name' => 'Title', 'values' => [['name' => $listing->variant_label ?: 'Default']]]], 'variants' => [$variant]];
        $result = $this->operation($connection, $uuid, 'publish', $listing->id, ['revision' => $listing->revision, 'input' => $input], fn () => $this->graph($connection, 'mutation MarketplaceProduct($input: ProductSetInput!, $identifier: ProductSetIdentifiers!) { productSet(input: $input, identifier: $identifier, synchronous: true) { product { id variants(first: 2) { nodes { id inventoryItem { id } } } } userErrors { code field message } } }', ['input' => $input, 'identifier' => ['handle' => 'mm-'.$listing->id]]), $actor);
        $product = $result['productSet']['product'] ?? [];
        abort_unless(! empty($product['id']) && count($product['variants']['nodes'] ?? []) === 1, 422, 'Shopify returned an unexpected product mapping.');
        $listing->forceFill(['connection_id' => $connection->id, 'shopify_product_gid' => $product['id'], 'shopify_variant_gid' => $product['variants']['nodes'][0]['id'], 'shopify_inventory_gid' => $product['variants']['nodes'][0]['inventoryItem']['id']])->save();
        $this->syncInventory($listing);
        $this->operation($connection, (string) Str::uuid(), 'activate_listing', $listing->id, ['product' => $product['id'], 'revision' => $listing->revision], fn () => $this->graph($connection, 'mutation MarketplaceActivateProduct($product: ProductUpdateInput!) { productUpdate(product: $product) { product { id } userErrors { field message } } }', ['product' => ['id' => $product['id'], 'status' => 'ACTIVE']]), $actor);
        $this->operation($connection, (string) Str::uuid(), 'publication', $listing->id, ['product' => $product['id'], 'publication' => $connection->publication_gid], fn () => $this->graph($connection, 'mutation MarketplacePublish($id: ID!, $input: [PublicationInput!]!) { publishablePublish(id: $id, input: $input) { userErrors { field message } } }', ['id' => $product['id'], 'input' => [['publicationId' => $connection->publication_gid]]]), $actor);
        $listing->forceFill(['published_revision' => $listing->revision, 'synced_at' => now()])->save();
    }

    public function pause(Listing $listing): void
    {
        $listing->forceFill(['published_revision' => null])->save();
        if (! $listing->shopify_product_gid || ! $listing->connection_id) {
            return;
        }
        $connection = Connection::query()->findOrFail($listing->connection_id);
        $this->operation($connection, (string) Str::uuid(), 'pause', $listing->id, ['product' => $listing->shopify_product_gid], fn () => $this->graph($connection, 'mutation MarketplacePause($product: ProductUpdateInput!) { productUpdate(product: $product) { product { id } userErrors { field message } } }', ['product' => ['id' => $listing->shopify_product_gid, 'status' => 'DRAFT']]));
    }

    public function inventory(Listing $listing): array
    {
        $connection = Connection::query()->findOrFail($listing->connection_id);
        $data = $this->graph($connection, 'query MarketplaceInventory($id: ID!) { inventoryItem(id: $id) { id inventoryLevels(first: 100) { nodes { location { id } quantities(names: ["available"]) { name quantity } } pageInfo { hasNextPage } } } }', ['id' => $listing->shopify_inventory_gid]);
        $levels = $data['inventoryItem']['inventoryLevels'] ?? null;
        if (! is_array($levels) || ($levels['pageInfo']['hasNextPage'] ?? true)) {
            $this->fail('The inventory response is incomplete.');
        }
        $values = [];
        foreach ($levels['nodes'] as $level) {
            $values[$level['location']['id']] = max(0, (int) collect($level['quantities'])->firstWhere('name', 'available')['quantity']);
        }

        return $values;
    }

    public function refreshInventory(Listing $listing): void
    {
        DB::transaction(function () use ($listing): void {
            $listing = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            $levels = $this->inventory($listing);
            foreach ($levels as $location => $quantity) {
                InventoryLevel::query()->updateOrCreate(['listing_id' => $listing->id, 'shopify_location_gid' => $location], ['quantity' => $quantity]);
            }
            InventoryLevel::query()->where('listing_id', $listing->id)->whereNotIn('shopify_location_gid', array_keys($levels))->update(['quantity' => 0]);
            $listing->forceFill(['stock_quantity' => array_sum($levels), 'synced_at' => now()])->save();
        });
    }

    public function syncInventory(Listing $listing): void
    {
        $connection = Connection::query()->findOrFail($listing->connection_id);
        foreach (InventoryAllocation::query()->where('listing_id', $listing->id)->whereNull('applied_at')->orderBy('id')->get() as $allocation) {
            $existing = Operation::query()->where('kind', 'stock_allocation')->where('subject_id', $allocation->id)->latest('id')->first();
            if ($existing) {
                if ($existing->status === 'completed') {
                    $this->finishAllocation($listing, $allocation);

                    continue;
                }
                if (! in_array($existing->status, ['rejected', 'prepared'], true)) {
                    $this->fail('This stock update needs reconciliation before another update can be sent.');
                }
            }
            $levels = $this->inventory($listing);
            $location = $allocation->shopify_location_gid;
            if (! array_key_exists($location, $levels)) {
                $this->operation($connection, (string) Str::uuid(), 'activate_inventory', $listing->id, ['item' => $listing->shopify_inventory_gid, 'location' => $location], fn () => $this->graph($connection, 'mutation MarketplaceActivate($item: ID!, $location: ID!) { inventoryActivate(inventoryItemId: $item, locationId: $location, available: 0) { inventoryLevel { id } userErrors { field message } } }', ['item' => $listing->shopify_inventory_gid, 'location' => $location]));
                $levels[$location] = 0;
            }
            $quantity = $levels[$location] + $allocation->quantity;
            abort_unless($quantity >= 0, 422, 'Some allocated stock has sold. Reduce the withdrawal and review inventory.');
            $input = $existing?->status === 'prepared' ? $existing->payload['input'] : ['name' => 'available', 'reason' => 'correction', 'referenceDocumentUri' => 'gid://marketsandmakers/Allocation/'.$allocation->id, 'quantities' => [['inventoryItemId' => $listing->shopify_inventory_gid, 'locationId' => $location, 'quantity' => $quantity, 'changeFromQuantity' => $levels[$location]]]];
            // A definitive CAS rejection changed no stock. A fresh attempt may
            // use the current quantity; uncertain responses must never be retried.
            $uuid = $existing?->status === 'rejected' ? (string) Str::uuid() : ($existing?->uuid ?? $allocation->request_uuid);
            $this->operation($connection, $uuid, 'stock_allocation', $allocation->id, ['input' => $input, 'allocation' => $allocation->id], fn () => $this->graph($connection, 'mutation MarketplaceStock($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) { inventoryAdjustmentGroup { createdAt } userErrors { code field message } } }', ['input' => $input]), $allocation->actor_user_id);
            $this->finishAllocation($listing, $allocation);
        }
        $this->refreshInventory($listing);
    }

    private function finishAllocation(Listing $listing, InventoryAllocation $allocation): void
    {
        DB::transaction(function () use ($listing, $allocation): void {
            $allocation = InventoryAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            if ($allocation->applied_at) {
                return;
            }
            if ($allocation->quantity < 0 && $allocation->source_variant_id) {
                $source = WebsiteProductVariant::query()->where('tenant_id', $listing->maker->tenant_id)->lockForUpdate()->findOrFail($allocation->source_variant_id);
                $source->increment('inventory_quantity', -$allocation->quantity);
                WebsiteInventoryMovement::query()->create(['tenant_id' => $source->tenant_id, 'website_product_variant_id' => $source->id, 'quantity_delta' => -$allocation->quantity, 'reason' => 'marketplace_return', 'reference_type' => 'marketplace_allocation', 'reference_id' => $allocation->id, 'actor_user_id' => $allocation->actor_user_id]);
            }
            $allocation->forceFill(['applied_at' => now()])->save();
        });
    }

    public function validCheckoutUrl(Connection $connection, string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), array_filter([$connection->shop_domain, $connection->credentials['checkout_host'] ?? null, 'checkout.shopify.com']), true);
    }

    public static function cents(mixed $amount): int
    {
        $amount = (string) $amount;
        if (! preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['money' => 'A provider money amount is invalid.']);
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['shopify' => $message]);
    }
}
