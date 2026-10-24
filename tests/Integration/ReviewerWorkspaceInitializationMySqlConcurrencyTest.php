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
use App\Proposals\ReviewerWorkspaceInitialization;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\ReviewerWorkspaceStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ReviewerWorkspaceFixtureHandlers;

require_once dirname(__DIR__).'/Support/reviewer-workspace-initialization-mysql-worker.php';

/** Strict existing disposable CI MySQL only; never starts/provisions a DB server. */
final class ReviewerWorkspaceInitializationMySqlConcurrencyTest extends TestCase
{
    use ReviewerWorkspaceFixtureHandlers;

    private array $processes = [];

    private array $connectionIds = [];

    private string $runtime;

    private array $fixture;

    /** Remove every inherited secret/provider/runtime setting before allowlisting synthetic CI connection input. */
    private function workerEnvironment(array $overrides = []): array
    {
        $env = array_fill_keys(array_keys(getenv()), false);
        foreach (['PATH', 'TMPDIR', 'APP_ENV', 'ENTERPRISE_APP_ENV', 'ENTERPRISE_DISPOSABLE_MYSQL', 'ENTERPRISE_DB_DRIVER',
            'ENTERPRISE_DB_HOST', 'ENTERPRISE_DB_PORT', 'ENTERPRISE_DB_DATABASE', 'ENTERPRISE_DB_USERNAME', 'ENTERPRISE_DB_PASSWORD', 'ENTERPRISE_RUNTIME_ROOT'] as $key) {
            $env[$key] = getenv($key);
        }

        return array_replace($env, ['ENTERPRISE_DB_URL' => false, 'ENTERPRISE_DB_SOCKET' => false], $overrides);
    }

    public function test_unapproved_initializer_worker_refuses_before_runtime_framework_database_or_provider(): void
    {
        $base = ['APP_ENV' => 'testing', 'ENTERPRISE_APP_ENV' => 'testing', 'ENTERPRISE_DISPOSABLE_MYSQL' => '1',
            'ENTERPRISE_DB_DRIVER' => 'mysql', 'ENTERPRISE_DB_HOST' => '127.0.0.1', 'ENTERPRISE_DB_PORT' => '3306',
            'ENTERPRISE_DB_DATABASE' => 'everbranch_enterprise_ci', 'ENTERPRISE_DB_USERNAME' => 'FICTIONAL_NOT_USED',
            'ENTERPRISE_DB_PASSWORD' => 'FICTIONAL_NOT_USED', 'ENTERPRISE_RUNTIME_ROOT' => '/nonexistent/refuse-before-bootstrap'];
        foreach ([['ENTERPRISE_DB_HOST' => '203.0.113.10'], ['ENTERPRISE_DB_DATABASE' => 'customer_database'],
            ['ENTERPRISE_DISPOSABLE_MYSQL' => false], ['ENTERPRISE_DB_URL' => 'mysql://FICTIONAL.invalid/never'], []] as $bad) {
            $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/reviewer-workspace-initialization-mysql-worker.php'], dirname(__DIR__, 2),
                $this->workerEnvironment(array_replace($base, $bad)), '{}', 5);
            $process->run();
            $this->assertSame(2, $process->getExitCode());
            $this->assertTrue($process->getOutput() === '{"error":"disposable_initializer_worker_refused"}', 'Worker refusal output changed; raw output suppressed.');
            $this->assertTrue($process->getErrorOutput() === '', 'Worker emitted unexpected stderr; raw content suppressed.');
        }
    }

    public function test_mysql_owner_initialization_is_once_only_and_fresh_owner_membership_locks_deny_revoked_waiters(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || getenv('ENTERPRISE_DB_DRIVER') !== 'mysql') {
            $this->markTestSkipped('NOT RUN: explicit existing disposable loopback MySQL8.4 required; local refusal/syntax is not concurrency acceptance.');
        }
        \ReviewerWorkspaceMySqlWorker::environment();
        $previous = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/reviewer-workspace-mysql-'.Str::uuid();
        $tenantId = $ownerId = null;
        $app = null;
        mkdir($this->runtime, 0700);
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs',
            'storage/app/private', 'storage/app/team-private', 'bootstrap/cache', 'barrier'] as $directory) {
            mkdir($this->runtime.'/'.$directory, 0700, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        chmod($this->runtime.'/.env', 0600);
        putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        try {
            $app = \ReviewerWorkspaceMySqlWorker::boot();
            $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
            $this->assertSame(0, $app->make(Kernel::class)->call('migrate', ['--force' => true]));
            foreach (['tenants', 'enterprise_client_silos', 'users', 'tenant_user', 'team_area_members', 'team_operation_records',
                'team_operation_revisions', 'team_work_locks', 'service_proposal_versions'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), 'Require clean owned CI fixture after the existing workspace gate cleanup; never clear foreign state.');
            }
            $manifest = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
                'storage_id' => config('enterprise_client.storage_id'), 'environment' => 'testing',
                'tenant' => ['business_id' => '92000000-0000-4000-8000-000000000011', 'slug' => 'fictional-owner-initializer-mysql', 'name' => 'FICTIONAL initializer MySQL owner'],
                'branding' => ['organization_name' => 'FICTIONAL initializer MySQL owner', 'accent' => '#123456'],
                'departments' => [['id' => '93000000-0000-4000-8000-000000000011', 'key' => 'administration', 'name' => 'Fictional administration', 'visibility' => 'department_only']],
                'locations' => [], 'workflow_defaults' => [], 'role_defaults' => []];
            $provisioner = app(ClientProvisioner::class);
            $installed = $provisioner->apply($manifest, $provisioner->plan($manifest)['plan_hash']);
            $tenantId = (int) $installed['tenant_id'];
            \ReviewerWorkspaceMySqlWorker::bindOwner();
            $tenant = Tenant::findOrFail($tenantId);
            $owner = User::create(['name' => 'FICTIONAL initializer owner', 'email' => 'mysql-initializer-owner@example.invalid',
                'password' => Hash::make('FICTIONAL_ONLY'), 'is_active' => true]);
            $ownerId = (int) $owner->id;
            $owner->forceFill(['email_verified_at' => now()])->save();
            $tenant->users()->attach($ownerId, ['role' => 'owner', 'membership_active' => true]);
            $marker = ['synthetic_fixture' => true, 'proposal_review_workspace_anchor' => true, 'reviewed_fixture' => 'mysql-only'];
            TeamArea::create(['id' => config('service_proposals.reviewer_workspace.anchor_area_id'), 'tenant_id' => $tenantId,
                'slug' => 'fictional-mysql-workspace', 'name' => 'FICTIONAL workspace storage', 'profile' => 'enterprise_shared', 'version' => 1, 'settings' => $marker]);
            // Pure graph reuse only; never invokes ReviewerWorkspaceFixture setup or creates its AI ledger.
            $seed = (new class('fixtureGraph') extends \Tests\Support\ReviewerWorkspaceFixture
            {
                public function fixtureGraph(): void {}

                public function graph(): array
                {
                    return $this->graphSeed();
                }
            })->graph();
            $seed = app(ReviewerWorkspaceSchema::class)->graph($seed, true);
            $service = app(ProposalService::class);
            $draft = $service->leesMcRaeDraft($tenant, $ownerId)->refresh();
            $old = $service->publish($tenant, $ownerId, $draft->id, (int) $draft->revision, 'FICTIONAL_MYSQL_PASSWORD', 7, (string) Str::uuid(), 'reviewer')['version'];
            $service->comment($old, $old->payload['reviewer_names'][0], 'overview', 'FICTIONAL preserved predecessor feedback', (string) Str::uuid());
            $policy = array_values(config('service_proposals.reviewer_workspace.policies'))[0];
            $payload = $draft->payload;
            $payload['review_workspace'] = ['schema_version' => 1, 'workspace_id' => (string) Str::uuid(), 'seed_sha256' => ReviewerWorkspaceSchema::digest($seed),
                'policy_sha256' => ReviewerWorkspaceSchema::digest($policy), 'expires_at' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s\Z')];
            $draft->payload = $payload;
            $draft->revision++;
            $draft->save();
            $draft->refresh();
            $published = $service->publish($tenant, $ownerId, $draft->id, (int) $draft->revision, 'FICTIONAL_MYSQL_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
            $version = $published['version'];
            $approval = ['schema_version' => 1, 'data_basis' => 'reviewed_current_edits', 'version_id' => $version->id, 'version_hash' => $version->content_hash,
                'build_id' => $version->payload['campus_review']['build_id'], 'source_hash' => $version->payload['campus_review']['source_hash'],
                'workspace_id' => $payload['review_workspace']['workspace_id'], 'descriptor_sha256' => ReviewerWorkspaceSchema::digest($payload['review_workspace']),
                'seed_sha256' => ReviewerWorkspaceSchema::digest($seed), 'import_evidence_sha256' => str_repeat('e', 64),
                'predecessor_version_id' => $old->id, 'predecessor_version_hash' => $old->content_hash,
                'expires_at' => now()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z')];
            $approvalHash = ReviewerWorkspaceSchema::digest($approval);
            config(['service_proposals.reviewer_workspace.owner_initialization' => ['enabled' => true, 'approvals' => [$approvalHash => $approval]]]);
            $body = ['version_id' => $version->id, 'request_id' => (string) Str::uuid(), 'approval_sha256' => $approvalHash,
                'import_evidence_sha256' => $approval['import_evidence_sha256'], 'seed' => $seed];
            $request = \ReviewerWorkspaceInitializationMySqlWorker::ownerRequest([], Str::random(40), $ownerId, $tenant->slug);
            $this->fixture = ['session' => $request->session()->all(), 'session_id' => $request->session()->getId(),
                'owner_id' => $ownerId, 'slug' => $tenant->slug, 'approval' => $approval];
            $initializer = app(ReviewerWorkspaceInitialization::class);
            $status = array_intersect_key($body, array_flip(['version_id', 'request_id', 'approval_sha256']));
            $this->assertSame(['status' => 'not_observed', 'settled' => false], $initializer->status($request, $status));
            // Explicit native test fixture lock row; every application mutation still uses its actual tenant lock.
            DB::table('team_work_locks')->insert(['tenant_id' => $tenantId]);
            $protected = $this->protectedState();
            $absent = $this->workspaceState();
            $this->assertSame(0, TeamOperationRecord::where('kind', 'like', 'ent_trial%')->count());
            $this->assertFileExists(app(ClientSilo::class)->markerPath());

            // A real current membership locking read waits behind the actual parent row, not a timed guess.
            $apply = $body + ['preview' => $initializer->preview($request, $body)['preview']];
            DB::beginTransaction();
            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $ownerId)->lockForUpdate()->first();
            $membership = $this->start('membership-waiter', 'apply', $apply);
            $this->releaseBarrier(['membership-waiter']);
            $this->assertWaiting(['membership-waiter'], 'tenant_user');
            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $ownerId)->update(['membership_active' => false]);
            DB::commit();
            $this->assertSame(403, $this->finish($membership)['http_status']);
            $this->assertSame($absent, $this->workspaceState(), 'Revoked membership waiter may not create graph, init receipt or audit.');
            $this->assertSame(403, $this->recover('membership-status-denied', $status)['http_status']);
            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $ownerId)->update(['membership_active' => true]);
            $this->assertSame($protected, $this->protectedState(), 'Only the explicit synthetic parent membership change is restored.');

            $apply = $body + ['preview' => $initializer->preview($request, $body)['preview']];
            DB::beginTransaction();
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $account = $this->start('account-waiter', 'apply', $apply);
            $this->releaseBarrier(['account-waiter']);
            $this->assertWaiting(['account-waiter'], 'users');
            DB::table('users')->where('id', $ownerId)->update(['is_active' => false]);
            DB::commit();
            $this->assertSame(403, $this->finish($account)['http_status']);
            $this->assertSame($absent, $this->workspaceState(), 'Cached active User cannot override a current inactive locking read.');
            $this->assertSame(403, $this->recover('account-status-denied', $status)['http_status']);
            DB::table('users')->where('id', $ownerId)->update(['is_active' => true]);
            $this->assertSame($protected, $this->protectedState());

            // SAME preview/session/intent on two actual processes: exactly one init and one replay.
            $apply = $body + ['preview' => $initializer->preview($request, $body)['preview']];
            DB::beginTransaction();
            DB::table('team_work_locks')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $one = $this->start('same-a', 'apply', $apply);
            $two = $this->start('same-b', 'apply', $apply);
            $this->releaseBarrier(['same-a', 'same-b']);
            $this->assertWaiting(['same-a', 'same-b'], 'team_work_locks');
            $this->assertSame(['status' => 'not_observed', 'settled' => false], $this->recover('pending-status', $status)['result']);
            $this->assertSame($absent, $this->workspaceState());
            $this->assertTrue($one->isRunning());
            $this->assertTrue($two->isRunning());
            DB::commit();
            $results = [$this->finish($one), $this->finish($two)];
            $this->assertSame([200, 200], array_column($results, 'http_status'));
            $this->assertEqualsCanonicalizing([false, true], array_column(array_column($results, 'result'), 'replayed'));
            $receipt = $results[0]['result']['receipt'];
            $this->assertSame($receipt, $results[1]['result']['receipt']);
            $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->count());
            $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->sole()->version);
            $this->assertSame(1, DB::table('team_operation_revisions')->count());
            $this->assertSame($protected, $this->protectedState());

            // Actual shared service edit, then independent original-intent replay/status must preserve it byte for byte.
            $reader = \ReviewerWorkspaceMySqlWorker::request([], Str::random(40));
            $reader->merge(['password' => 'FICTIONAL_MYSQL_PASSWORD']);
            app(RecipientController::class)->unlock($reader, $published['token']);
            $binding = ['version_hash' => $version->content_hash, 'build_id' => $approval['build_id'], 'source_hash' => $approval['source_hash']];
            $selection = ['persona' => 'demo_manager', 'area_ids' => [$seed['areas'][0]['id']], 'person_id' => null];
            app(ReviewerWorkspace::class)->command($reader, $published['token'], ['binding' => $binding, 'selection' => $selection,
                'request_id' => (string) Str::uuid(), 'expected_revision' => 1, 'action' => 'project.update',
                'record_id' => $seed['projects'][0]['id'], 'expected_record_version' => 1,
                'payload' => ['title' => 'FICTIONAL retained later edit', 'description' => 'Fictional', 'owner_id' => 'p1', 'due_on' => null]]);
            $edited = $this->workspaceState();
            $retry = $this->start('edited-replay', 'apply', $apply);
            $this->releaseBarrier(['edited-replay']);
            $this->assertSame(['status' => 'initialized', 'settled' => true, 'receipt' => $receipt, 'replayed' => true], $this->finish($retry)['result']);
            $this->assertSame($receipt, $this->recover('settled-status', $status)['result']['receipt']);
            $this->assertSame($edited, $this->workspaceState());
            $this->assertSame(2, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->sole()->version);
            $this->assertSame('FICTIONAL retained later edit', TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->sole()->data['graph']['projects'][0]['title']);
            $foreign = $status;
            $foreign['request_id'] = (string) Str::uuid();
            $this->assertSame(409, $this->recover('foreign-intent', $foreign)['http_status']);
            $this->assertSame($edited, $this->workspaceState());
            $this->assertSame($protected, $this->protectedState());
            $this->assertCount(count($this->connectionIds), array_unique($this->connectionIds));
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
                    $owned = $tenantId !== null && DB::table('tenants')->where('id', $tenantId)->where('slug', 'fictional-owner-initializer-mysql')->exists();
                    if ($owned) {
                        foreach (['team_operation_revisions', 'team_operation_records', 'team_work_locks', 'service_proposal_comments', 'service_proposal_reviews',
                            'service_proposal_versions', 'service_proposal_drafts', 'service_proposal_assets', 'tenant_user', 'team_areas', 'enterprise_client_silos'] as $table) {
                            DB::table($table)->where('tenant_id', $tenantId)->delete();
                        }
                        DB::table('tenants')->where('id', $tenantId)->where('slug', 'fictional-owner-initializer-mysql')->delete();
                        DB::table('users')->where('id', $ownerId)->where('email', 'mysql-initializer-owner@example.invalid')->delete();
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

    private function workspaceState(): array
    {
        return ['records' => DB::table('team_operation_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'audit' => DB::table('team_operation_revisions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }

    private function protectedState(): array
    {
        $state = [];
        foreach (['tenants', 'team_areas', 'users', 'tenant_user', 'team_area_members', 'team_tasks', 'team_projects', 'enterprise_client_silos',
            'service_proposal_versions', 'service_proposal_drafts', 'service_proposal_comments', 'service_proposal_reviews', 'service_proposal_assets'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $state['budget'] = DB::table('team_operation_records')->where('kind', 'like', 'ent_trial%')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $state['marker'] = file_get_contents(app(ClientSilo::class)->markerPath());

        return $state;
    }

    private function start(string $name, string $action, array $body): Process
    {
        $input = $this->fixture + ['worker' => $name, 'action' => $action, 'body' => $body];
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/reviewer-workspace-initialization-mysql-worker.php'], dirname(__DIR__, 2),
            $this->workerEnvironment(), json_encode($input, JSON_THROW_ON_ERROR), 35);
        $process->start();
        $this->processes[] = $process;
        $this->waitFor(fn () => is_file($this->runtime.'/barrier/ready-'.$name), 'Initializer worker did not reach its bounded barrier.');
        $this->connectionIds[$name] = (int) file_get_contents($this->runtime.'/barrier/ready-'.$name);

        return $process;
    }

    private function releaseBarrier(array $names): void
    {
        foreach ($names as $name) {
            file_put_contents($this->runtime.'/barrier/go-'.$name, 'go');
        }
        foreach ($names as $name) {
            $this->waitFor(fn () => is_file($this->runtime.'/barrier/attempt-'.$name), 'Initializer worker did not attempt operation.');
        }
    }

    private function assertWaiting(array $names, string $table): void
    {
        $ids = array_map(fn ($name) => $this->connectionIds[$name], $names);
        $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $this->waitFor(function () use ($ids, $table, $parent): bool {
            $rows = DB::select('SELECT DISTINCT t.PROCESSLIST_ID AS id FROM performance_schema.data_lock_waits w '
                .'JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID '
                .'JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID '
                .'JOIN performance_schema.data_locks l ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID '
                .'WHERE l.OBJECT_SCHEMA = ? AND l.OBJECT_NAME = ? AND b.PROCESSLIST_ID = ?', ['everbranch_enterprise_ci', $table, $parent]);
            $waiting = array_map(fn ($row) => (int) $row->id, $rows);

            return array_diff($ids, $waiting) === [];
        }, 'Independent initializer connections did not actually wait on the expected parent-held native row.');
    }

    private function finish(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), 'Disposable initializer failed; raw SQL/config/error output intentionally suppressed.');
        $this->assertTrue($process->getErrorOutput() === '', 'Worker emitted unexpected stderr; raw content suppressed.');

        return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    private function recover(string $name, array $body): array
    {
        $process = $this->start($name, 'status', $body);
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
