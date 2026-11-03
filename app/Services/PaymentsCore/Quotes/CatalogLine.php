<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\Contract;

/** Trusted server catalog result, never constructed from an HTTP line body. */
final readonly class CatalogLine
{
    public string $lineId;

    public function __construct(
        public int $variantId,
        public int $quantity,
        public string $kind,
        public string $currency,
        public int $unitAmountMinor,
        public string $revision,
        public bool $inventoryTracked,
        public ?int $availableQuantity,
        public string $title,
        public ?string $sku,
    ) {
        if ($variantId < 1 || $quantity < 1 || $quantity > 99 || $currency !== 'usd'
            || ! in_array($kind, ['merchandise', 'printing_service'], true)
            || ! preg_match('/^[a-f0-9]{64}$/D', $revision)
            || ($kind !== 'merchandise' && $inventoryTracked)
            || ($inventoryTracked && ($availableQuantity === null || $availableQuantity < $quantity))) {
            throw new QuoteException('catalog_item_unavailable', 409);
        }
        if ($unitAmountMinor < 1 || $unitAmountMinor > Contract::MAX_MINOR || $unitAmountMinor * $quantity > Contract::MAX_MINOR) {
            throw new QuoteException('catalog_price_unavailable');
        }
        $this->lineId = 'website_variant_'.$variantId;
    }

    /** Excludes changing stock counts; availability is checked afresh before collection. */
    public function terms(): array
    {
        return ['line_id' => $this->lineId, 'variant_id' => $this->variantId, 'quantity' => $this->quantity,
            'kind' => $this->kind, 'currency' => $this->currency, 'unit_amount_minor' => $this->unitAmountMinor,
            'catalog_revision' => $this->revision, 'inventory_tracked' => $this->inventoryTracked];
    }

    public function projection(): array
    {
        return ['variant_id' => $this->variantId, 'title' => $this->title, 'sku' => $this->sku,
            'kind' => $this->kind, 'currency' => $this->currency, 'unit_amount_minor' => $this->unitAmountMinor,
            'expected_revision' => $this->revision, 'available_quantity' => $this->availableQuantity, 'quoteable' => true];
    }

    public function snapshot(): array
    {
        return [...$this->terms(), 'available_quantity' => $this->availableQuantity, 'title' => $this->title, 'sku' => $this->sku];
    }

    public static function restore(array $data): self
    {
        $line = new self($data['variant_id'], $data['quantity'], $data['kind'], $data['currency'], $data['unit_amount_minor'],
            $data['catalog_revision'], $data['inventory_tracked'], $data['available_quantity'], $data['title'], $data['sku']);
        if ($line->lineId !== $data['line_id']) {
            throw new QuoteException('quote_evidence_invalid', 503);
        }

        return $line;
    }
}
