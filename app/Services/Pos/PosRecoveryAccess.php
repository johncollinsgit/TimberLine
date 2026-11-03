<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentAccess;

/** Explicit worker authority: recover existing operations, never collect or refund. */
final class PosRecoveryAccess implements PaymentAccess
{
    public function __construct(private PosStore $store) {}

    public function assertAllowed(MerchantBinding $binding, string $operation): void
    {
        if (! in_array($operation, ['inspect', 'recover'], true) || $binding->runtimeId !== $this->store->runtime()
            || config('pos_live.servicing_enabled') !== true
            || ! $this->store->rows('registers', $binding->tenantId)->where('id', $binding->registerId)->where('servicing_enabled', true)->exists()) {
            throw new PosException('recovery_scope_denied', 403);
        }
    }
}
