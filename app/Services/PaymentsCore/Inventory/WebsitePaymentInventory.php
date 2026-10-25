<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Inventory;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\Quotes\TrustedQuote;
use App\Services\PaymentsCore\Quotes\WebsitePaymentCatalog;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;

/** Shared Website authority inside journal TX. No financial orders or HTTP.
 * Local/test only; the independent default authority denies all operations.
 */
final class WebsitePaymentInventory
{
    public function __construct(private Connection $db, private WebsitePaymentCatalog $catalog,
        private Encrypter $crypt, private InventoryReservationAuthority $authority = new UnavailableInventoryAuthority) {}

    public function reserve(array $row, PaymentInventoryContext $context): void
    {
        $quote = $context->quote;
        $this->fixture($quote);
        $this->authority->assertAllowed($quote, 'reserve');
        if ($row['binding'] !== $quote->binding->snapshot() || $row['terms'] !== $quote->toSaleSnapshot()->snapshot()) {
            throw new DomainException('Inventory quote differs from immutable payment terms.');
        }
        $this->site($row['tenant_id'], $quote->siteId);
        // Consume the CURRENT locked encrypted row, never an older RR snapshot.
        $saved = $this->db->table('payment_core_quotes')->where('tenant_id', $row['tenant_id'])
            ->where('quote_id', $quote->id)->lockForUpdate()->first();
        if (! $saved) {
            throw new DomainException('The original inventory quote is unavailable.');
        }
        $json = $this->crypt->decrypt($saved->snapshot, false);
        $frozen = TrustedQuote::restore(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
        if (! hash_equals($saved->payload_hash, hash('sha256', $json)) || Contract::hash($frozen->snapshot()) !== $context->fingerprint()
            || $frozen->actorId !== (int) $saved->actor_id || $frozen->siteId !== (int) $saved->site_id
            || $frozen->saleId !== $saved->sale_id || $frozen->requestId !== $saved->request_id
            || $frozen->binding->registerId !== $saved->register_id || $frozen->binding->fingerprint() !== $saved->binding_fingerprint
            || $frozen->scopeHash() !== $saved->scope_hash || $frozen->selectionHash() !== $saved->selection_hash
            || $frozen->version !== (int) $saved->version || $frozen->createdAt->format('Y-m-d H:i:s') !== $saved->created_at
            || $frozen->expiresAt->format('Y-m-d H:i:s') !== $saved->expires_at) {
            throw new DomainException('Stored inventory quote requires integrity review.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($now >= $quote->expiresAt) {
            throw new DomainException('The original inventory quote expired.');
        }
        $lines = $quote->catalogLines;
        usort($lines, fn ($a, $b) => $a->variantId <=> $b->variantId);
        $current = [];
        foreach ($lines as $line) {
            $resolved = $this->catalog->item($row['tenant_id'], $quote->siteId, $line->variantId, $line->quantity, true);
            if ($resolved->terms() !== $line->terms()) {
                throw new DomainException('Catalog changed before inventory reservation.');
            }
            $current[$line->variantId] = $resolved;
        }
        $quote->tax->validate($quote->binding, $quote->siteId,
            array_map(fn ($line) => $current[$line->variantId], $quote->catalogLines), $now);
        $this->authority->assertAllowed($quote, 'reserve');
        foreach ($lines as $line) {
            if ($line->inventoryTracked) {
                $this->db->table('website_inventory_reservations')->insert(['tenant_id' => $row['tenant_id'],
                    'website_product_variant_id' => $line->variantId, 'website_order_id' => null,
                    'payment_core_operation_id' => $row['id'], 'quantity' => $line->quantity,
                    'status' => 'active', 'expires_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function settle(array $row, string $providerState): string
    {
        $quote = PaymentInventoryContext::restore($row['inventory_snapshot'])->quote;
        $this->fixture($quote);
        try {
            $this->authority->assertAllowed($quote, 'settle');
        } catch (InventoryUnavailableException) {
            return 'review';
        }
        if (! in_array($providerState, ['succeeded', 'failed', 'cancelled'], true)) {
            return $row['inventory_state'];
        }
        try {
            $this->site($row['tenant_id'], $quote->siteId);
        } catch (InventoryUnavailableException) {
            return 'review';
        }
        $lines = array_values(array_filter($quote->catalogLines, fn ($line) => $line->inventoryTracked));
        usort($lines, fn ($a, $b) => $a->variantId <=> $b->variantId);
        $variants = [];
        foreach ($lines as $line) {
            $variant = $this->db->table('website_product_variants as v')->join('website_products as p', 'p.id', '=', 'v.website_product_id')
                ->where('v.id', $line->variantId)->where('v.tenant_id', $row['tenant_id'])->where('p.tenant_id', $row['tenant_id'])
                ->where('p.tenant_site_id', $quote->siteId)->lockForUpdate()->select('v.id', 'v.inventory_quantity')->first();
            if (! $variant || $variant->inventory_quantity === null) {
                return 'review';
            }
            $variants[$line->variantId] = $variant;
        }
        $holds = $this->db->table('website_inventory_reservations')->where('tenant_id', $row['tenant_id'])
            ->where('payment_core_operation_id', $row['id'])->orderBy('id')->lockForUpdate()->get();
        if ($holds->count() !== count($lines)) {
            return 'review';
        }
        // Validate EVERY line before performing ANY write. Expected review may
        // commit canonical identity, but never a partial stock change.
        foreach ($lines as $line) {
            $hold = $holds->firstWhere('website_product_variant_id', $line->variantId);
            if (! $hold || $hold->website_order_id !== null || $hold->status !== 'active'
                || (int) $hold->quantity !== $line->quantity || $hold->expires_at !== null) {
                return 'review';
            }
            if ($providerState === 'succeeded') {
                $others = $this->db->table('website_inventory_reservations')->where('tenant_id', $row['tenant_id'])
                    ->where('website_product_variant_id', $line->variantId)->where('id', '<>', $hold->id)
                    ->where('status', 'active')->orderBy('id')->lockForUpdate()->get()->sum('quantity');
                if ((int) $variants[$line->variantId]->inventory_quantity - $others < $line->quantity) {
                    return 'review';
                }
            }
        }
        try {
            $this->authority->assertAllowed($quote, 'settle');
        } catch (InventoryUnavailableException) {
            return 'review';
        }
        foreach ($lines as $line) {
            $hold = $holds->firstWhere('website_product_variant_id', $line->variantId);
            if ($providerState === 'succeeded') {
                $this->db->table('website_product_variants')->where('tenant_id', $row['tenant_id'])->where('id', $line->variantId)->decrement('inventory_quantity', $line->quantity);
                $this->db->table('website_inventory_movements')->insert(['tenant_id' => $row['tenant_id'],
                    'website_product_variant_id' => $line->variantId, 'quantity_delta' => -$line->quantity,
                    'reason' => 'payments_fixture_sale', 'reference_type' => 'payment_core_reservation', 'reference_id' => $hold->id,
                    'actor_user_id' => $quote->actorId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->db->table('website_inventory_reservations')->where('id', $hold->id)
                ->update(['status' => $providerState === 'succeeded' ? 'confirmed' : 'released', 'updated_at' => now()]);
        }

        return $providerState === 'succeeded' ? 'committed' : 'released';
    }

    private function site(int $tenant, int $site): void
    {
        if (! $this->db->table('tenant_sites')->where('tenant_id', $tenant)->where('id', $site)->lockForUpdate()->first()) {
            throw new InventoryUnavailableException('Original inventory site is unavailable.');
        }
    }

    private function fixture(TrustedQuote $quote): void
    {
        if ($this->db->transactionLevel() < 1) {
            throw new DomainException('Inventory requires journal-owned transaction.');
        }
        if ($quote->binding->environment !== 'test' || ! app()->environment(['local', 'testing'])) {
            throw new InventoryUnavailableException('Inventory collection remains local/test only.');
        }
    }
}
