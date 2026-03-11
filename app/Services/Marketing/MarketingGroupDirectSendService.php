<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingGroup;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Validation\ValidationException;

class MarketingGroupDirectSendService
{
    public function __construct(
        protected TwilioSmsService $twilioSmsService,
        protected SendGridEmailService $sendGridEmailService,
        protected MarketingDeliveryTrackingService $deliveryTrackingService,
        protected MarketingIdentityNormalizer $normalizer
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array{processed:int,sent:int,failed:int,skipped:int,dry_run:int,channel:string}
     */
    public function sendToGroup(
        MarketingGroup $group,
        string $channel,
        string $message,
        ?string $subject = null,
        array $options = []
    ): array {
        if (! $group->is_internal) {
            throw ValidationException::withMessages([
                'group' => 'Direct group send is only available for internal groups.',
            ]);
        }

        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['sms', 'email'], true)) {
            throw ValidationException::withMessages([
                'channel' => 'Channel must be sms or email.',
            ]);
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;
        $subject = trim((string) $subject);
        $message = trim($message);

        if ($message === '') {
            throw ValidationException::withMessages([
                'message' => 'Message text is required.',
            ]);
        }

        if ($channel === 'email' && $subject === '') {
            throw ValidationException::withMessages([
                'subject' => 'Email subject is required for direct email sends.',
            ]);
        }

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => 0,
            'channel' => $channel,
        ];

        $profiles = $group->profiles()
            ->orderBy('marketing_profiles.id')
            ->get([
                'marketing_profiles.id',
                'marketing_profiles.first_name',
                'marketing_profiles.last_name',
                'marketing_profiles.email',
                'marketing_profiles.normalized_email',
                'marketing_profiles.phone',
                'marketing_profiles.normalized_phone',
                'marketing_profiles.accepts_email_marketing',
                'marketing_profiles.accepts_sms_marketing',
            ]);

        foreach ($profiles as $profile) {
            $summary['processed']++;

            $result = $channel === 'sms'
                ? $this->sendSmsToProfile($group, $profile, $message, $dryRun, $actorId)
                : $this->sendEmailToProfile($group, $profile, $subject, $message, $dryRun);

            if ($result === 'sent') {
                $summary['sent']++;
            } elseif ($result === 'failed') {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }

            if ($dryRun && $result === 'sent') {
                $summary['dry_run']++;
            }
        }

        return $summary;
    }

    protected function sendSmsToProfile(
        MarketingGroup $group,
        MarketingProfile $profile,
        string $message,
        bool $dryRun,
        ?int $actorId
    ): string {
        if (! (bool) $profile->accepts_sms_marketing) {
            return 'skipped';
        }

        $toPhone = $this->normalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone));
        if ($toPhone === null || $toPhone === '') {
            return 'skipped';
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
        ]);

        $send = $this->twilioSmsService->sendSms($toPhone, $message, [
            'dry_run' => $dryRun,
            'status_callback_url' => $this->statusCallbackUrl(),
        ]);

        $success = (bool) ($send['success'] ?? false);
        $status = strtolower(trim((string) ($send['status'] ?? ($success ? 'sent' : 'failed'))));

        $delivery->forceFill([
            'provider_message_id' => $send['provider_message_id'] ?? null,
            'from_identifier' => $send['from_identifier'] ?? null,
            'send_status' => $success ? $status : 'failed',
            'error_code' => $send['error_code'] ?? null,
            'error_message' => $send['error_message'] ?? null,
            'provider_payload' => [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'dry_run' => $dryRun,
                'provider' => $send,
            ],
            'sent_at' => $success ? now() : null,
            'delivered_at' => $success && $status === 'delivered' ? now() : null,
            'failed_at' => ! $success ? now() : null,
        ])->save();

        $this->deliveryTrackingService->appendEvent(
            delivery: $delivery,
            provider: 'twilio',
            providerMessageId: $delivery->provider_message_id,
            eventType: 'status_updated',
            eventStatus: $delivery->send_status,
            payload: [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'send' => $send,
            ],
            occurredAt: now()
        );

        return $success ? 'sent' : 'failed';
    }

    protected function sendEmailToProfile(
        MarketingGroup $group,
        MarketingProfile $profile,
        string $subject,
        string $message,
        bool $dryRun
    ): string {
        if (! (bool) $profile->accepts_email_marketing) {
            return 'skipped';
        }

        $email = trim((string) ($profile->normalized_email ?: $profile->email));
        if ($email === '') {
            return 'skipped';
        }

        $delivery = MarketingEmailDelivery::query()->create([
            'marketing_campaign_recipient_id' => null,
            'marketing_profile_id' => $profile->id,
            'email' => $email,
            'status' => 'sending',
            'raw_payload' => [
                'group_id' => $group->id,
                'group_name' => $group->name,
            ],
        ]);

        $send = $this->sendGridEmailService->sendEmail($email, $subject, $message, [
            'dry_run' => $dryRun,
            'custom_args' => [
                'marketing_email_delivery_id' => (string) $delivery->id,
                'marketing_profile_id' => (string) $profile->id,
                'marketing_group_id' => (string) $group->id,
            ],
        ]);

        $success = (bool) ($send['success'] ?? false);
        $delivery->forceFill([
            'sendgrid_message_id' => $send['message_id'] ?? null,
            'status' => $success ? 'sent' : 'failed',
            'sent_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
            'raw_payload' => [
                ...((array) $delivery->raw_payload),
                'dry_run' => $dryRun,
                'provider' => $send,
            ],
        ])->save();

        return $success ? 'sent' : 'failed';
    }

    protected function statusCallbackUrl(): ?string
    {
        $configured = trim((string) config('marketing.twilio.status_callback_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        try {
            return route('marketing.webhooks.twilio-status');
        } catch (\Throwable) {
            return null;
        }
    }
}
