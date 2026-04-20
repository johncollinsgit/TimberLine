<?php

namespace App\Services\Marketing;

use App\Models\MarketingImportRun;
use App\Models\MarketingPaidMediaDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MetaAdsSpendSyncService
{
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function sync(array $options = []): array
    {
        $enabled = (bool) config('marketing.meta_ads.enabled', false);
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null);
        $storeKey = $this->nullableString($options['store_key'] ?? null) ?? 'retail';
        $accountId = $this->normalizeAccountId($options['account_id'] ?? config('marketing.meta_ads.account_id'));
        $accessToken = $this->nullableString($options['access_token'] ?? config('marketing.meta_ads.access_token'));
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $defaultLookbackDays = max(1, (int) config('marketing.meta_ads.default_lookback_days', 30));
        $since = $this->parseDate($options['since'] ?? null)
            ?? now()->toImmutable()->subDays($defaultLookbackDays)->startOfDay();
        $until = $this->parseDate($options['until'] ?? null)
            ?? now()->toImmutable()->endOfDay();

        if ($since->greaterThan($until)) {
            [$since, $until] = [$until->copy()->startOfDay(), $since->copy()->endOfDay()];
        }

        if (! $enabled) {
            return [
                'status' => 'blocked',
                'reason' => 'meta_ads_disabled',
                'message' => 'Meta Ads spend sync is disabled by config.',
            ];
        }

        if ($tenantId === null) {
            return [
                'status' => 'blocked',
                'reason' => 'tenant_missing',
                'message' => 'Missing tenant scope. Provide tenant_id.',
            ];
        }

        if ($accountId === null || $accessToken === null) {
            return [
                'status' => 'blocked',
                'reason' => 'meta_credentials_missing',
                'message' => 'Meta account id and access token are required for spend sync.',
            ];
        }

        $startedAt = now()->toImmutable();
        $lastSyncedAt = now();
        $run = $this->startImportRun($tenantId, $storeKey, $since, $until, $dryRun);

        $summary = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'date_from' => $since->toDateString(),
            'date_to' => $until->toDateString(),
        ];

        try {
            foreach ($this->fetchInsights($accountId, $accessToken, $since, $until) as $insightRow) {
                if (! is_array($insightRow)) {
                    continue;
                }

                $normalized = $this->normalizeInsightRow(
                    row: $insightRow,
                    tenantId: $tenantId,
                    storeKey: $storeKey,
                    accountId: $accountId,
                    lastSyncedAt: $lastSyncedAt
                );

                if (! is_array($normalized)) {
                    $summary['errors']++;

                    continue;
                }

                $summary['processed']++;

                if ($dryRun) {
                    continue;
                }

                $existing = MarketingPaidMediaDailyStat::query()
                    ->where('row_fingerprint', (string) $normalized['row_fingerprint'])
                    ->first();

                if (! $existing instanceof MarketingPaidMediaDailyStat) {
                    MarketingPaidMediaDailyStat::query()->create($normalized);
                    $summary['created']++;

                    continue;
                }

                $existing->fill($normalized);
                if (! $existing->isDirty()) {
                    $summary['unchanged']++;

                    continue;
                }

                $existing->save();
                $summary['updated']++;
            }

            $status = 'ok';
            $message = 'Meta spend sync completed.';
        } catch (\Throwable $exception) {
            $status = 'failed';
            $summary['errors']++;
            $message = $exception->getMessage();
        }

        $this->finishImportRun($run, $status, $summary, $message, $startedAt, $dryRun);

        return [
            'status' => $status,
            'message' => $message,
            'run_id' => $run?->id,
            'summary' => $summary,
            'dry_run' => $dryRun,
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'platform' => 'meta',
        ];
    }

    /**
     * @return iterable<int,array<string,mixed>>
     */
    protected function fetchInsights(string $accountId, string $accessToken, CarbonImmutable $since, CarbonImmutable $until): iterable
    {
        $baseUrl = rtrim((string) config('marketing.meta_ads.api_base_url', 'https://graph.facebook.com'), '/');
        $apiVersion = trim((string) config('marketing.meta_ads.api_version', 'v21.0'), '/');
        $timeoutSeconds = max(5, (int) config('marketing.meta_ads.timeout_seconds', 20));
        $maxPages = max(1, (int) config('marketing.meta_ads.max_pages', 30));

        $url = sprintf('%s/%s/act_%s/insights', $baseUrl, $apiVersion, $accountId);
        $params = [
            'access_token' => $accessToken,
            'level' => 'ad',
            'time_increment' => 1,
            'fields' => implode(',', [
                'account_id',
                'campaign_id',
                'campaign_name',
                'adset_id',
                'adset_name',
                'ad_id',
                'ad_name',
                'spend',
                'impressions',
                'clicks',
                'reach',
                'actions',
                'action_values',
                'date_start',
                'date_stop',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_content',
                'utm_term',
            ]),
            'time_range' => json_encode([
                'since' => $since->toDateString(),
                'until' => $until->toDateString(),
            ], JSON_UNESCAPED_SLASHES),
            'limit' => max(100, (int) config('marketing.meta_ads.limit', 500)),
        ];

        $page = 0;

        do {
            $response = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->get($url, $params);

            if (! $response->successful()) {
                throw new RuntimeException('Meta insights request failed: '.$response->status().' '.$response->body());
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Meta insights response is not valid JSON.');
            }

            foreach ((array) ($payload['data'] ?? []) as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            $next = $this->nullableString(data_get($payload, 'paging.next'));
            $url = $next ?? '';
            $params = [];
            $page++;
        } while ($url !== '' && $page < $maxPages);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null
     */
    protected function normalizeInsightRow(array $row, int $tenantId, string $storeKey, string $accountId, \DateTimeInterface $lastSyncedAt): ?array
    {
        $metricDate = $this->nullableString($row['date_start'] ?? null);
        if ($metricDate === null) {
            return null;
        }

        $campaignId = $this->nullableString($row['campaign_id'] ?? null);
        $campaignName = $this->nullableString($row['campaign_name'] ?? null);
        $adSetId = $this->nullableString($row['adset_id'] ?? null);
        $adSetName = $this->nullableString($row['adset_name'] ?? null);
        $adId = $this->nullableString($row['ad_id'] ?? null);
        $adName = $this->nullableString($row['ad_name'] ?? null);

        $utm = $this->extractUtmSignals($row, $campaignName, $adSetName, $adName);
        $purchases = (int) round($this->sumActionValues((array) ($row['actions'] ?? []), [
            'purchase',
            'offsite_conversion.fb_pixel_purchase',
            'omni_purchase',
        ]));
        $purchaseValue = round($this->sumActionValues((array) ($row['action_values'] ?? []), [
            'purchase',
            'offsite_conversion.fb_pixel_purchase',
            'omni_purchase',
        ]), 2);

        $fingerprint = sha1(implode('|', [
            (string) $tenantId,
            strtolower(trim($storeKey)),
            'meta',
            strtolower($accountId),
            $metricDate,
            strtolower((string) ($campaignId ?? $campaignName ?? 'campaign-none')),
            strtolower((string) ($adSetId ?? $adSetName ?? 'adset-none')),
            strtolower((string) ($adId ?? $adName ?? 'ad-none')),
        ]));

        return [
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'platform' => 'meta',
            'account_id' => $this->nullableString($row['account_id'] ?? null) ?? $accountId,
            'metric_date' => $metricDate,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'ad_set_id' => $adSetId,
            'ad_set_name' => $adSetName,
            'ad_id' => $adId,
            'ad_name' => $adName,
            'spend' => round(max(0.0, $this->floatValue($row['spend'] ?? null)), 2),
            'impressions' => max(0, $this->intValue($row['impressions'] ?? null)),
            'clicks' => max(0, $this->intValue($row['clicks'] ?? null)),
            'reach' => max(0, $this->intValue($row['reach'] ?? null)),
            'purchases' => max(0, $purchases),
            'purchase_value' => max(0, $purchaseValue),
            'utm_source' => $utm['utm_source'] ?? null,
            'utm_medium' => $utm['utm_medium'] ?? null,
            'utm_campaign' => $utm['utm_campaign'] ?? null,
            'utm_content' => $utm['utm_content'] ?? null,
            'utm_term' => $utm['utm_term'] ?? null,
            'row_fingerprint' => $fingerprint,
            'raw_payload' => $row,
            'last_synced_at' => $lastSyncedAt,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,string>
     */
    protected function extractUtmSignals(array $row, ?string $campaignName, ?string $adSetName, ?string $adName): array
    {
        $signals = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
            $value = $this->nullableString($row[$field] ?? null);
            if ($value !== null) {
                $signals[$field] = $value;
            }
        }

        if (count($signals) === 5) {
            return $signals;
        }

        foreach ([$campaignName, $adSetName, $adName] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $extracted = $this->extractUtmFromText($candidate);
            foreach ($extracted as $field => $value) {
                if (! array_key_exists($field, $signals)) {
                    $signals[$field] = $value;
                }
            }

            if (count($signals) === 5) {
                break;
            }
        }

        return $signals;
    }

    /**
     * @return array<string,string>
     */
    protected function extractUtmFromText(string $value): array
    {
        $signals = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
            $matches = [];
            if (preg_match('/'.preg_quote($field, '/').'=([^&\s|]+)/i', $value, $matches) !== 1) {
                continue;
            }

            $candidate = $this->nullableString(urldecode((string) ($matches[1] ?? '')));
            if ($candidate !== null) {
                $signals[$field] = $candidate;
            }
        }

        return $signals;
    }

    /**
     * @param  array<int,mixed>  $actions
     */
    protected function sumActionValues(array $actions, array $matchingTypes): float
    {
        $sum = 0.0;

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = strtolower(trim((string) ($action['action_type'] ?? '')));
            if ($type === '') {
                continue;
            }

            if (! in_array($type, $matchingTypes, true)) {
                continue;
            }

            $sum += max(0.0, $this->floatValue($action['value'] ?? null));
        }

        return $sum;
    }

    protected function startImportRun(int $tenantId, string $storeKey, CarbonImmutable $since, CarbonImmutable $until, bool $dryRun): ?MarketingImportRun
    {
        if (! Schema::hasTable('marketing_import_runs')) {
            return null;
        }

        return MarketingImportRun::query()->create([
            'tenant_id' => $tenantId,
            'type' => 'meta_ads_daily_sync',
            'status' => 'running',
            'source_label' => 'meta_ads',
            'file_name' => null,
            'started_at' => now(),
            'summary' => [
                'store_key' => $storeKey,
                'date_from' => $since->toDateString(),
                'date_to' => $until->toDateString(),
                'dry_run' => $dryRun,
            ],
            'notes' => null,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    protected function finishImportRun(?MarketingImportRun $run, string $status, array $summary, string $message, CarbonImmutable $startedAt, bool $dryRun): void
    {
        if (! $run instanceof MarketingImportRun) {
            return;
        }

        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'summary' => array_merge((array) ($run->summary ?? []), $summary, [
                'status' => $status,
                'message' => $message,
                'duration_seconds' => max(0, $startedAt->diffInSeconds(now()->toImmutable())),
                'dry_run' => $dryRun,
            ]),
            'notes' => $message,
        ])->save();
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeAccountId(mixed $value): ?string
    {
        $resolved = $this->nullableString($value);
        if ($resolved === null) {
            return null;
        }

        $resolved = preg_replace('/^act_/i', '', $resolved);

        return $this->nullableString($resolved);
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    protected function intValue(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return (int) floor((float) $value);
    }

    protected function floatValue(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }
}
