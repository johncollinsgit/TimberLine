<?php

use App\Enterprise\FinancePackets;
use App\Enterprise\Planning;
use App\Enterprise\RequestWorkflow;
use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true,
        'enterprise.writes_enabled' => true, 'enterprise.finance_enabled' => true]);
    Route::middleware('web')->prefix('finance-packet-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
});

function financeFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => array_merge(config('enterprise.tenant_allowlist', []), [(string) $tenant->id])]);
    $command = fn (array $input) => app(RequestWorkflow::class)->execute($tenant, $owner, $input + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()]);
    $area = $command(['action' => 'workspace.create', 'title' => 'Synthetic Finance routing', 'visibility' => 'department_only'])['id'];
    $project = $command(['action' => 'project.create', 'title' => 'Synthetic equipment repair', 'area_id' => $area])['id'];
    $admin = teamMember($tenant, 'admin');
    $contractor = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area,
        'kind' => Planning::KINDS['contractor'], 'title' => 'Synthetic supplier', 'status' => 'active', 'version' => 1,
        'source_key' => hash('sha256', (string) Str::uuid()), 'visibility' => 'area', 'data' => []]);

    return [$tenant, $owner, $area, $project, $admin, $contractor];
}

function financePost($tenant, $user, string $area, string $action, array $input = [])
{
    return test()->actingAs($user)->postJson(route('enterprise.finance.command', ['tenant' => $tenant->slug]), $input + [
        'action' => $action, 'id' => (string) Str::uuid(), 'area_id' => $area, 'request_id' => (string) Str::uuid(), 'expected_version' => 0,
    ]);
}

function financeContents(string $project, $nextOwner, array $extra = []): array
{
    return $extra + ['title' => 'Synthetic invoice packet', 'project_id' => $project, 'contractor_id' => null, 'amount_minor' => 12500, 'currency' => 'USD',
        'document_reference' => 'Synthetic original invoice in approved cabinet', 'fund_code' => 'SAMPLE-CAPITAL',
        'restriction_reference' => 'Synthetic source restrictions; Finance validation pending', 'source_reference' => 'Synthetic authoritative budget-code reference',
        'next_owner_id' => $nextOwner->id];
}

function financeTransition($tenant, $user, string $area, array $packet, string $action, array $extra = [])
{
    return financePost($tenant, $user, $area, $action, $extra + ['id' => $packet['id'], 'expected_version' => $packet['version'],
        'confirmed' => true, 'reason' => 'Synthetic explicit routing decision']);
}

test('finance packet routing persists encrypted references exact retries and current reviewed owner without payments', function (): void {
    [$tenant, $owner, $area, $project, $admin, $contractor] = financeFixture();
    $input = financeContents($project, $admin, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
        'contractor_id' => $contractor->id, 'fund_code' => 'Secret donor reference', 'document_reference' => 'Secret invoice <img src=x>']);
    $packet = financePost($tenant, $owner, $area, 'packet.save', $input)->assertOk()->assertJsonPath('status', 'draft')->json();
    financePost($tenant, $owner, $area, 'packet.save', $input)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('version', 1);
    expect(DB::table('team_operation_records')->where('id', $packet['id'])->value('data'))->not->toContain('Secret donor reference', 'Secret invoice');
    $submitted = financeTransition($tenant, $owner, $area, $packet, 'packet.submit')->assertOk()->assertJsonPath('status', 'submitted')->json();
    financeTransition($tenant, $owner, $area, $submitted, 'packet.review', ['decision' => 'approved'])->assertConflict();
    $receipt = ['request_id' => (string) Str::uuid(), 'decision' => 'approved'];
    $approved = financeTransition($tenant, $admin, $area, $submitted, 'packet.review', $receipt)->assertOk()->json();
    financeTransition($tenant, $admin, $area, $submitted, 'packet.review', $receipt)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('version', 3);
    expect($approved['review']['by'])->toBe((string) $admin->id)->and($approved['review']['version_reviewed'])->toBe(2);
    expect($approved['external_completion'])->toBeNull()->and($approved['authority'])->toContain('No payment is executed or inferred');
    $completed = financeTransition($tenant, $admin, $area, $approved, 'packet.external_complete', ['external_reference' => 'Synthetic external signoff #14'])->assertOk()->json();
    expect($completed['status'])->toBe('externally_completed')->and($completed['external_completion']['verification'])->toBe('manually_recorded_external_process');
    $this->actingAs($owner)->getJson(route('enterprise.finance.history', ['tenant' => $tenant->slug, 'id' => $packet['id']]))->assertOk()
        ->assertJsonCount(4, 'items')->assertJsonPath('items.0.event.action', 'packet.external_complete');
    expect(DB::table('tenant_user')->count())->toBe(2)->and(DB::table('team_file_uploads')->count())->toBe(0);
    Http::assertNothingSent();
});

test('packet content cannot silently change an approved version and reopening invalidates old review', function (): void {
    [$tenant, $owner, $area, $project] = financeFixture();
    $packet = financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner))->assertOk()->json();
    $submitted = financeTransition($tenant, $owner, $area, $packet, 'packet.submit')->assertOk()->json();
    $approved = financeTransition($tenant, $owner, $area, $submitted, 'packet.review', ['decision' => 'approved'])->assertOk()->json();
    financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner, ['id' => $packet['id'], 'expected_version' => 3]))->assertConflict();
    $reopened = financeTransition($tenant, $owner, $area, $approved, 'packet.reopen')->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('review', null)->json();
    financeTransition($tenant, $owner, $area, $reopened, 'packet.external_complete', ['external_reference' => 'Cannot imply old approval'])->assertConflict();
    $edited = financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner, ['id' => $packet['id'], 'expected_version' => 4, 'amount_minor' => 15000]))->assertOk()->json();
    expect($edited['review'])->toBeNull()->and($edited['amount_minor'])->toBe(15000)->and($edited['version'])->toBe(5);
});

test('changed receipts stale versions and asserted reviewer or payment fields are rejected atomically', function (): void {
    [$tenant, $owner, $area, $project] = financeFixture();
    $input = financeContents($project, $owner, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()]);
    $packet = financePost($tenant, $owner, $area, 'packet.save', $input)->assertOk()->json();
    financePost($tenant, $owner, $area, 'packet.save', array_replace($input, ['amount_minor' => 1]))->assertConflict();
    financeTransition($tenant, $owner, $area, $packet, 'packet.submit', ['expected_version' => 0])->assertConflict();
    financeTransition($tenant, $owner, $area, $packet, 'packet.submit', ['confirmed' => false])->assertUnprocessable();
    financeTransition($tenant, $owner, $area, $packet, 'packet.submit', ['reason' => '   '])->assertUnprocessable();
    foreach (['paid' => true, 'reviewed_by' => $owner->id, 'fund_verified' => true, 'send_email' => true] as $field => $value) {
        financeTransition($tenant, $owner, $area, $packet, 'packet.submit', [$field => $value])->assertUnprocessable();
    }
    expect(TeamOperationRecord::find($packet['id'])->version)->toBe(1);
});

test('coordinator editor viewer and stale financial roles cannot read or mutate packets even shared areas', function (): void {
    [$tenant, $owner, $area, $project] = financeFixture();
    DB::table('team_areas')->where('id', $area)->update(['profile' => 'enterprise_shared']);
    $packet = financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner))->assertOk()->json();
    foreach (['member', 'viewer', 'guest'] as $role) {
        $user = teamMember($tenant, $role);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $user->id, 'role' => 'coordinator']);
        foreach (['enterprise.finance', 'enterprise.finance.show', 'enterprise.finance.history'] as $route) {
            $response = $this->actingAs($user)->getJson(route($route, ['tenant' => $tenant->slug, 'area_id' => $area, 'id' => $packet['id']]));
            expect($response->status())->toBeIn([403, 404]);
            expect($response->getContent())->not->toContain('Synthetic invoice packet');
        }
        expect(financeTransition($tenant, $user, $area, $packet, 'packet.submit')->status())->toBeIn([403, 404]);
    }
    $tenant->users()->updateExistingPivot($owner->id, ['role' => 'member']);
    financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner, ['id' => $packet['id'], 'expected_version' => 1]))->assertNotFound();
    expect(TeamOperationRecord::find($packet['id'])->version)->toBe(1);
});

test('foreign tenant area project contractor and next owner cannot be bound or disclosed', function (): void {
    [$tenant, $owner, $area, $project] = financeFixture();
    [$foreignTenant, $foreignOwner, $foreignArea, $foreignProject, $foreignAdmin, $foreignContractor] = financeFixture();
    $packet = financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner))->assertOk()->json();
    financePost($tenant, $owner, $area, 'packet.save', financeContents($foreignProject, $owner))->assertNotFound();
    financePost($tenant, $owner, $foreignArea, 'packet.save', financeContents($project, $owner))->assertNotFound();
    financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner, ['contractor_id' => $foreignContractor->id]))->assertNotFound();
    financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $foreignAdmin))->assertUnprocessable();
    $this->actingAs($foreignOwner)->getJson(route('enterprise.finance.show', ['tenant' => $foreignTenant->slug, 'id' => $packet['id']]))->assertNotFound();
    financeTransition($foreignTenant, $foreignOwner, $foreignArea, $packet, 'packet.submit')->assertNotFound();
    expect(TeamOperationRecord::where('kind', FinancePackets::KIND)->count())->toBe(1);
});

test('handoff requires eligible current financial identity and role loss prevents decisions and receipt replay', function (): void {
    [$tenant, $owner, $area, $project, $admin] = financeFixture();
    $receipt = financeContents($project, $owner, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()]);
    $packet = financePost($tenant, $owner, $area, 'packet.save', $receipt)->assertOk()->json();
    $submitted = financeTransition($tenant, $owner, $area, $packet, 'packet.submit')->assertOk()->json();
    $handed = financeTransition($tenant, $owner, $area, $submitted, 'packet.handoff', ['next_owner_id' => $admin->id])->assertOk()->assertJsonPath('next_owner_id', (string) $admin->id)->json();
    $this->actingAs($admin)->getJson(route('enterprise.finance.show', ['tenant' => $tenant->slug, 'id' => $packet['id']]))->assertOk()->assertJsonPath('can_decide', true);
    $admin->forceFill(['email_verified_at' => null])->save();
    financeTransition($tenant, $admin, $area, $handed, 'packet.review', ['decision' => 'approved'])->assertForbidden();
    $this->actingAs($owner)->getJson(route('enterprise.finance.show', ['tenant' => $tenant->slug, 'id' => $packet['id']]))->assertOk()->assertJsonPath('next_owner_eligible', false);
    financeTransition($tenant, $owner, $area, $handed, 'packet.handoff', ['next_owner_id' => $admin->id])->assertUnprocessable();
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    financePost($tenant, $owner, $area, 'packet.save', $receipt)->assertForbidden();
    expect(TeamOperationRecord::find($packet['id'])->version)->toBe(3);
});

test('source references are not verified by their presence incomplete drafts cannot submit and GET never mutates', function (): void {
    [$tenant, $owner, $area, $project] = financeFixture();
    $packet = financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner, ['document_reference' => null, 'fund_code' => null,
        'source_reference' => null, 'restriction_reference' => null]))->assertOk()->json();
    financeTransition($tenant, $owner, $area, $packet, 'packet.submit')->assertUnprocessable();
    $before = [DB::table('team_operation_records')->count(), DB::table('team_operation_attempts')->count(), DB::table('team_operation_revisions')->count()];
    $response = $this->actingAs($owner)->getJson(route('enterprise.finance', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->json('items.0.review'))->toBeNull()->and($response->json('projects.0.id'))->toBe($project)->and($response->json('approvers'))->toHaveCount(2);
    $this->getJson(route('enterprise.finance.history', ['tenant' => $tenant->slug, 'id' => $packet['id']]))->assertOk();
    expect([DB::table('team_operation_records')->count(), DB::table('team_operation_attempts')->count(), DB::table('team_operation_revisions')->count()])->toBe($before);
    Http::assertNothingSent();
});

test('financial reads survive write freeze and rollout removal denies all operations', function (): void {
    [$tenant, $owner, $area, $project] = financeFixture();
    $packet = financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $owner))->assertOk()->json();
    config(['enterprise.writes_enabled' => false]);
    $this->actingAs($owner)->getJson(route('enterprise.finance', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk()
        ->assertJsonPath('can_manage', false)->assertJsonPath('items.0.can_manage', false);
    financeTransition($tenant, $owner, $area, $packet, 'packet.submit')->assertForbidden();
    config(['enterprise.finance_enabled' => false]);
    $this->getJson(route('enterprise.finance.show', ['tenant' => $tenant->slug, 'id' => $packet['id']]))->assertNotFound();
});

test('same tenant department bindings and current archived project authority are checked on reads and replay', function (): void {
    [$tenant, $owner, $area, $project, $admin, $contractor] = financeFixture();
    $otherArea = app(RequestWorkflow::class)->execute($tenant, $owner, ['action' => 'workspace.create', 'id' => (string) Str::uuid(),
        'request_id' => (string) Str::uuid(), 'title' => 'Other synthetic department', 'visibility' => 'department_only'])['id'];
    financePost($tenant, $owner, $otherArea, 'packet.save', financeContents($project, $owner))->assertNotFound();
    $input = financeContents($project, $admin, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'contractor_id' => $contractor->id]);
    $packet = financePost($tenant, $owner, $area, 'packet.save', $input)->assertOk()->json();
    financeTransition($tenant, $owner, $otherArea, $packet, 'packet.submit')->assertNotFound();
    DB::table('team_operation_records')->where('id', $contractor->id)->update(['area_id' => $otherArea]);
    $this->actingAs($owner)->getJson(route('enterprise.finance.show', ['tenant' => $tenant->slug, 'id' => $packet['id']]))->assertNotFound();
    financePost($tenant, $owner, $area, 'packet.save', $input)->assertNotFound();
    DB::table('team_operation_records')->where('id', $contractor->id)->update(['area_id' => $area]);
    app(\App\Services\Team\TeamWorkRepository::class)->execute($tenant->id, fn () => app(\App\Enterprise\WorkspaceAccess::class)->actor($tenant, $owner, true),
        ['kind' => 'project.archive', 'id' => $project, 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'archived' => true]);
    $this->getJson(route('enterprise.finance', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk()->assertJsonCount(0, 'items')->assertJsonCount(0, 'projects');
    $this->getJson(route('enterprise.finance.history', ['tenant' => $tenant->slug, 'id' => $packet['id']]))->assertNotFound();
    financePost($tenant, $owner, $area, 'packet.save', $input)->assertNotFound();
    expect(TeamOperationRecord::find($packet['id'])->version)->toBe(1);
});

test('finance navigation feature excludes ordinary coordinators and survives owner read only mode', function (): void {
    [$tenant, $owner, $area] = financeFixture();
    $coordinator = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area,
        'user_id' => $coordinator->id, 'role' => 'coordinator']);
    $url = route('enterprise.data', ['tenant' => $tenant->slug, 'area_id' => $area]);
    $this->actingAs($coordinator)->getJson($url)->assertOk()->assertJsonPath('features.finance', false);
    config(['enterprise.writes_enabled' => false]);
    $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('features.finance', true)->assertJsonPath('can_manage', false);
    config(['enterprise.finance_enabled' => false]);
    $this->getJson($url)->assertOk()->assertJsonPath('features.finance', false);
});

test('an existing other kind identity cannot be adopted as a finance packet', function (): void {
    [$tenant, $owner, $area, $project, $admin, $contractor] = financeFixture();
    financePost($tenant, $owner, $area, 'packet.save', financeContents($project, $admin, ['id' => $contractor->id]))->assertNotFound();
    expect($contractor->fresh()->kind)->toBe(Planning::KINDS['contractor'])->and($contractor->fresh()->version)->toBe(1);
    expect(TeamOperationRecord::where('kind', FinancePackets::KIND)->count())->toBe(0);
});
