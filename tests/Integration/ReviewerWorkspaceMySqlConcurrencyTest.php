<?php

namespace Tests\Integration;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Enterprise\Provisioning\ClientSilo;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Proposals\ProposalService;
use App\Proposals\RecipientController;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\ReviewerWorkspaceStore;
use App\Proposals\Version;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__).'/Support/reviewer-workspace-mysql-worker.php';

/** This is a real MySQL gate, not SQLite or a mocked lock. It never starts/provisions a DB server. */
final class ReviewerWorkspaceMySqlConcurrencyTest extends TestCase
{
    private array $processes = [];

    private string $runtime;

    private array $fixture;

    private array $connectionIds = [];

    public function test_unapproved_worker_refuses_before_framework_or_database_bootstrap(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/reviewer-workspace-mysql-worker.php'], dirname(__DIR__, 2), [
            'ENTERPRISE_DISPOSABLE_MYSQL' => '1', 'APP_ENV' => 'testing', 'ENTERPRISE_APP_ENV' => 'testing',
            'ENTERPRISE_DB_DRIVER' => 'mysql', 'ENTERPRISE_DB_HOST' => '203.0.113.10',
            'ENTERPRISE_DB_DATABASE' => 'everbranch_enterprise_ci', 'ENTERPRISE_DB_PORT' => '3306',
            'ENTERPRISE_RUNTIME_ROOT' => '/nonexistent/refuse-before-runtime-read',
        ], '{}', 5);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('{"error":"disposable_workspace_worker_refused"}', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_real_mysql_commands_serialize_replay_and_recheck_revoked_publication_without_budget_mutation(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || getenv('ENTERPRISE_DB_DRIVER') !== 'mysql') {
            $this->markTestSkipped('NOT RUN: explicit disposable loopback MySQL 8.4 required; SQLite/local guard checks are not concurrency evidence.');
        }
        \ReviewerWorkspaceMySqlWorker::environment(); // Invalid supplied DB authority fails before any runtime/bootstrap/DB work.
        $previous = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/reviewer-workspace-mysql-'.Str::uuid();
        $tenantId = null;
        $ownerId = null;
        $app = null;
        mkdir($this->runtime, 0700);
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs',
            'storage/app/private', 'storage/app/team-private', 'bootstrap/cache'] as $directory) {
            mkdir($this->runtime.'/'.$directory, 0700, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        chmod($this->runtime.'/.env', 0600);
        putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        try {
            $app = \ReviewerWorkspaceMySqlWorker::boot();
            $this->assertSame('testing', app()->environment());
            $this->assertSame(0, $app->make(Kernel::class)->call('migrate', ['--force' => true]));
            // Never clear another fixture/customer's rows to make a provisioning test pass.
            $this->assertSame(0, DB::table('tenants')->count(), 'Run on the clean disposable fixture after provisioning-test cleanup.');
            $this->assertSame(0, DB::table('enterprise_client_silos')->count());
            $manifest = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
                'storage_id' => config('enterprise_client.storage_id'), 'environment' => 'testing',
                'tenant' => ['business_id' => '92000000-0000-4000-8000-000000000001', 'slug' => 'fictional-workspace-mysql', 'name' => 'FICTIONAL MySQL owner'],
                'branding' => ['organization_name' => 'FICTIONAL MySQL owner', 'accent' => '#123456'],
                'departments' => [['id' => '93000000-0000-4000-8000-000000000001', 'key' => 'administration', 'name' => 'Fictional administration', 'visibility' => 'department_only']],
                'locations' => [], 'workflow_defaults' => [], 'role_defaults' => []];
            $provisioner = app(ClientProvisioner::class);
            $installed = $provisioner->apply($manifest, $provisioner->plan($manifest)['plan_hash']);
            $tenantId = (int) $installed['tenant_id'];
            \ReviewerWorkspaceMySqlWorker::bindOwner();
            $tenant = Tenant::findOrFail($tenantId);
            $owner = User::create(['name' => 'FICTIONAL authorized owner', 'email' => 'mysql-owner@example.invalid', 'password' => Hash::make('FICTIONAL_ONLY'), 'is_active' => true]);
            $ownerId = (int) $owner->id;
            $owner->forceFill(['email_verified_at' => now()])->save();
            $tenant->users()->attach($ownerId, ['role' => 'owner', 'membership_active' => true]);
            $marker = ['synthetic_fixture' => true, 'proposal_review_workspace_anchor' => true, 'reviewed_fixture' => 'mysql-only'];
            TeamArea::create(['id' => config('service_proposals.reviewer_workspace.anchor_area_id'), 'tenant_id' => $tenantId,
                'slug' => 'fictional-mysql-workspace', 'name' => 'FICTIONAL workspace storage', 'profile' => 'enterprise_shared', 'version' => 1, 'settings' => $marker]);
            // Pure reviewed graph reuse only; its PHPUnit/AI setup is never invoked, hence no budget/AI anchor.
            $seed = (new class('fixtureGraph') extends \Tests\Support\ReviewerWorkspaceFixture
            {
                public function fixtureGraph(): void {}

                public function graph(): array
                {
                    return $this->graphSeed();
                }
            })->graph();
            $seed = app(ReviewerWorkspaceSchema::class)->graph($seed, true);
            $policy = array_values(config('service_proposals.reviewer_workspace.policies'))[0];
            $draft = app(ProposalService::class)->leesMcRaeDraft($tenant, $ownerId)->refresh();
            $payload = $draft->payload;
            $payload['review_workspace'] = ['schema_version' => 1, 'workspace_id' => (string) Str::uuid(), 'seed_sha256' => ReviewerWorkspaceSchema::digest($seed),
                'policy_sha256' => ReviewerWorkspaceSchema::digest($policy), 'expires_at' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s\Z')];
            $draft->payload = $payload;
            $draft->save();
            $published = app(ProposalService::class)->publish($tenant, $ownerId, $draft->id, $draft->revision,
                'FICTIONAL_MYSQL_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
            $version = $published['version'];
            $ownerRequest = \ReviewerWorkspaceMySqlWorker::request([], Str::random(40));
            $route = new Route('POST', 'owner-workspaces/{workspace}/fixture', fn () => null);
            $route->bind($ownerRequest);
            $route->setParameter('workspace', $tenant->slug);
            $ownerRequest->setRouteResolver(fn () => $route);
            $ownerRequest->setUserResolver(fn () => $owner);
            app(ReviewerWorkspace::class)->initialize($ownerRequest, $version->id, $seed, ['request_id' => (string) Str::uuid(),
                'descriptor_sha256' => ReviewerWorkspaceSchema::digest($payload['review_workspace'])]);
            mkdir($this->runtime.'/barrier', 0700);
            $binding = ['version_hash' => $version->content_hash, 'build_id' => $version->payload['campus_review']['build_id'], 'source_hash' => $version->payload['campus_review']['source_hash']];
            $selection = ['persona' => 'demo_manager', 'area_ids' => [$seed['areas'][0]['id']], 'person_id' => null];
            $sessions = [];
            for ($index = 0; $index < 2; $index++) {
                $request = \ReviewerWorkspaceMySqlWorker::request([], Str::random(40));
                $request->merge(['password' => 'FICTIONAL_MYSQL_PASSWORD']);
                app(RecipientController::class)->unlock($request, $published['token']);
                app(ReviewerWorkspace::class)->projection($request, $published['token'], $binding, $selection);
                $sessions[] = ['session' => $request->session()->all(), 'session_id' => $request->session()->getId()];
                $this->assertNull($request->user());
            }
            $this->assertNotSame($sessions[0]['session_id'], $sessions[1]['session_id']);
            $this->assertNotSame($sessions[0]['session']['service_proposal_workspace'], $sessions[1]['session']['service_proposal_workspace']);
            $this->fixture = compact('binding', 'selection') + ['token' => $published['token']];
            $command = ['binding' => $binding, 'selection' => $selection, 'request_id' => (string) Str::uuid(), 'expected_revision' => 1,
                'action' => 'project.update', 'record_id' => $seed['projects'][0]['id'], 'expected_record_version' => 1,
                'payload' => ['title' => 'FICTIONAL first competing edit', 'description' => 'Fictional', 'owner_id' => 'p1', 'due_on' => null]];
            $protected = $this->protectedState();
            $this->assertSame(0, TeamOperationRecord::where('kind', 'ent_trial_budget')->count());
            $this->assertFileExists(app(ClientSilo::class)->markerPath());

            // Hold the actual tenant row while two different anonymous commands compete for revision 1.
            DB::beginTransaction();
            DB::table('team_work_locks')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $first = $this->start('different-a', $sessions[0], $command);
            $other = $command;
            $other['request_id'] = (string) Str::uuid();
            $other['payload']['title'] = 'FICTIONAL second competing edit';
            $second = $this->start('different-b', $sessions[1], $other);
            $this->releaseBarrier(['different-a', 'different-b']);
            $this->assertWaiting(['different-a', 'different-b'], 'team_work_locks');
            $before = $this->workspaceState();
            // A read while the old write may still execute cannot settle/cancel/redispatch it.
            $status = $this->recover('unseen-inflight', $sessions[0], $command);
            $this->assertSame(['status' => 'not_observed', 'settled' => false], $status['result']);
            $this->assertSame($before, $this->workspaceState());
            $this->assertTrue($first->isRunning());
            $this->assertTrue($second->isRunning());
            DB::commit();
            $results = [$this->finish($first), $this->finish($second)];
            $this->assertEqualsCanonicalizing([200, 409], array_column($results, 'http_status'));
            $winner = $results[0]['http_status'] === 200 ? 0 : 1;
            $winnerCommand = $winner === 0 ? $command : $other;
            $this->assertSame(2, $this->workspaceRow()->version);
            $this->assertSame($winnerCommand['payload']['title'], $this->workspaceRow()->data['graph']['projects'][0]['title']);
            $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::COMMAND)->count());
            $this->assertSame(ReviewerWorkspaceSchema::commandDigest($winnerCommand), $results[$winner]['result']['receipt']['digest']);
            $recovered = $this->recover('winner-recover', $sessions[$winner], $winnerCommand);
            $this->assertSame($results[$winner]['result']['receipt'], $recovered['result']['receipt']);
            $this->assertSame($protected, $this->protectedState());

            // Exact same ID/body/capability on two connections: one durable application, one replay.
            $same = $command;
            $same['request_id'] = (string) Str::uuid();
            $same['expected_revision'] = 2;
            $same['expected_record_version'] = 2;
            $same['payload']['title'] = 'FICTIONAL exact retry once';
            DB::beginTransaction();
            DB::table('team_work_locks')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $sameA = $this->start('same-a', $sessions[0], $same);
            $sameB = $this->start('same-b', $sessions[0], $same);
            $this->releaseBarrier(['same-a', 'same-b']);
            $this->assertWaiting(['same-a', 'same-b'], 'team_work_locks');
            DB::commit();
            $sameResults = [$this->finish($sameA), $this->finish($sameB)];
            $this->assertSame([200, 200], array_column($sameResults, 'http_status'));
            $this->assertEqualsCanonicalizing([false, true], array_column(array_column($sameResults, 'result'), 'replayed'));
            $this->assertSame($sameResults[0]['result']['receipt'], $sameResults[1]['result']['receipt']);
            $this->assertSame(3, $this->workspaceRow()->version);
            $this->assertSame(2, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::COMMAND)->count());
            $this->assertSame([2, 3], TeamOperationRecord::where('kind', ReviewerWorkspaceStore::COMMAND)->get()->map(fn ($row) => $row->data['revision'])->sort()->values()->all());
            $this->assertSame(3, DB::table('team_operation_revisions')->count(), 'Initializer plus exactly two applied commands; replay/status add no audit writes.');
            $receipt = $this->recover('same-recover', $sessions[0], $same);
            $this->assertSame($sameResults[0]['result']['receipt'], $receipt['result']['receipt']);
            $before = $this->workspaceState();
            $wrongDigest = $same;
            $wrongDigest['payload']['title'] = 'FICTIONAL substituted body';
            $this->assertSame(409, $this->recover('wrong-digest', $sessions[0], $wrongDigest)['http_status']);
            $substitution = $this->start('same-id-new-body', $sessions[0], $wrongDigest);
            $this->releaseBarrier(['same-id-new-body']);
            $this->assertSame(409, $this->finish($substitution)['http_status']);
            $this->assertSame(['status' => 'not_observed', 'settled' => false], $this->recover('other-capability', $sessions[1], $same)['result']);
            $this->assertSame($before, $this->workspaceState());
            $this->assertSame($protected, $this->protectedState());

            // A command enters with the publication visible, then waits on its locking current read.
            $revoked = $same;
            $revoked['request_id'] = (string) Str::uuid();
            $revoked['expected_revision'] = 3;
            $revoked['expected_record_version'] = 3;
            DB::beginTransaction();
            Version::whereKey($version->id)->lockForUpdate()->firstOrFail();
            $pending = $this->start('revoke-waiter', $sessions[0], $revoked);
            $this->releaseBarrier(['revoke-waiter']);
            $this->assertWaiting(['revoke-waiter'], 'service_proposal_versions');
            Version::findOrFail($version->id)->update(['revoked_at' => now()]);
            DB::commit();
            $this->assertSame(410, $this->finish($pending)['http_status']);
            $this->assertSame($before, $this->workspaceState(), 'Revoked waiter must not create a command receipt or change graph/audit bytes.');
            $this->assertSame(410, $this->recover('revoked-unseen', $sessions[0], $revoked)['http_status']);
            $this->assertSame(410, $this->recover('revoked-prior', $sessions[0], $same)['http_status']);
            $this->assertSame($before, $this->workspaceState());
            $this->assertSame($protected, $this->protectedState());
            $this->assertCount(count($this->connectionIds), array_unique($this->connectionIds), 'Every operation uses its own actual MySQL process/connection.');
            Http::assertNothingSent();
        } finally {
            foreach ($this->processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            try {
                if ($app !== null) {
                    while (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    // Only this exact synthetic ownership is removed; no truncate/drop/grant/global cleanup.
                    $owned = DB::table('tenants')->where('id', $tenantId)->where('slug', 'fictional-workspace-mysql')->exists();
                    if ($owned) {
                        foreach (['team_operation_revisions', 'team_operation_records', 'team_work_locks', 'service_proposal_comments', 'service_proposal_reviews', 'service_proposal_versions', 'service_proposal_drafts', 'service_proposal_assets', 'tenant_user', 'team_areas', 'enterprise_client_silos'] as $table) {
                            DB::table($table)->where('tenant_id', $tenantId)->delete();
                        }
                        DB::table('tenants')->where('id', $tenantId)->where('slug', 'fictional-workspace-mysql')->delete();
                        DB::table('users')->where('id', $ownerId)->where('email', 'mysql-owner@example.invalid')->delete();
                    }
                    DB::disconnect('enterprise');
                    $app->flush();
                }
            } finally {
                putenv($previous === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$previous);
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function workspaceRow(): TeamOperationRecord
    {
        return TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->sole();
    }

    private function workspaceState(): array
    {
        return ['records' => DB::table('team_operation_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'audit' => DB::table('team_operation_revisions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }

    private function protectedState(): array
    {
        $tables = ['users', 'tenant_user', 'team_area_members', 'team_tasks', 'team_projects', 'enterprise_client_silos'];
        $state = [];
        foreach ($tables as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $state['budgets'] = DB::table('team_operation_records')->where('kind', 'like', 'ent_trial%')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $state['marker'] = file_get_contents(app(ClientSilo::class)->markerPath());

        return $state;
    }

    private function start(string $name, array $session, array $command, string $action = 'command'): Process
    {
        $input = $session + $this->fixture + ['worker' => $name, 'action' => $action, 'command' => $command,
            'request_id' => $command['request_id'], 'digest' => ReviewerWorkspaceSchema::commandDigest($command)];
        $env = [];
        foreach (['APP_ENV', 'ENTERPRISE_APP_ENV', 'ENTERPRISE_DISPOSABLE_MYSQL', 'ENTERPRISE_DB_DRIVER', 'ENTERPRISE_DB_HOST', 'ENTERPRISE_DB_PORT',
            'ENTERPRISE_DB_DATABASE', 'ENTERPRISE_DB_USERNAME', 'ENTERPRISE_DB_PASSWORD', 'ENTERPRISE_RUNTIME_ROOT'] as $key) {
            $env[$key] = getenv($key);
        }
        // No inherited DB URL/socket, real .env/config cache or production credentials can redirect this worker.
        $env += ['ENTERPRISE_DB_URL' => false, 'ENTERPRISE_DB_SOCKET' => false, 'ENTERPRISE_RUNTIME_ISOLATED' => 'true'];
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/reviewer-workspace-mysql-worker.php'], dirname(__DIR__, 2), $env,
            json_encode($input, JSON_THROW_ON_ERROR), 30);
        $process->start();
        $this->processes[] = $process;
        $this->waitFor(fn () => is_file($this->runtime.'/barrier/ready-'.$name), 'Independent worker did not reach barrier.');
        $this->connectionIds[$name] = (int) file_get_contents($this->runtime.'/barrier/ready-'.$name);

        return $process;
    }

    private function releaseBarrier(array $names): void
    {
        foreach ($names as $name) {
            file_put_contents($this->runtime.'/barrier/go-'.$name, 'go');
        }
        foreach ($names as $name) {
            $this->waitFor(fn () => is_file($this->runtime.'/barrier/attempt-'.$name), 'Worker did not attempt operation.');
        }
    }

    private function assertWaiting(array $names, string $table): void
    {
        $ids = array_map(fn ($name) => $this->connectionIds[$name], $names);
        $this->waitFor(function () use ($ids, $table): bool {
            $rows = DB::select('SELECT DISTINCT t.PROCESSLIST_ID AS id FROM performance_schema.data_lock_waits w '
                .'JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID '
                .'JOIN performance_schema.data_locks l ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID '
                .'WHERE l.OBJECT_SCHEMA = ? AND l.OBJECT_NAME = ?', ['everbranch_enterprise_ci', $table]);
            $waiting = array_map(fn ($row) => (int) $row->id, $rows);

            return array_diff($ids, $waiting) === [];
        }, 'Actual independent MySQL sessions did not block on the required native row lock.');
    }

    private function finish(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), 'Disposable worker failed; raw SQL/config output is intentionally suppressed.');
        $this->assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    private function recover(string $name, array $session, array $command): array
    {
        $process = $this->start($name, $session, $command, 'status');
        $this->releaseBarrier([$name]);

        return $this->finish($process);
    }

    private function waitFor(callable $predicate, string $message): void
    {
        $deadline = microtime(true) + 10;
        do {
            if ($predicate()) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail($message);
    }
}
