<?php

namespace App\Services\Shopify;

use App\Models\MarketingProfile;
use App\Services\Marketing\MarketingDirectMessagingService;
use App\Services\Marketing\TwilioSenderConfigService;

class ShopifyEmbeddedCustomerMessagingService
{
    public function __construct(
        protected MarketingDirectMessagingService $directMessagingService,
        protected TwilioSenderConfigService $senderConfigService
    ) {
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function sendSms(MarketingProfile $profile, string $message, ?int $actorId, ?string $senderKey = null): array
    {
        if (! $this->smsSupported()) {
            return [
                'ok' => false,
                'message' => 'SMS sending is currently disabled for this app.',
            ];
        }

        $phone = trim((string) ($profile->normalized_phone ?: $profile->phone));
        if ($phone === '') {
            return [
                'ok' => false,
                'message' => 'SMS not sent: no phone number is on file for this customer.',
            ];
        }

        if (! (bool) $profile->accepts_sms_marketing) {
            return [
                'ok' => false,
                'message' => 'SMS not sent: the customer has not granted SMS consent yet.',
            ];
        }

        $summary = $this->directMessagingService->send('sms', [
            [
                'profile_id' => $profile->id,
                'name' => trim((string) ($profile->first_name . ' ' . $profile->last_name)),
                'email' => $profile->email,
                'phone' => $profile->phone,
                'normalized_phone' => $profile->normalized_phone,
                'source_type' => 'shopify_embedded_admin',
            ],
        ], $message, [
            'actor_id' => $actorId,
            'source_label' => 'shopify_embedded_customer_detail',
            'sender_key' => $senderKey,
        ]);

        if ((int) ($summary['sent'] ?? 0) > 0) {
            return [
                'ok' => true,
                'message' => 'SMS sent successfully.',
            ];
        }

        if ((int) ($summary['failed'] ?? 0) > 0) {
            $error = trim((string) ($summary['first_error_message'] ?? '')) ?: 'SMS sending failed.';

            return [
                'ok' => false,
                'message' => $error,
            ];
        }

        return [
            'ok' => false,
            'message' => 'SMS was not sent. Please confirm consent and phone number.',
        ];
    }

    public function smsSupported(): bool
    {
        return $this->senderConfigService->smsSupported();
    }
}
