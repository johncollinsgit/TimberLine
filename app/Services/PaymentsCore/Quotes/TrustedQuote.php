<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\SaleSnapshot;
use DateTimeImmutable;
use DomainException;

/** Internal immutable quote. Serialize to clients only via projection(), never snapshot(). */
final readonly class TrustedQuote
{
    public string $currency;

    public int $amountMinor;

    public array $lines;

    /** @param list<CatalogLine> $catalogLines */
    public function __construct(
        public string $id,
        public string $saleId,
        public MerchantBinding $binding,
        public int $actorId,
        public int $siteId,
        public string $requestId,
        public array $items,
        public array $catalogLines,
        public TaxAssessment $tax,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public int $version = 1,
    ) {
        if (ItemSelection::uuid($id) !== $id || ItemSelection::uuid($saleId) !== $saleId
            || ItemSelection::uuid($requestId) !== $requestId || $actorId < 1 || $siteId < 1 || $version !== 1
            || $items !== ItemSelection::normalize($items) || count($items) !== count($catalogLines)
            || $expiresAt <= $createdAt || $expiresAt > $tax->expiresAt) {
            throw new QuoteException('quote_evidence_invalid', 503);
        }
        foreach ($catalogLines as $index => $line) {
            if (! $line instanceof CatalogLine || $line->variantId !== $items[$index]['variant_id']
                || $line->quantity !== $items[$index]['quantity'] || $line->revision !== $items[$index]['expected_revision']) {
                throw new QuoteException('quote_evidence_invalid', 503);
            }
        }
        $tax->validate($binding, $siteId, $catalogLines, $createdAt);
        $this->currency = $tax->currency;
        $this->lines = array_map(fn (CatalogLine $line) => [...$line->terms(), 'tax_amount_minor' => $tax->lineTaxes[$line->lineId]], $catalogLines);
        try {
            $this->amountMinor = $this->toSaleSnapshot()->amountMinor;
        } catch (DomainException $exception) {
            throw new QuoteException('quote_amount_unsupported');
        }
    }

    public function scopeHash(): string
    {
        return Contract::hash(['binding_fingerprint' => $this->binding->fingerprint(), 'actor_id' => $this->actorId, 'site_id' => $this->siteId]);
    }

    public function selectionHash(): string
    {
        return Contract::hash($this->items);
    }

    public function toSaleSnapshot(): SaleSnapshot
    {
        return new SaleSnapshot($this->saleId, $this->currency, array_map(fn (array $line) => [
            'line_id' => $line['line_id'], 'kind' => $line['kind'], 'quantity' => $line['quantity'],
            'unit_amount_minor' => $line['unit_amount_minor'], 'tax_amount_minor' => $line['tax_amount_minor'],
        ], $this->lines));
    }

    public function projection(): array
    {
        return ['quote_id' => $this->id, 'version' => $this->version, 'request_id' => $this->requestId,
            'sale_id' => $this->saleId, 'currency' => $this->currency, 'amount_minor' => $this->amountMinor,
            'lines' => $this->lines, 'created_at' => $this->createdAt->format(DATE_ATOM), 'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'tax_policy_revision' => $this->tax->policyRevision, 'tax_treatment' => $this->tax->treatment,
            'tax_rounding' => $this->tax->rounding];
    }

    /** Encrypted internal persistence only; includes routing evidence. */
    public function snapshot(): array
    {
        return ['id' => $this->id, 'sale_id' => $this->saleId, 'version' => $this->version, 'binding' => $this->binding->snapshot(),
            'actor_id' => $this->actorId, 'site_id' => $this->siteId, 'request_id' => $this->requestId, 'items' => $this->items,
            'catalog_lines' => array_map(fn (CatalogLine $line) => $line->snapshot(), $this->catalogLines),
            'tax' => $this->tax->snapshot(), 'created_at' => $this->createdAt->format(DATE_ATOM), 'expires_at' => $this->expiresAt->format(DATE_ATOM)];
    }

    public static function restore(array $data): self
    {
        return new self($data['id'], $data['sale_id'], MerchantBinding::restore($data['binding']), $data['actor_id'], $data['site_id'],
            $data['request_id'], $data['items'], array_map(CatalogLine::restore(...), $data['catalog_lines']),
            TaxAssessment::restore($data['tax']), new DateTimeImmutable($data['created_at']), new DateTimeImmutable($data['expires_at']), $data['version']);
    }
}
