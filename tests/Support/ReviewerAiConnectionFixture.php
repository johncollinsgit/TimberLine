<?php

namespace Tests\Support;

use App\Proposals\CampusReviewManifest;
use App\Proposals\ReviewerAiConnection;
use App\Proposals\Version;
use Illuminate\Support\Str;

abstract class ReviewerAiConnectionFixture extends ReviewerAiFixture
{
    protected function configuredConnection(): array
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

        return ['version' => $version, 'manifest' => $manifest, 'browser_hash' => str_repeat('d', 64)];
    }
}
