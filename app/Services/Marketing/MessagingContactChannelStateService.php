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
    ) {}

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
        $email = $this->identityNormalizer->normalizeEmail($email ?? $profile?->email);
        if ($email !== null && app(MarketingEmailUnsubscribeService::class)->suppressed($tenantId, $email)) {
            return 'unsubscribed';
        }
        $state = $email !== null ? MessagingContactChannelState::query()->forTenantId($tenantId)->where('email', $email)->first() : null;
        if ($state instanceof MessagingContactChannelState && filled($state->email_status) && $state->email_status !== 'unknown') {
            return (string) $state->email_status;
        }

        if ($profile instanceof MarketingProfile) {
            return (bool) ($profile->accepts_email_marketing ?? false) ? 'subscribed' : 'unknown';
        }

        return 'unknown';
    }

    /**
     * @param  array<string,mixed>  $metadata
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
     * @param  array<string,mixed>  $metadata
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
     * @param  array<string,mixed>  $metadata
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
        if ($profile && (int) $profile->tenant_id !== $tenantId) {
            throw new \InvalidArgumentException('Email consent profile belongs to another tenant.');
        }
        $email = $this->identityNormalizer->normalizeEmail($email ?? $profile?->email);
        if ($email === null) {
            throw new \InvalidArgumentException('Email consent requires a valid address.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($tenantId, $profile, $email, $status, $reason, $providerSource, $metadata, $occurredAt): MessagingContactChannelState {
            $timestamp = $this->asDate($occurredAt) ?? now();
            $normalizedStatus = in_array($status, ['subscribed', 'unsubscribed', 'bounced', 'suppressed', 'unknown'], true) ? $status : 'unknown';
            $profiles = MarketingProfile::query()->forTenantId($tenantId)
                ->where(function ($query) use ($email): void {
                    $query->where('normalized_email', $email)->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
                })->lockForUpdate()->get();
            $anchor = $profile && $this->identityNormalizer->normalizeEmail($profile->email) === $email ? $profile : $profiles->first();
            // This is the existing unique (tenant_id, email) address ledger. Do not
            // overwrite an old address by looking up a profile whose email changed.
            $state = MessagingContactChannelState::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'email' => $email],
                ['marketing_profile_id' => $anchor?->id],
            );
            $previousStatus = $state->email_status;
            $state->fill([
                'email_status' => $normalizedStatus, 'email_status_reason' => $reason,
                'email_status_changed_at' => $previousStatus === $normalizedStatus ? ($state->email_status_changed_at ?? $timestamp) : $timestamp, 'provider_source' => $providerSource,
                'metadata' => [...(array) $state->metadata, ...$metadata, 'email' => $email],
            ])->save();
            MessagingContactChannelState::query()->forTenantId($tenantId)->where('email', $email)->whereKeyNot($state->id)->update([
                'email_status' => $normalizedStatus, 'email_status_reason' => $reason,
                'email_status_changed_at' => $timestamp,
            ]);
            foreach ($profiles as $affected) {
                $attributes = [];
                if (in_array($normalizedStatus, ['unsubscribed', 'suppressed', 'bounced'], true)) {
                    $attributes['accepts_email_marketing'] = false;
                    if ($normalizedStatus === 'unsubscribed') {
                        $attributes['email_opted_out_at'] = $affected->email_opted_out_at ?? $timestamp;
                    }
                } elseif ($normalizedStatus === 'subscribed') {
                    $attributes = ['accepts_email_marketing' => true, 'email_opted_out_at' => null];
                }
                $affected->fill($attributes);
                $changed = $affected->isDirty();
                if ($changed) {
                    $affected->save();
                }
                if ($profile?->id === $affected->id) {
                    $profile->setRawAttributes($affected->getAttributes(), true);
                }
                if (in_array($normalizedStatus, ['subscribed', 'unsubscribed'], true) && ($changed || ($previousStatus !== $normalizedStatus && $anchor?->id === $affected->id))) {
                    $this->recordConsentEvent($tenantId, $affected, 'email', $normalizedStatus, $reason, $providerSource, [...$metadata, 'email' => $email], $timestamp);
                }
            }

            return $state;
        });
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
     * @param  array<string,mixed>  $attributes
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
     * @param  array<string,mixed>  $metadata
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
