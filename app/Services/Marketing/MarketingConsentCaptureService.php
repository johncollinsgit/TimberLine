<?php

namespace App\Services\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingConsentRequest;
use App\Models\MarketingProfile;
use Illuminate\Support\Str;

class MarketingConsentCaptureService
{
    public function __construct(
        protected MarketingProfileSyncService $profileSyncService,
        protected MarketingConsentService $consentService,
        protected MarketingConsentIncentiveService $incentiveService
    ) {
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $context
     * @return array{status:string,profile:?MarketingProfile,request:?MarketingConsentRequest,token:?string,sync:array<string,mixed>}
     */
    public function requestSmsConfirmation(array $identity, array $context = []): array
    {
        $sourceType = trim((string) ($context['source_type'] ?? 'storefront_consent'));
        $sourceId = trim((string) ($context['source_id'] ?? ('consent:' . Str::lower(Str::random(24)))));
        $sourceChannels = array_values(array_filter((array) ($context['source_channels'] ?? ['storefront'])));
        $expiresMinutes = max(5, min(240, (int) ($context['expires_minutes'] ?? 45)));

        $sync = $this->profileSyncService->syncExternalIdentity([
            'first_name' => trim((string) ($identity['first_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($identity['last_name'] ?? '')) ?: null,
            'raw_email' => trim((string) ($identity['email'] ?? $identity['raw_email'] ?? '')) ?: null,
            'raw_phone' => trim((string) ($identity['phone'] ?? $identity['raw_phone'] ?? '')) ?: null,
            'source_channels' => $sourceChannels,
            'source_links' => [[
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_meta' => is_array($context['source_meta'] ?? null) ? $context['source_meta'] : [],
            ]],
            'primary_source' => [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
        ], [
            'review_context' => [
                'source_label' => (string) ($context['source_label'] ?? $sourceType),
                'source_id' => $sourceId,
                ...((array) ($context['review_context'] ?? [])),
            ],
            'allow_create' => (bool) ($context['allow_create'] ?? true),
        ]);

        $profile = null;
        if ((int) ($sync['profile_id'] ?? 0) > 0) {
            $profile = MarketingProfile::query()->find((int) $sync['profile_id']);
        }

        if (! $profile) {
            return [
                'status' => 'review_required',
                'profile' => null,
                'request' => null,
                'token' => null,
                'sync' => $sync,
            ];
        }

        $existing = MarketingConsentRequest::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('channel', 'sms')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'requested')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($existing) {
            return [
                'status' => 'requested',
                'profile' => $profile,
                'request' => $existing,
                'token' => (string) $existing->token,
                'sync' => $sync,
            ];
        }

        $token = Str::lower(Str::random(48));
        $request = MarketingConsentRequest::query()->create([
            'marketing_profile_id' => $profile->id,
            'channel' => 'sms',
            'token' => $token,
            'status' => 'requested',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'payload' => [
                'award_bonus' => (bool) ($context['award_bonus'] ?? false),
                'flow' => (string) ($context['flow'] ?? 'verification'),
                'source_meta' => (array) ($context['source_meta'] ?? []),
                'request_meta' => (array) ($context['request_meta'] ?? []),
            ],
            'requested_at' => now(),
            'expires_at' => now()->addMinutes($expiresMinutes),
        ]);

        MarketingConsentEvent::query()->create([
            'marketing_profile_id' => $profile->id,
            'channel' => 'sms',
            'event_type' => 'requested',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'details' => [
                'request_id' => (int) $request->id,
                'flow' => (string) ($context['flow'] ?? 'verification'),
            ],
            'occurred_at' => now(),
        ]);

        return [
            'status' => 'requested',
            'profile' => $profile,
            'request' => $request,
            'token' => $token,
            'sync' => $sync,
        ];
    }

    /**
     * @return array{status:string,profile:?MarketingProfile,request:?MarketingConsentRequest,bonus_awarded:int,error:?string}
     */
    public function confirmSmsByToken(string $token, array $context = []): array
    {
        $token = Str::lower(trim($token));
        if ($token === '') {
            return [
                'status' => 'invalid',
                'profile' => null,
                'request' => null,
                'bonus_awarded' => 0,
                'error' => 'missing_token',
            ];
        }

        $request = MarketingConsentRequest::query()
            ->where('token', $token)
            ->where('channel', 'sms')
            ->first();

        if (! $request) {
            return [
                'status' => 'invalid',
                'profile' => null,
                'request' => null,
                'bonus_awarded' => 0,
                'error' => 'token_not_found',
            ];
        }

        $profile = $request->marketing_profile_id
            ? MarketingProfile::query()->find((int) $request->marketing_profile_id)
            : null;
        if (! $profile) {
            return [
                'status' => 'invalid',
                'profile' => null,
                'request' => $request,
                'bonus_awarded' => 0,
                'error' => 'profile_not_found',
            ];
        }

        if ($request->status === 'confirmed') {
            return [
                'status' => 'confirmed',
                'profile' => $profile,
                'request' => $request,
                'bonus_awarded' => (int) ($request->reward_awarded_points ?? 0),
                'error' => null,
            ];
        }

        if ($request->status !== 'requested') {
            return [
                'status' => 'invalid',
                'profile' => $profile,
                'request' => $request,
                'bonus_awarded' => 0,
                'error' => 'request_not_confirmable',
            ];
        }

        if ($request->expires_at && $request->expires_at->isPast()) {
            $request->forceFill(['status' => 'expired'])->save();

            return [
                'status' => 'expired',
                'profile' => $profile,
                'request' => $request->fresh(),
                'bonus_awarded' => 0,
                'error' => 'token_expired',
            ];
        }

        $sourceType = trim((string) ($context['source_type'] ?? 'consent_confirm'));
        $sourceId = trim((string) ($context['source_id'] ?? $request->source_id ?: ('consent-confirm:' . $request->id)));

        $this->consentService->setSmsConsent($profile, true, [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'details' => [
                'request_id' => (int) $request->id,
                'flow' => 'verification_confirm',
            ],
        ]);

        $bonusAwarded = 0;
        $awardBonus = (bool) data_get((array) $request->payload, 'award_bonus', false);
        if ($awardBonus && ! $request->reward_awarded_at) {
            $bonus = $this->incentiveService->awardSmsConsentBonusOnce(
                profile: $profile,
                sourceId: $request->source_id ?: ('consent-request:' . $request->id),
                description: (string) ($context['bonus_description'] ?? 'SMS consent confirmation bonus')
            );
            if ($bonus['awarded']) {
                $bonusAwarded = (int) $bonus['points'];
            }
        }

        $request->forceFill([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'reward_awarded_points' => $bonusAwarded > 0 ? $bonusAwarded : (int) ($request->reward_awarded_points ?? 0),
            'reward_awarded_at' => $bonusAwarded > 0 ? now() : $request->reward_awarded_at,
        ])->save();

        return [
            'status' => 'confirmed',
            'profile' => $profile->fresh(),
            'request' => $request->fresh(),
            'bonus_awarded' => $bonusAwarded,
            'error' => null,
        ];
    }
}

