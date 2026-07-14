<?php

namespace App\Services\Mobile;

use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceJobNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class EverbranchApnsService
{
    private ?string $cachedBearerToken = null;

    private ?int $cachedBearerTokenIssuedAt = null;

    /** @return array{sent:int,failed:int,skipped:int} */
    public function send(FieldServiceJobNotification $notification): array
    {
        $config = $this->config();
        if ($config === null) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 1];
        }
        $devices = EverbranchMobilePushDevice::query()
            ->where('user_id', (int) $notification->user_id)
            ->where('platform', 'ios')->where('notifications_enabled', true)->get();
        if ($devices->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 1];
        }

        try {
            $token = $this->bearerToken($config);
        } catch (Throwable $exception) {
            Log::warning('Everbranch APNs token generation failed.', ['exception' => class_basename($exception)]);

            return ['sent' => 0, 'failed' => 0, 'skipped' => $devices->count()];
        }

        $payload = $this->payload($notification);
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($devices as $device) {
            $state = $this->sendToDevice($device, $config, $token, $payload, $notification);
            $results[$state]++;
        }

        return $results;
    }

    /** @return array<string,mixed>|null */
    private function config(): ?array
    {
        if (! config('services.everbranch_apns.enabled', false)) {
            return null;
        }
        $authKey = $this->authKey();
        $teamId = trim((string) config('services.everbranch_apns.team_id'));
        $keyId = trim((string) config('services.everbranch_apns.key_id'));
        $bundleId = trim((string) config('services.everbranch_apns.bundle_id', 'com.everbranch.app'));
        if (! $authKey || $teamId === '' || $keyId === '' || $bundleId === '') {
            return null;
        }

        return ['auth_key' => $authKey, 'team_id' => $teamId, 'key_id' => $keyId, 'bundle_id' => $bundleId,
            'environment' => strtolower((string) config('services.everbranch_apns.environment', 'production')),
            'timeout' => max(3, (int) config('services.everbranch_apns.timeout', 10))];
    }

    private function authKey(): ?string
    {
        $inline = trim((string) config('services.everbranch_apns.auth_key'));
        if ($inline !== '') {
            return str_replace('\\n', "\n", $inline);
        }
        $base64 = trim((string) config('services.everbranch_apns.auth_key_base64'));
        if ($base64 !== '') {
            return base64_decode($base64, true) ?: null;
        }
        $path = trim((string) config('services.everbranch_apns.auth_key_path'));
        if ($path === '' || ! is_readable($path)) {
            return null;
        }
        $contents = file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    /** @param array<string,mixed> $config */
    private function bearerToken(array $config): string
    {
        $now = now()->timestamp;
        if ($this->cachedBearerToken && $this->cachedBearerTokenIssuedAt && $now - $this->cachedBearerTokenIssuedAt < 3000) {
            return $this->cachedBearerToken;
        }
        $header = $this->base64Url(json_encode(['alg' => 'ES256', 'kid' => $config['key_id']], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode(['iss' => $config['team_id'], 'iat' => $now], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $privateKey = openssl_pkey_get_private((string) $config['auth_key']);
        if ($privateKey === false || ! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('APNs JWT signing failed.');
        }
        openssl_free_key($privateKey);
        $this->cachedBearerTokenIssuedAt = $now;

        return $this->cachedBearerToken = $unsigned.'.'.$this->derToJose($signature, 64);
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $payload */
    private function sendToDevice(EverbranchMobilePushDevice $device, array $config, string $token, array $payload, FieldServiceJobNotification $notification): string
    {
        $deviceToken = trim((string) $device->device_token);
        if ($deviceToken === '') {
            return 'skipped';
        }
        $host = $config['environment'] === 'production' ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
        try {
            $response = Http::timeout((int) $config['timeout'])->withHeaders([
                'authorization' => 'bearer '.$token,
                'apns-topic' => $config['bundle_id'],
                'apns-push-type' => 'alert',
                'apns-priority' => '10',
                'apns-collapse-id' => 'everbranch-job-'.(int) $notification->field_service_job_id,
            ])->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')->send('POST', $host.'/3/device/'.$deviceToken);
        } catch (Throwable $exception) {
            Log::warning('Everbranch APNs request failed.', ['device_id' => (int) $device->id, 'notification_id' => (int) $notification->id, 'exception' => class_basename($exception)]);

            return 'failed';
        }
        if ($response->successful()) {
            $device->forceFill(['last_seen_at' => now()])->save();

            return 'sent';
        }
        $reason = trim((string) data_get($response->json(), 'reason'));
        if (in_array($reason, ['BadDeviceToken', 'DeviceTokenNotForTopic', 'Unregistered'], true)) {
            $device->forceFill(['notifications_enabled' => false])->save();
        }
        Log::warning('Everbranch APNs push rejected.', ['device_id' => (int) $device->id, 'notification_id' => (int) $notification->id, 'status' => $response->status(), 'reason' => $reason ?: null]);

        return 'failed';
    }

    /** @return array<string,mixed> */
    private function payload(FieldServiceJobNotification $notification): array
    {
        return [
            'aps' => ['alert' => ['title' => (string) data_get($notification->metadata, 'title', 'Everbranch'), 'body' => (string) data_get($notification->metadata, 'body', 'A job was updated.')], 'sound' => 'default', 'thread-id' => 'everbranch-work'],
            'type' => 'field_service_job',
            'workspace_slug' => (string) data_get($notification->metadata, 'workspace_slug'),
            'job_id' => (int) $notification->field_service_job_id,
            'notification_id' => (int) $notification->id,
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function derToJose(string $der, int $length): string
    {
        $offset = 3;
        $derLength = ord($der[1]);
        if ($derLength & 0x80) {
            $offset = 3 + ($derLength & 0x1F);
        }
        $rLength = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLength);
        $offset += 1 + $rLength + 1;
        $sLength = ord($der[$offset - 1]);
        $s = substr($der, $offset, $sLength);

        return $this->base64Url(str_pad(ltrim($r, "\x00"), $length / 2, "\x00", STR_PAD_LEFT).str_pad(ltrim($s, "\x00"), $length / 2, "\x00", STR_PAD_LEFT));
    }
}
