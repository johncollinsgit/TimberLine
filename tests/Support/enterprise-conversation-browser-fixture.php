<?php

// Creates only a new, explicitly named fictional SQLite fixture. No provider access.
require dirname(__DIR__, 2).'/vendor/autoload.php';
if (PHP_SAPI !== 'cli' || getenv('ENTERPRISE_APP_ENV') !== 'testing'
    || getenv('ENTERPRISE_TEST_CONVERSATION_PROVIDER') !== '1') {
    throw new RuntimeException('Explicit local conversation testing is required.');
}

final class ConversationBrowserFixture extends \Tests\Support\EnterpriseConversationFixture
{
    public function prepare(): void
    {
        $this->setUp();
        // The provisioner correctly requires empty initial private storage. The test fixture
        // starts in memory, then explicitly persists that already-fictional fixture for the
        // separate PHP browser process and binds its new test-only SQLite path.
        $database = $this->runtime.'/storage/framework/testing/enterprise-browser-conversation.sqlite';
        $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
        $pdo->exec('VACUUM INTO '.$pdo->quote($database));
        $databaseConfig = ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true];
        config(['database.connections.enterprise' => $databaseConfig, 'enterprise_client.database' => $database]);
        \Illuminate\Support\Facades\DB::purge('enterprise');
        $silo = app(\App\Enterprise\Provisioning\ClientSilo::class);
        $hash = hash('sha256', \App\Enterprise\Provisioning\ClientManifest::canonical($silo->binding()));
        \Illuminate\Support\Facades\DB::table('enterprise_client_silos')->update(['binding_hash' => $hash]);
        $marker = $silo->marker();
        $marker['binding_hash'] = $hash;
        file_put_contents($silo->markerPath(), json_encode($marker, JSON_THROW_ON_ERROR));
        $silo->assertReady();
        $this->user->password = \Illuminate\Support\Facades\Hash::make('Fictional-conversation-browser-only!');
        $this->user->save();
        config(['enterprise.conversation.ui_enabled' => true, 'enterprise.conversation.saved_assistants_enabled' => true]);
        foreach ([[1000, 'estimate'], [1500, 'recorded_cost']] as [$amount, $basis]) {
            $this->postJson($this->url('/enterprise/planning'), ['kind' => 'cost', 'id' => (string) \Illuminate\Support\Str::uuid(),
                'request_id' => (string) \Illuminate\Support\Str::uuid(), 'area_id' => $this->area, 'expected_version' => 0,
                'title' => 'Synthetic '.$basis, 'status' => 'recorded', 'project_id' => $this->project,
                'amount_minor' => $amount, 'basis' => $basis, 'currency' => 'USD'])->assertOk();
        }
        $config = [];
        foreach (['enterprise.enabled', 'enterprise.writes_enabled', 'enterprise.sample_data', 'enterprise.tenant_allowlist',
            'team_work.enabled', 'team_work.tenant_allowlist', 'enterprise.read_only_assistant_enabled', 'enterprise.briefing_enabled',
            'enterprise.planning_enabled', 'enterprise.operations_enabled', 'enterprise.resources_enabled',
            'enterprise.trial.enabled', 'enterprise.trial.tenant_id', 'enterprise.trial.area_id', 'enterprise.trial.prior_spend_verified',
            'enterprise.trial.prior_spend_microusd', 'enterprise.trial.prior_spend_evidence', 'enterprise.conversation', 'enterprise_client',
            'enterprise_runtime.isolated', 'enterprise_runtime.base_path', 'session.path'] as $key) {
            $config[$key] = config($key);
        }
        $config['enterprise.tour_enabled'] = true;
        $config['database.connections.enterprise'] = $databaseConfig;
        file_put_contents($this->runtime.'/conversation-browser.json', json_encode(['config' => $config, 'area' => $this->area,
            'user_id' => (string) $this->user->id, 'tenant' => $this->tenant->slug, 'tenant_id' => (string) $this->tenant->id,
            'project' => $this->project, 'fake_mode' => 'success'], JSON_THROW_ON_ERROR));
        chmod($this->runtime.'/conversation-browser.json', 0600);
        echo json_encode(['runtime' => $this->runtime, 'area' => $this->area, 'tenant' => $this->tenant->slug,
            'user_id' => (string) $this->user->id, 'tenant_id' => (string) $this->tenant->id, 'project' => $this->project, 'database' => $database], JSON_THROW_ON_ERROR);
    }
}

(new ConversationBrowserFixture('test_browser_fixture'))->prepare();
