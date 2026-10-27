<?php

/** Explicit LOCAL fictional comments fixture; no mutation route and no existing edition changes. */
require_once __DIR__.'/proposal-reviewer-workspace-browser-fixture.php';

function reviewerWorkspaceFilesBrowserApplication(): \Illuminate\Foundation\Application
{
    if (getenv('REVIEWER_WORKSPACE_FILES_BROWSER') !== '1') {
        throw new RuntimeException('Requires the explicit private file browser fixture.');
    }
    $app = reviewerWorkspaceBrowserApplication();
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function ($app): void {
        $policy = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false,
            'write_personas' => ['demo_manager', 'manager', 'worker', 'requester']];
        $app['config']->set('service_proposals.reviewer_workspace.collaboration', ['enabled' => true,
            'comments_write_enabled' => true, 'policies' => [\App\Proposals\ReviewerWorkspaceSchema::digest($policy) => $policy]]);
        $scanner = ['schema_version' => 1, 'transport' => 'existing_team_clamd_version_v1', 'host' => '127.0.0.1', 'port' => 3310,
            'file_max_bytes' => \App\Proposals\ReviewerWorkspaceAttachmentsStore::MAX_BYTES, 'engine_version' => '9.9.9', 'minimum_database_serial' => 123456,
            'maximum_definition_age_seconds' => 172800, 'daemon_timezone' => 'UTC',
            'scanner_source_sha256' => hash_file('sha256', (new \ReflectionClass(\App\Services\Team\TeamFileScanner::class))->getFileName()),
            'evidence_source_sha256' => hash_file('sha256', (new \ReflectionClass(\App\Proposals\ReviewerWorkspaceScannerEvidence::class))->getFileName())];
        $attachments = ['schema_version' => 1, 'uploads_enabled' => true, 'metadata_read_enabled' => true, 'scan_enabled' => false, 'write_personas' => $policy['write_personas']];
        $images = ['schema_version' => 1, 'images_enabled' => true, 'scan_config_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($scanner),
            'max_scan_age_seconds' => 3600, 'thumbnail_width' => 320, 'thumbnail_height' => 240, 'thumbnail_max_bytes' => 262144];
        $app['config']->set(['team_work.scanner.enabled' => true, 'team_work.scanner.host' => '127.0.0.1', 'team_work.scanner.port' => 3310,
            'team_work.file_max_bytes' => $scanner['file_max_bytes'],
            'service_proposals.reviewer_workspace.attachments' => ['enabled' => true, 'write_enabled' => true, 'policies' => [\App\Proposals\ReviewerWorkspaceSchema::digest($attachments) => $attachments]],
            'service_proposals.reviewer_workspace.image_access' => ['enabled' => true, 'scan_enabled' => true, 'policies' => [\App\Proposals\ReviewerWorkspaceSchema::digest($images) => $images],
                'scanners' => [\App\Proposals\ReviewerWorkspaceSchema::digest($scanner) => $scanner]]]);
        // Explicit fake scanner/evidence ONLY. Never opens a scanner socket/daemon or provider.
        $app->instance(\App\Services\Team\TeamFileScanner::class, new class extends \App\Services\Team\TeamFileScanner
        {
            public int $calls = 0;

            public array $observations = [];

            public function scan(string $path, string $mimeType, string $sha256): string
            {
                $this->calls++;
                $this->observations[] = ['transaction_level' => \Illuminate\Support\Facades\DB::connection()->transactionLevel(), 'permissions' => fileperms($path) & 0777, 'exact_bytes' => hash_file('sha256', $path) === $sha256];

                return 'clean';
            }
        });
        $app->instance(\App\Proposals\ReviewerWorkspaceScannerEvidence::class, new class extends \App\Proposals\ReviewerWorkspaceScannerEvidence
        {
            public int $calls = 0;

            private ?string $fixedDefinitionReply = null;

            public function version(array $config): ?string
            {
                $this->calls++;

                return $this->fixedDefinitionReply ??= 'ClamAV 9.9.9/123456/'.now()->utc()->format('D M j H:i:s Y');
            }
        });
    });

    return $app;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $app = reviewerWorkspaceFilesBrowserApplication();
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $command = $argv[1] ?? '';
    if ($command === 'revoke') {
        $version = \App\Proposals\Version::findOrFail($argv[2] ?? '');
        $version->update(['revoked_at' => now()]);
        echo json_encode(['revoked' => $version->id], JSON_THROW_ON_ERROR);
    } elseif (in_array($command, ['marker-remove', 'marker-mismatch', 'marker-restore'], true)) {
        $path = app(\App\Enterprise\Provisioning\ClientSilo::class)->markerPath();
        $original = getenv('ENTERPRISE_RUNTIME_ROOT').'/fixture-original-marker.json';
        if ($command === 'marker-remove') {
            unlink($path);
        } else {
            $marker = json_decode(file_get_contents($original), true, 8, JSON_THROW_ON_ERROR);
            if ($command === 'marker-mismatch') {
                $marker['binding_hash'] = str_repeat('f', 64);
            }
            file_put_contents($path, json_encode($marker, JSON_THROW_ON_ERROR));
            chmod($path, 0600);
        }
        echo json_encode(['marker' => $command], JSON_THROW_ON_ERROR);
    } elseif ($command === 'inspect') {
        $row = \App\Models\TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceStore::KIND)->sole();
        $locks = [];
        foreach (['show', 'comment', 'status'] as $action) {
            $route = app('router')->getRoutes()->getByName('service-proposals.recipient.workspace.collaboration.'.$action);
            $locks[$action] = $route === null ? null : [$route->locksFor(), $route->waitsFor()];
        }
        echo json_encode(['revision' => $row->version, 'graph' => $row->data['graph'], 'route_locks' => $locks,
            'thread_rows' => \App\Models\TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceCollaborationStore::THREAD)->count(),
            'comment_receipt_rows' => \App\Models\TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceCollaborationStore::RECEIPT)->count(),
            'native_tasks' => \Illuminate\Support\Facades\Schema::hasTable('team_tasks') ? \Illuminate\Support\Facades\DB::table('team_tasks')->count() : 0,
            'ledger_rows' => \App\Models\TeamOperationRecord::where('kind', 'ent_trial_budget')->count(),
            'uploads' => \App\Models\TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceAttachmentsStore::KIND)->get()->map(fn ($row) => ['id' => $row->id, 'status' => $row->status, 'version' => $row->version, 'payload' => $row->data['payload']])->all(),
            'attestations' => \App\Models\TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceImagesStore::KIND)->get()->map(fn ($row) => ['id' => $row->id, 'status' => $row->status, 'version' => $row->version])->all(),
            'private_files' => collect(\Illuminate\Support\Facades\Storage::disk('team_private')->allFiles())->map(fn ($path) => ['size' => \Illuminate\Support\Facades\Storage::disk('team_private')->size($path), 'sha256' => hash('sha256', \Illuminate\Support\Facades\Storage::disk('team_private')->get($path))])->all(),
            'users' => \App\Models\User::count(), 'memberships' => \Illuminate\Support\Facades\DB::table('tenant_user')->count()], JSON_THROW_ON_ERROR);
    } elseif ($command === 'fake-scan') {
        $version = \App\Proposals\Version::findOrFail($argv[2] ?? '');
        $upload = \App\Models\TeamOperationRecord::findOrFail($argv[3] ?? '');
        $owner = \App\Models\User::sole();
        $tenant = \App\Models\Tenant::findOrFail($version->tenant_id);
        $request = \Illuminate\Http\Request::create(config('app.url').'/enterprise-owner/owner-workspaces/'.$tenant->slug.'/fixture', 'POST');
        $route = new \Illuminate\Routing\Route('POST', 'owner-workspaces/{workspace}/fixture', fn () => null);
        $route->bind($request);
        $route->setParameter('workspace', $tenant->slug);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $owner);
        $workspace = \App\Models\TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceStore::KIND)->sole();
        $target = $upload->data['target'];
        $list = ['ticket' => 'tickets', 'project' => 'projects', 'task' => 'tasks'][$target['kind']];
        $record = collect($workspace->data['graph'][$list])->firstWhere('id', $target['id']);
        $result = app(\App\Proposals\ReviewerWorkspaceImagesScan::class)->scan($request, $version->id, $upload->id,
            ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'expected_workspace_revision' => $workspace->version, 'expected_target_version' => $record['version'],
                'expected_upload_revision' => $upload->version, 'expected_attestation_revision' => 0], false);
        echo json_encode(['result' => $result, 'FAKE_scanner_calls' => app(\App\Services\Team\TeamFileScanner::class)->calls,
            'FAKE_evidence_calls' => app(\App\Proposals\ReviewerWorkspaceScannerEvidence::class)->calls,
            'observations' => app(\App\Services\Team\TeamFileScanner::class)->observations], JSON_THROW_ON_ERROR);
    } elseif ($command === 'seed') {
        if (\Illuminate\Support\Facades\Schema::hasTable('tenants')) {
            throw new RuntimeException('Seed refuses an already initialized fixture.');
        }
        $disk = config('database.connections.enterprise.database');
        if (filesize($disk) !== 0) {
            throw new RuntimeException('Seed requires a fresh empty fixture file.');
        }
        unlink($disk);
        // Explicit LOCAL fixture composition: provision an empty in-memory runtime first.
        // Production storage/SQLite guards remain unchanged. No server runs during preparation.
        config(['database.connections.enterprise.database' => ':memory:', 'enterprise_client.database' => ':memory:']);
        \Illuminate\Support\Facades\DB::purge('enterprise');
        if ($app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Fixture migrations failed.');
        }
        $manifest = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
            'environment' => 'testing', 'storage_id' => config('enterprise_client.storage_id'),
            'tenant' => ['business_id' => '72000000-0000-4000-8000-000000000001', 'slug' => 'synthetic-workspace-owner', 'name' => 'FICTIONAL owner'],
            'branding' => ['organization_name' => 'FICTIONAL owner', 'accent' => '#123456'],
            'departments' => [['id' => '73000000-0000-4000-8000-000000000001', 'key' => 'administration', 'name' => 'Administration', 'visibility' => 'department_only']],
            'locations' => [['id' => '74000000-0000-4000-8000-000000000001', 'key' => 'private-vault', 'department_key' => 'administration',
                'name' => 'PRIVATE OWNER SENTINEL', 'source_url' => null, 'source_label' => 'Fictional fixture']], 'workflow_defaults' => [], 'role_defaults' => []];
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
        file_put_contents(getenv('ENTERPRISE_RUNTIME_ROOT').'/fixture-original-marker.json', json_encode($marker, JSON_THROW_ON_ERROR));
        chmod(getenv('ENTERPRISE_RUNTIME_ROOT').'/fixture-original-marker.json', 0600);
        $silo->assertReady(); // Actual production binding validator, before publication and HTTP.
        $tenant = \App\Models\Tenant::findOrFail($installed['tenant_id']);
        $owner = \App\Models\User::create(['name' => 'FICTIONAL authorized owner', 'email' => 'owner@example.invalid', 'password' => \Illuminate\Support\Facades\Hash::make('FICTIONAL_OWNER_ONLY'), 'is_active' => true]);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
        $marker = ['synthetic_fixture' => true, 'proposal_review_workspace_anchor' => true, 'reviewed_fixture' => 'browser-only'];
        \App\Models\TeamArea::create(['id' => config('service_proposals.reviewer_workspace.anchor_area_id'), 'tenant_id' => $tenant->id, 'slug' => 'fictional-browser-workspace',
            'name' => 'FICTIONAL browser storage', 'profile' => 'enterprise_shared', 'version' => 1, 'settings' => $marker]);
        // Reuse the independently tested closed graph shape only; no PHPUnit setup, native identity or authority substitution.
        $seed = (new class('fixtureGraph') extends \Tests\Support\ReviewerWorkspaceFixture
        {
            public function fixtureGraph(): void {}

            public function graph(): array
            {
                return $this->graphSeed();
            }
        })->graph();
        for ($number = 3; $number <= 20; $number++) {
            $projectId = (string) \Illuminate\Support\Str::uuid();
            $taskId = (string) \Illuminate\Support\Str::uuid();
            $seed['projects'][] = array_replace($seed['projects'][0], ['id' => $projectId, 'title' => 'FICTIONAL seeded job '.$number]);
            $seed['tasks'][] = array_replace($seed['tasks'][0], ['id' => $taskId, 'project_id' => $projectId, 'title' => 'FICTIONAL task '.$number]);
            $seed['tickets'][] = array_replace($seed['tickets'][0], ['id' => (string) \Illuminate\Support\Str::uuid(), 'project_id' => $projectId, 'task_id' => $taskId, 'title' => 'FICTIONAL ticket '.$number]);
        }
        if (getenv('REVIEWER_WORKSPACE_BROWSER_SEED') === 'official-map-v1') {
            $official = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/proposals/reviewer-workspace-map-seed.json'), true, 32, JSON_THROW_ON_ERROR);
            $seed = $official['graph'];
            if (\App\Proposals\ReviewerWorkspaceSchema::digest($seed) !== $official['graph_sha256']) {
                throw new RuntimeException('Official fictional graph digest changed.');
            }
        }
        $seed = app(\App\Proposals\ReviewerWorkspaceSchema::class)->graph($seed, true);
        $policy = array_values(config('service_proposals.reviewer_workspace.policies'))[0];
        $draft = app(\App\Proposals\ProposalService::class)->leesMcRaeDraft($tenant, $owner->id)->refresh();
        $payload = $draft->payload;
        $payload['review_workspace'] = ['schema_version' => 1, 'workspace_id' => (string) \Illuminate\Support\Str::uuid(),
            'seed_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($seed), 'policy_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($policy),
            'expires_at' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s\Z')];
        $collaborationPolicy = array_values(config('service_proposals.reviewer_workspace.collaboration.policies'))[0];
        $payload['review_workspace_collaboration'] = ['schema_version' => 1,
            'policy_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($collaborationPolicy),
            'expires_at' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z')];
        foreach (['attachments' => 'attachments', 'images' => 'image_access'] as $descriptor => $config) {
            $featurePolicy = array_values(config('service_proposals.reviewer_workspace.'.$config.'.policies'))[0];
            $payload['review_workspace_'.$descriptor] = ['schema_version' => 1, 'policy_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($featurePolicy),
                'expires_at' => now()->addDays(4)->utc()->format('Y-m-d\TH:i:s\Z')];
        }
        $draft->payload = $payload;
        $draft->save();
        $published = app(\App\Proposals\ProposalService::class)->publish($tenant, $owner->id, $draft->id, $draft->revision, 'FICTIONAL_BROWSER_PASSWORD', 7, (string) \Illuminate\Support\Str::uuid(), 'reviewer');
        $version = $published['version'];
        $request = \Illuminate\Http\Request::create(config('app.url').'/enterprise-owner/owner-workspaces/'.$tenant->slug.'/fixture', 'POST');
        $route = new \Illuminate\Routing\Route('POST', 'owner-workspaces/{workspace}/fixture', fn () => null);
        $route->bind($request);
        $route->setParameter('workspace', $tenant->slug);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $owner);
        app(\App\Proposals\ReviewerWorkspace::class)->initialize($request, $version->id, $seed, ['request_id' => (string) \Illuminate\Support\Str::uuid(),
            'descriptor_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($payload['review_workspace'])]);
        echo json_encode(['version_id' => $version->id, 'token' => $published['token'], 'password' => 'FICTIONAL_BROWSER_PASSWORD',
            'workspace_id' => $payload['review_workspace']['workspace_id'], 'seed_projects' => count($seed['projects']), 'reviewer_names' => $version->payload['reviewer_names'],
            'collaboration' => $version->payload['review_workspace_collaboration']], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Only seed, inspect or revoke are accepted by the local CLI fixture.');
    }
    \Illuminate\Support\Facades\Http::assertNothingSent();
}
