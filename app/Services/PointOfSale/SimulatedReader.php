<?php

namespace App\Services\PointOfSale;

/** Local fictional evidence only. A real Terminal adapter requires separate acceptance. */
interface SimulatedReader extends PosPaymentProvider
{
    public function simulatePayment(int $tenantId, string $id, string $outcome): array;

    public function simulateRefund(int $tenantId, string $id, string $outcome): array;
}
