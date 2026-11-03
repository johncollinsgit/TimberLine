<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

/** Native-only handoff; the journal owns the transaction and performs no provider I/O. */
interface NativeAtomicPaymentJournal
{
    public function handoffNative(MerchantBinding $binding, SaleSnapshot $sale, string $requestId, string $claimId): array;
}
