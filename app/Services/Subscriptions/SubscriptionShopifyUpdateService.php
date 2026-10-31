<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Durable native update calculation only. No commit, terms application, collection or schedule mutation. */
class SubscriptionShopifyUpdateService
{
    public function calculate(int $tenantId, User $actor, int $agreementId, array $input, string $uuid, int $revision): array
    {
        $this->outsideTransaction();
        abort_unless(Str::isUuid($uuid) && $revision >= 1, 422);
        $uuid = strtolower($uuid);
        $input = app(SubscriptionAmendmentProposal::class)->normalize($input);
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->authorize($row, $actor, false, false);
        if ($prior = $this->action($row, $uuid, 'requested')) {
            $this->sameRequest($prior, $actor, $input, $revision);

            return $this->reconcile($tenantId, $actor, $agreementId, $uuid);
        }
        $this->authorize($row, $actor, true, false);
        abort_unless($row->revision === $revision, 409, 'Review the current agreement revision.');
        app(SubscriptionBillingAmendmentService::class)->assertFuture($row);
        $transport = app(SubscriptionShopifyBillingTransport::class);
        $capabilities = $transport->updateRead($row, 'capabilities');
        $before = $transport->updateRead($row, 'contract')['subscriptionContract'] ?? [];
        $proposal = app(SubscriptionAmendmentProposal::class)->review($row, $input);
        $catalog = $transport->updateRead($row, 'catalog', null, $proposal['terms']);
        $intent = app(SubscriptionShopifyUpdateProtocol::class)->intent($row, $before, $input, $catalog, $uuid);
        $this->capabilities($capabilities, $intent);
        $this->locked($row, function ($current) use ($actor, $input, $revision, $uuid, $intent) {
            $this->authorize($current, $actor, true, true);
            SubscriptionBillingAuthorization::assertCurrent($current, true);
            if ($prior = $this->action($current, $uuid, 'requested', true)) {
                $this->sameRequest($prior, $actor, $input, $revision);

                return;
            }
            abort_unless($current->revision === $revision && $current->terms_hash === $intent['subject']['current_terms_hash']
                && SubscriptionEvidence::canonical($intent['context']) === SubscriptionEvidence::canonical(app(SubscriptionShopifyBillingTransport::class)->context($current, true)), 409, 'The original update review changed.');
            app(SubscriptionBillingAmendmentService::class)->assertFuture($current, true);
            $latest = SubscriptionBillingAction::query()->forTenantId($current->tenant_id)->where('agreement_id', $current->id)
                ->where('kind', 'shopify_update_requested')->orderByDesc('id')->lockForUpdate()->first();
            if ($latest) {
                $this->assertAction($latest);
                $oldUuid = $latest->request['request_uuid'];
                abort_unless($this->action($current, $oldUuid, 'aborted', true), 409, 'Close the original native update review before starting another.');
            }
            abort_if(SubscriptionBillingAction::query()->forTenantId($current->tenant_id)->where('agreement_id', $current->id)
                ->where('request_uuid', $uuid)->lockForUpdate()->first(), 409, 'This request ID belongs to another subscription action.');
            $this->append($current, $uuid, 'requested', $actor, $revision, ['request_uuid' => $uuid, 'input' => $input, 'intent' => $intent],
                ['terms' => $current->terms, 'terms_hash' => $current->terms_hash], ['prepared_at' => now()->toISOString()]);
        });

        return $this->execute($row, $actor, $uuid);
    }

    /** Polls only the retained calculation ID. A lost initial response never creates a second calculation. */
    public function reconcile(int $tenantId, User $actor, int $agreementId, string $uuid): array
    {
        $this->outsideTransaction();
        abort_unless(Str::isUuid($uuid), 422);
        $uuid = strtolower($uuid);
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->authorize($row, $actor, false, false);
        $requested = $this->required($row, $uuid, 'requested');
        abort_unless($requested->actor_reference === (string) $actor->id, 403);
        if ($this->action($row, $uuid, 'aborted')) {
            return ['status' => 'aborted', 'request_uuid' => $uuid, 'charges_customer' => false, 'changes_contract' => false];
        }
        if ($quote = $this->action($row, $uuid, 'quoted')) {
            return $this->quoted($quote, $uuid);
        }
        if (! $this->action($row, $uuid, 'calculate_sent')) {
            return $this->execute($row, $actor, $uuid);
        }
        $received = $this->action($row, $uuid, 'received');
        if (! $received) {
            return $this->unknown($uuid);
        }
        if (data_get($received->after_state, 'calculation.__typename') === 'SubscriptionContractCalculationFailure'
            || $this->action($row, $uuid, 'failed')) {
            return ['status' => 'calculation_failed', 'request_uuid' => $uuid, 'charges_customer' => false, 'changes_contract' => false];
        }
        $id = $received->after_state['calculation']['id'];
        $data = app(SubscriptionShopifyBillingTransport::class)->updateRead($row, 'calculation', $id);

        return $this->receive($row, $uuid, $data['subscriptionContractCalculation'] ?? []);
    }

    /** A pre-commit calculation cannot change a contract, including when its response was lost. */
    public function abort(int $tenantId, User $actor, int $agreementId, string $uuid): array
    {
        $this->outsideTransaction();
        abort_unless(Str::isUuid($uuid), 422);
        $uuid = strtolower($uuid);
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);

        return $this->locked($row, function ($current) use ($actor, $uuid) {
            $this->authorize($current, $actor, false, true);
            $request = $this->required($current, $uuid, 'requested', true);
            abort_unless($request->actor_reference === (string) $actor->id, 403);
            abort_if($this->action($current, $uuid, 'commit_sent', true), 409, 'Reconcile the dispatched original commit before closing this update.');
            if (! $this->action($current, $uuid, 'aborted', true)) {
                $this->append($current, $uuid, 'aborted', $actor, $current->revision, ['intent_hash' => $request->request['intent']['intent_hash']], [], ['closed_at' => now()->toISOString()]);
            }

            return ['status' => 'aborted', 'request_uuid' => $uuid, 'charges_customer' => false, 'changes_contract' => false];
        });
    }

    /** Internal fixed-transport hook: the claim commits before the bounded send obtains the servicing locks again. */
    public function dispatchCalculate(SubscriptionBillingAgreement $row, string $uuid, \Closure $send): array
    {
        $this->outsideTransaction();
        $this->locked($row, function ($current) use ($uuid) {
            $request = $this->required($current, $uuid, 'requested', true);
            $actor = User::findOrFail((int) $request->actor_reference);
            $this->assertSendable($current, $request, $actor);
            abort_if($this->action($current, $uuid, 'calculate_sent', true), 409, 'The original calculation was already dispatched; reconcile it without resending.');
            $this->append($current, $uuid, 'calculate_sent', $actor, $current->revision,
                ['intent_hash' => $request->request['intent']['intent_hash']], [], ['dispatched_at' => now()->toISOString()]);
        });

        return $this->locked($row, function ($current) use ($uuid, $send) {
            $request = $this->required($current, $uuid, 'requested', true);
            $this->required($current, $uuid, 'calculate_sent', true);
            $this->assertSendable($current, $request, User::findOrFail((int) $request->actor_reference));

            return $send($current, $request->request['intent']);
        });
    }

    private function execute(SubscriptionBillingAgreement $row, User $actor, string $uuid): array
    {
        if ($this->action($row, $uuid, 'calculate_sent')) {
            return $this->reconcile($row->tenant_id, $actor, $row->id, $uuid);
        }
        try {
            $data = app(SubscriptionShopifyBillingTransport::class)->updateCalculate($row, $uuid);
        } catch (\Throwable $error) {
            if ($this->action($row, $uuid, 'calculate_sent')) {
                return $this->unknown($uuid);
            }
            throw $error;
        }

        // Invalid, missing or warning-bearing results retain their dispatch and cannot authorize a commit.
        return $this->receive($row, $uuid, data_get($data, 'subscriptionContractUpdateCalculate.subscriptionContractCalculation', []),
            data_get($data, 'subscriptionContractUpdateCalculate.userErrors', []));
    }

    private function receive(SubscriptionBillingAgreement $row, string $uuid, array $calculation, array $errors = []): array
    {
        // This transaction must commit before validation: a warning or stale review cannot erase a known provider ID.
        $this->locked($row, function ($current) use ($uuid, $calculation, $errors) {
            $request = $this->required($current, $uuid, 'requested', true);
            $this->required($current, $uuid, 'calculate_sent', true);
            abort_unless(in_array($calculation['__typename'] ?? null, ['SubscriptionContractCalculationPending', 'SubscriptionContractCalculationSuccess', 'SubscriptionContractCalculationFailure'], true)
                && preg_match('#\Agid://shopify/SubscriptionContractCalculation/[A-Za-z0-9_-]{1,190}\z#', (string) ($calculation['id'] ?? '')), 409, 'Retain the original calculation reference; do not submit another update.');
            $actor = User::findOrFail((int) $request->actor_reference);
            $received = $this->action($current, $uuid, 'received', true);
            abort_unless(! $received || $received->after_state['calculation']['id'] === $calculation['id'], 409, 'The original calculation reference differs.');
            if (! $received) {
                $this->append($current, $uuid, 'received', $actor, $request->expected_revision,
                    ['intent_hash' => $request->request['intent']['intent_hash']], [], ['calculation' => $calculation,
                        'user_errors' => array_map(fn ($error) => ['code' => $error['code'] ?? null, 'field' => $error['field'] ?? null], $errors), 'received_at' => now()->toISOString()]);
            }
        });

        return $this->locked($row, function ($current) use ($uuid, $calculation, $errors) {
            $request = $this->required($current, $uuid, 'requested', true);
            $received = $this->required($current, $uuid, 'received', true);
            $actor = User::findOrFail((int) $request->actor_reference);
            if ($this->action($current, $uuid, 'aborted', true)) {
                return ['status' => 'aborted', 'request_uuid' => $uuid, 'charges_customer' => false, 'changes_contract' => false];
            }
            if ($prior = $this->action($current, $uuid, 'quoted', true)) {
                return $this->quoted($prior, $uuid);
            }
            abort_unless($errors === [] && $received->after_state['user_errors'] === [], 409, 'The original calculation returned user errors. Retain it for review without resubmitting.');
            if ($calculation['__typename'] !== 'SubscriptionContractCalculationSuccess') {
                if ($calculation['__typename'] === 'SubscriptionContractCalculationFailure' && ! $this->action($current, $uuid, 'failed', true)) {
                    $this->append($current, $uuid, 'failed', $actor, $request->expected_revision,
                        ['intent_hash' => $request->request['intent']['intent_hash']], [], ['calculation' => $calculation, 'failed_at' => now()->toISOString()]);
                }

                return ['status' => $calculation['__typename'] === 'SubscriptionContractCalculationPending' ? 'calculation_pending' : 'calculation_failed',
                    'request_uuid' => $uuid, 'calculation_id' => $calculation['id'], 'charges_customer' => false, 'changes_contract' => false];
            }
            // Keep the known ID even if the review became stale while the provider was calculating.
            if ($current->revision !== $request->expected_revision || $current->terms_hash !== $request->request['intent']['subject']['current_terms_hash']) {
                return ['status' => 'review_stale', 'request_uuid' => $uuid, 'calculation_id' => $calculation['id'], 'charges_customer' => false, 'changes_contract' => false];
            }
            $quote = app(SubscriptionShopifyUpdateProtocol::class)->quote($current, $request->request['intent'], $calculation);
            $action = $this->append($current, $uuid, 'quoted', $actor, $request->expected_revision,
                ['intent_hash' => $request->request['intent']['intent_hash']], [], ['quote' => $quote, 'calculation' => $calculation, 'quoted_at' => now()->toISOString()]);

            return $this->quoted($action, $uuid);
        });
    }

    private function assertSendable(SubscriptionBillingAgreement $row, SubscriptionBillingAction $request, User $actor): void
    {
        $this->authorize($row, $actor, true, true);
        SubscriptionBillingAuthorization::assertCurrent($row, true);
        app(SubscriptionBillingAmendmentService::class)->assertFuture($row, true);
        $intent = $request->request['intent'];
        abort_unless($row->revision === $request->expected_revision && $row->terms_hash === $intent['subject']['current_terms_hash']
            && SubscriptionEvidence::canonical($intent['subject']) === SubscriptionEvidence::canonical(app(SubscriptionAmendmentProposal::class)->subject($row))
            && SubscriptionEvidence::canonical($intent['context']) === SubscriptionEvidence::canonical(app(SubscriptionShopifyBillingTransport::class)->context($row, true))
            && ! $this->action($row, $request->request['request_uuid'], 'aborted', true), 409, 'The original review or provider authority changed before dispatch.');
    }

    private function authorize(SubscriptionBillingAgreement $row, User $actor, bool $edit, bool $currentRead): void
    {
        $actor = User::query()->whereKey($actor->id)->when($currentRead, fn ($q) => $q->lockForUpdate())->firstOrFail();
        $membership = DB::table('tenant_user')->where('tenant_id', $row->tenant_id)->where('user_id', $actor->id)
            ->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($actor->is_active && $actor->email_verified_at && $membership && $membership->membership_active
            && in_array(strtolower($membership->role), ['owner', 'tenant_owner', 'admin'], true), 403, 'The current verified owner/admin permission is required.');
        app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor, $row->member_id, $edit ? 'servicing' : 'setup');
        $member = app(SubscriptionReplacementAccess::class)->member($row);
        abort_unless($row->processor === 'shopify' && $row->activated_at && $row->method_verified_at && $member->billing_owner === 'everbranch', 409, 'Review the individually activated original Shopify membership.');
        if ($edit) {
            abort_unless(config('subscriptions.replacement_amendments_enabled') === true
                && config('subscriptions.replacement_shopify_amendments_enabled') === true, 423, 'Native update calculations are not enabled for this member.');
        }
    }

    private function capabilities(array $data, array $intent): void
    {
        abort_unless(collect(data_get($data, 'currentAppInstallation.accessScopes', []))->pluck('handle')->contains('write_own_subscription_contracts'), 423);
        foreach (['mutationType.fields' => ['subscriptionContractUpdateCalculate', 'subscriptionContractCalculationCommit'],
            'queryType.fields' => ['subscriptionContractCalculation', 'subscriptionContract'],
            'updateInputType.inputFields' => array_keys($intent['variables']['input'])] as $path => $required) {
            abort_unless(! array_diff($required, collect(data_get($data, $path, []))->pluck('name')->all()), 423, 'The original pinned API does not expose the reviewed update calculation.');
        }
        if (isset($intent['variables']['input']['lines'])) {
            abort_unless(! array_diff(array_keys($intent['variables']['input']['lines'][0]['productVariantLine']), collect(data_get($data, 'lineInputType.inputFields', []))->pluck('name')->all()), 423);
        }
    }

    private function outsideTransaction(): void
    {
        abort_unless(DB::transactionLevel() === 0, 409, 'Native update dispatch must start outside an enclosing transaction so its send record is durable before HTTP.');
    }

    private function locked(SubscriptionBillingAgreement $row, \Closure $work): mixed
    {
        return DB::transaction(function () use ($row, $work) {
            SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();

            return $work(SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail());
        });
    }

    private function key(SubscriptionBillingAgreement $row, string $uuid, string $phase): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'everbranch:shopify-update:'.$row->tenant_id.':'.$row->id.':'.$uuid.':'.$phase);
    }

    private function action(SubscriptionBillingAgreement $row, string $uuid, string $phase, bool $lock = false): ?SubscriptionBillingAction
    {
        $action = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->where('request_uuid', $this->key($row, $uuid, $phase))->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($action) {
            $this->assertAction($action);
            abort_unless($action->kind === 'shopify_update_'.$phase, 409, 'The original native update journal entry differs.');
        }

        return $action;
    }

    private function required(SubscriptionBillingAgreement $row, string $uuid, string $phase, bool $lock = false): SubscriptionBillingAction
    {
        $action = $this->action($row, $uuid, $phase, $lock);
        abort_unless($action, 409, 'The original native update journal entry is missing.');

        return $action;
    }

    private function assertAction(SubscriptionBillingAction $action): void
    {
        $hash = $action->after_state['authorization_hash'] ?? null;
        abort_unless(is_string($hash) && hash_equals(SubscriptionBillingTermsHistory::seal($action), $hash), 409, 'The retained native update evidence changed.');
    }

    private function append(SubscriptionBillingAgreement $row, string $uuid, string $phase, User $actor, int $revision, array $request, array $before, array $after): SubscriptionBillingAction
    {
        $action = new SubscriptionBillingAction(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id,
            'request_uuid' => $this->key($row, $uuid, $phase), 'kind' => 'shopify_update_'.$phase, 'actor_type' => 'user', 'actor_reference' => (string) $actor->id,
            'expected_revision' => $revision, 'request' => $request, 'before_state' => $before, 'after_state' => $after]);
        $after['authorization_hash'] = SubscriptionBillingTermsHistory::seal($action);
        $action->after_state = $after;
        $action->save();

        return $action;
    }

    private function sameRequest(SubscriptionBillingAction $prior, User $actor, array $input, int $revision): void
    {
        abort_unless($prior->actor_type === 'user' && $prior->actor_reference === (string) $actor->id && $prior->expected_revision === $revision
            && SubscriptionEvidence::canonical($prior->request['input']) === SubscriptionEvidence::canonical($input), 409, 'This request ID belongs to another native update review.');
    }

    private function quoted(SubscriptionBillingAction $action, string $uuid): array
    {
        return ['status' => 'quote_ready', 'request_uuid' => $uuid, 'quote' => $action->after_state['quote'], 'quoted_at' => $action->after_state['quoted_at'],
            'charges_customer' => false, 'changes_contract' => false, 'commit_available' => false];
    }

    private function unknown(string $uuid): array
    {
        return ['status' => 'calculation_unknown', 'request_uuid' => $uuid, 'charges_customer' => false, 'changes_contract' => false, 'resend_allowed' => false];
    }
}
