<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;
use PDO;
use Throwable;

/** Durable reference journal for disposable local/test databases ONLY.
 * BEGIN IMMEDIATE serializes reservation/claim/refund budgets across processes.
 * This reference implementation is rehearsal-only and never application-bound;
 * the separate MySQL implementation owns the production persistence contract.
 */
final class SqlitePaymentJournal implements PaymentJournal
{
    private bool $ownsTransaction = false;

    public function __construct(private PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new DomainException('This reference journal supports disposable SQLite only.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 10000');
        $db->exec('PRAGMA foreign_keys = ON');
    }

    public function installForTesting(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS payment_core_operations (
            id TEXT PRIMARY KEY, tenant_id INTEGER NOT NULL, register_id TEXT NOT NULL,
            sale_id TEXT NOT NULL, request_id TEXT NOT NULL, kind TEXT NOT NULL,
            original_id TEXT NULL REFERENCES payment_core_operations(id),
            terms_hash TEXT NOT NULL, binding TEXT NOT NULL, terms TEXT NOT NULL,
            amount_minor INTEGER NOT NULL, currency TEXT NOT NULL,
            state TEXT NOT NULL, claimed INTEGER NOT NULL DEFAULT 0,
            provider_id TEXT NULL, version INTEGER NOT NULL DEFAULT 0,
            original_provider_id TEXT NULL, effect_scope TEXT NOT NULL,
            UNIQUE(tenant_id, request_id), CHECK(amount_minor > 0), CHECK(kind IN (\'payment\', \'refund\'))
        )');
        $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS pc_sale_once ON payment_core_operations(tenant_id, sale_id) WHERE kind = 'payment'");
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS pc_provider_effect_once ON payment_core_operations(effect_scope, kind, provider_id) WHERE provider_id IS NOT NULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS payment_core_events (
            id INTEGER PRIMARY KEY, operation_id TEXT NOT NULL REFERENCES payment_core_operations(id),
            event_code TEXT NOT NULL, evidence_version INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS payment_core_bindings (
            tenant_id INTEGER NOT NULL, register_id TEXT NOT NULL, fingerprint TEXT NOT NULL,
            PRIMARY KEY(tenant_id, register_id)
        )');
    }

    public function reservePayment(MerchantBinding $binding, SaleSnapshot $sale, string $requestId): array
    {
        $this->assertTest($binding);
        Contract::id($requestId);

        return $this->locked(function () use ($binding, $sale, $requestId): array {
            $hash = Contract::hash([$binding->snapshot(), $sale->snapshot()]);
            if ($existing = $this->byRequest($binding->tenantId, $requestId)) {
                $this->same($existing, $hash, 'payment');

                return $existing;
            }
            $saved = $this->query('SELECT fingerprint FROM payment_core_bindings WHERE tenant_id = ? AND register_id = ?', [$binding->tenantId, $binding->registerId]);
            if ($saved && ! hash_equals($saved['fingerprint'], $binding->fingerprint())) {
                throw new DomainException('Register merchant binding is immutable. Provision a separately reviewed register identity.');
            }
            $this->execute('INSERT OR IGNORE INTO payment_core_bindings VALUES (?, ?, ?)', [$binding->tenantId, $binding->registerId, $binding->fingerprint()]);
            if ($this->query("SELECT id FROM payment_core_operations WHERE tenant_id = ? AND kind = 'payment' AND sale_id = ?", [$binding->tenantId, $sale->saleId])) {
                throw new DomainException('This sale already has a payment attempt. Recover its original request.');
            }
            if ($this->query("SELECT id FROM payment_core_operations o WHERE tenant_id = ? AND register_id = ? AND state IN ('reserved','processing','unknown','review') AND (state <> 'unknown' OR NOT EXISTS (SELECT 1 FROM payment_core_events p WHERE p.operation_id=o.id AND p.event_code='explicit_review_parked'))", [$binding->tenantId, $binding->registerId])) {
                throw new DomainException('Resolve the register operation before another sale.');
            }

            return $this->insert($binding, $requestId, $sale->saleId, 'payment', $hash, $sale->snapshot(), $sale->amountMinor, $sale->currency);
        });
    }

    public function reserveRefund(int $tenantId, string $paymentId, string $requestId, int $amountMinor, string $reason): array
    {
        Contract::id($requestId);
        Contract::minor($amountMinor);
        if (! in_array($reason, ['requested_by_customer', 'duplicate', 'other'], true)) {
            throw new DomainException('A supported reason is required.');
        }

        return $this->locked(function () use ($tenantId, $paymentId, $requestId, $amountMinor, $reason): array {
            $payment = $this->get($tenantId, $paymentId);
            $binding = MerchantBinding::restore($payment['binding']);
            $this->assertTest($binding);
            if ($payment['kind'] !== 'payment' || $payment['state'] !== 'succeeded' || ! $payment['provider_id']) {
                throw new DomainException('Refunds require the original verified paid payment.');
            }
            $terms = ['payment_id' => $paymentId, 'amount_minor' => $amountMinor, 'reason' => $reason];
            $hash = Contract::hash([$payment['binding'], $terms]);
            if ($existing = $this->byRequest($tenantId, $requestId)) {
                $this->same($existing, $hash, 'refund');

                return $existing;
            }
            $reserved = $this->query("SELECT COALESCE(SUM(amount_minor), 0) AS amount FROM payment_core_operations WHERE tenant_id = ? AND original_id = ? AND state NOT IN ('failed', 'cancelled')", [$tenantId, $paymentId]);
            if ((int) $reserved['amount'] + $amountMinor > $payment['amount_minor']) {
                throw new DomainException('Refund amount exceeds unreserved original-payment value.');
            }

            return $this->insert($binding, $requestId, $payment['sale_id'], 'refund', $hash, $terms,
                $amountMinor, $payment['currency'], $paymentId, $payment['provider_id']);
        });
    }

    public function get(int $tenantId, string $id): array
    {
        $this->assertReadableBoundary();
        $row = $this->query('SELECT * FROM payment_core_operations WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
        if (! $row) {
            throw new DomainException('Payment operation is unavailable.');
        }

        return $this->decode($row);
    }

    public function findByRequest(int $tenantId, string $requestId): ?array
    {
        $this->assertReadableBoundary();
        Contract::id($requestId);

        return $this->byRequest($tenantId, $requestId);
    }

    public function claim(int $tenantId, string $id): bool
    {
        return $this->locked(fn () => $this->execute("UPDATE payment_core_operations SET claimed = 1, state = 'processing' WHERE tenant_id = ? AND id = ? AND claimed = 0 AND state = 'reserved'", [$tenantId, $id])->rowCount() === 1);
    }

    public function unknown(int $tenantId, string $id): array
    {
        return $this->locked(function () use ($tenantId, $id): array {
            $this->execute("UPDATE payment_core_operations SET state = 'unknown' WHERE tenant_id = ? AND id = ? AND state IN ('reserved', 'processing', 'unknown')", [$tenantId, $id]);

            return $this->get($tenantId, $id);
        });
    }

    /** Core-internal trusted normalized evidence; never mount as a client API. */
    public function apply(int $tenantId, string $id, ProviderEvidence $evidence): array
    {
        return $this->locked(function () use ($tenantId, $id, $evidence): array {
            $row = $this->get($tenantId, $id);
            $binding = MerchantBinding::restore($row['binding']);
            $valid = $row['claimed'] === 1 && $evidence->operationId === $row['request_id']
                && hash_equals($binding->fingerprint(), $evidence->bindingFingerprint)
                && $row['amount_minor'] === $evidence->amountMinor && $row['currency'] === $evidence->currency
                && ($row['provider_id'] === null || $row['provider_id'] === $evidence->providerId)
                && $row['original_provider_id'] === $evidence->originalPaymentId;
            if (! $valid) {
                $this->event($row, 'evidence_mismatch', $evidence->version);
                $this->execute("UPDATE payment_core_operations SET state = 'review' WHERE tenant_id = ? AND id = ? AND state NOT IN ('succeeded', 'failed', 'cancelled')", [$tenantId, $id]);

                return $this->get($tenantId, $id);
            }
            if ($evidence->version <= $row['version']) {
                return $row;
            }
            if ($this->query('SELECT id FROM payment_core_operations WHERE effect_scope = ? AND kind = ? AND provider_id = ? AND id <> ?',
                [$row['effect_scope'], $row['kind'], $evidence->providerId, $id])) {
                $this->event($row, 'provider_reference_reused', $evidence->version);
                $this->execute("UPDATE payment_core_operations SET state = 'review' WHERE tenant_id = ? AND id = ? AND state NOT IN ('succeeded', 'failed', 'cancelled')", [$tenantId, $id]);

                return $this->get($tenantId, $id);
            }
            // Settled decisions never regress or release refund value from a late event.
            if (in_array($row['state'], ['succeeded', 'failed', 'cancelled'], true)) {
                if ($row['state'] !== $evidence->state) {
                    $this->event($row, 'terminal_evidence_conflict', $evidence->version);
                }

                return $row;
            }
            $this->event($row, 'verified_'.$evidence->state, $evidence->version);
            $this->execute('UPDATE payment_core_operations SET state = ?, provider_id = ?, version = ? WHERE tenant_id = ? AND id = ?',
                [$evidence->state, $evidence->providerId, $evidence->version, $tenantId, $id]);

            return $this->get($tenantId, $id);
        });
    }

    private function insert(MerchantBinding $binding, string $requestId, string $saleId, string $kind, string $hash, array $terms, int $amount, string $currency, ?string $originalId = null, ?string $originalProviderId = null): array
    {
        $id = bin2hex(random_bytes(16));
        $this->execute("INSERT INTO payment_core_operations (id, tenant_id, register_id, sale_id, request_id, kind, original_id, terms_hash, binding, terms, amount_minor, currency, state, original_provider_id, effect_scope) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reserved', ?, ?)",
            [$id, $binding->tenantId, $binding->registerId, $saleId, $requestId, $kind, $originalId, $hash,
                Contract::json($binding->snapshot()), Contract::json($terms), $amount, $currency, $originalProviderId,
                Contract::hash([$binding->provider, $binding->merchantAccount, $binding->environment])]);

        return $this->get($binding->tenantId, $id);
    }

    private function event(array $row, string $code, int $version): void
    {
        $this->execute('INSERT INTO payment_core_events (operation_id, event_code, evidence_version) VALUES (?, ?, ?)', [$row['id'], $code, $version]);
    }

    public function parkForReview(int $tenantId, string $id): array
    {
        return $this->locked(function () use ($tenantId, $id): array {
            $row = $this->get($tenantId, $id);
            if ($row['kind'] !== 'payment' || $row['binding']['provider'] !== 'square_native'
                || $row['state'] !== 'unknown' || $row['claimed'] !== 1 || $row['provider_id'] !== null) {
                throw new DomainException('Only an unresolved native interaction with no provider payment can be parked.');
            }
            if (! $row['parked_for_review']) {
                $this->event($row, 'explicit_review_parked', $row['version']);
            }

            return $this->get($tenantId, $id);
        });
    }

    private function byRequest(int $tenantId, string $requestId): ?array
    {
        $row = $this->query('SELECT * FROM payment_core_operations WHERE tenant_id = ? AND request_id = ?', [$tenantId, $requestId]);

        return $row ? $this->decode($row) : null;
    }

    private function same(array $row, string $hash, string $kind): void
    {
        if ($row['kind'] !== $kind || ! hash_equals($row['terms_hash'], $hash)) {
            throw new DomainException('Idempotency key already has different immutable terms.');
        }
    }

    private function assertTest(MerchantBinding $binding): void
    {
        if ($binding->environment !== 'test') {
            throw new DomainException('The reference SQLite journal cannot collect or service live payments.');
        }
    }

    private function decode(array $row): array
    {
        $row['parked_for_review'] = $this->query("SELECT id FROM payment_core_events WHERE operation_id=? AND event_code='explicit_review_parked'", [$row['id']]) !== null;
        $row['binding'] = json_decode($row['binding'], true, 512, JSON_THROW_ON_ERROR);
        $row['terms'] = json_decode($row['terms'], true, 512, JSON_THROW_ON_ERROR);
        foreach (['tenant_id', 'amount_minor', 'claimed', 'version'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $row;
    }

    private function locked(callable $work): mixed
    {
        if ($this->db->inTransaction()) {
            throw new DomainException('Payment journal requires its own committed transaction boundary.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        $this->ownsTransaction = true;
        try {
            $result = $work();
            $this->db->exec('COMMIT');

            return $result;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        } finally {
            $this->ownsTransaction = false;
        }
    }

    private function assertReadableBoundary(): void
    {
        if (! $this->ownsTransaction && $this->db->inTransaction()) {
            throw new DomainException('Payment journal requires its own committed transaction boundary.');
        }
    }

    private function query(string $sql, array $params): ?array
    {
        return $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function execute(string $sql, array $params): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement;
    }
}
