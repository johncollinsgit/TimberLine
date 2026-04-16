<?php

namespace App\Console\Commands;

use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Services\Marketing\MessageOrderAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MarketingBackfillMessageContext extends Command
{
    protected $signature = 'marketing:backfill-message-context
        {--tenant-id= : Restrict writes to a single tenant}
        {--store= : Restrict writes to a single store key}
        {--chunk=500 : Number of rows to inspect per chunk}
        {--window-days= : Override attribution window days for post-backfill sync}
        {--skip-attribution-sync : Skip post-backfill attribution sync}
        {--dry-run : Preview changes without writing updates}';

    protected $description = 'Backfill missing tenant/store message context for campaign deliveries and engagement events.';

    /**
     * @var array<int,array{tenant_id:?int,store_key:?string,source_label:?string}>
     */
    protected array $campaignContextCache = [];

    /**
     * @var array<int,array{campaign_id:?int,marketing_profile_id:?int}>
     */
    protected array $recipientContextCache = [];

    /**
     * @var array<int,?int>
     */
    protected array $profileTenantCache = [];

    /**
     * @var array<int,?string>
     */
    protected array $singleStoreByTenantCache = [];

    /**
     * @var array<int,array{tenant_id:?int,store_key:?string,marketing_profile_id:?int,channel:?string}>
     */
    protected array $messageDeliveryContextCache = [];

    /**
     * @var array<int,array{tenant_id:?int,store_key:?string,marketing_profile_id:?int,channel:?string}>
     */
    protected array $emailDeliveryContextCache = [];

    public function handle(MessageOrderAttributionService $attributionService): int
    {
        $tenantFilter = $this->positiveInt($this->option('tenant-id'));
        $storeFilter = $this->normalizedStoreKey($this->option('store'));
        $chunk = max(100, (int) ($this->option('chunk') ?: 500));
        $dryRun = (bool) $this->option('dry-run');
        $skipAttributionSync = (bool) $this->option('skip-attribution-sync');
        $windowDaysOption = $this->option('window-days');
        $windowDays = is_numeric($windowDaysOption) && (int) $windowDaysOption > 0
            ? (int) $windowDaysOption
            : null;

        $summary = [
            'deliveries_examined' => 0,
            'deliveries_repaired' => 0,
            'deliveries_skipped_ambiguous' => 0,
            'events_examined' => 0,
            'events_repaired' => 0,
            'events_skipped_ambiguous' => 0,
            'contexts_synced' => 0,
            'attribution_processed' => 0,
            'attribution_attributed' => 0,
            'attribution_created' => 0,
            'attribution_updated' => 0,
            'attribution_cleared' => 0,
            'attribution_skipped' => 0,
        ];

        $contextWindows = [];
        $ambiguousDeliverySamples = [];
        $ambiguousEventSamples = [];

        if (Schema::hasTable('marketing_message_deliveries')) {
            MarketingMessageDelivery::query()
                ->where(function ($query): void {
                    $query->whereNull('tenant_id')
                        ->orWhereNull('store_key')
                        ->orWhere('store_key', '')
                        ->orWhere(function ($campaignQuery): void {
                            $campaignQuery->whereNotNull('campaign_id')
                                ->where(function ($missing): void {
                                    $missing->whereNull('source_label')
                                        ->orWhere('source_label', '')
                                        ->orWhereNull('batch_id')
                                        ->orWhere('batch_id', '');
                                });
                        });
                })
                ->orderBy('id')
                ->chunkById($chunk, function ($rows) use (
                    &$summary,
                    &$contextWindows,
                    &$ambiguousDeliverySamples,
                    $tenantFilter,
                    $storeFilter,
                    $dryRun
                ): void {
                    foreach ($rows as $delivery) {
                        $summary['deliveries_examined']++;
                        $result = $this->repairDeliveryContext(
                            delivery: $delivery,
                            tenantFilter: $tenantFilter,
                            storeFilter: $storeFilter,
                            dryRun: $dryRun
                        );

                        if ((bool) ($result['ambiguous'] ?? false)) {
                            $summary['deliveries_skipped_ambiguous']++;
                            if (count($ambiguousDeliverySamples) < 15) {
                                $ambiguousDeliverySamples[] = [
                                    'delivery_id' => (int) $delivery->id,
                                    'reason' => (string) ($result['reason'] ?? 'ambiguous'),
                                ];
                            }
                            continue;
                        }

                        if (! (bool) ($result['repaired'] ?? false)) {
                            continue;
                        }

                        $summary['deliveries_repaired']++;
                        $this->trackContextWindow(
                            $contextWindows,
                            (int) ($result['tenant_id'] ?? 0),
                            (string) ($result['store_key'] ?? ''),
                            $result['timestamp'] ?? null
                        );
                    }
                });
        }

        if (Schema::hasTable('marketing_message_engagement_events')) {
            MarketingMessageEngagementEvent::query()
                ->where(function ($query): void {
                    $query->whereNull('tenant_id')
                        ->orWhereNull('store_key')
                        ->orWhere('store_key', '');
                })
                ->orderBy('id')
                ->chunkById($chunk, function ($rows) use (
                    &$summary,
                    &$contextWindows,
                    &$ambiguousEventSamples,
                    $tenantFilter,
                    $storeFilter,
                    $dryRun
                ): void {
                    foreach ($rows as $event) {
                        $summary['events_examined']++;
                        $result = $this->repairEventContext(
                            event: $event,
                            tenantFilter: $tenantFilter,
                            storeFilter: $storeFilter,
                            dryRun: $dryRun
                        );

                        if ((bool) ($result['ambiguous'] ?? false)) {
                            $summary['events_skipped_ambiguous']++;
                            if (count($ambiguousEventSamples) < 15) {
                                $ambiguousEventSamples[] = [
                                    'event_id' => (int) $event->id,
                                    'reason' => (string) ($result['reason'] ?? 'ambiguous'),
                                ];
                            }
                            continue;
                        }

                        if (! (bool) ($result['repaired'] ?? false)) {
                            continue;
                        }

                        $summary['events_repaired']++;
                        $this->trackContextWindow(
                            $contextWindows,
                            (int) ($result['tenant_id'] ?? 0),
                            (string) ($result['store_key'] ?? ''),
                            $result['timestamp'] ?? null
                        );
                    }
                });
        }

        if ($ambiguousDeliverySamples !== [] || $ambiguousEventSamples !== []) {
            Log::warning('marketing.backfill_message_context.ambiguous_rows', [
                'delivery_samples' => $ambiguousDeliverySamples,
                'event_samples' => $ambiguousEventSamples,
            ]);
        }

        if (! $dryRun && ! $skipAttributionSync && $contextWindows !== []) {
            $attributionWindowDays = $windowDays ?? max(1, (int) config('marketing.message_analytics.attribution_window_days', 7));

            foreach ($contextWindows as $context) {
                $tenantId = (int) ($context['tenant_id'] ?? 0);
                $storeKey = $this->normalizedStoreKey($context['store_key'] ?? null);
                $from = $this->dateOrNull($context['from'] ?? null);
                if ($tenantId <= 0 || $storeKey === null || ! $from instanceof CarbonImmutable) {
                    continue;
                }

                $result = $attributionService->syncForTenantStore(
                    tenantId: $tenantId,
                    storeKey: $storeKey,
                    dateFrom: $from->subDays($attributionWindowDays)->startOfDay(),
                    dateTo: CarbonImmutable::now()->endOfDay(),
                    windowDays: $windowDays
                );

                $summary['contexts_synced']++;
                foreach (['processed', 'attributed', 'created', 'updated', 'cleared', 'skipped'] as $key) {
                    $summary['attribution_'.$key] += (int) ($result[$key] ?? 0);
                }
            }
        }

        $this->info(sprintf(
            'deliveries_examined=%d deliveries_repaired=%d deliveries_skipped_ambiguous=%d events_examined=%d events_repaired=%d events_skipped_ambiguous=%d dry_run=%s',
            $summary['deliveries_examined'],
            $summary['deliveries_repaired'],
            $summary['deliveries_skipped_ambiguous'],
            $summary['events_examined'],
            $summary['events_repaired'],
            $summary['events_skipped_ambiguous'],
            $dryRun ? 'yes' : 'no'
        ));

        $this->info(sprintf(
            'contexts_synced=%d attribution_processed=%d attributed=%d created=%d updated=%d cleared=%d skipped=%d',
            $summary['contexts_synced'],
            $summary['attribution_processed'],
            $summary['attribution_attributed'],
            $summary['attribution_created'],
            $summary['attribution_updated'],
            $summary['attribution_cleared'],
            $summary['attribution_skipped']
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{repaired:bool,ambiguous:bool,reason:?string,tenant_id:?int,store_key:?string,timestamp:?CarbonImmutable}
     */
    protected function repairDeliveryContext(
        MarketingMessageDelivery $delivery,
        ?int $tenantFilter,
        ?string $storeFilter,
        bool $dryRun
    ): array {
        $deliveryId = (int) ($delivery->id ?? 0);
        if ($deliveryId <= 0) {
            return ['repaired' => false, 'ambiguous' => false, 'reason' => null, 'tenant_id' => null, 'store_key' => null, 'timestamp' => null];
        }

        $existingTenantId = $this->positiveInt($delivery->tenant_id);
        $existingStoreKey = $this->normalizedStoreKey($delivery->store_key);
        $existingCampaignId = $this->positiveInt($delivery->campaign_id);
        $existingSourceLabel = $this->nullableString($delivery->source_label);
        $existingBatchId = $this->nullableString($delivery->batch_id);

        $recipientContext = $this->recipientContext($this->positiveInt($delivery->campaign_recipient_id));

        $campaignIds = collect([
            $existingCampaignId,
            $this->positiveInt($recipientContext['campaign_id'] ?? null),
        ])
            ->filter(fn ($value): bool => $this->positiveInt($value) !== null)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        if ($existingCampaignId === null && $campaignIds->count() > 1) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'ambiguous_campaign_id',
                'tenant_id' => null,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        $resolvedCampaignId = $existingCampaignId ?? ($campaignIds->first() ?: null);

        $campaignTenantCandidates = collect();
        $campaignStoreCandidates = collect();
        $campaignSourceCandidates = collect();

        foreach ($campaignIds as $campaignId) {
            $campaignContext = $this->campaignContext((int) $campaignId);
            $campaignTenant = $this->positiveInt($campaignContext['tenant_id'] ?? null);
            $campaignStore = $this->normalizedStoreKey($campaignContext['store_key'] ?? null);
            $campaignSource = $this->nullableString($campaignContext['source_label'] ?? null);

            if ($campaignTenant !== null) {
                $campaignTenantCandidates->push($campaignTenant);
            }
            if ($campaignStore !== null) {
                $campaignStoreCandidates->push($campaignStore);
            }
            if ($campaignSource !== null) {
                $campaignSourceCandidates->push($campaignSource);
            }
        }

        $profileTenantCandidates = collect();
        foreach ([
            $this->positiveInt($delivery->marketing_profile_id),
            $this->positiveInt($recipientContext['marketing_profile_id'] ?? null),
        ] as $profileId) {
            if ($profileId === null) {
                continue;
            }
            $profileTenant = $this->profileTenant($profileId);
            if ($profileTenant !== null) {
                $profileTenantCandidates->push($profileTenant);
            }
        }

        $tenantCandidates = $campaignTenantCandidates
            ->merge($profileTenantCandidates)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();
        if ($existingTenantId === null && $tenantCandidates->count() > 1) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'ambiguous_tenant_context',
                'tenant_id' => null,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        $resolvedTenantId = $existingTenantId ?? ($tenantCandidates->first() ?: null);
        if ($resolvedTenantId === null) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'tenant_context_unresolved',
                'tenant_id' => null,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        $storeCandidates = $campaignStoreCandidates
            ->map(fn ($value): ?string => $this->normalizedStoreKey($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values();
        if ($existingStoreKey === null) {
            $fallbackStore = $this->singleStoreKeyForTenant($resolvedTenantId);
            if ($fallbackStore !== null) {
                $storeCandidates->push($fallbackStore);
            }
            $storeCandidates = $storeCandidates->unique()->values();
        }

        if ($existingStoreKey === null && $storeCandidates->count() > 1) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'ambiguous_store_context',
                'tenant_id' => $resolvedTenantId,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        $resolvedStoreKey = $existingStoreKey ?? ($storeCandidates->first() ?: null);
        if ($resolvedStoreKey === null) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'store_context_unresolved',
                'tenant_id' => $resolvedTenantId,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        if ($tenantFilter !== null && $resolvedTenantId !== $tenantFilter) {
            return [
                'repaired' => false,
                'ambiguous' => false,
                'reason' => null,
                'tenant_id' => $resolvedTenantId,
                'store_key' => $resolvedStoreKey,
                'timestamp' => null,
            ];
        }
        if ($storeFilter !== null && $resolvedStoreKey !== $storeFilter) {
            return [
                'repaired' => false,
                'ambiguous' => false,
                'reason' => null,
                'tenant_id' => $resolvedTenantId,
                'store_key' => $resolvedStoreKey,
                'timestamp' => null,
            ];
        }

        $resolvedSourceLabel = $existingSourceLabel
            ?? $campaignSourceCandidates
                ->map(fn ($value): ?string => $this->nullableString($value))
                ->filter(fn (?string $value): bool => $value !== null)
                ->unique()
                ->first();
        if ($resolvedSourceLabel === null && $resolvedCampaignId !== null) {
            $resolvedSourceLabel = 'marketing_campaign';
        }

        $resolvedBatchId = $existingBatchId;
        if ($resolvedBatchId === null && $resolvedCampaignId !== null) {
            $resolvedBatchId = 'cmp-'.$resolvedCampaignId.'-legacy-'.$deliveryId;
        }

        $patch = [];
        if ($existingTenantId === null) {
            $patch['tenant_id'] = $resolvedTenantId;
        }
        if ($existingStoreKey === null) {
            $patch['store_key'] = $resolvedStoreKey;
        }
        if ($existingCampaignId === null && $resolvedCampaignId !== null) {
            $patch['campaign_id'] = $resolvedCampaignId;
        }
        if ($existingSourceLabel === null && $resolvedSourceLabel !== null) {
            $patch['source_label'] = $resolvedSourceLabel;
        }
        if ($existingBatchId === null && $resolvedBatchId !== null) {
            $patch['batch_id'] = $resolvedBatchId;
        }

        if ($patch === []) {
            $this->messageDeliveryContextCache[$deliveryId] = [
                'tenant_id' => $resolvedTenantId,
                'store_key' => $resolvedStoreKey,
                'marketing_profile_id' => $this->positiveInt($delivery->marketing_profile_id),
                'channel' => $this->nullableString($delivery->channel),
            ];

            return [
                'repaired' => false,
                'ambiguous' => false,
                'reason' => null,
                'tenant_id' => $resolvedTenantId,
                'store_key' => $resolvedStoreKey,
                'timestamp' => null,
            ];
        }

        if (! $dryRun) {
            MarketingMessageDelivery::query()
                ->whereKey($deliveryId)
                ->update($patch);
        }

        $this->messageDeliveryContextCache[$deliveryId] = [
            'tenant_id' => (int) ($patch['tenant_id'] ?? $existingTenantId ?? $resolvedTenantId),
            'store_key' => (string) ($patch['store_key'] ?? $existingStoreKey ?? $resolvedStoreKey),
            'marketing_profile_id' => $this->positiveInt($delivery->marketing_profile_id),
            'channel' => $this->nullableString($delivery->channel),
        ];

        return [
            'repaired' => true,
            'ambiguous' => false,
            'reason' => null,
            'tenant_id' => $resolvedTenantId,
            'store_key' => $resolvedStoreKey,
            'timestamp' => $this->dateOrNull($delivery->sent_at ?? $delivery->created_at),
        ];
    }

    /**
     * @return array{repaired:bool,ambiguous:bool,reason:?string,tenant_id:?int,store_key:?string,timestamp:?CarbonImmutable}
     */
    protected function repairEventContext(
        MarketingMessageEngagementEvent $event,
        ?int $tenantFilter,
        ?string $storeFilter,
        bool $dryRun
    ): array {
        $eventId = (int) ($event->id ?? 0);
        if ($eventId <= 0) {
            return ['repaired' => false, 'ambiguous' => false, 'reason' => null, 'tenant_id' => null, 'store_key' => null, 'timestamp' => null];
        }

        $existingTenantId = $this->positiveInt($event->tenant_id);
        $existingStoreKey = $this->normalizedStoreKey($event->store_key);
        $existingProfileId = $this->positiveInt($event->marketing_profile_id);
        $existingChannel = $this->nullableString($event->channel);

        $messageDeliveryContext = $this->messageDeliveryContext($this->positiveInt($event->marketing_message_delivery_id));
        $emailDeliveryContext = $this->emailDeliveryContext($this->positiveInt($event->marketing_email_delivery_id));

        $tenantCandidates = collect([
            $this->positiveInt($messageDeliveryContext['tenant_id'] ?? null),
            $this->positiveInt($emailDeliveryContext['tenant_id'] ?? null),
        ])
            ->filter(fn ($value): bool => $this->positiveInt($value) !== null)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();
        if ($existingTenantId === null && $tenantCandidates->count() > 1) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'ambiguous_event_tenant_context',
                'tenant_id' => null,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        $resolvedTenantId = $existingTenantId ?? ($tenantCandidates->first() ?: null);

        if ($resolvedTenantId === null && $existingProfileId !== null) {
            $resolvedTenantId = $this->profileTenant($existingProfileId);
        }
        if ($resolvedTenantId === null) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'event_tenant_context_unresolved',
                'tenant_id' => null,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        $storeCandidates = collect([
            $this->normalizedStoreKey($messageDeliveryContext['store_key'] ?? null),
            $this->normalizedStoreKey($emailDeliveryContext['store_key'] ?? null),
        ])
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values();
        if ($existingStoreKey === null) {
            $fallbackStoreKey = $this->singleStoreKeyForTenant($resolvedTenantId);
            if ($fallbackStoreKey !== null) {
                $storeCandidates->push($fallbackStoreKey);
            }
            $storeCandidates = $storeCandidates->unique()->values();
        }

        if ($existingStoreKey === null && $storeCandidates->count() > 1) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'ambiguous_event_store_context',
                'tenant_id' => $resolvedTenantId,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        $resolvedStoreKey = $existingStoreKey ?? ($storeCandidates->first() ?: null);
        if ($resolvedStoreKey === null) {
            return [
                'repaired' => false,
                'ambiguous' => true,
                'reason' => 'event_store_context_unresolved',
                'tenant_id' => $resolvedTenantId,
                'store_key' => null,
                'timestamp' => null,
            ];
        }

        if ($tenantFilter !== null && $resolvedTenantId !== $tenantFilter) {
            return [
                'repaired' => false,
                'ambiguous' => false,
                'reason' => null,
                'tenant_id' => $resolvedTenantId,
                'store_key' => $resolvedStoreKey,
                'timestamp' => null,
            ];
        }
        if ($storeFilter !== null && $resolvedStoreKey !== $storeFilter) {
            return [
                'repaired' => false,
                'ambiguous' => false,
                'reason' => null,
                'tenant_id' => $resolvedTenantId,
                'store_key' => $resolvedStoreKey,
                'timestamp' => null,
            ];
        }

        $resolvedProfileId = $existingProfileId
            ?? $this->positiveInt($messageDeliveryContext['marketing_profile_id'] ?? null)
            ?? $this->positiveInt($emailDeliveryContext['marketing_profile_id'] ?? null);
        $resolvedChannel = $existingChannel
            ?? $this->nullableString($messageDeliveryContext['channel'] ?? null)
            ?? $this->nullableString($emailDeliveryContext['channel'] ?? null);

        $patch = [];
        if ($existingTenantId === null) {
            $patch['tenant_id'] = $resolvedTenantId;
        }
        if ($existingStoreKey === null) {
            $patch['store_key'] = $resolvedStoreKey;
        }
        if ($existingProfileId === null && $resolvedProfileId !== null) {
            $patch['marketing_profile_id'] = $resolvedProfileId;
        }
        if ($existingChannel === null && $resolvedChannel !== null) {
            $patch['channel'] = $resolvedChannel;
        }

        if ($patch === []) {
            return [
                'repaired' => false,
                'ambiguous' => false,
                'reason' => null,
                'tenant_id' => $resolvedTenantId,
                'store_key' => $resolvedStoreKey,
                'timestamp' => null,
            ];
        }

        if (! $dryRun) {
            MarketingMessageEngagementEvent::query()
                ->whereKey($eventId)
                ->update($patch);
        }

        return [
            'repaired' => true,
            'ambiguous' => false,
            'reason' => null,
            'tenant_id' => $resolvedTenantId,
            'store_key' => $resolvedStoreKey,
            'timestamp' => $this->dateOrNull($event->occurred_at ?? $event->created_at),
        ];
    }

    /**
     * @return array{tenant_id:?int,store_key:?string,source_label:?string}
     */
    protected function campaignContext(?int $campaignId): array
    {
        $campaignId = $this->positiveInt($campaignId);
        if ($campaignId === null) {
            return ['tenant_id' => null, 'store_key' => null, 'source_label' => null];
        }

        if (array_key_exists($campaignId, $this->campaignContextCache)) {
            return $this->campaignContextCache[$campaignId];
        }

        if (! Schema::hasTable('marketing_campaigns')) {
            return $this->campaignContextCache[$campaignId] = ['tenant_id' => null, 'store_key' => null, 'source_label' => null];
        }

        $record = DB::table('marketing_campaigns')
            ->where('id', $campaignId)
            ->first(['tenant_id', 'store_key', 'source_label']);

        return $this->campaignContextCache[$campaignId] = [
            'tenant_id' => $this->positiveInt($record->tenant_id ?? null),
            'store_key' => $this->normalizedStoreKey($record->store_key ?? null),
            'source_label' => $this->nullableString($record->source_label ?? null),
        ];
    }

    /**
     * @return array{campaign_id:?int,marketing_profile_id:?int}
     */
    protected function recipientContext(?int $recipientId): array
    {
        $recipientId = $this->positiveInt($recipientId);
        if ($recipientId === null) {
            return ['campaign_id' => null, 'marketing_profile_id' => null];
        }

        if (array_key_exists($recipientId, $this->recipientContextCache)) {
            return $this->recipientContextCache[$recipientId];
        }

        if (! Schema::hasTable('marketing_campaign_recipients')) {
            return $this->recipientContextCache[$recipientId] = ['campaign_id' => null, 'marketing_profile_id' => null];
        }

        $record = DB::table('marketing_campaign_recipients')
            ->where('id', $recipientId)
            ->first(['campaign_id', 'marketing_profile_id']);

        return $this->recipientContextCache[$recipientId] = [
            'campaign_id' => $this->positiveInt($record->campaign_id ?? null),
            'marketing_profile_id' => $this->positiveInt($record->marketing_profile_id ?? null),
        ];
    }

    protected function profileTenant(?int $profileId): ?int
    {
        $profileId = $this->positiveInt($profileId);
        if ($profileId === null) {
            return null;
        }

        if (array_key_exists($profileId, $this->profileTenantCache)) {
            return $this->profileTenantCache[$profileId];
        }

        if (! Schema::hasTable('marketing_profiles')) {
            return $this->profileTenantCache[$profileId] = null;
        }

        $tenantId = DB::table('marketing_profiles')
            ->where('id', $profileId)
            ->value('tenant_id');

        return $this->profileTenantCache[$profileId] = $this->positiveInt($tenantId);
    }

    protected function singleStoreKeyForTenant(?int $tenantId): ?string
    {
        $tenantId = $this->positiveInt($tenantId);
        if ($tenantId === null) {
            return null;
        }

        if (array_key_exists($tenantId, $this->singleStoreByTenantCache)) {
            return $this->singleStoreByTenantCache[$tenantId];
        }

        if (! Schema::hasTable('shopify_stores')) {
            return $this->singleStoreByTenantCache[$tenantId] = null;
        }

        $storeKeys = DB::table('shopify_stores')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('store_key')
            ->pluck('store_key')
            ->map(fn ($value): ?string => $this->normalizedStoreKey($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values();

        return $this->singleStoreByTenantCache[$tenantId] = $storeKeys->count() === 1
            ? (string) $storeKeys->first()
            : null;
    }

    /**
     * @return array{tenant_id:?int,store_key:?string,marketing_profile_id:?int,channel:?string}
     */
    protected function messageDeliveryContext(?int $deliveryId): array
    {
        $deliveryId = $this->positiveInt($deliveryId);
        if ($deliveryId === null) {
            return ['tenant_id' => null, 'store_key' => null, 'marketing_profile_id' => null, 'channel' => null];
        }

        if (array_key_exists($deliveryId, $this->messageDeliveryContextCache)) {
            return $this->messageDeliveryContextCache[$deliveryId];
        }

        if (! Schema::hasTable('marketing_message_deliveries')) {
            return $this->messageDeliveryContextCache[$deliveryId] = ['tenant_id' => null, 'store_key' => null, 'marketing_profile_id' => null, 'channel' => null];
        }

        $record = DB::table('marketing_message_deliveries')
            ->where('id', $deliveryId)
            ->first(['tenant_id', 'store_key', 'marketing_profile_id', 'channel']);

        return $this->messageDeliveryContextCache[$deliveryId] = [
            'tenant_id' => $this->positiveInt($record->tenant_id ?? null),
            'store_key' => $this->normalizedStoreKey($record->store_key ?? null),
            'marketing_profile_id' => $this->positiveInt($record->marketing_profile_id ?? null),
            'channel' => $this->nullableString($record->channel ?? null),
        ];
    }

    /**
     * @return array{tenant_id:?int,store_key:?string,marketing_profile_id:?int,channel:?string}
     */
    protected function emailDeliveryContext(?int $deliveryId): array
    {
        $deliveryId = $this->positiveInt($deliveryId);
        if ($deliveryId === null) {
            return ['tenant_id' => null, 'store_key' => null, 'marketing_profile_id' => null, 'channel' => null];
        }

        if (array_key_exists($deliveryId, $this->emailDeliveryContextCache)) {
            return $this->emailDeliveryContextCache[$deliveryId];
        }

        if (! Schema::hasTable('marketing_email_deliveries')) {
            return $this->emailDeliveryContextCache[$deliveryId] = ['tenant_id' => null, 'store_key' => null, 'marketing_profile_id' => null, 'channel' => null];
        }

        $record = DB::table('marketing_email_deliveries')
            ->where('id', $deliveryId)
            ->first(['tenant_id', 'store_key', 'marketing_profile_id']);

        return $this->emailDeliveryContextCache[$deliveryId] = [
            'tenant_id' => $this->positiveInt($record->tenant_id ?? null),
            'store_key' => $this->normalizedStoreKey($record->store_key ?? null),
            'marketing_profile_id' => $this->positiveInt($record->marketing_profile_id ?? null),
            'channel' => 'email',
        ];
    }

    /**
     * @param  array<string,array{tenant_id:int,store_key:string,from:CarbonImmutable}>  $contextWindows
     */
    protected function trackContextWindow(array &$contextWindows, int $tenantId, string $storeKey, mixed $timestamp): void
    {
        $tenantId = $this->positiveInt($tenantId) ?? 0;
        $storeKey = $this->normalizedStoreKey($storeKey) ?? '';
        if ($tenantId <= 0 || $storeKey === '') {
            return;
        }

        $date = $this->dateOrNull($timestamp) ?? CarbonImmutable::now();
        $key = $tenantId.'|'.$storeKey;
        $existing = $contextWindows[$key] ?? null;
        if (! is_array($existing)) {
            $contextWindows[$key] = [
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'from' => $date,
            ];

            return;
        }

        $existingFrom = $this->dateOrNull($existing['from'] ?? null) ?? $date;
        $contextWindows[$key]['from'] = $date->lessThan($existingFrom) ? $date : $existingFrom;
    }

    protected function dateOrNull(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\DateTime::createFromInterface($value));
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
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

    protected function normalizedStoreKey(mixed $value): ?string
    {
        $store = $this->nullableString($value);

        return $store !== null ? strtolower($store) : null;
    }
}
