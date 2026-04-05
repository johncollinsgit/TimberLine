<?php

namespace App\Console\Commands;

use App\Services\Marketing\MessageOrderAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingSyncMessageOrderAttributions extends Command
{
    protected $signature = 'marketing:sync-message-order-attributions
        {--tenant-id= : Restrict to a single tenant}
        {--store= : Restrict to a single store key}
        {--days=14 : Reconcile orders within the last N days}
        {--window-days= : Override click attribution window days}
        {--limit=0 : Limit how many tenant/store contexts to process}';

    protected $description = 'Reconcile message click -> order attributions for tenant/store contexts with tracked messaging activity.';

    public function handle(MessageOrderAttributionService $attributionService): int
    {
        $tenantId = $this->positiveInt($this->option('tenant-id'));
        $storeKey = $this->nullableString($this->option('store'));
        $days = max(1, (int) $this->option('days'));
        $windowDaysOption = $this->option('window-days');
        $windowDays = is_numeric($windowDaysOption) && (int) $windowDaysOption > 0
            ? (int) $windowDaysOption
            : null;
        $limit = max(0, (int) $this->option('limit'));

        $from = CarbonImmutable::now()->subDays($days)->startOfDay();
        $to = CarbonImmutable::now()->endOfDay();

        $contexts = $this->resolveContexts($tenantId, $storeKey, $from);
        if ($limit > 0) {
            $contexts = array_slice($contexts, 0, $limit);
        }

        if ($contexts === []) {
            $this->line('No tenant/store contexts matched the requested scope.');

            return self::SUCCESS;
        }

        $summary = [
            'contexts' => 0,
            'processed' => 0,
            'attributed' => 0,
            'created' => 0,
            'updated' => 0,
            'cleared' => 0,
            'skipped' => 0,
        ];

        foreach ($contexts as $context) {
            $contextTenantId = (int) ($context['tenant_id'] ?? 0);
            $contextStoreKey = $this->nullableString($context['store_key'] ?? null);
            if ($contextTenantId <= 0 || $contextStoreKey === null) {
                continue;
            }

            $result = $attributionService->syncForTenantStore(
                tenantId: $contextTenantId,
                storeKey: $contextStoreKey,
                dateFrom: $from,
                dateTo: $to,
                windowDays: $windowDays
            );

            $summary['contexts']++;
            foreach (['processed', 'attributed', 'created', 'updated', 'cleared', 'skipped'] as $key) {
                $summary[$key] += (int) ($result[$key] ?? 0);
            }

            $this->line(sprintf(
                'tenant=%d store=%s processed=%d attributed=%d created=%d updated=%d cleared=%d skipped=%d',
                $contextTenantId,
                $contextStoreKey,
                (int) ($result['processed'] ?? 0),
                (int) ($result['attributed'] ?? 0),
                (int) ($result['created'] ?? 0),
                (int) ($result['updated'] ?? 0),
                (int) ($result['cleared'] ?? 0),
                (int) ($result['skipped'] ?? 0),
            ));
        }

        $this->info(sprintf(
            'Completed %d contexts. processed=%d attributed=%d created=%d updated=%d cleared=%d skipped=%d',
            $summary['contexts'],
            $summary['processed'],
            $summary['attributed'],
            $summary['created'],
            $summary['updated'],
            $summary['cleared'],
            $summary['skipped'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{tenant_id:int,store_key:string}>
     */
    protected function resolveContexts(?int $tenantId, ?string $storeKey, CarbonImmutable $from): array
    {
        $contexts = [];

        if (Schema::hasTable('marketing_message_engagement_events')) {
            $events = DB::table('marketing_message_engagement_events')
                ->select(['tenant_id', 'store_key'])
                ->where('event_type', 'click')
                ->whereNotNull('tenant_id')
                ->whereNotNull('store_key')
                ->where('store_key', '!=', '')
                ->where(function ($query) use ($from): void {
                    $query->whereNull('occurred_at')
                        ->orWhere('occurred_at', '>=', $from);
                })
                ->when(
                    $tenantId !== null,
                    fn ($query) => $query->where('tenant_id', $tenantId)
                )
                ->when(
                    $storeKey !== null,
                    fn ($query) => $query->where('store_key', $storeKey)
                )
                ->groupBy(['tenant_id', 'store_key'])
                ->orderBy('tenant_id')
                ->orderBy('store_key')
                ->get();

            foreach ($events as $row) {
                $resolvedTenantId = (int) ($row->tenant_id ?? 0);
                $resolvedStoreKey = $this->nullableString($row->store_key);
                if ($resolvedTenantId <= 0 || $resolvedStoreKey === null) {
                    continue;
                }

                $contexts[$resolvedTenantId.'|'.$resolvedStoreKey] = [
                    'tenant_id' => $resolvedTenantId,
                    'store_key' => $resolvedStoreKey,
                ];
            }
        }

        if ($contexts !== []) {
            return array_values($contexts);
        }

        if (! Schema::hasTable('shopify_stores')) {
            return [];
        }

        $stores = DB::table('shopify_stores')
            ->select(['tenant_id', 'store_key'])
            ->whereNotNull('tenant_id')
            ->whereNotNull('store_key')
            ->where('store_key', '!=', '')
            ->when(
                $tenantId !== null,
                fn ($query) => $query->where('tenant_id', $tenantId)
            )
            ->when(
                $storeKey !== null,
                fn ($query) => $query->where('store_key', $storeKey)
            )
            ->orderBy('tenant_id')
            ->orderBy('store_key')
            ->get();

        foreach ($stores as $store) {
            $resolvedTenantId = (int) ($store->tenant_id ?? 0);
            $resolvedStoreKey = $this->nullableString($store->store_key);
            if ($resolvedTenantId <= 0 || $resolvedStoreKey === null) {
                continue;
            }

            $contexts[$resolvedTenantId.'|'.$resolvedStoreKey] = [
                'tenant_id' => $resolvedTenantId,
                'store_key' => $resolvedStoreKey,
            ];
        }

        return array_values($contexts);
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
