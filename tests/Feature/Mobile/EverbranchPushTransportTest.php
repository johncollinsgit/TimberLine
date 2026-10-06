<?php

use App\Models\EverbranchMobilePushDevice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\TeamCommunicationService;
use App\Services\Mobile\EverbranchApnsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('Everbranch uses HTTP2 and retries development device tokens without disabling them', function (): void {
    Queue::fake();
    $tenant = Tenant::query()->create(['name' => 'Transport Crew', 'slug' => 'transport-crew']);
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    foreach ([$sender, $recipient] as $user) {
        $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    }
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privateKey);
    config()->set('services.everbranch_apns', ['enabled' => true, 'team_id' => 'TESTTEAM', 'key_id' => 'TESTKEY', 'bundle_id' => 'com.everbranch.app', 'environment' => 'production', 'auth_key' => $privateKey]);
    $device = EverbranchMobilePushDevice::query()->create(['user_id' => $recipient->id, 'platform' => 'ios', 'device_token' => str_repeat('a', 64), 'device_token_hash' => hash('sha256', str_repeat('a', 64)), 'notifications_enabled' => true]);
    $team = app(TeamCommunicationService::class);
    $channel = $team->directChannel($tenant, $sender, $recipient);
    $message = $team->post($tenant, $sender, $channel, 'Private file details', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    Http::fake(['api.push.apple.com/*' => Http::response(['reason' => 'BadDeviceToken'], 400), 'api.sandbox.push.apple.com/*' => Http::response('', 200)]);
    expect(app(EverbranchApnsService::class)->sendTeamMessage($message, $channel, collect([(int) $recipient->id])))->toBe(['sent' => 1, 'failed' => 0, 'skipped' => 0]);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->toPsrRequest()->getProtocolVersion() === '2' && $request['team_channel_id'] === (int) $channel->id && ! str_contains($request->body(), 'Private file details'));
    expect($device->fresh()->notifications_enabled)->toBeTrue();
});
