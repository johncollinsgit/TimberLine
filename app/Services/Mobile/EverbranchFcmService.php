<?php

namespace App\Services\Mobile;

use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceJobNotification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class EverbranchFcmService
{
    private ?string $cachedAccessToken = null;

    private ?int $cachedAccessTokenExpiresAt = null;

    /** @return array{sent:int,failed:int,skipped:int} */
    public function send(FieldServiceJobNotification $notification): array
    {
        $devices = EverbranchMobilePushDevice::query()
            ->where('user_id', (int) $notification->user_id)
            ->where('platform', 'android')
            ->where('notifications_enabled', true)
            ->get();

        if ($devices->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $config = $this->config();
        if ($config === null) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => $devices->count()];
        }

        try {
            $accessToken = $this->accessToken($config);
        } catch (Throwable $exception) {
            Log::warning('Everbranch FCM access token generation failed.', [
                'exception' => class_basename($exception),
            ]);

            return ['sent' => 0, 'failed' => $devices->count(), 'skipped' => 0];
        }

        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($devices as $device) {
            $state = $this->sendToDevice($device, $config, $accessToken, $notification);
            $results[$state]++;
        }

        return $results;
    }

    /** @return array{project_id:string,client_email:string,private_key:string,token_uri:string,timeout:int}|null */
    private function config(): ?array
    {
        if (! config('services.everbranch_fcm.enabled', false)) {
            return null;
        }

        $projectId = trim((string) config('services.everbranch_fcm.project_id'));
        $clientEmail = trim((string) config('services.everbranch_fcm.client_email'));
        $privateKey = $this->privateKey();
        $tokenUri = trim((string) config('services.everbranch_fcm.token_uri', 'https://oauth2.googleapis.com/token'));

        if ($projectId === '' || $clientEmail === '' || $privateKey === null || $tokenUri === '') {
            return null;
        }

        return [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'token_uri' => $tokenUri,
            'timeout' => max(3, (int) config('services.everbranch_fcm.timeout', 10)),
        ];
    }

    private function privateKey(): ?string
    {
        $inline = trim((string) config('services.everbranch_fcm.private_key'));
        if ($inline !== '') {
            return str_replace('\\n', "\n", $inline);
        }

        $base64 = trim((string) config('services.everbranch_fcm.private_key_base64'));
        if ($base64 !== '') {
            return base64_decode($base64, true) ?: null;
        }

        $path = trim((string) config('services.everbranch_fcm.private_key_path'));
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    /** @param array{project_id:string,client_email:string,private_key:string,token_uri:string,timeout:int} $config */
    private function accessToken(array $config): string
    {
        $now = now()->timestamp;
        if ($this->cachedAccessToken !== null && $this->cachedAccessTokenExpiresAt !== null && $now < $this->cachedAccessTokenExpiresAt - 60) {
            return $this->cachedAccessToken;
        }

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $config['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $config['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $privateKey = openssl_pkey_get_private($config['private_key']);
        if ($privateKey === false || ! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('FCM service account JWT signing failed.');
        }

        $response = Http::asForm()
            ->timeout($config['timeout'])
            ->post($config['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$this->base64Url($signature),
            ]);

        $response->throw();
        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new \RuntimeException('FCM OAuth response did not include an access token.');
        }

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + max(60, (int) $response->json('expires_in', 3600));

        return $token;
    }

    /** @param array{project_id:string,client_email:string,private_key:string,token_uri:string,timeout:int} $config */
    private function sendToDevice(EverbranchMobilePushDevice $device, array $config, string $accessToken, FieldServiceJobNotification $notification): string
    {
        $deviceToken = trim((string) $device->device_token);
        if ($deviceToken === '') {
            return 'skipped';
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout($config['timeout'])
                ->post('https://fcm.googleapis.com/v1/projects/'.$config['project_id'].'/messages:send', [
                    'message' => [
                        'token' => $deviceToken,
                        'notification' => [
                            'title' => (string) data_get($notification->metadata, 'title', 'Everbranch'),
                            'body' => (string) data_get($notification->metadata, 'body', 'A job was updated.'),
                        ],
                        'data' => [
                            'type' => 'field_service_job',
                            'workspace_slug' => (string) data_get($notification->metadata, 'workspace_slug'),
                            'job_id' => (string) ((int) $notification->field_service_job_id),
                            'notification_id' => (string) ((int) $notification->id),
                        ],
                        'android' => [
                            'priority' => 'HIGH',
                            'collapse_key' => 'everbranch-job-'.(int) $notification->field_service_job_id,
                            'notification' => ['sound' => 'default'],
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::warning('Everbranch FCM request failed.', [
                'device_id' => (int) $device->id,
                'notification_id' => (int) $notification->id,
                'exception' => class_basename($exception),
            ]);

            return 'failed';
        }

        if ($response->successful()) {
            $device->forceFill(['last_seen_at' => now()])->save();

            return 'sent';
        }

        if ($this->isUnregistered($response)) {
            $device->forceFill(['notifications_enabled' => false])->save();
        }

        Log::warning('Everbranch FCM push rejected.', [
            'device_id' => (int) $device->id,
            'notification_id' => (int) $notification->id,
            'status' => $response->status(),
            'reason' => $response->json('error.status'),
        ]);

        return 'failed';
    }

    private function isUnregistered(Response $response): bool
    {
        return collect((array) $response->json('error.details', []))
            ->contains(fn (mixed $detail): bool => is_array($detail) && ($detail['errorCode'] ?? null) === 'UNREGISTERED');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
