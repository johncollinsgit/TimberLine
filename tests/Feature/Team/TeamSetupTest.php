<?php

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TenantAccessProfile;
use App\Services\Team\Operations\EventTravelSettings;
use App\Services\Team\Operations\TeamsSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
});

function setupValues(array $overrides = []): array
{
    return array_replace(['home_base' => ['address' => '1 Fictional Home Road', 'timezone' => 'America/New_York'], 'area' => ['name' => 'Fictional Shows', 'profile' => 'events'], 'member_ids' => [], 'event_defaults' => ['teardown_minutes' => 60, 'arrival_buffer_minutes' => 0], 'cost_policy' => ['template' => 'skip'], 'connection' => 'later'], $overrides);
}
function setupDraftInput(array $values, int $version = 0): array
{
    return ['request_id' => (string) Str::uuid(), 'expected_version' => $version, 'step' => 0, 'values' => $values];
}

test('first-time setup is owner-only and drafts work before any area exists without inviting people or connecting providers', function () {
    [$tenant, $owner] = teamWorkspace();
    $service = app(TeamsSetup::class);
    $status = $service->status($tenant, $owner);
    expect($status['required'])->toBeTrue()->and($status['version'])->toBe(0)->and($status['defaults']['event_defaults'])->toBe(['teardown_minutes' => 60, 'arrival_buffer_minutes' => 0]);
    $input = setupDraftInput(['home_base' => ['address' => 'A', 'timezone' => 'Amer']]);
    $saved = $service->saveDraft($tenant, $owner, $input);
    expect($saved['version'])->toBe(1)->and($saved['draft']['values']['home_base']['address'])->toBe('A')->and(TeamArea::count())->toBe(0)
        ->and(json_encode(TenantAccessProfile::where('tenant_id', $tenant->id)->first()->metadata))->not->toContain('Amer');
    expect($service->saveDraft($tenant, $owner, $input)['replayed'])->toBeTrue();
    $changed = $input;
    $changed['values']['home_base']['address'] = 'B';
    expect(fn () => $service->saveDraft($tenant, $owner, $changed))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->finish($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true]))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(DB::table('team_provider_connections')->count())->toBe(0);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('finishing setup is idempotent and changes only operational metadata and future defaults', function () {
    [$tenant, $owner] = teamWorkspace();
    $service = app(TeamsSetup::class);
    $profile = TenantAccessProfile::where('tenant_id', $tenant->id)->first();
    $profile->update(['metadata' => ['unrelated' => ['billing' => 'preserve'], 'teams' => ['custom_flag' => true]]]);
    $member = teamMember($tenant, 'member');
    $service->saveDraft($tenant, $owner, setupDraftInput(setupValues(['member_ids' => [$member->id], 'connection' => 'asana'])));
    $input = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true];
    $first = $service->finish($tenant, $owner, $input);
    $again = $service->finish($tenant, $owner, $input);
    expect($first['required'])->toBeFalse()->and($first['version'])->toBe(2)->and($first['draft'])->toBeNull()->and($again['replayed'])->toBeTrue()->and($again['area']['id'])->toBe($first['area']['id'])
        ->and($again['connection_next'])->toBe('asana')->and(TeamArea::count())->toBe(1)->and(TeamOperationAttempt::where('kind', 'teams.setup')->count())->toBe(1)
        ->and(DB::table('team_area_members')->count())->toBe(0)->and(DB::table('team_provider_connections')->count())->toBe(0);
    $area = TeamArea::first();
    expect($area->settings['default_member_ids'])->toBe([(string) $member->id])->and($area->settings['source_authority'])->toBe('teams');
    $metadata = $profile->fresh()->metadata;
    expect($metadata['unrelated']['billing'])->toBe('preserve')->and($metadata['teams']['custom_flag'])->toBeTrue()->and($profile->fresh()->plan_key)->toBe($profile->plan_key);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('partial draft steps preserve other steps and stale saves cannot overwrite newer company setup', function () {
    [$tenant, $owner] = teamWorkspace();
    $service = app(TeamsSetup::class);
    $service->saveDraft($tenant, $owner, setupDraftInput(['home_base' => setupValues()['home_base']]));
    $next = setupDraftInput(['area' => setupValues()['area']], 1);
    $next['step'] = 1;
    $saved = $service->saveDraft($tenant, $owner, $next);
    expect($saved['draft']['values'])->toHaveKeys(['home_base', 'area'])->and($saved['version'])->toBe(2);
    expect(fn () => $service->saveDraft($tenant, $owner, setupDraftInput(['connection' => 'later'], 1)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($service->status($tenant, $owner)['draft']['step'])->toBe(1);
});

test('foreign area and people and unavailable templates cannot enter setup and members cannot manage it', function () {
    [$tenant, $owner] = teamWorkspace();
    [$other, $foreignMember] = teamWorkspace();
    $foreignArea = studioArea($other);
    $member = teamMember($tenant, 'member');
    $service = app(TeamsSetup::class);
    expect(fn () => $service->status($tenant, $member))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->saveDraft($tenant, $owner, setupDraftInput(['area' => ['id' => (string) $foreignArea->id]])))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => $service->saveDraft($tenant, $owner, setupDraftInput(['member_ids' => [$foreignMember->id]])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->saveDraft($tenant, $owner, setupDraftInput(['cost_policy' => ['template' => 'modern_forestry']])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(TeamArea::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('existing areas finish nonblocking setup and selected members are checked again before completion', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $service = app(TeamsSetup::class);
    $status = $service->status($tenant, $owner);
    expect($status['required'])->toBeFalse()->and($status['suggested'])->toBeTrue();
    $member = teamMember($tenant, 'member');
    $service->saveDraft($tenant, $owner, setupDraftInput(setupValues(['area' => ['id' => (string) $area->id, 'expected_version' => 1], 'member_ids' => [$member->id]])));
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $member->id])->update(['membership_active' => false]);
    expect(fn () => $service->finish($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($area->fresh()->version)->toBe(1)->and($service->status($tenant, $owner)['completed_at'])->toBeNull();
});

test('optional company rates create only an editable Studio draft with no invented missing rates', function () {
    [$tenant, $owner] = teamWorkspace();
    $service = app(TeamsSetup::class);
    $service->saveDraft($tenant, $owner, setupDraftInput(setupValues(['cost_policy' => ['template' => 'blank', 'name' => 'Fictional event costs', 'currency' => 'CAD', 'effective_on' => '2026-11-08', 'mileage_rate_minor' => 55]])));
    $done = $service->finish($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true]);
    $policy = TeamOperationRecord::find($done['cost_policy']['id']);
    expect($policy->kind)->toBe('cost_policy')->and($policy->status)->toBe('draft')->and($policy->data['definition']['currency'])->toBe('CAD')->and($policy->data['definition']['rules'])->toHaveCount(1)
        ->and($policy->data['definition']['rules'][0]['effect'])->toBe(['kind' => 'mileage', 'rate_minor' => 55])->and($policy->data['definition']['requires_confirmation'])->not->toBeEmpty()
        ->and(TeamArea::first()->settings)->not->toHaveKey('published_cost_policy')->and(TeamOperationRecord::where('kind', 'event_budget')->count())->toBe(0);
});

test('changing company travel defaults leaves existing event estimates frozen until an explicit new review', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $area->update(['settings' => ['travel' => ['home_base' => ['address' => '1 Fictional Home Road', 'timezone' => 'America/New_York']], 'event_defaults' => ['teardown_minutes' => 60, 'arrival_buffer_minutes' => 0]]]);
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => 'Fictional dated event', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'data' => ['address' => '100 Fictional Forest Road', '_teams_enrichment' => ['calendar' => ['timezone' => 'America/New_York', 'sessions' => [['id' => 'selling', 'kind' => 'selling', 'date' => '2026-11-08', 'opens' => '10:00', 'closes' => '17:00']], 'session_evidence' => [['session_id' => 'selling', 'status' => 'staff_attested']]]]]]);
    $travel = app(EventTravelSettings::class);
    $input = ['request_id' => (string) Str::uuid(), 'expected_event_version' => 1, 'expected_area_version' => 1, 'reviewed' => true, 'outbound_minutes' => 60, 'return_minutes' => 60];
    $first = $travel->save($tenant, $owner, $event, $input);
    expect($first['travel']['departure_at'])->toBe('2026-11-08T09:00:00-05:00')->and($first['travel']['return_at'])->toBe('2026-11-08T19:00:00-05:00');
    $setup = app(TeamsSetup::class);
    $setup->saveDraft($tenant, $owner, setupDraftInput(setupValues(['area' => ['id' => (string) $area->id, 'expected_version' => 1], 'event_defaults' => ['teardown_minutes' => 90, 'arrival_buffer_minutes' => 30]])));
    $setup->finish($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true]);
    $replay = $travel->save($tenant, $owner, $event->fresh(), $input);
    expect($replay['travel']['departure_at'])->toBe($first['travel']['departure_at'])->and($replay['travel']['return_at'])->toBe($first['travel']['return_at'])->and($event->fresh()->version)->toBe(2);
    $new = $input;
    $new['request_id'] = (string) Str::uuid();
    $new['expected_event_version'] = 2;
    $new['expected_area_version'] = 2;
    $review = $travel->save($tenant, $owner, $event->fresh(), $new);
    expect($review['travel']['arrival_buffer_minutes'])->toBe(30)->and($review['travel']['teardown_minutes'])->toBe(90)->and($review['travel']['departure_at'])->toBe('2026-11-08T08:30:00-05:00')->and($review['travel']['return_at'])->toBe('2026-11-08T19:30:00-05:00');
});

test('setup HTTP endpoints enforce current owner access and preserve exact retry identities', function () {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $this->actingAs($member)->getJson('/team/setup?tenant='.$tenant->slug)->assertForbidden();
    $status = $this->actingAs($owner)->getJson('/team/setup?tenant='.$tenant->slug)->assertOk()->assertJsonPath('required', true)->assertJsonPath('version', 0);
    expect($status->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $draft = setupDraftInput(setupValues());
    $this->postJson('/team/setup/draft?tenant='.$tenant->slug, $draft)->assertOk()->assertJsonPath('version', 1);
    $this->postJson('/team/setup/draft?tenant='.$tenant->slug, $draft)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('replayed', true);
    $finish = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true];
    $first = $this->postJson('/team/setup/finish?tenant='.$tenant->slug, $finish)->assertOk()->assertJsonPath('replayed', false);
    $this->postJson('/team/setup/finish?tenant='.$tenant->slug, $finish)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('area.id', $first->json('area.id'));
    $different = $finish;
    $different['expected_version'] = 2;
    $this->postJson('/team/setup/finish?tenant='.$tenant->slug, $different)->assertConflict();
    expect(TeamArea::where('tenant_id', $tenant->id)->count())->toBe(1);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('a company can complete setup and create its first native event without an Asana connection', function () {
    [$tenant, $owner] = teamWorkspace();
    $service = app(TeamsSetup::class);
    $service->saveDraft($tenant, $owner, setupDraftInput(setupValues()));
    $done = $service->finish($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true]);
    $area = TeamArea::where('tenant_id', $tenant->id)->findOrFail($done['area']['id']);
    $event = app(\App\Services\Team\Operations\OperationsWorkspace::class)->save($tenant, $owner, $area, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'event', 'title' => 'Fictional first native event', 'status' => 'planned', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-11', 'data' => ['venue' => 'Fictional hall', 'address' => '100 Fictional Forest Road', 'hotel' => 'Fictional Hotel', 'hotel_address' => '200 Fictional Hotel Road', 'coordinator' => 'Fictional Coordinator', 'coordinator_phone' => '+18005550123', 'coordinator_email' => 'coordinator@fixture.invalid']]);
    expect($event->connection_id)->toBeNull()->and($event->kind)->toBe('event')->and($area->settings['source_authority'])->toBe('teams')->and(DB::table('team_provider_connections')->where('tenant_id', $tenant->id)->count())->toBe(0);
    $this->actingAs($owner)->getJson('/team/events/'.$event->id.'/essentials?tenant='.$tenant->slug)->assertOk()->assertJsonPath('title', 'Fictional first native event')->assertJsonPath('location.venue', 'Fictional hall')->assertJsonPath('hotel.address', '200 Fictional Hotel Road')->assertJsonPath('coordinator.email_url', 'mailto:coordinator@fixture.invalid');
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('setup preserves connected Modern Forestry Asana authority and does not enable an automatic cutover', function () {
    [$tenant, $owner] = teamWorkspace();
    $tenant->forceFill(['slug' => 'modern-forestry'])->save();
    $area = studioArea($tenant);
    $area->update(['settings' => ['source_authority' => 'asana', 'custom_setting' => 'preserve', 'timezone' => 'America/Los_Angeles']]);
    $service = app(TeamsSetup::class);
    $service->saveDraft($tenant, $owner, setupDraftInput(setupValues(['area' => ['id' => (string) $area->id, 'expected_version' => 1], 'connection' => 'later'])));
    $service->finish($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true]);
    expect($area->fresh()->settings['source_authority'])->toBe('asana')->and($area->fresh()->settings['custom_setting'])->toBe('preserve')->and($area->fresh()->settings['timezone'])->toBe('America/Los_Angeles');
    $authority = app(\App\Services\Team\Studio\TeamsStudio::class)->authority($tenant, $area->fresh());
    expect($authority['provider'])->toBe('asana')->and($authority['cutover_enabled'])->toBeFalse();
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('a new native area uses its selected home timezone for upcoming dates across UTC midnight', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-08T01:00:00Z'));
    [$tenant, $owner] = teamWorkspace();
    $setup = app(TeamsSetup::class);
    $setup->saveDraft($tenant, $owner, setupDraftInput(setupValues()));
    $done = $setup->finish($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true]);
    $area = TeamArea::where('tenant_id', $tenant->id)->findOrFail($done['area']['id']);
    $context = app(\App\Services\Team\Search\EventSearchOrder::class)->context($tenant);
    expect($area->settings['timezone'])->toBe('America/New_York')->and($context['areas'][$area->id]['timezone'])->toBe('America/New_York')->and($context['areas'][$area->id]['today'])->toBe('2026-11-07');
    $this->actingAs($owner)->getJson('/team/areas?tenant='.$tenant->slug)->assertOk()->assertJsonPath('items.0.timezone', 'America/New_York');
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => 'Fictional local evening event', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'planned', 'starts_on' => '2026-11-07', 'ends_on' => '2026-11-07', 'data' => []]);
    $this->getJson('/team/operations/records?tenant='.$tenant->slug.'&area='.$area->id.'&kind=event&event_scope=window&calendar_from=2026-11-07&calendar_to=2026-12-06')->assertOk()->assertJsonPath('items.0.id', $event->id);
});
