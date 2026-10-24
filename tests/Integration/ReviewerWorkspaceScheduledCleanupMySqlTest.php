<?php

namespace Tests\Integration;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Enterprise\Provisioning\ClientSilo;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Proposals\CampusReviewManifest;
use App\Proposals\ProposalService;
use App\Proposals\RecipientController;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceCollaborationStore;
use App\Proposals\ReviewerWorkspaceDiscussion;
use App\Proposals\ReviewerWorkspaceScheduledAuthority;
use App\Proposals\ReviewerWorkspaceScheduledCleanup;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\ReviewerWorkspaceStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Unit\Proposals\ReviewerWorkspaceScheduledCleanupConfigurationTest;

require_once dirname(__DIR__).'/Support/reviewer-workspace-scheduled-mysql-worker.php';
require_once dirname(__DIR__).'/Support/reviewer-workspace-initialization-mysql-worker.php';

/** Mutating scheduled paths ONLY on the existing strict disposable loopback MySQL8.4. No fake lock/principal. */
final class ReviewerWorkspaceScheduledCleanupMySqlTest extends TestCase
{
    private string $runtime;

    private array $processes = [];

    private array $connections = [];

    private function environment(array $overrides = []): array
    {
        $env = array_fill_keys(array_keys(getenv()), false);
        foreach (['PATH', 'TMPDIR', 'APP_ENV', 'ENTERPRISE_APP_ENV', 'ENTERPRISE_DISPOSABLE_MYSQL', 'ENTERPRISE_DB_DRIVER',
            'ENTERPRISE_DB_HOST', 'ENTERPRISE_DB_PORT', 'ENTERPRISE_DB_DATABASE', 'ENTERPRISE_DB_USERNAME', 'ENTERPRISE_DB_PASSWORD', 'ENTERPRISE_RUNTIME_ROOT'] as $key) {
            $env[$key] = getenv($key);
        }

        return array_replace($env, ['ENTERPRISE_DB_URL' => false, 'ENTERPRISE_DB_SOCKET' => false], $overrides);
    }

    public function test_unapproved_scheduled_worker_refuses_before_runtime_framework_database_or_provider(): void
    {
        $base = ['APP_ENV' => 'testing', 'ENTERPRISE_APP_ENV' => 'testing', 'ENTERPRISE_DISPOSABLE_MYSQL' => '1',
            'ENTERPRISE_DB_DRIVER' => 'mysql', 'ENTERPRISE_DB_HOST' => '127.0.0.1', 'ENTERPRISE_DB_PORT' => '3306',
            'ENTERPRISE_DB_DATABASE' => 'everbranch_enterprise_ci', 'ENTERPRISE_DB_USERNAME' => 'FICTIONAL_NOT_USED',
            'ENTERPRISE_DB_PASSWORD' => 'FICTIONAL_NOT_USED', 'ENTERPRISE_RUNTIME_ROOT' => '/nonexistent/refuse-before-bootstrap'];
        foreach ([['ENTERPRISE_DB_HOST' => '203.0.113.10'], ['ENTERPRISE_DB_DATABASE' => 'customer_database'],
            ['ENTERPRISE_DISPOSABLE_MYSQL' => false], ['ENTERPRISE_DB_URL' => 'mysql://FICTIONAL.invalid/never'], []] as $bad) {
            $p = new Process([PHP_BINARY, dirname(__DIR__).'/Support/reviewer-workspace-scheduled-mysql-worker.php'], dirname(__DIR__, 2),
                $this->environment(array_replace($base, $bad)), '{}', 5);
            $p->run();
            $this->assertSame(2, $p->getExitCode());
            $this->assertTrue($p->getOutput() === '{"error":"disposable_scheduled_worker_refused"}', 'Raw refusal output suppressed.');
            $this->assertTrue($p->getErrorOutput() === '', 'Raw stderr suppressed.');
        }
    }

    public function test_mysql_exclusion_rotation_zero_cursor_wrap_expired_only_partial_revocation_and_final_expiry(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || getenv('ENTERPRISE_DB_DRIVER') !== 'mysql') {
            $this->markTestSkipped('NOT RUN: approved existing disposable MySQL8.4 required; local pure/refusal tests are not scheduled mutation acceptance.');
        }
        \ReviewerWorkspaceMySqlWorker::environment();
        $previous = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/reviewer-workspace-mysql-'.Str::uuid();
        mkdir($this->runtime, 0700);
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs',
            'storage/app/private', 'storage/app/team-private', 'bootstrap/cache', 'barrier'] as $dir) {
            mkdir($this->runtime.'/'.$dir, 0700, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        chmod($this->runtime.'/.env', 0600);
        putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        $app = null;
        $tenantId = $ownerId = null;
        try {
            $app = \ReviewerWorkspaceMySqlWorker::boot();
            $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS v')->v);
            $this->assertSame(0, $app->make(Kernel::class)->call('migrate', ['--force' => true]));
            foreach (['tenants', 'users', 'tenant_user', 'team_area_members', 'team_operation_records', 'team_operation_revisions',
                'team_work_locks', 'enterprise_client_silos', 'service_proposal_versions', 'service_proposal_comments'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), 'Require clean owned CI fixture; never clear foreign state.');
            }
            $m = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
                'storage_id' => config('enterprise_client.storage_id'), 'environment' => 'testing',
                'tenant' => ['business_id' => '92000000-0000-4000-8000-000000000031', 'slug' => 'fictional-scheduled-cleanup-mysql', 'name' => 'FICTIONAL scheduled cleanup'],
                'branding' => ['organization_name' => 'FICTIONAL cleanup', 'accent' => '#123456'],
                'departments' => [['id' => '93000000-0000-4000-8000-000000000031', 'key' => 'administration', 'name' => 'Fictional administration', 'visibility' => 'department_only']],
                'locations' => [], 'workflow_defaults' => [], 'role_defaults' => []];
            $provisioner = app(ClientProvisioner::class);
            $installed = $provisioner->apply($m, $provisioner->plan($m)['plan_hash']);
            $tenantId = (int) $installed['tenant_id'];
            \ReviewerWorkspaceMySqlWorker::bindOwner();
            $tenant = Tenant::findOrFail($tenantId);
            $owner = User::create(['name' => 'FICTIONAL schedule owner', 'email' => 'schedule-owner@example.invalid', 'password' => Hash::make('FICTIONAL_ONLY'), 'is_active' => true]);
            $ownerId = (int) $owner->id;
            $owner->forceFill(['email_verified_at' => now()])->save();
            $tenant->users()->attach($ownerId, ['role' => 'owner', 'membership_active' => true]);
            $settings = ['synthetic_fixture' => true, 'proposal_review_workspace_anchor' => true, 'reviewed_fixture' => 'mysql-only'];
            $anchor = TeamArea::create(['id' => config('service_proposals.reviewer_workspace.anchor_area_id'), 'tenant_id' => $tenantId,
                'slug' => 'fictional-schedule-storage', 'name' => 'FICTIONAL schedule storage', 'profile' => 'enterprise_shared', 'version' => 1, 'settings' => $settings]);
            $graph = (new class('fixtureGraph') extends \Tests\Support\ReviewerWorkspaceFixture
            {
                public function fixtureGraph(): void {}

                public function graph(): array
                {
                    return $this->graphSeed();
                }
            })->graph();
            $graph = app(ReviewerWorkspaceSchema::class)->graph($graph, true);
            $cp = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false, 'write_personas' => ['demo_manager']];
            $ch = ReviewerWorkspaceSchema::digest($cp);
            config(['service_proposals.reviewer_workspace.collaboration' => ['enabled' => true, 'comments_write_enabled' => true, 'policies' => [$ch => $cp]]]);
            $scope = [];
            for ($i = 1; $i <= 2; $i++) {
                $service = app(ProposalService::class);
                $draft = $service->leesMcRaeDraft($tenant, $ownerId)->refresh();
                $payload = $draft->payload;
                $wid = '14000000-0000-4000-8000-00000000000'.$i;
                $payload['review_workspace'] = ['schema_version' => 1, 'workspace_id' => $wid, 'seed_sha256' => ReviewerWorkspaceSchema::digest($graph),
                    'policy_sha256' => array_key_first(config('service_proposals.reviewer_workspace.policies')), 'expires_at' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z')];
                $payload['review_workspace_collaboration'] = ['schema_version' => 1, 'policy_sha256' => $ch, 'expires_at' => now()->addDays(4)->utc()->format('Y-m-d\TH:i:s\Z')];
                $draft->update(['payload' => $payload]);
                $out = $service->publish($tenant, $ownerId, $draft->id, (int) $draft->revision, 'FICTIONAL_CLEANUP_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
                $v = $out['version'];
                $service->comment($v, $v->payload['reviewer_names'][0], 'overview', 'FICTIONAL preserved original feedback', (string) Str::uuid());
                $request = \ReviewerWorkspaceInitializationMySqlWorker::ownerRequest([], Str::random(40), $ownerId, $tenant->slug);
                app(ReviewerWorkspace::class)->initialize($request, $v->id, $graph, ['request_id' => (string) Str::uuid(), 'descriptor_sha256' => ReviewerWorkspaceSchema::digest($payload['review_workspace'])]);
                $reader = \ReviewerWorkspaceMySqlWorker::request([], Str::random(40));
                $reader->merge(['password' => 'FICTIONAL_CLEANUP_PASSWORD']);
                app(RecipientController::class)->unlock($reader, $out['token']);
                $reader->session()->put('service_proposal_name.'.$v->id, $v->payload['reviewer_names'][0]);
                app(ReviewerWorkspaceDiscussion::class)->comment($reader, $out['token'], ['binding' => ['version_hash' => $v->content_hash,
                    'build_id' => $v->payload['campus_review']['build_id'], 'source_hash' => $v->payload['campus_review']['source_hash']],
                    'selection' => ['persona' => 'demo_manager', 'area_ids' => [$graph['areas'][0]['id']], 'person_id' => null],
                    'target' => ['kind' => 'project', 'id' => $graph['projects'][0]['id']], 'request_id' => (string) Str::uuid(),
                    'expected_target_version' => 1, 'expected_thread_revision' => 0, 'expected_reviewer_name' => $v->payload['reviewer_names'][0], 'body' => 'FICTIONAL shared thread']);
                $scope[$wid] = $v;
            }
            $context = app(ReviewerWorkspaceStore::class)->context(false);
            $pins = ReviewerWorkspaceScheduledCleanupConfigurationTest::pins();
            $actual = DB::selectOne('SELECT DATABASE() AS db, @@server_uuid AS uuid');
            $editions = array_map(fn ($wid, $v) => ['version_id' => $v->id, 'version_hash' => $v->content_hash, 'workspace_id' => $wid], array_keys($scope), array_values($scope));
            usort($editions, fn ($a, $b) => strcmp($a['version_id'], $b['version_id']));
            $pins = array_replace($pins, ['runtime_root_sha256' => hash('sha256', realpath($this->runtime)), 'process_uid' => posix_geteuid(), 'process_gid' => posix_getegid(),
                'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'), 'storage_id' => config('enterprise_client.storage_id'),
                'tenant_id' => $tenantId, 'anchor_area_id' => $anchor->id, 'anchor_marker_sha256' => ReviewerWorkspaceSchema::digest($settings), 'context_hash' => $context['context_hash'],
                'database' => $actual->db, 'server_uuid' => strtolower($actual->uuid), 'build_id' => config('service_proposals.campus_review.build_id'),
                'source_hash' => app(CampusReviewManifest::class)->sourceFingerprint(), 'composer_sha256' => hash_file('sha256', base_path('composer.lock')),
                'authorizing_owner_id' => $ownerId, 'valid_from' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'),
                'expires_at' => now()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z'), 'limit' => 1, 'max_run_seconds' => 30, 'editions' => $editions]);
            $digest = $this->install($pins);
            $protected = $this->protected();
            $before = $this->records();
            $authority = ReviewerWorkspaceScheduledAuthority::admit();
            $key = $authority->lockName();
            $this->assertSame(1, (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS v', [$key])->v);
            $this->assertSame('busy', $this->execute('busy-old', $digest)['result']['status']);
            $pins['max_run_seconds'] = 40;
            $newDigest = $this->install($pins);
            $this->assertNotSame($digest, $newDigest);
            $this->assertSame('busy', $this->execute('busy-rotated', $newDigest)['result']['status']);
            $this->assertSame($before, $this->records());
            $this->assertSame(1, (int) DB::selectOne('SELECT RELEASE_LOCK(?) AS v', [$key])->v);
            $runner = app(ReviewerWorkspaceScheduledCleanup::class);
            $one = $runner->run();
            $this->assertSame('completed', $one['status']);
            $this->assertSame(0, $one['pages']['graph']['result']['erased']);
            $this->assertNotNull($one['pages']['graph']['result']['next_cursor']);
            $this->assertSame(0, $one['pages']['comments']['result']['erased']);
            $this->assertNotNull($one['pages']['comments']['result']['next_cursor']);
            $two = $runner->run();
            $this->assertSame('completed', $two['status']);
            $this->assertNull($two['pages']['graph']['result']['next_cursor']);
            $this->assertNull($two['pages']['comments']['result']['next_cursor']);
            $this->assertSame(2, TeamOperationRecord::where('kind', ReviewerWorkspaceScheduledCleanup::CURSOR)->count());
            $this->assertSame($protected, $this->protected());
            $originalRevisions = DB::table('team_operation_revisions')->pluck('id')->all();
            $first = array_values($scope)[0];
            $first->update(['revoked_at' => now()]);
            $protected = $this->protected();
            $this->assertSame('completed', $runner->run()['status']);
            $this->assertSame('completed', $runner->run()['status']);
            $this->assertSame('expired', TeamOperationRecord::findOrFail(array_key_first($scope))->status);
            $this->assertNull(TeamOperationRecord::findOrFail(array_key_first($scope))->data['graph']);
            $thread = TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->where('title', 'Private workspace discussion:'.array_key_first($scope))->sole();
            $this->assertSame('expired', $thread->status);
            $this->assertSame(['comments' => []], $thread->data['payload']);
            $this->assertSame($protected, $this->protected());
            $newAudit = DB::table('team_operation_revisions')->whereNotIn('id', $originalRevisions)->get();
            $this->assertCount(2, $newAudit);
            foreach ($newAudit as $row) {
                $data = json_decode(Crypt::decryptString($row->payload), true);
                $this->assertSame('approved_schedule', $data['actor_type']);
                $this->assertSame($ownerId, $data['authorizing_owner_id']);
                $this->assertSame($newDigest, $data['policy_sha256']);
            }
            $stable = $this->records();
            foreach (['users', 'tenant_user', 'team_areas', 'enterprise_client_silos'] as $table) {
                DB::beginTransaction();
                $query = match ($table) {
                    'users' => DB::table($table)->where('id', $ownerId),
                    'tenant_user' => DB::table($table)->where('tenant_id', $tenantId)->where('user_id', $ownerId),
                    'team_areas' => DB::table($table)->where('id', $anchor->id),
                    'enterprise_client_silos' => DB::table($table)->where('id', 1),
                };
                $original = (array) $query->lockForUpdate()->first();
                $worker = 'revoked-'.str_replace('_', '-', $table);
                $p = $this->start($worker, $newDigest);
                $this->go([$worker]);
                $this->waiting($worker, $table);
                $query->update(match ($table) {
                    'users' => ['is_active' => false],
                    'tenant_user' => ['membership_active' => false],
                    'team_areas' => ['profile' => 'ordinary'],
                    'enterprise_client_silos' => ['plan_hash' => str_repeat('0', 64)],
                });
                DB::commit();
                $this->assertFalse($this->finish($p)['result']['settled']);
                $this->assertSame($stable, $this->records());
                $query->update($original);
                $this->assertSame($protected, $this->protected());
            }
            // Foreign binding: graph page commits zero-erasure cursor, comments page refuses without advancing its cursor.
            $pins['previous_policy_sha256'] = ['graph' => $newDigest, 'comments' => $newDigest];
            $pins['max_run_seconds'] = 45;
            $partialDigest = $this->install($pins);
            $bad = TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->orderBy('id')->firstOrFail();
            $raw = $bad->getRawOriginal();
            $data = $bad->data;
            $data['binding']['context_hash'] = str_repeat('0', 64);
            $bad->data = $data;
            $bad->save();
            $commentsCursor = TeamOperationRecord::where('kind', ReviewerWorkspaceScheduledCleanup::CURSOR)->where('title', 'Private scheduled cleanup:comments')->sole()->getRawOriginal();
            $partial = $runner->run();
            $this->assertSame('partial_or_unknown', $partial['status']);
            $this->assertTrue($partial['pages']['graph']['settled']);
            $this->assertFalse($partial['pages']['comments']['settled']);
            $this->assertSame($commentsCursor, TeamOperationRecord::where('kind', ReviewerWorkspaceScheduledCleanup::CURSOR)->where('title', 'Private scheduled cleanup:comments')->sole()->getRawOriginal());
            DB::table('team_operation_records')->where('id', $bad->id)->update($raw);
            // A later rotation must bind each actually observed cursor, not borrow the sibling's predecessor.
            $graphCursor = TeamOperationRecord::where('kind', ReviewerWorkspaceScheduledCleanup::CURSOR)->where('title', 'Private scheduled cleanup:graph')->sole();
            $this->assertSame($partialDigest, $graphCursor->data['policy_sha256']);
            $this->assertSame($newDigest, TeamOperationRecord::where('kind', ReviewerWorkspaceScheduledCleanup::CURSOR)->where('title', 'Private scheduled cleanup:comments')->sole()->data['policy_sha256']);
            $pins['previous_policy_sha256'] = ['graph' => $partialDigest, 'comments' => $newDigest];
            $pins['max_run_seconds'] = 50;
            $recoveredDigest = $this->install($pins);
            $this->assertSame('completed', $runner->run()['status']);
            foreach (TeamOperationRecord::where('kind', ReviewerWorkspaceScheduledCleanup::CURSOR)->get() as $cursor) {
                $this->assertSame($recoveredDigest, $cursor->data['policy_sha256']);
            }
            // Complete one zero-erasure page so the next graph page contains the second workspace.
            $this->assertSame('completed', $runner->run()['status']);
            // Next graph is the second workspace. Late policy expiry after its scrub/cursor write rolls the WHOLE page back.
            array_values($scope)[1]->update(['revoked_at' => now()]);
            $before = $this->records();
            $event = 'eloquent.updated: '.TeamOperationRecord::class;
            Event::listen($event, function (TeamOperationRecord $r) use ($pins): void {
                if ($r->kind === ReviewerWorkspaceScheduledCleanup::CURSOR) {
                    \Carbon\Carbon::setTestNow($pins['expires_at']);
                    \Carbon\CarbonImmutable::setTestNow($pins['expires_at']);
                }
            });
            try {
                $late = $runner->run();
                $this->assertFalse($late['settled']);
            } finally {
                Event::forget($event);
                \Carbon\Carbon::setTestNow();
                \Carbon\CarbonImmutable::setTestNow();
            }
            $this->assertSame($before, $this->records());
            $this->assertSame(0, DB::table('team_operation_records')->where('kind', 'like', 'ent_trial%')->count());
            Http::assertNothingSent();
        } finally {
            foreach ($this->processes as $p) {
                if ($p->isRunning()) {
                    $p->stop(1);
                }
            }
            try {
                if ($app !== null) {
                    while (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    $owned = $tenantId !== null && DB::table('tenants')->where('id', $tenantId)->where('slug', 'fictional-scheduled-cleanup-mysql')->exists();
                    if ($owned) {
                        foreach (['team_operation_revisions', 'team_operation_records', 'team_work_locks', 'service_proposal_comments', 'service_proposal_reviews',
                            'service_proposal_versions', 'service_proposal_drafts', 'service_proposal_assets', 'tenant_user', 'team_areas', 'enterprise_client_silos'] as $table) {
                            DB::table($table)->where('tenant_id', $tenantId)->delete();
                        }
                        DB::table('tenants')->where('id', $tenantId)->where('slug', 'fictional-scheduled-cleanup-mysql')->delete();
                        DB::table('users')->where('id', $ownerId)->where('email', 'schedule-owner@example.invalid')->delete();
                    }
                    DB::disconnect('enterprise');
                    $app->flush();
                }
            } finally {
                putenv($previous === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$previous);
                (new Filesystem)->deleteDirectory($this->runtime);
                $this->assertDirectoryDoesNotExist($this->runtime);
                foreach ($this->processes as $p) {
                    $this->assertFalse($p->isRunning());
                }
            }
        }
    }

    private function install(array $pins): string
    {
        $bytes = json_encode($pins, JSON_THROW_ON_ERROR);
        file_put_contents($this->runtime.'/reviewer-workspace-cleanup.json', $bytes);
        chmod($this->runtime.'/reviewer-workspace-cleanup.json', 0600);
        $digest = hash('sha256', $bytes);
        \ReviewerWorkspaceScheduledMySqlWorker::configure($digest);

        return $digest;
    }

    private function records(): array
    {
        return ['rows' => DB::table('team_operation_records')->orderBy('id')->get()->toJson(), 'audit' => DB::table('team_operation_revisions')->orderBy('id')->get()->toJson()];
    }

    private function protected(): array
    {
        $out = [];
        foreach (DB::connection()->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, ['team_operation_records', 'team_operation_revisions'], true)) {
                continue;
            }
            $rows = [];
            foreach (DB::table($table)->get() as $row) {
                $data = (array) $row;
                ksort($data, SORT_STRING);
                $rows[] = json_encode($data, JSON_THROW_ON_ERROR);
            }
            sort($rows, SORT_STRING);
            $out[$table] = $rows;
        }
        ksort($out, SORT_STRING);
        $out['receipts'] = DB::table('team_operation_records')->whereNotIn('kind', [ReviewerWorkspaceStore::KIND, ReviewerWorkspaceCollaborationStore::THREAD, ReviewerWorkspaceScheduledCleanup::CURSOR])->orderBy('id')->get()->toJson();
        $out['marker'] = file_get_contents(app(ClientSilo::class)->markerPath());

        return $out;
    }

    private function start(string $name, string $digest): Process
    {
        $p = new Process([PHP_BINARY, dirname(__DIR__).'/Support/reviewer-workspace-scheduled-mysql-worker.php'], dirname(__DIR__, 2), $this->environment(), json_encode(['worker' => $name, 'digest' => $digest]), 35);
        $p->start();
        $this->processes[] = $p;
        $this->wait(fn () => is_file($this->runtime.'/barrier/ready-'.$name));
        $this->connections[$name] = (int) file_get_contents($this->runtime.'/barrier/ready-'.$name);

        return $p;
    }

    private function go(array $names): void
    {
        foreach ($names as $name) {
            file_put_contents($this->runtime.'/barrier/go-'.$name, 'go');
        }
        foreach ($names as $name) {
            $this->wait(fn () => is_file($this->runtime.'/barrier/attempt-'.$name));
        }
    }

    private function finish(Process $p): array
    {
        $p->wait();
        $this->assertTrue($p->isSuccessful(), 'Scheduled worker refused; raw SQL/config/error output suppressed.');
        $this->assertTrue($p->getErrorOutput() === '', 'Raw stderr suppressed.');

        return json_decode($p->getOutput(), true, 16, JSON_THROW_ON_ERROR);
    }

    private function execute(string $name, string $digest): array
    {
        $p = $this->start($name, $digest);
        $this->go([$name]);

        return $this->finish($p);
    }

    private function waiting(string $name, string $table): void
    {
        $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $this->wait(function () use ($name, $table, $parent): bool {
            $row = DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID '
                .'JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE=w.ENGINE AND l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID '
                .'WHERE l.OBJECT_SCHEMA=? AND l.OBJECT_NAME=? AND t.PROCESSLIST_ID=? AND b.PROCESSLIST_ID=?', ['everbranch_enterprise_ci', $table, $this->connections[$name], $parent]);

            return (int) $row->n > 0;
        });
    }

    private function wait(callable $predicate): void
    {
        $end = microtime(true) + 10;
        do {
            if ($predicate()) {
                return;
            } usleep(10000);
        } while (microtime(true) < $end);
        $this->fail('Actual bounded barrier/native row-lock observation failed.');
    }
}
