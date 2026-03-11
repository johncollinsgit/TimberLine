<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Marketing\MarketingProfileSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingSyncProfiles extends Command
{
    protected $signature = 'marketing:sync-profiles
        {--limit= : Maximum number of orders to process}
        {--since= : Process orders updated on/after this datetime}
        {--order-id= : Process a single order by ID}
        {--shopify-only : Process only Shopify-linked orders}
        {--dry-run : Preview changes without writing}';

    protected $description = 'Backfill/sync marketing profiles from existing operational orders.';

    public function handle(MarketingProfileSyncService $syncService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verbose = $this->getOutput()->isVerbose();
        $limit = $this->integerOption('limit');
        $orderId = $this->integerOption('order-id');
        $since = $this->dateOption('since');
        $shopifyOnly = (bool) $this->option('shopify-only');

        $summary = [
            'processed' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'links_created' => 0,
            'links_reused' => 0,
            'reviews_created' => 0,
            'records_skipped' => 0,
        ];
        /** @var array<string,int> $reasonCounts */
        $reasonCounts = [];

        if ($orderId !== null) {
            $order = Order::query()->find($orderId);
            if (!$order) {
                $this->error("Order {$orderId} not found.");
                return self::FAILURE;
            }

            $result = $syncService->syncOrder($order, ['dry_run' => $dryRun]);
            $this->accumulate($summary, $result);
            $this->accumulateReason($reasonCounts, $result);
            $summary['processed']++;

            if ($verbose) {
                $this->line($this->formatOrderResult($order, $result));
            }
        } else {
            $query = Order::query()
                ->when($shopifyOnly, function ($builder): void {
                    $builder->where(function ($nested): void {
                        $nested->whereNotNull('shopify_order_id')
                            ->orWhere('source', 'like', '%shopify%');
                    });
                })
                ->when($since, fn ($builder) => $builder->where('updated_at', '>=', $since))
                ->orderBy('id');

            foreach ($query->lazyById(200) as $order) {
                if ($limit !== null && $summary['processed'] >= $limit) {
                    break;
                }

                $result = $syncService->syncOrder($order, ['dry_run' => $dryRun]);
                $this->accumulate($summary, $result);
                $this->accumulateReason($reasonCounts, $result);
                $summary['processed']++;

                if ($verbose) {
                    $this->line($this->formatOrderResult($order, $result));
                }
            }
        }

        $this->line($dryRun ? 'Mode: dry-run (no writes performed)' : 'Mode: live-sync');
        $this->line('scope=' . ($shopifyOnly ? 'shopify_only' : 'all_orders'));
        $this->line('processed=' . $summary['processed']);
        $this->line('profiles_created=' . $summary['profiles_created']);
        $this->line('profiles_updated=' . $summary['profiles_updated']);
        $this->line('links_created=' . $summary['links_created']);
        $this->line('links_reused=' . $summary['links_reused']);
        $this->line('reviews_created=' . $summary['reviews_created']);
        $this->line('records_skipped=' . $summary['records_skipped']);
        if ($reasonCounts !== []) {
            foreach ($reasonCounts as $reason => $count) {
                $this->line("reason_{$reason}={$count}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,mixed> $result
     */
    protected function accumulate(array &$summary, array $result): void
    {
        foreach (['profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped'] as $key) {
            $summary[$key] += (int) ($result[$key] ?? 0);
        }
    }

    /**
     * @param array<string,int> $reasonCounts
     * @param array<string,mixed> $result
     */
    protected function accumulateReason(array &$reasonCounts, array $result): void
    {
        $reason = trim((string) ($result['reason'] ?? 'unknown'));
        if ($reason === '') {
            $reason = 'unknown';
        }

        $reasonCounts[$reason] = (int) ($reasonCounts[$reason] ?? 0) + 1;
    }

    protected function integerOption(string $key): ?int
    {
        $value = $this->option($key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    protected function dateOption(string $key): ?CarbonImmutable
    {
        $value = trim((string) $this->option($key));
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable $e) {
            $this->warn("Invalid --{$key} value '{$value}', ignoring.");
            return null;
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    protected function formatOrderResult(Order $order, array $result): string
    {
        return sprintf(
            '#%d %s reason=%s profile=%s',
            (int) $order->id,
            (string) ($result['status'] ?? 'unknown'),
            (string) ($result['reason'] ?? 'n/a'),
            $result['profile_id'] !== null ? (string) $result['profile_id'] : '-'
        );
    }
}
