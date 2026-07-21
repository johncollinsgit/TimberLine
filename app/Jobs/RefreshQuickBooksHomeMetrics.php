<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\FieldService\QuickBooksReportingSnapshotService;
use App\Services\Integrations\ConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshQuickBooksHomeMetrics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public int $tenantId,
        public int $connectionId,
        public string $rangeKey,
        public string $startDate,
        public string $endDate,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->tenantId, $this->rangeKey, $this->startDate, $this->endDate]);
    }

    public function handle(ConnectionManager $connections, QuickBooksReportingSnapshotService $snapshots): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $connection = IntegrationConnection::query()->forTenantId($this->tenantId)
            ->whereKey($this->connectionId)->where('provider', 'quickbooks')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)->first();
        if (! $tenant || ! $connection || ! $connections->hasConnector('quickbooks')) {
            return;
        }

        $snapshots->refresh(
            $tenant,
            $connection,
            $connections->connector('quickbooks')->client($connection),
            $this->rangeKey,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            'Cash',
        );
    }
}
