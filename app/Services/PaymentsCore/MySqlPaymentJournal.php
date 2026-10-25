<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use App\Services\PaymentsCore\Inventory\InventoryUnavailableException;
use App\Services\PaymentsCore\Inventory\PaymentInventoryContext;
use App\Services\PaymentsCore\Inventory\QuotedPaymentJournal;
use App\Services\PaymentsCore\Inventory\WebsitePaymentInventory;
use App\Services\PaymentsCore\Quotes\TrustedQuote;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/** Production-capable persistence contract, not an activated payment provider.
 * Schema is installed exclusively by reviewed migrations. No network/HTTP runs
 * in these transactions, including their bounded deadlock retries.
 */
final class MySqlPaymentJournal implements QuotedPaymentJournal
{
    public function __construct(private Connection $db, private Encrypter $crypt, private ?WebsitePaymentInventory $inventory = null)
    {
        if ($db->getDriverName() !== 'mysql') {
            throw new DomainException('The MySQL payment journal requires MySQL.');
        }
    }

    public function reservePayment(MerchantBinding $binding, SaleSnapshot $sale, string $requestId): array
    {
        return $this->reserve($binding, $sale, $requestId, null);
    }

    public function reserveQuotedPayment(TrustedQuote $quote, string $requestId): array
    {
        if (! $this->inventory) {
            throw new InventoryUnavailableException('Atomic inventory participant is unavailable.');
        }

        return $this->reserve($quote->binding, $quote->toSaleSnapshot(), $requestId, PaymentInventoryContext::fromQuote($quote));
    }

    private function reserve(MerchantBinding $binding, SaleSnapshot $sale, string $requestId, ?PaymentInventoryContext $context): array
    {
        Contract::id($requestId);

        return $this->locked($binding->tenantId, function () use ($binding, $sale, $requestId, $context): array {
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
                if (($row['inventory_hash'] ?? null) !== $context?->fingerprint()) {
                    throw new DomainException('Inventory request already has different immutable quote terms.');
                }

                return $row;
            }
            if ($this->db->table('payment_core_operations')->where('tenant_id', $binding->tenantId)
                ->where('register_id', $binding->registerId)->where('id', '<>', $row['id'])
                ->whereIn('state', ['reserved', 'processing', 'unknown', 'review'])->lockForUpdate()->first()) {
                throw new DomainException('Resolve the register operation before another sale.');
            }
            if ($context) {
                $this->inventory->reserve($row, $context);
                $this->db->table('payment_core_operations')->where('id', $row['id'])->update([
                    'inventory_snapshot' => $this->encrypt($context->snapshot()), 'inventory_hash' => $context->fingerprint(),
                    'inventory_state' => 'held', 'inventory_payment_state' => null,
                ]);
                $row = $this->load($binding->tenantId, $row['id']);
            }
            $this->event($row['id'], 'reserved', 0);

            return $row;
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
            $canonicalInventory = $row['inventory_payment_state'] ?? null;
            if (in_array($canonicalInventory, ['succeeded', 'failed', 'cancelled'], true) && $canonicalInventory !== $evidence->state) {
                $this->event($id, 'terminal_evidence_conflict', $evidence->version);

                return $row;
            }
            $repair = ($row['inventory_state'] ?? null) === 'review' && $canonicalInventory === $evidence->state;
            if ($evidence->version < $row['version'] || ($evidence->version === $row['version'] && ! $repair)) {
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
            $changes = ['state' => $evidence->state, 'provider_id' => $evidence->providerId, 'version' => $evidence->version];
            if ($row['kind'] === 'payment' && ($row['inventory_hash'] ?? null) !== null) {
                $inventoryState = $this->inventory ? $this->inventory->settle($row, $evidence->state)
                    : (in_array($evidence->state, ['succeeded', 'failed', 'cancelled'], true) ? 'review' : $row['inventory_state']);
                $changes['inventory_state'] = $inventoryState;
                $changes['inventory_payment_state'] = $evidence->state;
                if ($inventoryState === 'review') {
                    $changes['state'] = 'review';
                    $this->event($id, 'inventory_settlement_review', $evidence->version);
                }
            }
            $this->event($id, 'verified_'.$evidence->state, $evidence->version);
            $this->db->table('payment_core_operations')->where('tenant_id', $tenantId)->where('id', $id)->update($changes);

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

        $inventoryHash = $row['inventory_hash'] ?? null;
        if ($inventoryHash !== null) {
            if ($row['kind'] !== 'payment' || ! is_string($row['inventory_snapshot'] ?? null)
                || ! in_array($row['inventory_state'] ?? null, ['held', 'committed', 'released', 'review'], true)
                || ! in_array($row['inventory_payment_state'] ?? null, [null, 'processing', 'succeeded', 'failed', 'cancelled'], true)) {
                throw new DomainException('Stored inventory context requires integrity review.');
            }
            $snapshot = json_decode($this->crypt->decrypt($row['inventory_snapshot'], false), true, 64, JSON_THROW_ON_ERROR);
            $context = PaymentInventoryContext::restore($snapshot);
            if (! hash_equals($inventoryHash, $context->fingerprint()) || $context->quote->binding->snapshot() !== $row['binding']
                || $context->quote->toSaleSnapshot()->snapshot() !== $row['terms']
                || (($row['inventory_state'] === 'committed') && $row['state'] !== 'succeeded')
                || ($row['inventory_state'] === 'committed' && $row['inventory_payment_state'] !== 'succeeded')
                || ($row['inventory_state'] === 'released' && $row['inventory_payment_state'] !== $row['state'])
                || ($row['state'] === 'succeeded' && $row['inventory_state'] !== 'committed')
                || (in_array($row['state'], ['failed', 'cancelled'], true) && $row['inventory_state'] !== 'released')
                || (($row['inventory_state'] === 'released') && ! in_array($row['state'], ['failed', 'cancelled'], true))) {
                throw new DomainException('Stored inventory identity requires integrity review.');
            }
            $row['inventory_snapshot'] = $snapshot;
        } elseif (($row['inventory_snapshot'] ?? null) !== null || ($row['inventory_state'] ?? null) !== null
            || ($row['inventory_payment_state'] ?? null) !== null) {
            throw new DomainException('Partial inventory context requires integrity review.');
        } elseif ($this->db->getSchemaBuilder()->hasColumn('website_inventory_reservations', 'payment_core_operation_id')
            && $this->db->table('website_inventory_reservations')->where('payment_core_operation_id', $row['id'])->exists()) {
            throw new DomainException('Inventory hold has lost its immutable quote context.');
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
