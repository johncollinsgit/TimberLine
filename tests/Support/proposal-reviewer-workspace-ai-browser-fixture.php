<?php

/** Explicit opt-in LOCAL fake-provider fixture, separate from the unchanged AI-off/ledger-zero fixture. */
require_once __DIR__.'/proposal-reviewer-workspace-browser-fixture.php';

function reviewerWorkspaceAiBrowserApplication(): \Illuminate\Foundation\Application
{
    if (getenv('REVIEWER_WORKSPACE_AI_BROWSER') !== '1') {
        throw new RuntimeException('Explicit local fake-provider fixture required.');
    }
    $app = reviewerWorkspaceBrowserApplication(); // All existing loopback/private-root/empty-env/SQLite/real-CSRF guards.
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function ($app): void {
        $file = getenv('ENTERPRISE_RUNTIME_ROOT').'/fixture-ai-approval.json';
        if (is_file($file)) {
            if (is_link($file) || (fileperms($file) & 0777) !== 0600) {
                throw new RuntimeException('Private fictional approval required.');
            }
            $config = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            $config['api_key'] = 'sk-FICTIONAL-NEVER-VALID-LOCAL-BROWSER-ONLY';
            $app['config']->set('service_proposals.reviewer_ai', $config);
        }
    });
    $app->booted(static function (): void {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(static function ($request) {
            if ($request->url() !== 'https://api.openai.com/v1/responses' || $request->method() !== 'POST') {
                throw new RuntimeException('No external transport is allowed in this local fixture.');
            }
            $body = $request->data();
            $payload = json_decode($body['input'][0]['content'], true, 64, JSON_THROW_ON_ERROR);
            $ref = $payload['snapshot']['sources'][0]['ref'];
            $calls = getenv('ENTERPRISE_RUNTIME_ROOT').'/fixture-provider-calls.jsonl';
            $entry = ['request_id' => $request->header('X-Client-Request-Id')[0], 'input_keys' => array_keys($payload),
                'snapshot_sha256' => \App\Proposals\ReviewerWorkspaceSchema::digest($payload['snapshot']),
                'source_refs' => array_column($payload['snapshot']['sources'], 'ref'), 'history_count' => count($payload['history'] ?? []),
                'history_questions' => array_column($payload['history'] ?? [], 'question')];
            file_put_contents($calls, json_encode($entry, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            chmod($calls, 0600);

            return \Illuminate\Support\Facades\Http::response(['id' => 'resp_FICTIONAL_BROWSER_'.str_replace('-', '', $entry['request_id']),
                'model' => \App\Proposals\ReviewerAiResponses::MODEL, 'service_tier' => 'default', 'status' => 'completed',
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 50, 'total_tokens' => 1050],
                'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text',
                    'text' => json_encode(['answer' => 'FICTIONAL local test answer from current saved shared work.', 'refs' => [$ref]], JSON_THROW_ON_ERROR)]]]]]);
        });
    });

    return $app;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $app = reviewerWorkspaceAiBrowserApplication();
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $action = $argv[1] ?? '';
    if ($action === 'prepare-fixture') {
        $path = getenv('ENTERPRISE_RUNTIME_ROOT').'/fixture-ai-approval.json';
        if (file_exists($path) || \App\Models\TeamOperationRecord::whereIn('kind', ['ent_trial_budget', 'ent_trial_charge', 'ent_proposal_ai'])->exists()) {
            throw new RuntimeException('Fixture refuses duplicate/reset campaign state.');
        }
        $installed = app(\App\Enterprise\Provisioning\ClientSilo::class)->assertReady();
        $version = \App\Proposals\Version::findOrFail($argv[2] ?? '');
        if ((int) $version->tenant_id !== (int) $installed->tenant_id) {
            throw new RuntimeException('Synthetic publication owner mismatch.');
        }
        $evidence = hash('sha256', 'FICTIONAL LOCAL BROWSER no prior spending; source-only test evidence');
        $area = \App\Models\TeamArea::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $installed->tenant_id,
            'slug' => \App\Proposals\ReviewerAiStore::AREA_SLUG, 'name' => 'FICTIONAL local campaign storage', 'profile' => 'enterprise_shared', 'version' => 1,
            'settings' => ['synthetic_fixture' => true, 'proposal_review_ai_anchor' => true, 'inventory_evidence_hash' => $evidence, 'baseline_evidence_hash' => $evidence]]);
        $ledger = \App\Models\TeamOperationRecord::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $installed->tenant_id,
            'area_id' => $area->id, 'kind' => 'ent_trial_budget', 'title' => 'FICTIONAL one shared lifetime campaign', 'status' => 'active', 'visibility' => 'area', 'version' => 1,
            'source_key' => hash('sha256', \App\Proposals\ReviewerAiStore::LEDGER_SOURCE),
            'data' => ['baseline' => ['prior_microusd' => 0, 'evidence_hash' => $evidence], 'cap_microusd' => 5000000]]);
        $config = ['enabled' => true, 'ui_enabled' => true, 'schema_version' => 1, 'approval_id' => (string) \Illuminate\Support\Str::uuid(),
            'client_id' => $installed->client_id, 'silo_id' => $installed->silo_id, 'tenant_id' => (int) $installed->tenant_id, 'area_id' => $area->id, 'ledger_id' => $ledger->id,
            'mount' => '/enterprise-owner', 'prior_spend_verified' => true, 'prior_spend_microusd' => 0, 'prior_spend_evidence_hash' => $evidence, 'inventory_evidence_hash' => $evidence,
            'project_id' => 'proj_FICTIONAL_BROWSER_ONLY', 'organization_id' => null, 'model' => \App\Proposals\ReviewerAiConnection::MODEL, 'model_context_tokens' => 400000,
            'max_output_tokens' => 800, 'input_ceiling_microusd_per_million' => 750000, 'output_ceiling_microusd_per_million' => 4500000, 'per_call_overhead_microusd' => 0,
            'model_context_evidence' => 'FICTIONAL LOCAL fixture model evidence, not current provider authority', 'rates_evidence' => 'FICTIONAL LOCAL fake billing evidence',
            'version_id' => $version->id, 'version_hash' => $version->content_hash, 'build_id' => $version->payload['campus_review']['build_id'], 'source_hash' => $version->payload['campus_review']['source_hash'],
            'valid_until' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z'), 'credential_valid_until' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s\Z'),
            'credential_sha256' => hash('sha256', 'sk-FICTIONAL-NEVER-VALID-LOCAL-BROWSER-ONLY')];
        $config['approval_sha256'] = hash('sha256', json_encode(\App\Proposals\ReviewerAiConnection::approvalFields($config), JSON_THROW_ON_ERROR));
        $file = fopen($path, 'x');
        chmod($path, 0600);
        fwrite($file, json_encode($config, JSON_THROW_ON_ERROR));
        fclose($file);
        echo json_encode(['synthetic' => true, 'ledger_id' => $ledger->id, 'cap_microusd' => 5000000], JSON_THROW_ON_ERROR);
    } elseif ($action === 'inspect') {
        $ledgers = \App\Models\TeamOperationRecord::where('kind', 'ent_trial_budget')->get();
        $workspace = \App\Models\TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceStore::KIND)->sole();
        $file = getenv('ENTERPRISE_RUNTIME_ROOT').'/fixture-provider-calls.jsonl';
        $calls = is_file($file) ? array_map(static fn ($line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
        echo json_encode(['ledger_count' => $ledgers->count(), 'ledger_id' => $ledgers->sole()->id, 'ledger' => $ledgers->sole()->data,
            'charges' => \App\Models\TeamOperationRecord::where('kind', 'ent_trial_charge')->get()->map(static fn ($row) => ['id' => $row->id, 'status' => $row->status, 'data' => $row->data])->all(),
            'calls' => $calls, 'workspace_revision' => $workspace->version, 'users' => \App\Models\User::count(),
            'memberships' => \Illuminate\Support\Facades\DB::table('tenant_user')->count()], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Only explicit local fixture preparation and readonly inspection are supported.');
    }
}
