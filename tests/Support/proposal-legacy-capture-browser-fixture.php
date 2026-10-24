<?php

/** Actual LOCAL owner login/CSRF/ClientSilo and browser-only Version. Synthetic state; no human capture or authority impersonation. */
function legacyCaptureBrowserApplication(): \Illuminate\Foundation\Application
{
    $source = dirname(__DIR__, 2);
    $root = realpath((string) getenv('ENTERPRISE_RUNTIME_ROOT'));
    $origin = (string) getenv('LEGACY_CAPTURE_BROWSER_ORIGIN');
    if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true) || getenv('LEGACY_CAPTURE_BROWSER') !== '1' || getenv('APP_ENV') !== 'testing'
        || ! $root || dirname($root) !== dirname($source) || ! preg_match('/^runtime-[0-9]+$/D', basename($root))
        || ! preg_match('~^https://127\.0\.0\.1:9045$~D', $origin)
        || (PHP_SAPI === 'cli-server' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1')) {
        throw new RuntimeException('Requires the explicitly private local capture fixture.');
    }
    require_once $source.'/vendor/autoload.php';
    $database = realpath($root.'/storage/framework/testing/enterprise-browser-legacy-capture.sqlite');
    if (! $database || dirname($database) !== $root.'/storage/framework/testing' || filesize($root.'/.env') !== 0) {
        throw new RuntimeException('Requires a disposable SQLite fixture and empty ENV.');
    }
    if (! class_exists('LegacyCaptureBrowserCsrf', false)) {
        class LegacyCaptureBrowserCsrf extends \App\Http\Middleware\EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        }
    }
    $app = (static fn ($file) => require $file)($source.'/apps/enterprise/bootstrap/app.php');
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function ($app) use ($root, $database, $origin): void {
        $app['config']->set(['app.env' => 'testing', 'app.debug' => true, 'app.url' => $origin,
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '/enterprise-owner', 'enterprise_runtime.trial_mount_only' => false,
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
            'enterprise_client.client_id' => '71000000-0000-4000-8000-000000000001', 'enterprise_client.silo_id' => '71000000-0000-4000-8000-000000000002',
            'enterprise_client.storage_id' => '71000000-0000-4000-8000-000000000003', 'enterprise_client.environment' => 'testing', 'enterprise_client.database' => $database,
            'session.driver' => 'file', 'session.path' => '/enterprise-owner', 'cache.default' => 'file', 'cache.limiter' => 'file', 'hashing.bcrypt.rounds' => 4,
            'mail.default' => 'array', 'queue.default' => 'sync', 'logging.default' => 'null',
            'enterprise.enabled' => false, 'enterprise.writes_enabled' => false, 'enterprise.trial.enabled' => false, 'enterprise.conversation.enabled' => false,
            'enterprise.intelligence_enabled' => false, 'enterprise.voice_generation_enabled' => false,
            'enterprise.reviewer_demo_enabled' => true, 'enterprise.reviewer_trial_destination_approved' => false,
            'service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
            'service_proposals.runtime_purpose' => 'proposal_owner', 'service_proposals.owner_client_id' => '71000000-0000-4000-8000-000000000001',
            'service_proposals.owner_silo_id' => '71000000-0000-4000-8000-000000000002', 'service_proposals.owner_workspaces' => [1 => 'enterprise'],
            'service_proposals.recipient_hosts' => ['127.0.0.1'], 'service_proposals.campus_review.enabled' => true,
            'service_proposals.campus_review.build_id' => 'FICTIONAL-browser-local-capture-v1',
            'service_proposals.reviewer_ai.enabled' => false, 'service_proposals.reviewer_ai.ui_enabled' => false,
            'service_proposals.reviewer_workspace.enabled' => false,
        ]);
        $approvalPath = $root.'/synthetic-capture-approval.json';
        if (is_file($approvalPath)) {
            $app['config']->set('service_proposals.legacy_capture', ['enabled' => true, 'approvals' => json_decode(file_get_contents($approvalPath), true, 12, JSON_THROW_ON_ERROR)]);
        }
        $app->detectEnvironment(static fn () => 'testing');
    });
    $app->booted(static function () use ($app): void {
        $app->bind(\App\Http\Middleware\EnterpriseCsrfToken::class, LegacyCaptureBrowserCsrf::class);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    });

    return $app;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $app = legacyCaptureBrowserApplication();
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $command = $argv[1] ?? '';
    if ($command === 'seed') {
        $disk = config('database.connections.enterprise.database');
        if (filesize($disk) !== 0) {
            throw new RuntimeException('Seed refuses an existing fixture.');
        }
        unlink($disk);
        // Local composition only: claim truly empty storage using memory, then bind the disposable file before any HTTP.
        config(['database.connections.enterprise.database' => ':memory:', 'enterprise_client.database' => ':memory:']);
        \Illuminate\Support\Facades\DB::purge('enterprise');
        if ($app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Fixture migration failed.');
        }
        $manifest = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'), 'environment' => 'testing', 'storage_id' => config('enterprise_client.storage_id'),
            'tenant' => ['business_id' => '72000000-0000-4000-8000-000000000001', 'slug' => 'synthetic-capture-owner', 'name' => 'FICTIONAL Capture Owner'],
            'branding' => ['organization_name' => 'FICTIONAL Capture Owner', 'accent' => '#123456'],
            'departments' => [['id' => '73000000-0000-4000-8000-000000000001', 'key' => 'administration', 'name' => 'Administration', 'visibility' => 'department_only']],
            'locations' => [['id' => '74000000-0000-4000-8000-000000000001', 'key' => 'private-vault', 'department_key' => 'administration', 'name' => 'PRIVATE FIXTURE SENTINEL', 'source_url' => null, 'source_label' => 'Synthetic fixture']],
            'workflow_defaults' => [], 'role_defaults' => []];
        $provisioner = app(\App\Enterprise\Provisioning\ClientProvisioner::class);
        $installed = $provisioner->apply($manifest, $provisioner->plan($manifest)['plan_hash']);
        $connection = \Illuminate\Support\Facades\DB::connection('enterprise');
        $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($disk));
        chmod($disk, 0600);
        config(['database.connections.enterprise.database' => $disk, 'enterprise_client.database' => $disk]);
        \Illuminate\Support\Facades\DB::purge('enterprise');
        $silo = app(\App\Enterprise\Provisioning\ClientSilo::class);
        $hash = hash('sha256', \App\Enterprise\Provisioning\ClientManifest::canonical($silo->binding()));
        \Illuminate\Support\Facades\DB::table('enterprise_client_silos')->where('id', 1)->update(['binding_hash' => $hash]);
        $marker = json_decode(file_get_contents($silo->markerPath()), true, 8, JSON_THROW_ON_ERROR);
        $marker['binding_hash'] = $hash;
        file_put_contents($silo->markerPath(), json_encode($marker, JSON_THROW_ON_ERROR));
        chmod($silo->markerPath(), 0600);
        $silo->assertReady();
        $tenant = \App\Models\Tenant::findOrFail($installed['tenant_id']);
        $owner = \App\Models\User::create(['name' => 'FICTIONAL OWNER', 'email' => 'capture@example.invalid', 'password' => \Illuminate\Support\Facades\Hash::make('FICTIONAL_CAPTURE_ONLY'), 'is_active' => true]);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
        $service = app(\App\Proposals\ProposalService::class);
        $draft = $service->leesMcRaeDraft($tenant, $owner->id)->refresh();
        $published = $service->publish($tenant, $owner->id, $draft->id, (int) $draft->revision, 'FICTIONAL_RECIPIENT_CAPTURE', 7, (string) \Illuminate\Support\Str::uuid(), 'reviewer');
        $v = $published['version'];
        $m = $v->payload['campus_review'];
        $approval = ['tenant_id' => (int) $tenant->id, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'), 'storage_id' => config('enterprise_client.storage_id'),
            'version_hash' => $v->content_hash, 'manifest_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($m), 'source_hash' => $m['source_hash'], 'build_id' => $m['build_id'],
            'module_sha256' => hash('sha256', \App\Proposals\ReviewerLegacyCapture::script()), 'expires_at' => now()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z')];
        $path = getenv('ENTERPRISE_RUNTIME_ROOT').'/synthetic-capture-approval.json';
        file_put_contents($path, json_encode([$v->id => $approval], JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        echo json_encode(['version_id' => $v->id, 'version_hash' => $v->content_hash, 'source_hash' => $m['source_hash'], 'build_id' => $m['build_id'], 'token' => $published['token'],
            'owner_email' => $owner->email, 'owner_password' => 'FICTIONAL_CAPTURE_ONLY', 'recipient_password' => 'FICTIONAL_RECIPIENT_CAPTURE', 'workspace' => $tenant->slug], JSON_THROW_ON_ERROR);
    } elseif ($command === 'inspect') {
        $rows = [];
        foreach (['users', 'tenant_user', 'service_proposal_drafts', 'service_proposal_versions', 'service_proposal_comments', 'team_operation_records', 'team_areas'] as $table) {
            $rows[$table] = hash('sha256', \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson());
        }
        echo json_encode(['rows' => $rows, 'ledger_count' => \Illuminate\Support\Facades\DB::table('team_operation_records')->where('kind', 'ent_trial_budget')->count()], JSON_THROW_ON_ERROR);
    } elseif ($command === 'revoke-owner') {
        \Illuminate\Support\Facades\DB::table('tenant_user')->update(['membership_active' => false]);
        echo '{"synthetic_owner_revoked":true}';
    } else {
        throw new RuntimeException('Only explicit synthetic seed/inspect/revoke-owner commands are permitted.');
    }
}
