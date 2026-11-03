<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Durable native calculation, exact approval and one commit; never collects or restores a prior schedule. */
class SubscriptionShopifyUpdateService
{
    public function calculateCustomer(ModernForestryMobileCustomerSession $session, array $change, string $uuid, int $revision): array
    {
        $row = app(SubscriptionReplacementCustomerAccess::class)->agreement($session);
        abort_unless($row && $row->processor === 'shopify', 404);
        Validator::make(['change' => $change], ['change' => ['required', 'array:shipping_address,swaps']])->validate();
        $actor = SubscriptionShopifyCustomerActor::fromSession($row, $session);

        return $this->calculate($row->tenant_id, $actor, $row->id,
            $change + ['fixed_shipping_reviewed' => true, 'policy_reference' => 'delivery-policy:'.$actor->proof['policy_uuid']], $uuid, $revision);
    }

    public function confirmCustomer(ModernForestryMobileCustomerSession $session, string $uuid, int $revision, string $hash, bool $confirmed): array
    {
        abort_unless($confirmed, 422, 'Confirm the exact future delivery and total.');
        [$row, $actor] = $this->customerRequest($session, $uuid);

        return $this->commit($row->tenant_id, $actor, $row->id, $uuid, $revision, $hash,
            ['reference' => 'delivery-policy:'.$actor->proof['policy_uuid'], 'quoted_total_reviewed' => true, 'continuing_authorization_reviewed' => true]);
    }

    public function reconcileCustomer(ModernForestryMobileCustomerSession $session, string $uuid): array
    {
        [$row, $actor] = $this->customerRequest($session, $uuid);
        if (! $this->action($row, strtolower($uuid), 'calculate_sent')) {
            return ['status' => 'calculation_prepared', 'request_uuid' => strtolower($uuid), 'charges_customer' => false, 'changes_contract' => false];
        }

        return $this->reconcile($row->tenant_id, $actor, $row->id, $uuid);
    }

    public function abortCustomer(ModernForestryMobileCustomerSession $session, string $uuid): array
    {
        [$row, $actor] = $this->customerRequest($session, $uuid);

        return $this->abort($row->tenant_id, $actor, $row->id, $uuid);
    }

    private function customerRequest(ModernForestryMobileCustomerSession $session, string $uuid): array
    {
        abort_unless(Str::isUuid($uuid), 422);
        $row = app(SubscriptionReplacementCustomerAccess::class)->agreement($session);
        abort_unless($row && $row->processor === 'shopify', 404);
        $request = $this->required($row, strtolower($uuid), 'requested');

        return [$row, SubscriptionShopifyCustomerActor::forRequest($row, $session, $request)];
    }

    public function preview(int $tenantId, User $actor, int $agreementId): array
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->authorize($row, $actor, false, false);
        SubscriptionBillingAuthorization::assertCurrent($row);

        return ['agreement_id' => $row->id, 'revision' => $row->revision, 'terms_hash' => $row->terms_hash,
            'status' => $row->status, 'current_amount_cents' => $row->terms['amount_cents'], 'currency' => $row->terms['currency'],
            'pending_commit_request_id' => $this->pendingCommitUuid($row), 'provider_requests' => false, 'charges_customer' => false,
            'calculation_enabled' => config('subscriptions.replacement_shopify_amendments_enabled') === true,
            'apply_enabled' => config('subscriptions.replacement_amendment_apply_enabled') === true];
    }

    public function calculate(int $tenantId, User|SubscriptionShopifyCustomerActor $actor, int $agreementId, array $input, string $uuid, int $revision): array
    {
        $this->outsideTransaction();
        abort_unless(! $actor instanceof SubscriptionShopifyCustomerActor || $actor->interactive, 403, 'Start a new review from your authenticated account.');
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
        if ($actor instanceof SubscriptionShopifyCustomerActor) {
            $actor->permits($row, $input, false);
        }
        abort_unless($row->revision === $revision, 409, 'Review the current agreement revision.');
        app(SubscriptionBillingAmendmentService::class)->assertFuture($row);
        $transport = app(SubscriptionShopifyBillingTransport::class);
        $capabilities = $transport->updateRead($row, 'capabilities');
        $before = $transport->updateRead($row, 'contract')['subscriptionContract'] ?? [];
        $proposal = app(SubscriptionAmendmentProposal::class)->review($row, $input);
        $catalog = $transport->updateRead($row, 'catalog', null, $proposal['terms']);
        $intent = app(SubscriptionShopifyUpdateProtocol::class)->intent($row, $before, $input, $catalog, $uuid,
            $actor instanceof SubscriptionShopifyCustomerActor ? 'CUSTOMER' : 'MERCHANT');
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
                abort_unless($this->action($current, $oldUuid, 'aborted', true) || $this->appliedAction($current, $oldUuid, true), 409, 'Close or reconcile the original native update review before starting another.');
            }
            abort_if(SubscriptionBillingAction::query()->forTenantId($current->tenant_id)->where('agreement_id', $current->id)
                ->where('request_uuid', $uuid)->lockForUpdate()->first(), 409, 'This request ID belongs to another subscription action.');
            $this->append($current, $uuid, 'requested', $actor, $revision, ['request_uuid' => $uuid, 'input' => $input, 'intent' => $intent]
                + ($actor instanceof SubscriptionShopifyCustomerActor ? ['customer_actor' => $actor->proof] : []),
                ['terms' => $current->terms, 'terms_hash' => $current->terms_hash], ['prepared_at' => now()->toISOString()]);
        });

        return $this->execute($row, $actor, $uuid);
    }

    /** Polls only the retained calculation ID. A lost initial response never creates a second calculation. */
    public function reconcile(int $tenantId, User|SubscriptionShopifyCustomerActor $actor, int $agreementId, string $uuid): array
    {
        $this->outsideTransaction();
        abort_unless(Str::isUuid($uuid), 422);
        $uuid = strtolower($uuid);
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->authorize($row, $actor, false, false);
        $requested = $this->required($row, $uuid, 'requested');
        $sent = $this->action($row, $uuid, 'commit_sent');
        abort_unless($requested->actor_reference === (string) $actor->id || $sent, 403);
        if ($applied = $this->appliedAction($row, $uuid)) {
            SubscriptionBillingAuthorization::assertCurrent($row);

            return $this->applied($row, $applied);
        }
        if ($this->action($row, $uuid, 'aborted')) {
            return ['status' => 'aborted', 'request_uuid' => $uuid, 'charges_customer' => false, 'changes_contract' => false];
        }
        if ($this->action($row, $uuid, 'commit_rejected') || $this->action($row, $uuid, 'commit_not_sent')) {
            return ['status' => 'commit_closed', 'request_uuid' => $uuid, 'charges_customer' => false, 'resend_allowed' => false];
        }
        if ($sent) {
            $contract = app(SubscriptionShopifyBillingTransport::class)->updateRead($row, 'contract')['subscriptionContract'] ?? [];

            return $this->completeCommit($row, $uuid, $contract);
        }
        if ($this->action($row, $uuid, 'commit_requested')) {
            return ['status' => 'commit_prepared', 'request_uuid' => $uuid, 'charges_customer' => false, 'changes_contract' => false];
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
    public function abort(int $tenantId, User|SubscriptionShopifyCustomerActor $actor, int $agreementId, string $uuid): array
    {
        abort_unless(! $actor instanceof SubscriptionShopifyCustomerActor || $actor->interactive, 403);
        $this->outsideTransaction();
        abort_unless(Str::isUuid($uuid), 422);
        $uuid = strtolower($uuid);
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);

        return $this->locked($row, function ($current) use ($actor, $uuid) {
            $this->authorize($current, $actor, false, true);
            $request = $this->required($current, $uuid, 'requested', true);
            abort_unless($request->actor_reference === (string) $actor->id, 403);
            abort_if($this->action($current, $uuid, 'commit_sent', true)
                && ! $this->action($current, $uuid, 'commit_rejected', true) && ! $this->action($current, $uuid, 'commit_not_sent', true),
                409, 'Reconcile the dispatched original commit before closing this update.');
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
            $actor = $this->actor($current, $request);
            $this->assertSendable($current, $request, $actor);
            abort_if($this->action($current, $uuid, 'calculate_sent', true), 409, 'The original calculation was already dispatched; reconcile it without resending.');
            $this->append($current, $uuid, 'calculate_sent', $actor, $current->revision,
                ['intent_hash' => $request->request['intent']['intent_hash']], [], ['dispatched_at' => now()->toISOString()]);
        });

        return $this->locked($row, function ($current) use ($uuid, $send) {
            $request = $this->required($current, $uuid, 'requested', true);
            $this->required($current, $uuid, 'calculate_sent', true);
            $this->assertSendable($current, $request, $this->actor($current, $request));

            return $send($current, $request->request['intent']);
        });
    }

    public function commit(int $tenantId, User|SubscriptionShopifyCustomerActor $actor, int $agreementId, string $uuid, int $revision, string $hash, array $approval): array
    {
        $this->outsideTransaction();
        abort_unless(! $actor instanceof SubscriptionShopifyCustomerActor || $actor->interactive, 403, 'Confirm from your authenticated account.');
        abort_unless(Str::isUuid($uuid) && $revision >= 1 && preg_match('/\A[a-f0-9]{64}\z/', $hash), 422);
        $uuid = strtolower($uuid);
        $approval = Validator::make(['approval' => $approval], ['approval' => ['required', 'array:reference,quoted_total_reviewed,continuing_authorization_reviewed'],
            'approval.reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:\/-]{1,190}\z/'],
            'approval.quoted_total_reviewed' => ['required', 'accepted'], 'approval.continuing_authorization_reviewed' => ['required', 'accepted']])->validate()['approval'];
        $approval['quoted_total_reviewed'] = $approval['continuing_authorization_reviewed'] = true;
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->authorize($row, $actor, false, false);
        $request = $this->required($row, $uuid, 'requested');
        $quoted = $this->required($row, $uuid, 'quoted');
        abort_unless($request->actor_reference === (string) $actor->id && $request->expected_revision === $revision
            && hash_equals($quoted->after_state['quote']['review_hash'], $hash), 409, 'Accept the exact original actor, request, revision and quote.');
        $review = ['request_uuid' => $uuid, 'approval' => $approval, 'quote_hash' => $hash, 'review_revision' => $revision];
        if ($actor instanceof SubscriptionShopifyCustomerActor) {
            abort_unless($approval['reference'] === 'delivery-policy:'.$actor->proof['policy_uuid']
                && SubscriptionEvidence::canonical($request->request['customer_actor'] ?? []) === SubscriptionEvidence::canonical($actor->proof), 409, 'Confirm your original policy and delivery review.');
            $review['customer_confirmation'] = $actor->proof + ['request_uuid' => $uuid, 'revision' => $revision,
                'quote_hash' => $hash, 'quoted_terms_hash' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($quoted->after_state['quote']['terms'])),
                'confirmed_total_cents' => $quoted->after_state['quote']['terms']['amount_cents'], 'confirmed' => true];
        }
        if ($prior = $this->action($row, $uuid, 'commit_requested')) {
            abort_unless($prior->request === $review, 409, 'This native approval already records a different review.');
            if ($this->action($row, $uuid, 'commit_sent') || $this->appliedAction($row, $uuid)) {
                return $this->reconcile($tenantId, $actor, $agreementId, $uuid);
            }
        }
        $this->assertCommitReady($row, $uuid, $actor, false);
        $transport = app(SubscriptionShopifyBillingTransport::class);
        $fresh = $transport->updateRead($row, 'calculation', $quoted->after_state['quote']['calculation_id']);
        $quote = app(SubscriptionShopifyUpdateProtocol::class)->quote($row, $request->request['intent'], $fresh['subscriptionContractCalculation'] ?? []);
        abort_unless(SubscriptionEvidence::canonical($quote) === SubscriptionEvidence::canonical($quoted->after_state['quote'])
            && SubscriptionEvidence::canonical($transport->updateRead($row, 'contract')['subscriptionContract'] ?? []) === SubscriptionEvidence::canonical($request->request['intent']['before']),
            409, 'Review the changed original contract or calculation before committing.');
        $authority = app(SubscriptionShopifyExecutionService::class)->amendmentAuthority($row,
            $actor instanceof SubscriptionShopifyCustomerActor ? User::findOrFail($actor->proof['merchant_reviewer_id']) : $actor);
        $this->locked($row, function ($current) use ($uuid, $actor, $review, $revision) {
            $this->assertCommitReady($current, $uuid, $actor, true);
            if ($prior = $this->action($current, $uuid, 'commit_requested', true)) {
                abort_unless($prior->request === $review, 409);
            } else {
                $this->append($current, $uuid, 'commit_requested', $actor, $revision, $review, [], ['reviewed_at' => now()->toISOString()]);
            }
        });
        try {
            $data = $transport->updateCommit($row, $uuid, $authority);
        } catch (\Throwable $error) {
            if ($this->action($row, $uuid, 'commit_sent')) {
                if ($this->action($row, $uuid, 'commit_not_sent')) {
                    return ['status' => 'commit_closed', 'request_uuid' => $uuid, 'charges_customer' => false, 'resend_allowed' => false];
                }

                return $this->commitUnknown($uuid);
            }
            throw $error;
        }
        $payload = $data['subscriptionContractCalculationCommit'] ?? [];
        $this->locked($row, function ($current) use ($uuid, $payload) {
            $request = $this->required($current, $uuid, 'requested', true);
            $actor = $this->actor($current, $request);
            if (! $this->action($current, $uuid, 'commit_response', true)) {
                $this->append($current, $uuid, 'commit_response', $actor, $request->expected_revision, [], [], ['payload' => $payload]);
            }
            if (is_array($payload['userErrors'] ?? null) && $payload['userErrors'] !== [] && ($payload['contract'] ?? null) === null
                && ! $this->action($current, $uuid, 'commit_rejected', true)) {
                $this->append($current, $uuid, 'commit_rejected', $actor, $request->expected_revision, [], [], ['payload' => $payload]);
            }
        });
        if (($payload['userErrors'] ?? null) !== [] || ! is_array($payload['contract'] ?? null)) {
            if ($this->action($row, $uuid, 'commit_rejected')) {
                return ['status' => 'commit_closed', 'request_uuid' => $uuid, 'charges_customer' => false, 'resend_allowed' => false];
            }

            return $this->commitUnknown($uuid);
        }

        return $this->completeCommit($row, $uuid, $payload['contract']);
    }

    /** One committed claim precedes HTTP. A known failure before invoking HTTP is retained separately. */
    public function dispatchCommit(SubscriptionBillingAgreement $row, string $uuid, array $authority, \Closure $send): array
    {
        $this->outsideTransaction();
        $this->locked($row, function ($current) use ($uuid, $authority) {
            $request = $this->required($current, $uuid, 'requested', true);
            $actor = $this->actor($current, $request);
            $this->assertCommitReady($current, $uuid, $actor, true);
            $this->required($current, $uuid, 'commit_requested', true);
            abort_if($this->action($current, $uuid, 'commit_sent', true), 409, 'The original commit cannot be sent twice.');
            app(SubscriptionShopifyExecutionService::class)->assertAmendmentAuthority($current, $authority);
            $this->append($current, $uuid, 'commit_sent', $actor, $request->expected_revision,
                ['request_uuid' => $uuid, 'intent_hash' => $request->request['intent']['intent_hash'], 'quote_hash' => $this->required($current, $uuid, 'quoted', true)->after_state['quote']['review_hash']], [],
                ['authority' => $authority, 'dispatched_at' => now()->toISOString()]);
        });
        $invoked = false;
        try {
            return $this->locked($row, function ($current) use ($uuid, $send, &$invoked) {
                $request = $this->required($current, $uuid, 'requested', true);
                $this->assertCommitReady($current, $uuid, $this->actor($current, $request), true);
                $sent = $this->required($current, $uuid, 'commit_sent', true);
                app(SubscriptionShopifyExecutionService::class)->assertAmendmentAuthority($current, $sent->after_state['authority']);
                $invoked = true;

                return $send($current, $this->required($current, $uuid, 'quoted', true)->after_state['quote']);
            });
        } catch (\Throwable $error) {
            if (! $invoked) {
                $this->locked($row, function ($current) use ($uuid) {
                    $request = $this->required($current, $uuid, 'requested', true);
                    if (! $this->action($current, $uuid, 'commit_not_sent', true)) {
                        $this->append($current, $uuid, 'commit_not_sent', $this->actor($current, $request), $request->expected_revision, [], [], ['checked_at' => now()->toISOString()]);
                    }
                });
            }
            throw $error;
        }
    }

    private function assertCommitReady(SubscriptionBillingAgreement $row, string $uuid, User|SubscriptionShopifyCustomerActor $actor, bool $currentRead): void
    {
        $this->assertSendable($row, $this->required($row, $uuid, 'requested', $currentRead), $actor);
        abort_unless(config('subscriptions.replacement_amendment_apply_enabled') === true
            && ($row->method_binding['environment'] === 'sandbox' || config('subscriptions.replacement_shopify_live_acceptance_verified') === true)
            && data_get($row->authorization_evidence, 'shopify_billing_acceptance.processor_context') === app(SubscriptionShopifyBillingTransport::class)->context($row, $currentRead)
            && CarbonImmutable::parse($this->required($row, $uuid, 'quoted', $currentRead)->after_state['quoted_at'])->betweenIncluded(now()->subMinutes(10), now()->addSeconds(5)),
            423, 'Current native apply/merchant authority and a fresh exact quote are required.');
        abort_if($this->action($row, $uuid, 'failed', $currentRead) || $this->action($row, $uuid, 'commit_rejected', $currentRead)
            || $this->action($row, $uuid, 'commit_not_sent', $currentRead), 409);
    }

    private function completeCommit(SubscriptionBillingAgreement $row, string $uuid, array $contract): array
    {
        try {
            return $this->locked($row, function ($current) use ($uuid, $contract) {
                if ($applied = $this->appliedAction($current, $uuid, true)) {
                    SubscriptionBillingAuthorization::assertCurrent($current, true);

                    return $this->applied($current, $applied);
                }
                $request = $this->required($current, $uuid, 'requested', true);
                $quoted = $this->required($current, $uuid, 'quoted', true);
                $approval = $this->required($current, $uuid, 'commit_requested', true);
                $sent = $this->required($current, $uuid, 'commit_sent', true);
                abort_if($this->action($current, $uuid, 'commit_rejected', true) || $this->action($current, $uuid, 'commit_not_sent', true), 409);
                SubscriptionBillingAuthorization::assertCurrent($current, true);
                $intent = $request->request['intent'];
                $stops = SubscriptionShopifyUpdateHistory::stops($current, $intent, true);
                $state = $stops['state'];
                $frozen = clone $current;
                $frozen->revision = $request->expected_revision;
                $frozen->status = $intent['local_state']['status'];
                $frozen->next_billing_date = $intent['local_state']['next_billing_date'];
                $frozen->resume_on = $intent['local_state']['resume_on'];
                $statuses = [$intent['before']['status']];
                foreach ($stops['actions'] as $stop) {
                    $statuses[] = $stop['kind'] === 'pause' ? 'PAUSED' : 'CANCELLED';
                }
                app(SubscriptionShopifyUpdateProtocol::class)->assertRetainedApplied($frozen, $intent, $quoted->after_state['quote'], $contract, $statuses);
                $actor = $this->actor($current, $request);
                $result = $this->append($current, $uuid, 'commit_result', $actor, $request->expected_revision,
                    ['intent_hash' => $intent['intent_hash'], 'quote_hash' => $quoted->after_state['quote']['review_hash']], [], ['contract' => $contract, 'user_errors' => [], 'confirmed_at' => now()->toISOString()]);
                $terms = $quoted->after_state['quote']['terms'];
                $hash = SubscriptionBillingTermsHistory::termsHash($current, $terms, $uuid);
                $action = new SubscriptionBillingAction(['tenant_id' => $current->tenant_id, 'agreement_id' => $current->id, 'request_uuid' => $uuid,
                    'kind' => 'terms_amended', 'actor_type' => $request->actor_type, 'actor_reference' => $request->actor_reference, 'expected_revision' => $current->revision,
                    'request' => ['version' => 1, 'agreement_public_id' => $current->public_id, 'member_id' => $current->member_id,
                        'original_terms_hash' => SubscriptionBillingAuthorization::original($current)['terms_hash'], 'accepted_by' => $actor->id, 'approval' => $approval->request['approval'],
                        ...($actor instanceof SubscriptionShopifyCustomerActor ? ['customer_confirmation' => $approval->request['customer_confirmation'],
                            'continuing_authorization_reviewed_by' => $actor->proof['merchant_reviewer_id']] : []),
                        'native_update' => ['requested' => SubscriptionShopifyUpdateHistory::snapshot($request), 'quoted' => SubscriptionShopifyUpdateHistory::snapshot($quoted),
                            'commit_requested' => SubscriptionShopifyUpdateHistory::snapshot($approval), 'commit_sent' => SubscriptionShopifyUpdateHistory::snapshot($sent),
                            'commit_result' => SubscriptionShopifyUpdateHistory::snapshot($result), 'stops' => $stops['actions'], 'state_before_application' => $state]],
                    'before_state' => ['terms' => $current->terms, 'terms_hash' => $current->terms_hash],
                    'after_state' => ['terms' => $terms, 'terms_hash' => $hash, 'revision' => $current->revision + 1,
                        'effective_after_resume' => $current->status === 'paused',
                        'effective_from' => $current->status === 'paused' ? null : $current->next_billing_date?->toDateString()]]);
                $action->after_state = $action->after_state + ['authorization_hash' => SubscriptionBillingTermsHistory::seal($action)];
                $action->save();
                $current->update(['terms' => $terms, 'terms_hash' => $hash, 'revision' => $current->revision + 1]);
                SubscriptionBillingAuthorization::assertCurrent($current->fresh(), true);

                return $this->applied($current, $action);
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return $this->commitUnknown($uuid);
        }
    }

    public function assertNoPendingCommit(SubscriptionBillingAgreement $row, bool $currentRead = false): void
    {
        abort_if($this->pendingCommitUuid($row, $currentRead) !== null, 409,
            'Your delivery update is still being confirmed. You can still pause or cancel future billing.');
    }

    public function pendingCommitUuid(SubscriptionBillingAgreement $row, bool $currentRead = false): ?string
    {
        if ($row->processor !== 'shopify') {
            return null;
        }
        $claim = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->whereIn('kind', ['shopify_update_commit_requested', 'shopify_update_commit_sent'])
            ->orderByDesc('id')->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        if (! $claim) {
            return null;
        }
        $this->assertAction($claim);
        $uuid = $claim->request['request_uuid'];
        $this->required($row, $uuid, 'commit_requested', $currentRead);
        if ($claim->kind === 'shopify_update_commit_sent') {
            $this->required($row, $uuid, 'commit_sent', $currentRead);
        }

        return $this->appliedAction($row, $uuid, $currentRead) || $this->action($row, $uuid, 'aborted', $currentRead)
            || $this->action($row, $uuid, 'commit_rejected', $currentRead) || $this->action($row, $uuid, 'commit_not_sent', $currentRead) ? null : $uuid;
    }

    private function appliedAction(SubscriptionBillingAgreement $row, string $uuid, bool $lock = false): ?SubscriptionBillingAction
    {
        $action = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('request_uuid', $uuid)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($action) {
            $this->assertAction($action);
            abort_unless($action->kind === 'terms_amended' && isset($action->request['native_update']), 409);
        }

        return $action;
    }

    private function applied(SubscriptionBillingAgreement $row, SubscriptionBillingAction $action): array
    {
        return ['status' => 'applied', 'request_uuid' => $action->request_uuid, 'applied_revision' => $action->after_state['revision'],
            'terms_hash' => $action->after_state['terms_hash'], 'current_state' => app(SubscriptionBillingAgreementService::class)->state($row), 'charges_customer' => false];
    }

    private function commitUnknown(string $uuid): array
    {
        return ['status' => 'commit_unknown', 'request_uuid' => $uuid, 'charges_customer' => false, 'resend_allowed' => false];
    }

    private function execute(SubscriptionBillingAgreement $row, User|SubscriptionShopifyCustomerActor $actor, string $uuid): array
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
                && SubscriptionShopifyUpdateProtocol::calculationId($calculation['id'] ?? null), 409, 'Retain the original calculation reference; do not submit another update.');
            $actor = $this->actor($current, $request);
            $received = $this->action($current, $uuid, 'received', true);
            // A result of the fixed original-ID poll can become a terminal concrete type/ID. Keep the initial ID immutable.
            // A success must still prove the unique amendment marker and exact original contract/line/price/method intent below.
            abort_unless(! $received || $received->after_state['calculation']['id'] === $calculation['id']
                || (data_get($received->after_state, 'calculation.__typename') === 'SubscriptionContractCalculationPending'
                    && in_array($calculation['__typename'], ['SubscriptionContractCalculationSuccess', 'SubscriptionContractCalculationFailure'], true)),
                409, 'The original calculation reference differs.');
            if (! $received) {
                $this->append($current, $uuid, 'received', $actor, $request->expected_revision,
                    ['intent_hash' => $request->request['intent']['intent_hash']], [], ['calculation' => $calculation,
                        'user_errors' => array_map(fn ($error) => ['code' => $error['code'] ?? null, 'field' => $error['field'] ?? null], $errors), 'received_at' => now()->toISOString()]);
            }
        });

        return $this->locked($row, function ($current) use ($uuid, $calculation, $errors) {
            $request = $this->required($current, $uuid, 'requested', true);
            $received = $this->required($current, $uuid, 'received', true);
            $actor = $this->actor($current, $request);
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

    private function assertSendable(SubscriptionBillingAgreement $row, SubscriptionBillingAction $request, User|SubscriptionShopifyCustomerActor $actor): void
    {
        $this->sameRequest($request, $actor, $request->request['input'], $request->expected_revision);
        $this->authorize($row, $actor, true, true);
        if ($actor instanceof SubscriptionShopifyCustomerActor) {
            $actor->permits($row, $request->request['input'], true);
        }
        SubscriptionBillingAuthorization::assertCurrent($row, true);
        app(SubscriptionBillingAmendmentService::class)->assertFuture($row, true);
        $intent = $request->request['intent'];
        abort_unless($row->revision === $request->expected_revision && $row->terms_hash === $intent['subject']['current_terms_hash']
            && SubscriptionEvidence::canonical($intent['subject']) === SubscriptionEvidence::canonical(app(SubscriptionAmendmentProposal::class)->subject($row))
            && SubscriptionEvidence::canonical($intent['context']) === SubscriptionEvidence::canonical(app(SubscriptionShopifyBillingTransport::class)->context($row, true))
            && ! $this->action($row, $request->request['request_uuid'], 'aborted', true), 409, 'The original review or provider authority changed before dispatch.');
    }

    private function authorize(SubscriptionBillingAgreement $row, User|SubscriptionShopifyCustomerActor $actor, bool $edit, bool $currentRead): void
    {
        if ($actor instanceof SubscriptionShopifyCustomerActor) {
            $actor->assertCurrent($row, $edit, $currentRead);

            return;
        }
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
        return SubscriptionShopifyUpdateHistory::key($row, $uuid, $phase);
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

    private function append(SubscriptionBillingAgreement $row, string $uuid, string $phase, User|SubscriptionShopifyCustomerActor $actor, int $revision, array $request, array $before, array $after): SubscriptionBillingAction
    {
        $action = new SubscriptionBillingAction(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id,
            'request_uuid' => $this->key($row, $uuid, $phase), 'kind' => 'shopify_update_'.$phase,
            'actor_type' => $actor instanceof SubscriptionShopifyCustomerActor ? 'shopify_customer' : 'user', 'actor_reference' => (string) $actor->id,
            'expected_revision' => $revision, 'request' => $request, 'before_state' => $before, 'after_state' => $after]);
        $after['authorization_hash'] = SubscriptionBillingTermsHistory::seal($action);
        $action->after_state = $after;
        $action->save();

        return $action;
    }

    private function sameRequest(SubscriptionBillingAction $prior, User|SubscriptionShopifyCustomerActor $actor, array $input, int $revision): void
    {
        abort_unless($prior->actor_type === ($actor instanceof SubscriptionShopifyCustomerActor ? 'shopify_customer' : 'user')
            && $prior->actor_reference === (string) $actor->id && $prior->expected_revision === $revision
            && (! $actor instanceof SubscriptionShopifyCustomerActor || SubscriptionEvidence::canonical($prior->request['customer_actor'] ?? []) === SubscriptionEvidence::canonical($actor->proof))
            && SubscriptionEvidence::canonical($prior->request['input']) === SubscriptionEvidence::canonical($input), 409, 'This request ID belongs to another native update review.');
    }

    private function quoted(SubscriptionBillingAction $action, string $uuid): array
    {
        return ['status' => 'quote_ready', 'request_uuid' => $uuid, 'quote' => $action->after_state['quote'], 'quoted_at' => $action->after_state['quoted_at'],
            'charges_customer' => false, 'changes_contract' => false,
            'commit_available' => config('subscriptions.replacement_amendment_apply_enabled') === true && config('subscriptions.replacement_shopify_amendments_enabled') === true];
    }

    private function unknown(string $uuid): array
    {
        return ['status' => 'calculation_unknown', 'request_uuid' => $uuid, 'charges_customer' => false, 'changes_contract' => false, 'resend_allowed' => false];
    }

    public function authorizePolicy(SubscriptionBillingAgreement $row, User $actor, bool $currentRead = false): void
    {
        $this->authorize($row, $actor, true, $currentRead);
        abort_unless(config('subscriptions.replacement_amendment_apply_enabled') === true, 423, 'Customer delivery policy requires reviewed native apply authority.');
    }

    private function actor(SubscriptionBillingAgreement $row, SubscriptionBillingAction $request): User|SubscriptionShopifyCustomerActor
    {
        return $request->actor_type === 'shopify_customer'
            ? SubscriptionShopifyCustomerActor::fromJournal($row, $request)
            : User::findOrFail((int) $request->actor_reference);
    }
}
