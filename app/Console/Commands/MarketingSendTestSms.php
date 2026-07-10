<?php

namespace App\Console\Commands;

use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Marketing\TwilioSmsService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Console\Command;

class MarketingSendTestSms extends Command
{
    protected $signature = 'marketing:send-test-sms
        {to : Recipient phone number}
        {message : SMS body}
        {--tenant= : Tenant ID when isolated messaging is enabled}
        {--sender= : Sender key such as toll_free or local}';

    protected $description = 'Send a one-off SMS through the configured Twilio sender stack.';

    public function handle(
        TwilioSmsService $twilioSmsService,
        TwilioSenderConfigService $senderConfigService,
        MarketingIdentityNormalizer $normalizer
    ): int {
        $to = trim((string) $this->argument('to'));
        $message = trim((string) $this->argument('message'));
        $senderKey = $this->nullableString($this->option('sender'));
        $platformManaged = (bool) config('features.tenant_messaging_platform');
        $tenantId = is_numeric($this->option('tenant')) ? (int) $this->option('tenant') : null;

        if ($to === '') {
            $this->error('Recipient phone number is required.');

            return self::FAILURE;
        }

        if ($message === '') {
            $this->error('Message body is required.');

            return self::FAILURE;
        }

        $normalizedTo = $normalizer->toE164($to);
        if ($normalizedTo === null) {
            $this->error('Recipient phone number must be a valid US phone number.');

            return self::FAILURE;
        }

        if (! (bool) config('marketing.sms.enabled')) {
            $this->error('MARKETING_SMS_ENABLED must be true for a live verification send.');

            return self::FAILURE;
        }

        if (! (bool) config('marketing.twilio.enabled')) {
            $this->error('MARKETING_TWILIO_ENABLED must be true for a live verification send.');

            return self::FAILURE;
        }

        if ((bool) config('marketing.sms.dry_run')) {
            $this->error('MARKETING_SMS_DRY_RUN=true blocks live sends. Disable it before running this command.');

            return self::FAILURE;
        }

        if ($platformManaged && ($tenantId === null || $tenantId <= 0)) {
            $this->error('Provide --tenant=<id> when isolated tenant messaging is enabled.');

            return self::FAILURE;
        }

        $sender = null;
        $senderPath = 'tenant_messaging_service';
        if (! $platformManaged) {
            $accountSid = trim((string) config('marketing.twilio.account_sid', ''));
            $authToken = trim((string) config('marketing.twilio.auth_token', ''));
            if ($accountSid === '' || $authToken === '') {
                $this->error($accountSid === '' ? 'Missing config: TWILIO_ACCOUNT_SID' : 'Missing config: TWILIO_AUTH_TOKEN');

                return self::FAILURE;
            }

            $resolution = $senderConfigService->resolveForSend($senderKey);
            $sender = $resolution['sender'] ?? null;
            if (($resolution['error'] ?? null) !== null || $sender === null) {
                $this->error((string) ($resolution['error']['message'] ?? 'Unable to resolve an SMS sender.'));

                return self::FAILURE;
            }
            $senderPath = $this->senderPath($sender);
            if ($senderPath === null) {
                $this->error('Selected sender is enabled but missing both messaging_service_sid and from_number.');

                return self::FAILURE;
            }
        }

        $result = $twilioSmsService->sendSms($normalizedTo, $message, [
            'sender_key' => $senderKey,
            'tenant_id' => $tenantId,
        ]);

        $this->line('to='.$normalizedTo);
        $this->line('sender_key='.(string) ($result['sender_key'] ?? data_get($sender, 'key', '')));
        $this->line('sender_label='.(string) ($result['sender_label'] ?? data_get($sender, 'label', '')));
        $this->line('sender_path='.$senderPath);
        $this->line('from_identifier='.(string) ($result['from_identifier'] ?? data_get($sender, 'from_identifier', '')));
        $this->line('status='.(string) ($result['status'] ?? 'unknown'));

        if ((bool) ($result['success'] ?? false)) {
            $this->info('twilio_message_sid='.(string) ($result['provider_message_id'] ?? ''));

            return self::SUCCESS;
        }

        $this->error('error_code='.(string) ($result['error_code'] ?? 'unknown'));
        $this->error('error_message='.(string) ($result['error_message'] ?? 'SMS send failed.'));

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $sender
     */
    protected function senderPath(array $sender): ?string
    {
        if (trim((string) ($sender['messaging_service_sid'] ?? '')) !== '') {
            return 'messaging_service_sid';
        }

        if (trim((string) ($sender['from_number'] ?? '')) !== '') {
            return 'from_number';
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
