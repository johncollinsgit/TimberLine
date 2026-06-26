<?php

namespace App\Services\Mobile;

use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Models\MobilePushDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ModernForestryApnsService
{
    protected ?string $cachedBearerToken = null;

    protected ?int $cachedBearerTokenIssuedAt = null;

    /**
     * @return array{sent:int,failed:int,skipped:int}
     */
    public function sendAccountMessageNotification(
        MessagingConversation $conversation,
        MessagingConversationMessage $message
    ): array {
        if (! $this->enabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 1];
        }

        $config = $this->config();
        if ($config === null) {
            Log::warning('Modern Forestry APNs configuration is incomplete.');

            return ['sent' => 0, 'failed' => 0, 'skipped' => 1];
        }

        $profileId = (int) ($conversation->marketing_profile_id ?? 0);
        if ($profileId <= 0) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 1];
        }

        $devices = MobilePushDevice::query()
            ->where('tenant_id', (int) $conversation->tenant_id)
            ->where('marketing_profile_id', $profileId)
            ->where('platform', 'ios')
            ->where('push_enabled', true)
            ->whereIn('authorization_status', ['authorized', 'provisional', 'ephemeral'])
            ->get();

        if ($devices->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 1];
        }

        try {
            $bearerToken = $this->bearerToken($config);
        } catch (Throwable $exception) {
            Log::warning('Modern Forestry APNs token generation failed.', [
                'message' => $exception->getMessage(),
            ]);

            return ['sent' => 0, 'failed' => 0, 'skipped' => $devices->count()];
        }

        $badgeCount = $this->unreadAccountMessageCount((int) $conversation->tenant_id, $profileId);
        $payload = $this->payload($conversation, $message, $badgeCount);
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($devices as $device) {
            $response = $this->sendToDevice($device, $config, $bearerToken, $payload);

            if ($response['state'] === 'sent') {
                $results['sent']++;
                continue;
            }

            if ($response['state'] === 'skipped') {
                $results['skipped']++;
                continue;
            }

            $results['failed']++;
        }

        return $results;
    }

    protected function enabled(): bool
    {
        return (bool) config('services.modern_forestry_apns.enabled', false);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function config(): ?array
    {
        $authKey = $this->authKey();
        $teamId = trim((string) config('services.modern_forestry_apns.team_id', ''));
        $keyId = trim((string) config('services.modern_forestry_apns.key_id', ''));
        $bundleId = trim((string) config('services.modern_forestry_apns.bundle_id', ''));
        $environment = strtolower(trim((string) config('services.modern_forestry_apns.environment', 'production')));

        if (! in_array($environment, ['production', 'development', 'sandbox'], true)) {
            $environment = 'production';
        }

        if ($authKey === null || $teamId === '' || $keyId === '' || $bundleId === '') {
            return null;
        }

        return [
            'auth_key' => $authKey,
            'team_id' => $teamId,
            'key_id' => $keyId,
            'bundle_id' => $bundleId,
            'environment' => $environment,
            'timeout' => max(3, (int) config('services.modern_forestry_apns.timeout', 10)),
        ];
    }

    protected function authKey(): ?string
    {
        $inline = trim((string) config('services.modern_forestry_apns.auth_key', ''));
        if ($inline !== '') {
            return str_replace('\n', "\n", $inline);
        }

        $base64 = trim((string) config('services.modern_forestry_apns.auth_key_base64', ''));
        if ($base64 !== '') {
            $decoded = base64_decode($base64, true);

            return $decoded !== false ? $decoded : null;
        }

        $path = trim((string) config('services.modern_forestry_apns.auth_key_path', ''));
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function bearerToken(array $config): string
    {
        $now = now()->timestamp;
        if ($this->cachedBearerToken !== null
            && $this->cachedBearerTokenIssuedAt !== null
            && ($now - $this->cachedBearerTokenIssuedAt) < (50 * 60)) {
            return $this->cachedBearerToken;
        }

        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'ES256',
            'kid' => $config['key_id'],
        ], JSON_THROW_ON_ERROR));

        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $config['team_id'],
            'iat' => $now,
        ], JSON_THROW_ON_ERROR));

        $unsignedToken = $header.'.'.$claims;
        $privateKey = openssl_pkey_get_private((string) $config['auth_key']);

        if ($privateKey === false) {
            throw new \RuntimeException('APNs private key could not be loaded.');
        }

        $signature = '';
        $signed = openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKey);

        if (! $signed) {
            throw new \RuntimeException('APNs JWT signing failed.');
        }

        $this->cachedBearerTokenIssuedAt = $now;
        $this->cachedBearerToken = $unsignedToken.'.'.$this->derToJoseSignature($signature, 64);

        return $this->cachedBearerToken;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $payload
     * @return array{state:string,status:int|null}
     */
    protected function sendToDevice(
        MobilePushDevice $device,
        array $config,
        string $bearerToken,
        array $payload
    ): array {
        $deviceToken = trim((string) $device->device_token);
        if ($deviceToken === '') {
            return ['state' => 'skipped', 'status' => null];
        }

        $host = $config['environment'] === 'production'
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';
        $url = $host.'/3/device/'.$deviceToken;

        try {
            $response = Http::timeout((int) $config['timeout'])
                ->withHeaders([
                    'authorization' => 'bearer '.$bearerToken,
                    'apns-topic' => (string) $config['bundle_id'],
                    'apns-push-type' => 'alert',
                    'apns-priority' => '10',
                    'apns-collapse-id' => 'modern-forestry-support-'.(int) $payload['mf_conversation_id'],
                ])
                ->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')
                ->send('POST', $url);
        } catch (Throwable $exception) {
            Log::warning('Modern Forestry APNs request failed.', [
                'device_id' => (int) $device->id,
                'tenant_id' => (int) $device->tenant_id,
                'marketing_profile_id' => (int) $device->marketing_profile_id,
                'message' => $exception->getMessage(),
            ]);

            return ['state' => 'failed', 'status' => null];
        }

        if ($response->successful()) {
            $device->forceFill([
                'last_seen_at' => now(),
            ])->save();

            return ['state' => 'sent', 'status' => $response->status()];
        }

        $reason = trim((string) data_get($response->json(), 'reason', ''));
        if (in_array($reason, ['BadDeviceToken', 'DeviceTokenNotForTopic', 'Unregistered'], true)) {
            $device->forceFill([
                'push_enabled' => false,
                'authorization_status' => 'invalid_token',
            ])->save();
        }

        Log::warning('Modern Forestry APNs push was rejected.', [
            'device_id' => (int) $device->id,
            'tenant_id' => (int) $device->tenant_id,
            'marketing_profile_id' => (int) $device->marketing_profile_id,
            'status' => $response->status(),
            'reason' => $reason !== '' ? $reason : null,
            'body' => $response->body(),
        ]);

        return ['state' => 'failed', 'status' => $response->status()];
    }

    protected function unreadAccountMessageCount(int $tenantId, int $profileId): int
    {
        return MessagingConversationMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('marketing_profile_id', $profileId)
            ->where('message_type', 'app_message')
            ->where('direction', 'outbound')
            ->whereNull('customer_read_at')
            ->count();
    }

    /**
     * @return array<string,mixed>
     */
    protected function payload(
        MessagingConversation $conversation,
        MessagingConversationMessage $message,
        int $badgeCount
    ): array {
        $body = Str::limit(trim((string) $message->body), 160, '...');

        return [
            'aps' => [
                'alert' => [
                    'title' => 'Modern Forestry',
                    'subtitle' => 'App-only message',
                    'body' => $body !== '' ? $body : 'You have a new message waiting in the app.',
                ],
                'badge' => max(0, $badgeCount),
                'sound' => 'default',
                'thread-id' => 'modern-forestry-support',
            ],
            'mf_type' => 'account_message',
            'mf_route' => 'account',
            'mf_conversation_id' => (int) $conversation->id,
            'mf_unread_count' => max(0, $badgeCount),
        ];
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function derToJoseSignature(string $der, int $partLength): string
    {
        $offset = 3;
        $length = ord($der[1]);
        if ($length & 0x80) {
            $lengthOfLength = $length & 0x1f;
            $offset = 2 + $lengthOfLength + 1;
        }

        $rLength = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLength);
        $offset += 1 + $rLength + 1;
        $sLength = ord($der[$offset - 1]);
        $s = substr($der, $offset, $sLength);

        return $this->base64UrlEncode(
            str_pad(ltrim($r, "\x00"), $partLength / 2, "\x00", STR_PAD_LEFT)
            .str_pad(ltrim($s, "\x00"), $partLength / 2, "\x00", STR_PAD_LEFT)
        );
    }
}
