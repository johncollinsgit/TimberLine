<?php

use App\Enterprise\ContractorCertificates;
use App\Enterprise\Planning;
use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true,
        'enterprise.writes_enabled' => true, 'enterprise.certificates_enabled' => true]);
    Route::middleware('web')->prefix('certificate-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-22T16:00:00Z'));
});

function certificateFixture(string $profile = 'enterprise_department'): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => array_merge(config('enterprise.tenant_allowlist', []), [(string) $tenant->id])]);
    $area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'cert-'.Str::lower(Str::random(8)),
        'name' => 'Synthetic managerial certificates', 'profile' => $profile, 'version' => 1]);
    $contractor = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'kind' => Planning::KINDS['contractor'], 'title' => 'Synthetic contractor contact', 'status' => 'active', 'version' => 1,
        'source_key' => hash('sha256', (string) Str::uuid()), 'visibility' => 'area', 'data' => []]);

    return [$tenant, $owner, $area, $contractor];
}

function certificateMember($tenant, $area, string $areaRole, string $tenantRole = 'member')
{
    $user = teamMember($tenant, $tenantRole);
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'user_id' => $user->id, 'role' => $areaRole]);

    return $user;
}

function certificatePost($tenant, $user, $area, string $action, array $input = [])
{
    return test()->actingAs($user)->postJson(route('enterprise.certificates.command', ['tenant' => $tenant->slug]), $input + [
        'action' => $action, 'id' => (string) Str::uuid(), 'area_id' => $area->id, 'request_id' => (string) Str::uuid(), 'expected_version' => 0,
    ]);
}

function certificatePropose($tenant, $owner, $area, $contractor, array $input = []): array
{
    return certificatePost($tenant, $owner, $area, 'certificate.propose', $input + ['title' => 'Synthetic private certificate',
        'contractor_id' => $contractor->id, 'replacement_id' => (string) Str::uuid(), 'document_reference' => 'Synthetic private cabinet reference A',
        'expires_on' => '2026-10-25', 'renewal_contact' => 'Synthetic renewal contact, example.invalid', 'warning_lead_days' => 7,
        'review_due_on' => '2026-11-01'])->assertOk()->json();
}

function certificateReview($tenant, $user, $area, array $certificate, string $decision = 'accept', array $extra = [])
{
    return certificatePost($tenant, $user, $area, 'certificate.review', $extra + ['id' => $certificate['id'],
        'expected_version' => $certificate['version'], 'replacement_id' => $certificate['pending']['replacement_id'],
        'decision' => $decision, 'reason' => 'Synthetic explicit metadata review', 'confirmed' => true]);
}

test('certificates persist encrypted managerial metadata with explicit reviewer identity immutable source and no bytes or grants', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $coordinator = certificateMember($tenant, $area, 'coordinator');
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'document_reference' => 'Secret manual reference <img src=x>',
        'renewal_contact' => 'Secret renewal contact', 'title' => 'Synthetic certificate'];
    $candidate = certificatePropose($tenant, $owner, $area, $contact, $input);
    expect($candidate['accepted'])->toBeNull()->and($candidate['pending']['expires_on'])->toBe('2026-10-25');
    expect(certificatePropose($tenant, $owner, $area, $contact, $input + ['replacement_id' => $candidate['pending']['replacement_id']])['replayed'])->toBeTrue();
    $raw = DB::table('team_operation_records')->where('id', $candidate['id'])->value('data');
    expect($raw)->not->toContain('Secret renewal contact')->not->toContain('Secret manual reference');
    $reviewInput = ['request_id' => (string) Str::uuid()];
    $reviewed = certificateReview($tenant, $coordinator, $area, $candidate, 'accept', $reviewInput)->assertOk()->json();
    certificateReview($tenant, $coordinator, $area, $candidate, 'accept', $reviewInput)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('version', 2);
    certificateReview($tenant, $owner, $area, $candidate, 'accept', $reviewInput)->assertConflict();
    expect($reviewed['accepted']['reviewed_by'])->toBe((string) $coordinator->id)->and($reviewed['accepted']['expires_on'])->toBe('2026-10-25')
        ->and($reviewed['pending'])->toBeNull()->and($reviewed['version'])->toBe(2)->and($reviewed['expiry_state'])->toBe('warning');
    expect(DB::table('team_operation_revisions')->where('record_id', $candidate['id'])->count())->toBe(2);
    $response = $this->actingAs($coordinator)->getJson(route('enterprise.certificates.history', ['tenant' => $tenant->slug, 'id' => $candidate['id']]))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $history = $response->json();
    expect($history['items'][0]['actor_id'])->toBe($coordinator->id)->and($history['items'][0]['event']['action'])->toBe('certificate.review');
    expect(DB::table('tenant_user')->count())->toBe(2)->and(DB::table('team_area_members')->count())->toBe(1)
        ->and(DB::table('team_file_uploads')->count())->toBe(0);
    Http::assertNothingSent();
});

test('unreviewed future replacements cannot remove accepted expired reasons and rejected proposals preserve accepted metadata', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $old = certificatePropose($tenant, $owner, $area, $contact, ['expires_on' => '2026-10-01']);
    $accepted = certificateReview($tenant, $owner, $area, $old)->assertOk()->json();
    $proposal = certificatePost($tenant, $owner, $area, 'certificate.propose', ['id' => $accepted['id'], 'expected_version' => $accepted['version'],
        'replacement_id' => (string) Str::uuid(), 'document_reference' => 'Synthetic future replacement reference', 'expires_on' => '2027-10-01'])->assertOk()->json();
    expect($proposal['accepted']['expires_on'])->toBe('2026-10-01')->and($proposal['pending']['expires_on'])->toBe('2027-10-01')
        ->and($proposal['reminder_reasons'])->toContain('expired', 'awaiting_replacement_review');
    $rejected = certificateReview($tenant, $owner, $area, $proposal, 'reject')->assertOk()->json();
    expect($rejected['accepted']['expires_on'])->toBe('2026-10-01')->and($rejected['pending'])->toBeNull()->and($rejected['expiry_state'])->toBe('expired');
    $replacement = certificatePost($tenant, $owner, $area, 'certificate.propose', ['id' => $accepted['id'], 'expected_version' => $rejected['version'],
        'replacement_id' => (string) Str::uuid(), 'document_reference' => 'Reviewed replacement ref', 'expires_on' => '2027-10-01'])->assertOk()->json();
    $new = certificateReview($tenant, $owner, $area, $replacement)->assertOk()->json();
    expect($new['accepted']['expires_on'])->toBe('2027-10-01')->and($new['expiry_state'])->toBe('outside_warning')->and($new['reminder_reasons'])->toBe([]);
});

test('derived reminders use explicit nullable lead time review due and Eastern date boundaries without GET writes', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $first = certificatePropose($tenant, $owner, $area, $contact, ['warning_lead_days' => null, 'review_due_on' => null, 'renewal_contact' => null]);
    $accepted = certificateReview($tenant, $owner, $area, $first)->assertOk()->json();
    expect($accepted['expiry_state'])->toBe('not_configured')->and($accepted['reminder_reasons'])->toContain('warning_not_configured', 'review_not_scheduled', 'renewal_contact_missing');
    $configured = certificatePost($tenant, $owner, $area, 'certificate.metadata', ['id' => $accepted['id'], 'expected_version' => $accepted['version'],
        'title' => $accepted['title'], 'renewal_contact' => 'Synthetic reviewer reference', 'warning_lead_days' => 0, 'review_due_on' => '2026-10-23'])->assertOk()->json();
    expect($configured['expiry_state'])->toBe('outside_warning')->and($configured['reminder_reasons'])->toBe([]);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26T03:59:00Z'));
    $before = [TeamOperationRecord::count(), TeamOperationAttempt::count(), DB::table('team_operation_revisions')->count()];
    $url = route('enterprise.certificates.reminders', ['tenant' => $tenant->slug, 'area_id' => $area->id]);
    $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('as_of_on', '2026-10-25')->assertJsonPath('timezone', 'America/New_York')
        ->assertJsonPath('items.0.expiry_state', 'expires_today')->assertJsonPath('counts.attention', 1);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26T04:00:00Z'));
    $this->getJson($url)->assertOk()->assertJsonPath('as_of_on', '2026-10-26')->assertJsonPath('items.0.expiry_state', 'expired');
    expect([TeamOperationRecord::count(), TeamOperationAttempt::count(), DB::table('team_operation_revisions')->count()])->toBe($before);
    Http::assertNothingSent();
});

test('shared area certificate metadata counts history mutation and exact replay remain manager only including tenant viewers', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture('enterprise_shared');
    $certificate = certificatePropose($tenant, $owner, $area, $contact);
    foreach ([['editor', 'member'], ['viewer', 'member'], ['coordinator', 'viewer'], ['coordinator', 'guest'], ['coordinator', 'unexpected']] as [$areaRole, $tenantRole]) {
        $user = certificateMember($tenant, $area, $areaRole, $tenantRole);
        foreach (['enterprise.certificates', 'enterprise.certificates.reminders', 'enterprise.certificates.show', 'enterprise.certificates.history'] as $route) {
            $response = $this->actingAs($user)->getJson(route($route, ['tenant' => $tenant->slug, 'area_id' => $area->id, 'id' => $certificate['id']]));
            expect($response->status())->toBeIn([403, 404]);
            expect($response->getContent())->not->toContain('Synthetic private certificate')->not->toContain('renewal_contact');
        }
        $response = certificateReview($tenant, $user, $area, $certificate);
        expect($response->status())->toBeIn([403, 404]);
    }
    expect(TeamOperationRecord::find($certificate['id'])->version)->toBe(1);
});

test('current manager demotion inactive verification and membership revocation prevent receipt replay and history', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $manager = certificateMember($tenant, $area, 'coordinator');
    $receipt = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'replacement_id' => (string) Str::uuid()];
    $record = certificatePropose($tenant, $manager, $area, $contact, $receipt);
    DB::table('team_area_members')->where('user_id', $manager->id)->update(['role' => 'editor']);
    certificatePost($tenant, $manager, $area, 'certificate.propose', $receipt + ['title' => 'Synthetic private certificate', 'contractor_id' => $contact->id,
        'document_reference' => 'Synthetic private cabinet reference A', 'expires_on' => '2026-10-25', 'renewal_contact' => 'Synthetic renewal contact, example.invalid',
        'warning_lead_days' => 7, 'review_due_on' => '2026-11-01'])->assertNotFound();
    $this->actingAs($manager)->getJson(route('enterprise.certificates.history', ['tenant' => $tenant->slug, 'id' => $record['id']]))->assertNotFound();
    DB::table('team_area_members')->where('user_id', $manager->id)->update(['role' => 'coordinator']);
    $manager->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($manager)->getJson(route('enterprise.certificates.show', ['tenant' => $tenant->slug, 'id' => $record['id']]))->assertForbidden();
    $manager->forceFill(['email_verified_at' => now()])->save();
    $tenant->users()->updateExistingPivot($manager->id, ['membership_active' => false]);
    $this->actingAs($manager)->getJson(route('enterprise.certificates.show', ['tenant' => $tenant->slug, 'id' => $record['id']]))->assertForbidden();
    expect(TeamOperationRecord::find($record['id'])->version)->toBe(1);
});

test('foreign certificate and contact identities are denied before writing or exposing reminder counts', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $certificate = certificatePropose($tenant, $owner, $area, $contact);
    [$foreignTenant, $foreignOwner, $foreignArea, $foreignContact] = certificateFixture();
    $proposal = ['contractor_id' => $foreignContact->id, 'title' => 'Foreign reference', 'replacement_id' => (string) Str::uuid(),
        'document_reference' => 'No access', 'expires_on' => '2026-12-01'];
    certificatePost($tenant, $owner, $area, 'certificate.propose', $proposal)->assertNotFound();
    certificateReview($foreignTenant, $foreignOwner, $foreignArea, $certificate)->assertNotFound();
    $this->actingAs($foreignOwner)->getJson(route('enterprise.certificates.show', ['tenant' => $foreignTenant->slug, 'id' => $certificate['id']]))->assertNotFound();
    $this->actingAs($owner)->getJson(route('enterprise.certificates', ['tenant' => $tenant->slug, 'area_id' => $area->id, 'contractor_id' => $foreignContact->id]))->assertNotFound();
    $otherArea = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'other-private', 'name' => 'Other private', 'profile' => 'enterprise_department', 'version' => 1]);
    certificatePost($tenant, $owner, $otherArea, 'certificate.propose', ['contractor_id' => $contact->id, 'title' => 'Wrong area',
        'replacement_id' => (string) Str::uuid(), 'document_reference' => 'No access', 'expires_on' => '2026-12-01'])->assertNotFound();
    certificatePost($tenant, $owner, $otherArea, 'certificate.metadata', ['id' => $certificate['id'], 'expected_version' => 1,
        'title' => 'Scope widening', 'renewal_contact' => null, 'warning_lead_days' => 1, 'review_due_on' => null])->assertNotFound();
    expect(TeamOperationRecord::where('kind', ContractorCertificates::KIND)->count())->toBe(1);
});

test('stale reviews altered receipts duplicate pending candidates and incomplete accepted metadata fail atomically', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'replacement_id' => (string) Str::uuid()];
    $record = certificatePropose($tenant, $owner, $area, $contact, $input);
    certificatePost($tenant, $owner, $area, 'certificate.propose', $input + ['title' => 'Changed retry', 'contractor_id' => $contact->id,
        'document_reference' => 'Synthetic private cabinet reference A', 'expires_on' => '2026-10-25'])->assertConflict();
    certificateReview($tenant, $owner, $area, $record, 'accept', ['expected_version' => 0])->assertConflict();
    certificateReview($tenant, $owner, $area, $record, 'accept', ['replacement_id' => (string) Str::uuid()])->assertConflict();
    certificateReview($tenant, $owner, $area, $record, 'accept', ['confirmed' => false])->assertUnprocessable();
    certificatePost($tenant, $owner, $area, 'certificate.propose', ['id' => $record['id'], 'expected_version' => 1,
        'replacement_id' => (string) Str::uuid(), 'document_reference' => 'Replacement', 'expires_on' => '2027-01-01'])->assertConflict();
    $unknown = certificatePropose($tenant, $owner, $area, $contact, ['expires_on' => null, 'document_reference' => null]);
    certificateReview($tenant, $owner, $area, $unknown)->assertUnprocessable();
    expect(TeamOperationRecord::find($unknown['id'])->version)->toBe(1)->and(TeamOperationRecord::find($record['id'])->version)->toBe(1);
    certificatePost($tenant, $owner, $area, 'certificate.metadata', ['id' => $record['id'], 'expected_version' => 1, 'title' => $record['title'],
        'renewal_contact' => 'Retained', 'warning_lead_days' => -1, 'review_due_on' => null])->assertUnprocessable();
});

test('archival retains audited history and exact replay but suppresses future reminders without stop work effects', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $record = certificatePropose($tenant, $owner, $area, $contact);
    $input = ['id' => $record['id'], 'expected_version' => 1, 'request_id' => (string) Str::uuid(), 'reason' => 'Synthetic record withdrawn after explicit review', 'confirmed' => true];
    certificatePost($tenant, $owner, $area, 'certificate.archive', $input)->assertOk()->assertJsonPath('status', 'archived');
    certificatePost($tenant, $owner, $area, 'certificate.archive', $input)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('version', 2);
    $this->actingAs($owner)->getJson(route('enterprise.certificates.reminders', ['tenant' => $tenant->slug, 'area_id' => $area->id]))->assertOk()->assertJsonCount(0, 'items');
    certificateReview($tenant, $owner, $area, $record, 'accept', ['expected_version' => 2])->assertConflict();
    expect($contact->fresh()->status)->toBe('active')->and($contact->fresh()->version)->toBe(1);
    expect(DB::table('team_operation_revisions')->where('record_id', $record['id'])->count())->toBe(2);
});

test('readable manager records contacts and history survive write freeze while writes replay and rollout fail closed', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $manager = certificateMember($tenant, $area, 'coordinator');
    $record = certificatePropose($tenant, $manager, $area, $contact);
    config(['enterprise.writes_enabled' => false, 'enterprise.planning_enabled' => false]);
    $this->actingAs($manager)->getJson(route('enterprise.certificates', ['tenant' => $tenant->slug, 'area_id' => $area->id]))->assertOk()
        ->assertJsonPath('can_manage', false)->assertJsonPath('items.0.can_manage', false)->assertJsonPath('contractors.0.id', $contact->id);
    $this->getJson(route('enterprise.certificates.history', ['tenant' => $tenant->slug, 'id' => $record['id']]))->assertOk();
    certificateReview($tenant, $manager, $area, $record)->assertForbidden();
    config(['enterprise.certificates_enabled' => false]);
    $this->actingAs($manager)->getJson(route('enterprise.certificates.show', ['tenant' => $tenant->slug, 'id' => $record['id']]))->assertNotFound();
    expect(TeamOperationRecord::find($record['id'])->version)->toBe(1);
});

test('HTTP commands reject asserted reviewers actors attachment and sending fields instead of silently dropping them', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $record = certificatePropose($tenant, $owner, $area, $contact);
    foreach (['reviewer_id' => (string) $owner->id, 'reviewed_by' => '1234', 'actor_id' => (string) $owner->id,
        'file_id' => (string) Str::uuid(), 'send_email' => true, 'stop_work' => true] as $field => $value) {
        certificateReview($tenant, $owner, $area, $record, 'accept', [$field => $value])->assertUnprocessable();
    }
    $this->actingAs($owner)->post(route('enterprise.certificates.command', ['tenant' => $tenant->slug]), [
        'action' => 'certificate.archive', 'id' => $record['id'], 'area_id' => $area->id, 'expected_version' => 1,
        'request_id' => (string) Str::uuid(), 'confirmed' => true, 'reason' => 'Non-JSON input'])->assertStatus(415);
    expect(TeamOperationRecord::find($record['id'])->version)->toBe(1)->and(TeamOperationAttempt::where('kind', ContractorCertificates::KIND)->count())->toBe(1);
    Http::assertNothingSent();
});

test('configured client calendar timezone changes only date classification and invalid configuration fails closed', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $record = certificatePropose($tenant, $owner, $area, $contact, ['expires_on' => '2026-10-21']);
    certificateReview($tenant, $owner, $area, $record)->assertOk();
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-22T00:30:00Z'));
    $url = route('enterprise.certificates.show', ['tenant' => $tenant->slug, 'id' => $record['id']]);
    config(['enterprise.certificates_timezone' => 'America/New_York']);
    $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('as_of_on', '2026-10-21')->assertJsonPath('expiry_state', 'expires_today');
    config(['enterprise.certificates_timezone' => 'Europe/London']);
    $this->getJson($url)->assertOk()->assertJsonPath('as_of_on', '2026-10-22')->assertJsonPath('timezone', 'Europe/London')->assertJsonPath('expiry_state', 'expired');
    config(['enterprise.certificates_timezone' => 'Invented/ClientCampus']);
    $this->getJson($url)->assertStatus(503);
    expect(TeamOperationRecord::find($record['id'])->version)->toBe(2);
});

test('attention matching precedes the return cap and inactive contacts retain private history without new proposals', function (): void {
    [$tenant, $owner, $area, $contact] = certificateFixture();
    $record = certificatePropose($tenant, $owner, $area, $contact, ['title' => 'Z pending review']);
    $data = TeamOperationRecord::find($record['id'])->data;
    $data['pending'] = null;
    $data['accepted'] = ['replacement_id' => (string) Str::uuid(), 'document_reference' => 'Synthetic reviewed reference', 'expires_on' => '2028-10-01',
        'proposed_by' => (string) $owner->id, 'proposed_at' => now()->toIso8601String(), 'reviewed_by' => (string) $owner->id,
        'reviewed_at' => now()->toIso8601String(), 'review_reason' => 'Synthetic reviewed metadata'];
    for ($i = 0; $i < 205; $i++) {
        TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
            'kind' => ContractorCertificates::KIND, 'title' => 'A future certificate '.$i, 'status' => 'active', 'version' => 1,
            'source_key' => hash('sha256', 'synthetic-future-certificate-'.$i), 'visibility' => 'manager', 'data' => $data]);
    }
    $response = $this->actingAs($owner)->getJson(route('enterprise.certificates.reminders', ['tenant' => $tenant->slug, 'area_id' => $area->id]))
        ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $record['id'])->assertJsonPath('counts.returned', 1)->assertJsonPath('partial', true);
    expect($response->json('coverage'))->toContain('returned records only');
    $contact->status = 'inactive';
    $contact->save();
    $this->getJson(route('enterprise.certificates.show', ['tenant' => $tenant->slug, 'id' => $record['id']]))->assertOk()->assertJsonPath('contractor_status', 'inactive');
    certificatePost($tenant, $owner, $area, 'certificate.propose', ['contractor_id' => $contact->id, 'title' => 'Inactive new proposal',
        'replacement_id' => (string) Str::uuid(), 'document_reference' => 'Ref', 'expires_on' => '2026-12-01'])->assertUnprocessable();
});
