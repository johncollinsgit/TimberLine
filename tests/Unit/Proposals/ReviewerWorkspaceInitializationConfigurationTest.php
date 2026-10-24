<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ReviewerWorkspaceSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Pure fictional configuration assembly; no Kernel, credentials or DB. */
final class ReviewerWorkspaceInitializationConfigurationTest extends TestCase
{
    private function environment(): array
    {
        $workspace = ['schema_version' => 1, 'anchor_area_id' => '02000000-0000-4000-8000-000000000001', 'anchor_marker_sha256' => str_repeat('a', 64), 'policies' => [], 'collaboration_policies' => []];
        $review = ['schema_version' => 1, 'data_basis' => 'reviewed_current_edits', 'version_id' => '03000000-0000-4000-8000-000000000001',
            'version_hash' => str_repeat('b', 64), 'build_id' => 'fictional-config-only', 'source_hash' => str_repeat('c', 64),
            'workspace_id' => '04000000-0000-4000-8000-000000000001', 'descriptor_sha256' => str_repeat('d', 64), 'seed_sha256' => str_repeat('e', 64),
            'import_evidence_sha256' => str_repeat('f', 64), 'predecessor_version_id' => '03000000-0000-4000-8000-000000000002',
            'predecessor_version_hash' => str_repeat('1', 64), 'expires_at' => '2026-11-01T00:00:00Z'];

        return ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'proposal_owner', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => '17',
            'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON' => json_encode($workspace),
            'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_INITIALIZATION_APPROVALS_JSON' => json_encode((object) [ReviewerWorkspaceSchema::digest($review) => $review])];
    }

    private function configuration(array $environment): array
    {
        $script = <<<'PHP'
        $fixture = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
        function env($key, $default = null) {global $fixture; return $fixture[$key] ?? $default;}
        function base_path($path = '') {return $GLOBALS['argv'][1].'/'.$path;}
        require base_path('vendor/autoload.php');
        $config = require base_path('apps/enterprise/config/service_proposals.php');
        echo json_encode($config, JSON_THROW_ON_ERROR);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 3)], dirname(__DIR__, 3), [], json_encode($environment, JSON_THROW_ON_ERROR), 5);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_only_exact_owner_metadata_and_literal_flag_enable_initializer_without_enabling_other_paths(): void
    {
        $env = $this->environment();
        $off = $this->configuration($env);
        $this->assertFalse($off['reviewer_workspace']['owner_initialization']['enabled']);
        $this->assertCount(1, $off['reviewer_workspace']['owner_initialization']['approvals']);
        $on = $this->configuration($env + ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_OWNER_INITIALIZATION_ENABLED' => true]);
        $this->assertTrue($on['reviewer_workspace']['owner_initialization']['enabled']);
        foreach (['enabled', 'writes_enabled', 'publish_enabled'] as $flag) {
            $this->assertFalse($on[$flag]);
        }
        foreach (['enabled', 'writes_enabled', 'initialization_enabled', 'financial_enabled', 'maintenance_enabled'] as $flag) {
            $this->assertFalse($on['reviewer_workspace'][$flag]);
        }
        $this->assertFalse($on['reviewer_ai']['enabled']);
        $this->assertFalse($on['reviewer_workspace']['collaboration']['enabled']);
        $this->assertSame([], $on['campus_review']['legacy_versions']);
    }

    public function test_missing_malformed_wrong_runtime_or_nonboolean_flag_remains_closed(): void
    {
        $env = $this->environment() + ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_OWNER_INITIALIZATION_ENABLED' => true];
        foreach ([['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'campus'], ['SERVICE_PROPOSALS_OWNER_TENANT_ID' => ''],
            ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON' => 'invalid'],
            ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_INITIALIZATION_APPROVALS_JSON' => ''],
            ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_INITIALIZATION_APPROVALS_JSON' => '[]'],
            ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_INITIALIZATION_APPROVALS_JSON' => '{}'],
            ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_INITIALIZATION_APPROVALS_JSON' => '{invalid'],
            ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_INITIALIZATION_APPROVALS_JSON' => str_repeat('x', 24001)]] as $bad) {
            $this->assertFalse($this->configuration(array_replace($env, $bad))['reviewer_workspace']['owner_initialization']['enabled']);
        }
        foreach ([1, 'true', 'false', null] as $bad) {
            $this->assertFalse($this->configuration(array_replace($env, ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_OWNER_INITIALIZATION_ENABLED' => $bad]))['reviewer_workspace']['owner_initialization']['enabled']);
        }
    }
}
