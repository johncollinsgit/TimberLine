<?php

use App\Enterprise\EnterpriseMailboxGateway;
use App\Enterprise\IntelligenceStatus;
use App\Enterprise\MailboxGateway;
use App\Models\TeamOperationRecord;
use App\Models\TenantMailbox;
use App\Models\TenantMailDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
        'enterprise.email_drafts_enabled' => true, 'enterprise.mailbox.enabled' => false, 'enterprise.gmail.connection_enabled' => false]);
    Route::middleware('web')->prefix('enterprise-notification-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    app()->bind(MailboxGateway::class, EnterpriseMailboxGateway::class);
    Http::preventStrayRequests();
    Mail::fake();
});

function notificationReadinessFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = test()->actingAs($owner)->postJson(route('enterprise.commands', ['tenant' => $tenant->slug]), [
        'action' => 'workspace.create', 'title' => 'Fictional notification department', 'visibility' => 'shared',
        'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
    ])->assertOk()->json('id');

    return [$tenant, $owner, $area];
}

function notificationReadinessMailbox($tenant, $user): TenantMailbox
{
    $domain = TenantMailDomain::query()->create(['tenant_id' => $tenant->id, 'domain' => 'synthetic-'.Str::lower(Str::random(8)).'.example.invalid',
        'transport' => 'direct', 'status' => 'ready', 'verified_at' => now()]);
    $mailbox = TenantMailbox::query()->create(['tenant_id' => $tenant->id, 'tenant_mail_domain_id' => $domain->id,
        'address' => 'staff@'.$domain->domain, 'display_name' => 'Fictional staff', 'status' => 'ready', 'provider_credentials' => ['password' => 'synthetic-only-not-valid']]);
    $mailbox->users()->attach($user->id, ['tenant_id' => $tenant->id, 'permission' => 'read_write']);
    config(['enterprise.mailbox' => ['enabled' => true, 'send_enabled' => true, 'allowed_ids' => [(string) $mailbox->id]],
        'mailbox.direct_enabled' => true, 'mailbox.submission_host' => '127.0.0.1', 'mailbox.submission_port' => 587]);

    return $mailbox;
}

test('notification readiness separates native in-app progress from unavailable outbound and separate trial grants', function (): void {
    [$tenant, $owner, $area] = notificationReadinessFixture();
    config(['enterprise.trial.enabled' => true, 'enterprise.trial.channels.email.enabled' => true,
        'enterprise.trial.channels.sms.enabled' => true, 'enterprise_ses.send_enabled' => true, 'ses_migration.platform_enabled' => true]);
    $before = DB::table('team_operation_records')->get()->toJson();
    $result = $this->actingAs($owner)->getJson(route('enterprise.integrations', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk()->json('notifications');
    expect($result['in_app'])->toBe(['mode' => 'native_workspace', 'available' => true, 'reason_code' => 'current_authorized_workspace'])
        ->and($result['email']['configured'])->toBeFalse()->and($result['email']['dispatch_enabled'])->toBeFalse()
        ->and($result['email']['reason_code'])->toBe('own_mailbox_required')
        ->and($result['sms']['dispatch_enabled'])->toBeFalse()->and($result['sms']['reason_code'])->toBe('no_native_sms_adapter')
        ->and($result['controlled_trial'])->toBe(['mode' => 'separate_approved_session', 'evaluated' => false, 'reason_code' => 'separate_trial_session_required']);
    foreach (['email', 'sms'] as $channel) {
        expect($result[$channel]['automatic_notifications'])->toBeFalse()->and($result[$channel]['requires_confirmation'])->toBeTrue()
            ->and($result[$channel]['live_delivery_verified'])->toBeFalse();
    }
    expect(DB::table('team_operation_records')->get()->toJson())->toBe($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('global SMTP readiness and another users sender never authorize this user while exact own grant does', function (): void {
    [$tenant, $owner, $area] = notificationReadinessFixture();
    $other = teamMember($tenant, 'member');
    $mailbox = notificationReadinessMailbox($tenant, $other);
    $status = fn () => app(IntelligenceStatus::class)->forUser($tenant, $owner, $area);
    expect($status()['mailbox']['send_enabled'])->toBeTrue()->and($status()['notifications']['email']['dispatch_enabled'])->toBeFalse();
    $mailbox->users()->attach($owner->id, ['tenant_id' => $tenant->id, 'permission' => 'read_write']);
    config(['enterprise.mailbox.send_enabled' => false]);
    $disabled = $status()['notifications']['email'];
    expect($disabled['configured'])->toBeTrue()->and($disabled['dispatch_enabled'])->toBeFalse()->and($disabled['reason_code'])->toBe('sender_dispatch_disabled');
    config(['enterprise.mailbox.send_enabled' => true]);
    $ready = $status()['notifications']['email'];
    expect($ready['configured'])->toBeTrue()->and($ready['dispatch_enabled'])->toBeTrue()->and($ready['reason_code'])->toBe('exact_confirmation_required');
    expect(json_encode($ready))->not->toContain($mailbox->address)->not->toContain((string) $mailbox->id)->not->toContain('synthetic-only-not-valid');
    $mailbox->users()->detach($owner->id);
    expect($status()['notifications']['email']['dispatch_enabled'])->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('read-only and paused actors receive status without resolving write-only sender listings', function (): void {
    [$tenant, $owner, $area] = notificationReadinessFixture();
    $viewer = teamMember($tenant, 'viewer');
    $gateway = Mockery::mock(MailboxGateway::class);
    $gateway->shouldNotReceive('mailboxes');
    $gateway->shouldNotReceive('dispatch');
    app()->instance(MailboxGateway::class, $gateway);
    $this->actingAs($viewer)->getJson(route('enterprise.integrations', ['tenant' => $tenant->slug, 'area_id' => $area]))
        ->assertOk()->assertJsonPath('notifications.email.reason_code', 'read_only_scope')->assertJsonPath('notifications.email.dispatch_enabled', false);
    config(['enterprise.writes_enabled' => false]);
    expect(app(IntelligenceStatus::class)->forUser($tenant, $owner, $area)['notifications']['email']['reason_code'])->toBe('read_only_scope');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('scope and membership revocation deny readiness before sender lookup and provider IO', function (): void {
    [$tenant, $owner, $area] = notificationReadinessFixture();
    $gateway = Mockery::mock(MailboxGateway::class);
    $gateway->shouldNotReceive('mailboxes');
    $gateway->shouldNotReceive('dispatch');
    app()->instance(MailboxGateway::class, $gateway);
    $this->actingAs($owner)->getJson(route('enterprise.integrations', ['tenant' => $tenant->slug, 'area_id' => (string) Str::uuid()]))->assertNotFound();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $owner->id])->update(['membership_active' => false]);
    $this->actingAs($owner)->getJson(route('enterprise.integrations', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertForbidden();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('readiness never changes or resolves an uncertain email or trial cost record', function (): void {
    [$tenant, $owner, $area] = notificationReadinessFixture();
    notificationReadinessMailbox($tenant, $owner);
    foreach (['ent_email', 'ent_trial_charge'] as $kind) {
        TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area,
            'kind' => $kind, 'source_key' => hash('sha256', $kind), 'title' => 'Fictional unresolved evidence', 'visibility' => 'area',
            'status' => 'uncertain', 'version' => 1, 'data' => ['held' => 1000, 'private_fixture' => 'never-output-this']]);
    }
    $before = DB::table('team_operation_records')->get()->toJson();
    $result = app(IntelligenceStatus::class)->forUser($tenant, $owner, $area)['notifications'];
    expect($result['email']['dispatch_enabled'])->toBeTrue()->and($result['email']['live_delivery_verified'])->toBeFalse()
        ->and(json_encode($result))->not->toContain('never-output-this')
        ->and(DB::table('team_operation_records')->get()->toJson())->toBe($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});
