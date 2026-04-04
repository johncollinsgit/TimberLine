<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingMessageGroupMember;
use App\Models\MarketingProfile;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingDirectMessagingService
{
    public function __construct(
        protected TwilioSmsService $twilioSmsService,
        protected SendGridEmailService $sendGridEmailService,
        protected MarketingDeliveryTrackingService $deliveryTrackingService,
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    /**
     * @param array<int,array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * }> $recipients
     * @param array<string,mixed> $options
     * @return array{
     *   processed:int,
     *   sent:int,
     *   failed:int,
     *   skipped:int,
     *   dry_run:int,
     *   batch_id:string,
     *   first_error_code:?string,
     *   first_error_message:?string
     * }
     */
    public function send(string $channel, array $recipients, string $message, array $options = []): array
    {
        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['sms', 'email'], true)) {
            throw new \InvalidArgumentException("Unsupported channel '{$channel}'.");
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;
        $batchId = trim((string) ($options['batch_id'] ?? ''));
        if ($batchId === '') {
            $batchId = (string) Str::uuid();
        }
        $groupId = isset($options['group_id']) ? (int) $options['group_id'] : null;
        $sourceLabel = trim((string) ($options['source_label'] ?? 'direct_message_wizard'));
        $senderKey = $this->nullableString($options['sender_key'] ?? null);
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null);
        $storeKey = $this->nullableString($options['store_key'] ?? null);
        $subject = $this->nullableString($options['subject'] ?? null);
        $htmlBody = $this->nullableString($options['html_body'] ?? null);
        $emailTemplate = is_array($options['email_template'] ?? null)
            ? $options['email_template']
            : null;

        $forceSendProfileIds = collect((array) ($options['force_send_profile_ids'] ?? []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $forceSendProfileLookup = array_fill_keys($forceSendProfileIds, true);

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => 0,
            'batch_id' => $batchId,
            'first_error_code' => null,
            'first_error_message' => null,
        ];

        $message = trim($message);
        if ($channel === 'sms' && $message === '') {
            throw new \InvalidArgumentException('Message body is required.');
        }
        if ($channel === 'email' && $subject === null) {
            throw new \InvalidArgumentException('Email subject is required.');
        }
        if ($channel === 'email' && $message === '' && $htmlBody === null) {
            throw new \InvalidArgumentException('Email message content is required.');
        }

        foreach ($recipients as $recipient) {
            $summary['processed']++;

            $resolved = $channel === 'sms'
                ? $this->resolveSmsRecipient($recipient, $tenantId)
                : $this->resolveEmailRecipient($recipient, $tenantId);
            if (! $resolved['sendable']) {
                $summary['skipped']++;
                continue;
            }

            /** @var MarketingProfile $profile */
            $profile = $resolved['profile'];
            $profileId = (int) ($profile->id ?? 0);
            $forceConsentBypass = $profileId > 0 && isset($forceSendProfileLookup[$profileId]);

            if (! $forceConsentBypass && (bool) ($resolved['requires_consent'] ?? false) && (
                ($channel === 'sms' && ! (bool) $profile->accepts_sms_marketing)
                || ($channel === 'email' && ! (bool) $profile->accepts_email_marketing)
            )) {
                $summary['skipped']++;
                continue;
            }

            if ($channel === 'sms') {
                $toPhone = (string) ($resolved['to_phone'] ?? '');
                $delivery = MarketingMessageDelivery::query()->create([
                    'campaign_id' => null,
                    'campaign_recipient_id' => null,
                    'marketing_profile_id' => $profile->id,
                    'tenant_id' => $tenantId ?? $this->positiveInt($profile->tenant_id),
                    'store_key' => $storeKey,
                    'batch_id' => $batchId,
                    'source_label' => $sourceLabel,
                    'message_subject' => Str::limit($message, 160),
                    'channel' => 'sms',
                    'provider' => 'twilio',
                    'to_phone' => $toPhone,
                    'variant_id' => null,
                    'attempt_number' => 1,
                    'rendered_message' => $message,
                    'send_status' => 'sending',
                    'created_by' => $actorId,
                    'provider_payload' => [
                        'batch_id' => $batchId,
                        'source_label' => $sourceLabel,
                        'group_id' => $groupId,
                        'sender_key' => $senderKey,
                        'source_type' => (string) ($recipient['source_type'] ?? 'profile'),
                    ],
                ]);

                $sendResult = $this->twilioSmsService->sendSms($toPhone, $message, [
                    'dry_run' => $dryRun,
                    'sender_key' => $senderKey,
                    'status_callback_url' => $this->statusCallbackUrl(),
                ]);

                $success = (bool) ($sendResult['success'] ?? false);
                $providerStatus = $this->deliveryTrackingService->mapProviderStatus($sendResult['status'] ?? null);

                $delivery->forceFill([
                    'provider_message_id' => $sendResult['provider_message_id'] ?? null,
                    'from_identifier' => $sendResult['from_identifier'] ?? null,
                    'send_status' => $success ? $providerStatus : 'failed',
                    'error_code' => $sendResult['error_code'] ?? null,
                    'error_message' => $sendResult['error_message'] ?? null,
                    'provider_payload' => [
                        ...((array) $delivery->provider_payload),
                        'sender_key' => $sendResult['sender_key'] ?? $senderKey,
                        'sender_label' => $sendResult['sender_label'] ?? null,
                        'twilio_response' => $sendResult['payload'] ?? [],
                    ],
                    'sent_at' => $success && in_array($providerStatus, ['queued', 'sending', 'sent', 'delivered', 'undelivered'], true)
                        ? now()
                        : null,
                    'delivered_at' => $success && $providerStatus === 'delivered' ? now() : null,
                    'failed_at' => ! $success || in_array($providerStatus, ['failed', 'undelivered', 'canceled'], true) ? now() : null,
                ])->save();

                $eventStatus = $success ? $providerStatus : 'failed';
                $this->deliveryTrackingService->appendEvent(
                    delivery: $delivery,
                    provider: 'twilio',
                    providerMessageId: $delivery->provider_message_id,
                    eventType: 'status_updated',
                    eventStatus: $eventStatus,
                    payload: [
                        'batch_id' => $batchId,
                        'source_label' => $sourceLabel,
                        'result' => $sendResult,
                    ],
                    occurredAt: now()
                );

                if ($success) {
                    $summary['sent']++;
                } else {
                    $summary['failed']++;
                    if ($summary['first_error_code'] === null) {
                        $summary['first_error_code'] = $this->nullableString($sendResult['error_code'] ?? null);
                    }
                    if ($summary['first_error_message'] === null) {
                        $summary['first_error_message'] = $this->nullableString($sendResult['error_message'] ?? null);
                    }
                }

                if ((bool) ($sendResult['dry_run'] ?? false)) {
                    $summary['dry_run']++;
                }
                continue;
            }

            $toEmail = (string) ($resolved['to_email'] ?? '');
            $resolvedTenantId = $tenantId ?? $this->positiveInt($profile->tenant_id);
            $delivery = MarketingEmailDelivery::query()->create([
                'marketing_campaign_recipient_id' => null,
                'marketing_profile_id' => $profile->id,
                'tenant_id' => $resolvedTenantId,
                'store_key' => $storeKey,
                'batch_id' => $batchId,
                'source_label' => $sourceLabel,
                'message_subject' => $subject,
                'provider' => 'sendgrid',
                'campaign_type' => 'direct_message',
                'template_key' => 'direct_message',
                'email' => $toEmail,
                'status' => 'sending',
                'raw_payload' => [
                    'batch_id' => $batchId,
                    'source_label' => $sourceLabel,
                    'group_id' => $groupId,
                    'source_type' => (string) ($recipient['source_type'] ?? 'profile'),
                ],
                'metadata' => [
                    'batch_id' => $batchId,
                    'source_label' => $sourceLabel,
                    'group_id' => $groupId,
                    'source_type' => (string) ($recipient['source_type'] ?? 'profile'),
                    'subject' => $subject,
                    'template_mode' => $this->nullableString(data_get($emailTemplate, 'mode')),
                    'template_sections' => is_array(data_get($emailTemplate, 'sections'))
                        ? data_get($emailTemplate, 'sections')
                        : [],
                ],
            ]);

            $sendResult = $this->sendGridEmailService->sendEmail($toEmail, (string) $subject, $message, [
                'dry_run' => $dryRun,
                'tenant_id' => $resolvedTenantId,
                'campaign_type' => 'direct_message',
                'template_key' => 'direct_message',
                'customer_id' => (int) $profile->id,
                'metadata' => [
                    'batch_id' => $batchId,
                    'source_label' => $sourceLabel,
                    'group_id' => $groupId,
                    'source_type' => (string) ($recipient['source_type'] ?? 'profile'),
                    'subject' => $subject,
                    'template_mode' => $this->nullableString(data_get($emailTemplate, 'mode')),
                    'template_sections' => is_array(data_get($emailTemplate, 'sections'))
                        ? data_get($emailTemplate, 'sections')
                        : [],
                ],
                'html_body' => $htmlBody,
                'categories' => [
                    'direct-message',
                    'shopify-embedded',
                ],
                'custom_args' => [
                    'marketing_email_delivery_id' => (string) $delivery->id,
                    'marketing_profile_id' => (string) $profile->id,
                ],
            ]);

            $success = (bool) ($sendResult['success'] ?? false);
            $delivery->forceFill([
                'provider' => (string) ($sendResult['provider'] ?? 'sendgrid'),
                'provider_message_id' => $sendResult['message_id'] ?? null,
                'sendgrid_message_id' => ((string) ($sendResult['provider'] ?? 'sendgrid')) === 'sendgrid'
                    ? ($sendResult['message_id'] ?? null)
                    : null,
                'status' => $success ? 'sent' : 'failed',
                'raw_payload' => [
                    ...((array) $delivery->raw_payload),
                    'provider' => $sendResult['provider'] ?? 'sendgrid',
                    'provider_result' => $sendResult,
                ],
                'metadata' => [
                    ...((array) ($delivery->metadata ?? [])),
                    'subject' => $subject,
                    'template_mode' => $this->nullableString(data_get($emailTemplate, 'mode')),
                    'template_sections' => is_array(data_get($emailTemplate, 'sections'))
                        ? data_get($emailTemplate, 'sections')
                        : [],
                ],
                'sent_at' => $success ? now() : null,
                'failed_at' => $success ? null : now(),
            ])->save();

            if ($success) {
                $summary['sent']++;
            } else {
                $summary['failed']++;
                if ($summary['first_error_code'] === null) {
                    $summary['first_error_code'] = $this->nullableString($sendResult['error_code'] ?? null);
                }
                if ($summary['first_error_message'] === null) {
                    $summary['first_error_message'] = $this->nullableString($sendResult['error_message'] ?? null);
                }
            }

            if ((bool) ($sendResult['dry_run'] ?? false)) {
                $summary['dry_run']++;
            }
        }

        if ($groupId && $summary['processed'] > 0) {
            MarketingMessageGroup::query()->whereKey($groupId)->update([
                'last_used_at' => now(),
            ]);
        }

        return $summary;
    }

    /**
     * @param array<int,array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * }> $members
     */
    public function saveGroup(
        string $name,
        string $channel,
        array $members,
        bool $isReusable = true,
        ?int $createdBy = null,
        ?string $description = null,
        ?int $tenantId = null
    ): MarketingMessageGroup {
        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['sms', 'email', 'multi'], true)) {
            throw new \InvalidArgumentException("Unsupported group channel '{$channel}'.");
        }

        return DB::transaction(function () use ($name, $channel, $members, $isReusable, $createdBy, $description, $tenantId): MarketingMessageGroup {
            $group = MarketingMessageGroup::query()->create([
                'tenant_id' => $tenantId,
                'name' => trim($name),
                'channel' => $channel,
                'is_reusable' => $isReusable,
                'is_system' => false,
                'system_key' => null,
                'description' => $description !== null ? trim($description) : null,
                'created_by' => $createdBy,
            ]);

            $rows = $this->normalizedMembersForStorage($members, $tenantId)
                ->map(function (array $row) use ($group): array {
                    return [
                        'marketing_message_group_id' => (int) $group->id,
                        'marketing_profile_id' => $row['profile_id'],
                        'source_type' => $row['source_type'],
                        'full_name' => $row['name'],
                        'email' => $row['email'],
                        'phone' => $row['phone'],
                        'normalized_phone' => $row['normalized_phone'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                })
                ->values()
                ->all();

            if ($rows !== []) {
                MarketingMessageGroupMember::query()->insert($rows);
            }

            return $group;
        });
    }

    /**
     * @param array<int,array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * }> $members
     * @return Collection<int,array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * }>
     */
    protected function normalizedMembersForStorage(array $members, ?int $tenantId = null): Collection
    {
        $rows = collect($members)
            ->map(function (array $member) use ($tenantId): ?array {
                $profileId = isset($member['profile_id']) ? (int) $member['profile_id'] : null;
                if ($tenantId !== null && $profileId !== null && $profileId > 0) {
                    $profile = MarketingProfile::query()
                        ->forTenantId($tenantId)
                        ->where('id', $profileId)
                        ->first([
                            'id',
                            'first_name',
                            'last_name',
                            'email',
                            'normalized_email',
                            'phone',
                            'normalized_phone',
                        ]);
                    if (! $profile instanceof MarketingProfile) {
                        return null;
                    }

                    return [
                        'profile_id' => $profileId,
                        'name' => trim((string) ($profile->first_name . ' ' . $profile->last_name)) !== ''
                            ? trim((string) ($profile->first_name . ' ' . $profile->last_name))
                            : null,
                        'email' => $this->nullableString($profile->email),
                        'phone' => $this->nullableString($profile->phone ?: $profile->normalized_phone),
                        'normalized_phone' => $this->nullableString($profile->normalized_phone),
                        'source_type' => trim((string) ($member['source_type'] ?? 'profile')) ?: 'profile',
                    ];
                }

                $normalizedPhone = $this->identityNormalizer->normalizePhone((string) ($member['normalized_phone'] ?? $member['phone'] ?? ''));
                $rawPhone = trim((string) ($member['phone'] ?? ''));
                $email = $this->identityNormalizer->normalizeEmail((string) ($member['email'] ?? ''));

                if ($profileId !== null && $profileId > 0) {
                    $profile = MarketingProfile::query()
                        ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
                        ->where('id', $profileId)
                        ->first([
                            'id',
                            'first_name',
                            'last_name',
                            'email',
                            'normalized_email',
                            'phone',
                            'normalized_phone',
                        ]);
                    if ($profile instanceof MarketingProfile) {
                        return [
                            'profile_id' => $profileId,
                            'name' => trim((string) ($profile->first_name . ' ' . $profile->last_name)) !== ''
                                ? trim((string) ($profile->first_name . ' ' . $profile->last_name))
                                : null,
                            'email' => $this->nullableString($profile->email),
                            'phone' => $this->nullableString($profile->phone ?: $profile->normalized_phone),
                            'normalized_phone' => $this->nullableString($profile->normalized_phone),
                            'source_type' => trim((string) ($member['source_type'] ?? 'profile')) ?: 'profile',
                        ];
                    }
                }

                if ($normalizedPhone === null && $email === null) {
                    return null;
                }

                return [
                    'profile_id' => $profileId,
                    'name' => $this->nullableString($member['name'] ?? null),
                    'email' => $email ?? $this->nullableString($member['email'] ?? null),
                    'phone' => $rawPhone !== '' ? $rawPhone : ($normalizedPhone ?? null),
                    'normalized_phone' => $normalizedPhone,
                    'source_type' => trim((string) ($member['source_type'] ?? 'profile')) ?: 'profile',
                ];
            })
            ->filter()
            ->unique(function (array $member): string {
                $profileId = isset($member['profile_id']) ? (int) $member['profile_id'] : 0;
                if ($profileId > 0) {
                    return 'profile:' . $profileId;
                }

                $normalizedPhone = trim((string) ($member['normalized_phone'] ?? ''));
                if ($normalizedPhone !== '') {
                    return 'phone:' . $normalizedPhone;
                }

                $email = strtolower(trim((string) ($member['email'] ?? '')));

                return 'email:' . $email;
            })
            ->values();

        return $rows;
    }

    /**
     * @param array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * } $recipient
     * @return array{sendable:bool,profile:?MarketingProfile,to_phone:?string,requires_consent:bool}
     */
    protected function resolveSmsRecipient(array $recipient, ?int $tenantId = null): array
    {
        return $this->resolveRecipient($recipient, $tenantId);
    }

    /**
     * @param array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * } $recipient
     * @return array{sendable:bool,profile:?MarketingProfile,to_email:?string,requires_consent:bool}
     */
    protected function resolveEmailRecipient(array $recipient, ?int $tenantId = null): array
    {
        $profileId = isset($recipient['profile_id']) ? (int) $recipient['profile_id'] : null;
        if ($profileId) {
            $profile = MarketingProfile::query()
                ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
                ->find($profileId);
            if (! $profile instanceof MarketingProfile) {
                return ['sendable' => false, 'profile' => null, 'to_email' => null, 'requires_consent' => true];
            }

            $toEmail = $this->identityNormalizer->normalizeEmail((string) ($profile->normalized_email ?: $profile->email));
            if ($toEmail === null) {
                return ['sendable' => false, 'profile' => null, 'to_email' => null, 'requires_consent' => true];
            }

            return ['sendable' => true, 'profile' => $profile, 'to_email' => $toEmail, 'requires_consent' => true];
        }

        $email = $this->identityNormalizer->normalizeEmail((string) ($recipient['email'] ?? ''));
        if ($email === null) {
            return ['sendable' => false, 'profile' => null, 'to_email' => null, 'requires_consent' => false];
        }

        $profile = MarketingProfile::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
            ->where('normalized_email', $email)
            ->first();

        if (! $profile instanceof MarketingProfile) {
            $normalizedPhone = $this->identityNormalizer->normalizePhone((string) ($recipient['normalized_phone'] ?? $recipient['phone'] ?? ''));
            $rawPhone = trim((string) ($recipient['phone'] ?? ''));

            $profile = MarketingProfile::query()->create([
                'tenant_id' => $tenantId,
                'first_name' => $this->firstName((string) ($recipient['name'] ?? '')),
                'last_name' => $this->lastName((string) ($recipient['name'] ?? '')),
                'email' => $email,
                'normalized_email' => $email,
                'phone' => $rawPhone !== '' ? $rawPhone : $normalizedPhone,
                'normalized_phone' => $normalizedPhone,
                'source_channels' => ['manual_message_entry'],
                'accepts_sms_marketing' => false,
                'accepts_email_marketing' => false,
            ]);
        }

        return ['sendable' => true, 'profile' => $profile, 'to_email' => $email, 'requires_consent' => false];
    }

    /**
     * @param array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * } $recipient
     * @return array{sendable:bool,profile:?MarketingProfile,to_phone:?string,requires_consent:bool}
     */
    protected function resolveRecipient(array $recipient, ?int $tenantId = null): array
    {
        $profileId = isset($recipient['profile_id']) ? (int) $recipient['profile_id'] : null;
        if ($profileId) {
            $profile = MarketingProfile::query()
                ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
                ->find($profileId);
            if (! $profile) {
                return ['sendable' => false, 'profile' => null, 'to_phone' => null, 'requires_consent' => true];
            }

            $toPhone = $this->identityNormalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone));
            if ($toPhone === null) {
                return ['sendable' => false, 'profile' => null, 'to_phone' => null, 'requires_consent' => true];
            }

            return ['sendable' => true, 'profile' => $profile, 'to_phone' => $toPhone, 'requires_consent' => true];
        }

        $phone = trim((string) ($recipient['normalized_phone'] ?? $recipient['phone'] ?? ''));
        $normalized = $this->identityNormalizer->normalizePhone($phone);
        if ($normalized === null) {
            return ['sendable' => false, 'profile' => null, 'to_phone' => null, 'requires_consent' => false];
        }

        $phoneCandidates = $this->identityNormalizer->phoneMatchCandidates($phone);
        $profile = MarketingProfile::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
            ->whereIn('normalized_phone', $phoneCandidates)
            ->first();

        if (! $profile) {
            $normalizedE164 = $this->identityNormalizer->toE164($normalized) ?: $normalized;
            $rawPhone = trim((string) ($recipient['phone'] ?? ''));
            $profile = MarketingProfile::query()->create([
                'tenant_id' => $tenantId,
                'first_name' => $this->firstName((string) ($recipient['name'] ?? '')),
                'last_name' => $this->lastName((string) ($recipient['name'] ?? '')),
                'email' => $this->nullableString($recipient['email'] ?? null),
                'normalized_email' => null,
                'phone' => $rawPhone !== '' ? $rawPhone : $normalizedE164,
                'normalized_phone' => $normalizedE164,
                'source_channels' => ['manual_message_entry'],
                'accepts_sms_marketing' => false,
                'accepts_email_marketing' => false,
            ]);
        }

        $toPhone = $this->identityNormalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone))
            ?: ($this->identityNormalizer->toE164($normalized) ?: $normalized);

        return ['sendable' => true, 'profile' => $profile, 'to_phone' => $toPhone, 'requires_consent' => false];
    }

    protected function firstName(string $name): ?string
    {
        [$first] = $this->identityNormalizer->splitName($name);

        return $first;
    }

    protected function lastName(string $name): ?string
    {
        [, $last] = $this->identityNormalizer->splitName($name);

        return $last;
    }

    protected function statusCallbackUrl(): string
    {
        $configured = trim((string) config('marketing.twilio.status_callback_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        try {
            return route('marketing.webhooks.twilio-status');
        } catch (\Throwable) {
            return '';
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }
}
