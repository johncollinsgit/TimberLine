<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/** Production-capable persistence contract, not an activated payment provider.
 * Schema is installed exclusively by reviewed migrations. No network/HTTP runs
 * in these transactions, including their bounded deadlock retries.
 */
final class MySqlPaymentJournal implements NativeAtomicPaymentJournal, PaymentJournal
{
    public function __construct(private Connection $db, private Encrypter $crypt)
    {
        if ($db->getDriverName() !== 'mysql') {
            throw new DomainException('The MySQL payment journal requires MySQL.');
        }
    }

    public function reservePayment(MerchantBinding $binding, SaleSnapshot $sale, string $requestId): array
    {
        Contract::id($requestId);

        return $this->locked($binding->tenantId, function () use ($binding, $sale, $requestId): array {
            $hash = Contract::hash([$binding->snapshot(), $sale->snapshot()]);
            // Insert/upsert immutable identities BEFORE any absent-key locking
            // reads: RR gap locks on different tenants must not form cycles.
            $this->db->statement('INSERT INTO payment_core_bindings (tenant_id, register_id, fingerprint, binding) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                [$binding->tenantId, $binding->registerId, $binding->fingerprint(), $this->encrypt($binding->snapshot())]);
            $register = $this->db->table('payment_core_bindings')->where('tenant_id', $binding->tenantId)
                ->where('register_id', $binding->registerId)->lockForUpdate()->first();
            if (! $register || ! hash_equals($register->fingerprint, $binding->fingerprint())) {
                throw new DomainException('Register merchant binding is immutable. Provision a separately reviewed register identity.');
            }
            [$row, $created] = $this->insert($binding, (int) $register->id, $requestId, $sale->saleId, 'payment', $hash,
                $sale->snapshot(), $sale->amountMinor, $sale->currency);
            if (! $created) {
                return $row;
            }
            if ($this->db->table('payment_core_operations')->where('tenant_id', $binding->tenantId)
                ->where('register_id', $binding->registerId)->where('id', '<>', $row['id'])
                ->whereIn('state', ['reserved', 'processing', 'unknown', 'review'])
                ->where(fn ($active) => $active->where('state', '<>', 'unknown')
                    ->orWhereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_core_events as parked')
                        ->whereColumn('parked.operation_id', 'payment_core_operations.id')->where('parked.event_code', 'explicit_review_parked')))
                ->lockForUpdate()->first()) {
                throw new DomainException('Resolve the register operation before another sale.');
            }
            $this->event($row['id'], 'reserved', 0);

            return $row;
        });
    }

    /** Atomically fence one owned-stock native descriptor against cancellation. */
    public function handoffNative(MerchantBinding $binding, SaleSnapshot $sale, string $requestId, string $claimId): array
    {
        Contract::id($requestId);
        if ($binding->provider !== 'square_native' || ! preg_match('/^[a-f0-9-]{36}$/D', $claimId)) {
            throw new DomainException('Invalid native handoff identity.');
        }

        return $this->locked($binding->tenantId, function () use ($binding, $sale, $requestId, $claimId): array {
            $current = $this->db->table('pos_live_sales')->where('runtime_id', $binding->runtimeId)
                ->where('tenant_id', $binding->tenantId)->where('register_id', $binding->registerId)
                ->where('id', $sale->saleId)->lockForUpdate()->first();
            $claim = $this->db->table('pos_payment_start_claims')->where('runtime_id', $binding->runtimeId)
                ->where('tenant_id', $binding->tenantId)->where('sale_id', $sale->saleId)->where('id', $claimId)->lockForUpdate()->first();
            if (! $current || ! $claim || $claim->state !== 'preparing_native_v1' || $claim->payment_request_id !== $requestId
                || $current->payment_request_id !== $requestId || $current->state !== 'quoted'
                || $current->commerce_source !== 'everbranch_inventory_v1' || $current->stock_state !== 'held'
                || $current->attempt_id || $current->descriptor_issued_at || $current->source_reference
                || strtotime($current->expires_at) <= time()) {
                throw new DomainException('Native handoff is no longer available.');
            }
            $snapshot = json_decode($this->crypt->decrypt($current->snapshot, false), true, 512, JSON_THROW_ON_ERROR);
            if (! hash_equals($current->snapshot_hash, Contract::hash($snapshot))
                || Contract::hash($snapshot['binding'] ?? []) !== Contract::hash($binding->snapshot())
                || Contract::hash($snapshot['sale'] ?? []) !== Contract::hash($sale->snapshot())
                || (int) $current->amount_minor !== $sale->amountMinor || strtolower($current->currency) !== $sale->currency) {
                throw new DomainException('Native handoff terms require review.');
            }
            $holdKey = 'pos:'.$binding->runtimeId.':'.$sale->saleId;
            $stockLock = $this->db->table('commerce_stock_operations')->where('tenant_id', $binding->tenantId)
                ->where('request_hash', hash('sha256', 'collect-lock:'.$holdKey))->where('kind', 'collection_lock')->lockForUpdate()->first();
            $stockHolds = $this->db->table('commerce_stock_reservations')->where('tenant_id', $binding->tenantId)
                ->where('hold_key', $holdKey)->lockForUpdate()->get();
            if (! $stockLock || $stockHolds->contains(fn ($hold): bool => $hold->state !== 'held' || $hold->collection_locked_at === null)) {
                throw new DomainException('Native inventory reservation requires review.');
            }
            $this->db->statement('INSERT INTO payment_core_bindings (tenant_id, register_id, fingerprint, binding) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                [$binding->tenantId, $binding->registerId, $binding->fingerprint(), $this->encrypt($binding->snapshot())]);
            $register = $this->db->table('payment_core_bindings')->where('tenant_id', $binding->tenantId)
                ->where('register_id', $binding->registerId)->lockForUpdate()->first();
            if (! $register || ! hash_equals($register->fingerprint, $binding->fingerprint())) {
                throw new DomainException('Register merchant binding is immutable.');
            }
            $hash = Contract::hash([$binding->snapshot(), $sale->snapshot()]);
            [$row, $created] = $this->insert($binding, (int) $register->id, $requestId, $sale->saleId, 'payment', $hash,
                $sale->snapshot(), $sale->amountMinor, $sale->currency);
            if (! $created || $this->db->table('payment_core_operations')->where('tenant_id', $binding->tenantId)
                ->where('register_id', $binding->registerId)->where('id', '<>', $row['id'])
                ->whereIn('state', ['reserved', 'processing', 'unknown', 'review'])
                ->where(fn ($active) => $active->where('state', '<>', 'unknown')
                    ->orWhereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_core_events as parked')
                        ->whereColumn('parked.operation_id', 'payment_core_operations.id')->where('parked.event_code', 'explicit_review_parked')))
                ->lockForUpdate()->exists()) {
                throw new DomainException('Resolve the register operation before another sale.');
            }
            $this->event($row['id'], 'reserved', 0);
            $this->event($row['id'], 'dispatch_claimed', 0);
            $this->db->table('payment_core_operations')->where('tenant_id', $binding->tenantId)->where('id', $row['id'])
                ->update(['claimed' => true, 'state' => 'unknown']);
            $this->db->table('pos_live_sales')->where('runtime_id', $binding->runtimeId)->where('tenant_id', $binding->tenantId)
                ->where('id', $sale->saleId)->update(['attempt_id' => $row['id'], 'state' => 'unknown', 'descriptor_issued_at' => now()]);
            $this->db->table('pos_payment_start_claims')->where('id', $claimId)->update(['state' => 'handed_off', 'updated_at' => now()]);
            $this->db->table('pos_live_events')->insert(['runtime_id' => $binding->runtimeId, 'tenant_id' => $binding->tenantId,
                'sale_id' => $sale->saleId, 'code' => 'native_descriptor_issued',
                'details' => $this->crypt->encrypt('[]', false), 'created_at' => now()]);

            return $this->load($binding->tenantId, $row['id']);
        });
    }

    public function reserveRefund(int $tenantId, string $paymentId, string $requestId, int $amountMinor, string $reason): array
    {
        Contract::id($requestId);
        Contract::minor($amountMinor);
        if (! in_array($reason, ['requested_by_customer', 'duplicate', 'other'], true)) {
            throw new DomainException('A supported reason is required.');
        }

        return $this->locked($tenantId, function () use ($tenantId, $paymentId, $requestId, $amountMinor, $reason): array {
            $payment = $this->load($tenantId, $paymentId);
            if ($payment['kind'] !== 'payment' || $payment['state'] !== 'succeeded' || ! $payment['provider_id']) {
                throw new DomainException('Refunds require the original verified paid payment.');
            }
            $binding = MerchantBinding::restore($payment['binding']);
            $terms = ['payment_id' => $paymentId, 'amount_minor' => $amountMinor, 'reason' => $reason];
            $hash = Contract::hash([$payment['binding'], $terms]);
            [$row, $created] = $this->insert($binding, $payment['binding_id'], $requestId, $payment['sale_id'], 'refund',
                $hash, $terms, $amountMinor, $payment['currency'], $paymentId, $payment['provider_id']);
            if (! $created) {
                return $row;
            }
            // Candidate exists before the range lock, avoiding missing-range
            // gap-lock cycles. Parent/admission serialize the current budget.
            $reserved = $this->db->table('payment_core_operations')->where('tenant_id', $tenantId)
                ->where('original_id', $paymentId)->whereNotIn('state', ['failed', 'cancelled'])
                ->lockForUpdate()->get()->sum(fn ($refund) => $this->decode((array) $refund)['amount_minor']);
            if ($reserved > $payment['amount_minor']) {
                throw new DomainException('Refund amount exceeds unreserved original-payment value.');
            }
            $this->event($row['id'], 'reserved', 0);

            return $row;
        });
    }

    public function get(int $tenantId, string $id): array
    {
        // Refuse outer transactions on reads too: Core may contact the provider
        // immediately after this method returns during explicit recovery.
        $this->outsideTransaction();

        return $this->load($tenantId, $id, false);
    }

    public function findByRequest(int $tenantId, string $requestId): ?array
    {
        $this->outsideTransaction();
        Contract::id($requestId);
        $row = $this->db->table('payment_core_operations')->where('tenant_id', $tenantId)
            ->where('request_id', $requestId)->first();

        return $row ? $this->decode((array) $row) : null;
    }

    public function claim(int $tenantId, string $id): bool
    {
        return $this->locked($tenantId, function () use ($tenantId, $id): bool {
            $row = $this->load($tenantId, $id);
            if ($row['claimed'] !== 0 || $row['state'] !== 'reserved') {
                return false;
            }
            $this->db->table('payment_core_operations')->where('tenant_id', $tenantId)->where('id', $id)
                ->update(['claimed' => true, 'state' => 'processing']);
            $this->event($id, 'dispatch_claimed', 0);

            return true;
        });
    }

    public function unknown(int $tenantId, string $id): array
    {
        return $this->locked($tenantId, function () use ($tenantId, $id): array {
            $row = $this->load($tenantId, $id);
            if (in_array($row['state'], ['reserved', 'processing', 'unknown'], true)) {
                $this->db->table('payment_core_operations')->where('tenant_id', $tenantId)->where('id', $id)->update(['state' => 'unknown']);
            }

            return $this->load($tenantId, $id);
        });
    }

    public function parkForReview(int $tenantId, string $id): array
    {
        return $this->locked($tenantId, function () use ($tenantId, $id): array {
            $row = $this->load($tenantId, $id);
            if ($row['kind'] !== 'payment' || $row['binding']['provider'] !== 'square_native'
                || $row['state'] !== 'unknown' || $row['claimed'] !== 1 || $row['provider_id'] !== null) {
                throw new DomainException('Only an unresolved native interaction with no provider payment can be parked.');
            }
            if (! $row['parked_for_review']) {
                $this->event($id, 'explicit_review_parked', $row['version']);
            }

            return $this->load($tenantId, $id);
        });
    }

    public function apply(int $tenantId, string $id, ProviderEvidence $evidence): array
    {
        try {
            return $this->applyOnce($tenantId, $id, $evidence);
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }

            // Concurrent global canonical-effect assignment lost the unique
            // constraint race. The first transaction has committed; a new
            // transaction observes that reference and quarantines this one.
            return $this->applyOnce($tenantId, $id, $evidence);
        }
    }

    private function applyOnce(int $tenantId, string $id, ProviderEvidence $evidence): array
    {
        return $this->locked($tenantId, function () use ($tenantId, $id, $evidence): array {
            $row = $this->load($tenantId, $id);
            $binding = MerchantBinding::restore($row['binding']);
            $valid = $row['claimed'] === 1 && $evidence->operationId === $row['request_id']
                && hash_equals($binding->fingerprint(), $evidence->bindingFingerprint)
                && $row['amount_minor'] === $evidence->amountMinor && $row['currency'] === $evidence->currency
                && ($row['provider_id'] === null || $row['provider_id'] === $evidence->providerId)
                && $row['original_provider_id'] === $evidence->originalPaymentId;
            if (! $valid) {
                return $this->review($row, 'evidence_mismatch', $evidence->version);
            }
            if ($evidence->version <= $row['version']) {
                return $row;
            }
            // Exact effect uniqueness spans tenant/register/location. Current
            // locking read plus the global unique constraint handles contenders.
            $duplicate = $this->db->table('payment_core_operations')->where('effect_scope', $row['effect_scope'])
                ->where('kind', $row['kind'])->where('provider_id', $evidence->providerId)
                ->where('id', '<>', $id)->first();
            if ($duplicate) {
                return $this->review($row, 'provider_reference_reused', $evidence->version);
            }
            if (in_array($row['state'], ['succeeded', 'failed', 'cancelled'], true)) {
                if ($row['state'] !== $evidence->state) {
                    $this->event($id, 'terminal_evidence_conflict', $evidence->version);
                }

                return $row;
            }
            $this->event($id, 'verified_'.$evidence->state, $evidence->version);
            $this->db->table('payment_core_operations')->where('tenant_id', $tenantId)->where('id', $id)
                ->update(['state' => $evidence->state, 'provider_id' => $evidence->providerId, 'version' => $evidence->version]);

            return $this->load($tenantId, $id);
        });
    }

    private function insert(MerchantBinding $binding, int $bindingId, string $requestId, string $saleId, string $kind, string $hash, array $terms, int $amount, string $currency, ?string $originalId = null, ?string $originalProviderId = null): array
    {
        $id = bin2hex(random_bytes(16));
        $values = [
            'id' => $id, 'tenant_id' => $binding->tenantId, 'binding_id' => $bindingId,
            'register_id' => $binding->registerId, 'sale_id' => $saleId,
            'payment_sale_key' => $kind === 'payment' ? $saleId : null,
            'request_id' => $requestId, 'kind' => $kind, 'original_id' => $originalId, 'terms_hash' => $hash,
            'binding' => $this->encrypt($binding->snapshot()), 'terms' => $this->encrypt($terms),
            'amount_minor' => $amount, 'currency' => $currency, 'state' => 'reserved',
            'original_provider_id' => $originalProviderId,
            'effect_scope' => Contract::hash([$binding->provider, $binding->merchantAccount, $binding->environment]),
        ];
        $columns = implode('`, `', array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->db->statement('INSERT INTO payment_core_operations (`'.$columns.'`) VALUES ('.$placeholders.') ON DUPLICATE KEY UPDATE id = id', array_values($values));
        $row = $this->byRequest($binding->tenantId, $requestId);
        if (! $row) {
            throw new DomainException('This sale already has a payment attempt. Recover its original request.');
        }
        $this->same($row, $hash, $kind);

        return [$row, $row['id'] === $id];
    }

    private function review(array $row, string $code, int $version): array
    {
        $this->event($row['id'], $code, $version);
        if (! in_array($row['state'], ['succeeded', 'failed', 'cancelled'], true)) {
            $this->db->table('payment_core_operations')->where('tenant_id', $row['tenant_id'])->where('id', $row['id'])->update(['state' => 'review']);
        }

        return $this->load($row['tenant_id'], $row['id']);
    }

    private function load(int $tenantId, string $id, bool $lock = true): array
    {
        $query = $this->db->table('payment_core_operations')->where('tenant_id', $tenantId)->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new DomainException('Payment operation is unavailable.');
        }

        return $this->decode((array) $row);
    }

    private function byRequest(int $tenantId, string $requestId): ?array
    {
        $row = $this->db->table('payment_core_operations')->where('tenant_id', $tenantId)
            ->where('request_id', $requestId)->lockForUpdate()->first();

        return $row ? $this->decode((array) $row) : null;
    }

    private function same(array $row, string $hash, string $kind): void
    {
        if ($row['kind'] !== $kind || ! hash_equals($row['terms_hash'], $hash)) {
            throw new DomainException('Idempotency key already has different immutable terms.');
        }
    }

    private function decode(array $row): array
    {
        $row['parked_for_review'] = $this->db->table('payment_core_events')->where('operation_id', $row['id'])->where('event_code', 'explicit_review_parked')->exists();
        $row['binding'] = json_decode($this->crypt->decrypt($row['binding'], false), true, 512, JSON_THROW_ON_ERROR);
        $row['terms'] = json_decode($this->crypt->decrypt($row['terms'], false), true, 512, JSON_THROW_ON_ERROR);
        foreach (['tenant_id', 'binding_id', 'amount_minor', 'claimed', 'version'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $binding = MerchantBinding::restore($row['binding']);
        if ($binding->tenantId !== $row['tenant_id'] || $binding->registerId !== $row['register_id']
            || ! hash_equals(Contract::hash([$row['binding'], $row['terms']]), $row['terms_hash'])
            || ! hash_equals(Contract::hash([$binding->provider, $binding->merchantAccount, $binding->environment]), $row['effect_scope'])) {
            throw new DomainException('Stored payment terms require integrity review.');
        }
        $register = $this->db->table('payment_core_bindings')->where('id', $row['binding_id'])
            ->where('tenant_id', $row['tenant_id'])->where('register_id', $row['register_id'])->first();
        if (! $register || ! hash_equals($register->fingerprint, $binding->fingerprint())
            || ! hash_equals($register->fingerprint, Contract::hash(json_decode($this->crypt->decrypt($register->binding, false), true, 512, JSON_THROW_ON_ERROR)))) {
            throw new DomainException('Stored register binding requires integrity review.');
        }
        if ($row['kind'] === 'payment') {
            $sale = new SaleSnapshot($row['terms']['sale_id'], $row['terms']['currency'], $row['terms']['lines']);
            if ($sale->snapshot() !== $row['terms'] || $sale->saleId !== $row['sale_id']
                || $sale->amountMinor !== $row['amount_minor'] || $sale->currency !== $row['currency']
                || $row['payment_sale_key'] !== $row['sale_id'] || $row['original_id'] !== null || $row['original_provider_id'] !== null) {
                throw new DomainException('Stored payment amount or identity requires integrity review.');
            }
        } elseif ($row['kind'] === 'refund') {
            $original = $this->db->table('payment_core_operations')->where('tenant_id', $row['tenant_id'])
                ->where('id', $row['original_id'])->where('kind', 'payment')->first();
            if (! $original || $row['terms']['payment_id'] !== $row['original_id']
                || $row['terms']['amount_minor'] !== $row['amount_minor'] || $row['payment_sale_key'] !== null) {
                throw new DomainException('Stored refund amount or identity requires integrity review.');
            }
            $payment = $this->decode((array) $original);
            if ($payment['state'] !== 'succeeded' || $payment['currency'] !== $row['currency']
                || $payment['sale_id'] !== $row['sale_id'] || $payment['binding'] !== $row['binding']
                || $payment['provider_id'] !== $row['original_provider_id'] || $row['amount_minor'] > $payment['amount_minor']) {
                throw new DomainException('Stored refund original-payment context requires integrity review.');
            }
        } else {
            throw new DomainException('Stored payment operation kind requires integrity review.');
        }

        return $row;
    }

    private function encrypt(array $value): string
    {
        return $this->crypt->encrypt(Contract::json($value), false);
    }

    private function event(string $id, string $code, int $version): void
    {
        $this->db->table('payment_core_events')->insert(['operation_id' => $id, 'event_code' => $code, 'evidence_version' => $version]);
    }

    private function outsideTransaction(): void
    {
        if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) {
            throw new DomainException('Payment journal requires its own committed transaction boundary.');
        }
    }

    private function locked(int $tenantId, callable $work): mixed
    {
        $this->outsideTransaction();
        if ($tenantId < 1) {
            throw new DomainException('Invalid tenant scope.');
        }

        return $this->db->transaction(function () use ($tenantId, $work): mixed {
            // A dedicated tenant mutex avoids locking Tenant parents needed by
            // inventory/order FK inserts. It also avoids absent-key RR cycles.
            $this->db->statement('INSERT INTO payment_core_admissions (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)', [$tenantId]);
            $this->db->table('payment_core_admissions')->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();

            return $work();
        }, 3);
    }
}
