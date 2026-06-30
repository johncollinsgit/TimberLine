<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileScentQuizResult;
use App\Models\MarketingSocialShareClaim;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ModernForestrySocialShareRewardService
{
    public const REWARD_CANDLE_CASH = 1;

    /**
     * @var array<int,string>
     */
    protected const PLATFORMS = ['facebook', 'instagram'];

    /**
     * @var array<int,string>
     */
    protected const SHARE_MODES = ['story', 'post', 'copy_link', 'generic'];

    /**
     * @var array<int,string>
     */
    protected const TARGET_TYPES = ['purchased_product', 'product', 'scent_personality'];

    public function __construct(
        protected CandleCashService $candleCashService,
        protected MarketingStorefrontEventLogger $eventLogger
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function config(MarketingProfile $profile): array
    {
        return [
            'reward' => [
                'amount' => self::REWARD_CANDLE_CASH,
                'formatted' => $this->candleCashService->formatCurrency(self::REWARD_CANDLE_CASH),
                'label' => '$1 Candle Cash',
            ],
            'platforms' => [
                ['id' => 'facebook', 'label' => 'Facebook'],
                ['id' => 'instagram', 'label' => 'Instagram'],
            ],
            'limits' => [
                'rule' => 'once_per_profile_platform_target',
            ],
            'scentPersonality' => $this->scentPersonalityTarget($profile),
        ];
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array<string,mixed>
     */
    public function started(MarketingProfile $profile, string $platform, array $target, array $context = []): array
    {
        $platform = $this->normalizePlatform($platform);
        $target = $this->normalizeTarget($profile, $target);
        $shareMode = $this->normalizeShareMode($context['share_mode'] ?? null);
        $shareSource = $this->shareSource($platform, $shareMode);
        $shareUrl = $this->contextualizedShareUrl((string) $target['share_url'], $shareSource);

        $claim = MarketingSocialShareClaim::query()->firstOrNew([
            'marketing_profile_id' => $profile->id,
            'platform' => $platform,
            'target_type' => $target['type'],
            'target_id' => $target['id'],
        ]);

        $claim->forceFill([
            'tenant_id' => $this->tenantId($profile),
            'share_url' => $shareUrl,
            'started_at' => $claim->started_at ?: now(),
            'metadata' => $this->claimMetadata($target, [
                ...$context,
                'share_mode' => $shareMode,
                'share_source' => $shareSource,
            ]),
        ]);

        if (! $claim->exists || ! $claim->claimed_at) {
            $claim->status = 'started';
        }

        $claim->save();

        $this->eventLogger->log('social_share_started', [
            'status' => 'ok',
            'profile' => $profile,
            'source_surface' => (string) ($context['surface'] ?? 'social_share'),
            'endpoint' => (string) ($context['endpoint'] ?? null),
            'source_type' => 'modern_forestry_social_share',
            'source_id' => $platform.':'.$target['type'].':'.$target['id'],
            'meta' => [
                'platform' => $platform,
                'target_type' => $target['type'],
                'target_id' => $target['id'],
                'share_url' => $shareUrl,
                'title' => $target['title'] ?? null,
                'share_mode' => $shareMode,
                'share_source' => $shareSource,
            ],
            'resolution_status' => 'resolved',
        ]);

        return $this->claimPayload($claim->fresh(), (bool) $claim->candle_cash_transaction_id);
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array<string,mixed>
     */
    public function claim(MarketingProfile $profile, string $platform, array $target, array $payload = [], array $context = []): array
    {
        $platform = $this->normalizePlatform($platform);
        $target = $this->normalizeTarget($profile, $target);
        $tenantId = $this->tenantId($profile);
        $sourceId = $this->sourceId($profile, $platform, $target);
        $shareMode = $this->normalizeShareMode($context['share_mode'] ?? null);
        $shareSource = $this->shareSource($platform, $shareMode);
        $shareUrl = $this->contextualizedShareUrl((string) $target['share_url'], $shareSource);

        return DB::transaction(function () use ($profile, $platform, $target, $payload, $context, $tenantId, $sourceId, $shareMode, $shareSource, $shareUrl): array {
            $claim = MarketingSocialShareClaim::query()
                ->where('marketing_profile_id', $profile->id)
                ->where('platform', $platform)
                ->where('target_type', $target['type'])
                ->where('target_id', $target['id'])
                ->lockForUpdate()
                ->first();

            if (! $claim instanceof MarketingSocialShareClaim) {
                $claim = MarketingSocialShareClaim::query()->create([
                    'tenant_id' => $tenantId,
                    'marketing_profile_id' => $profile->id,
                    'platform' => $platform,
                    'target_type' => $target['type'],
                    'target_id' => $target['id'],
                    'share_url' => $shareUrl,
                    'status' => 'started',
                    'started_at' => now(),
                    'metadata' => $this->claimMetadata($target, [
                        ...$context,
                        'share_mode' => $shareMode,
                        'share_source' => $shareSource,
                    ]),
                ]);
            }

            $award = $this->candleCashService->addPointsIdempotent(
                profile: $profile,
                points: self::REWARD_CANDLE_CASH,
                source: 'social_share_reward',
                sourceId: $sourceId,
                type: 'earn',
                description: 'Social share reward'
            );

            $claim->forceFill([
                'share_url' => $shareUrl,
                'status' => ((bool) ($award['already_awarded'] ?? false)) ? 'already_awarded' : 'awarded',
                'proof_url' => $this->nullableString($payload['proof_url'] ?? null),
                'proof_text' => $this->nullableString($payload['proof_text'] ?? null),
                'claimed_at' => now(),
                'awarded_at' => $claim->awarded_at ?: now(),
                'candle_cash_transaction_id' => (int) ($award['transaction_id'] ?? 0) ?: $claim->candle_cash_transaction_id,
                'metadata' => $this->claimMetadata($target, [
                    ...$context,
                    'share_mode' => $shareMode,
                    'share_source' => $shareSource,
                    'already_awarded' => (bool) ($award['already_awarded'] ?? false),
                ]),
            ])->save();

            $this->eventLogger->log('social_share_claimed', [
                'status' => 'ok',
                'profile' => $profile,
                'source_surface' => (string) ($context['surface'] ?? 'social_share'),
                'endpoint' => (string) ($context['endpoint'] ?? null),
                'source_type' => 'modern_forestry_social_share',
                'source_id' => $platform.':'.$target['type'].':'.$target['id'],
                'meta' => [
                    'platform' => $platform,
                    'target_type' => $target['type'],
                    'target_id' => $target['id'],
                    'share_url' => $shareUrl,
                    'already_awarded' => (bool) ($award['already_awarded'] ?? false),
                    'transaction_id' => (int) ($award['transaction_id'] ?? 0),
                    'share_mode' => $shareMode,
                    'share_source' => $shareSource,
                ],
                'resolution_status' => 'resolved',
            ]);

            return $this->claimPayload($claim->fresh(), (bool) ($award['already_awarded'] ?? false));
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function scentPersonalityTarget(MarketingProfile $profile): ?array
    {
        $result = $profile->relationLoaded('scentQuizResult')
            ? $profile->scentQuizResult
            : $profile->scentQuizResult()->first();

        if (! $result instanceof MarketingProfileScentQuizResult) {
            return null;
        }

        $token = $this->ensureScentShareToken($result);
        $revision = $result->publicShareRevision();
        $url = route('marketing.public.scent-personality-share', [
            'token' => $token,
            'v' => $revision,
            'card' => $result->publicShareCardVersion(),
        ]);

        return [
            'type' => 'scent_personality',
            'id' => 'scent-result:'.$result->id,
            'share_url' => $url,
            'title' => (string) ($result->personality_title ?: $result->headline ?: 'My Modern Forestry scent personality'),
            'body' => (string) ($result->personality_body ?: 'I took the Modern Forestry candle personality quiz and found the scent profile that fits me best.'),
        ];
    }

    public function ensureScentShareToken(MarketingProfileScentQuizResult $result): string
    {
        if (trim((string) $result->public_share_token) !== '') {
            return (string) $result->public_share_token;
        }

        do {
            $token = Str::lower(Str::random(40));
        } while (MarketingProfileScentQuizResult::query()->where('public_share_token', $token)->exists());

        $result->forceFill(['public_share_token' => $token])->save();

        return $token;
    }

    /**
     * @return array<string,mixed>
     */
    public function analytics(int $tenantId = 1): array
    {
        $base = MarketingSocialShareClaim::query()->where('tenant_id', $tenantId);
        $recent = (clone $base)->where('created_at', '>=', now()->subDays(30));

        return [
            'starts' => (int) (clone $recent)->whereNotNull('started_at')->count(),
            'claims' => (int) (clone $recent)->whereNotNull('claimed_at')->count(),
            'awards' => (int) (clone $recent)->whereNotNull('candle_cash_transaction_id')->count(),
            'duplicates' => (int) (clone $recent)->where('status', 'already_awarded')->count(),
            'platforms' => $this->countsBy($recent, 'platform'),
            'targets' => $this->countsBy($recent, 'target_type'),
            'top_shared_products' => $this->topSharedTargets($recent, ['product', 'purchased_product']),
            'top_shared_scent_personalities' => $this->topSharedTargets($recent, ['scent_personality']),
        ];
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array{type:string,id:string,share_url:string,title:?string,body:?string,image_url:?string}
     */
    protected function normalizeTarget(MarketingProfile $profile, array $target): array
    {
        $type = strtolower(trim((string) ($target['type'] ?? $target['target_type'] ?? '')));
        if (! in_array($type, self::TARGET_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported social share target.');
        }

        if ($type === 'scent_personality') {
            $scentTarget = $this->scentPersonalityTarget($profile);
            if (! is_array($scentTarget)) {
                throw new InvalidArgumentException('Take the scent quiz before sharing your candle personality.');
            }

            return [
                'type' => 'scent_personality',
                'id' => (string) $scentTarget['id'],
                'share_url' => (string) $scentTarget['share_url'],
                'title' => (string) $scentTarget['title'],
                'body' => (string) $scentTarget['body'],
                'image_url' => null,
            ];
        }

        $handle = Str::slug((string) ($target['handle'] ?? $target['product_handle'] ?? ''));
        $targetId = trim((string) ($target['id'] ?? $target['target_id'] ?? ''));
        if ($targetId === '' && $type === 'product' && $handle !== '') {
            $targetId = 'product:'.$handle;
        }
        if ($targetId === '' || $handle === '') {
            throw new InvalidArgumentException('Product share target is missing product context.');
        }

        $shareUrl = $this->productShareUrl($handle);

        return [
            'type' => $type,
            'id' => $targetId,
            'share_url' => $shareUrl,
            'title' => $this->nullableString($target['title'] ?? null) ?? $this->productShareTitle($handle),
            'body' => $this->nullableString($target['body'] ?? null) ?? $this->productShareBody($target['title'] ?? $handle),
            'image_url' => $this->nullableString($target['image_url'] ?? $target['imageUrl'] ?? null),
        ];
    }

    protected function productShareUrl(string $handle): string
    {
        return route('marketing.public.product-share', [
            'handle' => ltrim($handle, '/'),
        ]);
    }

    protected function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (! in_array($platform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException('Unsupported social share platform.');
        }

        return $platform;
    }

    /**
     * @param  array<string,mixed>  $target
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function claimMetadata(array $target, array $context): array
    {
        return array_filter([
            'title' => $target['title'] ?? null,
            'body' => $target['body'] ?? null,
            'image_url' => $target['image_url'] ?? null,
            'surface' => $context['surface'] ?? null,
            'share_mode' => $context['share_mode'] ?? null,
            'share_source' => $context['share_source'] ?? null,
            'already_awarded' => $context['already_awarded'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function normalizeShareMode(mixed $value): string
    {
        $mode = Str::lower(trim((string) $value));

        return in_array($mode, self::SHARE_MODES, true) ? $mode : 'generic';
    }

    protected function shareSource(string $platform, string $shareMode): string
    {
        return match ([$platform, $shareMode]) {
            ['facebook', 'story'] => 'facebook_story',
            ['facebook', 'post'] => 'facebook_post',
            ['instagram', 'story'] => 'instagram_story',
            ['instagram', 'copy_link'] => 'copied_link',
            default => 'generic_share',
        };
    }

    protected function contextualizedShareUrl(string $shareUrl, string $shareSource): string
    {
        $resolved = trim($shareUrl);
        if ($resolved === '') {
            return $resolved;
        }

        $separator = str_contains($resolved, '?') ? '&' : '?';

        return $resolved.$separator.http_build_query([
            'source' => $shareSource,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function claimPayload(MarketingSocialShareClaim $claim, bool $alreadyAwarded): array
    {
        $balance = $claim->profile instanceof MarketingProfile
            ? $this->candleCashService->currentBalance($claim->profile)
            : 0;

        return [
            'ok' => true,
            'state' => $alreadyAwarded ? 'already_awarded' : (string) $claim->status,
            'alreadyAwarded' => $alreadyAwarded,
            'reward' => [
                'amount' => self::REWARD_CANDLE_CASH,
                'formatted' => $this->candleCashService->formatCurrency(self::REWARD_CANDLE_CASH),
                'label' => '$1 Candle Cash',
            ],
            'claim' => [
                'id' => (int) $claim->id,
                'platform' => (string) $claim->platform,
                'targetType' => (string) $claim->target_type,
                'targetId' => (string) $claim->target_id,
                'shareUrl' => (string) $claim->share_url,
                'status' => (string) $claim->status,
                'claimedAt' => optional($claim->claimed_at)->toIso8601String(),
                'awardedAt' => optional($claim->awarded_at)->toIso8601String(),
            ],
            'balance' => $this->candleCashService->balancePayloadFromPoints($balance),
        ];
    }

    protected function sourceId(MarketingProfile $profile, string $platform, array $target): string
    {
        return Str::lower('profile:'.$profile->id.'|platform:'.$platform.'|target:'.$target['type'].':'.$target['id']);
    }

    protected function tenantId(MarketingProfile $profile): int
    {
        return max(1, (int) ($profile->tenant_id ?? 1));
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function productShareTitle(string $handle): string
    {
        return Str::headline(str_replace(['-', '_'], ' ', $handle)).' from Modern Forestry';
    }

    protected function productShareBody(mixed $title): string
    {
        $name = trim((string) $title);

        if ($name === '') {
            $name = 'this candle';
        }

        return 'I found '.$name.' from Modern Forestry and thought it belonged on your candle radar.';
    }

    /**
     * @return array<int,array{label:string,count:int}>
     */
    protected function countsBy($query, string $field): array
    {
        /** @var EloquentCollection<int,MarketingSocialShareClaim> $rows */
        $rows = (clone $query)
            ->selectRaw($field.', COUNT(*) as aggregate')
            ->groupBy($field)
            ->orderByDesc('aggregate')
            ->get();

        return $rows
            ->map(fn (MarketingSocialShareClaim $claim): array => [
                'label' => (string) ($claim->{$field} ?? 'unknown'),
                'count' => (int) ($claim->aggregate ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $targetTypes
     * @return array<int,array{target_type:string,target_id:string,title:string,count:int}>
     */
    protected function topSharedTargets($query, array $targetTypes): array
    {
        /** @var EloquentCollection<int,MarketingSocialShareClaim> $rows */
        $rows = (clone $query)
            ->whereIn('target_type', $targetTypes)
            ->orderByDesc('created_at')
            ->get(['target_type', 'target_id', 'metadata']);

        return $rows
            ->groupBy(fn (MarketingSocialShareClaim $claim): string => $claim->target_type.'|'.$claim->target_id)
            ->map(function ($claims): array {
                /** @var MarketingSocialShareClaim|null $first */
                $first = $claims->first();
                $title = trim((string) data_get($first?->metadata, 'title'));

                return [
                    'target_type' => (string) ($first?->target_type ?? 'unknown'),
                    'target_id' => (string) ($first?->target_id ?? 'unknown'),
                    'title' => $title !== '' ? $title : (string) ($first?->target_id ?? 'Shared item'),
                    'count' => $claims->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(8)
            ->values()
            ->all();
    }
}
