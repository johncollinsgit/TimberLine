<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ReviewerLegacyCaptureConfiguration as Capture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Independent config entry points and standard-library-only parser; all values are fictional nonsecret fixtures. */
final class ReviewerLegacyCaptureConfigurationTest extends TestCase
{
    private const VERSION = '11000000-0000-4000-8000-000000000001';

    private function row(): array
    {
        return ['tenant_id' => 17, 'client_id' => '21000000-0000-4000-8000-000000000001', 'silo_id' => '31000000-0000-4000-8000-000000000001',
            'storage_id' => '41000000-0000-4000-8000-000000000001', 'version_hash' => str_repeat('a', 64), 'manifest_sha256' => str_repeat('b', 64),
            'source_hash' => str_repeat('c', 64), 'build_id' => 'FICTIONAL:config-fixture-v1', 'module_sha256' => str_repeat('d', 64), 'expires_at' => '2028-02-29T12:34:56Z'];
    }

    private function text(?array $row = null): string
    {
        return json_encode([self::VERSION => $row ?? $this->row()], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function context(): array
    {
        $row = $this->row();

        return ['purpose' => 'proposal_owner', 'owner_workspaces' => [17 => 'enterprise'], 'owner_client_id' => $row['client_id'], 'owner_silo_id' => $row['silo_id'],
            'runtime_client_id' => $row['client_id'], 'runtime_silo_id' => $row['silo_id'], 'runtime_storage_id' => $row['storage_id']];
    }

    private function environment(): array
    {
        $row = $this->row();

        return ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'proposal_owner', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => '17',
            'SERVICE_PROPOSALS_OWNER_CLIENT_ID' => $row['client_id'], 'SERVICE_PROPOSALS_OWNER_SILO_ID' => $row['silo_id'],
            'ENTERPRISE_CLIENT_ID' => $row['client_id'], 'ENTERPRISE_SILO_ID' => $row['silo_id'], 'ENTERPRISE_CLIENT_STORAGE_ID' => $row['storage_id'],
            'SERVICE_PROPOSALS_LEGACY_CAPTURE_ENABLED' => true, 'SERVICE_PROPOSALS_LEGACY_CAPTURE_APPROVALS_JSON' => $this->text()];
    }

    private function configuration(array $environment, bool $enterprise = true): array
    {
        $script = <<<'PHP'
        $fixture = json_decode(stream_get_contents(STDIN), true, 12, JSON_THROW_ON_ERROR);
        function env($key, $default = null) {global $fixture; return array_key_exists($key, $fixture) ? $fixture[$key] : $default;}
        function base_path($path = '') {return $GLOBALS['argv'][1].'/'.$path;}
        require base_path('vendor/autoload.php');
        $config = require base_path($argv[2]);
        // Serializability is needed for ordinary config caching; do not bootstrap/cache anything.
        $cached = unserialize(serialize($config), ['allowed_classes' => false]);
        if ($cached !== $config) throw new RuntimeException('Nonserializable configuration.');
        echo json_encode($config, JSON_THROW_ON_ERROR);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 3), $enterprise ? 'apps/enterprise/config/service_proposals.php' : 'config/service_proposals.php'],
            dirname(__DIR__, 3), [], json_encode($environment, JSON_THROW_ON_ERROR), 5);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
    }

    public function test_valid_closed_map_preserves_exact_values_and_reordered_pretty_json_without_normalization(): void
    {
        $this->assertSame([self::VERSION => $this->row()], Capture::parse($this->text()));
        $row = array_reverse($this->row(), true);
        $text = " \n".json_encode([self::VERSION => $row], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\t ";
        $this->assertSame([self::VERSION => $row], Capture::parse($text));
        $this->assertSame(str_repeat('d', 64), Capture::parse($text)[self::VERSION]['module_sha256']);
    }

    public function test_empty_absent_and_every_invalid_container_refuse_without_partial_or_previous_fallback(): void
    {
        foreach (['', '{}', ' { } '] as $empty) {
            $this->assertSame([], Capture::parse($empty));
        }
        foreach ([null, false, 1, [], new \stdClass, ' ', 'null', '[]', 'true', '1', '"text"', '{', '{"wrong":{}}'] as $bad) {
            $this->assertNull(Capture::parse($bad));
        }
        $this->assertNull(Capture::parse(json_encode([self::VERSION => $this->row(), '11000000-0000-4000-8000-000000000002' => $this->row()])));
    }

    public function test_exact4096_byte_limit_invalid_utf8_and_nested_depth_refuse_closed(): void
    {
        $valid = $this->text();
        $exact = $valid.str_repeat(' ', 4096 - strlen($valid));
        $this->assertSame(4096, strlen($exact));
        $this->assertSame([self::VERSION => $this->row()], Capture::parse($exact));
        $this->assertNull(Capture::parse($exact.' '));
        $this->assertNull(Capture::parse(str_replace('FICTIONAL', "\xff", $valid)));
        $row = $this->row();
        $row['build_id'] = [[['untrusted']]];
        $this->assertNull(Capture::parse($this->text($row)));
        $this->assertSame(4096, Capture::MAX_JSON_BYTES);
    }

    public function test_closed_fields_reject_credentials_raw_data_missing_fields_and_wrong_scalar_types(): void
    {
        foreach (array_keys($this->row()) as $key) {
            $row = $this->row();
            unset($row[$key]);
            $this->assertNull(Capture::parse($this->text($row)), $key);
        }
        foreach (['api_key', 'token', 'password', 'raw', 'reviewer_name', 'enabled'] as $key) {
            $this->assertNull(Capture::parse($this->text($this->row() + [$key => 'UNTRUSTED'])));
        }
        foreach ([0, -1, true, 17.0, '17', null, [], PHP_INT_MAX.'0'] as $bad) {
            $this->assertNull(Capture::parse($this->text(array_replace($this->row(), ['tenant_id' => $bad]))));
        }
        foreach (['client_id', 'silo_id', 'storage_id'] as $key) {
            foreach ([false, 1, null, [], 'BAD', 'A1000000-0000-4000-8000-000000000001', '21000000-0000-0000-8000-000000000001', '21000000-0000-4000-7000-000000000001'] as $bad) {
                $this->assertNull(Capture::parse($this->text(array_replace($this->row(), [$key => $bad]))));
            }
        }
        foreach (['version_hash', 'manifest_sha256', 'source_hash', 'module_sha256'] as $key) {
            foreach ([false, null, [], str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65)] as $bad) {
                $this->assertNull(Capture::parse($this->text(array_replace($this->row(), [$key => $bad]))));
            }
        }
        foreach (['', '_bad', 'x y', 'réview', str_repeat('a', 121), null, true] as $bad) {
            $this->assertNull(Capture::parse($this->text(array_replace($this->row(), ['build_id' => $bad]))));
        }
    }

    public function test_utc_is_exact_real_calendar_shape_not_time_or_permission_authority(): void
    {
        foreach (['2026-02-29T00:00:00Z', '2028-02-30T00:00:00Z', '2028-13-01T00:00:00Z', '2028-01-01T24:00:00Z', '2028-01-01T00:00:60Z',
            '2028-02-29T12:34:56+00:00', '2028-02-29T12:34:56.000Z', 'tomorrow', '2028-02-29 12:34:56Z', null, 1] as $bad) {
            $this->assertNull(Capture::parse($this->text(array_replace($this->row(), ['expires_at' => $bad]))));
        }
        // Past shape is retained, NEVER a fresh runtime permission. Existing endpoint expiry checks remain mandatory.
        $past = array_replace($this->row(), ['expires_at' => '2000-01-01T00:00:00Z']);
        $this->assertSame([self::VERSION => $past], Capture::parse($this->text($past)));
    }

    public function test_duplicate_decoded_row_and_top_level_keys_including_escaped_aliases_are_rejected(): void
    {
        $row = json_encode($this->row(), JSON_THROW_ON_ERROR);
        foreach (['"tenant_id":17,"tenant_id":18', '"tenant_id":17,"\\u0074enant_id":18'] as $pair) {
            $text = str_replace('"tenant_id":17', $pair, $this->text());
            $this->assertNull(Capture::parse($text));
        }
        foreach ([self::VERSION, '\\u0031'.substr(self::VERSION, 1)] as $alias) {
            $this->assertNull(Capture::parse('{"'.self::VERSION.'":'.$row.',"'.$alias.'":'.$row.'}'));
        }
        $escaped = str_replace('"tenant_id"', '"\\u0074enant_id"', $this->text());
        $this->assertSame([self::VERSION => $this->row()], Capture::parse($escaped));
    }

    public function test_configuration_requires_literal_true_and_matching_closed_owner_context_but_retains_valid_metadata_while_off(): void
    {
        $expected = [self::VERSION => $this->row()];
        foreach ([false, 'true', 'false', 1, 0, null, []] as $flag) {
            $this->assertSame(['enabled' => false, 'approvals' => $expected], Capture::configuration($flag, $this->text(), $this->context()));
        }
        $this->assertSame(['enabled' => true, 'approvals' => $expected], Capture::configuration(true, $this->text(), $this->context()));
        foreach (['', '{}'] as $empty) {
            $this->assertSame(['enabled' => false, 'approvals' => []], Capture::configuration(true, $empty, $this->context()));
        }
        $this->assertSame(['enabled' => false, 'approvals' => null], Capture::configuration(true, 'invalid', $this->context()));
        $this->assertSame(['enabled' => false, 'approvals' => []], Capture::configuration(true, 'invalid', array_replace($this->context(), ['purpose' => 'campus'])));
        foreach (array_keys($this->context()) as $key) {
            $ctx = $this->context();
            unset($ctx[$key]);
            $this->assertSame(['enabled' => false, 'approvals' => null], Capture::configuration(true, $this->text(), $ctx));
        }
        $this->assertSame(['enabled' => false, 'approvals' => null], Capture::configuration(true, $this->text(), $this->context() + ['raw' => 'UNTRUSTED']));
        foreach (['owner_client_id', 'owner_silo_id', 'runtime_client_id', 'runtime_silo_id', 'runtime_storage_id'] as $key) {
            $ctx = array_replace($this->context(), [$key => '51000000-0000-4000-8000-000000000001']);
            $this->assertSame(['enabled' => false, 'approvals' => null], Capture::configuration(true, $this->text(), $ctx));
        }
        foreach ([[], [17 => 'regular'], [18 => 'enterprise'], [17 => 'enterprise', 18 => 'enterprise'], ['wrong' => 'enterprise']] as $bad) {
            $this->assertSame(['enabled' => false, 'approvals' => null], Capture::configuration(true, $this->text(), array_replace($this->context(), ['owner_workspaces' => $bad])));
        }
    }

    public function test_parser_runs_without_autoload_application_database_filesystem_network_or_clock_helpers(): void
    {
        $script = <<<'PHP'
        function app(...$args) {throw new RuntimeException('Forbidden helper');}
        function config(...$args) {throw new RuntimeException('Forbidden helper');}
        function env(...$args) {throw new RuntimeException('Forbidden helper');}
        function base_path(...$args) {throw new RuntimeException('Forbidden helper');}
        function storage_path(...$args) {throw new RuntimeException('Forbidden helper');}
        function now(...$args) {throw new RuntimeException('Forbidden helper');}
        require $argv[1];
        $text = stream_get_contents(STDIN);
        echo json_encode(\App\Proposals\ReviewerLegacyCaptureConfiguration::parse($text), JSON_THROW_ON_ERROR);
        PHP;
        // -n disables php.ini, no Composer/Laravel boot. All forbidden framework-helper sentinels are fixed; no application/container is created.
        $process = new Process([PHP_BINARY, '-n', '-r', $script, dirname(__DIR__, 3).'/app/Proposals/ReviewerLegacyCaptureConfiguration.php'],
            dirname(__DIR__, 3), [], $this->text(), 5);
        $process->mustRun();
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame([self::VERSION => $this->row()], json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR));
    }

    public function test_actual_root_config_never_admits_capture_env_and_enterprise_matches_exact_runtime_metadata_without_other_gate_changes(): void
    {
        $env = $this->environment();
        $ordinary = $this->configuration($env, false);
        $this->assertSame(['enabled' => false, 'approvals' => []], $ordinary['legacy_capture']);
        $on = $this->configuration($env);
        $this->assertSame(['enabled' => true, 'approvals' => [self::VERSION => $this->row()]], $on['legacy_capture']);
        $off = $this->configuration(array_replace($env, ['SERVICE_PROPOSALS_LEGACY_CAPTURE_ENABLED' => false]));
        $this->assertFalse($off['legacy_capture']['enabled']);
        $this->assertSame($on['legacy_capture']['approvals'], $off['legacy_capture']['approvals']);
        unset($on['legacy_capture'], $off['legacy_capture']);
        $this->assertSame($off, $on);
        $this->assertFalse($on['reviewer_ai']['enabled']);
        $this->assertFalse($on['reviewer_workspace']['enabled']);
        $this->assertSame([], $on['campus_review']['legacy_versions']);
        foreach ([['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'campus'], ['SERVICE_PROPOSALS_OWNER_TENANT_ID' => ''], ['SERVICE_PROPOSALS_OWNER_TENANT_ID' => '18'],
            ['SERVICE_PROPOSALS_OWNER_CLIENT_ID' => '51000000-0000-4000-8000-000000000001'], ['ENTERPRISE_CLIENT_STORAGE_ID' => '51000000-0000-4000-8000-000000000001'],
            ['SERVICE_PROPOSALS_LEGACY_CAPTURE_APPROVALS_JSON' => 'invalid'], ['SERVICE_PROPOSALS_LEGACY_CAPTURE_APPROVALS_JSON' => '{}'],
            ['SERVICE_PROPOSALS_LEGACY_CAPTURE_ENABLED' => 'true'], ['SERVICE_PROPOSALS_LEGACY_CAPTURE_ENABLED' => 1]] as $bad) {
            $this->assertFalse($this->configuration(array_replace($env, $bad))['legacy_capture']['enabled']);
        }
        $missing = $env;
        unset($missing['SERVICE_PROPOSALS_LEGACY_CAPTURE_ENABLED']);
        $this->assertFalse($this->configuration($missing)['legacy_capture']['enabled']);
    }
}
