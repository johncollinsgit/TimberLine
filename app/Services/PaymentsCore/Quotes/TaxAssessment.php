<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use DateTimeImmutable;

/** Explicit evidence: zero tax is valid only when a policy returns reviewed zero line amounts. */
final readonly class TaxAssessment
{
    public function __construct(
        public string $authority,
        public string $policyRevision,
        public string $scopeFingerprint,
        public string $termsFingerprint,
        public string $currency,
        public string $treatment,
        public string $rounding,
        public array $lineTaxes,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $expiresAt,
    ) {
        Contract::id($authority);
        Contract::id($policyRevision);
        if ($currency !== 'usd' || $treatment !== 'exclusive'
            || ! in_array($rounding, ['per_line_half_up', 'invoice_half_up'], true)
            || ! preg_match('/^[a-f0-9]{64}$/D', $scopeFingerprint)
            || ! preg_match('/^[a-f0-9]{64}$/D', $termsFingerprint)
            || $expiresAt <= $effectiveAt) {
            throw new QuoteException('tax_policy_unsupported', 503);
        }
        foreach ($lineTaxes as $lineId => $amount) {
            if (! is_string($lineId) || ! is_int($amount) || $amount < 0 || $amount > Contract::MAX_MINOR) {
                throw new QuoteException('tax_evidence_invalid', 503);
            }
            Contract::id($lineId);
        }
    }

    public static function scope(MerchantBinding $binding, int $siteId): string
    {
        return Contract::hash(['binding' => $binding->fingerprint(), 'site_id' => $siteId]);
    }

    public static function terms(array $lines): string
    {
        return Contract::hash(array_map(fn (CatalogLine $line) => $line->terms(), $lines));
    }

    public function validate(MerchantBinding $binding, int $siteId, array $lines, DateTimeImmutable $at): void
    {
        if ($at < $this->effectiveAt || $at >= $this->expiresAt) {
            throw new QuoteException('tax_policy_expired', 503);
        }
        $ids = array_map(fn (CatalogLine $line) => $line->lineId, $lines);
        $taxIds = array_keys($this->lineTaxes);
        sort($ids);
        sort($taxIds);
        if ($ids !== $taxIds || $this->scopeFingerprint !== self::scope($binding, $siteId)
            || $this->termsFingerprint !== self::terms($lines)
            || count(array_unique(array_map(fn (CatalogLine $line) => $line->currency, $lines))) !== 1
            || $this->currency !== $lines[0]->currency) {
            throw new QuoteException('tax_evidence_invalid', 503);
        }
    }

    public function fingerprint(): string
    {
        $taxes = $this->lineTaxes;
        ksort($taxes);

        return Contract::hash(['authority' => $this->authority, 'policy_revision' => $this->policyRevision,
            'scope_fingerprint' => $this->scopeFingerprint, 'terms_fingerprint' => $this->termsFingerprint,
            'currency' => $this->currency, 'treatment' => $this->treatment, 'rounding' => $this->rounding, 'line_taxes' => $taxes]);
    }

    public function snapshot(): array
    {
        return ['authority' => $this->authority, 'policy_revision' => $this->policyRevision,
            'scope_fingerprint' => $this->scopeFingerprint, 'terms_fingerprint' => $this->termsFingerprint,
            'currency' => $this->currency, 'treatment' => $this->treatment, 'rounding' => $this->rounding,
            'line_taxes' => $this->lineTaxes, 'effective_at' => $this->effectiveAt->format(DATE_ATOM), 'expires_at' => $this->expiresAt->format(DATE_ATOM)];
    }

    public static function restore(array $data): self
    {
        return new self($data['authority'], $data['policy_revision'], $data['scope_fingerprint'], $data['terms_fingerprint'],
            $data['currency'], $data['treatment'], $data['rounding'], $data['line_taxes'],
            new DateTimeImmutable($data['effective_at']), new DateTimeImmutable($data['expires_at']));
    }
}
