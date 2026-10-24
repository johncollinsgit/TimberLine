<?php

namespace App\Proposals;

use App\Enterprise\Conversation\Connection;
use Illuminate\Support\Str;

/** Exact operator-approved private reviewer connection. No native account, inherited credential or provider discovery. */
final class ReviewerAiConnection
{
    public const MODEL = 'gpt-5.4-mini-2026-03-17';

    public const FIELDS = ['schema_version', 'approval_id', 'client_id', 'silo_id', 'mount', 'tenant_id', 'area_id', 'ledger_id',
        'prior_spend_verified', 'prior_spend_microusd', 'prior_spend_evidence_hash', 'inventory_evidence_hash',
        'project_id', 'organization_id', 'model', 'model_context_tokens', 'max_output_tokens',
        'input_ceiling_microusd_per_million', 'output_ceiling_microusd_per_million', 'per_call_overhead_microusd',
        'model_context_evidence', 'rates_evidence', 'version_id', 'version_hash', 'build_id', 'source_hash',
        'valid_until', 'credential_valid_until', 'credential_sha256'];

    public const OFF_FLAGS = ['enterprise.enabled', 'enterprise.writes_enabled', 'enterprise.trial.enabled', 'enterprise.conversation.enabled',
        'enterprise.intelligence.enabled', 'enterprise.voice_generation_enabled', 'enterprise.mailbox.send_enabled', 'enterprise.gmail.send_enabled',
        'enterprise.trial.channels.ai.enabled', 'enterprise.trial.channels.sms.enabled', 'enterprise.trial.channels.email.enabled'];

    public function __construct(private ReviewerAiStore $store) {}

    /** Closed nonsecret fields only. Unknown input is rejected by settings(), never silently accepted into approval. */
    public static function approvalFields(array $config): array
    {
        $fields = array_intersect_key($config, array_flip(self::FIELDS));
        ksort($fields, SORT_STRING);

        return $fields;
    }

    private function expiry(mixed $text): \DateTimeImmutable
    {
        $date = is_string($text) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $text)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $text, new \DateTimeZone('UTC')) : false;
        $errors = \DateTimeImmutable::getLastErrors();
        abort_unless($date && ($errors === false || $errors['warning_count'] === 0 && $errors['error_count'] === 0)
            && $date->format('Y-m-d\TH:i:s\Z') === $text, 503, 'The reviewer AI approval expiry is invalid.');

        return $date;
    }

    public function settings(array $scope): array
    {
        $this->store->tenant(); // Pure existing-anchor/ledger read. Never initializes or requests ordinary Trial authority.
        foreach (self::OFF_FLAGS as $flag) {
            abort_if(config($flag), 503, 'All other Enterprise paid and native-write paths must remain disabled for this reviewer trial.');
        }
        $config = (array) config('service_proposals.reviewer_ai');
        $keys = array_keys($config);
        sort($keys, SORT_STRING);
        $expected = [...self::FIELDS, 'approval_sha256', 'api_key', 'ui_enabled', 'enabled'];
        sort($expected, SORT_STRING);
        abort_unless($keys === $expected && $config['schema_version'] === 1 && $config['ui_enabled'] === true && $config['enabled'] === true,
            503, 'An exact reviewer-only connection approval is required.');
        foreach (['approval_id', 'client_id', 'silo_id', 'area_id', 'ledger_id', 'version_id'] as $key) {
            abort_unless(is_string($config[$key]) && Str::isUuid($config[$key]), 503, 'The reviewer AI approval identity is invalid.');
        }
        foreach (['version_hash', 'source_hash', 'credential_sha256', 'approval_sha256', 'prior_spend_evidence_hash', 'inventory_evidence_hash'] as $key) {
            abort_unless(is_string($config[$key]) && preg_match('/^[a-f0-9]{64}$/D', $config[$key]), 503, 'The reviewer AI approval evidence is invalid.');
        }
        foreach (['model_context_evidence', 'rates_evidence'] as $key) {
            abort_unless(is_string($config[$key]) && trim($config[$key]) !== '' && strlen($config[$key]) <= 1000, 503,
                'Current model context and inclusive account cost evidence are required.');
        }
        $version = $scope['version'] ?? null;
        $manifest = $scope['manifest'] ?? null;
        abort_unless($version instanceof Version && is_array($manifest)
            && $config['client_id'] === config('enterprise_client.client_id') && $config['silo_id'] === config('enterprise_client.silo_id')
            && $config['mount'] === config('enterprise_runtime.base_path') && $config['mount'] === '/enterprise-owner'
            && is_int($config['tenant_id']) && $config['tenant_id'] === (int) $version->tenant_id
            && $config['area_id'] === $this->store->areaId() && $config['ledger_id'] === $this->store->ledgerId()
            && $version->id === $config['version_id'] && hash_equals($version->content_hash, $config['version_hash'])
            && ($manifest['build_id'] ?? null) === $config['build_id'] && ($manifest['source_hash'] ?? null) === $config['source_hash']
            && ($manifest['client_reference'] ?? null) === CampusReviewManifest::CLIENT_REFERENCE
            && is_string($config['build_id']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $config['build_id']),
            503, 'This exact private reviewer edition and owner runtime are not approved for AI.');
        abort_unless(is_string($config['project_id']) && preg_match('/^proj_[A-Za-z0-9_-]{1,100}$/D', $config['project_id'])
            && ($config['organization_id'] === null || is_string($config['organization_id']) && preg_match('/^org-[A-Za-z0-9_-]{1,100}$/D', $config['organization_id']))
            && $config['model'] === self::MODEL && $config['model_context_tokens'] === 400000, 503,
            'Only the exact approved dedicated project and dated model context are allowed.');
        foreach (['max_output_tokens' => [32, 800], 'input_ceiling_microusd_per_million' => [1, 1000000000],
            'output_ceiling_microusd_per_million' => [1, 1000000000], 'per_call_overhead_microusd' => [0, 1000000]] as $key => [$minimum, $maximum]) {
            abort_unless(is_int($config[$key]) && $config[$key] >= $minimum && $config[$key] <= $maximum, 503,
                'Inclusive account-specific provider cost ceilings must be approved.');
        }
        $expires = $this->expiry($config['valid_until']);
        $credentialExpires = $this->expiry($config['credential_valid_until']);
        abort_unless(now()->lt($expires) && now()->addDays(7)->gte($expires) && $expires <= $credentialExpires
            && $version->expires_at && $version->expires_at->gte($expires), 503, 'The reviewer AI approval, dedicated key or proposal has expired.');
        abort_unless(is_string($config['api_key']) && strlen($config['api_key']) >= 20 && strlen($config['api_key']) <= 512
            && ! preg_match('/\s/', $config['api_key']) && hash_equals($config['credential_sha256'], hash('sha256', $config['api_key'])),
            503, 'The dedicated reviewer credential does not match this approval.');
        $digest = hash('sha256', json_encode(self::approvalFields($config), JSON_THROW_ON_ERROR));
        abort_unless(hash_equals($config['approval_sha256'], $digest), 503, 'The exact reviewer AI approval changed.');
        $maximum = Connection::maximum($config);
        abort_unless($maximum > 0 && $maximum <= 5000000, 503, 'The full documented model context does not fit in the shared lifetime allowance.');

        return $config + ['settings_sha256' => $digest, 'maximum_microusd' => $maximum];
    }
}
