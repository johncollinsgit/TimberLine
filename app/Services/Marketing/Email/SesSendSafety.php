<?php

namespace App\Services\Marketing\Email;

use App\Models\MarketingEmailDelivery;
use App\Models\TenantMessagingLedgerEntry;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SesSendSafety
{
    /** This guard stays active after switching configuration back to SendGrid. */
    public function priorResult(int $tenantId, string $email, ?int $deliveryId = null): ?array
    {
        if ($deliveryId) {
            $delivery = MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->find($deliveryId);
            if ($attempt = data_get($delivery?->metadata, 'ses_attempt')) {
                if (strtolower(trim($delivery->email)) !== strtolower(trim($email))) {
                    return $this->heldResult();
                }

                return $attempt['result'] ?? $this->heldResult();
            }
        }
        $unresolved = MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->where('provider', 'ses_tenant')
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])
            ->whereIn('metadata->ses_attempt->state', ['submitting', 'unknown'])->exists();

        return $unresolved ? $this->heldResult() : null;
    }

    protected function heldResult(): array
    {
        return ['success' => false, 'provider' => 'ses_tenant', 'status' => 'unknown',
            'message_id' => null, 'error_code' => 'ses_reconciliation_required',
            'error_message' => 'A prior SES attempt requires reconciliation before another provider send.',
            'retryable' => false, 'payload' => [], 'dry_run' => false];
    }

    /** Persist the claim before HTTP. A crash or lost response leaves it held. */
    public function claim(int $deliveryId, int $tenantId, string $email, array $payload, array $usageReservation = []): ?array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SES cannot send inside a caller transaction.');
        }

        if ($prior = $this->priorResult($tenantId, $email, $deliveryId)) {
            return $prior;
        }
        // Admission is outside the delivery transaction: a database-backed cache
        // must not read counters from an older MySQL repeatable-read snapshot.
        // A failed/duplicate claim may consume capacity, never the reverse.
        if (! $this->admit($tenantId)) {
            throw new RuntimeException('SES attempt cap reached.');
        }

        return DB::transaction(function () use ($deliveryId, $tenantId, $email, $payload, $usageReservation): ?array {
            \App\Models\Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $delivery = MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($deliveryId);
            if (strtolower(trim($delivery->email)) !== strtolower(trim($email)) || $delivery->provider !== 'ses_tenant') {
                throw new RuntimeException('SES delivery ownership does not match.');
            }
            $attempt = data_get($delivery->metadata, 'ses_attempt');
            if ($attempt) {
                // Never re-send even if the caller creates a fresh queue retry.
                return $attempt['result'] ?? [
                    'success' => false, 'provider' => 'ses_tenant', 'status' => 'unknown',
                    'message_id' => null, 'error_code' => 'ses_reconciliation_required',
                    'error_message' => 'SES attempt is reserved; reconcile provider events before any retry.',
                    'retryable' => false, 'payload' => [], 'dry_run' => false,
                ];
            }
            if ($delivery->provider_message_id || ! in_array($delivery->status, ['pending', 'sending', 'queued'], true)) {
                throw new RuntimeException('Existing delivery cannot be resubmitted to SES.');
            }
            if (MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->where('email', $delivery->email)
                ->where('provider', 'ses_tenant')->where('id', '!=', $deliveryId)
                ->whereIn('metadata->ses_attempt->state', ['submitting', 'unknown'])->exists()) {
                throw new RuntimeException('A prior SES attempt for this recipient needs reconciliation.');
            }
            if ($usageReservation !== []) {
                $this->assertUsageBinding($tenantId, $usageReservation);
                if (MarketingEmailDelivery::query()->where('tenant_id', $tenantId)
                    ->where('metadata->ses_attempt->usage_reservation->ledger_id', (int) $usageReservation['ledger_id'])->exists()) {
                    throw new RuntimeException('SES usage reservation is already bound to a delivery.');
                }
            }
            $delivery->forceFill(['metadata' => [...(array) $delivery->metadata, 'ses_attempt' => [
                'state' => 'submitting', 'claimed_at' => now()->toIso8601String(),
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'tenant_name' => $payload['TenantName'], 'configuration_set' => $payload['ConfigurationSetName'],
                'from' => preg_match('/<([^>]+)>$/', $payload['FromEmailAddress'], $address) ? $address[1] : $payload['FromEmailAddress'],
                'usage_reservation' => $usageReservation,
            ]]]);
            DB::table('marketing_email_deliveries')->where('id', $deliveryId)->update(['metadata' => json_encode($delivery->metadata, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return null;
        });
    }

    public function complete(int $deliveryId, int $tenantId, array $result): void
    {
        $accepted = DB::transaction(function () use ($deliveryId, $tenantId, $result): ?array {
            $delivery = MarketingEmailDelivery::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($deliveryId);
            $attempt = (array) data_get($delivery->metadata, 'ses_attempt', []);
            if (($attempt['result']['success'] ?? false) && ! ($result['success'] ?? false)) {
                return $attempt;
            }
            $delivery->forceFill([
                'provider_message_id' => $delivery->provider_message_id ?: ($result['message_id'] ?? null),
                'metadata' => [...(array) $delivery->metadata, 'ses_attempt' => [...$attempt,
                    'state' => $result['status'], 'result' => $result, 'completed_at' => now()->toIso8601String(),
                ]],
            ]);
            DB::table('marketing_email_deliveries')->where('id', $deliveryId)->update(['provider_message_id' => $delivery->provider_message_id, 'metadata' => json_encode($delivery->metadata, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return data_get($delivery->metadata, 'ses_attempt');
        });
        // Provider receipts and authenticated events resolve the same immutable
        // reservation. The usage service makes duplicate settlements harmless.
        $binding = (array) ($accepted['usage_reservation'] ?? []);
        if (($accepted['result']['success'] ?? false) && $binding !== []) {
            try {
                $this->assertUsageBinding($tenantId, $binding);
                app(TenantMessagingUsageService::class)->settle($tenantId, $binding['key'], ['provider_result' => $accepted['result']]);
            } catch (\Throwable $exception) {
                Log::critical('SES accepted usage requires settlement reconciliation', [
                    'tenant_id' => $tenantId, 'delivery_id' => $deliveryId, 'ledger_id' => $binding['ledger_id'] ?? null,
                    'error_class' => $exception::class,
                ]);
            }
        }
    }

    protected function assertUsageBinding(int $tenantId, array $binding): void
    {
        if (empty($binding['key']) || ! TenantMessagingLedgerEntry::query()->forAllTenants()
            ->whereKey((int) ($binding['ledger_id'] ?? 0))->where('tenant_id', $tenantId)
            ->where('channel', 'email')->where('entry_type', 'usage_reservation')
            ->where('idempotency_key', 'reserve:'.$binding['key'])
            ->where('metadata->provider', 'ses_tenant')->exists()) {
            throw new RuntimeException('SES usage reservation ownership does not match.');
        }
    }

    /** Shared cache required in production; all attempted sends consume capacity. */
    public function admit(int $tenantId): bool
    {
        $driver = config('cache.stores.'.config('cache.default').'.driver');
        if (app()->isProduction() && ! in_array($driver, ['database', 'redis'], true)) {
            throw new RuntimeException('SES requires a shared persistent cache for attempt caps.');
        }

        $cache = Cache::store();
        $limiter = new RateLimiter($cache);

        return $cache->lock('ses:admission', 10)->block(2, function () use ($tenantId, $limiter): bool {
            $day = now('UTC')->format('Y-m-d');
            $cost = (int) config('ses_migration.approved_attempt_cost_micros');
            $spend = (int) config('ses_migration.daily_spend_cap_micros');
            $limits = [
                'ses:account:'.$day => [min((int) config('ses_migration.account_daily_attempts'), $cost > 0 ? intdiv(max(0, $spend), $cost) : 0), 172800],
                'ses:tenant:'.$tenantId.':'.$day => [(int) config('ses_migration.tenant_daily_attempts'), 172800],
                'ses:second' => [(int) config('ses_migration.max_per_second'), 1],
            ];
            foreach ($limits as $key => [$max, $ttl]) {
                if ($max < 1 || $limiter->tooManyAttempts($key, $max)) {
                    return false;
                }
            }
            foreach ($limits as $key => [$max, $ttl]) {
                $limiter->hit($key, $ttl);
            }

            return true;
        });
    }
}
