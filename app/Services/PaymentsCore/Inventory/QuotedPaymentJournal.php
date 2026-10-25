<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Inventory;

use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\Quotes\TrustedQuote;

/** Full server quote admission, never app prices or arbitrary callbacks. */
interface QuotedPaymentJournal extends PaymentJournal
{
    public function reserveQuotedPayment(TrustedQuote $quote, string $requestId): array;
}
