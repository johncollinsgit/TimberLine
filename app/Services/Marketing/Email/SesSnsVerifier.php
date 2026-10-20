<?php

namespace App\Services\Marketing\Email;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\Http;

class SesSnsVerifier
{
    public function valid(Message $message): bool
    {
        // A valid AWS signature alone does not authorize another account's topic.
        if (! in_array((string) ($message['TopicArn'] ?? ''), config('ses_migration.sns_topic_arns', []), true)) {
            return false;
        }
        $topic = explode(':', (string) $message['TopicArn']);
        $region = $topic[3] ?? '';
        if (($topic[2] ?? '') !== 'sns' || $region !== config('services.ses.region')
            || ($topic[4] ?? '') !== (string) config('services.ses.account_id')) {
            return false;
        }
        $url = parse_url((string) ($message['SigningCertURL'] ?? ''));
        if (($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'sns.'.$region.'.amazonaws.com'
            || isset($url['port']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! preg_match('~^/SimpleNotificationService-[A-Za-z0-9_-]+\.pem$~D', $url['path'] ?? '')) {
            return false;
        }
        try {
            return (new MessageValidator(fn (string $certUrl) => Http::timeout(5)->withoutRedirecting()->get($certUrl)->throw()->body()))->isValid($message);
        } catch (\Throwable) {
            return false;
        }
    }
}
