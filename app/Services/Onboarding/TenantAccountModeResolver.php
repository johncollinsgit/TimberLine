<?php

namespace App\Services\Onboarding;

use App\Models\Tenant;
use App\Support\Onboarding\AccountMode;

class TenantAccountModeResolver
{
    public function resolveForTenant(?Tenant $tenant): AccountMode
    {
        $raw = $tenant?->accessProfile?->metadata['account_mode'] ?? null;
        $normalized = strtolower(trim((string) $raw));
        $mode = $normalized !== '' ? AccountMode::tryFrom($normalized) : null;

        return $mode ?? AccountMode::Production;
    }
}

