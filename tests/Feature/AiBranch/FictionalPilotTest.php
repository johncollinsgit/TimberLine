<?php

use App\Models\AiBranchAccount;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Services\AiBranch\AiBranchProvider;
use App\Services\AiBranch\AiFictionalPilot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
});

function prepareFictionalVoicePilot($test): array
{
    $owner = User::factory()->create(['email' => 'fictional-owner@example.test', 'is_active' => true, 'email_verified_at' => now()]);
    $test->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $owner->email, '--authorization' => 'fictional-test-approval', '--apply' => true])->assertSuccessful();

    return [Tenant::where('slug', AiFictionalPilot::SLUG)->firstOrFail(), $owner];
}

test('fictional provisioning previews without writes and grants only an existing verified account', function () {
    $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $owner->email])->assertSuccessful();
    expect(Tenant::count())->toBe(0);
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => 'missing@example.test', '--apply' => true])->assertFailed();
    expect(Tenant::count())->toBe(0);
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $owner->email, '--apply' => true])->assertFailed();
    expect(Tenant::count())->toBe(0);
    Http::assertNothingSent();
});

test('fictional pilot uses real scoped approvals and scripted zero-cost chat without a subscription or provider', function () {
    [$tenant, $owner] = prepareFictionalVoicePilot($this);
    $base = '/ai-branch';
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $owner->email, '--verify' => true])->assertSuccessful();
    $query = '?tenant='.$tenant->slug;
    $this->actingAs($owner)->getJson($base.'/assistant'.$query)
        ->assertOk()->assertJsonPath('fictional_pilot', true)->assertJsonPath('commitments_available', true)->assertJsonPath('actions_available', true)
        ->assertJsonPath('reports_available', true)->assertJsonPath('attention_available', true);
    $conversation = $this->postJson($base.'/conversations'.$query)->assertSuccessful()->json('conversation_id');
    $uuid = (string) Str::uuid();
    $answer = $this->postJson($base.'/conversations/'.$conversation.'/messages'.$query, ['question' => 'What should I do next?', 'request_id' => $uuid])->assertSuccessful();
    expect(json_encode($answer->json()))->toContain('Scripted fictional practice');
    expect(TenantAccessAddon::count())->toBe(0)->and(TenantBillingSubscription::count())->toBe(0);
    $event = TenantAiUsageEvent::firstOrFail();
    expect($event->provider_cost_micros)->toBe(0)->and($event->buyer_charge_micros)->toBe(0)->and($event->metadata['billing_scope'])->toBe('fictional_no_charge');
    $job = FieldServiceJob::where('tenant_id', $tenant->id)->firstOrFail();
    $preview = $this->postJson($base.'/actions'.$query, ['job_id' => $job->id, 'body' => 'Fictional reviewed pilot note', 'request_id' => (string) Str::uuid()])->assertSuccessful();
    $action = \App\Models\AiBranchAction::findOrFail($preview->json('action_id'));
    $this->postJson($base.'/actions/'.$action->id.'/decision'.$query, ['decision' => 'approve', 'digest' => $action->digest, 'confirmed' => true])->assertSuccessful();
    $this->postJson($base.'/actions/'.$action->id.'/decision'.$query, ['decision' => 'approve', 'digest' => $action->digest, 'confirmed' => true])->assertSuccessful();
    expect(FieldServiceJobNote::where('body', 'Fictional reviewed pilot note')->count())->toBe(1);
    expect(config('ai_branch.fictional_request'))->toBeNull()->and(config('ai_branch.assistant_enabled'))->toBeFalse();
    Http::assertNothingSent();
});

test('fictional grants cannot be copied by metadata or accessed by other members and tenants', function () {
    [$tenant, $owner] = prepareFictionalVoicePilot($this);
    $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($member)->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertNotFound();
    $other = Tenant::create(['name' => 'Real workspace', 'slug' => 'other-real-workspace']);
    $owner->tenants()->attach($other->id, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($owner)->getJson('/ai-branch/assistant?tenant='.$other->slug)->assertNotFound();
    Http::assertNothingSent();
});

test('revoked expired and disabled fictional grants never fall through to the configured live provider', function () {
    [$tenant, $owner] = prepareFictionalVoicePilot($this);
    config(['ai_branch.api_key' => 'test-never-dispatch', 'ai_branch.model' => 'configured-but-forbidden']);
    $this->actingAs($owner);
    request()->setUserResolver(fn () => $owner);
    app(AiFictionalPilot::class)->during($tenant, $owner, function () use ($tenant) {
        $settings = app(AiBranchProvider::class)->settings();
        LandlordOperatorAction::create(['tenant_id' => $tenant->id, 'action_type' => 'ai_voice.pilot.revoke', 'status' => 'success']);
        expect(fn () => app(AiBranchProvider::class)->complete([], $settings))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    });
    $this->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertNotFound();
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $owner->email, '--authorization' => 'renew-test', '--apply' => true])->assertSuccessful();
    config(['ai_branch.fictional_pilot_disabled' => true]);
    $this->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertNotFound();
    config(['ai_branch.fictional_pilot_disabled' => false]);
    $this->travel(8)->days();
    $this->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertNotFound();
    Http::assertNothingSent();
});

test('renewal retains pilot work and refuses an owner or reserved-workspace takeover', function () {
    [$tenant, $owner] = prepareFictionalVoicePilot($this);
    $this->travel(8)->days();
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $owner->email, '--authorization' => 'repeat-test', '--apply' => true])->assertSuccessful();
    expect(Tenant::count())->toBe(1)->and(FieldServiceJob::count())->toBe(1)->and(AiBranchAccount::count())->toBe(1);
    $this->actingAs($owner)->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertOk();
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $other->email, '--authorization' => 'not-a-takeover', '--apply' => true])->assertFailed();
    Http::assertNothingSent();
});

test('request-local pilot flags restore after failure and membership revocation blocks a captured provider scope', function () {
    [$tenant, $owner] = prepareFictionalVoicePilot($this);
    $this->actingAs($owner);
    request()->setUserResolver(fn () => $owner);
    expect(fn () => app(AiFictionalPilot::class)->during($tenant, $owner, function () use ($tenant, $owner) {
        $settings = app(AiBranchProvider::class)->settings();
        $owner->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
        app(AiBranchProvider::class)->complete([], $settings);
    }))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(config('ai_branch.fictional_request'))->toBeNull()->and(config('ai_branch.assistant_enabled'))->toBeFalse();
    Http::assertNothingSent();
});

test('production provisioning requires its explicit flag and refuses a preexisting unaudited reserved workspace', function () {
    $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    app()->instance('env', 'production');
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $owner->email, '--apply' => true, '--authorization' => 'test-production'])
        ->assertFailed();
    expect(Tenant::count())->toBe(0);
    Tenant::create(['name' => 'Existing unrelated workspace', 'slug' => AiFictionalPilot::SLUG]);
    $this->artisan('everbranch:prepare-voice-pilot', ['--owner-email' => $owner->email, '--apply' => true, '--authorization' => 'test-production', '--force-production' => true])
        ->assertFailed();
    expect(LandlordOperatorAction::count())->toBe(0);
    Http::assertNothingSent();
});
