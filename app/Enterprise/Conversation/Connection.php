<?php

namespace App\Enterprise\Conversation;

use App\Enterprise\Provisioning\ClientSilo;
use App\Enterprise\Trial\TrialStore;
use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Exact, independently approved campus trial connection. Never falls back to retail/ordinary AI credentials. */
class Connection
{
    public const OTHER_PAID_FLAGS = [
        'enterprise.intelligence.enabled', 'enterprise.voice_generation_enabled',
        'enterprise.mailbox.send_enabled', 'enterprise.gmail.send_enabled',
        'enterprise.trial.channels.ai.enabled', 'enterprise.trial.channels.sms.enabled', 'enterprise.trial.channels.email.enabled',
    ];

    public function __construct(private WorkspaceAccess $access, private TrialStore $store) {}

    public function authority(Tenant $tenant, User $user, string $area): array
    {
        abort_unless(config('enterprise.conversation.enabled') === true, 404);
        $actor = $this->access->actor($tenant, $user);
        $this->access->area($tenant, $actor, $area);
        $settings = $this->settings();
        abort_unless((string) $tenant->id === (string) $settings['tenant_id'] && $area === $settings['area_id'], 404);
        $anchor = $this->store->tenant();
        abort_unless((string) $anchor->id === (string) $tenant->id && config('enterprise.trial.area_id') === $area
            && in_array((string) $user->id, $settings['user_ids'], true), 404);

        return ['actor' => $actor, 'settings' => $settings];
    }

    /** Nonsecret fields only. Operator hashes the exact reviewed packet; a changed account/key/rate requires new approval. */
    public static function approvalFields(array $config): array
    {
        $fields = array_intersect_key($config, array_flip(['approval_id', 'client_id', 'silo_id', 'mount', 'tenant_id', 'area_id', 'prior_spend_microusd', 'project_id', 'organization_id',
            'model', 'model_context_tokens', 'model_context_evidence', 'max_output_tokens', 'input_ceiling_microusd_per_million',
            'output_ceiling_microusd_per_million', 'per_call_overhead_microusd', 'rates_evidence', 'valid_until', 'user_ids', 'prior_spend_evidence_hash']));
        $fields['credential_sha256'] = hash('sha256', (string) ($config['api_key'] ?? ''));
        ksort($fields);

        return $fields;
    }

    public function settings(): array
    {
        abort_unless(config('enterprise.runtime') === true && config('enterprise.sample_data') === true && config('enterprise.writes_enabled') === true,
            503, 'The approved synthetic campus trial and spend writes must be configured.');
        foreach (self::OTHER_PAID_FLAGS as $flag) {
            abort_if(config($flag), 503, 'Other billable Enterprise trial paths must remain server-disabled.');
        }
        app(ClientSilo::class)->assertReady();
        abort_if(TeamOperationRecord::query()->where('kind', 'ent_ai_usage')->whereIn('status', ['reserved', 'uncertain'])->exists(), 503,
            'Outstanding ordinary Enterprise AI exposure must be reconciled before this trial connection.');
        $config = (array) config('enterprise.conversation', []);
        foreach (['approval_id', 'client_id', 'silo_id', 'mount', 'project_id', 'api_key', 'model_context_evidence', 'rates_evidence', 'valid_until'] as $key) {
            abort_unless(is_string($config[$key] ?? null) && trim($config[$key]) !== '', 503, 'An exact conversation connection approval is required.');
        }
        // The server-bound silo and mount cannot be supplied by chat JSON or role preview.
        abort_unless($config['client_id'] === config('enterprise_client.client_id') && $config['silo_id'] === config('enterprise_client.silo_id')
            && (string) ($config['tenant_id'] ?? '') === (string) config('enterprise.trial.tenant_id')
            && ($config['area_id'] ?? '') === config('enterprise.trial.area_id')
            && ($config['prior_spend_microusd'] ?? null) === config('enterprise.trial.prior_spend_microusd')
            && $config['mount'] === config('enterprise_runtime.base_path') && str_starts_with($config['mount'], '/')
            && preg_match('/^proj_[A-Za-z0-9_-]{1,100}$/D', $config['project_id'])
            && (empty($config['organization_id']) || preg_match('/^org-[A-Za-z0-9_-]{1,100}$/D', $config['organization_id'])), 503,
            'The conversation approval does not match this server-bound campus silo.');
        abort_unless(is_string($config['model'] ?? null) && preg_match('/^[A-Za-z0-9._-]{1,64}-\d{4}-\d{2}-\d{2}$/D', $config['model']),
            503, 'An exact approved dated model snapshot is required; aliases are not permitted.');
        foreach (['model_context_tokens' => [1024, 2000000], 'max_output_tokens' => [32, 800],
            'input_ceiling_microusd_per_million' => [1, 1000000000], 'output_ceiling_microusd_per_million' => [1, 1000000000],
            'per_call_overhead_microusd' => [0, 1000000]] as $key => [$minimum, $maximum]) {
            abort_unless(is_int($config[$key] ?? null) && $config[$key] >= $minimum && $config[$key] <= $maximum, 503,
                'Safe approved inclusive provider cost bounds are required.');
        }
        abort_unless(is_array($config['user_ids'] ?? null) && array_is_list($config['user_ids']) && count($config['user_ids']) >= 1 && count($config['user_ids']) <= 20, 503);
        foreach ($config['user_ids'] as $id) {
            abort_unless(is_string($id) && ctype_digit($id) && (int) $id > 0, 503);
        }
        try {
            $valid = now()->lt(CarbonImmutable::parse($config['valid_until']));
        } catch (\Throwable) {
            $valid = false;
        }
        abort_unless($valid && config('enterprise.trial.prior_spend_verified') === true
            && is_string(config('enterprise.trial.prior_spend_evidence'))
            && hash_equals(hash('sha256', config('enterprise.trial.prior_spend_evidence')), (string) ($config['prior_spend_evidence_hash'] ?? '')),
            503, 'Current rates and verified aggregate prior trial spend are required.');
        $digest = hash('sha256', json_encode(self::approvalFields($config), JSON_THROW_ON_ERROR));
        abort_unless(is_string($config['approval_sha256'] ?? null) && hash_equals($digest, $config['approval_sha256']), 503,
            'Connection fields changed. Obtain a fresh exact approval before provider dispatch.');
        $config['settings_sha256'] = $digest;
        $config['maximum_microusd'] = self::maximum($config);
        abort_unless($config['maximum_microusd'] > 0 && $config['maximum_microusd'] <= 5000000, 503,
            'The full approved model context cannot fit safely inside the shared $5 trial.');

        return $config;
    }

    public static function ceilingCost(int $inputTokens, int $outputTokens, array $settings): int
    {
        // Integer ceilings include worst approved cache writes, region/tier and other per-request fees.
        $numerator = $inputTokens * $settings['input_ceiling_microusd_per_million'] + $outputTokens * $settings['output_ceiling_microusd_per_million'];

        return intdiv($numerator + 999999, 1000000) + $settings['per_call_overhead_microusd'];
    }

    public static function maximum(array $settings): int
    {
        // Reserve the entire documented model context: no byte/token heuristic or unbudgeted count API call.
        return self::ceilingCost($settings['model_context_tokens'], $settings['max_output_tokens'], $settings);
    }
}
