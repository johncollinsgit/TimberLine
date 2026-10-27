<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ReviewerWorkspaceRuntimeConfiguration;
use App\Proposals\ReviewerWorkspaceSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class IndependentWorkspaceRuntimeConfigurationTest extends TestCase
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

    public function test_independent_parser_bounds_closed_shapes_and_actual_consumed_workspace_schema_match(): void
    {
        $base = $this->approval();
        $p = array_values($base['policies'])[0];
        $p['area_ids'] = array_map(fn ($i) => sprintf('%08d-0000-4000-8000-000000000001', $i), range(1, 8));
        $p['personas'] = ReviewerWorkspaceSchema::PERSONAS;
        sort($p['personas'], SORT_STRING);
        $base['policies'] = [ReviewerWorkspaceSchema::digest($p) => $p];
        $this->assertSame($p, (new ReviewerWorkspaceSchema)->policy($p));
        $this->assertSame($base, ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($base)));
        $changes = [['area_ids' => array_merge($p['area_ids'], ['09000000-0000-4000-8000-000000000001'])], ['personas' => []], ['personas' => ['demo_manager', 'demo_manager']], ['allow_financial' => 1], ['schema_version' => 1.0], ['max_role' => 'owner'], ['unexpected' => true], ['area_ids' => ['../area']]];
        foreach ($changes as $change) {
            $bad = $base;
            $bp = array_replace($p, $change);
            $bad['policies'] = [ReviewerWorkspaceSchema::digest($bp) => $bp];
            $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($bad, JSON_PRESERVE_ZERO_FRACTION)));
        }
        $many = $base;
        $many['policies'] = [];
        for ($i = 1; $i <= 11; $i++) {
            $pp = $p;
            $pp['area_ids'] = [sprintf('%08d-0000-4000-8000-000000000001', $i)];
            $many['policies'][ReviewerWorkspaceSchema::digest($pp)] = $pp;
            if ($i === 10) {
                $this->assertNotNull(ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($many)));
            }
        }
        $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($many)));
        foreach (['policies', 'collaboration_policies'] as $group) {
            foreach ([null, true, 'map', [[1]]] as $badValue) {
                $bad = $base;
                $bad[$group] = $badValue;
                $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($bad, JSON_PRESERVE_ZERO_FRACTION)));
            }
        }
        $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse(str_repeat(' ', 24001).json_encode($base)));
        $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse('{"schema_version":'));
    }

    public function test_independent_every_gate_is_separate_and_bad_purpose_keeps_anchor_unavailable(): void
    {
        $base = $this->approval();
        $env = ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'proposal_owner', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => '17', 'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON' => json_encode($base)];
        $gates = ['enabled', 'writes_enabled', 'initialization_enabled', 'financial_enabled'];
        foreach ($gates as $on) {
            $c = $this->config($env + ['SERVICE_PROPOSALS_REVIEWER_WORKSPACE_'.strtoupper($on) => true]);
            foreach ($gates as $gate) {
                $this->assertSame($gate === $on, $c['workspace'][$gate]);
            }$this->assertSame($base['anchor_area_id'], $c['workspace']['anchor_area_id']);
            $this->assertFalse($c['workspace']['collaboration']['enabled']);
            $this->assertFalse($c['ai']);
        }
        foreach (['enabled', 'comments_write_enabled'] as $on) {
            $c = $this->config($env + ['SERVICE_PROPOSALS_REVIEWER_COLLABORATION_'.strtoupper($on) => true]);
            foreach (['enabled', 'comments_write_enabled'] as $gate) {
                $this->assertSame($gate === $on, $c['workspace']['collaboration'][$gate]);
            }foreach ($gates as $gate) {
                $this->assertFalse($c['workspace'][$gate]);
            }
        }
        foreach (['campus', 'regular', '', null] as $purpose) {
            $c = $this->config(array_replace($env, ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => $purpose, 'SERVICE_PROPOSALS_REVIEWER_WORKSPACE_ENABLED' => true, 'SERVICE_PROPOSALS_REVIEWER_COLLABORATION_COMMENTS_WRITE_ENABLED' => true]));
            $this->assertNull($c['workspace']['anchor_area_id']);
            $this->assertFalse($c['workspace']['enabled']);
            $this->assertFalse($c['workspace']['collaboration']['comments_write_enabled']);
        }
        $c = $this->config($env);
        $this->assertSame($base['anchor_area_id'], $c['workspace']['anchor_area_id']);
        $this->assertSame($base['anchor_marker_sha256'], $c['workspace']['anchor_marker_sha256']);
        foreach ($gates as $gate) {
            $this->assertFalse($c['workspace'][$gate]);
        }
    }

    public function test_independent_comments_types_digest_and_upload_authority_close(): void
    {
        $base = $this->approval();
        $p = array_values($base['collaboration_policies'])[0];
        foreach ([['comments_enabled' => false], ['comments_enabled' => 1], ['uploads_enabled' => true], ['write_personas' => ['executive']], ['write_personas' => ['worker', 'worker']], ['write_personas' => ['secret' => 'worker']], ['schema_version' => true], ['scanner_endpoint' => 'https://invalid']] as $change) {
            $bad = $base;
            $pp = array_replace($p, $change);
            $bad['collaboration_policies'] = [ReviewerWorkspaceSchema::digest($pp) => $pp];
            $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($bad, JSON_PRESERVE_ZERO_FRACTION)));
        }
        $empty = $base;
        $pp = array_replace($p, ['write_personas' => []]);
        $empty['collaboration_policies'] = [ReviewerWorkspaceSchema::digest($pp) => $pp];
        $this->assertSame($empty, ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($empty)));
        $bad = $base;
        $bad['collaboration_policies'][str_repeat('f', 64)] = $p;
        $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($bad, JSON_PRESERVE_ZERO_FRACTION)));
        $bad = $base;
        $bad['schema_version'] = '1';
        $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($bad, JSON_PRESERVE_ZERO_FRACTION)));
        $bad = $base;
        $bad['secret'] = 'unexpected';
        $this->assertNull(ReviewerWorkspaceRuntimeConfiguration::parse(json_encode($bad, JSON_PRESERVE_ZERO_FRACTION)));
    }
}
