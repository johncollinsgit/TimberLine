<?php

namespace App\Enterprise;

use App\Models\Tenant;
use App\Models\User;

/** Implementations must commit a worst-case claim before IO and retain uncertain cost. */
interface VoiceCostReservation
{
    public function ready(Tenant $tenant): bool;

    public function generate(Tenant $tenant, User $user, string $area, array $payload, string $requestId): array;
}
