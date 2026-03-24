<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingProfile;
use App\Models\User;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingAllOptedInSendService
{
    public function __construct(
        protected MarketingIdentityNormalizer $normalizer,
        protected MarketingTemplateRenderer $templateRenderer,
        protected MarketingSmsExecutionService $smsExecutionService,
        protected MarketingEmailExecutionService $emailExecutionService,
        protected TwilioSmsService $twilioSmsService,
        protected SendGridEmailService $sendGridEmailService,
    ) {
    }

    /**
     * @return array{sms:int,email:int,overlap:int,unique:int}
     */
    public function audienceSummary(): array
    {
        $summary = [
            'sms' => 0,
            'email' => 0,
            'overlap' => 0,
            'unique' => 0,
        ];

        MarketingProfile::query()
            ->select([
                'id',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_email_marketing',
                'accepts_sms_marketing',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($profiles) use (&$summary): void {
                foreach ($profiles as $profile) {
                    $smsEligible = $this->sendableSmsPhone($profile) !== null;
                    $emailEligible = $this->sendableEmailAddress($profile) !== null;

                    if ($smsEligible) {
                        $summary['sms']++;
                    }

                    if ($emailEligible) {
                        $summary['email']++;
                    }

                    if ($smsEligible || $emailEligible) {
                        $summary['unique']++;
                    }

                    if ($smsEligible && $emailEligible) {
                        $summary['overlap']++;
                    }
                }
            });

        return $summary;
    }

    /**
     * @return array{sms:int,email:int,overlap:int,unique:int,delivery_total:int,selected_unique:int}
     */
    public function selectedAudienceSummary(string $selection): array
    {
        $base = $this->audienceSummary();
        $selection = $this->normalizeChannelSelection($selection);

        $deliveryTotal = match ($selection) {
            'sms' => $base['sms'],
            'email' => $base['email'],
            default => $base['sms'] + $base['email'],
        };

        $selectedUnique = match ($selection) {
            'sms' => $base['sms'],
            'email' => $base['email'],
            default => $base['unique'],
        };

        return [
            ...$base,
            'delivery_total' => $deliveryTotal,
            'selected_unique' => $selectedUnique,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{selection:string,results:array<string,array<string,mixed>>}
     */
    public function sendTest(User $actor, array $payload): array
    {
        $selection = $this->normalizeChannelSelection((string) ($payload['channel'] ?? 'both'));
        $channels = $this->channelsForSelection($selection);
        $previewProfile = $this->previewProfileForActor($actor);
        $results = [];

        if (in_array('sms', $channels, true)) {
            $toPhone = $this->normalizer->toE164((string) ($payload['test_phone'] ?? ''));
            if ($toPhone === null || $toPhone === '') {
                throw ValidationException::withMessages([
                    'test_phone' => 'Enter a valid test phone number for SMS.',
                ]);
            }

            $results['sms'] = $this->twilioSmsService->sendSms(
                $toPhone,
                $this->renderPreviewText((string) ($payload['sms_body'] ?? ''), $previewProfile, (string) ($payload['cta_link'] ?? ''), 'sms'),
                [
                    'dry_run' => false,
                    'sender_key' => $this->nullableString($payload['sender_key'] ?? null),
                ]
            );
        }

        if (in_array('email', $channels, true)) {
            $toEmail = $this->normalizer->normalizeEmail((string) ($payload['test_email'] ?? ''));
            if ($toEmail === null || $toEmail === '') {
                throw ValidationException::withMessages([
                    'test_email' => 'Enter a valid test email address for email sends.',
                ]);
            }

            $subject = trim($this->templateRenderer->renderText((string) ($payload['email_subject'] ?? ''), $previewProfile));
            if ($subject === '') {
                throw ValidationException::withMessages([
                    'email_subject' => 'Email subject is required when email is selected.',
                ]);
            }

            $tenantId = is_numeric($payload['tenant_id'] ?? null)
                ? (int) $payload['tenant_id']
                : null;

            $results['email'] = $this->sendGridEmailService->sendEmail(
                $toEmail,
                $subject,
                $this->renderPreviewText((string) ($payload['email_body'] ?? ''), $previewProfile, (string) ($payload['cta_link'] ?? ''), 'email'),
                [
                    'dry_run' => false,
                    'tenant_id' => $tenantId,
                    'campaign_type' => 'all_opted_in_test',
                    'template_key' => 'all_opted_in_preview',
                    'categories' => ['all-opted-in-test'],
                ]
            );
        }

        return [
            'selection' => $selection,
            'results' => $results,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{selection:string,counts:array<string,int>,campaigns:array<int,array<string,mixed>>}
     */
    public function createAndSend(User $actor, array $payload): array
    {
        $selection = $this->normalizeChannelSelection((string) ($payload['channel'] ?? 'both'));
        $counts = $this->selectedAudienceSummary($selection);
        $channels = $this->channelsForSelection($selection);

        if ($counts['selected_unique'] <= 0) {
            throw ValidationException::withMessages([
                'channel' => 'No opted-in recipients with valid contact details are available for the selected channel.',
            ]);
        }

        $campaigns = [];
        foreach ($channels as $channel) {
            [$campaign, $recipientCount] = $this->buildCampaign($actor, $channel, $payload);
            if ($recipientCount <= 0) {
                continue;
            }

            $summary = $channel === 'sms'
                ? $this->smsExecutionService->sendApprovedForCampaign($campaign, [
                    'limit' => $recipientCount,
                    'actor_id' => (int) $actor->id,
                    'sender_key' => $this->nullableString($payload['sender_key'] ?? null),
                ])
                : $this->emailExecutionService->sendApprovedForCampaign($campaign, [
                    'limit' => $recipientCount,
                    'actor_id' => (int) $actor->id,
                ]);

            $campaign->forceFill([
                'status' => 'completed',
                'launched_at' => $campaign->launched_at ?: now(),
                'completed_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $campaigns[] = [
                'id' => (int) $campaign->id,
                'name' => (string) $campaign->name,
                'channel' => $channel,
                'recipient_count' => $recipientCount,
                'summary' => [
                    'processed' => (int) ($summary['processed'] ?? 0),
                    'sent' => (int) ($summary['sent'] ?? 0),
                    'failed' => (int) ($summary['failed'] ?? 0),
                    'skipped' => (int) ($summary['skipped'] ?? 0),
                ],
            ];
        }

        return [
            'selection' => $selection,
            'counts' => $counts,
            'campaigns' => $campaigns,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:MarketingCampaign,1:int}
     */
    protected function buildCampaign(User $actor, string $channel, array $payload): array
    {
        $messageText = $this->messageTextForChannel($channel, $payload);
        $emailSubject = $channel === 'email' ? trim((string) ($payload['email_subject'] ?? '')) : null;
        $ctaLink = trim((string) ($payload['cta_link'] ?? ''));
        $timestamp = now();

        $campaign = MarketingCampaign::query()->create([
            'name' => sprintf('All Opted-In %s %s', strtoupper($channel), $timestamp->format('Y-m-d H:i:s')),
            'slug' => sprintf('all-opted-in-%s-%s', $channel, $timestamp->format('YmdHis')),
            'description' => $this->campaignDescription($channel, $emailSubject, $ctaLink),
            'status' => 'active',
            'channel' => $channel,
            'objective' => null,
            'attribution_window_days' => 7,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'launched_at' => $timestamp,
        ]);

        $variant = MarketingCampaignVariant::query()->create([
            'campaign_id' => $campaign->id,
            'name' => sprintf('All Opted-In %s', strtoupper($channel)),
            'variant_key' => 'all_opted_in',
            'message_text' => $messageText,
            'weight' => 100,
            'is_control' => true,
            'status' => 'active',
            'notes' => 'Quick send to all opted-in customers.',
        ]);

        $recipientCount = 0;
        $this->candidateQueryForChannel($channel)
            ->chunkById(500, function ($profiles) use (&$recipientCount, $campaign, $variant, $actor, $channel, $emailSubject, $ctaLink, $timestamp): void {
                $rows = [];

                foreach ($profiles as $profile) {
                    if (! $this->profileEligibleForChannel($profile, $channel)) {
                        continue;
                    }

                    $recipientCount++;
                    $rows[] = [
                        'campaign_id' => $campaign->id,
                        'marketing_profile_id' => $profile->id,
                        'segment_snapshot' => json_encode([
                            'audience' => 'all_opted_in',
                            'created_via' => 'quick_send',
                            'channel' => $channel,
                            'cta_link' => $ctaLink !== '' ? $ctaLink : null,
                            'initiated_by' => $actor->id,
                            'matched_at' => $timestamp->toIso8601String(),
                        ], JSON_UNESCAPED_SLASHES),
                        'recommendation_snapshot' => json_encode([
                            'created_via' => 'quick_send',
                            'email_subject' => $emailSubject !== '' ? $emailSubject : null,
                            'sender_key' => $channel === 'sms'
                                ? $this->nullableString($payload['sender_key'] ?? null)
                                : null,
                        ], JSON_UNESCAPED_SLASHES),
                        'variant_id' => $variant->id,
                        'channel' => $channel,
                        'status' => 'approved',
                        'send_attempt_count' => 0,
                        'reason_codes' => json_encode(['quick_send', 'all_opted_in'], JSON_UNESCAPED_SLASHES),
                        'approved_by' => $actor->id,
                        'approved_at' => $timestamp,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($rows !== []) {
                    MarketingCampaignRecipient::query()->insert($rows);
                }
            });

        return [$campaign, $recipientCount];
    }

    protected function candidateQueryForChannel(string $channel): Builder
    {
        $query = MarketingProfile::query()
            ->select([
                'id',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_email_marketing',
                'accepts_sms_marketing',
            ])
            ->orderBy('id');

        if ($channel === 'sms') {
            $query->where('accepts_sms_marketing', true)
                ->where(function (Builder $nested): void {
                    $nested->whereNotNull('normalized_phone')
                        ->where('normalized_phone', '!=', '')
                        ->orWhere(function (Builder $phoneQuery): void {
                            $phoneQuery->whereNotNull('phone')->where('phone', '!=', '');
                        });
                });
        } else {
            $query->where('accepts_email_marketing', true)
                ->where(function (Builder $nested): void {
                    $nested->whereNotNull('normalized_email')
                        ->where('normalized_email', '!=', '')
                        ->orWhere(function (Builder $emailQuery): void {
                            $emailQuery->whereNotNull('email')->where('email', '!=', '');
                        });
                });
        }

        return $query;
    }

    protected function profileEligibleForChannel(MarketingProfile $profile, string $channel): bool
    {
        return $channel === 'sms'
            ? $this->sendableSmsPhone($profile) !== null
            : $this->sendableEmailAddress($profile) !== null;
    }

    protected function sendableSmsPhone(MarketingProfile $profile): ?string
    {
        if (! (bool) $profile->accepts_sms_marketing) {
            return null;
        }

        return $this->normalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone));
    }

    protected function sendableEmailAddress(MarketingProfile $profile): ?string
    {
        if (! (bool) $profile->accepts_email_marketing) {
            return null;
        }

        return $this->normalizer->normalizeEmail((string) ($profile->normalized_email ?: $profile->email));
    }

    protected function messageTextForChannel(string $channel, array $payload): string
    {
        $body = $channel === 'sms'
            ? (string) ($payload['sms_body'] ?? '')
            : (string) ($payload['email_body'] ?? '');

        $ctaLink = trim((string) ($payload['cta_link'] ?? ''));
        if ($ctaLink === '') {
            return trim($body);
        }

        $separator = $channel === 'sms' ? "\n" : "\n\n";

        return trim(rtrim($body) . $separator . $ctaLink);
    }

    protected function renderPreviewText(string $text, MarketingProfile $profile, string $ctaLink, string $channel): string
    {
        return trim($this->templateRenderer->renderText(
            $this->messageTextForChannel($channel, [
                'sms_body' => $text,
                'email_body' => $text,
                'cta_link' => $ctaLink,
            ]),
            $profile
        ));
    }

    protected function previewProfileForActor(User $actor): MarketingProfile
    {
        $normalizedEmail = $this->normalizer->normalizeEmail((string) $actor->email);
        if ($normalizedEmail !== null) {
            $existing = MarketingProfile::query()
                ->where('normalized_email', $normalizedEmail)
                ->orWhere('email', $normalizedEmail)
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        [$firstName] = $this->normalizer->splitName((string) $actor->name);

        return new MarketingProfile([
            'first_name' => $firstName ?: 'there',
            'email' => $normalizedEmail,
            'normalized_email' => $normalizedEmail,
        ]);
    }

    /**
     * @return array<int,string>
     */
    protected function channelsForSelection(string $selection): array
    {
        return match ($selection) {
            'sms' => ['sms'],
            'email' => ['email'],
            default => ['sms', 'email'],
        };
    }

    protected function normalizeChannelSelection(string $selection): string
    {
        $selection = strtolower(trim($selection));

        return in_array($selection, ['sms', 'email', 'both'], true)
            ? $selection
            : 'both';
    }

    protected function campaignDescription(string $channel, ?string $emailSubject, string $ctaLink): string
    {
        $parts = [
            'Quick send to all opted-in customers.',
            'Channel: ' . strtoupper($channel),
        ];

        if ($emailSubject !== null && trim($emailSubject) !== '') {
            $parts[] = 'Subject: ' . trim($emailSubject);
        }

        if ($ctaLink !== '') {
            $parts[] = 'CTA: ' . $ctaLink;
        }

        return implode(' ', $parts);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
