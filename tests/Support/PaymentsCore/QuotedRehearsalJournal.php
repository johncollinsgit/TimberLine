<?php

declare(strict_types=1);

namespace Tests\Support\PaymentsCore;

use App\Services\PaymentsCore\Inventory\QuotedPaymentJournal;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\ProviderEvidence;
use App\Services\PaymentsCore\Quotes\TrustedQuote;
use App\Services\PaymentsCore\SaleSnapshot;

/** Legacy authorization/replay rehearsal only; DOES NOT prove atomic inventory.
 * Actual stock/intent/confirmation evidence comes from MobileInventoryMySqlTest.
 * Never bound by application code or used as an application fallback.
 */
final class QuotedRehearsalJournal implements QuotedPaymentJournal
{
    public function __construct(private PaymentJournal $inner) {}

    public function reserveQuotedPayment(TrustedQuote $quote, string $requestId): array
    {
        return $this->inner->reservePayment($quote->binding, $quote->toSaleSnapshot(), $requestId);
    }

    public function reservePayment(MerchantBinding $binding, SaleSnapshot $sale, string $requestId): array
    {
        return $this->inner->reservePayment($binding, $sale, $requestId);
    }

    public function reserveRefund(int $tenantId, string $paymentId, string $requestId, int $amountMinor, string $reason): array
    {
        return $this->inner->reserveRefund($tenantId, $paymentId, $requestId, $amountMinor, $reason);
    }

    public function get(int $tenantId, string $id): array
    {
        return $this->inner->get($tenantId, $id);
    }

    public function findByRequest(int $tenantId, string $requestId): ?array
    {
        return $this->inner->findByRequest($tenantId, $requestId);
    }

    public function claim(int $tenantId, string $id): bool
    {
        return $this->inner->claim($tenantId, $id);
    }

    public function unknown(int $tenantId, string $id): array
    {
        return $this->inner->unknown($tenantId, $id);
    }

    public function apply(int $tenantId, string $id, ProviderEvidence $evidence): array
    {
        return $this->inner->apply($tenantId, $id, $evidence);
    }
}
