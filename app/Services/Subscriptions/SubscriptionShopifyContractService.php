<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Calculate and adopt the original saved method; no collection, source cancellation or local activation. */
class SubscriptionShopifyContractService
{
    public function plan(int $tenantId, User $actor, int $agreementId): array
    {
        $row = $this->row($tenantId, $actor, $agreementId);

        return $this->summary($row) + ['terms_hash' => $row->terms_hash, 'revision' => $row->revision, 'currency' => $row->terms['currency'],
            'amount_cents' => $row->terms['amount_cents'], 'first_billing_date' => $row->terms['first_billing_date'],
            'provider_requests' => false, 'source_terms_only' => true];
    }

    public function calculate(int $tenantId, User $actor, int $agreementId, string $expectedHash, int $expectedRevision): array
    {
        $row = $this->row($tenantId, $actor, $agreementId);
        $this->mutable($row, $expectedHash, $expectedRevision);
        $state = $this->state($row);
        if ($state !== []) {
            if (isset($state['calculation_id'])) {
                return $this->refresh($tenantId, $actor, $agreementId);
            }
            abort_if(isset($state['calculate_dispatched_at']), 409, 'The original calculation result is unconfirmed; do not create another request.');
            $proof = $this->method($row, $actor);
            abort_unless($proof['processor_context'] === $state['original']['processor_context'], 409);

            return $this->sendCalculation($row, $actor);
        }
        $proof = $this->method($row, $actor);
        $uuid = (string) Str::uuid();
        $original = ['request_uuid' => $uuid, 'actor_id' => $actor->id, 'terms_hash' => $row->terms_hash, 'agreement_revision' => $row->revision,
            'processor_context' => $proof['processor_context'], 'source_context' => $proof['source']['credential_context'],
            'input' => SubscriptionShopifyContractProtocol::input($row, $uuid), 'created_at' => now()->toISOString()];
        DB::transaction(function () use ($row, $actor, $original) {
            $current = $this->locked($row);
            $this->mutable($current, $row->terms_hash, $row->revision);
            app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
            abort_unless($this->state($current) === [] && $original['processor_context'] === $this->transport()->context($current), 409, 'The original calculation intent changed.');
            $this->saveState($current, ['status' => 'calculation_prepared', 'original' => $original]);
            $this->audit($current, $actor, 'shopify_calculation_requested', $original, $original['request_uuid']);
        });

        return $this->sendCalculation($row->fresh(), $actor);
    }

    private function sendCalculation(SubscriptionBillingAgreement $row, User $actor): array
    {
        try {
            $data = $this->transport()->contractMutation($row->fresh(), 'calculate');
        } catch (\Throwable $error) {
            $fresh = $row->fresh();
            if (! isset($this->state($fresh)['calculate_dispatched_at'])) {
                throw $error;
            }
            $this->updateState($fresh, ['status' => 'calculation_unconfirmed', 'last_error_code' => 'original_calculation_result_unconfirmed']);

            return $this->summary($fresh->fresh());
        }
        $payload = $data['subscriptionContractCreateCalculate'] ?? [];
        if (! is_array($payload) || ! is_array($payload['userErrors'] ?? null)
            || (empty($payload['userErrors']) && ! is_string(data_get($payload, 'subscriptionContractCalculation.id')))) {
            $this->updateState($row->fresh(), ['status' => 'calculation_unconfirmed', 'last_error_code' => 'original_calculation_result_incomplete']);

            return $this->summary($row->fresh());
        }
        if (! empty($payload['userErrors'])) {
            $this->updateState($row->fresh(), ['status' => 'calculation_rejected', 'last_error_code' => 'shopify_calculation_rejected']);

            return $this->summary($row->fresh());
        }

        return $this->acceptCalculation($row->fresh(), $actor, $payload['subscriptionContractCalculation'] ?? []);
    }

    public function refresh(int $tenantId, User $actor, int $agreementId): array
    {
        $row = $this->row($tenantId, $actor, $agreementId);
        $state = $this->state($row);
        abort_unless(isset($state['calculation_id']), 409, 'No original calculation is available to refresh.');
        $data = $this->transport()->contractRead($row, 'calculation');

        return $this->acceptCalculation($row, $actor, $data['subscriptionContractCalculation'] ?? []);
    }

    public function adopt(int $tenantId, User $actor, int $agreementId, string $reviewHash, int $expectedRevision): array
    {
        $row = $this->row($tenantId, $actor, $agreementId);
        $this->mutable($row, $row->terms_hash, $expectedRevision);
        $state = $this->state($row);
        if (isset($state['commit_original'])) {
            abort_unless(hash_equals($state['commit_original']['review_hash'], $reviewHash), 409, 'Recover only the original reviewed contract adoption.');
            if (isset($state['commit_dispatched_at'])) {
                return $this->recover($tenantId, $actor, $agreementId);
            }
            $calculation = $this->transport()->contractRead($row, 'reviewed_calculation')['subscriptionContractCalculation'] ?? [];
            $quote = SubscriptionShopifyContractProtocol::quote($row, $calculation, $state['original']['request_uuid']);
            abort_unless(hash_equals($reviewHash, $quote['review_hash']) && $quote['calculation_id'] === $state['commit_original']['calculation_id'], 409, 'The original reviewed calculation changed before dispatch.');
            $method = $this->method($row, $actor);
            abort_unless($method['processor_context'] === $state['original']['processor_context'], 409);
            $proof = $this->sourceStopped($row, $actor);
            DB::transaction(function () use ($row, $actor, $state, $quote, $proof) {
                $current = $this->locked($row);
                $this->mutable($current, $row->terms_hash, $row->revision);
                app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
                $latest = $this->state($current);
                abort_unless(($latest['original'] ?? null) === $state['original'] && ($latest['commit_original'] ?? null) === $state['commit_original']
                    && ! isset($latest['commit_dispatched_at']) && ($latest['terminal_calculation_id'] ?? $quote['calculation_id']) === $quote['calculation_id']
                    && $this->fresh($proof) && $state['original']['processor_context'] === $this->transport()->context($current), 409, 'The original reviewed adoption changed before dispatch.');
                // Older prepared intents gain a terminal ID only from this freshly verified original review.
                $this->saveState($current, array_replace($latest, ['terminal_calculation_id' => $quote['calculation_id'], 'quote' => $quote, 'commit_send_proof' => $proof]));
            });

            return $this->sendCommit($row->fresh(), $actor);
        }
        $this->refresh($tenantId, $actor, $agreementId);
        $row = $row->fresh();
        $state = $this->state($row);
        abort_unless($state['status'] === 'calculated' && hash_equals($state['quote']['review_hash'], $reviewHash), 409, 'Review the exact current calculated contract before committing.');
        $method = $this->method($row, $actor);
        $proof = $this->sourceStopped($row, $actor);
        abort_unless(isset($state['terminal_calculation_id']) && $state['terminal_calculation_id'] === $state['quote']['calculation_id'], 409, 'Verify the successful calculation before adoption.');
        $commit = ['calculation_id' => $state['terminal_calculation_id'], 'review_hash' => $reviewHash, 'actor_id' => $actor->id,
            'processor_context' => $method['processor_context'], 'source_stop_proof' => $proof, 'prepared_at' => now()->toISOString()];
        DB::transaction(function () use ($row, $actor, $commit) {
            $current = $this->locked($row);
            $this->mutable($current, $row->terms_hash, $row->revision);
            $state = $this->state($current);
            app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
            abort_unless(! isset($state['commit_original']) && $state['status'] === 'calculated' && $state['quote']['review_hash'] === $commit['review_hash']
                && $state['terminal_calculation_id'] === $commit['calculation_id'] && $state['quote']['calculation_id'] === $commit['calculation_id'] && $this->fresh($commit['source_stop_proof'])
                && $commit['processor_context'] === $this->transport()->context($current), 409, 'Original contract or source-stop proof changed before adoption.');
            $this->saveState($current, array_replace($state, ['commit_original' => $commit, 'commit_send_proof' => $commit['source_stop_proof'], 'status' => 'commit_prepared']));
            $this->audit($current, $actor, 'shopify_commit_requested', $commit);
        });

        return $this->sendCommit($row->fresh(), $actor);
    }

    private function sendCommit(SubscriptionBillingAgreement $row, User $actor): array
    {
        try {
            $data = $this->transport()->contractMutation($row->fresh(), 'commit');
        } catch (\Throwable $error) {
            $fresh = $row->fresh();
            if (! isset($this->state($fresh)['commit_dispatched_at'])) {
                throw $error;
            }
            $this->updateState($fresh, ['status' => 'commit_unconfirmed', 'last_error_code' => 'original_contract_commit_unconfirmed']);

            return $this->summary($fresh->fresh());
        }
        $payload = $data['subscriptionContractCalculationCommit'] ?? [];
        if (! is_array($payload) || ! is_array($payload['userErrors'] ?? null)
            || (empty($payload['userErrors']) && ! is_string(data_get($payload, 'contract.id')))) {
            $this->updateState($row->fresh(), ['status' => 'commit_unconfirmed', 'last_error_code' => 'original_contract_commit_result_incomplete']);

            return $this->summary($row->fresh());
        }
        if (! empty($payload['userErrors'])) {
            $this->updateState($row->fresh(), ['status' => 'commit_rejected', 'last_error_code' => 'original_contract_commit_rejected']);

            return $this->summary($row->fresh());
        }
        $this->bindContract($row->fresh(), $actor, $payload['contract'] ?? []);

        return $this->finish($row->fresh(), $actor);
    }

    /** Recover the original UUID/contract only; an empty or incomplete read never authorizes another commit. */
    public function recover(int $tenantId, User $actor, int $agreementId): array
    {
        $row = $this->row($tenantId, $actor, $agreementId);
        $this->mutable($row, $row->terms_hash, $row->revision, true);
        $state = $this->state($row);
        abort_unless(isset($state['commit_original'], $state['commit_dispatched_at']), 409, 'There is no dispatched original commit to recover.');
        if (! isset($state['contract_id'])) {
            $data = $this->transport()->contractRead($row, 'customer_contracts');
            abort_unless(data_get($data, 'customer.id') === 'gid://shopify/Customer/'.$row->terms['shopify_customer_id']
                && data_get($data, 'customer.subscriptionContracts.pageInfo.hasNextPage') === false, 409, 'Original customer contract recovery is incomplete.');
            $matches = collect(data_get($data, 'customer.subscriptionContracts.nodes', []))->filter(function ($contract) use ($row, $state) {
                $attributes = collect($contract['customAttributes'] ?? [])->pluck('value', 'key');

                return data_get($contract, 'app.id') === $row->method_binding['app_gid']
                    && $attributes->get('everbranch_agreement') === $row->public_id
                    && $attributes->get('everbranch_adoption') === $state['original']['request_uuid'];
            })->values();
            if ($matches->count() !== 1) {
                $this->updateState($row, ['status' => 'commit_unconfirmed', 'last_error_code' => $matches->count() > 1 ? 'multiple_original_contracts_need_review' : 'original_contract_not_yet_found']);

                return $this->summary($row->fresh());
            }
            $this->bindContract($row, $actor, $matches->first());
        }

        return $this->finish($row->fresh(), $actor);
    }

    private function finish(SubscriptionBillingAgreement $row, User $actor): array
    {
        $contract = $this->ownedContract($row);
        // Only the same immutable contract is patched. These schedule commands create no order/payment.
        if (($contract['status'] ?? null) !== 'PAUSED') {
            abort_unless(($contract['status'] ?? null) === 'ACTIVE', 409, 'The adopted contract status needs review.');
            $this->audit($row, $actor, 'shopify_adoption_pause_requested', ['contract_id' => $this->state($row)['contract_id']]);
            $result = $this->transport()->contractMutation($row, 'pause');
            abort_unless(empty($result['subscriptionContractPause']['userErrors']), 409, 'The original adopted contract could not be paused.');
            $contract = $this->ownedContract($row->fresh());
        }
        abort_unless(($contract['status'] ?? null) === 'PAUSED', 409, 'Confirm that the adopted contract is paused before continuing.');
        if (! $this->pausedTermsMatch($row, $contract)) {
            return $this->summary($row->fresh());
        }
        $clock = now($row->terms['timezone']);
        if ($row->terms['first_billing_date'] < $clock->toDateString()
            || ($row->terms['first_billing_date'] === $clock->toDateString() && $clock->hour >= $row->terms['billing_hour'])) {
            $this->updateState($row, ['status' => 'adopted_paused_date_review', 'last_error_code' => 'original_billing_day_needs_new_review']);

            return $this->summary($row->fresh());
        }
        $date = SubscriptionShopifyContractProtocol::firstBillingInstant($row);
        if (! $this->sameInstant($contract['nextBillingDate'] ?? null, $date)) {
            $this->audit($row, $actor, 'shopify_adoption_date_requested', ['contract_id' => $this->state($row)['contract_id'], 'date' => $date]);
            $result = $this->transport()->contractMutation($row, 'first_date');
            abort_unless(empty($result['subscriptionContractSetNextBillingDate']['userErrors']), 409, 'The original contract billing date needs review.');
            $contract = $this->ownedContract($row->fresh());
        }
        abort_unless(($contract['status'] ?? null) === 'PAUSED' && $this->sameInstant($contract['nextBillingDate'] ?? null, $date), 409, 'Original contract pause/date confirmation is incomplete.');
        if (! $this->pausedTermsMatch($row, $contract)) {
            return $this->summary($row->fresh());
        }
        DB::transaction(function () use ($row, $actor) {
            $current = $this->locked($row);
            app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
            $this->mutable($current, $row->terms_hash, $row->revision, true);
            $state = $this->state($current);
            if ($state['status'] !== 'adopted_paused') {
                $this->saveState($current, array_replace($state, ['status' => 'adopted_paused', 'confirmed_at' => now()->toISOString(), 'last_error_code' => null]));
                $this->audit($current, $actor, 'shopify_contract_adopted_paused', ['contract_id' => $state['contract_id'], 'collection_enabled' => false]);
            }
        });

        return $this->summary($row->fresh());
    }

    private function bindContract(SubscriptionBillingAgreement $row, User $actor, array $contract): void
    {
        $this->assertOwned($row, $contract);
        DB::transaction(function () use ($row, $actor, $contract) {
            $current = $this->locked($row);
            $this->mutable($current, $row->terms_hash, $row->revision, true);
            app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
            $state = $this->state($current);
            abort_unless(isset($state['commit_original']) && (! isset($state['contract_id']) || $state['contract_id'] === $contract['id']), 409);
            $this->saveState($current, array_replace($state, ['contract_id' => $contract['id'], 'status' => 'contract_created_needs_pause']));
        });
    }

    private function ownedContract(SubscriptionBillingAgreement $row): array
    {
        $contract = $this->transport()->contractRead($row, 'contract')['subscriptionContract'] ?? [];
        $this->assertOwned($row, $contract);
        abort_unless($contract['id'] === $this->state($row)['contract_id'], 409);

        return $contract;
    }

    private function assertOwned(SubscriptionBillingAgreement $row, array $contract): void
    {
        $attributes = collect($contract['customAttributes'] ?? [])->map(fn ($item) => [$item['key'] ?? null, $item['value'] ?? null])->sort()->values()->all();
        $expected = collect(SubscriptionShopifyContractProtocol::input($row, $this->state($row)['original']['request_uuid'])['customAttributes'])
            ->map(fn ($item) => [$item['key'], $item['value']])->sort()->values()->all();
        abort_unless(is_string($contract['id'] ?? null) && preg_match('/\Agid:\/\/shopify\/SubscriptionContract\/[1-9][0-9]*\z/', $contract['id'])
            && data_get($contract, 'app.id') === $row->method_binding['app_gid'] && $attributes === $expected
            && data_get($contract, 'customer.id') === 'gid://shopify/Customer/'.$row->terms['shopify_customer_id'], 409, 'The contract is not the original app/customer adoption.');
    }

    private function pausedTermsMatch(SubscriptionBillingAgreement $row, array $contract): bool
    {
        try {
            SubscriptionShopifyContractProtocol::assertTerms($row, $contract, $this->state($row)['original']['request_uuid']);

            return true;
        } catch (\Throwable) {
            $this->updateState($row, ['status' => 'adopted_paused_terms_review', 'last_error_code' => 'adopted_contract_terms_need_review']);

            return false;
        }
    }

    private function acceptCalculation(SubscriptionBillingAgreement $row, User $actor, array $calculation): array
    {
        $id = $calculation['id'] ?? null;
        abort_unless(SubscriptionShopifyContractProtocol::calculationId($id), 409, 'The original calculation result has no usable identity.');
        $type = $calculation['__typename'] ?? null;
        abort_unless(in_array($type, ['SubscriptionContractCalculationPending', 'SubscriptionContractCalculationFailure', 'SubscriptionContractCalculationSuccess'], true), 409, 'The original calculation state is unknown.');
        DB::transaction(function () use ($row, $actor, $calculation, $id, $type) {
            $current = $this->locked($row);
            abort_unless($current->terms_hash === $row->terms_hash && $current->revision === $row->revision
                && $this->state($current)['original'] === $this->state($row)['original'], 409);
            app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
            $state = $this->state($current);
            if (! isset($state['commit_original'])) {
                $initialId = $this->state($row)['calculation_id'] ?? $id;
                abort_unless(($state['calculation_id'] ?? $initialId) === $initialId, 409, 'The original polling reference changed.');
                abort_unless(! isset($state['terminal_calculation_id']) || ($type === 'SubscriptionContractCalculationSuccess' && $state['terminal_calculation_id'] === $id), 409, 'The verified successful calculation reference changed.');
                abort_unless($type !== 'SubscriptionContractCalculationPending' || $id === $initialId, 409, 'The original pending calculation reference changed.');
                $changes = ['calculation_id' => $initialId, 'checked_at' => now()->toISOString(), 'quote' => null];
                if ($type === 'SubscriptionContractCalculationPending') {
                    $changes['status'] = 'calculation_pending';
                } elseif ($type === 'SubscriptionContractCalculationFailure') {
                    $changes += ['status' => 'calculation_failed', 'last_error_code' => 'original_calculation_failed'];
                } else {
                    try {
                        $changes['quote'] = SubscriptionShopifyContractProtocol::quote($current, $calculation, $state['original']['request_uuid']);
                        $changes += ['terminal_calculation_id' => $id, 'status' => 'calculated', 'last_error_code' => null];
                    } catch (\Throwable) {
                        $changes += ['status' => 'calculation_review', 'last_error_code' => 'calculated_terms_or_totals_differ'];
                    }
                }
                $this->saveState($current, array_replace($state, $changes));
            }
        });

        return $this->summary($row->fresh());
    }

    private function method(SubscriptionBillingAgreement $row, User $actor): array
    {
        $order = data_get($row->authorization_evidence, 'provider_proof.source.source_order_id');
        abort_unless($row->method_verified_at && is_string($order), 409, 'Verify and retain the exact original saved-method mapping first.');

        return app(SubscriptionShopifyMethodService::class)->verify($row->tenant_id, $actor, $row->id, $order, false);
    }

    private function row(int $tenantId, User $actor, int $id): SubscriptionBillingAgreement
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->where('processor', 'shopify')->findOrFail($id);
        app(SubscriptionReplacementAccess::class)->owner($tenantId, $actor, $row->member_id, 'setup');
        app(SubscriptionReplacementAccess::class)->member($row);

        return $row;
    }

    private function mutable(SubscriptionBillingAgreement $row, string $hash, int $revision, bool $allowPastDateForPause = false): void
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        abort_unless(config('subscriptions.replacement_shopify_adoption_enabled') === true && $row->status === 'prepared' && ! $row->activated_at
            && $row->terms_hash === $hash && $row->revision === $revision && ($allowPastDateForPause || $row->terms['first_billing_date'] >= now($row->terms['timezone'])->toDateString())
            && ($row->method_binding['environment'] === 'sandbox' || config('subscriptions.replacement_shopify_live_acceptance_verified') === true), 423, 'Review the exact future agreement and isolated merchant acceptance before adoption.');
    }

    private function locked(SubscriptionBillingAgreement $row): SubscriptionBillingAgreement
    {
        SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();

        return SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
    }

    private function state(SubscriptionBillingAgreement $row): array
    {
        return data_get($row->authorization_evidence, 'shopify_adoption', []);
    }

    private function saveState(SubscriptionBillingAgreement $row, array $state): void
    {
        $evidence = $row->authorization_evidence;
        $evidence['shopify_adoption'] = $state;
        $row->update(['authorization_evidence' => $evidence]);
    }

    private function updateState(SubscriptionBillingAgreement $row, array $changes): void
    {
        DB::transaction(function () use ($row, $changes) {
            $current = $this->locked($row);
            $this->saveState($current, array_replace($this->state($current), $changes));
        });
    }

    private function audit(SubscriptionBillingAgreement $row, User $actor, string $kind, array $request, ?string $uuid = null): void
    {
        SubscriptionBillingAction::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'request_uuid' => $uuid ?? (string) Str::uuid(),
            'kind' => $kind, 'actor_type' => 'user', 'actor_reference' => (string) $actor->id, 'expected_revision' => $row->revision,
            'request' => $request, 'before_state' => [], 'after_state' => ['adoption_status' => $this->state($row)['status'] ?? null, 'collection_enabled' => false]]);
    }

    private function fresh(array $proof): bool
    {
        return is_string($proof['checked_at'] ?? null) && CarbonImmutable::parse($proof['checked_at'])->betweenIncluded(now()->subMinute(), now()->addSeconds(5));
    }

    private function sourceStopped(SubscriptionBillingAgreement $row, User $actor): array
    {
        $proof = app(SubscriptionPilotSourceGuard::class)->stopped(app(SubscriptionReplacementAccess::class)->member($row),
            $row->method_binding['source_connection_id'], $row->terms['first_billing_date'], $actor);
        abort_unless($proof['credential_revision_id'] === $row->method_binding['source_credential_revision_id'], 409, 'The original source credential changed.');

        return $proof;
    }

    private function sameInstant(mixed $actual, string $expected): bool
    {
        return is_string($actual) && CarbonImmutable::parse($actual)->eq(CarbonImmutable::parse($expected));
    }

    private function summary(SubscriptionBillingAgreement $row): array
    {
        $state = $this->state($row);

        return ['adoption_status' => $state['status'] ?? 'not_started', 'contract_adopted' => ($state['status'] ?? null) === 'adopted_paused',
            'contract_paused' => ($state['status'] ?? null) === 'adopted_paused', 'billing_owner_changed' => false, 'collection_enabled' => false,
            'quote' => $state['quote'] ?? null, 'last_error_code' => $state['last_error_code'] ?? null, 'reauthorization_required' => null];
    }

    private function transport(): SubscriptionShopifyBillingTransport
    {
        return app(SubscriptionShopifyBillingTransport::class);
    }
}
