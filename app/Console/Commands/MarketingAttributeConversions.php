<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\SquareOrder;
use App\Services\Marketing\MarketingConversionAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingAttributeConversions extends Command
{
    protected $signature = 'marketing:attribute-conversions
        {--since= : Attribute conversions on/after this datetime}
        {--limit=500 : Maximum source records to process}
        {--order-id= : Attribute only one operational order}
        {--square-order-id= : Attribute only one Square order id}
        {--dry-run : Preview conversion attribution without writing}';

    protected $description = 'Attribute campaign conversions from linked operational and Square order records.';

    public function handle(MarketingConversionAttributionService $service): int
    {
        $since = $this->parseSince((string) $this->option('since'));
        $limit = max(1, (int) ($this->option('limit') ?: 500));
        $dryRun = (bool) $this->option('dry-run');
        $verbose = $this->getOutput()->isVerbose();
        $orderId = $this->integerOption('order-id');
        $squareOrderId = trim((string) $this->option('square-order-id'));

        $summary = [
            'sources_processed' => 0,
            'profiles_resolved' => 0,
            'conversions_created' => 0,
            'conversions_updated' => 0,
            'conversions_skipped' => 0,
        ];

        if ($orderId) {
            $order = Order::query()->find($orderId);
            if (! $order) {
                $this->error("Order {$orderId} not found.");
                return self::FAILURE;
            }

            $result = $service->attributeForOrder($order, ['dry_run' => $dryRun]);
            $this->mergeSummary($summary, $result);
            if ($verbose) {
                $this->line($this->formatResult('order:' . $order->id, $result));
            }

            return $this->renderSummary($summary, $dryRun);
        }

        if ($squareOrderId !== '') {
            $squareOrder = SquareOrder::query()->where('square_order_id', $squareOrderId)->first();
            if (! $squareOrder) {
                $this->error("Square order {$squareOrderId} not found.");
                return self::FAILURE;
            }

            $result = $service->attributeForSquareOrder($squareOrder, ['dry_run' => $dryRun]);
            $this->mergeSummary($summary, $result);
            if ($verbose) {
                $this->line($this->formatResult('square_order:' . $squareOrderId, $result));
            }

            return $this->renderSummary($summary, $dryRun);
        }

        $remaining = $limit;

        foreach ($this->operationalOrders($since, $remaining)->cursor() as $order) {
            if ($remaining <= 0) {
                break;
            }

            $result = $service->attributeForOrder($order, ['dry_run' => $dryRun]);
            $this->mergeSummary($summary, $result);
            if ($verbose) {
                $this->line($this->formatResult('order:' . $order->id, $result));
            }
            $remaining--;
        }

        foreach ($this->squareOrders($since, $remaining)->cursor() as $squareOrder) {
            if ($remaining <= 0) {
                break;
            }

            $result = $service->attributeForSquareOrder($squareOrder, ['dry_run' => $dryRun]);
            $this->mergeSummary($summary, $result);
            if ($verbose) {
                $this->line($this->formatResult('square_order:' . $squareOrder->square_order_id, $result));
            }
            $remaining--;
        }

        return $this->renderSummary($summary, $dryRun);
    }

    protected function operationalOrders(?CarbonImmutable $since, int $remaining)
    {
        return Order::query()
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->limit(max(1, $remaining));
    }

    protected function squareOrders(?CarbonImmutable $since, int $remaining)
    {
        return SquareOrder::query()
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->limit(max(1, $remaining));
    }

    protected function parseSince(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            $this->warn("Invalid --since value '{$value}', ignoring.");
            return null;
        }
    }

    protected function integerOption(string $key): ?int
    {
        $value = $this->option($key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,int> $result
     */
    protected function mergeSummary(array &$summary, array $result): void
    {
        foreach (array_keys($summary) as $key) {
            $summary[$key] += (int) ($result[$key] ?? 0);
        }
    }

    /**
     * @param array<string,int> $result
     */
    protected function formatResult(string $source, array $result): string
    {
        return sprintf(
            '%s profiles=%d created=%d updated=%d skipped=%d',
            $source,
            (int) ($result['profiles_resolved'] ?? 0),
            (int) ($result['conversions_created'] ?? 0),
            (int) ($result['conversions_updated'] ?? 0),
            (int) ($result['conversions_skipped'] ?? 0),
        );
    }

    /**
     * @param array<string,int> $summary
     */
    protected function renderSummary(array $summary, bool $dryRun): int
    {
        $this->line($dryRun ? 'mode=dry-run' : 'mode=live');
        foreach ($summary as $key => $value) {
            $this->line($key . '=' . $value);
        }

        return self::SUCCESS;
    }
}
