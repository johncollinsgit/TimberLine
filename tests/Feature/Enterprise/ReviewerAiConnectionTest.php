<?php

namespace Tests\Feature\Enterprise;

use App\Proposals\CampusReviewManifest;
use App\Proposals\ReviewerAiConnection;
use App\Proposals\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReviewerAiFixture;

final class ReviewerAiConnectionTest extends ReviewerAiFixture
{
    private function configured(): array
    {
        $version = new Version;
        $version->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'content_hash' => str_repeat('b', 64), 'expires_at' => now()->addDays(7)]);
        $manifest = ['client_reference' => CampusReviewManifest::CLIENT_REFERENCE, 'build_id' => 'fictional-ai-test', 'source_hash' => str_repeat('c', 64)];
        $config = config('service_proposals.reviewer_ai') + ['schema_version' => 1, 'approval_id' => (string) Str::uuid(),
            'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'), 'mount' => '/enterprise-owner',
            'project_id' => 'proj_FICTIONAL_TEST_ONLY', 'organization_id' => null, 'model' => ReviewerAiConnection::MODEL, 'model_context_tokens' => 400000,
            'max_output_tokens' => 800, 'input_ceiling_microusd_per_million' => 750000, 'output_ceiling_microusd_per_million' => 4500000,
            'per_call_overhead_microusd' => 0, 'model_context_evidence' => 'FICTIONAL TEST model context evidence', 'rates_evidence' => 'FICTIONAL TEST inclusive account rate evidence',
            'version_id' => $version->id, 'version_hash' => $version->content_hash, 'build_id' => $manifest['build_id'], 'source_hash' => $manifest['source_hash'],
            'valid_until' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s\Z'), 'credential_valid_until' => now()->addDays(7)->utc()->format('Y-m-d\TH:i:s\Z'),
            'api_key' => 'sk-FICTIONAL-NEVER-VALID-TEST-ONLY'];
        $config['credential_sha256'] = hash('sha256', $config['api_key']);
        $config['approval_sha256'] = hash('sha256', json_encode(ReviewerAiConnection::approvalFields($config), JSON_THROW_ON_ERROR));
        config(['service_proposals.reviewer_ai' => $config]);

        return ['version' => $version, 'manifest' => $manifest];
    }

    private function denied(callable $call): void
    {
        try {
            $call();
            $this->fail('Changed or unapproved reviewer connection must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringNotContainsString('sk-FICTIONAL', $exception->getMessage());
        }
    }

    public function test_exact_connection_reads_existing_ledger_without_database_writes_or_native_authority(): void
    {
        $scope = $this->configured();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $settings = app(ReviewerAiConnection::class)->settings($scope);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']);
        }
        $this->assertSame(303600, $settings['maximum_microusd']);
        $this->assertSame($settings['approval_sha256'], $settings['settings_sha256']);
        $fields = ReviewerAiConnection::approvalFields($settings);
        $this->assertArrayNotHasKey('api_key', $fields);
        $this->assertArrayNotHasKey('enabled', $fields);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('team_area_members')->count());
    }

    public function test_unapproved_field_changes_wrong_edition_or_native_paid_flags_cannot_reuse_the_approval(): void
    {
        $scope = $this->configured();
        $original = config('service_proposals.reviewer_ai');
        $changes = ['model' => 'gpt-5.4-mini', 'model_context_tokens' => 399999, 'tenant_id' => (string) $this->tenant->id,
            'mount' => '/enterprise-campus', 'project_id' => 'proj_another', 'api_key' => 'sk-FICTIONAL-OTHER-TEST-ONLY', 'schema_version' => '1',
            'input_ceiling_microusd_per_million' => 1, 'max_output_tokens' => 801, 'version_id' => (string) Str::uuid(),
            'version_hash' => str_repeat('d', 64), 'build_id' => 'other-build', 'source_hash' => str_repeat('d', 64), 'unexpected' => true];
        foreach ($changes as $key => $value) {
            config(['service_proposals.reviewer_ai' => array_replace($original, [$key => $value])]);
            $this->denied(fn () => app(ReviewerAiConnection::class)->settings($scope));
        }
        config(['service_proposals.reviewer_ai' => $original]);
        foreach (ReviewerAiConnection::OFF_FLAGS as $flag) {
            config([$flag => true]);
            $this->denied(fn () => app(ReviewerAiConnection::class)->settings($scope));
            config([$flag => false]);
        }
        $this->assertSame(0, DB::table('team_operation_records')->where('kind', 'ent_trial_charge')->count());
    }

    public function test_even_rehashed_approval_cannot_exceed_actual_key_proposal_or_seven_day_expiry_and_full_context_cap(): void
    {
        $scope = $this->configured();
        $original = config('service_proposals.reviewer_ai');
        foreach ([['valid_until' => now()->subSecond()->utc()->format('Y-m-d\TH:i:s\Z')],
            ['valid_until' => now()->addDays(8)->utc()->format('Y-m-d\TH:i:s\Z')],
            ['credential_valid_until' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')],
            ['valid_until' => '2026-02-30T00:00:00Z'], ['valid_until' => '2030-01-01T00:00:00+00:00'],
            ['input_ceiling_microusd_per_million' => 1000000000], ['organization_id' => 'not-an-organization'],
            ['model_context_evidence' => ''], ['rates_evidence' => '']] as $changes) {
            $config = array_replace($original, $changes);
            $config['approval_sha256'] = hash('sha256', json_encode(ReviewerAiConnection::approvalFields($config), JSON_THROW_ON_ERROR));
            config(['service_proposals.reviewer_ai' => $config]);
            $this->denied(fn () => app(ReviewerAiConnection::class)->settings($scope));
        }
        config(['service_proposals.reviewer_ai' => $original]);
        $scope['version']->expires_at = now()->addDay();
        $this->denied(fn () => app(ReviewerAiConnection::class)->settings($scope));
    }

    public function test_config_loader_defaults_off_and_json_cannot_override_separate_flags_or_dedicated_key(): void
    {
        $repository = \Illuminate\Support\Env::getRepository();
        $names = ['SERVICE_PROPOSALS_REVIEWER_AI_APPROVAL_JSON', 'SERVICE_PROPOSALS_REVIEWER_AI_UI_ENABLED',
            'SERVICE_PROPOSALS_REVIEWER_AI_ENABLED', 'ENTERPRISE_PROPOSAL_REVIEW_AI_API_KEY'];
        $original = array_combine($names, array_map(fn ($name) => $repository->get($name), $names));
        $load = static fn () => (require base_path('config/service_proposals.php'))['reviewer_ai'];
        try {
            foreach ($names as $name) {
                $repository->clear($name);
            }
            $config = $load();
            $this->assertFalse($config['enabled']);
            $this->assertFalse($config['ui_enabled']);
            $this->assertSame('', $config['api_key']);
            $repository->set('SERVICE_PROPOSALS_REVIEWER_AI_APPROVAL_JSON', json_encode(['enabled' => true, 'ui_enabled' => true, 'api_key' => 'JSON-MUST-NOT-SUPPLY-A-KEY', 'tenant_id' => 42]));
            $config = $load();
            $this->assertFalse($config['enabled']);
            $this->assertFalse($config['ui_enabled']);
            $this->assertSame('', $config['api_key']);
            $this->assertSame(42, $config['tenant_id']);
            $repository->set('ENTERPRISE_PROPOSAL_REVIEW_AI_API_KEY', 'sk-FICTIONAL-DEDICATED-SLOT-ONLY');
            $this->assertSame('sk-FICTIONAL-DEDICATED-SLOT-ONLY', $load()['api_key']);
            foreach (['[1,2]', '{broken', str_repeat('x', 12001)] as $invalid) {
                $repository->set('SERVICE_PROPOSALS_REVIEWER_AI_APPROVAL_JSON', $invalid);
                $this->assertNull($load()['tenant_id']);
            }
        } finally {
            foreach ($original as $name => $value) {
                $value === null ? $repository->clear($name) : $repository->set($name, $value);
            }
        }
    }
}
