<?php

use App\Models\Agreement;
use App\Models\OperatorAlertLog;
use App\Models\Tenant;
use App\Models\TenantSupportTicket;
use App\Models\User;
use App\Services\Marketing\TwilioSmsService;
use App\Services\Operations\OperatorAlertService;

beforeEach(function (): void {
    config()->set('everbranch.operator_alert_sms_enabled', true);
    config()->set('everbranch.operator_alert_phone', '+1 (555) 010-0101');
    config()->set('everbranch.operator_alert_sms_repeat_window_minutes', 360);
});

test('operator sms alerts send for a real event once and coalesce repeated same-message alerts', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Real Client', 'slug' => 'real-client']);
    $message = 'Everbranch: Real Client accepted Real Client Launch Agreement.';

    $twilio = \Mockery::mock(TwilioSmsService::class);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->with(
            '15550100101',
            $message,
            \Mockery::on(fn (array $options): bool => ($options['source_type'] ?? null) === 'operator_alert')
        )
        ->andReturn(['success' => true, 'provider' => 'twilio', 'error_code' => null]);
    app()->instance(TwilioSmsService::class, $twilio);

    $alerts = app(OperatorAlertService::class);
    $baseContext = [
        'tenant_id' => (int) $tenant->id,
        'tenant_name' => $tenant->name,
        'tenant_slug' => $tenant->slug,
        'target_type' => 'agreement',
        'request_host' => 'real-client.theeverbranch.com',
        'signer_email' => 'owner@realclient.com',
    ];

    $alerts->notify('agreement.accepted', $message, [...$baseContext, 'dedupe_key' => 'agreement-accepted:1:1', 'target_id' => 1]);
    $alerts->notify('agreement.accepted', $message, [...$baseContext, 'dedupe_key' => 'agreement-accepted:2:1', 'target_id' => 2]);

    expect(OperatorAlertLog::query()->count())->toBe(2)
        ->and(OperatorAlertLog::query()->where('status', 'sent')->count())->toBe(1)
        ->and(OperatorAlertLog::query()->where('status', 'suppressed')->count())->toBe(1)
        ->and(OperatorAlertLog::query()->latest('id')->first()?->metadata['reason'] ?? null)->toBe('similar_alert_recently_sent');
});

test('operator sms alerts suppress sandbox and test mode agreement acceptances', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Front Yard Foods', 'slug' => 'front-yard-foods']);
    $agreement = Agreement::query()->create([
        'tenant_id' => (int) $tenant->id,
        'agreement_type' => Agreement::TYPE_SANDBOX_VALIDATION,
        'template_key' => Agreement::TEMPLATE_FRONT_YARD_SANDBOX_VALIDATION,
        'title' => 'TEST MODE ONLY — Front Yard Foods LLC - Shopify Migration and Everbranch Launch Partner Agreement.',
        'status' => 'sent',
    ]);

    $twilio = \Mockery::mock(TwilioSmsService::class);
    $twilio->shouldReceive('sendSms')->never();
    app()->instance(TwilioSmsService::class, $twilio);

    app(OperatorAlertService::class)->notify(
        'agreement.accepted',
        "Everbranch: {$tenant->name} accepted {$agreement->title}.",
        [
            'dedupe_key' => 'agreement-accepted:'.$agreement->id.':1',
            'tenant_id' => (int) $tenant->id,
            'target_type' => 'agreement',
            'target_id' => (int) $agreement->id,
            'request_host' => 'front-yard-foods.theeverbranch.com',
            'signer_email' => 'real-owner@frontyardfoods.com',
        ]
    );

    $log = OperatorAlertLog::query()->firstOrFail();

    expect($log->status)->toBe('suppressed')
        ->and($log->metadata['reason'] ?? null)->toBe('non_real_event')
        ->and($log->metadata['reasons'] ?? [])->toContain('sandbox_validation_agreement')
        ->and($log->metadata['reasons'] ?? [])->toContain('test_mode_agreement');
});

test('operator sms alerts suppress test signer emails and non production hosts', function (): void {
    $twilio = \Mockery::mock(TwilioSmsService::class);
    $twilio->shouldReceive('sendSms')->never();
    app()->instance(TwilioSmsService::class, $twilio);

    app(OperatorAlertService::class)->notify('agreement.accepted', 'Everbranch: Front Yard Foods accepted Launch Agreement.', [
        'dedupe_key' => 'agreement-accepted:fake-email:1',
        'tenant_name' => 'Front Yard Foods',
        'tenant_slug' => 'front-yard-foods',
        'request_host' => 'evergrove.test',
        'signer_email' => 'laura@example.test',
    ]);

    $log = OperatorAlertLog::query()->firstOrFail();

    expect($log->status)->toBe('suppressed')
        ->and($log->metadata['reasons'] ?? [])->toContain('non_production_request_host')
        ->and($log->metadata['reasons'] ?? [])->toContain('test_signer_email');
});

test('operator sms alerts suppress known fake support ticket tenants', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Needs Help', 'slug' => 'needs-help']);
    $user = User::factory()->create();
    $ticket = TenantSupportTicket::withoutGlobalScopes()->create([
        'tenant_id' => (int) $tenant->id,
        'created_by_user_id' => (int) $user->id,
        'subject' => 'Cannot invite a crew member',
        'category' => 'access',
        'priority' => 'high',
        'status' => 'open',
        'source_type' => 'account_help',
        'last_activity_at' => now(),
    ]);

    $twilio = \Mockery::mock(TwilioSmsService::class);
    $twilio->shouldReceive('sendSms')->never();
    app()->instance(TwilioSmsService::class, $twilio);

    app(OperatorAlertService::class)->notify('support_ticket.created', 'Everbranch: HIGH ticket from Needs Help — Cannot invite a crew member', [
        'dedupe_key' => 'support-ticket:'.$ticket->id,
        'tenant_id' => (int) $tenant->id,
        'target_type' => 'tenant_support_ticket',
        'target_id' => (int) $ticket->id,
    ]);

    $log = OperatorAlertLog::query()->firstOrFail();

    expect($log->status)->toBe('suppressed')
        ->and($log->metadata['reason'] ?? null)->toBe('non_real_event')
        ->and($log->metadata['reasons'] ?? [])->toContain('test_tenant');
});
