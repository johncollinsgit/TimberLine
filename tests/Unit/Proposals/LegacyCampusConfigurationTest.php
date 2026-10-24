<?php

namespace Tests\Unit\Proposals;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Exercise actual config assembly in a separate process with only fictional env input. */
final class LegacyCampusConfigurationTest extends TestCase
{
    private function configuration(array $environment): array
    {
        $script = <<<'PHP'
        $fixture = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
        function env($key, $default = null) {global $fixture; return $fixture[$key] ?? $default;}
        function base_path($path = '') {return $GLOBALS['argv'][1].'/'.$path;}
        require base_path('vendor/autoload.php');
        $config = require base_path('apps/enterprise/config/service_proposals.php');
        echo json_encode(['legacy'=>$config['campus_review']['legacy_versions'], 'ai'=>$config['reviewer_ai']['enabled'],
            'workspace'=>$config['reviewer_workspace']['enabled']], JSON_THROW_ON_ERROR);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 3)], dirname(__DIR__, 3), [], json_encode($environment, JSON_THROW_ON_ERROR), 5);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
    }

    private function environment(array $row): array
    {
        return ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'proposal_owner', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => '17',
            'SERVICE_PROPOSALS_CAMPUS_REVIEW_LEGACY_VERSIONS_JSON' => json_encode(['01000000-0000-7000-8000-000000000001' => $row], JSON_THROW_ON_ERROR)];
    }

    public function test_empty_default_and_exact_owner_map_never_enable_other_features(): void
    {
        $this->assertSame(['legacy' => [], 'ai' => false, 'workspace' => false], $this->configuration([]));
        $row = ['tenant_id' => 17, 'content_hash' => str_repeat('a', 64), 'renderer' => 'lees-mcrae-v5'];
        $this->assertSame(['legacy' => ['01000000-0000-7000-8000-000000000001' => $row], 'ai' => false, 'workspace' => false], $this->configuration($this->environment($row)));
    }

    public function test_wrong_tenant_authority_shape_types_and_unbounded_config_remain_invalid(): void
    {
        $row = ['tenant_id' => 17, 'content_hash' => str_repeat('a', 64), 'renderer' => 'lees-mcrae-v5'];
        $valid = $this->environment($row);
        $cases = [
            $this->environment($row + ['enabled' => true]),
            $this->environment(array_replace($row, ['tenant_id' => '17'])),
            $this->environment(array_replace($row, ['tenant_id' => 18])),
            $this->environment(array_replace($row, ['content_hash' => 'guessed'])),
            $this->environment(array_replace($row, ['renderer' => 'current'])),
            array_replace($valid, ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'campus']),
            array_replace($valid, ['SERVICE_PROPOSALS_OWNER_TENANT_ID' => '']),
        ];
        foreach (['invalid JSON', str_repeat('x', 12001), 'true', '[{}]', '{"unknown":{}}'] as $input) {
            $cases[] = array_replace($valid, ['SERVICE_PROPOSALS_CAMPUS_REVIEW_LEGACY_VERSIONS_JSON' => $input]);
        }
        foreach ($cases as $case) {
            $this->assertNull($this->configuration($case)['legacy']);
        }
    }
}
