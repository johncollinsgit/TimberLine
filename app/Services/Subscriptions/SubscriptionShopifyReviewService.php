<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Local operator review only. A closure never approves, dispatches or replaces provider work. */
class SubscriptionShopifyReviewService
{
    private const PHASES = ['requested', 'calculate_sent', 'received', 'failed', 'quoted', 'commit_requested',
        'commit_sent', 'commit_not_sent', 'commit_response', 'commit_rejected', 'commit_result', 'aborted'];

    public function inspect(int $tenantId, User $actor, int $agreementId, string $uuid): array
    {
        return $this->locked($tenantId, $actor, $agreementId, $uuid,
            fn ($row, $uuid) => $this->review($row, $actor, $uuid)['summary']);
    }

    public function close(int $tenantId, User $actor, int $agreementId, string $uuid, int $revision, string $hash, array $input): array
    {
        $closure = Validator::make(['closure' => $input], [
            'closure' => ['required', 'array:reference,reason,no_provider_change_reviewed'],
            'closure.reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:\/-]{1,190}\z/'],
            'closure.reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/'],
            'closure.no_provider_change_reviewed' => ['required', 'boolean'],
        ])->validate()['closure'];
        abort_unless($closure['no_provider_change_reviewed'] === true && $revision > 0
            && preg_match('/\A[0-9a-f]{64}\z/D', $hash), 422, 'Review the exact local state and acknowledge that closure makes no provider change.');
        $closure['reason'] = trim($closure['reason']);
        abort_unless(mb_strlen($closure['reason']) >= 10, 422, 'Record a meaningful closure reason.');

        return $this->locked($tenantId, $actor, $agreementId, $uuid, function ($row, $uuid) use ($actor, $revision, $hash, $closure) {
            $review = $this->review($row, $actor, $uuid);
            if ($prior = $review['actions']['aborted'] ?? null) {
                abort_unless($review['summary']['integrity_valid'] && ! $review['summary']['requires_provider_recovery']
                    && $prior->actor_type === 'user' && $prior->actor_reference === (string) $actor->id
                    && $prior->expected_revision === $revision && ($prior->request['operator_review_hash'] ?? null) === $hash
                    && SubscriptionEvidence::canonical($prior->request['closure'] ?? []) === SubscriptionEvidence::canonical($closure),
                    409, 'This review already has a different closure. Retain its original actor and reason.');

                return $this->closed($uuid, $prior);
            }
            abort_unless($review['summary']['close_allowed'] && $row->revision === $revision
                && hash_equals($review['summary']['review_hash'], $hash), 409, 'Inspect the current signed review; a changed or uncertain commit cannot be closed.');
            $original = $review['actions']['requested'];
            $action = new SubscriptionBillingAction(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id,
                'request_uuid' => SubscriptionShopifyUpdateHistory::key($row, $uuid, 'aborted'), 'kind' => 'shopify_update_aborted',
                'actor_type' => 'user', 'actor_reference' => (string) $actor->id, 'expected_revision' => $revision,
                'request' => ['intent_hash' => $original->request['intent']['intent_hash'], 'operator_review_hash' => $hash,
                    'closure' => $closure, 'original_actor_type' => $original->actor_type, 'original_actor_reference' => $original->actor_reference,
                    'reviewed_journal' => $review['fingerprint']],
                'before_state' => [], 'after_state' => ['closed_at' => now()->toISOString()]]);
            $action->after_state = $action->after_state + ['authorization_hash' => SubscriptionBillingTermsHistory::seal($action)];
            $action->save();

            return $this->closed($uuid, $action);
        });
    }

    private function closed(string $uuid, SubscriptionBillingAction $action): array
    {
        return ['status' => 'aborted', 'request_uuid' => $uuid, 'closure_action_id' => $action->id,
            'closed_at' => $action->after_state['closed_at'], 'provider_requests' => false, 'charges_customer' => false, 'changes_contract' => false];
    }

    private function locked(int $tenantId, User $actor, int $agreementId, string $uuid, \Closure $work): array
    {
        abort_unless(DB::transactionLevel() === 0, 409, 'Inspect or close a native review outside an enclosing transaction.');
        abort_unless(Str::isUuid($uuid), 422, 'Use the original native review UUID.');
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);

        return DB::transaction(function () use ($row, $actor, $uuid, $work) {
            SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();
            $current = SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $this->authorize($current, $actor);
            abort_unless($current->processor === 'shopify', 409, 'Use the original native Shopify agreement.');

            return $work($current, strtolower($uuid));
        });
    }

    private function authorize(SubscriptionBillingAgreement $row, User $actor): void
    {
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $membership = DB::table('tenant_user')->where('tenant_id', $row->tenant_id)->where('user_id', $current->id)->lockForUpdate()->first();
        abort_unless($current->is_active && $current->email_verified_at && $membership && $membership->membership_active
            && in_array(strtolower($membership->role), ['owner', 'tenant_owner', 'admin'], true), 403, 'Current verified workspace owner/admin permission is required.');
        // Lock canonical access inputs, then resolve afresh rather than reusing request-scoped access caches.
        foreach (['tenant_access_profiles', 'tenant_access_addons', 'tenant_module_states', 'tenant_module_entitlements'] as $table) {
            DB::table($table)->where('tenant_id', $row->tenant_id)->orderBy('id')->lockForUpdate()->get();
        }
        abort_unless(app()->build(TenantModuleAccessResolver::class)->module($row->tenant_id, 'subscriptions')['has_access'],
            403, 'The Subscriptions add-on is not enabled for this workspace.');
    }

    private function review(SubscriptionBillingAgreement $row, User $actor, string $uuid): array
    {
        $actions = [];
        $stages = [];
        $fingerprint = [];
        $valid = true;
        foreach (self::PHASES as $phase) {
            $action = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
                ->where('request_uuid', SubscriptionShopifyUpdateHistory::key($row, $uuid, $phase))->lockForUpdate()->first();
            if (! $action) {
                continue;
            }
            $actions[$phase] = $action;
            $signed = true;
            try {
                SubscriptionShopifyUpdateHistory::journal($row, SubscriptionShopifyUpdateHistory::snapshot($action), $phase, $uuid);
            } catch (HttpException) {
                $signed = false;
                $valid = false;
            }
            $stages[$phase] = ['action_id' => $action->id, 'recorded_at' => $action->created_at->toISOString(), 'signature_valid' => $signed];
            $fingerprint[$phase] = ['action_id' => $action->id, 'signature' => $action->after_state['authorization_hash'] ?? null,
                'content_hash' => SubscriptionBillingTermsHistory::seal($action)];
        }
        $applied = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('request_uuid', $uuid)->lockForUpdate()->first();
        if ($applied) {
            $signed = $applied->kind === 'terms_amended' && isset($applied->request['native_update'])
                && is_string($applied->after_state['authorization_hash'] ?? null)
                && hash_equals(SubscriptionBillingTermsHistory::seal($applied), $applied->after_state['authorization_hash']);
            $valid = $valid && $signed;
            $stages['applied'] = ['action_id' => $applied->id, 'recorded_at' => $applied->created_at->toISOString(), 'signature_valid' => $signed];
            $fingerprint['applied'] = ['action_id' => $applied->id, 'signature' => $applied->after_state['authorization_hash'] ?? null,
                'content_hash' => SubscriptionBillingTermsHistory::seal($applied)];
        }
        try {
            $this->assertChain($row, $uuid, $actions);
            SubscriptionBillingAuthorization::assertCurrent($row, true);
        } catch (HttpException) {
            $valid = false;
        }
        $knownClosed = $valid && (isset($actions['commit_not_sent']) || isset($actions['commit_rejected']));
        $recovery = (! $applied || ! $valid) && (isset($actions['commit_sent']) || isset($actions['commit_response']) || isset($actions['commit_result'])) && ! $knownClosed;
        $closed = isset($actions['aborted']);
        $summary = ['status' => ! $valid ? 'review_invalid' : ($recovery ? 'recovery_required' : ($applied ? 'applied' : ($closed ? 'aborted' : 'review_open'))),
            'request_uuid' => $uuid, 'agreement_id' => $row->id, 'revision' => $row->revision, 'agreement_status' => $row->status,
            'next_billing_date' => $row->next_billing_date?->toDateString(), 'original_actor_type' => $actions['requested']->actor_type ?? null,
            'stages' => $stages, 'integrity_valid' => $valid, 'close_allowed' => $valid && ! $recovery && ! $applied && ! $closed,
            'requires_provider_recovery' => $recovery, 'provider_requests' => false, 'charges_customer' => false, 'changes_contract' => false];
        $summary['review_hash'] = SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['actor_id' => $actor->id,
            'tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'request_uuid' => $uuid,
            'state' => app(SubscriptionBillingAgreementService::class)->state($row), 'terms' => $row->terms,
            'method_binding' => $row->method_binding, 'authorization' => $row->authorization_evidence, 'journal' => $fingerprint]));

        return ['summary' => $summary, 'actions' => $actions, 'fingerprint' => $fingerprint];
    }

    private function assertChain(SubscriptionBillingAgreement $row, string $uuid, array $actions): void
    {
        $request = $actions['requested'] ?? null;
        abort_unless($request && ($request->request['request_uuid'] ?? null) === $uuid
            && ($request->request['intent']['request_uuid'] ?? null) === $uuid
            && ($request->request['intent']['subject']['tenant_id'] ?? null) === $row->tenant_id
            && ($request->request['intent']['subject']['agreement_id'] ?? null) === $row->id
            && ($request->request['intent']['subject']['member_id'] ?? null) === $row->member_id
            && ($request->request['intent']['subject']['revision'] ?? null) === $request->expected_revision, 409);
        $intent = $request->request['intent'];
        $hash = $intent['intent_hash'] ?? null;
        unset($intent['intent_hash']);
        abort_unless(is_string($hash) && hash_equals(SubscriptionEvidence::digest(SubscriptionEvidence::canonical($intent)), $hash), 409);
        $parents = ['calculate_sent' => 'requested', 'received' => 'calculate_sent', 'failed' => 'received', 'quoted' => 'received',
            'commit_requested' => 'quoted', 'commit_sent' => 'commit_requested', 'commit_not_sent' => 'commit_sent',
            'commit_response' => 'commit_sent', 'commit_rejected' => 'commit_response', 'commit_result' => 'commit_sent', 'aborted' => 'requested'];
        foreach ($actions as $phase => $action) {
            if ($phase === 'requested') {
                continue;
            }
            $parent = $actions[$parents[$phase]] ?? null;
            abort_unless($parent && $parent->id < $action->id, 409);
            if ($phase !== 'aborted') {
                abort_unless($action->actor_type === $request->actor_type && $action->actor_reference === $request->actor_reference
                    && $action->expected_revision === $request->expected_revision, 409);
            }
            if (in_array($phase, ['calculate_sent', 'received', 'failed', 'quoted', 'commit_sent', 'commit_result', 'aborted'], true)) {
                abort_unless(($action->request['intent_hash'] ?? null) === $hash, 409);
            }
        }
        if (isset($actions['commit_requested'])) {
            $approval = $actions['commit_requested']->request;
            abort_unless(($approval['request_uuid'] ?? null) === $uuid && ($approval['review_revision'] ?? null) === $request->expected_revision
                && ($approval['quote_hash'] ?? null) === ($actions['quoted']->after_state['quote']['review_hash'] ?? null), 409);
        }
        if (isset($actions['commit_not_sent'])) {
            abort_if(isset($actions['commit_response']) || isset($actions['commit_rejected']) || isset($actions['commit_result']), 409);
        }
        if (isset($actions['commit_rejected'])) {
            $payload = $actions['commit_rejected']->after_state['payload'] ?? [];
            abort_unless(array_key_exists('contract', $payload) && $payload['contract'] === null
                && is_array($payload['userErrors'] ?? null) && $payload['userErrors'] !== []
                && SubscriptionEvidence::canonical($payload) === SubscriptionEvidence::canonical($actions['commit_response']->after_state['payload'] ?? [])
                && ! isset($actions['commit_result']), 409);
        }
    }
}
