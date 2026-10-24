<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ReviewerWorkspaceRuntimeConfiguration;
use App\Proposals\ReviewerWorkspaceSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReviewerWorkspaceRuntimeConfigurationTest extends TestCase
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

    public function test_reviewed_metadata_alone_never_activates_features_and_flags_need_exact_owner_context(): void
    {
        $approval = $this->approval();
        $environment = ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'proposal_owner', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => '17',
            'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON' => json_encode($approval, JSON_THROW_ON_ERROR)];
        $value = $this->config($environment);
        foreach (['enabled', 'writes_enabled', 'initialization_enabled', 'financial_enabled'] as $gate) {
            $this->assertFalse($value['workspace'][$gate]);
        }
        $this->assertFalse($value['workspace']['collaboration']['enabled']);
        $this->assertFalse($value['workspace']['collaboration']['comments_write_enabled']);
        $this->assertSame($approval['policies'], $value['workspace']['policies']);
        $this->assertFalse($value['ai']);
        $this->assertSame([], $value['legacy']);
        $environment['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_ENABLED'] = true;
        $environment['SERVICE_PROPOSALS_REVIEWER_COLLABORATION_ENABLED'] = true;
        $value = $this->config($environment);
        $this->assertTrue($value['workspace']['enabled']);
        $this->assertTrue($value['workspace']['collaboration']['enabled']);
        $this->assertFalse($value['workspace']['writes_enabled']);
        $this->assertFalse($value['workspace']['collaboration']['comments_write_enabled']);
        foreach (['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'campus', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => ''] as $key => $bad) {
            $this->assertFalse($this->config(array_replace($environment, [$key => $bad]))['workspace']['enabled']);
        }
        $this->assertFalse($this->config(array_replace($environment, ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_ENABLED' => 'true']))['workspace']['enabled']);
    }

    public function test_invalid_hash_shape_type_bounds_or_added_authority_fail_closed_without_provisioning(): void
    {
        $valid = $this->approval();
        $this->assertSame($valid, ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($valid)));
        $cases = [null, '', 'invalid', 'true', '[{}]', str_repeat('x', 24001)];
        $cases[] = json_encode($valid + ['enabled' => true]);
        $cases[] = json_encode(array_replace($valid, ['anchor_marker_sha256' => 'guessed']));
        $cases[] = json_encode(array_replace($valid, ['anchor_area_id' => 'not-an-anchor']));
        $bad = $valid;
        $hash = array_key_first($bad['policies']);
        $bad['policies'][$hash]['allow_financial'] = true;
        $cases[] = json_encode($bad);
        $bad = $valid;
        $comments = array_values($bad['collaboration_policies'])[0];
        $comments['uploads_enabled'] = true;
        $bad['collaboration_policies'] = [ReviewerWorkspaceSchema::digest($comments) => $comments];
        $cases[] = json_encode($bad);
        $bad = $valid;
        $policy = array_values($bad['policies'])[0];
        $policy['personas'] = ['requester', 'demo_manager'];
        $bad['policies'] = [ReviewerWorkspaceSchema::digest($policy) => $policy];
        $cases[] = json_encode($bad);
        foreach ($cases as $text) {
            $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse($text));
        }
        $value = $this->config(['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'proposal_owner', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => '17',
            'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON' => 'invalid', 'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_ENABLED' => true,
            'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_WRITES_ENABLED' => true, 'SERVICE_PROPOSALS_REVIEWER_COLLABORATION_ENABLED' => true]);
        $this->assertFalse($value['workspace']['enabled']);
        $this->assertFalse($value['workspace']['writes_enabled']);
        $this->assertFalse($value['workspace']['collaboration']['enabled']);
        $this->assertNull($value['workspace']['anchor_area_id']);
    }
}
