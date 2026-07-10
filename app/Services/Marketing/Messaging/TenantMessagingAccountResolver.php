<?php

namespace App\Services\Marketing\Messaging;

use App\Models\TenantMessagingAccount;
use RuntimeException;

class TenantMessagingAccountResolver
{
    /**
     * @return array{mode:string,account:?TenantMessagingAccount,provider:string}
     */
    public function resolve(int $tenantId, string $channel): array
    {
        $channel = $this->channel($channel);
        $account = TenantMessagingAccount::query()
            ->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->first();

        if ((bool) config('features.tenant_messaging_platform') && $account?->isReady()) {
            $this->assertProviderCostIsAllowed($account->provider, $channel);

            return [
                'mode' => 'tenant',
                'account' => $account,
                'provider' => (string) $account->provider,
            ];
        }

        if ($this->isLegacyTenant($tenantId)) {
            return [
                'mode' => 'legacy',
                'account' => null,
                'provider' => $channel === 'email' ? 'sendgrid_global' : 'twilio_global',
            ];
        }

        throw new RuntimeException("Tenant {$tenantId} does not have a ready {$channel} messaging account.");
    }

    public function resolveTwilioCallback(string $accountSid): TenantMessagingAccount
    {
        $accountSid = trim($accountSid);
        $account = TenantMessagingAccount::query()
            ->forAllTenants()
            ->where('channel', 'sms')
            ->where('provider', 'twilio_subaccount')
            ->where('provider_account_id', $accountSid)
            ->first();

        if (! $account?->isReady()) {
            throw new RuntimeException('The Twilio callback account is not registered or ready.');
        }

        return $account;
    }

    public function isLegacyTenant(int $tenantId): bool
    {
        return in_array($tenantId, $this->legacyTenantIds(), true);
    }

    /** @return array<int,int> */
    public function legacyTenantIds(): array
    {
        $configured = config('marketing.messaging.platform.legacy_tenant_ids', [1]);
        $values = is_array($configured) ? $configured : explode(',', (string) $configured);

        return collect($values)
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function assertProviderCostIsAllowed(string $provider, string $channel): void
    {
        $provider = match ($provider) {
            'twilio_subaccount' => 'twilio',
            default => $provider,
        };
        $cost = (int) config("marketing.messaging.platform.provider_cost_micros.{$provider}.{$channel}", 0);
        $ceiling = (int) config("marketing.messaging.platform.provider_cost_ceiling_micros.{$channel}", 0);

        if ($cost <= 0 || $ceiling <= 0 || $cost > $ceiling) {
            throw new RuntimeException("Provider {$provider} exceeds the configured {$channel} cost ceiling.");
        }
    }

    protected function channel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        if (! in_array($channel, ['email', 'sms', 'mms'], true)) {
            throw new RuntimeException("Unsupported messaging channel: {$channel}");
        }

        return $channel === 'mms' ? 'sms' : $channel;
    }
}
