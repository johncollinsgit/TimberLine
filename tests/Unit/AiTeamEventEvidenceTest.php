<?php

use App\Models\AiBranchAccount;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\TenantAccessAddon;
use App\Models\TenantBillingSubscription;
use App\Services\AiBranch\AiTeamEventEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(Tests\TestCase::class);
require_once __DIR__.'/../Support/TeamStudioFixtures.php';
require_once __DIR__.'/../../app/Services/AiBranch/AiTeamEventEvidence.php';

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
        $this->markTestSkipped('Requires a disposable in-memory SQLite database.');
    }
    $this->artisan('migrate:fresh', ['--force' => true]);
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true,
        'ai_branch.enabled' => true]);
    Http::preventStrayRequests();
});

function aiTeamEvidenceFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_growth', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_'.Str::uuid(),
        'purchase_key' => 'addon.ai_branch_growth', 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
    AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1500]);
    config(['ai_branch.tenant_ids' => [$tenant->id]]);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id,
        'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional',
        'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'],
        'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read']]);
    $task = ['gid' => '400', 'name' => 'Fictional event', 'notes' => str_repeat('é', 4000), 'completed' => false,
        'modified_at' => '2026-11-06T05:00:48.901Z', 'workspace' => ['gid' => '100'], 'assignee' => ['gid' => '300'],
        'memberships' => [['project' => ['gid' => '200']]]];
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'connection_id' => $connection->id, 'kind' => 'event', 'title' => 'Fictional event', 'source_key' => hash('sha256', 'asana:100:400'),
        'status' => 'source', 'version' => 1, 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-06',
        'data' => ['_generation' => 1, 'source_id' => '400', 'source_project' => '200', 'source_workspace' => '100',
            'source_version' => $task['modified_at'], 'original_notes' => $task['notes']]]);

    return [$tenant, $owner, $connection, $event, $task];
}

test('event evidence is server-scoped, bounded, untrusted, and read without database locks', function (): void {
    [$tenant, $owner, $connection, $event, $task] = aiTeamEvidenceFixture();
    Http::fake(function () use ($task) {
        expect(DB::transactionLevel())->toBe(0);

        return Http::response(['data' => $task]);
    });
    $adapter = app(AiTeamEventEvidence::class);
    $evidence = $adapter->capture($tenant, $owner, $event->id);
    expect($evidence['source']['ref'])->toBe('team_event:'.$event->id)
        ->and(strlen($evidence['source']['untrusted_notes']))->toBe(6000)
        ->and(mb_check_encoding($evidence['source']['untrusted_notes'], 'UTF-8'))->toBeTrue()
        ->and($evidence['source']['notes_truncated'])->toBeTrue()
        ->and($adapter->revalidate($tenant, $owner, $evidence)['binding'])->toBe($evidence['binding']);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('uuid, tenant, source revocation, and local changes fail closed', function (): void {
    [$tenant, $owner, $connection, $event, $task] = aiTeamEvidenceFixture();
    Http::fake(fn () => Http::response(['data' => $task]));
    $adapter = app(AiTeamEventEvidence::class);
    expect(fn () => $adapter->capture($tenant, $owner, '400'))->toThrow(HttpException::class);
    $proof = $adapter->capture($tenant, $owner, $event->id);
    [$other, $otherOwner] = aiTeamEvidenceFixture();
    config(['ai_branch.tenant_ids' => [$tenant->id, $other->id]]);
    expect(fn () => $adapter->capture($other, $otherOwner, $event->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $event->update(['version' => 2]);
    expect(fn () => $adapter->revalidate($tenant, $owner, $proof))->toThrow(HttpException::class);
    $event->update(['version' => 1]);
    $connection->update(['status' => 'disconnected']);
    expect(fn () => $adapter->revalidate($tenant, $owner, $proof))->toThrow(HttpException::class);
});

test('changed live source is rejected and no source read occurs inside a transaction', function (): void {
    [$tenant, $owner, $connection, $event, $task] = aiTeamEvidenceFixture();
    $calls = 0;
    Http::fake(function () use (&$task, &$calls) {
        $calls++;

        return Http::response(['data' => $task]);
    });
    $adapter = app(AiTeamEventEvidence::class);
    $proof = $adapter->capture($tenant, $owner, $event->id);
    $task['modified_at'] = '2026-11-07T00:00:00Z';
    expect(fn () => $adapter->revalidate($tenant, $owner, $proof))->toThrow(HttpException::class);
    $before = $calls;
    DB::beginTransaction();
    try {
        expect(fn () => $adapter->capture($tenant, $owner, $event->id))->toThrow(HttpException::class);
    } finally {
        DB::rollBack();
    }
    expect($calls)->toBe($before);
});

test('revoked membership and source permission cannot reuse staged evidence', function (): void {
    [$tenant, $owner, $connection, $event, $task] = aiTeamEvidenceFixture();
    $deny = false;
    Http::fake(function () use (&$deny, $task) {
        return $deny ? Http::response([], 403) : Http::response(['data' => $task]);
    });
    $adapter = app(AiTeamEventEvidence::class);
    $proof = $adapter->capture($tenant, $owner, $event->id);
    $deny = true;
    expect(fn () => $adapter->revalidate($tenant, $owner, $proof))->toThrow(HttpException::class);
    $deny = false;
    $owner->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    expect(fn () => $adapter->revalidate($tenant, $owner, $proof))->toThrow(HttpException::class);
});

test('changed live notes with unchanged modified time are rejected', function (): void {
    [$tenant, $owner, $connection, $event, $task] = aiTeamEvidenceFixture();
    Http::fake(function () use (&$task) {
        return Http::response(['data' => $task]);
    });
    $adapter = app(AiTeamEventEvidence::class);
    $proof = $adapter->capture($tenant, $owner, $event->id);
    $task['notes'] = 'Changed source text';
    expect(fn () => $adapter->revalidate($tenant, $owner, $proof))->toThrow(HttpException::class);
});
