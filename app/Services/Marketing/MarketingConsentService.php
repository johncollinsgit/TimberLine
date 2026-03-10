<?php

namespace App\Services\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;

class MarketingConsentService
{
    /**
     * @param array<string,mixed> $incoming
     * @param array<string,mixed> $context
     */
    public function applyToProfile(MarketingProfile $profile, array $incoming, array $context = []): bool
    {
        $changed = false;

        $emailOptIn = $this->nullableBool($incoming['accepts_email_marketing'] ?? null);
        $smsOptIn = $this->nullableBool($incoming['accepts_sms_marketing'] ?? null);
        $emailOptOutAt = $this->asNullableDate($incoming['email_opted_out_at'] ?? null);
        $smsOptOutAt = $this->asNullableDate($incoming['sms_opted_out_at'] ?? null);

        $before = [
            'email' => (bool) $profile->accepts_email_marketing,
            'sms' => (bool) $profile->accepts_sms_marketing,
            'email_opted_out_at' => $profile->email_opted_out_at,
            'sms_opted_out_at' => $profile->sms_opted_out_at,
        ];

        // Explicit opt-out always wins.
        if ($emailOptIn === false || $emailOptOutAt !== null) {
            if ($profile->accepts_email_marketing !== false) {
                $profile->accepts_email_marketing = false;
                $changed = true;
            }
            if ($profile->email_opted_out_at === null || ($emailOptOutAt && $profile->email_opted_out_at != $emailOptOutAt)) {
                $profile->email_opted_out_at = $emailOptOutAt ?: CarbonImmutable::now();
                $changed = true;
            }
        } elseif ($emailOptIn === true) {
            // Do not override an explicit prior opt-out with weaker imported opt-in.
            if (! $profile->email_opted_out_at && $profile->accepts_email_marketing !== true) {
                $profile->accepts_email_marketing = true;
                $changed = true;
            }
        }

        if ($smsOptIn === false || $smsOptOutAt !== null) {
            if ($profile->accepts_sms_marketing !== false) {
                $profile->accepts_sms_marketing = false;
                $changed = true;
            }
            if ($profile->sms_opted_out_at === null || ($smsOptOutAt && $profile->sms_opted_out_at != $smsOptOutAt)) {
                $profile->sms_opted_out_at = $smsOptOutAt ?: CarbonImmutable::now();
                $changed = true;
            }
        } elseif ($smsOptIn === true) {
            if (! $profile->sms_opted_out_at && $profile->accepts_sms_marketing !== true) {
                $profile->accepts_sms_marketing = true;
                $changed = true;
            }
        }

        if ($changed) {
            $profile->save();
            $this->recordChangeEvents($profile, $before, $context);
        }

        return $changed;
    }

    /**
     * Explicit/manual consent updates that can supersede prior imported values.
     *
     * @param array<string,mixed> $context
     */
    public function setSmsConsent(MarketingProfile $profile, bool $consented, array $context = []): bool
    {
        $changed = false;
        $before = (bool) $profile->accepts_sms_marketing;

        if ($consented) {
            if (! $before || $profile->sms_opted_out_at !== null) {
                $profile->accepts_sms_marketing = true;
                $profile->sms_opted_out_at = null;
                $changed = true;
            }
        } else {
            if ($before || $profile->sms_opted_out_at === null) {
                $profile->accepts_sms_marketing = false;
                $profile->sms_opted_out_at = CarbonImmutable::now();
                $changed = true;
            }
        }

        if (! $changed) {
            return false;
        }

        $profile->save();

        $this->recordConsentEvent(
            profile: $profile,
            channel: 'sms',
            eventType: $consented ? 'confirmed' : 'revoked',
            context: [
                ...$context,
                'details' => [
                    'previous' => $before,
                    'current' => $consented,
                    ...((array) ($context['details'] ?? [])),
                ],
            ]
        );

        return true;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function setEmailConsent(MarketingProfile $profile, bool $consented, array $context = []): bool
    {
        $changed = false;
        $before = (bool) $profile->accepts_email_marketing;

        if ($consented) {
            if (! $before || $profile->email_opted_out_at !== null) {
                $profile->accepts_email_marketing = true;
                $profile->email_opted_out_at = null;
                $changed = true;
            }
        } else {
            if ($before || $profile->email_opted_out_at === null) {
                $profile->accepts_email_marketing = false;
                $profile->email_opted_out_at = CarbonImmutable::now();
                $changed = true;
            }
        }

        if (! $changed) {
            return false;
        }

        $profile->save();

        $this->recordConsentEvent(
            profile: $profile,
            channel: 'email',
            eventType: $consented ? 'confirmed' : 'revoked',
            context: [
                ...$context,
                'details' => [
                    'previous' => $before,
                    'current' => $consented,
                    ...((array) ($context['details'] ?? [])),
                ],
            ]
        );

        return true;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $context
     */
    protected function recordChangeEvents(MarketingProfile $profile, array $before, array $context): void
    {
        $emailAfter = (bool) $profile->accepts_email_marketing;
        $smsAfter = (bool) $profile->accepts_sms_marketing;

        if ($before['email'] !== $emailAfter) {
            $this->recordConsentEvent(
                profile: $profile,
                channel: 'email',
                eventType: $this->eventTypeForTransition($emailAfter, $context),
                context: [
                    ...$context,
                    'details' => [
                        'previous' => (bool) $before['email'],
                        'current' => $emailAfter,
                        ...((array) ($context['details'] ?? [])),
                    ],
                ]
            );
        }

        if ($before['sms'] !== $smsAfter) {
            $this->recordConsentEvent(
                profile: $profile,
                channel: 'sms',
                eventType: $this->eventTypeForTransition($smsAfter, $context),
                context: [
                    ...$context,
                    'details' => [
                        'previous' => (bool) $before['sms'],
                        'current' => $smsAfter,
                        ...((array) ($context['details'] ?? [])),
                    ],
                ]
            );
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function eventTypeForTransition(bool $current, array $context): string
    {
        if (! $current) {
            return 'opted_out';
        }

        $sourceType = strtolower(trim((string) ($context['source_type'] ?? '')));
        if (str_contains($sourceType, 'import') || str_contains($sourceType, 'sync')) {
            return 'imported';
        }

        return 'opted_in';
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function recordConsentEvent(
        MarketingProfile $profile,
        string $channel,
        string $eventType,
        array $context = []
    ): void {
        $sourceType = trim((string) ($context['source_type'] ?? '')) ?: null;
        $sourceId = trim((string) ($context['source_id'] ?? '')) ?: null;
        $details = is_array($context['details'] ?? null) ? $context['details'] : null;

        MarketingConsentEvent::query()->create([
            'marketing_profile_id' => $profile->id,
            'channel' => strtolower(trim($channel)),
            'event_type' => strtolower(trim($eventType)),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'details' => $details,
            'occurred_at' => $this->asNullableDate($context['occurred_at'] ?? null) ?: now(),
        ]);
    }

    protected function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $string = strtolower(trim((string) $value));
        if ($string === '') {
            return null;
        }

        if (in_array($string, ['true', 'yes', 'y', 'opt_in', 'subscribed', 'subscribed_true'], true)) {
            return true;
        }

        if (in_array($string, ['false', 'no', 'n', 'opt_out', 'unsubscribed', 'unsubscribed_true'], true)) {
            return false;
        }

        return null;
    }

    protected function asNullableDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\DateTime::createFromInterface($value));
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }
}
