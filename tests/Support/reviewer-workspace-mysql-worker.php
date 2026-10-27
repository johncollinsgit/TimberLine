<?php

/** Disposable loopback-only MySQL fixture. No production entry, provider, budget, fake User or DB provisioning. */
final class ReviewerWorkspaceMySqlWorker
{
    public static function environment(): array
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || getenv('APP_ENV') !== 'testing'
            || getenv('ENTERPRISE_APP_ENV') !== 'testing' || getenv('ENTERPRISE_DB_DRIVER') !== 'mysql'
            || getenv('ENTERPRISE_DB_DATABASE') !== 'everbranch_enterprise_ci'
            || ! in_array(getenv('ENTERPRISE_DB_HOST'), ['127.0.0.1', 'localhost'], true)
            || ! ctype_digit((string) getenv('ENTERPRISE_DB_PORT')) || (int) getenv('ENTERPRISE_DB_PORT') < 1
            || (int) getenv('ENTERPRISE_DB_PORT') > 65535 || getenv('ENTERPRISE_DB_URL') || getenv('ENTERPRISE_DB_SOCKET')) {
            throw new RuntimeException('Disposable loopback MySQL approval is required.');
        }

        return ['driver' => 'mysql', 'host' => getenv('ENTERPRISE_DB_HOST'), 'port' => getenv('ENTERPRISE_DB_PORT'),
            'database' => 'everbranch_enterprise_ci', 'username' => getenv('ENTERPRISE_DB_USERNAME'), 'password' => getenv('ENTERPRISE_DB_PASSWORD'),
            'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
    }

    public static function boot(): \Illuminate\Foundation\Application
    {
        $database = self::environment(); // Refuse before autoload, framework bootstrap, DB or runtime reads.
        $runtime = realpath((string) getenv('ENTERPRISE_RUNTIME_ROOT'));
        if (! $runtime || dirname($runtime) !== realpath(sys_get_temp_dir())
            || ! preg_match('/^reviewer-workspace-mysql-[a-f0-9-]{36}$/D', basename($runtime))
            || is_link(getenv('ENTERPRISE_RUNTIME_ROOT')) || (fileperms($runtime) & 0777) !== 0700
            || ! is_file($runtime.'/.env') || is_link($runtime.'/.env') || filesize($runtime.'/.env') !== 0) {
            throw new RuntimeException('Fresh private disposable runtime required.');
        }
        $source = dirname(__DIR__, 2);
        require_once $source.'/vendor/autoload.php';
        $app = (static fn ($file) => require $file)($source.'/apps/enterprise/bootstrap/app.php');
        $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function ($app) use ($database): void {
            $policy = app(\App\Proposals\ReviewerWorkspaceSchema::class)->policy(['schema_version' => 1, 'max_role' => 'editor',
                'area_ids' => ['81000000-0000-4000-8000-000000000001', '81000000-0000-4000-8000-000000000002'],
                'personas' => \App\Proposals\ReviewerWorkspaceSchema::PERSONAS, 'allow_financial' => true]);
            $marker = ['synthetic_fixture' => true, 'proposal_review_workspace_anchor' => true, 'reviewed_fixture' => 'mysql-only'];
            $app['config']->set(['app.env' => 'testing', 'app.debug' => false, 'app.url' => 'https://proposal.example.invalid',
                'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
                'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '/enterprise-owner', 'enterprise_runtime.trial_mount_only' => false,
                'database.default' => 'enterprise', 'database.connections.enterprise' => $database,
                'enterprise_client.client_id' => '91000000-0000-4000-8000-000000000001', 'enterprise_client.silo_id' => '91000000-0000-4000-8000-000000000002',
                'enterprise_client.storage_id' => '91000000-0000-4000-8000-000000000003', 'enterprise_client.environment' => 'testing', 'enterprise_client.database' => $database['database'],
                'session.driver' => 'array', 'session.path' => '/enterprise-owner', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4,
                'mail.default' => 'array', 'queue.default' => 'sync', 'logging.default' => 'null',
                'enterprise.enabled' => false, 'enterprise.writes_enabled' => false, 'enterprise.trial.enabled' => false, 'enterprise.conversation.enabled' => false,
                'enterprise.intelligence_enabled' => false, 'enterprise.voice_generation_enabled' => false,
                'service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
                'service_proposals.runtime_purpose' => 'proposal_owner', 'service_proposals.owner_client_id' => '91000000-0000-4000-8000-000000000001',
                'service_proposals.owner_silo_id' => '91000000-0000-4000-8000-000000000002', 'service_proposals.owner_workspaces' => [],
                'service_proposals.recipient_hosts' => ['proposal.example.invalid'], 'service_proposals.campus_review.enabled' => true,
                'service_proposals.campus_review.build_id' => 'FICTIONAL-shared-workspace-mysql-v1',
                'service_proposals.reviewer_ai.enabled' => false, 'service_proposals.reviewer_ai.ui_enabled' => false,
                'service_proposals.reviewer_workspace' => ['enabled' => true, 'writes_enabled' => true, 'initialization_enabled' => true, 'financial_enabled' => true,
                    'anchor_area_id' => '99000000-0000-4000-8000-000000000001', 'anchor_marker_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($marker),
                    'policies' => [\App\Proposals\ReviewerWorkspaceSchema::digest($policy) => $policy]]]);
            $app->detectEnvironment(static fn () => 'testing');
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $connection = \Illuminate\Support\Facades\DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'everbranch_enterprise_ci') {
            throw new RuntimeException('Disposable database binding changed.');
        }
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 12');

        return $app;
    }

    public static function bindOwner(): void
    {
        $installed = app(\App\Enterprise\Provisioning\ClientSilo::class)->assertReady();
        if ($installed->client_id !== config('enterprise_client.client_id')) {
            throw new RuntimeException('Synthetic client binding changed.');
        }
        config(['service_proposals.owner_workspaces' => [(int) $installed->tenant_id => 'enterprise']]);
    }

    public static function request(array $session, string $id): \Illuminate\Http\Request
    {
        $store = new \Illuminate\Session\Store('workspace-mysql', new \Illuminate\Session\ArraySessionHandler(60));
        $store->setId($id);
        $store->start();
        $store->replace($session);
        $request = \Illuminate\Http\Request::create('https://proposal.example.invalid/enterprise-owner/fixture', 'POST', [], [], [], [
            'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on']);
        $request->setLaravelSession($store);
        $request->setUserResolver(static fn () => null);

        return $request;
    }

    public static function main(): void
    {
        try {
            self::boot();
            self::bindOwner();
            $data = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
            if (! in_array($data['action'] ?? null, ['command', 'status'], true) || ! preg_match('/^[a-z0-9-]{1,40}$/D', $data['worker'] ?? '')) {
                throw new RuntimeException('Closed worker input required.');
            }
            $request = self::request($data['session'], $data['session_id']);
            $connection = (int) \Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $barrier = getenv('ENTERPRISE_RUNTIME_ROOT').'/barrier';
            file_put_contents($barrier.'/ready-'.$data['worker'], (string) $connection);
            $deadline = microtime(true) + 15;
            while (! is_file($barrier.'/go-'.$data['worker']) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! is_file($barrier.'/go-'.$data['worker'])) {
                throw new RuntimeException('Parent barrier timed out.');
            }
            file_put_contents($barrier.'/attempt-'.$data['worker'], 'attempt');
            $service = app(\App\Proposals\ReviewerWorkspace::class);
            try {
                $result = $data['action'] === 'command'
                    ? $service->command($request, $data['token'], $data['command'])
                    : $service->commandStatus($request, $data['token'], $data['binding'], $data['selection'], $data['request_id'], $data['digest']);
                $result = ['http_status' => 200, 'result' => $result];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                $result = ['http_status' => $error->getStatusCode()];
            }
            \Illuminate\Support\Facades\Http::assertNothingSent();
            echo json_encode(['connection_id' => $connection, ...$result], JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // No SQL/config/password/path/stack output, even for an unapproved direct invocation.
            fwrite(STDOUT, '{"error":"disposable_workspace_worker_refused"}');
            exit(2);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ReviewerWorkspaceMySqlWorker::main();
}
