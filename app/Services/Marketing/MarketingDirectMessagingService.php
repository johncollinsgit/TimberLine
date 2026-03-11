<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingMessageGroupMember;
use App\Models\MarketingProfile;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketingDirectMessagingService
{
    public function __construct(
        protected TwilioSmsService $twilioSmsService,
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
        if (!in_array($channel, ['sms', 'email'], true)) {
            throw new \InvalidArgumentException("Unsupported channel '{$channel}'.");
        }

        if ($channel === 'email') {
            throw new \RuntimeException('Email direct sends are not implemented yet.');
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;
        $batchId = trim((string) ($options['batch_id'] ?? ''));
        if ($batchId === '') {
            $batchId = (string) \Illuminate\Support\Str::uuid();
        }
        $groupId = isset($options['group_id']) ? (int) $options['group_id'] : null;
        $sourceLabel = trim((string) ($options['source_label'] ?? 'direct_message_wizard'));

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
        if ($message === '') {
            throw new \InvalidArgumentException('Message body is required.');
        }

        foreach ($recipients as $recipient) {
            $summary['processed']++;

            $resolved = $this->resolveRecipient($recipient);
            if (! $resolved['sendable']) {
                $summary['skipped']++;
                continue;
            }

            /** @var MarketingProfile $profile */
            $profile = $resolved['profile'];
            $toPhone = (string) $resolved['to_phone'];

            if ((bool) ($resolved['requires_consent'] ?? false) && ! (bool) $profile->accepts_sms_marketing) {
                $summary['skipped']++;
                continue;
            }

            $delivery = MarketingMessageDelivery::query()->create([
                'campaign_id' => null,
                'campaign_recipient_id' => null,
                'marketing_profile_id' => $profile->id,
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
                    'source_type' => (string) ($recipient['source_type'] ?? 'profile'),
                ],
            ]);

            $sendResult = $this->twilioSmsService->sendSms($toPhone, $message, [
                'dry_run' => $dryRun,
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
        ?string $description = null
    ): MarketingMessageGroup {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, ['sms', 'email'], true)) {
            throw new \InvalidArgumentException("Unsupported group channel '{$channel}'.");
        }

        return DB::transaction(function () use ($name, $channel, $members, $isReusable, $createdBy, $description): MarketingMessageGroup {
            $group = MarketingMessageGroup::query()->create([
                'name' => trim($name),
                'channel' => $channel,
                'is_reusable' => $isReusable,
                'description' => $description !== null ? trim($description) : null,
                'created_by' => $createdBy,
            ]);

            $rows = $this->normalizedMembersForStorage($members)
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
     *   phone:string,
     *   normalized_phone:string,
     *   source_type:string
     * }>
     */
    protected function normalizedMembersForStorage(array $members): Collection
    {
        $rows = collect($members)
            ->map(function (array $member): ?array {
                $normalizedPhone = $this->identityNormalizer->normalizePhone((string) ($member['normalized_phone'] ?? $member['phone'] ?? ''));
                if ($normalizedPhone === null) {
                    return null;
                }

                $rawPhone = trim((string) ($member['phone'] ?? ''));

                return [
                    'profile_id' => isset($member['profile_id']) ? (int) $member['profile_id'] : null,
                    'name' => $this->nullableString($member['name'] ?? null),
                    'email' => $this->nullableString($member['email'] ?? null),
                    'phone' => $rawPhone !== '' ? $rawPhone : $normalizedPhone,
                    'normalized_phone' => $normalizedPhone,
                    'source_type' => trim((string) ($member['source_type'] ?? 'profile')) ?: 'profile',
                ];
            })
            ->filter()
            ->unique(fn (array $member) => $member['normalized_phone'])
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
    protected function resolveRecipient(array $recipient): array
    {
        $profileId = isset($recipient['profile_id']) ? (int) $recipient['profile_id'] : null;
        if ($profileId) {
            $profile = MarketingProfile::query()->find($profileId);
            if (! $profile) {
                return ['sendable' => false, 'profile' => null, 'to_phone' => null, 'requires_consent' => true];
            }

            $toPhone = trim((string) ($profile->normalized_phone ?: $profile->phone));
            if ($toPhone === '') {
                return ['sendable' => false, 'profile' => null, 'to_phone' => null, 'requires_consent' => true];
            }

            return ['sendable' => true, 'profile' => $profile, 'to_phone' => $toPhone, 'requires_consent' => true];
        }

        $phone = trim((string) ($recipient['normalized_phone'] ?? $recipient['phone'] ?? ''));
        $normalized = $this->identityNormalizer->normalizePhone($phone);
        if ($normalized === null) {
            return ['sendable' => false, 'profile' => null, 'to_phone' => null, 'requires_consent' => false];
        }

        $profile = MarketingProfile::query()
            ->where('normalized_phone', $normalized)
            ->first();

        if (! $profile) {
            $profile = MarketingProfile::query()->create([
                'first_name' => $this->firstName((string) ($recipient['name'] ?? '')),
                'last_name' => $this->lastName((string) ($recipient['name'] ?? '')),
                'email' => $this->nullableString($recipient['email'] ?? null),
                'normalized_email' => null,
                'phone' => trim((string) ($recipient['phone'] ?? $normalized)) ?: $normalized,
                'normalized_phone' => $normalized,
                'source_channels' => ['manual_message_entry'],
                'accepts_sms_marketing' => false,
                'accepts_email_marketing' => false,
            ]);
        }

        return ['sendable' => true, 'profile' => $profile, 'to_phone' => $normalized, 'requires_consent' => false];
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
        $configured = trim((string) (config('services.twilio.status_callback_url') ?: config('marketing.twilio.status_callback_url', '')));
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
}
