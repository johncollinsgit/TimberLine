<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ReviewerWorkspaceSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReviewerWorkspaceMaintenanceConfigurationTest extends TestCase
{
    private function approval(): array
    {
        $policy = ['schema_version' => 1, 'max_role' => 'editor', 'area_ids' => ['01000000-0000-4000-8000-000000000001'],
            'personas' => ['demo_manager', 'requester'], 'allow_financial' => false];
        $comments = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false, 'write_personas' => ['demo_manager']];

        return ['schema_version' => 1, 'anchor_area_id' => '02000000-0000-4000-8000-000000000001', 'anchor_marker_sha256' => str_repeat('a', 64),
            'policies' => [ReviewerWorkspaceSchema::digest($policy) => $policy], 'collaboration_policies' => [ReviewerWorkspaceSchema::digest($comments) => $comments]];
    }

    private function config(array $environment): array
    {
        $script = <<<'PHP'
        $fixture = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
        function env($key, $default = null) {global $fixture; return $fixture[$key] ?? $default;}
        function base_path($path = '') {return $GLOBALS['argv'][1].'/'.$path;}
        require base_path('vendor/autoload.php');
        $config = require base_path('apps/enterprise/config/service_proposals.php');
        echo json_encode(['workspace'=>$config['reviewer_workspace'], 'ai'=>$config['reviewer_ai']['enabled'],
            'legacy'=>$config['campus_review']['legacy_versions']], JSON_THROW_ON_ERROR);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 3)], dirname(__DIR__, 3), [], json_encode($environment, JSON_THROW_ON_ERROR), 5);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
    }

    public function test_maintenance_flag_is_independent_owner_only_and_metadata_is_retained_while_crud_off(): void
    {
        $approval = $this->approval();
        $env = ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'proposal_owner', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => '17', 'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON' => json_encode($approval)];
        $off = $this->config($env);
        $this->assertFalse($off['workspace']['maintenance_enabled']);
        $this->assertSame($approval['anchor_area_id'], $off['workspace']['anchor_area_id']);
        $env['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_MAINTENANCE_ENABLED'] = true;
        $on = $this->config($env);
        $this->assertTrue($on['workspace']['maintenance_enabled']);
        foreach (['enabled', 'writes_enabled', 'initialization_enabled', 'financial_enabled'] as $gate) {
            $this->assertFalse($on['workspace'][$gate]);
        }
        $this->assertFalse($on['workspace']['collaboration']['enabled']);
        $this->assertFalse($on['workspace']['collaboration']['comments_write_enabled']);
        $this->assertFalse($on['ai']);
        foreach ([['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'campus'], ['SERVICE_PROPOSALS_OWNER_TENANT_ID' => ''], ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON' => 'invalid']] as $bad) {
            $this->assertFalse($this->config(array_replace($env, $bad))['workspace']['maintenance_enabled']);
        }
        foreach ([1, 'true', 'false', null] as $bad) {
            $this->assertFalse($this->config(array_replace($env, ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_MAINTENANCE_ENABLED' => $bad]))['workspace']['maintenance_enabled']);
        }
    }
}
