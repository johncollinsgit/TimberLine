<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ReviewerWorkspaceScheduledAuthority;
use App\Proposals\ReviewerWorkspaceScheduledCleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Pure fictional pins/config only. No admitted principal, Kernel, runtime, account or DB. */
final class ReviewerWorkspaceScheduledCleanupConfigurationTest extends TestCase
{
    public static function pins(): array
    {
        return ['schema_version' => 1, 'purpose' => 'proposal_workspace_cleanup', 'runtime_root_sha256' => str_repeat('a', 64),
            'process_uid' => 1000, 'process_gid' => 1000, 'client_id' => '11000000-0000-4000-8000-000000000001',
            'silo_id' => '11000000-0000-4000-8000-000000000002', 'storage_id' => '11000000-0000-4000-8000-000000000003',
            'tenant_id' => 17, 'anchor_area_id' => '11000000-0000-4000-8000-000000000004', 'anchor_marker_sha256' => str_repeat('b', 64),
            'context_hash' => str_repeat('c', 64), 'database' => 'FICTIONAL_owner', 'server_uuid' => '11000000-0000-4000-8000-000000000005',
            'build_id' => 'fictional-source-only', 'source_hash' => str_repeat('d', 64), 'composer_sha256' => str_repeat('e', 64),
            'authorizing_owner_id' => 11, 'valid_from' => '2026-10-24T00:00:00Z', 'expires_at' => '2026-11-01T00:00:00Z',
            'limit' => 25, 'max_run_seconds' => 30, 'kinds' => ['comments', 'graph'], 'editions' => [
                ['version_id' => '12000000-0000-4000-8000-000000000001', 'version_hash' => str_repeat('f', 64), 'workspace_id' => '13000000-0000-4000-8000-000000000001']],
            'previous_policy_sha256' => ['graph' => null, 'comments' => null]];
    }

    public function test_pins_are_closed_typed_finite_and_never_a_parsed_identity(): void
    {
        $p = self::pins();
        $this->assertSame($p, ReviewerWorkspaceScheduledAuthority::parse(json_encode($p)));
        foreach (['[]', '{}', 'null', '{', str_repeat('x', 12289)] as $bad) {
            $this->assertNull(ReviewerWorkspaceScheduledAuthority::parse($bad));
        }
        foreach (['actor_type' => 'real_proposal_owner', 'purpose' => 'paid_ai', 'process_uid' => '1000', 'tenant_id' => 0,
            'limit' => 26, 'max_run_seconds' => 0, 'expires_at' => $p['valid_from'], 'source_hash' => [], 'authorizing_owner_id' => '11',
            'kinds' => ['quarantine'], 'editions' => [], 'previous_policy_sha256' => 'bad'] as $key => $value) {
            $bad = $p;
            $bad[$key] = $value;
            $this->assertNull(ReviewerWorkspaceScheduledAuthority::parse(json_encode($bad)));
        }
        $bad = $p;
        $bad['editions'][] = $p['editions'][0];
        $this->assertNull(ReviewerWorkspaceScheduledAuthority::parse(json_encode($bad)));
        $bad['editions'][1]['version_id'] = '12000000-0000-4000-8000-000000000002';
        $this->assertNull(ReviewerWorkspaceScheduledAuthority::parse(json_encode($bad)), 'Duplicate workspace is not a new grant.');
        foreach ([null, [], ['graph' => null], ['graph' => null, 'comments' => null, 'extra' => null], ['graph' => 1, 'comments' => null]] as $previous) {
            $bad = $p;
            $bad['previous_policy_sha256'] = $previous;
            $this->assertNull(ReviewerWorkspaceScheduledAuthority::parse(json_encode($bad)));
        }
        $p['previous_policy_sha256'] = ['graph' => str_repeat('1', 64), 'comments' => str_repeat('2', 64)];
        $this->assertSame($p, ReviewerWorkspaceScheduledAuthority::parse(json_encode($p)));
        $r = new \ReflectionClass(ReviewerWorkspaceScheduledAuthority::class);
        $this->assertTrue($r->getConstructor()->isPrivate());
        $this->assertTrue($r->getMethod('__clone')->isPrivate());
    }

    public function test_per_kind_partial_rotation_cannot_reset_missing_or_foreign_cursor_lineage(): void
    {
        $previousContainer = \Illuminate\Container\Container::getInstance();
        // Only the framework's abort exception adapter; no bootstrap, principal, DB or lock substitute.
        \Illuminate\Container\Container::setInstance(new \Illuminate\Foundation\Application(dirname(__DIR__, 3)));
        try {
            $context = str_repeat('a', 64);
            $p1 = str_repeat('1', 64);
            $p2 = str_repeat('2', 64);
            $p3 = str_repeat('3', 64);
            $scope = str_repeat('b', 64);
            $after = '13000000-0000-4000-8000-000000000001';
            $row = ['schema_version' => 1, 'context_hash' => $context, 'kind' => 'graph', 'policy_sha256' => $p2,
                'scope_sha256' => $scope, 'after' => $after, 'last' => ['run_id' => '14000000-0000-4000-8000-000000000001',
                    'committed_at' => '2026-10-24T00:00:00Z', 'processed' => 1, 'eligible' => 0, 'erased' => 0,
                    'wrapped' => false, 'previous_policy_sha256' => $p1]];
            $previous = ['graph' => $p2, 'comments' => $p1];
            $this->assertSame(['after' => $after, 'previous' => $p2], ReviewerWorkspaceScheduledCleanup::resume($row, $context, 'graph', $p3, $scope, $previous));
            $comments = $row;
            $comments['kind'] = 'comments';
            $comments['policy_sha256'] = $p1;
            $this->assertSame(['after' => $after, 'previous' => $p1], ReviewerWorkspaceScheduledCleanup::resume($comments, $context, 'comments', $p3, $scope, $previous));
            $this->assertSame(['after' => null, 'previous' => $p2], ReviewerWorkspaceScheduledCleanup::resume($row, $context, 'graph', $p3, str_repeat('c', 64), $previous));
            $current = $row;
            $current['policy_sha256'] = $p3;
            $this->assertSame(['after' => $after, 'previous' => null], ReviewerWorkspaceScheduledCleanup::resume($current, $context, 'graph', $p3, $scope, $previous));
            $this->assertSame(['after' => null, 'previous' => null], ReviewerWorkspaceScheduledCleanup::resume(null, $context, 'graph', $p3, $scope, ['graph' => null, 'comments' => $p1]));
            $bad = [[$row, 'graph', $scope, ['graph' => $p1, 'comments' => $p2]],
                [null, 'graph', $scope, $previous], [$current, 'graph', str_repeat('c', 64), $previous],
                [$row, 'comments', $scope, $previous]];
            foreach ($bad as [$cursor, $kind, $expectedScope, $predecessors]) {
                try {
                    ReviewerWorkspaceScheduledCleanup::resume($cursor, $context, $kind, $p3, $expectedScope, $predecessors);
                    $this->fail('Missing/wrong-kind/unknown predecessor or current-scope drift admitted.');
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $this->assertContains($e->getStatusCode(), [409, 503]);
                }
            }
        } finally {
            \Illuminate\Container\Container::setInstance($previousContainer);
        }
    }

    private function configuration(array $input): array
    {
        $script = <<<'PHP'
        $fixture=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
        function env($key,$default=null){global $fixture;return $fixture[$key]??$default;}
        function base_path($path=''){return $GLOBALS['argv'][1].'/'.$path;}
        require base_path('vendor/autoload.php');
        echo json_encode(require base_path('apps/enterprise/config/service_proposals.php'),JSON_THROW_ON_ERROR);
        PHP;
        $p = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 3)], dirname(__DIR__, 3), [], json_encode($input), 5);
        $p->mustRun();
        $this->assertSame('', $p->getErrorOutput());

        return json_decode($p->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_literal_new_gate_and_exact_digest_do_not_enable_other_features(): void
    {
        $base = ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'proposal_owner', 'SERVICE_PROPOSALS_OWNER_TENANT_ID' => '17',
            'SERVICE_PROPOSALS_WORKSPACE_CLEANUP_PINS_SHA256' => str_repeat('a', 64)];
        $this->assertFalse($this->configuration($base)['reviewer_workspace']['scheduled_cleanup']['enabled']);
        $on = $this->configuration($base + ['SERVICE_PROPOSALS_WORKSPACE_SCHEDULED_CLEANUP_ENABLED' => true]);
        $this->assertTrue($on['reviewer_workspace']['scheduled_cleanup']['enabled']);
        foreach (['enabled', 'writes_enabled', 'publish_enabled'] as $key) {
            $this->assertFalse($on[$key]);
        }
        foreach (['enabled', 'writes_enabled', 'initialization_enabled', 'financial_enabled', 'maintenance_enabled'] as $key) {
            $this->assertFalse($on['reviewer_workspace'][$key]);
        }
        $this->assertFalse($on['reviewer_ai']['enabled']);
        foreach ([['SERVICE_PROPOSALS_WORKSPACE_SCHEDULED_CLEANUP_ENABLED' => 'true'], ['SERVICE_PROPOSALS_WORKSPACE_CLEANUP_PINS_SHA256' => []],
            ['SERVICE_PROPOSALS_WORKSPACE_CLEANUP_PINS_SHA256' => ''], ['SERVICE_PROPOSALS_RUNTIME_PURPOSE' => 'campus'],
            ['SERVICE_PROPOSALS_OWNER_TENANT_ID' => '']] as $bad) {
            $this->assertFalse($this->configuration(array_replace($base, ['SERVICE_PROPOSALS_WORKSPACE_SCHEDULED_CLEANUP_ENABLED' => true], $bad))['reviewer_workspace']['scheduled_cleanup']['enabled']);
        }
    }
}
