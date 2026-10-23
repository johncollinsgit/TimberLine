<?php

namespace App\Services\Trajectory;

use App\Models\AiBranchAccount;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBillingSubscription;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\AiBranch\AiBranchAccess;
use App\Services\AiBranch\AiBranchOffer;
use App\Services\AiBranch\AiBranchProvider;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Finance authorization is separate from generic tenant membership and chat. */
class FinanceAiService
{
    public function authorize(User $user, Space $space, bool $usage = true): array
    {
        $user->refresh();
        $space->refresh();
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless(config('trajectory.gerald_enabled'), 503, 'Gerald is currently unavailable.');
        $tenant = Tenant::findOrFail($space->tenant_id);
        if ($space->kind === 'business') {
            $tier = app(AiBranchAccess::class)->authorize($tenant, $user, $usage);
        } else {
            $offer = app(AiBranchOffer::class);
            abort_unless($offer->metered($tenant) && app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'ai_branch'), 403, 'The household owner must activate existing AI access before enabling Gerald.');
            if (! $offer->included($tenant)) {
                abort_unless(TenantBillingSubscription::where('tenant_id', $tenant->id)->where('provider', 'stripe')->where('purchase_key', 'addon.ai_branch_access')->where('status', 'active')->whereNull('ended_at')->where(fn ($q) => $q->where('current_period_ends_at', '>', now())->orWhere(fn ($r) => $r->whereNull('current_period_ends_at')->where('updated_at', '>', now()->subDay())))->count() === 1, 403, 'A current AI access subscription is required.');
            }
            $tier = $offer->tier($tenant);
            if ($usage) {
                abort_unless(AiBranchAccount::where('tenant_id', $tenant->id)->where('enabled', true)->where('budget_cents', '>', 0)->exists(), 403, 'The household owner must approve an AI model and spending cap.');
            }
        }

        return [$tenant, $tier];
    }

    public function configure(User $user, Space $space, array $input): void
    {
        [$tenant, $tier] = $this->authorize($user, $space, false);
        app(WorkspaceService::class)->manage($user, $space);
        abort_unless($space->kind === 'household' || app(AiBranchAccess::class)->canApprove($tenant, $user), 403);
        $data = Validator::make($input, ['enabled' => 'required|boolean', 'confirmed' => 'accepted', 'model' => ['required', Rule::in(array_keys(config('ai_branch.models')))], 'budget_cents' => 'required|integer|min:0|max:'.$tier['ai_limits']['provider_budget_cents'], 'audio_approved' => 'required|boolean'])->validate();
        abort_if($data['enabled'] && $data['budget_cents'] === 0, 422, 'Choose a positive spending cap.');
        DB::transaction(function () use ($tenant, $user, $space, $data) {
            $account = AiBranchAccount::firstOrCreate(['tenant_id' => $tenant->id]);
            $account = AiBranchAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $profile = TenantAccessProfile::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) $profile->metadata;
            data_set($metadata, 'ai_branch.model', $data['model']);
            data_set($metadata, 'ai_branch.usage_terms', ['version' => config('ai_branch.rates_version'), 'multiplier' => 2, 'rates_digest' => hash('sha256', json_encode(config('ai_branch.models')[$data['model']], JSON_THROW_ON_ERROR)), 'approved_by' => $user->id, 'approved_at' => now()->toIso8601String()]);
            $profile->update(['metadata' => $metadata]);
            $account->update(['enabled' => $data['enabled'], 'budget_cents' => $data['budget_cents'], 'approved_by' => $user->id, 'approved_at' => now()]);
            $space = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $space->update(['settings' => [...($space->settings ?? []), 'gerald_audio_terms' => $data['audio_approved'] ? ['digest' => $this->audioDigest(), 'actor_id' => $user->id, 'approved_at' => now()->toIso8601String()] : null]]);
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'gerald_ai_settings', 'after' => $data]);
        });
    }

    public function audioDigest(): string
    {
        return hash('sha256', json_encode(config('trajectory.audio_rates'), JSON_THROW_ON_ERROR));
    }

    public function presentation(User $user, Space $space): array
    {
        app(FinanceAccess::class)->authorize($user, $space);
        $tenant = Tenant::findOrFail($space->tenant_id);
        $account = AiBranchAccount::where('tenant_id', $tenant->id)->first();
        try {
            $this->authorize($user, $space);
            app(AiBranchProvider::class)->liveSettings($tenant);
            $ready = true;
            $reason = null;
        } catch (Throwable $e) {
            $ready = false;
            $reason = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getMessage() : 'AI setup requires owner review.';
        }

        return ['ready' => $ready, 'reason' => $reason, 'enabled' => $account?->enabled ?? false, 'budget_cents' => $account?->budget_cents ?? 0,
            'model' => app(AiBranchOffer::class)->model($tenant), 'models' => config('ai_branch.models'), 'rates_version' => config('ai_branch.rates_version'), 'buyer_multiplier' => 2,
            'credit_available_micros' => \App\Models\TenantMessagingCreditAccount::forAllTenants()->where('tenant_id', $tenant->id)->first()?->availableMicros() ?? 0,
            'audio_approved' => hash_equals($this->audioDigest(), $space->settings['gerald_audio_terms']['digest'] ?? ''), 'audio_rates' => config('trajectory.audio_rates'),
            'can_configure' => $space->kind === 'household' ? $space->owner_user_id === $user->id : app(AiBranchAccess::class)->canApprove($tenant, $user)];
    }

    /** Every finance request shares the existing AI account lock, cap and wallet. */
    public function run(User $user, Space $space, string $purpose, string $uuid, array $payload, callable $dispatch, ?array $audio = null): array
    {
        [$tenant, $tier] = $this->authorize($user, $space);
        abort_unless(Str::isUuid($uuid), 422);
        $settings = app(AiBranchProvider::class)->liveSettings($tenant);
        // Finance currently participates in the existing prepaid offer only.
        abort_unless(($settings['buyer_multiplier'] ?? 0) === 2, 403, 'Approve current AI usage terms for Gerald.');
        $hash = hash('sha256', json_encode([$user->id, $space->id, $purpose, $payload], JSON_THROW_ON_ERROR));
        $reserved = $audio['reserve_micros'] ?? app(AiBranchProvider::class)->cost($settings['max_input'], $settings['max_output'], $settings);
        if ($audio) {
            abort_unless(hash_equals($this->audioDigest(), $space->fresh()->settings['gerald_audio_terms']['digest'] ?? '') && now()->lt(CarbonImmutable::parse(config('trajectory.audio_rates.valid_until'))), 403, 'The finance owner must approve current audio rates.');
        }
        $reservation = DB::transaction(function () use ($user, $space, $tenant, $tier, $purpose, $uuid, $hash, $settings, $reserved, $audio) {
            $account = AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $space);
            $existing = TenantAiUsageEvent::where('tenant_id', $tenant->id)->where('client_uuid', $uuid)->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && hash_equals($existing->metadata['request_hash'] ?? '', $hash), 409, 'This request identifier is already used.');
                $stored = Record::where('space_id', $space->id)->where('kind', 'gerald_request')->find($existing->metadata['finance_result_id'] ?? 0);
                abort_unless($existing->status === 'settled' && $stored && $stored->data['actor_id'] === $user->id && $stored->data['expires_at'] > now()->timestamp, 409, 'This request is pending, expired, or requires usage review.');

                return ['replay' => $stored->data['result']];
            }
            $usage = TenantAiUsageEvent::where('tenant_id', $tenant->id)->where('feature', 'ai_branch_chat')->where('occurred_at', '>=', now('UTC')->startOfMonth());
            abort_unless((clone $usage)->count() < $tier['ai_limits']['responses'] && $reserved > 0 && $usage->sum('buyer_charge_micros') + $reserved * 2 <= min($account->budget_cents, $tier['ai_limits']['provider_budget_cents']) * 10000, 429, 'The AI spending allowance is exhausted. Resume after owner review.');
            $event = TenantAiUsageEvent::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $uuid, 'feature' => 'ai_branch_chat', 'context' => 'trajectory', 'model' => $audio['model'] ?? $settings['model'], 'status' => 'reserved', 'duration_seconds' => 0, 'provider_cost_micros' => $reserved, 'buyer_charge_micros' => $reserved * 2, 'occurred_at' => now('UTC'), 'metadata' => ['request_hash' => $hash, 'settings' => $settings, 'billing_scope' => 'ai_prepaid', 'purpose' => 'trajectory_'.$purpose, 'space_id' => $space->id, 'audio' => (bool) $audio, 'audio_rates' => $audio ? config('trajectory.audio_rates') : null]]);
            app(TenantMessagingUsageService::class)->reserveAi($event, $reserved * 2, $settings);

            return ['event' => $event];
        }, 3);
        if (isset($reservation['replay'])) {
            return $reservation['replay'];
        }
        try {
            $result = $dispatch($settings);
            $cost = $result['cost_micros'] ?? app(AiBranchProvider::class)->cost($result['input_tokens'], $result['output_tokens'], $settings, $result['cached_input_tokens'] ?? 0);
            abort_unless(is_int($cost) && $cost >= 0 && $cost <= $reserved, 503);
            DB::transaction(function () use ($tenant, $user, $space, $reservation, $result, $cost) {
                AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                $event = TenantAiUsageEvent::whereKey($reservation['event']->id)->lockForUpdate()->firstOrFail();
                abort_unless($event->status === 'reserved', 409);
                $event->provider_request_id = $result['provider_id'];
                app(TenantMessagingUsageService::class)->settleAi($event, $cost);
                $stored = Record::create(['space_id' => $space->id, 'kind' => 'gerald_request', 'name' => 'Private finance response', 'data' => ['actor_id' => $user->id, 'expires_at' => isset($result['audio_usage']) ? now()->addMinutes(2)->timestamp : now()->addDays(30)->timestamp, 'result' => $result['value']]]);
                $event->update(['status' => 'settled', 'provider_request_id' => $result['provider_id'], 'provider_cost_micros' => $cost, 'buyer_charge_micros' => $cost * 2, 'duration_seconds' => $result['duration_seconds'] ?? 0, 'metadata' => [...$event->metadata, 'finance_result_id' => $stored->id, 'input_tokens' => $result['input_tokens'] ?? 0, 'output_tokens' => $result['output_tokens'] ?? 0, 'cached_input_tokens' => $result['cached_input_tokens'] ?? 0, 'audio_usage' => $result['audio_usage'] ?? null]]);
            }, 3);
        } catch (Throwable) {
            $reservation['event']->update(['status' => 'uncertain']);
            abort(503, 'Gerald could not verify this response. Its allowance remains reserved for usage review.');
        }
        $this->authorize($user, $space);

        return $result['value'];
    }
}
