<?php

use App\Enterprise\UsageOverview;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true,
        'enterprise.operations_enabled' => true, 'enterprise.writes_enabled' => true]);
    Route::middleware('web')->prefix('usage-overview-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
});

function usageFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => array_merge(config('enterprise.tenant_allowlist', []), [(string) $tenant->id])]);
    $area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'usage-'.Str::lower(Str::random(8)),
        'name' => 'Synthetic authorized department', 'profile' => 'enterprise_department', 'version' => 1]);

    return [$tenant, $owner, $area];
}

function usageLedger($tenant, $area, string $purpose, string $status, array $data = []): TeamOperationRecord
{
    return TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'kind' => 'ent_ai_usage', 'title' => 'PRIVATE AI record title', 'status' => $status, 'source_key' => hash('sha256', (string) Str::uuid()),
        'version' => 1, 'visibility' => 'area', 'data' => $data + ['purpose' => $purpose, 'user_id' => 'PRIVATE-actor',
            'input_hash' => 'PRIVATE-input-hash', 'settings_hash' => 'PRIVATE-settings-hash', 'model' => 'PRIVATE-model',
            'provider_id' => 'PRIVATE-provider-id', 'value' => ['question' => 'PRIVATE-question', 'refs' => ['PRIVATE-project']]]]);
}

function usageGet($tenant, $user, ?string $area = null)
{
    return test()->actingAs($user)->getJson(route('enterprise.ai_usage', ['tenant' => $tenant->slug] + ($area === null ? [] : ['area_id' => $area])));
}

test('usage overview aggregates real ledger fields by permitted department and purpose without private content', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    $other = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'other-usage', 'name' => 'Other synthetic department',
        'profile' => 'enterprise_shared', 'version' => 1]);
    $complete = usageLedger($tenant, $area, 'ask', 'completed', ['reserved_microusd' => 100, 'charged_microusd' => 15, 'input_tokens' => 10, 'output_tokens' => 5]);
    usageLedger($tenant, $area, 'briefing', 'uncertain', ['reserved_microusd' => 200]);
    usageLedger($tenant, $other, 'workflow_draft', 'reserved', ['reserved_microusd' => 300]);
    usageLedger($tenant, $other, 'ask', 'cancelled', ['reserved_microusd' => 400, 'charged_microusd' => 0]);
    $response = usageGet($tenant, $owner)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertJsonPath('read_only', true)->assertJsonPath('provider_invoice_verified', false)->assertJsonPath('scope.type', 'authorized_departments');
    $totals = $response->json('totals');
    expect($totals['requests'])->toBe(4)->and($totals['completed'])->toBe(1)->and($totals['uncertain'])->toBe(1)->and($totals['reserved'])->toBe(1)
        ->and($totals['cancelled'])->toBe(1)->and($totals['input_tokens'])->toBe(10)->and($totals['output_tokens'])->toBe(5)
        ->and($totals['original_reserved_microusd'])->toBe(1000)->and($totals['settled_usage_derived_microusd'])->toBe(15)
        ->and($totals['reserved_exposure_microusd'])->toBe(500)->and($totals['uncertain_reserved_microusd'])->toBe(200)
        ->and($totals['estimated_total_exposure_microusd'])->toBe(515)->and($totals['reported_token_records'])->toBe(1)->and($totals['token_unknown_records'])->toBe(3);
    $departments = collect($response->json('departments'));
    expect($departments->firstWhere('id', $area->id)['totals']['requests'])->toBe(2);
    expect(collect($departments->firstWhere('id', $other->id)['purposes'])->firstWhere('purpose', 'workflow_draft')['totals']['reserved_exposure_microusd'])->toBe(300);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->getContent())->not->toContain('PRIVATE-', $complete->id)->and($response->json('basis.settled_cost'))->toContain('not a verified provider invoice');
    expect(DB::table('team_operation_records')->where('id', $complete->id)->value('data'))->not->toContain('PRIVATE-question');
    Http::assertNothingSent();
});

test('an existing Intelligence request produces the ledger summarized without another provider call', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    config(['enterprise.intelligence' => ['enabled' => true, 'api_key' => 'synthetic-no-real-key', 'model' => 'approved-test-model',
        'input_rate_microusd_per_million' => 1000000, 'output_rate_microusd_per_million' => 1000000, 'max_input' => 4000, 'max_output' => 100,
        'lifetime_cap_microusd' => 10000, 'rates_valid_until' => now()->addDay()->toIso8601String(), 'tenant_allowlist' => [(string) $tenant->id]]]);
    Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'PRIVATE-provider-receipt', 'status' => 'completed',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"refs":["PRIVATE-project"]}']]]]])]);
    app(\App\Enterprise\Intelligence::class)->generate($tenant, $owner, $area->id, 'ask', ['question' => 'PRIVATE-question'], (string) Str::uuid());
    Http::assertSentCount(1);
    $response = usageGet($tenant, $owner, $area->id)->assertOk()->assertJsonPath('totals.requests', 1)->assertJsonPath('totals.input_tokens', 10)
        ->assertJsonPath('totals.output_tokens', 5)->assertJsonPath('totals.original_reserved_microusd', 4100)
        ->assertJsonPath('totals.settled_usage_derived_microusd', 15)->assertJsonPath('totals.reserved_exposure_microusd', 0);
    expect($response->getContent())->not->toContain('PRIVATE-', 'synthetic-no-real-key');
    Http::assertSentCount(1);
});

test('selected department excludes foreign tenant nonenterprise scope and other record kinds', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    [$foreignTenant, $foreignOwner, $foreignArea] = usageFixture();
    usageLedger($tenant, $area, 'ask', 'completed', ['reserved_microusd' => 100, 'charged_microusd' => 5, 'input_tokens' => 2, 'output_tokens' => 3]);
    usageLedger($foreignTenant, $foreignArea, 'ask', 'uncertain', ['reserved_microusd' => 999999]);
    $legacyArea = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'nonenterprise', 'name' => 'PRIVATE-legacy-department', 'profile' => 'general', 'version' => 1]);
    usageLedger($tenant, $legacyArea, 'ask', 'uncertain', ['reserved_microusd' => 999999]);
    $otherKind = usageLedger($tenant, $area, 'ask', 'uncertain', ['reserved_microusd' => 999999]);
    $otherKind->forceFill(['kind' => 'ent_trial_usage'])->save();
    usageGet($tenant, $owner, $area->id)->assertOk()->assertJsonPath('scope.type', 'department')->assertJsonPath('totals.requests', 1)->assertJsonPath('totals.estimated_total_exposure_microusd', 5);
    usageGet($tenant, $owner, $foreignArea->id)->assertNotFound();
    usageGet($tenant, $owner, $legacyArea->id)->assertNotFound();
    usageGet($foreignTenant, $owner, $foreignArea->id)->assertForbidden();
    $all = usageGet($tenant, $owner)->assertOk()->assertJsonPath('totals.requests', 1);
    expect($all->getContent())->not->toContain($foreignArea->id, 'PRIVATE-legacy-department');
});

test('ordinary coordinators viewers and demoted financial actors cannot read usage totals', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    usageLedger($tenant, $area, 'ask', 'uncertain', ['reserved_microusd' => 1234]);
    foreach (['member', 'viewer', 'guest'] as $role) {
        $user = teamMember($tenant, $role);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id, 'role' => 'coordinator']);
        expect(usageGet($tenant, $user, $area->id)->status())->toBeIn([403, 404]);
    }
    $tenant->users()->updateExistingPivot($owner->id, ['role' => 'member']);
    usageGet($tenant, $owner)->assertNotFound();
});

test('inactive unverified or revoked identities lose access immediately and paused writes preserve readonly usage', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    usageLedger($tenant, $area, 'ask', 'uncertain', ['reserved_microusd' => 1234]);
    config(['enterprise.writes_enabled' => false]);
    usageGet($tenant, $owner)->assertOk()->assertJsonPath('totals.requests', 1)->assertJsonPath('read_only', true)->assertJsonPath('technical_budget.ready', false);
    $owner->forceFill(['email_verified_at' => null])->save();
    usageGet($tenant, $owner)->assertForbidden();
    $owner->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
    usageGet($tenant, $owner)->assertForbidden();
    $owner->forceFill(['is_active' => true])->save();
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    usageGet($tenant, $owner)->assertForbidden();
});

test('GET does not mutate ledger revisions receipts permissions or call providers and readiness hides technical amounts', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    config(['enterprise.intelligence' => ['enabled' => true, 'api_key' => 'PRIVATE-key', 'model' => 'approved-test-model',
        'input_rate_microusd_per_million' => 7654321, 'output_rate_microusd_per_million' => 7654321, 'max_input' => 4000, 'max_output' => 100,
        'lifetime_cap_microusd' => 87654321, 'rates_valid_until' => now()->addDay()->toIso8601String(), 'tenant_allowlist' => [(string) $tenant->id]]]);
    usageLedger($tenant, $area, 'ask', 'reserved', ['reserved_microusd' => 321]);
    $before = [DB::table('team_operation_records')->get()->toJson(), DB::table('team_operation_revisions')->count(), DB::table('team_operation_attempts')->count(), DB::table('tenant_user')->count()];
    $response = usageGet($tenant, $owner)->assertOk()->assertJsonPath('technical_budget.ready', true)->assertJsonPath('technical_budget.provider_calls_verified', false);
    expect($response->getContent())->not->toContain('PRIVATE-key', '7654321', '87654321', 'input_hash', 'settings_hash', 'provider_id', 'user_id');
    expect([DB::table('team_operation_records')->get()->toJson(), DB::table('team_operation_revisions')->count(), DB::table('team_operation_attempts')->count(), DB::table('tenant_user')->count()])->toBe($before);
    Http::assertNothingSent();
});

test('zero records and unknown or malformed outcomes retain explicit uncertainty instead of invented zero spend', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    $empty = usageGet($tenant, $owner)->assertOk()->assertJsonPath('ledger_state', 'no_records_in_scope')->assertJsonPath('totals.requests', 0);
    expect($empty->json('basis.zero_records'))->toContain('not proof of zero provider-account spend');
    usageLedger($tenant, $area, 'PRIVATE-question-as-purpose', 'completed', ['reserved_microusd' => 100, 'charged_microusd' => '12', 'input_tokens' => 3, 'output_tokens' => 4]);
    usageLedger($tenant, $area, 'ask', 'PRIVATE-bad-status', ['reserved_microusd' => -50]);
    $response = usageGet($tenant, $owner)->assertOk()->assertJsonPath('totals.unreconciled', 2)->assertJsonPath('totals.completed', 0)
        ->assertJsonPath('totals.settled_usage_derived_microusd', 0)->assertJsonPath('totals.reserved_exposure_microusd', 100)->assertJsonPath('totals.exposure_unknown_records', 1)
        ->assertJsonPath('totals.original_reservation_unknown_records', 1);
    expect($response->getContent())->not->toContain('PRIVATE-');
    expect(array_column($response->json('departments.0.purposes'), 'purpose'))->toContain('other');
});

test('usage scope and permission are checked again after encrypted records load', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    usageLedger($tenant, $area, 'ask', 'uncertain', ['reserved_microusd' => 1234]);
    $changed = false;
    DB::listen(function ($query) use ($tenant, $owner, &$changed): void {
        if (! $changed && str_contains(strtolower($query->sql), 'from "team_operation_records"') && in_array('ent_ai_usage', $query->bindings, true)) {
            $changed = true;
            DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $owner->id])->update(['role' => 'member']);
        }
    });
    usageGet($tenant, $owner)->assertNotFound();
    expect($changed)->toBeTrue();
});

test('department retirement during generation fails without exposing stale counts', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    usageLedger($tenant, $area, 'ask', 'uncertain', ['reserved_microusd' => 1234]);
    $changed = false;
    DB::listen(function ($query) use ($area, &$changed): void {
        if (! $changed && str_contains(strtolower($query->sql), 'from "team_operation_records"') && in_array('ent_ai_usage', $query->bindings, true)) {
            $changed = true;
            DB::table('team_areas')->where('id', $area->id)->update(['profile' => 'general']);
        }
    });
    $response = usageGet($tenant, $owner)->assertConflict();
    expect($response->getContent())->not->toContain('1234', 'Synthetic authorized department');
});

test('bounded ledger scan is explicit and no routing override or disabled rollout can reveal usage', function (): void {
    [$tenant, $owner, $area] = usageFixture();
    $encrypted = \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['purpose' => 'ask', 'reserved_microusd' => 1], JSON_THROW_ON_ERROR));
    $rows = [];
    for ($i = 0; $i <= UsageOverview::SCAN_LIMIT; $i++) {
        $id = (string) Str::uuid();
        $rows[] = ['id' => $id, 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'ent_ai_usage', 'title' => 'Synthetic usage',
            'status' => 'reserved', 'source_key' => hash('sha256', $id), 'version' => 1, 'visibility' => 'area', 'data' => $encrypted,
            'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('team_operation_records')->insert($chunk);
    }
    usageGet($tenant, $owner)->assertOk()->assertJsonPath('totals.requests', UsageOverview::SCAN_LIMIT)
        ->assertJsonPath('totals.reserved_exposure_microusd', UsageOverview::SCAN_LIMIT)->assertJsonPath('totals.exposure_unknown_records', 0)
        ->assertJsonPath('coverage.partial', true)->assertJsonPath('coverage.has_more_records', true)->assertJsonPath('coverage.scanned_records', UsageOverview::SCAN_LIMIT);
    config(['enterprise.operations_enabled' => false]);
    usageGet($tenant, $owner)->assertNotFound();
});
