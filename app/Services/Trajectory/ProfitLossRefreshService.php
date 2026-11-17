<?php

namespace App\Services\Trajectory;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\FieldService\QuickBooksReportingSnapshotService;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/** Explicit source report GET and snapshot cache save. No provider financial writes. */
class ProfitLossRefreshService
{
    public static function available(Space $space): bool
    {
        if ($space->kind !== 'business' || ! app(TenantModuleAccessResolver::class)->canAccess($space->tenant_id, 'quickbooks')) {
            return false;
        }

        return IntegrationConnection::query()->forTenantId($space->tenant_id)->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->get()->contains(fn ($connection) => self::usable($connection));
    }

    private static function usable(IntegrationConnection $connection): bool
    {
        return $connection->status === IntegrationConnection::STATUS_CONNECTED && filled($connection->access_token) && ! $connection->isExpired() && ! $connection->needsRefresh();
    }

    public function refresh(User $user, Space $space, string $from, string $through, string $basis, ?int $snapshotId = null): array
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless($space->kind === 'business', 404);
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess($space->tenant_id, 'quickbooks'), 403);
        $snapshot = $snapshotId === null ? null : QuickBooksReportingSnapshot::query()->forTenantId($space->tenant_id)->findOrFail($snapshotId);
        if ($snapshot) {
            $oldBasis = strtolower((string) ($snapshot->metrics['accounting_method'] ?? data_get($snapshot->metrics, 'source_report.ReportBasis', '')));
            abort_unless($snapshot->period_start->toDateString() === $from && $snapshot->period_end->toDateString() === $through && ($oldBasis === '' || $oldBasis === $basis), 422, 'The selected source report must match the requested period and accounting basis.');
            $connection = IntegrationConnection::query()->forTenantId($space->tenant_id)->whereKey($snapshot->integration_connection_id)->where('provider', 'quickbooks')->firstOrFail();
        } else {
            $connections = IntegrationConnection::query()->forTenantId($space->tenant_id)->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->latest('id')->get();
            // A missing report cannot authorize guessing between accounting companies.
            abort_unless($connections->count() === 1, 422, 'Connect one current QuickBooks company before loading a new report.');
            $connection = $connections->first();
        }
        abort_unless(self::usable($connection), 422, 'The original QuickBooks connection needs its normal reconnection before this report can be loaded.');
        $lock = Cache::lock('quickbooks-sync:'.$connection->id, 120);
        abort_unless($lock->get(), 409, 'QuickBooks is busy with another operation. Load this report again shortly.');
        try {
            $tenant = Tenant::findOrFail($space->tenant_id);
            $client = new QuickBooksOnlineClient($connection, (string) config('services.quickbooks.api_base'), (int) config('services.quickbooks.minor_version', 75));
            $snapshotMetrics = $snapshot?->metrics;
            $expectedCurrency = $snapshot ? data_get($snapshotMetrics, 'source_report.Currency') : null;
            $admit = function (array $payload) use ($user, $space, $connection, $snapshot, $snapshotMetrics, $from, $through, $basis, $expectedCurrency): void {
                $header = (array) ($payload['Header'] ?? []);
                $currency = $header['Currency'] ?? null;
                abort_unless(($header['ReportName'] ?? null) === 'ProfitAndLoss' && ($header['StartPeriod'] ?? null) === $from && ($header['EndPeriod'] ?? null) === $through
                    && strtolower((string) ($header['ReportBasis'] ?? '')) === $basis && ($header['SummarizeColumnsBy'] ?? null) === 'Total'
                    && is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency) && ($expectedCurrency === null || $expectedCurrency === $currency), 422, 'QuickBooks returned an unsupported report identity, accounting basis or currency. No report was saved.');
                $columns = (array) data_get($payload, 'Columns.Column', []);
                abort_unless(count($columns) === 2 && ($columns[0]['ColType'] ?? null) === 'Account' && ($columns[1]['ColType'] ?? null) === 'Money', 422, 'QuickBooks did not return one total accounting column. No report was saved.');
                $freshSpace = $space->fresh();
                abort_unless($freshSpace && $freshSpace->tenant_id === $space->tenant_id, 409, 'This finance space changed. Reload it before loading a report.');
                app(FinanceAccess::class)->authorize($user->fresh(), $freshSpace);
                $current = IntegrationConnection::query()->forTenantId($space->tenant_id)->whereKey($connection->id)->where('provider', 'quickbooks')->first();
                abort_unless($current && self::usable($current) && $current->external_account_id === $connection->external_account_id
                    && $current->external_account_secret === $connection->external_account_secret
                    && app(TenantModuleAccessResolver::class)->canAccess($space->tenant_id, 'quickbooks'), 403, 'The QuickBooks connection or financial access changed. No report was saved.');
                if ($snapshot) {
                    $fresh = $snapshot->fresh();
                    abort_unless($fresh && $fresh->tenant_id === $space->tenant_id && $fresh->integration_connection_id === $connection->id && $fresh->metrics === $snapshotMetrics
                        && $fresh->period_start->toDateString() === $from && $fresh->period_end->toDateString() === $through, 409, 'The selected saved statement changed. Reload it before refreshing.');
                }
            };
            $saved = app(QuickBooksReportingSnapshotService::class)->refresh($tenant, $connection, $client,
                'trajectory:'.$connection->id.':'.str_replace('-', '', $from).':'.str_replace('-', '', $through),
                CarbonImmutable::parse($from, $space->timezone), CarbonImmutable::parse($through, $space->timezone), ucfirst($basis), $admit);

            return ['ok' => true, 'snapshot_id' => $saved->id, 'period' => ['start' => $from, 'end' => $through], 'basis' => $basis];
        } catch (RequestException|ConnectionException $exception) {
            app(FinanceAccess::class)->authorize($user->fresh(), $space->fresh());
            abort(502, 'QuickBooks could not supply this report. No report was saved. Try loading it again later.');
        } finally {
            $lock->release();
        }
    }
}
