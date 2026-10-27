<?php

use App\Models\Tenant;
use App\Models\TenantMessagingAccount;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Soccer\SoccerFamilyService;
use App\Services\Soccer\SoccerSmsConsent;
use App\Services\Soccer\SoccerStore;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    $this->store = app(SoccerStore::class);
    $this->familyId = $this->store->insert('families', $this->tenant->id, ['email' => 'parent@example.com', 'profile' => $this->store->encrypt([]), 'verified_at' => now()]);
    $this->details = ['parentName' => 'Parent', 'phone' => '8645550100', 'emergencyContact' => 'Guardian', 'emergencyPhone' => '8645550101'];
});

it('allows registration contact details without enrolling in texts', function () {
    app(SoccerFamilyService::class)->saveProfile($this->tenant->id, $this->store->row('families', $this->tenant->id, $this->familyId), [...$this->details, 'sms_consent' => false]);
    expect($this->store->row('families', $this->tenant->id, $this->familyId)->sms_consent_at)->toBeNull()
        ->and($this->store->query('deliveries', $this->tenant->id)->count())->toBe(0);
});

it('requires the current disclosed choice and retains immutable consent and withdrawal evidence', function () {
    $families = app(SoccerFamilyService::class);
    $family = fn () => $this->store->row('families', $this->tenant->id, $this->familyId);
    expect(fn () => $families->saveProfile($this->tenant->id, $family(), [...$this->details, 'sms_consent' => true]))->toThrow(ValidationException::class);
    $data = [...$this->details, 'sms_consent' => true, 'sms_consent_version' => SoccerSmsConsent::VERSION];
    $families->saveProfile($this->tenant->id, $family(), $data);
    $families->saveProfile($this->tenant->id, $family(), $data);
    $grant = $this->store->query('events', $this->tenant->id)->where('action', 'sms_consent_granted')->sole();
    $evidence = $this->store->decrypt($grant->data);
    expect($evidence)->toMatchArray(['phone' => '+18645550100', 'consented' => true, 'version' => SoccerSmsConsent::VERSION, 'text' => SoccerSmsConsent::TEXT, 'source' => 'verified_family_portal'])
        ->and($evidence['recorded_at'])->not->toBeEmpty();
    $families->saveProfile($this->tenant->id, $family(), [...$this->details, 'sms_consent' => false]);
    expect($family()->sms_consent_at)->toBeNull()
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'sms_consent_revoked')->count())->toBe(1)
        ->and($this->store->query('events', $this->tenant->id)->where('id', $grant->id)->value('data'))->toBe($grant->data)
        ->and($this->store->query('deliveries', $this->tenant->id)->count())->toBe(0);
});

it('handles signed STOP without a conversation and returns HELP without enrolling anyone', function () {
    $families = app(SoccerFamilyService::class);
    $family = $this->store->row('families', $this->tenant->id, $this->familyId);
    $families->saveProfile($this->tenant->id, $family, [...$this->details, 'sms_consent' => true, 'sms_consent_version' => SoccerSmsConsent::VERSION]);
    $account = new TenantMessagingAccount(['tenant_id' => $this->tenant->id]);
    $this->mock(TenantMessagingGateway::class)->shouldReceive('validateTwilioCallback')->andReturn($account);
    $input = ['AccountSid' => 'AC'.str_repeat('a', 32), 'MessageSid' => 'SM'.str_repeat('b', 32), 'From' => '+18645550100', 'Body' => 'STOP'];
    $this->post('/api/soccer/sms', $input)->assertOk()->assertSee('unsubscribed');
    $this->post('/api/soccer/sms', $input)->assertOk();
    $this->post('/api/soccer/sms', [...$input, 'Body' => 'ARRET', 'OptOutType' => 'STOP'])->assertNoContent();
    expect($this->store->row('families', $this->tenant->id, $this->familyId)->sms_consent_at)->toBeNull()
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'sms_consent_revoked')->count())->toBe(1);
    $this->post('/api/soccer/sms', [...$input, 'Body' => 'HELP'])->assertOk()->assertSee('john@evergrovesoftware.com')->assertSee('Reply STOP');
    $this->post('/api/soccer/sms', [...$input, 'Body' => 'HELP', 'OptOutType' => 'HELP'])->assertNoContent();
    expect($this->store->query('deliveries', $this->tenant->id)->count())->toBe(0);
});

it('does not change family consent for an unverified callback', function () {
    $this->mock(TenantMessagingGateway::class)->shouldReceive('validateTwilioCallback')->andReturn(null);
    $this->post('/api/soccer/sms', ['Body' => 'STOP', 'From' => '+18645550100'])->assertForbidden();
    expect($this->store->query('events', $this->tenant->id)->count())->toBe(0);
});

it('sends and records sender identity and opt-out instructions once', function () {
    \Carbon\Carbon::setTestNow('2026-10-26 14:00:00 UTC');
    config()->set(['soccer.sms_enabled' => true, 'soccer.notification_mode' => 'sms']);
    app(SoccerFamilyService::class)->saveProfile($this->tenant->id, $this->store->row('families', $this->tenant->id, $this->familyId), [...$this->details, 'sms_consent' => true, 'sms_consent_version' => SoccerSmsConsent::VERSION]);
    $this->mock(TenantMessagingGateway::class)->shouldReceive('sendSms')->once()->with($this->tenant->id, '+18645550100', \Mockery::on(fn ($body) => str_starts_with($body, 'Wren Family Soccer:') && str_contains($body, 'Reply STOP to unsubscribe.') && str_contains($body, 'HELP for help.')), \Mockery::type('array'))->andReturn(['success' => true, 'provider_message_id' => 'SM'.str_repeat('c', 32)]);
    app(\App\Services\Soccer\SoccerEvents::class)->record($this->tenant->id, null, $this->familyId, 'test_update', [], familyText: 'Wren Soccer: your registration has an update.');
    app(\App\Services\Soccer\SoccerDeliveryService::class)->run($this->tenant->id);
    expect($this->store->query('deliveries', $this->tenant->id)->value('status'))->toBe('accepted');
    $body = \App\Models\MessagingConversationMessage::query()->forTenantId($this->tenant->id)->value('body');
    expect($body)->toContain('Reply STOP to unsubscribe.');
    expect(app(SoccerSmsConsent::class)->message($body))->toBe($body);
});
