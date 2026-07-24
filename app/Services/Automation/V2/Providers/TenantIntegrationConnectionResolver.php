<?php

namespace App\Services\Automation\V2\Providers;

use App\Models\IntegrationConnection;
use App\Services\Automation\AutomationWorkflowException;
use Illuminate\Support\Str;

class TenantIntegrationConnectionResolver
{
    public function resolve(int $tenantId, ?int $connectionId, string $provider): IntegrationConnection
    {
        $query = IntegrationConnection::query()
            ->forTenantId($tenantId)
            ->where('provider', $provider)
            ->where('status', IntegrationConnection::STATUS_CONNECTED);

        $connection = $connectionId !== null && $connectionId > 0
            ? $query->whereKey($connectionId)->first()
            : $query->latest('connected_at')->latest('id')->first();

        if (! $connection) {
            throw new AutomationWorkflowException(
                'The selected '.Str::headline($provider).' connection is unavailable. Reconnect it and try again.'
            );
        }

        return $connection;
    }
}
