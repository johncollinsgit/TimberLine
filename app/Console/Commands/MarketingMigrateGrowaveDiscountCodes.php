<?php

namespace App\Console\Commands;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MarketingMigrateGrowaveDiscountCodes extends Command
{
    protected $signature = 'marketing:migrate-growave-discount-codes
        {--store=retail : Shopify store key that owns the legacy Growave codes}
        {--tenant-id=1 : Tenant that owns the customer profiles and Candle Cash wallet}
        {--apply : Write rollover credit into Candle Cash}
        {--deactivate : Deactivate successfully migrated and already-used Growave codes in Shopify}';

    protected $description = 'Roll active legacy Growave Candle Cash codes into the canonical wallet, then optionally retire the Shopify codes.';

    private const ROLLOVER_SOURCE = 'growave_discount_code_rollover';

    public function handle(): int
    {
        $storeKey = strtolower(trim((string) $this->option('store')));
        $tenantId = (int) $this->option('tenant-id');
        $apply = (bool) $this->option('apply');
        $deactivate = (bool) $this->option('deactivate');

        if ($storeKey === '' || $tenantId <= 0) {
            $this->error('A non-empty --store and positive --tenant-id are required.');

            return self::FAILURE;
        }

        if ($deactivate && ! $apply) {
            $this->error('--deactivate requires --apply so an unused code is never retired without a wallet rollover.');

            return self::FAILURE;
        }

        $store = ShopifyStore::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_key', $storeKey)
            ->first();

        if (! $store) {
            $this->error("No Shopify store '{$storeKey}' belongs to tenant {$tenantId}.");

            return self::FAILURE;
        }

        $client = new ShopifyGraphqlClient($store->shop_domain, $store->access_token);
        $summary = [
            'active_legacy_codes' => 0,
            'used_codes' => 0,
            'unused_codes' => 0,
            'unused_value' => 0.0,
            'would_rollover' => 0,
            'rolled_over' => 0,
            'already_rolled_over' => 0,
            'unresolved_unused_codes' => 0,
            'would_deactivate' => 0,
            'deactivated' => 0,
            'deactivation_errors' => 0,
        ];

        try {
            $codes = $this->activeGrowaveCodes($client);
        } catch (\Throwable $exception) {
            $this->error('Unable to read active Shopify discounts: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($codes as $code) {
            $summary['active_legacy_codes']++;
            $used = $code['usage_count'] > 0;

            if ($used) {
                $summary['used_codes']++;
                $eligibleForDeactivation = true;
            } else {
                $summary['unused_codes']++;
                $summary['unused_value'] += $code['amount'];
                $profileId = $this->resolveProfileId($tenantId, $storeKey, $code['customer_gid']);

                if ($profileId === null) {
                    $summary['unresolved_unused_codes']++;
                    $eligibleForDeactivation = false;
                    $this->warn('Unresolved unused code '.$this->displayCode($code).' was left active.');
                } elseif (! $apply) {
                    $summary['would_rollover']++;
                    $eligibleForDeactivation = true;
                } else {
                    $result = $this->rolloverCode($tenantId, $profileId, $code);
                    $summary[$result]++;
                    $eligibleForDeactivation = true;
                }
            }

            if (! $deactivate || ! $eligibleForDeactivation) {
                continue;
            }

            $summary['would_deactivate']++;

            try {
                $this->deactivateCode($client, $code['node_id']);
                $summary['deactivated']++;
            } catch (\Throwable $exception) {
                $summary['deactivation_errors']++;
                $this->warn('Could not deactivate '.$this->displayCode($code).': '.$exception->getMessage());
            }
        }

        $this->line('mode='.($apply ? 'apply' : 'preview'));
        foreach (array_keys($summary) as $key) {
            $value = $summary[$key];
            $this->line($key.'='.(is_float($value) ? number_format($value, 2, '.', '') : $value));
        }

        return $summary['unresolved_unused_codes'] > 0 || $summary['deactivation_errors'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array<int,array{node_id:string,code:string,amount:float,usage_count:int,customer_gid:?string}>
     */
    private function activeGrowaveCodes(ShopifyGraphqlClient $client): array
    {
        $query = <<<'GRAPHQL'
query GrowaveCandleCashCodes {
  discountNodes(first: 250, query: "status:active AND title:Candle Cash") {
    nodes {
      id
      discount {
        __typename
        ... on DiscountCodeBasic {
          title
          asyncUsageCount
          codes(first: 1) {
            nodes { code }
          }
          customerSelection {
            __typename
            ... on DiscountCustomers {
              customers { id }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

        $nodes = (array) data_get($client->query($query), 'discountNodes.nodes', []);
        $codes = [];

        foreach ($nodes as $node) {
            $discount = (array) data_get($node, 'discount', []);
            $title = trim((string) data_get($discount, 'title', ''));

            if ((string) data_get($discount, '__typename') !== 'DiscountCodeBasic'
                || preg_match('/^\$(\d+(?:\.\d{1,2})?)\s+Candle Cash\b/i', $title, $matches) !== 1) {
                continue;
            }

            $customerIds = array_values(array_filter(array_map(
                fn (mixed $customer): ?string => $this->nullableString(data_get($customer, 'id')),
                (array) data_get($discount, 'customerSelection.customers', [])
            )));

            $nodeId = $this->nullableString(data_get($node, 'id'));
            $code = $this->nullableString(data_get($discount, 'codes.nodes.0.code'));
            if ($nodeId === null || $code === null) {
                continue;
            }

            $codes[] = [
                'node_id' => $nodeId,
                'code' => $code,
                'amount' => CandleCashMeasurement::normalizeStoredAmount((float) $matches[1]),
                'usage_count' => max(0, (int) data_get($discount, 'asyncUsageCount', 0)),
                'customer_gid' => count($customerIds) === 1 ? $customerIds[0] : null,
            ];
        }

        return $codes;
    }

    private function resolveProfileId(int $tenantId, string $storeKey, ?string $customerGid): ?int
    {
        if ($customerGid === null) {
            return null;
        }

        $profileId = CustomerExternalProfile::query()
            ->forTenantId($tenantId)
            ->where('store_key', $storeKey)
            ->where('provider', 'shopify')
            ->where('external_customer_gid', $customerGid)
            ->value('marketing_profile_id');

        if (! is_numeric($profileId) || (int) $profileId <= 0) {
            return null;
        }

        return MarketingProfile::query()
            ->forTenantId($tenantId)
            ->whereKey((int) $profileId)
            ->exists()
            ? (int) $profileId
            : null;
    }

    /**
     * @param array{node_id:string,code:string,amount:float,usage_count:int,customer_gid:?string} $code
     */
    private function rolloverCode(int $tenantId, int $profileId, array $code): string
    {
        return DB::transaction(function () use ($tenantId, $profileId, $code): string {
            $profile = MarketingProfile::query()
                ->forTenantId($tenantId)
                ->whereKey($profileId)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                throw new RuntimeException("Marketing profile {$profileId} is outside tenant {$tenantId}.");
            }

            $existing = CandleCashTransaction::query()
                ->where('marketing_profile_id', $profileId)
                ->where('source', self::ROLLOVER_SOURCE)
                ->where('source_id', $code['node_id'])
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                return 'already_rolled_over';
            }

            $balance = CandleCashBalance::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['marketing_profile_id' => $profileId],
                    ['balance' => 0]
                );

            $balance->forceFill([
                'balance' => CandleCashMeasurement::normalizeStoredAmount($balance->balance + $code['amount']),
            ])->save();

            CandleCashTransaction::query()->create([
                'marketing_profile_id' => $profileId,
                'type' => 'legacy_discount_rollover',
                'candle_cash_delta' => $code['amount'],
                'source' => self::ROLLOVER_SOURCE,
                'source_id' => $code['node_id'],
                'description' => 'Rolled over unused legacy Growave Candle Cash code '.$code['code'].' into the Everbranch wallet.',
                'notification_status' => 'notified_in_wallet',
            ]);

            return 'rolled_over';
        });
    }

    private function deactivateCode(ShopifyGraphqlClient $client, string $nodeId): void
    {
        $mutation = <<<'GRAPHQL'
mutation DeactivateLegacyGrowaveCode($id: ID!) {
  discountCodeDeactivate(id: $id) {
    codeDiscountNode { id }
    userErrors { field message code }
  }
}
GRAPHQL;

        $payload = (array) data_get($client->query($mutation, ['id' => $nodeId]), 'discountCodeDeactivate', []);
        $errors = (array) data_get($payload, 'userErrors', []);
        if ($errors !== []) {
            throw new RuntimeException((string) collect($errors)
                ->map(fn (array $error): string => trim((string) ($error['message'] ?? 'Unknown Shopify error.')))
                ->filter()
                ->implode(' '));
        }

        if (! data_get($payload, 'codeDiscountNode.id')) {
            throw new RuntimeException('Shopify did not confirm deactivation.');
        }
    }

    /**
     * @param array{code:string} $code
     */
    private function displayCode(array $code): string
    {
        return substr($code['code'], 0, 4).'…';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
