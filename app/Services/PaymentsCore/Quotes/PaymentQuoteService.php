<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/** Server-only prices/tax; does not authorize or dispatch collection or hold inventory. */
final class PaymentQuoteService
{
    public function __construct(
        private PaymentCatalog $catalog,
        private QuoteRepository $repository,
        private TaxPolicy $taxPolicy = new UnavailableTaxPolicy,
        private ?Closure $clock = null,
        private int $ttlSeconds = 300,
    ) {
        if ($ttlSeconds < 1 || $ttlSeconds > 900) {
            throw new QuoteException('quote_policy_unavailable', 503);
        }
    }

    public function createQuote(MerchantBinding $binding, int $actorId, int $siteId, string $requestId, array $items): TrustedQuote
    {
        $requestId = ItemSelection::uuid($requestId);
        $items = ItemSelection::normalize($items);
        if ($actorId < 1 || $siteId < 1) {
            throw new QuoteException('invalid_quote_scope');
        }
        $existing = $this->repository->findByRequest($binding->tenantId, $requestId);
        if ($existing) {
            $scopeHash = Contract::hash(['binding_fingerprint' => $binding->fingerprint(), 'actor_id' => $actorId, 'site_id' => $siteId]);
            if ($existing->scopeHash() !== $scopeHash || $existing->selectionHash() !== Contract::hash($items)) {
                throw new QuoteException('quote_conflict', 409);
            }

            return $existing;
        }
        $now = $this->now();
        $lines = $this->resolve($binding, $siteId, $items);
        $tax = $this->taxPolicy->assess($binding, $siteId, $lines, $now);
        $tax->validate($binding, $siteId, $lines, $now);
        $expiry = min($now->modify('+'.$this->ttlSeconds.' seconds'), $tax->expiresAt);
        $quote = new TrustedQuote((string) Str::uuid(), (string) Str::uuid(), $binding, $actorId, $siteId, $requestId,
            $items, $lines, $tax, $now, $expiry);

        return $this->repository->saveIfAbsent($quote);
    }

    /** Historical identity check for an existing same-request attempt, without repricing it. */
    public function loadIdentity(MerchantBinding $binding, int $actorId, int $siteId, string $quoteId, int $version = 1): TrustedQuote
    {
        $quote = $this->repository->findById($binding->tenantId, ItemSelection::uuid($quoteId));
        if (! $quote || $quote->actorId !== $actorId || $quote->siteId !== $siteId || $quote->binding->registerId !== $binding->registerId) {
            throw new QuoteException('quote_not_found', 404);
        }
        if ($quote->version !== $version) {
            throw new QuoteException('quote_conflict', 409);
        }
        if ($quote->binding->fingerprint() !== $binding->fingerprint()) {
            throw new QuoteException('quote_binding_changed', 409);
        }

        return $quote;
    }

    /** Before a NEW attempt only; Website stock hold/commit remains an independent required gate. */
    public function loadForAttempt(MerchantBinding $binding, int $actorId, int $siteId, string $quoteId, int $version = 1): TrustedQuote
    {
        $quote = $this->loadIdentity($binding, $actorId, $siteId, $quoteId, $version);
        $now = $this->now();
        if ($now >= $quote->expiresAt) {
            throw new QuoteException('quote_expired', 409);
        }
        $lines = $this->resolve($binding, $siteId, $quote->items);
        if (TaxAssessment::terms($lines) !== TaxAssessment::terms($quote->catalogLines)) {
            throw new QuoteException('catalog_changed', 409);
        }
        $tax = $this->taxPolicy->assess($binding, $siteId, $lines, $now);
        $tax->validate($binding, $siteId, $lines, $now);
        if ($tax->fingerprint() !== $quote->tax->fingerprint()) {
            throw new QuoteException('tax_policy_changed', 409);
        }

        return $quote;
    }

    private function resolve(MerchantBinding $binding, int $siteId, array $items): array
    {
        return array_map(function (array $item) use ($binding, $siteId): CatalogLine {
            $line = $this->catalog->item($binding->tenantId, $siteId, $item['variant_id'], $item['quantity']);
            if ($line->revision !== $item['expected_revision']) {
                throw new QuoteException('catalog_changed', 409);
            }

            return $line;
        }, $items);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
