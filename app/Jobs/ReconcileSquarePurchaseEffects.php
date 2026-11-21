<?php

namespace App\Jobs;

use App\Services\Marketing\SquarePurchaseEffects;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileSquarePurchaseEffects implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public int $tries = 3;

    public function __construct(public int $tenant, public string $orderId) {}

    public function uniqueId(): string
    {
        return $this->tenant.':'.$this->orderId;
    }

    public function handle(SquarePurchaseEffects $effects): void
    {
        $effects->reconcile($this->tenant, $this->orderId);
    }
}
