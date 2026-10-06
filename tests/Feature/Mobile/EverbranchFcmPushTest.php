<?php

use App\Jobs\SendFieldServicePushNotification;
use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mobile\EverbranchApnsService;
use App\Services\Mobile\EverbranchFcmService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

function configureEverbranchFcm(): void
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privateKey);

    config()->set('services.everbranch_apns.enabled', false);
    config()->set('services.everbranch_fcm', [
        'enabled' => true,
        'project_id' => 'everbranch-test-project',
        'client_email' => 'push-test@everbranch-test-project.iam.gserviceaccount.com',
        'private_key' => $privateKey,
        'private_key_base64' => null,
        'private_key_path' => null,
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'timeout' => 10,
    ]);
}

/** @return array{0:FieldServiceJobNotification,1:EverbranchMobilePushDevice} */
function androidFieldNotification(): array
{
    $tenant = Tenant::query()->create(['name' => 'Android Push Test', 'slug' => 'android-push-test']);
    $user = User::factory()->create();
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Panel replacement',
        'status' => 'open',
    ]);
    $notification = FieldServiceJobNotification::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $job->id,
        'user_id' => $user->id,
        'channel' => 'push',
        'event_type' => 'comment',
        'event_key' => 'comment:android-test',
        'status' => 'queued',
        'metadata' => [
            'title' => 'New job message',
            'body' => 'Andrew posted a photo.',
            'workspace_slug' => $tenant->slug,
        ],
    ]);
    $token = 'android-registration-token';
    $device = EverbranchMobilePushDevice::query()->create([
        'user_id' => $user->id,
        'platform' => 'android',
        'device_token' => $token,
        'device_token_hash' => hash('sha256', $token),
        'app_version' => '2.3.21',
        'device_name' => 'Android Test Phone',
        'notifications_enabled' => true,
    ]);

    return [$notification, $device];
}

test('the mobile account endpoint registers an android fcm token', function (): void {
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    Sanctum::actingAs($user, ['mobile:write']);

    $this->postJson('/api/mobile/v1/account/push-device', [
        'platform' => 'android',
        'device_token' => 'android-endpoint-token',
        'app_version' => '2.3.21',
        'device_name' => 'Employee Android',
    ])->assertCreated()->assertJsonPath('ok', true);

    $device = EverbranchMobilePushDevice::query()->sole();
    expect($device->user_id)->toBe($user->id)
        ->and($device->platform)->toBe('android')
        ->and($device->device_token)->toBe('android-endpoint-token')
        ->and($device->notifications_enabled)->toBeTrue();
});

test('the push job delivers field notifications to android through fcm http v1', function (): void {
    configureEverbranchFcm();
    [$notification, $device] = androidFieldNotification();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fcm-oauth-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'https://fcm.googleapis.com/v1/projects/everbranch-test-project/messages:send' => Http::response([
            'name' => 'projects/everbranch-test-project/messages/message-1',
        ]),
    ]);

    (new SendFieldServicePushNotification((int) $notification->id))->handle(
        app(EverbranchApnsService::class),
        app(EverbranchFcmService::class),
    );

    expect($notification->fresh()->status)->toBe('sent')
        ->and($notification->fresh()->failure_code)->toBeNull()
        ->and($notification->fresh()->sent_at)->not->toBeNull()
        ->and($device->fresh()->last_seen_at)->not->toBeNull()
        ->and($device->fresh()->notifications_enabled)->toBeTrue();

    Http::assertSent(function (Request $request) use ($notification): bool {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && substr_count((string) $request['assertion'], '.') === 2;
        }

        return $request->url() === 'https://fcm.googleapis.com/v1/projects/everbranch-test-project/messages:send'
            && $request->hasHeader('Authorization', 'Bearer fcm-oauth-token')
            && $request['message']['token'] === 'android-registration-token'
            && $request['message']['notification']['title'] === 'New job message'
            && $request['message']['data']['job_id'] === (string) $notification->field_service_job_id
            && $request['message']['android']['priority'] === 'HIGH';
    });
});

test('fcm unregistered responses disable only the rejected android device', function (): void {
    configureEverbranchFcm();
    [$notification, $device] = androidFieldNotification();

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fcm-oauth-token', 'expires_in' => 3600]),
        'https://fcm.googleapis.com/v1/projects/everbranch-test-project/messages:send' => Http::response([
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                    'errorCode' => 'UNREGISTERED',
                ]],
            ],
        ], 404),
    ]);

    $result = app(EverbranchFcmService::class)->send($notification);

    expect($result)->toBe(['sent' => 0, 'failed' => 1, 'skipped' => 0])
        ->and($device->fresh()->notifications_enabled)->toBeFalse();
});

test('android devices are skipped without complete fcm credentials', function (): void {
    config()->set('services.everbranch_fcm.enabled', true);
    config()->set('services.everbranch_fcm.project_id', null);
    [$notification, $device] = androidFieldNotification();

    Http::fake();
    $result = app(EverbranchFcmService::class)->send($notification);

    expect($result)->toBe(['sent' => 0, 'failed' => 0, 'skipped' => 1])
        ->and($device->fresh()->notifications_enabled)->toBeTrue();
    Http::assertNothingSent();
});
