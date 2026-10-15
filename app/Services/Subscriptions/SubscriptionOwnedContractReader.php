<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;

/** Builds an all-or-nothing bounded observation in memory, outside DB locks. */
class SubscriptionOwnedContractReader
{
    private float $started;

    private int $pages = 0;

    private int $rows = 0;

    private array $bounds;

    private string $observedAt;

    public function __construct(private SubscriptionShopifyReadTransport $transport) {}

    public function read(SubscriptionInstallation $installation, SubscriptionCredentialRevision $revision, array $bounds, ?array $onlyIndex = null): array
    {
        $this->started = microtime(true);
        $this->pages = 0;
        $this->rows = 0;
        $this->bounds = $bounds;
        $this->observedAt = now()->toISOString();
        $index = $onlyIndex ?? $this->index($installation, $revision);
        $resources = [];
        foreach ($index as $id => $expectedRevision) {
            $core = $this->core($installation, $revision, $id, $expectedRevision);
            $customer = $core['customer']['id'] ?? null;
            $this->validateCore($core);
            $lines = $this->children($installation, $revision, $id, $expectedRevision, 'SubscriptionContractLines', 'lines');
            $discounts = $this->children($installation, $revision, $id, $expectedRevision, 'SubscriptionContractDiscounts', 'discounts');
            // Attempts/cycles can change independently of contract.revisionId.
            // Two complete stable passes are a bounded observation, not a
            // provider-wide transactional snapshot or future execution proof.
            $attempts = $this->children($installation, $revision, $id, $expectedRevision, 'SubscriptionContractAttempts', 'billingAttempts');
            $cycles = $this->cycles($installation, $revision, $id, $expectedRevision);
            if ($this->canonical($attempts) !== $this->canonical($this->children($installation, $revision, $id, $expectedRevision, 'SubscriptionContractAttempts', 'billingAttempts'))
                || $this->canonical($cycles) !== $this->canonical($this->cycles($installation, $revision, $id, $expectedRevision))) {
                $this->fail('changing_attempt_or_cycle_observation');
            }
            if ($this->canonical($core) !== $this->canonical($this->core($installation, $revision, $id, $expectedRevision))) {
                $this->fail('changing_contract_observation');
            }
            $method = $core['customerPaymentMethod'];
            $core['payment_method_observation'] = $method === null ? 'unavailable_or_redacted' : ($method['revokedAt'] !== null ? 'revoked' : ($method['instrument'] === null ? 'instrument_unavailable' : (($method['instrument']['__typename'] ?? null) === 'CustomerCreditCard' ? 'observed' : 'unsupported_instrument')));
            $contractIndex = count($resources);
            $resources[] = $this->resource('contract', $id, $id, $customer, $expectedRevision, strtolower($core['status']), $core, $core['updatedAt']);
            foreach ($lines as $line) {
                $this->gid($line['id'] ?? null, 'SubscriptionLine');
                if (! is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                    $this->fail('invalid_contract_line');
                }
                $this->money($line['currentPrice'] ?? null, $core['currencyCode']);
                $this->money($line['lineDiscountedPrice'] ?? null, $core['currencyCode']);
                if (! is_array($line['discountAllocations'] ?? null)) {
                    $this->fail('incomplete_contract_line');
                }
                foreach ($line['discountAllocations'] as $allocation) {
                    $this->money($allocation['amount'] ?? null, $core['currencyCode']);
                }
                $resources[] = $this->resource('contract_line', $line['id'], $id, $customer, $expectedRevision, 'observed', $line);
            }
            // Discount snapshots remain attached to their contract. Line
            // allocations preserve applicability without an unbounded nested read.
            foreach ($discounts as $discount) {
                $this->gid($discount['id'] ?? null, 'SubscriptionManualDiscount');
                $value = $discount['value'] ?? [];
                if (($value['__typename'] ?? null) === 'SubscriptionDiscountFixedAmountValue') {
                    $this->money($value['amount'] ?? null, $core['currencyCode']);
                    if (! is_bool($value['appliesOnEachItem'] ?? null)) {
                        $this->fail('incomplete_discount');
                    }
                } elseif (($value['__typename'] ?? null) === 'SubscriptionDiscountPercentageValue') {
                    if (! is_int($value['percentage'] ?? null) || $value['percentage'] < 0 || $value['percentage'] > 100) {
                        $this->fail('invalid_discount_percentage');
                    }
                } else {
                    $this->fail('unsupported_discount_value');
                }
            }
            $resources[$contractIndex]['snapshot']['discounts'] = array_values($discounts);
            SubscriptionEvidence::assertContent($resources[$contractIndex]['snapshot']);
            if ($method !== null) {
                $this->gid($method['id'] ?? null, 'CustomerPaymentMethod');
                if ($customer === null || ($method['customer']['id'] ?? null) !== $customer) {
                    $this->fail('payment_customer_mismatch');
                }
                $resources[] = $this->resource('payment_method', $method['id'], null, $customer, null, $core['payment_method_observation'], $method);
            }
            foreach ($attempts as $attempt) {
                $this->gid($attempt['id'] ?? null, 'SubscriptionBillingAttempt');
                $this->owned($attempt['subscriptionContract'] ?? null, $installation, $id);
                $this->instant($attempt['createdAt'] ?? null);
                $state = $attempt['state']['__typename'] ?? null;
                $status = match ($state) {
                    'SubscriptionBillingAttemptPendingState' => 'pending',
                    'SubscriptionBillingAttemptActionRequiredState' => 'action_required',
                    'SubscriptionBillingAttemptFailedState' => 'failed',
                    'SubscriptionBillingAttemptSuccessState' => 'succeeded',
                    default => 'unsupported',
                };
                if ($status === 'unsupported') {
                    $this->fail('unsupported_attempt_state');
                }
                $this->validateAttempt($attempt, $status);
                $resources[] = $this->resource('billing_attempt', $attempt['id'], $id, $customer, null, $status, $attempt);
                if ($status === 'succeeded') {
                    if (! array_key_exists('order', $attempt['state'])) {
                        $this->fail('incomplete_order_observation');
                    }
                    $order = $attempt['state']['order'];
                    if ($order !== null) {
                        $this->gid($order['id'] ?? null, 'Order');
                        if (($order['customer']['id'] ?? null) !== null && $order['customer']['id'] !== $customer) {
                            $this->fail('order_customer_mismatch');
                        }
                        $resources[] = $this->resource('order_link', $id.':order:'.substr($order['id'], strrpos($order['id'], '/') + 1), $id, $customer, null, 'provider_confirmed', ['order' => $order, 'billing_attempt_gid' => $attempt['id']]);
                    }
                }
            }
            foreach ($cycles as $cycle) {
                $resources[] = $this->resource('cycle', $id.':cycle:'.$cycle['cycleIndex'], $id, $customer, $expectedRevision, strtolower($cycle['status']), $cycle);
            }
        }
        if ($onlyIndex === null && $index !== $this->index($installation, $revision)) {
            $this->fail('changing_contract_index');
        }
        $this->budget();

        return ['resources' => $this->deduplicate($resources), 'owned_contract_count' => count($index), 'pages' => $this->pages,
            'cycle_window' => ['start_index' => $bounds['cycle_start'], 'end_index' => $bounds['cycle_end']],
            'consistency' => 'stable_consecutive_reads', 'observed_at' => now()->toISOString()];
    }

    private function index(SubscriptionInstallation $i, SubscriptionCredentialRevision $r): array
    {
        $index = [];
        $nodes = $this->connection(fn ($after) => $this->query($i, $r, 'SubscriptionOwnedContracts', ['after' => $after])['subscriptionContracts'] ?? null);
        foreach ($nodes as $node) {
            $this->owned($node, $i);
            $id = $node['id'];
            if (isset($index[$id])) {
                $this->fail('duplicate_contract');
            }
            $index[$id] = SubscriptionUnsignedRevision::parse($node['revisionId'] ?? null);
            if (count($index) > $this->bounds['max_contracts']) {
                $this->fail('contract_bound_exceeded');
            }
        }
        ksort($index, SORT_STRING);

        return $index;
    }

    private function core(SubscriptionInstallation $i, SubscriptionCredentialRevision $r, string $id, string $revision): array
    {
        $core = $this->query($i, $r, 'SubscriptionContractCore', ['id' => $id])['subscriptionContract'] ?? null;
        $this->owned($core, $i, $id, $revision);

        return $core;
    }

    private function children(SubscriptionInstallation $i, SubscriptionCredentialRevision $r, string $id, string $revision, string $operation, string $field): array
    {
        return $this->connection(function ($after) use ($i, $r, $id, $revision, $operation, $field) {
            $parent = $this->query($i, $r, $operation, ['id' => $id, 'after' => $after])['subscriptionContract'] ?? null;
            $this->owned($parent, $i, $id, $revision);

            return $parent[$field] ?? null;
        });
    }

    private function cycles(SubscriptionInstallation $i, SubscriptionCredentialRevision $r, string $id, string $revision): array
    {
        $cycles = $this->connection(fn ($after) => $this->query($i, $r, 'SubscriptionContractCycles', ['id' => $id, 'after' => $after, 'start' => $this->bounds['cycle_start'], 'end' => $this->bounds['cycle_end']])['subscriptionBillingCycles'] ?? null, 'cycleIndex');
        foreach ($cycles as $cycle) {
            $this->owned($cycle['sourceContract'] ?? null, $i, $id, $revision);
            if (! is_int($cycle['cycleIndex']) || $cycle['cycleIndex'] < $this->bounds['cycle_start'] || $cycle['cycleIndex'] > $this->bounds['cycle_end']) {
                $this->fail('invalid_cycle_identity');
            }
            foreach (['billingAttemptExpectedDate', 'cycleStartAt', 'cycleEndAt'] as $key) {
                $this->instant($cycle[$key] ?? null);
            }
            if (! is_string($cycle['status'] ?? null) || ! is_bool($cycle['edited'] ?? null) || ! is_bool($cycle['skipped'] ?? null)) {
                $this->fail('incomplete_cycle');
            }
        }

        return $cycles;
    }

    private function connection(callable $fetch, string $identity = 'id'): array
    {
        $nodes = [];
        $after = null;
        $cursors = [];
        do {
            $connection = $fetch($after);
            if (! is_array($connection) || ! is_array($connection['nodes'] ?? null) || ! array_is_list($connection['nodes']) || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)
                || ! array_key_exists('endCursor', $connection['pageInfo'])) {
                $this->fail('incomplete_connection');
            }
            foreach ($connection['nodes'] as $node) {
                if (! is_array($node) || ! isset($node[$identity]) || ! is_scalar($node[$identity])) {
                    $this->fail('incomplete_resource');
                }
                $key = (string) $node[$identity];
                if (isset($nodes[$key])) {
                    $this->fail('duplicate_connection_resource');
                }
                $nodes[$key] = $node;
                $this->rows++;
                $this->budget();
            }
            if (! $connection['pageInfo']['hasNextPage']) {
                break;
            }
            $after = $connection['pageInfo']['endCursor'];
            if (! is_string($after) || $after === '' || isset($cursors[$after]) || ! $connection['nodes']) {
                $this->fail('invalid_connection_cursor');
            }
            $cursors[$after] = true;
        } while (true);
        ksort($nodes, SORT_STRING);

        return $nodes;
    }

    private function query(SubscriptionInstallation $i, SubscriptionCredentialRevision $r, string $operation, array $variables): array
    {
        $this->pages++;
        $this->budget();
        $result = $this->transport->query($i, $r, $operation, $variables);
        $this->budget();

        return $result;
    }

    private function budget(): void
    {
        if ($this->pages > $this->bounds['max_pages'] || $this->rows > $this->bounds['max_rows'] || microtime(true) - $this->started > $this->bounds['max_seconds']) {
            $this->fail('read_budget_exceeded');
        }
    }

    private function owned(mixed $node, SubscriptionInstallation $i, ?string $id = null, ?string $revision = null): void
    {
        if (! is_array($node)) {
            $this->fail('contract_unavailable');
        }
        $this->gid($node['id'] ?? null, 'SubscriptionContract');
        if (($node['app']['id'] ?? null) !== $i->app_gid || ($id !== null && $node['id'] !== $id)) {
            $this->fail('contract_ownership_mismatch');
        }
        if ($revision !== null && SubscriptionUnsignedRevision::parse($node['revisionId'] ?? null) !== $revision) {
            $this->fail('mixed_contract_revision');
        }
    }

    private function validateCore(array $core): void
    {
        foreach (['status', 'currencyCode', 'createdAt', 'updatedAt', 'nextBillingDate', 'customer', 'billingPolicy', 'deliveryPolicy', 'deliveryMethod', 'deliveryPrice', 'customerPaymentMethod'] as $field) {
            if (! array_key_exists($field, $core)) {
                $this->fail('incomplete_contract');
            }
        }
        if (! in_array($core['status'], ['ACTIVE', 'PAUSED', 'FAILED', 'CANCELLED', 'EXPIRED'], true) || ! preg_match('/^[A-Z]{3}$/D', (string) $core['currencyCode'])) {
            $this->fail('unsupported_contract_status');
        }
        foreach (['createdAt', 'updatedAt'] as $field) {
            $this->instant($core[$field]);
        }
        if ($core['nextBillingDate'] !== null) {
            $this->instant($core['nextBillingDate']);
        }
        if ($core['customer'] !== null) {
            $this->gid($core['customer']['id'] ?? null, 'Customer');
        }
        foreach (['billingPolicy', 'deliveryPolicy'] as $field) {
            if (! is_string($core[$field]['interval'] ?? null) || ! is_int($core[$field]['intervalCount'] ?? null) || $core[$field]['intervalCount'] < 1 || ! is_array($core[$field]['anchors'] ?? null)) {
                $this->fail('incomplete_contract_policy');
            }
        }
        $this->money($core['deliveryPrice'], $core['currencyCode']);
        $method = $core['customerPaymentMethod'];
        if ($method !== null && (! is_array($method) || ! array_key_exists('revokedAt', $method) || ! array_key_exists('instrument', $method))) {
            $this->fail('incomplete_payment_method');
        }
        if ($method !== null && $method['revokedAt'] !== null) {
            $this->instant($method['revokedAt']);
        }
        if ($method !== null && $method['instrument'] !== null && ! is_string($method['instrument']['__typename'] ?? null)) {
            $this->fail('incomplete_payment_instrument');
        }
        if (($method['instrument']['__typename'] ?? null) === 'CustomerCreditCard') {
            $card = $method['instrument'];
            if (! is_string($card['brand'] ?? null) || ! preg_match('/^[0-9]{4}$/D', (string) ($card['lastDigits'] ?? '')) || ! is_int($card['expiryMonth'] ?? null) || $card['expiryMonth'] < 1 || $card['expiryMonth'] > 12 || ! is_int($card['expiryYear'] ?? null)) {
                $this->fail('invalid_masked_payment_method');
            }
        }
    }

    private function resource(string $type, string $id, ?string $parent, ?string $customer, ?string $revision, string $status, array $snapshot, ?string $updated = null): array
    {
        SubscriptionEvidence::assertContent($snapshot);

        return ['resource_type' => $type, 'provider_gid' => $id, 'parent_contract_gid' => $parent, 'customer_gid' => $customer,
            'provider_revision' => $revision, 'status' => $status, 'snapshot' => $snapshot, 'provider_updated_at' => $updated, 'observed_at' => $this->observedAt];
    }

    private function deduplicate(array $resources): array
    {
        $unique = [];
        foreach ($resources as $resource) {
            $key = $resource['resource_type'].':'.$resource['provider_gid'];
            if (isset($unique[$key]) && $this->canonical($unique[$key]) !== $this->canonical($resource)) {
                $this->fail('conflicting_resource_identity');
            }
            $unique[$key] = $resource;
        }

        return array_values($unique);
    }

    private function money(mixed $money, ?string $currency = null): void
    {
        if (! is_array($money) || ! is_string($money['amount'] ?? null) || ! preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $money['amount']) || ! preg_match('/^[A-Z]{3}$/D', (string) ($money['currencyCode'] ?? '')) || ($currency !== null && $money['currencyCode'] !== $currency)) {
            $this->fail('invalid_source_money');
        }
    }

    private function validateAttempt(array $attempt, string $status): void
    {
        foreach (['completedAt', 'originTime'] as $field) {
            if (! array_key_exists($field, $attempt)) {
                $this->fail('incomplete_attempt');
            }
            if ($attempt[$field] !== null) {
                $this->instant($attempt[$field]);
            }
        }
        if ($status === 'action_required') {
            $action = $attempt['state']['action'] ?? [];
            if (($action['__typename'] ?? null) !== 'SubscriptionBillingAttemptPaymentChallenge'
                || ! is_string($action['status'] ?? null) || ! preg_match('/^[A-Z_]+$/D', $action['status'])
                || ! is_string($action['nextActionUrl'] ?? null) || ! filter_var($action['nextActionUrl'], FILTER_VALIDATE_URL) || parse_url($action['nextActionUrl'], PHP_URL_SCHEME) !== 'https') {
                $this->fail('incomplete_attempt_action');
            }
        }
        if ($status === 'failed') {
            $error = $attempt['state']['error'] ?? [];
            $field = match ($error['__typename'] ?? null) {
                'SubscriptionBillingAttemptGeneralError' => 'generalCode',
                'SubscriptionBillingAttemptInventoryError' => 'inventoryCode',
                'SubscriptionBillingAttemptPaymentError' => 'paymentCode',
                'SubscriptionBillingAttemptUnexpectedError' => 'message',
                default => null,
            };
            if ($field === null || ! is_string($error[$field] ?? null) || $error[$field] === '' || strlen($error[$field]) > 8192) {
                $this->fail('incomplete_attempt_error');
            }
        }
    }

    private function instant(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $this->fail('invalid_provider_instant');
        }
        try {
            new \DateTimeImmutable($value);
        } catch (\Throwable) {
            $this->fail('invalid_provider_instant');
        }
    }

    private function gid(mixed $value, string $type): void
    {
        $suffix = in_array($type, ['SubscriptionLine', 'SubscriptionManualDiscount', 'CustomerPaymentMethod'], true) ? '[a-zA-Z0-9_-]{1,128}' : '[1-9][0-9]*';
        if (! is_string($value) || strlen($value) > 190 || ! preg_match('/^gid:\/\/shopify\/'.$type.'\/'.$suffix.'$/D', $value)) {
            $this->fail('invalid_resource_identity');
        }
    }

    private function canonical(array $value): string
    {
        return SubscriptionEvidence::canonical($value);
    }

    private function fail(string $code): never
    {
        throw new SubscriptionProviderReadException($code);
    }
}
