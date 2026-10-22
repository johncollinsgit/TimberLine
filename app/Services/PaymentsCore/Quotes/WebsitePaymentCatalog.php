<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\Contract;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/** Read-only Website lane adapter; entitlement and register assignment precede this call. */
final class WebsitePaymentCatalog implements PaymentCatalog
{
    public function __construct(private Connection $database, private ServiceItemPolicy $servicePolicy = new UnavailableServiceItemPolicy) {}

    public function item(int $tenantId, int $siteId, int $variantId, int $quantity = 1): CatalogLine
    {
        if ($tenantId < 1 || $siteId < 1 || $variantId < 1 || $quantity < 1 || $quantity > 99) {
            throw new QuoteException('invalid_selection');
        }
        $row = $this->query($tenantId, $siteId)->where('v.id', $variantId)->first();
        if (! $row || $row->product_status !== 'active' || ! $row->is_available) {
            throw new QuoteException('catalog_item_not_found', 404);
        }
        $kind = 'merchandise';
        $mappingRevision = null;
        if ($row->product_type === 'service') {
            $mapping = $this->servicePolicy->mapping($tenantId, $siteId, $variantId);
            if (! $mapping || $row->track_inventory) {
                throw new QuoteException('service_policy_missing');
            }
            $kind = 'printing_service';
            $mappingRevision = $mapping->policyRevision;
        } elseif ($row->product_type !== 'physical') {
            // Quote-only, variable/unmapped services and postage have no instant-sale adapter.
            throw new QuoteException('catalog_kind_unsupported');
        }
        if ((int) $row->price_cents < 1 || (int) $row->price_cents > Contract::MAX_MINOR) {
            throw new QuoteException('catalog_price_unavailable');
        }
        $available = null;
        if ($row->track_inventory) {
            if ($row->inventory_quantity === null) {
                throw new QuoteException('inventory_unknown', 409);
            }
            // Conservatively count all retained active Website holds, including expired ones
            // awaiting the Website lane's own release. A quote never creates/deletes a hold.
            $held = (int) $this->database->table('website_inventory_reservations')->where('tenant_id', $tenantId)
                ->where('website_product_variant_id', $variantId)->where('status', 'active')->sum('quantity');
            $available = max(0, (int) $row->inventory_quantity - $held);
            if ($available < $quantity) {
                throw new QuoteException('inventory_unavailable', 409);
            }
        }
        $details = json_decode($row->service_details ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        // Existing Website commerce prices are USD cents. Other currencies need an explicit
        // canonical adapter; a register/client currency cannot reinterpret these amounts.
        $revision = Contract::hash(['source' => 'website_catalog_v1', 'tenant_id' => $tenantId,
            'site_id' => $siteId, 'product_id' => (int) $row->product_id, 'variant_id' => $variantId,
            'catalog_revision' => $details['catalog_revision'] ?? 0, 'product_status' => $row->product_status,
            'product_type' => $row->product_type, 'kind' => $kind, 'mapping_revision' => $mappingRevision,
            'is_available' => (bool) $row->is_available, 'inventory_tracked' => (bool) $row->track_inventory,
            'currency' => 'usd', 'unit_amount_minor' => (int) $row->price_cents, 'sku' => $row->sku]);

        return new CatalogLine($variantId, $quantity, $kind, 'usd', (int) $row->price_cents, $revision,
            (bool) $row->track_inventory, $available, (string) $row->product_title, $row->sku);
    }

    public function listing(int $tenantId, int $siteId, ?string $cursor = null, int $limit = 25, ?string $sku = null): array
    {
        if ($tenantId < 1 || $siteId < 1 || $limit < 1 || $limit > 50
            || ($cursor !== null && ! preg_match('/^[1-9][0-9]{0,17}$/D', $cursor))
            || ($sku !== null && ($cursor !== null || strlen($sku) < 1 || strlen($sku) > 120 || preg_match('/[\x00-\x1f\x7f]/', $sku)))) {
            throw new QuoteException('invalid_catalog_query');
        }
        $query = $this->query($tenantId, $siteId)->where('p.status', 'active')->where('v.is_available', true);
        if ($cursor !== null) {
            $query->where('v.id', '>', (int) $cursor);
        }
        if ($sku !== null) {
            if (in_array($this->database->getDriverName(), ['mysql', 'mariadb'], true)) {
                $query->whereRaw('BINARY v.sku = ?', [$sku]);
            } else {
                $query->where('v.sku', $sku);
            }
        }
        $rows = $query->orderBy('v.id')->limit($limit + 1)->get();
        if ($sku !== null) {
            $rows = $rows->filter(fn ($row) => $row->sku === $sku)->values();
            if ($rows->count() > 1) {
                throw new QuoteException('catalog_sku_ambiguous', 409);
            }
        }
        $items = [];
        $blockers = [];
        foreach ($rows->take($limit) as $row) {
            try {
                $items[] = $this->item($tenantId, $siteId, (int) $row->id)->projection();
            } catch (QuoteException $exception) {
                $blockers[] = $exception->blockerCode;
            }
        }

        return ['items' => $items, 'next_cursor' => $rows->count() > $limit ? (string) $rows[$limit - 1]->id : null,
            'blockers' => array_values(array_unique($blockers))];
    }

    private function query(int $tenantId, int $siteId): Builder
    {
        return $this->database->table('website_product_variants as v')->join('website_products as p', 'p.id', '=', 'v.website_product_id')
            ->join('tenant_sites as s', 's.id', '=', 'p.tenant_site_id')
            ->where('v.tenant_id', $tenantId)->where('p.tenant_id', $tenantId)->where('s.tenant_id', $tenantId)->where('s.id', $siteId)
            ->select(['v.id', 'v.price_cents', 'v.inventory_quantity', 'v.is_available', 'v.sku',
                'p.id as product_id', 'p.title as product_title', 'p.product_type', 'p.status as product_status', 'p.track_inventory', 'p.service_details']);
    }
}
