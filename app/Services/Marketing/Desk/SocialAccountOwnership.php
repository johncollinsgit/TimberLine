<?php

namespace App\Services\Marketing\Desk;

use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\DB;

class SocialAccountOwnership
{
    public function claim(int $tenantId, string $provider, string $fingerprint): void
    {
        abort_if(IntegrationConnection::forAllTenants()->where('provider', $provider)->where('external_account_id', $fingerprint)->where('status', 'connected')->where('tenant_id', '!=', $tenantId)->exists(), 409, 'This social account already belongs to another workspace.');
        DB::table('marketing_social_account_bindings')->insertOrIgnore(['tenant_id' => $tenantId, 'provider' => $provider, 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
        abort_unless((int) DB::table('marketing_social_account_bindings')->where('provider', $provider)->where('fingerprint', $fingerprint)->value('tenant_id') === $tenantId, 409, 'This social account needs an ownership review before it can move workspaces.');
    }
}
