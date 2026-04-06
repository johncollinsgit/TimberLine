<?php

namespace App\Services\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\MessagingContactChannelState;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;

class MessagingContactChannelStateService
{
    public function __construct(
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    public function resolveSmsStatus(int $tenantId, ?MarketingProfile $profile = null, ?string $phone = null): string
    {
        $state = $this->findState($tenantId, $profile, $phone, null);
        if ($state instanceof MessagingContactChannelState && $this->nullableString($state->sms_status) !== null) {
            return (string) $state->sms_status;
        }

        if ($profile instanceof MarketingProfile) {
            return (bool) ($profile->accepts_sms_marketing ?? false) ? 'subscribed' : 'unknown';
        }

        return 'unknown';
    }

    public function resolveEmailStatus(int $tenantId, ?MarketingProfile $profile = null, ?string $email = null): string
    {
        $state = $this->findState($tenantId, $profile, null, $email);
        if ($state instanceof MessagingContactChannelState && $this->nullableString($state->email_status) !== null) {
            return (string) $state->email_status;
        }

        if ($profile instanceof MarketingProfile) {
            return (bool) ($profile->accepts_email_marketing ?? false) ? 'subscribed' : 'unknown';
        }

        return 'unknown';
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function markSmsUnsubscribed(
        int $tenantId,
        ?MarketingProfile $profile,
        ?string $phone,
        string $reason,
        ?string $providerSource = null,
        array $metadata = [],
        mixed $occurredAt = null
    ): MessagingContactChannelState {
        $timestamp = $this->asDate($occurredAt) ?? now();
        $state = $this->upsertState(
            tenantId: $tenantId,
            profile: $profile,
            phone: $phone,
            email: null,
            attributes: [
                'sms_status' => 'unsubscribed',
                'sms_status_reason' => $reason,
                'sms_status_changed_at' => $timestamp,
                'provider_source' => $providerSource,
                'metadata' => $metadata,
            ]
        );

        if ($profile instanceof MarketingProfile) {
            $profile->forceFill([
                'accepts_sms_marketing' => false,
                'sms_opted_out_at' => $profile->sms_opted_out_at ?? $timestamp,
            ])->save();

            $this->recordConsentEvent($tenantId, $profile, 'sms', 'unsubscribed', $reason, $providerSource, $metadata, $timestamp);
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function markSmsSubscribed(
        int $tenantId,
        ?MarketingProfile $profile,
        ?string $phone,
        string $reason,
        ?string $providerSource = null,
        array $metadata = [],
        mixed $occurredAt = null
    ): MessagingContactChannelState {
        $timestamp = $this->asDate($occurredAt) ?? now();
        $state = $this->upsertState(
            tenantId: $tenantId,
            profile: $profile,
            phone: $phone,
            email: null,
            attributes: [
                'sms_status' => 'subscribed',
                'sms_status_reason' => $reason,
                'sms_status_changed_at' => $timestamp,
                'provider_source' => $providerSource,
                'metadata' => $metadata,
            ]
        );

        if ($profile instanceof MarketingProfile) {
            $profile->forceFill([
                'accepts_sms_marketing' => true,
            ])->save();

            $this->recordConsentEvent($tenantId, $profile, 'sms', 'subscribed', $reason, $providerSource, $metadata, $timestamp);
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function markEmailStatus(
        int $tenantId,
        ?MarketingProfile $profile,
        ?string $email,
        string $status,
        string $reason,
        ?string $providerSource = null,
        array $metadata = [],
        mixed $occurredAt = null
    ): MessagingContactChannelState {
        $timestamp = $this->asDate($occurredAt) ?? now();
        $normalizedStatus = in_array($status, ['subscribed', 'unsubscribed', 'bounced', 'suppressed', 'unknown'], true)
            ? $status
            : 'unknown';

        $state = $this->upsertState(
            tenantId: $tenantId,
            profile: $profile,
            phone: null,
            email: $email,
            attributes: [
                'email_status' => $normalizedStatus,
                'email_status_reason' => $reason,
                'email_status_changed_at' => $timestamp,
                'provider_source' => $providerSource,
                'metadata' => $metadata,
            ]
        );

        if ($profile instanceof MarketingProfile) {
            $profileAttributes = [];
            if (in_array($normalizedStatus, ['unsubscribed', 'suppressed', 'bounced'], true)) {
                $profileAttributes['accepts_email_marketing'] = false;
                if ($normalizedStatus === 'unsubscribed') {
                    $profileAttributes['email_opted_out_at'] = $profile->email_opted_out_at ?? $timestamp;
                }
            } elseif ($normalizedStatus === 'subscribed') {
                $profileAttributes['accepts_email_marketing'] = true;
            }

            if ($profileAttributes !== []) {
                $profile->forceFill($profileAttributes)->save();
            }

            if (in_array($normalizedStatus, ['subscribed', 'unsubscribed'], true)) {
                $this->recordConsentEvent($tenantId, $profile, 'email', $normalizedStatus, $reason, $providerSource, $metadata, $timestamp);
            }
        }

        return $state;
    }

    protected function findState(
        int $tenantId,
        ?MarketingProfile $profile = null,
        ?string $phone = null,
        ?string $email = null
    ): ?MessagingContactChannelState {
        $query = MessagingContactChannelState::query()->forTenantId($tenantId);

        if ($profile instanceof MarketingProfile) {
            $state = (clone $query)
                ->where('marketing_profile_id', (int) $profile->id)
                ->first();
            if ($state instanceof MessagingContactChannelState) {
                return $state;
            }
        }

        $normalizedPhone = $this->identityNormalizer->toE164($phone);
        if ($normalizedPhone !== null) {
            $state = (clone $query)
                ->where('phone', $normalizedPhone)
                ->first();
            if ($state instanceof MessagingContactChannelState) {
                return $state;
            }
        }

        $normalizedEmail = $this->identityNormalizer->normalizeEmail($email);
        if ($normalizedEmail !== null) {
            return (clone $query)
                ->where('email', $normalizedEmail)
                ->first();
        }

        return null;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    protected function upsertState(
        int $tenantId,
        ?MarketingProfile $profile,
        ?string $phone,
        ?string $email,
        array $attributes
    ): MessagingContactChannelState {
        $normalizedPhone = $this->identityNormalizer->toE164($phone);
        $normalizedEmail = $this->identityNormalizer->normalizeEmail($email);
        $state = $this->findState($tenantId, $profile, $normalizedPhone, $normalizedEmail);

        if (! $state instanceof MessagingContactChannelState) {
            $state = new MessagingContactChannelState([
                'tenant_id' => $tenantId,
            ]);
        }

        $existingMetadata = is_array($state->metadata) ? $state->metadata : [];
        $incomingMetadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];

        $state->fill([
            'marketing_profile_id' => $profile?->id,
            'phone' => $normalizedPhone ?? $state->phone,
            'email' => $normalizedEmail ?? $state->email,
            ...$attributes,
            'metadata' => [
                ...$existingMetadata,
                ...$incomingMetadata,
            ],
        ]);
        $state->save();

        return $state;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    protected function recordConsentEvent(
        int $tenantId,
        MarketingProfile $profile,
        string $channel,
        string $eventType,
        string $reason,
        ?string $providerSource,
        array $metadata,
        CarbonImmutable|\DateTimeInterface|string $occurredAt
    ): void {
        MarketingConsentEvent::query()->create([
            'tenant_id' => $tenantId,
            'marketing_profile_id' => (int) $profile->id,
            'channel' => $channel,
            'event_type' => $eventType,
            'source_type' => 'responses_inbox',
            'source_id' => null,
            'details' => [
                'reason' => $reason,
                'provider_source' => $providerSource,
                'metadata' => $metadata,
            ],
            'occurred_at' => $occurredAt,
        ]);
    }

    protected function asDate(mixed $value): ?CarbonImmutable
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

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
