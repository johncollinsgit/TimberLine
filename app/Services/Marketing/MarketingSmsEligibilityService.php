<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MessagingContactChannelState;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Collection;

class MarketingSmsEligibilityService
{
    /**
     * @var array<int,string>
     */
    protected const SUPPRESSED_STATUSES = ['unsubscribed', 'suppressed', 'bounced'];

    public function __construct(
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    /**
     * @return array{
     *   eligible:bool,
     *   reason_codes:array<int,string>,
     *   blocking_reason:?string,
     *   note:?string,
     *   normalized_phone:?string,
     *   sms_status:string,
     *   sms_status_reason:?string
     * }
     */
    public function evaluateProfile(MarketingProfile $profile, ?int $tenantId = null): array
    {
        $evaluated = $this->evaluateProfiles(collect([$profile]), $tenantId);

        /** @var array{
         *   eligible:bool,
         *   reason_codes:array<int,string>,
         *   blocking_reason:?string,
         *   note:?string,
         *   normalized_phone:?string,
         *   sms_status:string,
         *   sms_status_reason:?string
         * } $fallback
         */
        $fallback = [
            'eligible' => false,
            'reason_codes' => ['missing_profile'],
            'blocking_reason' => 'missing_profile',
            'note' => 'Recipient profile could not be resolved.',
            'normalized_phone' => null,
            'sms_status' => 'unknown',
            'sms_status_reason' => null,
        ];

        return $evaluated->get((int) $profile->id, $fallback);
    }

    /**
     * @param iterable<int,MarketingProfile> $profiles
     * @return Collection<int,array{
     *   eligible:bool,
     *   reason_codes:array<int,string>,
     *   blocking_reason:?string,
     *   note:?string,
     *   normalized_phone:?string,
     *   sms_status:string,
     *   sms_status_reason:?string
     * }>
     */
    public function evaluateProfiles(iterable $profiles, ?int $tenantId = null): Collection
    {
        $collection = collect($profiles)
            ->filter(fn ($profile): bool => $profile instanceof MarketingProfile)
            ->values();

        if ($collection->isEmpty()) {
            return collect();
        }

        $profileIds = $collection
            ->map(fn (MarketingProfile $profile): int => (int) $profile->id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $phones = $collection
            ->map(fn (MarketingProfile $profile): ?string => $this->identityNormalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone)))
            ->filter(fn (?string $phone): bool => $phone !== null)
            ->values();

        $statesQuery = MessagingContactChannelState::query()
            ->select(['marketing_profile_id', 'phone', 'sms_status', 'sms_status_reason'])
            ->where(function ($query) use ($profileIds, $phones): void {
                if ($profileIds->isNotEmpty()) {
                    $query->whereIn('marketing_profile_id', $profileIds->all());
                }

                if ($phones->isNotEmpty()) {
                    $query->orWhereIn('phone', $phones->all());
                }
            });

        if ($tenantId !== null) {
            $statesQuery->forTenantId($tenantId);
        }

        $states = $statesQuery->get();

        $stateByProfileId = $states
            ->filter(fn (MessagingContactChannelState $state): bool => (int) ($state->marketing_profile_id ?? 0) > 0)
            ->keyBy(fn (MessagingContactChannelState $state): int => (int) $state->marketing_profile_id);

        $stateByPhone = $states
            ->filter(fn (MessagingContactChannelState $state): bool => trim((string) $state->phone) !== '')
            ->keyBy(fn (MessagingContactChannelState $state): string => (string) $state->phone);

        return $collection->mapWithKeys(function (MarketingProfile $profile) use ($stateByProfileId, $stateByPhone): array {
            $normalizedPhone = $this->identityNormalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone));
            $reasonCodes = [];

            if (! (bool) $profile->accepts_sms_marketing) {
                $reasonCodes[] = 'sms_not_consented';
            }

            if ($normalizedPhone === null) {
                $reasonCodes[] = 'missing_phone';
            }

            $state = $this->resolveChannelState($profile, $normalizedPhone, $stateByProfileId, $stateByPhone);
            if (in_array($state['status'], self::SUPPRESSED_STATUSES, true)) {
                $reasonCodes[] = $this->reasonCodeForSuppression($state['status'], $state['reason']);
            }

            $reasonCodes = collect($reasonCodes)
                ->map(fn ($value): string => strtolower(trim((string) $value)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return [
                (int) $profile->id => [
                    'eligible' => $reasonCodes === [],
                    'reason_codes' => $reasonCodes,
                    'blocking_reason' => $reasonCodes[0] ?? null,
                    'note' => $this->blockingNote($reasonCodes, $state['status'], $state['reason']),
                    'normalized_phone' => $normalizedPhone,
                    'sms_status' => $state['status'],
                    'sms_status_reason' => $state['reason'],
                ],
            ];
        });
    }

    /**
     * @param Collection<int,MessagingContactChannelState> $stateByProfileId
     * @param Collection<string,MessagingContactChannelState> $stateByPhone
     * @return array{status:string,reason:?string}
     */
    protected function resolveChannelState(
        MarketingProfile $profile,
        ?string $normalizedPhone,
        Collection $stateByProfileId,
        Collection $stateByPhone
    ): array {
        $state = $stateByProfileId->get((int) $profile->id);

        if (! $state instanceof MessagingContactChannelState && $normalizedPhone !== null) {
            $state = $stateByPhone->get($normalizedPhone);
        }

        $status = strtolower(trim((string) ($state?->sms_status ?? '')));
        if ($status === '') {
            $status = (bool) ($profile->accepts_sms_marketing ?? false) ? 'subscribed' : 'unknown';
        }

        $reason = trim((string) ($state?->sms_status_reason ?? ''));

        return [
            'status' => $status,
            'reason' => $reason !== '' ? $reason : null,
        ];
    }

    protected function reasonCodeForSuppression(string $status, ?string $reason): string
    {
        $status = strtolower(trim($status));

        if ($status === 'unsubscribed') {
            $reason = strtolower(trim((string) $reason));

            if (in_array($reason, ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit'], true)) {
                return 'sms_stop_suppressed';
            }

            return 'sms_unsubscribed';
        }

        return match ($status) {
            'suppressed' => 'sms_suppressed',
            'bounced' => 'sms_bounced',
            default => 'sms_suppressed',
        };
    }

    /**
     * @param array<int,string> $reasonCodes
     */
    protected function blockingNote(array $reasonCodes, string $status, ?string $reason): ?string
    {
        if ($reasonCodes === []) {
            return null;
        }

        return match ($reasonCodes[0]) {
            'sms_not_consented' => 'SMS consent is no longer active.',
            'missing_phone' => 'Recipient has no sendable phone number.',
            'sms_stop_suppressed' => 'SMS reply suppression is active from STOP/UNSUBSCRIBE history.',
            'sms_unsubscribed' => 'Recipient is marked unsubscribed for SMS.',
            'sms_bounced' => 'Recipient is marked as bounced for SMS and cannot be sent.',
            'sms_suppressed' => 'Recipient is suppressed for SMS and cannot be sent.',
            default => sprintf(
                'SMS cannot be sent while channel status is %s%s.',
                $status,
                $reason ? " ({$reason})" : ''
            ),
        };
    }
}
