<?php

namespace Tests\Integration;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Enterprise\Provisioning\ClientSilo;
use App\Models\TeamArea;
use App\Models\Tenant;
use App\Models\User;
use App\Proposals\Draft;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\Version;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ReviewerWorkspaceFixtureHandlers;

require_once dirname(__DIR__).'/Support/reviewer-workspace-preparation-mysql-worker.php';

/** Existing disposable CI service only. Local syntax/refusal never stands for actual MySQL acceptance. */
final class ReviewerWorkspacePreparationMySqlConcurrencyTest extends TestCase
{
    use ReviewerWorkspaceFixtureHandlers;

    private array $processes = [];

    private array $connectionIds = [];

    private string $runtime;

    private array $fixture;

    private int $ownerId;

    private function workerEnvironment(array $overrides = []): array
    {
        $env = array_fill_keys(array_keys(getenv()), false);
        foreach (['PATH', 'TMPDIR', 'APP_ENV', 'ENTERPRISE_APP_ENV', 'ENTERPRISE_DISPOSABLE_MYSQL', 'ENTERPRISE_DB_DRIVER',
            'ENTERPRISE_DB_HOST', 'ENTERPRISE_DB_PORT', 'ENTERPRISE_DB_DATABASE', 'ENTERPRISE_DB_USERNAME', 'ENTERPRISE_DB_PASSWORD', 'ENTERPRISE_RUNTIME_ROOT'] as $key) {
            $env[$key] = getenv($key);
        }

        return array_replace($env, ['ENTERPRISE_DB_URL' => false, 'ENTERPRISE_DB_SOCKET' => false], $overrides);
    }

    public function test_unapproved_preparation_worker_refuses_before_runtime_framework_database_or_provider(): void
    {
        $base = ['APP_ENV' => 'testing', 'ENTERPRISE_APP_ENV' => 'testing', 'ENTERPRISE_DISPOSABLE_MYSQL' => '1',
            'ENTERPRISE_DB_DRIVER' => 'mysql', 'ENTERPRISE_DB_HOST' => '127.0.0.1', 'ENTERPRISE_DB_PORT' => '3306',
            'ENTERPRISE_DB_DATABASE' => 'everbranch_enterprise_ci', 'ENTERPRISE_DB_USERNAME' => 'FICTIONAL_NOT_USED',
            'ENTERPRISE_DB_PASSWORD' => 'FICTIONAL_NOT_USED', 'ENTERPRISE_RUNTIME_ROOT' => '/nonexistent/refuse-before-bootstrap'];
        foreach ([['ENTERPRISE_DB_HOST' => '203.0.113.10'], ['ENTERPRISE_DB_DATABASE' => 'customer_database'],
            ['ENTERPRISE_DISPOSABLE_MYSQL' => false], ['ENTERPRISE_DB_URL' => 'mysql://FICTIONAL.invalid/never'], []] as $bad) {
            $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/reviewer-workspace-preparation-mysql-worker.php'], dirname(__DIR__, 2),
                $this->workerEnvironment(array_replace($base, $bad)), '{}', 5);
            $process->run();
            $this->assertSame(2, $process->getExitCode());
            $this->assertTrue($process->getOutput() === '{"error":"disposable_preparation_worker_refused"}', 'Worker refused with unexpected output, raw content suppressed.');
            $this->assertTrue($process->getErrorOutput() === '', 'Worker emitted unexpected stderr, raw content suppressed.');
        }
    }

    public function test_mysql_preparation_and_publication_are_once_only_with_current_owner_membership_and_source_locking_reads(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || getenv('ENTERPRISE_DB_DRIVER') !== 'mysql') {
            $this->markTestSkipped('NOT RUN: existing explicit disposable loopback MySQL8.4 required; syntax/refusal is not concurrency acceptance.');
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
                'team_operation_revisions', 'team_work_locks', 'service_proposal_versions', 'service_proposal_drafts', 'service_proposal_comments', 'service_proposal_assets'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), 'Require clean CI fixture; never clear foreign state.');
            }
            $manifest = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
                'storage_id' => config('enterprise_client.storage_id'), 'environment' => 'testing',
                'tenant' => ['business_id' => '92000000-0000-4000-8000-000000000021', 'slug' => 'fictional-owner-preparation-mysql', 'name' => 'FICTIONAL preparation MySQL owner'],
                'branding' => ['organization_name' => 'FICTIONAL preparation MySQL owner', 'accent' => '#123456'],
                'departments' => [['id' => '93000000-0000-4000-8000-000000000021', 'key' => 'administration', 'name' => 'Fictional administration', 'visibility' => 'department_only']],
                'locations' => [], 'workflow_defaults' => [], 'role_defaults' => []];
            $provisioner = app(ClientProvisioner::class);
            $installed = $provisioner->apply($manifest, $provisioner->plan($manifest)['plan_hash']);
            $tenantId = (int) $installed['tenant_id'];
            \ReviewerWorkspaceMySqlWorker::bindOwner();
            $tenant = Tenant::findOrFail($tenantId);
            $owner = User::create(['name' => 'FICTIONAL preparation owner', 'email' => 'mysql-preparation-owner@example.invalid',
                'password' => Hash::make('FICTIONAL_ONLY'), 'is_active' => true]);
            $ownerId = (int) $owner->id;
            $owner->forceFill(['email_verified_at' => now()])->save();
            $tenant->users()->attach($ownerId, ['role' => 'owner', 'membership_active' => true]);
            $marker = ['synthetic_fixture' => true, 'proposal_review_workspace_anchor' => true, 'reviewed_fixture' => 'mysql-only'];
            TeamArea::create(['id' => config('service_proposals.reviewer_workspace.anchor_area_id'), 'tenant_id' => $tenantId,
                'slug' => 'fictional-mysql-workspace', 'name' => 'FICTIONAL workspace storage', 'profile' => 'enterprise_shared', 'version' => 1, 'settings' => $marker]);
            $service = app(ProposalService::class);
            $source = $service->leesMcRaeDraft($tenant, $ownerId)->refresh();
            $old = $service->publish($tenant, $ownerId, $source->id, (int) $source->revision, 'FICTIONAL_OLD_PASSWORD', 7, (string) Str::uuid(), 'reviewer')['version'];
            $service->comment($old, $old->payload['reviewer_names'][0], 'overview', 'FICTIONAL preserved old feedback', (string) Str::uuid());
            $policy = array_values(config('service_proposals.reviewer_workspace.policies'))[0];
            $approval = ['schema_version' => 1, 'data_basis' => 'reviewed_current_edits', 'source_draft_id' => $source->id,
                'source_draft_revision' => (int) $source->revision, 'source_draft_sha256' => $service->hash($source->payload), 'prepared_draft_id' => (string) Str::uuid(),
                'predecessor_version_id' => $old->id, 'predecessor_version_hash' => $old->content_hash,
                'seed_sha256' => str_repeat('d', 64), 'import_evidence_sha256' => str_repeat('e', 64),
                'workspace_descriptor' => ['schema_version' => 1, 'workspace_id' => (string) Str::uuid(), 'seed_sha256' => str_repeat('d', 64),
                    'policy_sha256' => ReviewerWorkspaceSchema::digest($policy), 'expires_at' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s\Z')],
                'collaboration_descriptor' => null, 'build_id' => $old->payload['campus_review']['build_id'], 'source_hash' => $old->payload['campus_review']['source_hash'],
                'expires_at' => now()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z')];
            $this->fixture = ['approval' => $approval];
            $this->ownerId = $ownerId;
            \ReviewerWorkspacePreparationMySqlWorker::configureApproval($approval);
            $sessions = [\ReviewerWorkspacePreparationMySqlWorker::session($owner), \ReviewerWorkspacePreparationMySqlWorker::session($owner), \ReviewerWorkspacePreparationMySqlWorker::session($owner)];
            $this->assertCount(3, array_unique(array_column($sessions, 'id')));
            $this->assertCount(3, array_unique(array_column($sessions, 'cookie')));
            $intent = ['approval_sha256' => ReviewerWorkspaceSchema::digest($approval), 'request_id' => (string) Str::uuid(), 'import_evidence_sha256' => $approval['import_evidence_sha256']];
            $status = array_intersect_key($intent, array_flip(['approval_sha256', 'request_id']));
            $previewA = $this->preview($sessions[0], $intent);
            $protected = $this->protectedState();
            $absent = $this->preparedState();
            $this->assertSame(0, DB::table('team_operation_records')->where('kind', 'like', 'ent_trial%')->count());
            $this->assertFileExists(app(ClientSilo::class)->markerPath());

            DB::beginTransaction();
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $revoked = $this->start('owner-waiter', 'apply', $intent + ['preview' => $previewA], $sessions[0]);
            $this->releaseBarrier(['owner-waiter']);
            $this->assertWaiting(['owner-waiter'], 'users');
            DB::table('users')->where('id', $ownerId)->update(['is_active' => false]);
            DB::commit();
            $this->assertSame(403, $this->finish($revoked)['http_status']);
            $this->assertSame($absent, $this->preparedState());
            DB::table('users')->where('id', $ownerId)->update(['is_active' => true]);
            $this->assertSame($protected, $this->protectedState());

            // Distinct sessions each mint consent for the SAME intent; session blocking stays enabled.
            $applyA = $intent + ['preview' => $this->preview($sessions[0], $intent)];
            $applyB = $intent + ['preview' => $this->preview($sessions[1], $intent)];
            $this->assertNotSame($applyA['preview']['claims']['session_sha256'], $applyB['preview']['claims']['session_sha256']);
            $one = $this->start('prepare-a', 'apply', $applyA, $sessions[0]);
            $two = $this->start('prepare-b', 'apply', $applyB, $sessions[1]);
            // Both HTTP workers are queued at their explicit test barrier. Absence is never settled.
            $pending = \ReviewerWorkspacePreparationMySqlWorker::http($sessions[2], 'status', $status, $approval);
            $this->assertSame(200, $pending['http_status']);
            $this->assertSame(['status' => 'not_observed', 'settled' => false], $pending['result']);
            DB::beginTransaction();
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $this->releaseBarrier(['prepare-a', 'prepare-b']);
            $this->assertWaiting(['prepare-a', 'prepare-b'], 'users');
            DB::commit();
            $prepared = [$this->finish($one), $this->finish($two)];
            $this->assertSame([200, 200], array_column($prepared, 'http_status'));
            $this->assertNotSame($prepared[0]['session_sha256'], $prepared[1]['session_sha256']);
            $this->assertEqualsCanonicalizing([false, true], array_column(array_column($prepared, 'result'), 'replayed'));
            $receipt = $prepared[0]['result']['receipt'];
            $this->assertSame($receipt, $prepared[1]['result']['receipt']);
            $this->assertSame(1, Draft::whereKey($approval['prepared_draft_id'])->count());
            $this->assertSame(0, Version::where('draft_id', $approval['prepared_draft_id'])->count());
            $this->assertSame($protected, $this->protectedState());
            $clone = $this->preparedState();
            $foreignIntent = $status;
            $foreignIntent['request_id'] = (string) Str::uuid();
            $foreignStatus = \ReviewerWorkspacePreparationMySqlWorker::http($sessions[2], 'status', $foreignIntent, $approval);
            $this->assertSame(409, $foreignStatus['http_status']);
            $this->assertFalse($foreignStatus['result']['settled']);
            $this->assertSame($clone, $this->preparedState());

            $publish = ['revision' => 1, 'password' => 'FICTIONAL_NEW_PASSWORD', 'days' => 7, 'publish_key' => (string) Str::uuid(), 'confirm' => '1', 'audience' => 'reviewer'];
            DB::beginTransaction();
            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $ownerId)->lockForUpdate()->first();
            $membership = $this->start('membership-waiter', 'publish', $publish, $sessions[0]);
            $this->releaseBarrier(['membership-waiter']);
            $this->assertWaiting(['membership-waiter'], 'tenant_user');
            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $ownerId)->update(['membership_active' => false]);
            DB::commit();
            $this->assertSame(403, $this->finish($membership)['http_status']);
            $this->assertSame($clone, $this->preparedState());
            DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $ownerId)->update(['membership_active' => true]);
            $this->assertSame($protected, $this->protectedState());

            $sourceRaw = (array) DB::table('service_proposal_drafts')->where('id', $source->id)->first();
            DB::beginTransaction();
            DB::table('service_proposal_drafts')->where('id', $source->id)->lockForUpdate()->first();
            $changed = $this->start('source-waiter', 'publish', $publish, $sessions[1]);
            $this->releaseBarrier(['source-waiter']);
            $this->assertWaiting(['source-waiter'], 'service_proposal_drafts');
            DB::table('service_proposal_drafts')->where('id', $source->id)->update(['revision' => $sourceRaw['revision'] + 1]);
            DB::commit();
            $this->assertSame(409, $this->finish($changed)['http_status']);
            $this->assertSame($clone, $this->preparedState());
            DB::table('service_proposal_drafts')->where('id', $source->id)->update($sourceRaw);
            $this->assertSame($protected, $this->protectedState(), 'Restore only the exact synthetic source row to isolate the next case.');

            DB::beginTransaction();
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $first = $this->start('publish-a', 'publish', $publish, $sessions[0]);
            $second = $this->start('publish-b', 'publish', $publish, $sessions[1]);
            $this->releaseBarrier(['publish-a', 'publish-b']);
            $this->assertWaiting(['publish-a', 'publish-b'], 'users');
            DB::commit();
            $out = [$this->finish($first), $this->finish($second)];
            $this->assertSame([302, 302], array_column($out, 'http_status'));
            $this->assertSame([true, true], array_column($out, 'edit_redirect_verified'));
            $this->assertNotSame($out[0]['session_sha256'], $out[1]['session_sha256']);
            $this->assertSame($out[0]['publication'], $out[1]['publication']);
            $this->assertSame(1, Version::where('draft_id', $approval['prepared_draft_id'])->count());
            $this->assertSame($protected, $this->protectedState());
            $committed = $this->preparedState();
            DB::table('service_proposal_drafts')->where('id', $source->id)->update(['revision' => $sourceRaw['revision'] + 1]);
            $drifted = $this->protectedState();
            $retry = $this->start('published-replay', 'publish', $publish, $sessions[2]);
            $this->releaseBarrier(['published-replay']);
            $this->assertSame($out[0]['publication'], $this->finish($retry)['publication']);
            $different = $publish;
            $different['publish_key'] = (string) Str::uuid();
            $foreign = $this->start('foreign-publication', 'publish', $different, $sessions[0]);
            $this->releaseBarrier(['foreign-publication']);
            $this->assertSame(409, $this->finish($foreign)['http_status']);
            $observed = \ReviewerWorkspacePreparationMySqlWorker::http($sessions[2], 'status', $status, $approval);
            $this->assertSame(200, $observed['http_status']);
            $this->assertSame($receipt, $observed['result']['receipt']);
            $this->assertFalse($observed['result']['publishable']);
            $this->assertSame($committed, $this->preparedState());
            $this->assertSame($drifted, $this->protectedState());
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
                    $owned = $tenantId !== null && DB::table('tenants')->where('id', $tenantId)->where('slug', 'fictional-owner-preparation-mysql')->exists();
                    if ($owned) {
                        foreach (['team_operation_revisions', 'team_operation_records', 'team_work_locks', 'service_proposal_comments', 'service_proposal_reviews',
                            'service_proposal_versions', 'service_proposal_drafts', 'service_proposal_assets', 'tenant_user', 'team_areas', 'enterprise_client_silos'] as $table) {
                            DB::table($table)->where('tenant_id', $tenantId)->delete();
                        }
                        DB::table('tenants')->where('id', $tenantId)->where('slug', 'fictional-owner-preparation-mysql')->delete();
                        DB::table('users')->where('id', $ownerId)->where('email', 'mysql-preparation-owner@example.invalid')->delete();
                    }
                    DB::disconnect('enterprise');
                    $app->flush();
                }
            } finally {
                putenv($previous === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$previous);
                (new Filesystem)->deleteDirectory($this->runtime);
                $this->assertDirectoryDoesNotExist($this->runtime);
                foreach ($this->processes as $process) {
                    $this->assertFalse($process->isRunning(), 'Owned fixture child remained running.');
                }
            }
        }
    }

    private function preparedState(): array
    {
        $id = $this->fixture['approval']['prepared_draft_id'];

        return ['draft' => DB::table('service_proposal_drafts')->where('id', $id)->get()->toJson(),
            'versions' => DB::table('service_proposal_versions')->where('draft_id', $id)->orderBy('id')->get()->toJson()];
    }

    private function protectedState(): array
    {
        $state = [];
        foreach (['tenants', 'team_areas', 'users', 'tenant_user', 'team_area_members', 'team_tasks', 'team_projects', 'enterprise_client_silos',
            'team_operation_records', 'team_operation_revisions', 'team_work_locks', 'service_proposal_comments', 'service_proposal_reviews', 'service_proposal_assets'] as $table) {
            $state[$table] = DB::table($table)->orderBy($table === 'team_work_locks' ? 'tenant_id' : 'id')->get()->toJson();
        }
        $id = $this->fixture['approval']['prepared_draft_id'];
        $state['drafts'] = DB::table('service_proposal_drafts')->where('id', '<>', $id)->orderBy('id')->get()->toJson();
        $state['versions'] = DB::table('service_proposal_versions')->where('draft_id', '<>', $id)->orderBy('id')->get()->toJson();
        $state['marker'] = file_get_contents(app(ClientSilo::class)->markerPath());

        return $state;
    }

    private function preview(array $session, array $body): array
    {
        $out = \ReviewerWorkspacePreparationMySqlWorker::http($session, 'preview', $body, $this->fixture['approval']);
        $this->assertSame(200, $out['http_status']);
        $this->assertSame('preview', $out['result']['status']);

        return $out['result']['preview'];
    }

    private function start(string $name, string $action, array $body, array $session): Process
    {
        $input = $this->fixture + ['worker' => $name, 'action' => $action, 'session' => $session, 'body' => $body];
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/reviewer-workspace-preparation-mysql-worker.php'], dirname(__DIR__, 2),
            $this->workerEnvironment(), json_encode($input, JSON_THROW_ON_ERROR), 35);
        $process->start();
        $this->processes[] = $process;
        $this->waitFor(fn () => is_file($this->runtime.'/barrier/ready-'.$name), 'Preparation worker did not reach bounded barrier.');
        $this->connectionIds[$name] = (int) file_get_contents($this->runtime.'/barrier/ready-'.$name);

        return $process;
    }

    private function releaseBarrier(array $names): void
    {
        foreach ($names as $name) {
            file_put_contents($this->runtime.'/barrier/go-'.$name, 'go');
        }
        foreach ($names as $name) {
            $this->waitFor(fn () => is_file($this->runtime.'/barrier/attempt-'.$name), 'Preparation worker did not attempt operation.');
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

            return array_diff($ids, array_map(fn ($row) => (int) $row->id, $rows)) === [];
        }, 'Independent workers did not actually wait on the expected parent-held native row.');
    }

    private function finish(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), 'Disposable preparation worker failed; raw SQL/config/error output suppressed.');
        $this->assertTrue($process->getErrorOutput() === '', 'Unexpected worker stderr, raw content suppressed.');

        $out = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame($this->ownerId, $out['owner_id']);

        return $out;
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
