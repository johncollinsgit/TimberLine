<?php

namespace App\Models\Concerns;

use App\Services\Marketing\CandleCashLegacyCompatibilityService;

trait TracksLegacyCandleCashCompatibility
{
    protected function recordLegacyCandleCashCompatibility(string $path, string $operation, string $context): void
    {
        try {
            app(CandleCashLegacyCompatibilityService::class)->record($path, $operation, $context);
        } catch (\Throwable) {
            // Compatibility telemetry must never interrupt the primary code path.
        }
    }
}
