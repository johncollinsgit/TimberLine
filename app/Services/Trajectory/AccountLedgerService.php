<?php

namespace App\Services\Trajectory;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Integrations\QuickBooks\QuickBooksConnector;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/** Financial report GET only; ordinary OAuth renewal may rotate local credentials. */
class AccountLedgerService
{
    public const COLUMNS = 'tx_date,txn_type,doc_num,name,memo,split_acc,debt_amt,credit_amt';

    private const MAX_ROWS = 2000;

    public static function ready(QuickBooksReportingSnapshot $snapshot): bool
    {
        $connection = IntegrationConnection::query()->forTenantId($snapshot->tenant_id)->whereKey($snapshot->integration_connection_id)->where('provider', 'quickbooks')->first();

        return $connection && $connection->status === IntegrationConnection::STATUS_CONNECTED
            && (filled($connection->refresh_token) || filled($connection->access_token) && ! $connection->isExpired())
            && app(TenantModuleAccessResolver::class)->canAccess($snapshot->tenant_id, 'quickbooks');
    }

    public function forAccount(User $user, Space $space, int $snapshotId, string $accountKey): array
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless($space->kind === 'business', 404);
        $snapshot = QuickBooksReportingSnapshot::query()->forTenantId($space->tenant_id)->findOrFail($snapshotId);
        $connection = IntegrationConnection::query()->forTenantId($space->tenant_id)->whereKey($snapshot->integration_connection_id)->where('provider', 'quickbooks')->firstOrFail();
        $metrics = $snapshot->metrics ?? [];
        $header = (array) ($metrics['source_report'] ?? []);
        $basis = strtolower((string) ($metrics['accounting_method'] ?? $header['ReportBasis'] ?? ''));
        $currency = $header['Currency'] ?? null;
        abort_unless(in_array($basis, ['cash', 'accrual'], true) && is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency)
            && (! isset($header['StartPeriod']) || $header['StartPeriod'] === $snapshot->period_start->toDateString())
            && (! isset($header['EndPeriod']) || $header['EndPeriod'] === $snapshot->period_end->toDateString())
            && (! isset($header['ReportBasis']) || strtolower($header['ReportBasis']) === $basis)
            && (! isset($header['ReportName']) || $header['ReportName'] === 'ProfitAndLoss')
            && (! isset($header['SummarizeColumnsBy']) || $header['SummarizeColumnsBy'] === 'Total'), 422, 'This saved report does not establish a supported accounting identity.');
        $matches = [];
        $walk = function (array $rows, ?string $group = null, ?array $parent = null) use (&$walk, &$matches, $accountKey): void {
            foreach ($rows as $row) {
                $rowGroup = $row['group'] ?? $group;
                if (($row['key'] ?? null) === $accountKey) {
                    $matches[] = [...$row, 'source_group' => $rowGroup, 'source_parent' => $parent];
                }
                $walk((array) ($row['children'] ?? []), $rowGroup, $row);
            }
        };
        $walk((array) ($metrics['account_sections'] ?? []));
        abort_unless(count($matches) === 1, 404);
        $account = $matches[0];
        abort_unless(in_array($account['source_group'], ['Income', 'OtherIncome', 'COGS', 'Expenses', 'OtherExpenses'], true)
            && empty($account['children']) && preg_match('/^[0-9]{1,30}$/D', (string) ($account['id'] ?? ''))
            && $this->money($account['amount'] ?? null) !== null, 422, 'Choose a source account with its own reported amount. Expand parent accounts first.');
        $parent = $account['source_parent'];
        $direct = $parent && $accountKey === ($parent['key'] ?? '').'.direct' && ($parent['id'] ?? null) === $account['id'];
        $descendants = [];
        $collectIds = function (array $children) use (&$collectIds, &$descendants): void {
            foreach ($children as $child) {
                if (preg_match('/^[0-9]{1,30}$/D', (string) ($child['id'] ?? ''))) {
                    $descendants[] = (string) $child['id'];
                }
                $collectIds((array) ($child['children'] ?? []));
            }
        };
        if ($direct) {
            $collectIds((array) $parent['children']);
        }
        $context = ['snapshot_id' => $snapshot->id, 'account_key' => $accountKey, 'account' => ['id' => (string) $account['id'], 'label' => (string) ($account['label'] ?? '')],
            'period' => ['start' => $snapshot->period_start->toDateString(), 'end' => $snapshot->period_end->toDateString()], 'basis' => $basis, 'currency' => $currency,
            'source' => 'QuickBooks General Ledger', 'snapshot_observed_at' => $snapshot->observed_at?->toIso8601String(),
            'statement_amount_cents' => $this->money($account['amount']), 'orientation' => in_array($account['source_group'], ['Income', 'OtherIncome'], true) ? 'credit_minus_debit' : 'debit_minus_credit',
            'direct_postings' => (bool) $direct, 'descendant_account_ids' => array_values(array_diff(array_unique($descendants), [(string) $account['id']]))];
        if (! self::ready($snapshot)) {
            return $this->unavailable($context, 'The original QuickBooks connection is unavailable or needs its normal reconnection. Saved statement totals remain available.');
        }
        $lock = Cache::lock('quickbooks-sync:'.$connection->id, 120);
        if (! $lock->get()) {
            return $this->unavailable($context, 'QuickBooks is busy with another operation. Open this account again shortly.');
        }
        try {
            $admit = function () use ($user, $space, $connection, $snapshot, $metrics): void {
                $currentSpace = $space->fresh();
                abort_unless($currentSpace && $currentSpace->tenant_id === $space->tenant_id && $currentSpace->kind === 'business', 403);
                app(FinanceAccess::class)->authorize($user->fresh(), $currentSpace);
                $currentConnection = IntegrationConnection::query()->forTenantId($space->tenant_id)->whereKey($connection->id)->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->first();
                abort_unless($currentConnection && $currentConnection->external_account_id === $connection->external_account_id
                    && $currentConnection->external_account_secret === $connection->external_account_secret
                    && data_get($currentConnection->metadata, 'realm_id') === data_get($connection->metadata, 'realm_id')
                    && app(TenantModuleAccessResolver::class)->canAccess($space->tenant_id, 'quickbooks'), 403);
                $fresh = $snapshot->fresh();
                abort_unless($fresh && $fresh->tenant_id === $space->tenant_id && $fresh->integration_connection_id === $connection->id
                    && $fresh->period_start->equalTo($snapshot->period_start) && $fresh->period_end->equalTo($snapshot->period_end) && $fresh->metrics === $metrics, 409, 'The saved statement changed during this request. Reload the statement.');
            };
            $admit();
            $client = app(QuickBooksConnector::class)->client($connection->fresh(), $admit);
            $admit();
            $payload = $client->report('GeneralLedger', ['accounting_method' => ucfirst($basis), 'start_date' => $context['period']['start'], 'end_date' => $context['period']['end'], 'account' => $account['id'], 'columns' => self::COLUMNS]);
            $admit();

            return $this->normalize($payload, $context);
        } catch (RequestException|ConnectionException $exception) {
            app(FinanceAccess::class)->authorize($user->fresh(), $space->fresh());

            return $this->unavailable($context, 'QuickBooks could not supply this account report. No records were imported or changed. Open the account again later.');
        } finally {
            $lock->release();
        }
    }

    /** Explicit debit/credit columns avoid ambiguous natural amounts and running balances. */
    public function normalize(array $payload, array $context): array
    {
        $header = (array) ($payload['Header'] ?? []);
        if (($header['ReportName'] ?? null) !== 'GeneralLedger' || ($header['StartPeriod'] ?? null) !== $context['period']['start']
            || ($header['EndPeriod'] ?? null) !== $context['period']['end'] || strtolower((string) ($header['ReportBasis'] ?? '')) !== $context['basis']
            || ($header['Currency'] ?? null) !== $context['currency']) {
            return $this->unavailable($context, 'QuickBooks returned a different report period, basis or currency. Its rows are withheld.');
        }
        $columns = [];
        foreach ((array) data_get($payload, 'Columns.Column', []) as $index => $column) {
            $keys = collect((array) ($column['MetaData'] ?? []))->where('Name', 'ColKey')->pluck('Value')->values()->all();
            if (count($keys) !== 1 || isset($columns[$keys[0]])) {
                return $this->unavailable($context, 'QuickBooks returned unsupported or ambiguous report columns.');
            }
            $columns[$keys[0]] = ['index' => $index, 'type' => $column['ColType'] ?? null];
        }
        foreach (explode(',', self::COLUMNS) as $key) {
            if (! isset($columns[$key]) || in_array($key, ['debt_amt', 'credit_amt'], true) && $columns[$key]['type'] !== 'Money') {
                return $this->unavailable($context, 'This report does not provide explicit debit and credit columns for reliable signed activity.');
            }
        }
        $cell = fn (array $row, string $key) => data_get($row, 'ColData.'.$columns[$key]['index'].'.value');
        $amount = function (array $row) use ($cell, $context): ?array {
            $debit = $cell($row, 'debt_amt');
            $credit = $cell($row, 'credit_amt');
            if (($debit === null || $debit === '') && ($credit === null || $credit === '')) {
                return null;
            }
            $debit = $debit === '' || $debit === null ? 0 : $this->money($debit);
            $credit = $credit === '' || $credit === null ? 0 : $this->money($credit);
            if ($debit === null || $credit === null) {
                return null;
            }

            return ['debit_cents' => $debit, 'credit_cents' => $credit, 'amount_cents' => $context['orientation'] === 'credit_minus_debit' ? $credit - $debit : $debit - $credit];
        };
        $rows = [];
        $malformed = 0;
        $truncated = false;
        $foreign = false;
        $summaryCount = 0;
        $summaryMismatch = false;
        $nodesSeen = 0;
        $postingsSeen = 0;
        $allowedAccounts = [$context['account']['id'], ...(($context['direct_postings'] ?? false) ? ($context['descendant_account_ids'] ?? []) : [])];
        $walk = function (array $nodes, ?string $accountId = null, int $depth = 0) use (&$walk, &$rows, &$malformed, &$truncated, &$foreign, &$summaryCount, &$summaryMismatch, &$nodesSeen, &$postingsSeen, $allowedAccounts, $cell, $amount, $columns, $context): int {
            $total = 0;
            foreach ($nodes as $node) {
                if (++$nodesSeen > self::MAX_ROWS * 3 || $depth > 20) {
                    $truncated = true;

                    return $total;
                }
                if (! is_array($node)) {
                    $malformed++;

                    continue;
                }
                $scope = $accountId;
                if (isset($node['Header'])) {
                    $scope = (string) data_get($node, 'Header.ColData.0.id', '');
                    if (! in_array($scope, $allowedAccounts, true)) {
                        $foreign = true;

                        return $total;
                    }
                }
                $nodeTotal = 0;
                if (isset($node['ColData'])) {
                    $date = (string) $cell($node, 'tx_date');
                    $type = (string) $cell($node, 'txn_type');
                    if (in_array(strtolower(trim($date)), ['beginning balance', 'opening balance', 'ending balance'], true) && $type === '') {
                        continue;
                    }
                    if (! in_array($scope, $allowedAccounts, true)) {
                        $foreign = true;

                        return $total;
                    }
                    $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                        && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
                    $values = $amount($node);
                    if (! $validDate || $date < $context['period']['start'] || $date > $context['period']['end'] || $type === '' || $values === null) {
                        $malformed++;

                        continue;
                    }
                    if (++$postingsSeen > self::MAX_ROWS) {
                        $truncated = true;

                        continue;
                    }
                    $nodeTotal += $values['amount_cents'];
                    if ($scope === $context['account']['id']) {
                        $rows[] = ['date' => $date, 'transaction_type' => $type, 'transaction_id' => data_get($node, 'ColData.'.$columns['txn_type']['index'].'.id'),
                            'document_number' => (string) $cell($node, 'doc_num'), 'name' => (string) $cell($node, 'name'), 'memo' => (string) $cell($node, 'memo'), 'split_account' => (string) $cell($node, 'split_acc'), ...$values];
                    }
                }
                if (isset($node['Rows']['Row'])) {
                    $nodeTotal += $walk((array) $node['Rows']['Row'], $scope, $depth + 1);
                }
                if (isset($node['Summary']) && in_array($scope, $allowedAccounts, true)) {
                    $summary = $amount((array) $node['Summary']);
                    if ($summary === null) {
                        $malformed++;
                    } else {
                        $summaryCount++;
                        $summaryMismatch = $summaryMismatch || $summary['amount_cents'] !== $nodeTotal;
                    }
                } elseif (isset($node['Header'])) {
                    $malformed++;
                }
                $total += $nodeTotal;
            }

            return $total;
        };
        $walk((array) data_get($payload, 'Rows.Row', []));
        if ($foreign) {
            return $this->unavailable($context, 'The provider account filter could not be verified. Rows from another or unidentified account are withheld.');
        }
        $noData = collect((array) ($header['Option'] ?? []))->contains(fn ($option) => ($option['Name'] ?? '') === 'NoReportData' && strtolower((string) ($option['Value'] ?? '')) === 'true');
        $providerTruncated = collect((array) ($header['Option'] ?? []))->contains(fn ($option) => str_contains(strtolower((string) ($option['Name'] ?? '')), 'truncat') && strtolower((string) ($option['Value'] ?? '')) === 'true');
        $truncated = $truncated || $providerTruncated;
        $sum = array_sum(array_column($rows, 'amount_cents'));
        $summaryMatches = $summaryCount > 0 ? ! $summaryMismatch : $noData && $postingsSeen === 0;
        $partial = $malformed > 0 || $truncated || ! $summaryMatches || $noData && $postingsSeen > 0;
        $activity = $partial ? null : $sum;
        $difference = $activity === null ? null : $activity - $context['statement_amount_cents'];
        $reconciles = $difference === null ? null : $difference === 0;
        $notes = ['Signed amounts use '.str_replace('_', ' ', $context['orientation']).' from explicit source debit and credit columns. Opening balances and running totals are excluded. Multiple postings sharing a transaction ID remain separate.'];
        if ($context['direct_postings'] ?? false) {
            $notes[] = 'Only this parent account’s own postings are displayed. Known descendant accounts are validated against their source summaries but are excluded from the displayed direct activity.';
        }
        if ($partial) {
            $notes[] = 'Some report rows or source totals are unavailable, malformed or truncated. The visible rows do not establish a complete account total.';
        }
        if ($reconciles === false) {
            $notes[] = 'The current General Ledger activity differs from this saved statement account. The statement may be stale or source coverage may differ; no adjustment or bank substitution has been made.';
        }
        $providerObserved = $header['Time'] ?? null;
        $providerStale = null;
        if (is_string($providerObserved) && isset($context['snapshot_observed_at'])) {
            try {
                $providerStale = CarbonImmutable::parse($providerObserved)->lt(CarbonImmutable::parse($context['snapshot_observed_at']));
            } catch (\Throwable) {
                // Unknown provider time cannot establish source freshness.
            }
        }
        if ($providerStale) {
            $notes[] = 'The provider report timestamp predates this saved statement. Review source freshness before relying on the drill-down.';
        }

        return [...$context, 'status' => $partial || $reconciles === false || $providerStale ? 'partial' : 'complete', 'provider_observed_at' => $providerObserved, 'provider_stale' => $providerStale,
            'rows' => $rows, 'activity_cents' => $activity, 'visible_activity_cents' => $sum, 'difference_cents' => $difference, 'reconciles' => $reconciles,
            'stale_possible' => $reconciles === false || $providerStale === true, 'truncated' => $truncated, 'malformed_rows' => $malformed, 'notes' => $notes];
    }

    private function money(mixed $value): ?int
    {
        return (is_string($value) || is_int($value) || is_float($value)) && preg_match('/^-?\d+(?:\.\d{1,2})?$/D', (string) $value)
            && is_finite((float) $value) && abs((float) $value) < 1000000000000 ? Money::cents($value) : null;
    }

    private function unavailable(array $context, string $note): array
    {
        return [...$context, 'status' => 'unavailable', 'provider_observed_at' => null, 'rows' => [], 'activity_cents' => null, 'visible_activity_cents' => null,
            'difference_cents' => null, 'reconciles' => null, 'stale_possible' => false, 'truncated' => false, 'notes' => [$note]];
    }
}
