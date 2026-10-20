<?php

namespace App\Services\AiBranch;

use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;

/** Audited, expiring access to one synthetic workspace and its approved provider. */
class AiFictionalPilot
{
    public const SLUG = 'everbranch-voice-pilot';

    public const SOURCE = 'fictional_voice_pilot';

    public function reserved(Tenant $tenant): bool
    {
        return $tenant->slug === self::SLUG || LandlordOperatorAction::query()->where('tenant_id', $tenant->id)
            ->whereIn('action_type', ['ai_voice.pilot.grant', 'ai_voice.pilot.revoke'])->exists();
    }

    public function grant(Tenant $tenant, User $user): ?LandlordOperatorAction
    {
        if (config('ai_branch.fictional_pilot_disabled') || $tenant->slug !== self::SLUG) {
            return null;
        }
        $user->refresh();
        if (! $user->is_active || ! $user->hasVerifiedEmail()
            || ! $user->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->wherePivot('role', 'owner')->exists()
            || ! TenantAccessProfile::query()->where('tenant_id', $tenant->id)->where('source', self::SOURCE)->exists()) {
            return null;
        }
        $grant = LandlordOperatorAction::query()->where('tenant_id', $tenant->id)
            ->whereIn('action_type', ['ai_voice.pilot.grant', 'ai_voice.pilot.revoke'])->latest('id')->first();
        if (! $grant || $grant->action_type !== 'ai_voice.pilot.grant' || $grant->status !== 'success'
            || (int) data_get($grant->result, 'owner_user_id') !== (int) $user->id
            || ! in_array(data_get($grant->result, 'provider'), ['scripted', 'openai'], true)
            || data_get($grant->result, 'synthetic_only') !== true
            || ! is_string(data_get($grant->result, 'expires_at'))) {
            return null;
        }
        if (data_get($grant->result, 'provider') === 'openai'
            && (! is_int(data_get($grant->result, 'provider_budget_cents'))
                || $grant->result['provider_budget_cents'] < 1 || $grant->result['provider_budget_cents'] > 1500
                || ! is_array(data_get($grant->result, 'provider_settings')))) {
            return null;
        }
        try {
            if (! CarbonImmutable::parse($grant->result['expires_at'])->isFuture()) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $grant;
    }

    public function current(): ?array
    {
        $scope = config('ai_branch.fictional_request');
        if (! is_array($scope) || ! request()->user() instanceof User) {
            return null;
        }
        $tenant = Tenant::find($scope['tenant_id'] ?? 0);
        $grant = $tenant ? $this->grant($tenant, request()->user()) : null;

        return $grant && (int) $grant->id === (int) ($scope['grant_id'] ?? 0) ? $scope : null;
    }

    public function during(Tenant $tenant, User $user, Closure $next): mixed
    {
        $grant = $this->grant($tenant, $user);
        if (! $grant) {
            return $next();
        }
        // Request-local only; restore even after exceptions and workspace changes.
        $values = ['enabled' => true, 'tenant_ids' => [$tenant->id], 'assistant_enabled' => true,
            'assistant_tenant_ids' => [$tenant->id], 'workspace_context_enabled' => true,
            'workspace_context_tenant_ids' => [$tenant->id], 'actions_enabled' => true,
            'assistant_reports_enabled' => true, 'assistant_attention_enabled' => true,
            'assistant_commitments_enabled' => true, 'assistant_finance_enabled' => false,
            'assistant_phone_practice_enabled' => true,
            'fictional_request' => ['tenant_id' => (int) $tenant->id, 'owner_id' => (int) $user->id, 'grant_id' => (int) $grant->id]];
        $before = [];
        foreach ($values as $key => $value) {
            $before['ai_branch.'.$key] = config('ai_branch.'.$key);
            config(['ai_branch.'.$key => $value]);
        }
        try {
            return $next();
        } finally {
            config($before);
        }
    }

    public function response(array $input, array $settings): array
    {
        $scope = $this->current();
        abort_unless($scope && ($settings['fictional_scope'] ?? null) === $scope, 403, 'Fictional pilot access changed.');
        abort_unless(($settings['fictional_provider'] ?? 'scripted') === 'scripted', 403);
        $evidence = json_decode((string) ($input[0]['content'] ?? ''), true);
        $sources = data_get($evidence, 'untrusted_workspace_context.sources', []);
        $lines = ['Scripted fictional practice — no language model or external provider was contacted.',
            'These are permitted sample records, not a complete business report:'];
        foreach (array_slice($sources, 0, 8) as $source) {
            if (is_string($source['title'] ?? null) && is_string($source['ref'] ?? null)) {
                $lines[] = $source['title'].' ['.$source['ref'].']';
            }
        }
        $lines[] = 'Try the Activity tab to prepare a note or a task from a recorded promise. Review its exact preview before approving. No action has been executed by this reply.';

        return ['reply' => implode("\n\n", $lines), 'input_tokens' => 0, 'output_tokens' => 0, 'provider_id' => 'fictional-scripted-no-dispatch'];
    }
}
