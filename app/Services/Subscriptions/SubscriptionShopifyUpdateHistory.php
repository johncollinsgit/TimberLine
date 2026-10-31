<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Ramsey\Uuid\Uuid;

/** Signed original approval, single commit and later-stop proof; local historical reads require no provider access. */
class SubscriptionShopifyUpdateHistory
{
    public static function key(SubscriptionBillingAgreement $row, string $uuid, string $phase): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'everbranch:shopify-update:'.$row->tenant_id.':'.$row->id.':'.$uuid.':'.$phase);
    }

    public static function snapshot(SubscriptionBillingAction $action): array
    {
        return $action->only(['tenant_id', 'agreement_id', 'request_uuid', 'kind', 'actor_type', 'actor_reference', 'expected_revision', 'request', 'before_state', 'after_state']);
    }

    public static function journal(SubscriptionBillingAgreement $row, array $snapshot, string $phase, string $uuid): SubscriptionBillingAction
    {
        $action = new SubscriptionBillingAction($snapshot);
        $hash = $action->after_state['authorization_hash'] ?? null;
        abort_unless($action->tenant_id === $row->tenant_id && $action->agreement_id === $row->id
            && $action->request_uuid === self::key($row, $uuid, $phase) && $action->kind === 'shopify_update_'.$phase
            && $action->actor_type === 'user' && ctype_digit($action->actor_reference)
            && is_string($hash) && hash_equals(SubscriptionBillingTermsHistory::seal($action), $hash), 409, 'Retain the signed original native update journal.');

        return $action;
    }

    public static function stops(SubscriptionBillingAgreement $row, array $intent, bool $currentRead = false): array
    {
        $baseline = $intent['local_state']['revision'];
        $actions = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->whereIn('kind', ['pause', 'cancel'])->where('expected_revision', '>=', $baseline)
            ->where('expected_revision', '<', $row->revision)->orderBy('id')->when($currentRead, fn ($q) => $q->lockForUpdate())->get();
        $snapshots = $actions->map(fn ($action) => self::snapshot($action))->all();
        $state = app(SubscriptionBillingAgreementService::class)->state($row);
        self::stopState($row, $intent, $snapshots, $state);

        return ['actions' => $snapshots, 'state' => $state];
    }

    private static function stopState(SubscriptionBillingAgreement $row, array $intent, array $stops, array $expected): array
    {
        $state = $intent['local_state'];
        $statuses = [$intent['before']['status']];
        foreach ($stops as $snapshot) {
            $action = new SubscriptionBillingAction($snapshot);
            $hash = $action->after_state['authorization_hash'] ?? null;
            abort_unless($action->tenant_id === $row->tenant_id && $action->agreement_id === $row->id
                && in_array($action->kind, ['pause', 'cancel'], true) && $action->expected_revision === $state['revision']
                && is_string($hash) && hash_equals(SubscriptionBillingTermsHistory::seal($action), $hash)
                && SubscriptionEvidence::canonical($action->before_state) === SubscriptionEvidence::canonical($state), 409, 'A later native stop needs its original signed action chain.');
            $next = Arr::only($action->after_state, array_keys($state));
            abort_unless(($next['revision'] ?? null) === $state['revision'] + 1
                && ($next['status'] ?? null) === ($action->kind === 'pause' ? 'paused' : 'cancelled')
                && SubscriptionEvidence::canonical(Arr::except($next, ['revision', 'status', 'next_billing_date', 'resume_on']))
                    === SubscriptionEvidence::canonical(Arr::except($state, ['revision', 'status', 'next_billing_date', 'resume_on'])), 409);
            $state = $next;
            $statuses[] = $action->kind === 'pause' ? 'PAUSED' : 'CANCELLED';
        }
        abort_unless(SubscriptionEvidence::canonical($state) === SubscriptionEvidence::canonical($expected), 409, 'A positive schedule change or missing stop cannot be covered by an older native review.');

        return array_unique($statuses);
    }

    public static function assert(SubscriptionBillingAgreement $row, SubscriptionBillingAction $action): void
    {
        $native = $action->request['native_update'] ?? [];
        $uuid = $action->request_uuid;
        $request = self::journal($row, $native['requested'] ?? [], 'requested', $uuid);
        $quoted = self::journal($row, $native['quoted'] ?? [], 'quoted', $uuid);
        $approval = self::journal($row, $native['commit_requested'] ?? [], 'commit_requested', $uuid);
        $sent = self::journal($row, $native['commit_sent'] ?? [], 'commit_sent', $uuid);
        $result = self::journal($row, $native['commit_result'] ?? [], 'commit_result', $uuid);
        $intent = $request->request['intent'];
        $quote = $quoted->after_state['quote'];
        foreach ([$request, $quoted, $approval, $sent, $result] as $record) {
            abort_unless($record->actor_reference === $action->actor_reference && $record->expected_revision === $request->expected_revision, 409);
        }
        abort_unless($request->request['request_uuid'] === $uuid && $intent['request_uuid'] === $uuid
            && SubscriptionEvidence::canonical($request->before_state) === SubscriptionEvidence::canonical($action->before_state)
            && $request->expected_revision === $intent['subject']['revision'] && ($intent['variables']['actor'] ?? null) === 'MERCHANT'
            && $approval->request['approval'] === $action->request['approval']
            && $approval->request['quote_hash'] === $quote['review_hash'] && $approval->request['review_revision'] === $request->expected_revision
            && $sent->request['request_uuid'] === $uuid && $sent->request['intent_hash'] === $intent['intent_hash'] && $sent->request['quote_hash'] === $quote['review_hash']
            && $result->request['intent_hash'] === $intent['intent_hash'] && $result->request['quote_hash'] === $quote['review_hash']
            && $result->after_state['user_errors'] === [], 409, 'The accepted native update differs from its original approval or commit.');
        $before = clone $row;
        $before->terms = $action->before_state['terms'];
        $before->terms_hash = $action->before_state['terms_hash'];
        $before->revision = $request->expected_revision;
        $before->status = $intent['local_state']['status'];
        $before->next_billing_date = $intent['local_state']['next_billing_date'];
        $before->resume_on = $intent['local_state']['resume_on'];
        $protocol = app(SubscriptionShopifyUpdateProtocol::class);
        abort_unless(SubscriptionEvidence::canonical($protocol->retainedQuote($before, $intent, $quoted->after_state['calculation'])) === SubscriptionEvidence::canonical($quote)
            && SubscriptionEvidence::canonical($action->after_state['terms']) === SubscriptionEvidence::canonical($quote['terms']), 409, 'The retained native quote or applied terms changed.');
        $state = $native['state_before_application'] ?? [];
        abort_unless(($state['revision'] ?? null) === $action->expected_revision, 409);
        $statuses = self::stopState($row, $intent, $native['stops'] ?? [], $state);
        $protocol->assertRetainedApplied($before, $intent, $quote, $result->after_state['contract'], $statuses);
        $proof = $sent->after_state['authority'];
        $source = $proof['source_stop_proof'] ?? [];
        $method = $proof['method'] ?? [];
        abort_unless(($source['connection_id'] ?? null) === $row->method_binding['source_connection_id']
            && ($source['credential_revision_id'] ?? null) === $row->method_binding['source_credential_revision_id']
            && ($source['source_customer_id'] ?? null) === $row->terms['recharge_customer_id']
            && ($source['pending_charges'] ?? null) === 0 && ($source['collected_cycle_charges'] ?? null) === 0 && ($source['paid_shopify_cycle_orders'] ?? null) === 0
            && ($method['provider_verified'] ?? null) === true && ($method['original_method_accessible'] ?? null) === true
            && ($method['original_terms_hash'] ?? null) === SubscriptionBillingAuthorization::original($row)['terms_hash']
            && SubscriptionEvidence::canonical($method['processor_context'] ?? []) === SubscriptionEvidence::canonical($intent['context'])
            && CarbonImmutable::parse($source['checked_at'])->betweenIncluded(CarbonImmutable::parse($sent->after_state['dispatched_at'])->subMinute(), CarbonImmutable::parse($sent->after_state['dispatched_at'])->addSeconds(5))
            && CarbonImmutable::parse($method['checked_at'])->betweenIncluded(CarbonImmutable::parse($sent->after_state['dispatched_at'])->subMinute(), CarbonImmutable::parse($sent->after_state['dispatched_at'])->addSeconds(5)), 409, 'Retain the original fresh source-stop and saved-method commit authority.');
    }

    public static function attributes(SubscriptionBillingAgreement $row): ?array
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        $action = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->where('kind', 'terms_amended')->orderByDesc('id')->first();

        return $action ? data_get($action->request, 'native_update.quoted.after_state.quote.contract_attributes') : null;
    }
}
