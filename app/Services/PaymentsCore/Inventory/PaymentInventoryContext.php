<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Inventory;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\Quotes\TrustedQuote;

final readonly class PaymentInventoryContext
{
    private function __construct(public TrustedQuote $quote) {}

    public static function fromQuote(TrustedQuote $quote): self
    {
        return new self($quote);
    }

    public function snapshot(): array
    {
        return $this->quote->snapshot();
    }

    public function fingerprint(): string
    {
        return Contract::hash($this->snapshot());
    }

    public static function restore(array $snapshot): self
    {
        return new self(TrustedQuote::restore($snapshot));
    }
}
