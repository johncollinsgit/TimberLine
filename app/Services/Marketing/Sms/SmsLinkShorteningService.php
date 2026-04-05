<?php

namespace App\Services\Marketing\Sms;

class SmsLinkShorteningService
{
    /**
     * @param  array<string,mixed>  $options
     * @return array{message:string,provider:string,twilio_shorten_urls:bool}
     */
    public function prepareMessage(string $message, array $options = []): array
    {
        $resolved = trim($message);
        $requested = (bool) ($options['enabled'] ?? false);
        if (! $requested) {
            return [
                'message' => $resolved,
                'provider' => 'none',
                'twilio_shorten_urls' => false,
            ];
        }

        $provider = strtolower(trim((string) config('marketing.messaging.link_shortening.provider', 'twilio')));
        $twilioNativeEnabled = (bool) config('marketing.messaging.link_shortening.twilio_native_enabled', false);

        if ($provider === 'twilio' && $twilioNativeEnabled) {
            return [
                'message' => $resolved,
                'provider' => 'twilio_native',
                'twilio_shorten_urls' => true,
            ];
        }

        return [
            'message' => $resolved,
            'provider' => $provider !== '' ? $provider : 'noop',
            'twilio_shorten_urls' => false,
        ];
    }
}
