<?php

namespace App\Services\Marketing;

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\Email\TenantEmailSettingsService;

class BirthdayEmailDispatchService
{
    public function __construct(
        protected TenantEmailDispatchService $emailDispatchService,
        protected TenantEmailSettingsService $emailSettingsService,
        protected MarketingTemplateRenderer $templateRenderer,
        protected MarketingEmailReadiness $emailReadiness
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function sendIssuanceEmail(BirthdayRewardIssuance $issuance, array $options = []): array
    {
        $issuance->loadMissing(['marketingProfile', 'birthdayProfile']);

        $templateKey = $this->nullableString($options['template_key'] ?? null) ?: 'birthday_email_primary';
        $campaignType = 'birthday';
        $eventCampaignType = 'birthday_email';
        $eventKey = $this->eventKey($issuance, $templateKey);
        $force = (bool) ($options['force'] ?? false);

        $existingEvent = BirthdayMessageEvent::query()->where('event_key', $eventKey)->first();
        if ($existingEvent && ! $force) {
            return [
                'ok' => in_array((string) $existingEvent->status, ['sent', 'delivered', 'opened', 'clicked'], true),
                'success' => in_array((string) $existingEvent->status, ['sent', 'delivered', 'opened', 'clicked'], true),
                'attempted' => false,
                'already_recorded' => true,
                'provider' => (string) ($existingEvent->provider ?? ''),
                'status' => (string) ($existingEvent->status ?? 'unknown'),
                'message_id' => $this->nullableString($existingEvent->provider_message_id),
                'delivery_id' => $this->positiveInt(data_get($existingEvent->metadata, 'delivery_id')),
                'birthday_message_event_id' => (int) $existingEvent->id,
                'error_code' => $this->nullableString(data_get($existingEvent->metadata, 'error_code')),
                'error_message' => $this->nullableString(data_get($existingEvent->metadata, 'error_message')),
            ];
        }

        $profile = $issuance->marketingProfile;
        $birthdayProfile = $issuance->birthdayProfile;
        $tenantId = $this->positiveInt($profile?->tenant_id);
        $settings = $this->emailSettingsService->resolvedForTenant($tenantId);
        $providerContext = $this->emailReadiness->providerContextForDelivery($tenantId);
        $selectedProvider = trim((string) ($providerContext['provider'] ?? ($settings['email_provider'] ?? 'sendgrid')));
        $selectedProvider = $selectedProvider !== '' ? strtolower($selectedProvider) : 'sendgrid';

        $config = $this->campaignConfig();
        $subjectTemplate = $this->nullableString($config['birthday_email_subject'] ?? null)
            ?: 'Happy Birthday from The Forestry Studio';
        $bodyTemplate = $this->nullableString($config['birthday_email_body'] ?? null)
            ?: 'Activate your birthday reward and use it on your next order.';

        $couponCode = $this->nullableString($issuance->reward_code);
        $birthdayDate = $birthdayProfile
            ? $this->birthdayDateValue((int) ($birthdayProfile->birth_month ?? 0), (int) ($birthdayProfile->birth_day ?? 0))
            : null;
        $cohortDate = optional($issuance->issued_at ?: now())->toDateString();
        $applyPath = $couponCode !== null ? $this->birthdayApplyPath($couponCode) : null;
        $applyUrl = $applyPath !== null ? $this->storefrontUrl($applyPath) : null;

        $metadata = [
            'tenant_id' => $tenantId,
            'customer_id' => $this->positiveInt($issuance->marketing_profile_id),
            'campaign_type' => $campaignType,
            'coupon_code' => $couponCode,
            'template_key' => $templateKey,
            'birthday_campaign_id' => $this->nullableString($options['birthday_campaign_id'] ?? null),
            'birthday_date' => $birthdayDate,
            'cohort_date' => $cohortDate,
            'birthday_reward_issuance_id' => (int) $issuance->id,
            'provider' => $selectedProvider,
            'provider_resolution_source' => (string) ($providerContext['resolution_source'] ?? 'none'),
            'provider_readiness_status' => (string) ($providerContext['readiness_status'] ?? 'error'),
            'provider_config_status' => (string) ($providerContext['config_status'] ?? 'error'),
            'provider_using_fallback_config' => (bool) ($providerContext['using_fallback_config'] ?? false),
        ];

        $failure = $this->preflightFailure($profile, $config);

        $subject = '';
        $textBody = '';
        $htmlBody = '';
        $toEmail = '';

        if (! $failure && $profile instanceof MarketingProfile) {
            $toEmail = trim((string) ($profile->normalized_email ?: $profile->email));
            $subject = trim($this->templateRenderer->renderText($subjectTemplate, $profile, $this->templateExtra($metadata, $issuance, $applyUrl)));
            $textBody = trim($this->templateRenderer->renderText($bodyTemplate, $profile, $this->templateExtra($metadata, $issuance, $applyUrl)));
            if ($subject === '') {
                $subject = 'Happy Birthday from The Forestry Studio';
            }
            if ($textBody === '') {
                $textBody = 'Your birthday reward is ready.';
            }
            $htmlBody = $this->htmlFromText($textBody);
        }

        if (! $failure && trim($toEmail) === '') {
            $failure = [
                'code' => 'missing_email',
                'message' => 'Birthday email could not be sent because the profile has no deliverable email address.',
            ];
        }

        $delivery = MarketingEmailDelivery::query()->create([
            'marketing_campaign_recipient_id' => null,
            'marketing_profile_id' => $this->positiveInt($issuance->marketing_profile_id),
            'tenant_id' => $tenantId,
            'provider' => $selectedProvider,
            'campaign_type' => $campaignType,
            'template_key' => $templateKey,
            'email' => $toEmail,
            'status' => 'sending',
            'raw_payload' => [
                'source' => 'birthday_reward_engine',
                'birthday_reward_issuance_id' => (int) $issuance->id,
                'event_key' => $eventKey,
                'provider_resolution' => $providerContext,
            ],
            'metadata' => $metadata,
        ]);

        if ($failure) {
            $sendResult = $this->failureResult($selectedProvider, (string) $failure['code'], (string) $failure['message']);
        } else {
            $sendResult = $this->emailDispatchService->sendEmail(
                toEmail: $toEmail,
                subject: $subject,
                textBody: $textBody,
                options: [
                    'tenant_id' => $tenantId,
                    'from_name' => $this->nullableString($settings['from_name'] ?? null),
                    'from_email' => $this->nullableString($settings['from_email'] ?? null),
                    'reply_to_email' => $this->nullableString($settings['reply_to_email'] ?? null),
                    'html_body' => $htmlBody,
                    'campaign_type' => $campaignType,
                    'template_key' => $templateKey,
                    'customer_id' => $this->positiveInt($issuance->marketing_profile_id),
                    'coupon_code' => $couponCode,
                    'metadata' => $metadata,
                    'categories' => [
                        'birthday',
                        'birthday-reward',
                    ],
                    'custom_args' => [
                        'marketing_email_delivery_id' => (string) $delivery->id,
                        'birthday_reward_issuance_id' => (string) $issuance->id,
                        'template_key' => $templateKey,
                    ],
                ]
            );
        }

        $success = (bool) ($sendResult['success'] ?? false);
        $provider = $this->nullableString($sendResult['provider'] ?? null) ?: $selectedProvider;
        $messageId = $this->nullableString($sendResult['message_id'] ?? null);
        $errorCode = $this->nullableString($sendResult['error_code'] ?? null);
        $errorMessage = $this->nullableString($sendResult['error_message'] ?? null);

        $delivery->forceFill([
            'provider' => $provider,
            'provider_message_id' => $messageId,
            'sendgrid_message_id' => $provider === 'sendgrid' ? $messageId : null,
            'status' => $success ? 'sent' : 'failed',
            'sent_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
            'raw_payload' => [
                ...((array) ($delivery->raw_payload ?? [])),
                'provider' => $provider,
                'provider_result' => [
                    'status' => $sendResult['status'] ?? null,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'retryable' => (bool) ($sendResult['retryable'] ?? false),
                    'payload' => is_array($sendResult['payload'] ?? null) ? $sendResult['payload'] : [],
                ],
            ],
            'metadata' => [
                ...((array) ($delivery->metadata ?? [])),
                'provider' => $provider,
                'delivery_id' => (int) $delivery->id,
                'error_code' => $errorCode,
            ],
        ])->save();

        $event = BirthdayMessageEvent::query()->updateOrCreate(
            ['event_key' => $eventKey],
            [
                'customer_birthday_profile_id' => $this->positiveInt($issuance->customer_birthday_profile_id),
                'marketing_profile_id' => $this->positiveInt($issuance->marketing_profile_id),
                'birthday_reward_issuance_id' => (int) $issuance->id,
                'campaign_type' => $eventCampaignType,
                'channel' => 'email',
                'provider' => $provider,
                'provider_message_id' => $messageId,
                'status' => $success ? 'sent' : 'failed',
                'sent_at' => $success ? now() : null,
                'utm_campaign' => 'birthday-email',
                'utm_source' => 'birthday-reward-engine',
                'metadata' => [
                    ...$metadata,
                    'delivery_id' => (int) $delivery->id,
                    'provider' => $provider,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                ],
            ]
        );

        $this->updateIssuanceMetadata($issuance, $delivery, $event, $provider, $messageId, $success, $errorCode, $errorMessage);

        return [
            'ok' => $success,
            'success' => $success,
            'attempted' => true,
            'already_recorded' => false,
            'provider' => $provider,
            'status' => (string) ($sendResult['status'] ?? ($success ? 'sent' : 'failed')),
            'message_id' => $messageId,
            'delivery_id' => (int) $delivery->id,
            'birthday_message_event_id' => (int) $event->id,
            'event_key' => $eventKey,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * @return array{code:string,message:string}|null
     */
    protected function preflightFailure(?MarketingProfile $profile, array $campaignConfig): ?array
    {
        if (! (bool) ($campaignConfig['email_enabled'] ?? true)) {
            return [
                'code' => 'birthday_campaign_email_disabled',
                'message' => 'Birthday campaign email sending is disabled in birthday campaign settings.',
            ];
        }

        if (! $profile) {
            return [
                'code' => 'missing_profile',
                'message' => 'Birthday email could not be sent because the marketing profile is missing.',
            ];
        }

        if (! (bool) $profile->accepts_email_marketing) {
            return [
                'code' => 'email_not_consented',
                'message' => 'Birthday email could not be sent because the profile is not opted into email marketing.',
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    protected function templateExtra(array $metadata, BirthdayRewardIssuance $issuance, ?string $applyUrl): array
    {
        return [
            'coupon_code' => $metadata['coupon_code'] ?? '',
            'reward_code' => $metadata['coupon_code'] ?? '',
            'reward_name' => $this->nullableString($issuance->reward_name) ?? '',
            'reward_value' => $this->nullableString($issuance->reward_value) ?? '',
            'reward_type' => $this->nullableString($issuance->reward_type) ?? '',
            'birthday_date' => $this->nullableString($metadata['birthday_date'] ?? null) ?? '',
            'cohort_date' => $this->nullableString($metadata['cohort_date'] ?? null) ?? '',
            'reward_apply_url' => $applyUrl ?? '',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function campaignConfig(): array
    {
        return array_merge(
            [
                'email_enabled' => true,
                'birthday_email_subject' => 'Happy Birthday from The Forestry Studio',
                'birthday_email_body' => 'Activate your birthday reward and use it on your next order.',
            ],
            (array) optional(\App\Models\MarketingSetting::query()->where('key', 'birthday_campaign_config')->first())->value
        );
    }

    protected function htmlFromText(string $text): string
    {
        $escaped = e($text);
        $html = str_replace(["\r\n", "\n", "\r"], '<br>', $escaped);

        return '<p>' . $html . '</p>';
    }

    protected function birthdayApplyPath(string $rewardCode): string
    {
        $redirect = '/cart?forestry_reward_code=' . rawurlencode($rewardCode) . '&forestry_reward_kind=birthday';

        return '/discount/' . rawurlencode($rewardCode) . '?redirect=' . rawurlencode($redirect);
    }

    protected function storefrontUrl(string $path): string
    {
        $base = rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/');

        return $base . $path;
    }

    protected function eventKey(BirthdayRewardIssuance $issuance, string $templateKey): string
    {
        return 'birthday_email:' . (int) $issuance->id . ':' . $templateKey;
    }

    protected function birthdayDateValue(int $month, int $day): ?string
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return sprintf('%02d-%02d', $month, $day);
    }

    protected function updateIssuanceMetadata(
        BirthdayRewardIssuance $issuance,
        MarketingEmailDelivery $delivery,
        BirthdayMessageEvent $event,
        string $provider,
        ?string $providerMessageId,
        bool $success,
        ?string $errorCode,
        ?string $errorMessage
    ): void {
        $metadata = is_array($issuance->metadata) ? $issuance->metadata : [];
        $metadata['birthday_email'] = [
            'delivery_id' => (int) $delivery->id,
            'birthday_message_event_id' => (int) $event->id,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'status' => $success ? 'sent' : 'failed',
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'attempted_at' => now()->toIso8601String(),
        ];

        $issuance->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    /**
     * @return array{
     *   success:false,
     *   provider:string,
     *   status:string,
     *   message_id:null,
     *   error_code:string,
     *   error_message:string,
     *   retryable:false,
     *   payload:array<string,mixed>,
     *   dry_run:false
     * }
     */
    protected function failureResult(string $provider, string $errorCode, string $errorMessage): array
    {
        return [
            'success' => false,
            'provider' => $provider,
            'status' => 'failed',
            'message_id' => null,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'retryable' => false,
            'payload' => [
                'source' => 'birthday_email_preflight',
            ],
            'dry_run' => false,
        ];
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
