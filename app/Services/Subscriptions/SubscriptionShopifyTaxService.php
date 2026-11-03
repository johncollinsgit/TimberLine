<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Durable, independently renewed calculation-only checks for accepted native amended billing. */
class SubscriptionShopifyTaxService
{
    public const FRESH_SECONDS = 180;

    public function required(SubscriptionBillingAgreement $row): bool
    {
        return $row->processor === 'shopify' && SubscriptionBillingAction::query()->forTenantId($row->tenant_id)
            ->where('agreement_id', $row->id)->where('kind', 'terms_amended')->exists();
    }

    public function assertCollectEntry(SubscriptionBillingAgreement $row): void
    {
        if ($this->required($row)) {
            abort_unless(DB::transactionLevel() === 0, 409, 'Native amended collection must begin outside an enclosing transaction.');
            abort_unless(app(SubscriptionReplacementAccess::class)->member($row)->billing_owner === 'everbranch'
                && $row->activated_at && $row->method_verified_at, 409, 'Only the activated Everbranch billing owner can collect these native terms.');
        }
    }

    public function review(SubscriptionBillingAgreement $row, User $actor, string $date, ?int $cycleId = null): ?array
    {
        if (! $this->required($row)) {
            return null;
        }
        abort_unless(DB::transactionLevel() === 0, 409, 'Native tax calculation must start outside an enclosing transaction.');
        $key = $this->cycleKey($row, $date);
        $request = $this->locked($row, function ($current) use ($actor, $date, $cycleId, $key) {
            $this->ready($current, $actor, $date, $cycleId);
            abort_unless($this->cycleKey($current, $date) === $key, 409, 'Review the current billing state before checking native tax.');
            $latest = SubscriptionBillingAction::query()->forTenantId($current->tenant_id)->where('agreement_id', $current->id)
                ->where('kind', 'shopify_tax_requested')->orderByDesc('id')->lockForUpdate()->first();
            if ($latest) {
                $this->assertAction($latest);
                if ($latest->request['cycle_key'] === $key) {
                    $sent = $this->action($current, $latest->request['request_uuid'], 'sent', true);
                    if (! $sent || $this->fresh($sent->after_state['dispatched_at'])) {
                        return $latest;
                    }
                    if (! $this->action($current, $latest->request['request_uuid'], 'expired', true)) {
                        $this->append($current, $actor, $latest->request['request_uuid'], 'expired', ['intent_hash' => $latest->request['intent']['intent_hash']], ['expired_at' => now()->toISOString()]);
                    }
                }
            }

            return null;
        });
        if (! $request) {
            $before = app(SubscriptionShopifyBillingTransport::class)->updateRead($row, 'contract')['subscriptionContract'] ?? [];
            $request = $this->locked($row, function ($current) use ($actor, $date, $cycleId, $key, $before) {
                $this->ready($current, $actor, $date, $cycleId);
                abort_unless($this->cycleKey($current, $date) === $key, 409);
                $latest = SubscriptionBillingAction::query()->forTenantId($current->tenant_id)->where('agreement_id', $current->id)
                    ->where('kind', 'shopify_tax_requested')->orderByDesc('id')->lockForUpdate()->first();
                if ($latest) {
                    $this->assertAction($latest);
                    $sent = $this->action($current, $latest->request['request_uuid'], 'sent', true);
                    if ($latest->request['cycle_key'] === $key && (! $sent || $this->fresh($sent->after_state['dispatched_at']))) {
                        return $latest;
                    }
                }
                $generation = $latest && $latest->request['cycle_key'] === $key ? $latest->request['generation'] + 1 : 1;
                $uuid = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'everbranch:native-tax:'.$key.':'.$generation);
                $intent = SubscriptionShopifyTaxProtocol::intent($current, $before, $uuid, $date);

                return $this->append($current, $actor, $uuid, 'requested', ['cycle_key' => $key, 'generation' => $generation, 'request_uuid' => $uuid, 'cycle_id' => $cycleId, 'intent' => $intent], ['prepared_at' => now()->toISOString()]);
            });
        }
        $uuid = $request->request['request_uuid'];
        $sent = $this->action($row, $uuid, 'sent');
        $proof = $this->action($row, $uuid, 'proof');
        if ($proof) {
            $summary = $proof->after_state['summary'];
            $this->assertFresh($row, $summary, $date);

            return $summary;
        }
        $received = $this->action($row, $uuid, 'received');
        if (! $sent) {
            try {
                $data = app(SubscriptionShopifyBillingTransport::class)->taxCalculate($row, $uuid);
                $calculation = data_get($data, 'subscriptionContractUpdateCalculate.subscriptionContractCalculation', []);
                $errors = data_get($data, 'subscriptionContractUpdateCalculate.userErrors', []);
            } catch (\Throwable) {
                abort(409, 'The original tax calculation is unconfirmed. No payment was submitted.');
            }
        } elseif (! $received) {
            abort(409, 'The original tax calculation is unconfirmed. No payment was submitted.');
        } else {
            $data = app(SubscriptionShopifyBillingTransport::class)->updateRead($row, 'calculation', $received->after_state['calculation']['id']);
            $calculation = $data['subscriptionContractCalculation'] ?? [];
            $errors = $received->after_state['user_errors'];
        }
        // Retain the provider handle in its own transaction before a warning/stale/changed-price result is rejected.
        $this->locked($row, function ($current) use ($actor, $uuid, $calculation, $errors) {
            $this->requiredAction($current, $uuid, 'sent', true);
            abort_unless(is_array($calculation) && SubscriptionShopifyUpdateProtocol::calculationId($calculation['id'] ?? null)
                && in_array($calculation['__typename'] ?? null, ['SubscriptionContractCalculationPending', 'SubscriptionContractCalculationSuccess', 'SubscriptionContractCalculationFailure'], true), 409);
            if (! $this->action($current, $uuid, 'received', true)) {
                $this->append($current, $actor, $uuid, 'received', [], ['calculation' => $calculation, 'user_errors' => $errors]);
            }
        });
        // The collection scheduler runs every five minutes. Give an ordinary asynchronous calculation two
        // bounded reads now; a longer pending result stays recorded and cannot be used after its freshness window.
        $originalId = $this->requiredAction($row, $uuid, 'received')->after_state['calculation']['id'];
        for ($poll = 0; $errors === [] && ($calculation['__typename'] ?? null) === 'SubscriptionContractCalculationPending' && $poll < 2; $poll++) {
            usleep(150000);
            $data = app(SubscriptionShopifyBillingTransport::class)->updateRead($row, 'calculation', $originalId);
            $calculation = $data['subscriptionContractCalculation'] ?? [];
        }

        return $this->locked($row, function ($current) use ($actor, $date, $cycleId, $uuid, $calculation, $errors) {
            $this->ready($current, $actor, $date, $cycleId);
            $request = $this->requiredAction($current, $uuid, 'requested', true);
            $sent = $this->requiredAction($current, $uuid, 'sent', true);
            $this->latest($current, $request, true);
            abort_unless($errors === [] && $this->fresh($sent->after_state['dispatched_at']), 409, 'Review the changed or expired native tax calculation before billing.');
            $quote = SubscriptionShopifyTaxProtocol::quote($current, $request->request['intent'], $calculation, true);
            $summary = ['request_uuid' => $uuid, 'cycle_key' => $request->request['cycle_key'], 'intent_hash' => $request->request['intent']['intent_hash'],
                'quote' => $quote, 'dispatched_at' => $sent->after_state['dispatched_at'], 'checked_at' => now()->toISOString()];
            $summary['proof_hash'] = SubscriptionEvidence::digest(SubscriptionEvidence::canonical($summary));
            if ($prior = $this->action($current, $uuid, 'proof', true)) {
                return $prior->after_state['summary'];
            }
            $this->append($current, $actor, $uuid, 'proof', [], ['summary' => $summary, 'calculation' => $calculation]);

            return $summary;
        });
    }

    public function dispatch(SubscriptionBillingAgreement $row, string $uuid, \Closure $send): array
    {
        abort_unless(DB::transactionLevel() === 0, 409);
        $this->locked($row, function ($current) use ($uuid) {
            $request = $this->requiredAction($current, $uuid, 'requested', true);
            $actor = User::findOrFail((int) $request->actor_reference);
            $this->ready($current, $actor, $request->request['intent']['date'], $request->request['cycle_id']);
            $this->latest($current, $request, true);
            SubscriptionShopifyTaxProtocol::assertIntent($current, $request->request['intent'], true);
            abort_if($this->action($current, $uuid, 'sent', true), 409, 'Poll the original tax calculation without resending.');
            $this->append($current, $actor, $uuid, 'sent', ['intent_hash' => $request->request['intent']['intent_hash']], ['dispatched_at' => now()->toISOString()]);
        });

        return $this->locked($row, function ($current) use ($uuid, $send) {
            $request = $this->requiredAction($current, $uuid, 'requested', true);
            $this->ready($current, User::findOrFail((int) $request->actor_reference), $request->request['intent']['date'], $request->request['cycle_id']);
            $this->latest($current, $request, true);
            SubscriptionShopifyTaxProtocol::assertIntent($current, $request->request['intent'], true);

            return $send($current, $request->request['intent']['variables']);
        });
    }

    public function assertFresh(SubscriptionBillingAgreement $row, ?array $summary, string $date, bool $currentRead = false, ?array $contract = null): void
    {
        if (! $this->required($row)) {
            return;
        }
        abort_unless(is_array($summary) && is_string($summary['request_uuid'] ?? null), 409, 'A fresh native tax calculation is required before billing.');
        $request = $this->requiredAction($row, $summary['request_uuid'], 'requested', $currentRead);
        $sent = $this->requiredAction($row, $summary['request_uuid'], 'sent', $currentRead);
        $proof = $this->requiredAction($row, $summary['request_uuid'], 'proof', $currentRead);
        $this->merchant($row, (int) $request->actor_reference, $currentRead);
        $this->latest($row, $request, $currentRead);
        abort_unless($request->request['cycle_key'] === $this->cycleKey($row, $date)
            && SubscriptionEvidence::canonical($proof->after_state['summary']) === SubscriptionEvidence::canonical($summary)
            && $this->fresh($sent->after_state['dispatched_at']) && $this->fresh($summary['checked_at'])
            && SubscriptionEvidence::canonical(SubscriptionShopifyTaxProtocol::quote($row, $request->request['intent'], $proof->after_state['calculation'], $currentRead))
                === SubscriptionEvidence::canonical($summary['quote'])
            && ($contract === null || SubscriptionEvidence::canonical($contract) === SubscriptionEvidence::canonical($request->request['intent']['before'])),
            409, 'The native tax, contract version or billing authority changed before payment.');
    }

    private function ready(SubscriptionBillingAgreement $row, User $actor, string $date, ?int $cycleId): void
    {
        $currentRead = DB::transactionLevel() > 0;
        $actor = $this->merchant($row, $actor->id, $currentRead);
        app(SubscriptionShopifyExecutionService::class)->ready($row, $actor, $cycleId);
        $member = app(SubscriptionReplacementAccess::class)->member($row);
        abort_unless($member->billing_owner === 'everbranch' && $row->activated_at && $row->method_verified_at
            && $row->status === 'active' && $row->next_billing_date?->toDateString() === $date
            && $date === now($row->terms['timezone'])->toDateString() && now($row->terms['timezone'])->hour >= $row->terms['billing_hour'], 409);
        if ($cycleId) {
            $cycle = SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->whereKey($cycleId)->lockForUpdate()->firstOrFail();
            abort_if($cycle->payment_started_at, 409, 'Recover the original payment instead of calculating another tax review.');
            abort_unless($cycle->agreement_revision === $row->revision && $cycle->terms['terms_hash'] === $row->terms_hash
                && $cycle->method_binding === $row->method_binding && $cycle->amount_cents === $row->terms['amount_cents'], 409,
                'The original prepared cycle needs review before using a different billing authority.');
        }
    }

    private function merchant(SubscriptionBillingAgreement $row, int $actorId, bool $currentRead): User
    {
        $actor = User::query()->whereKey($actorId)->when($currentRead, fn ($q) => $q->lockForUpdate())->firstOrFail();
        $membership = DB::table('tenant_user')->where('tenant_id', $row->tenant_id)->where('user_id', $actor->id)
            ->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($actor->is_active && $actor->email_verified_at && $membership && $membership->membership_active
            && in_array(strtolower($membership->role), ['owner', 'tenant_owner', 'admin'], true), 403, 'Current verified merchant collection authority is required.');
        app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor, $row->member_id, 'collection');

        return $actor;
    }

    private function cycleKey(SubscriptionBillingAgreement $row, string $date): string
    {
        return SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['subject' => app(SubscriptionAmendmentProposal::class)->subject($row), 'date' => $date, 'binding' => $row->method_binding]));
    }

    private function fresh(string $at): bool
    {
        return CarbonImmutable::parse($at)->betweenIncluded(now()->subSeconds(self::FRESH_SECONDS), now()->addSeconds(5));
    }

    private function latest(SubscriptionBillingAgreement $row, SubscriptionBillingAction $request, bool $currentRead): void
    {
        $latest = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('kind', 'shopify_tax_requested')
            ->orderByDesc('id')->when($currentRead, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($latest && $latest->id === $request->id, 409, 'The original tax calculation has been superseded.');
        $this->assertAction($latest);
    }

    private function locked(SubscriptionBillingAgreement $row, \Closure $work): mixed
    {
        return DB::transaction(function () use ($row, $work) {
            SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();

            return $work(SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail());
        });
    }

    private function action(SubscriptionBillingAgreement $row, string $uuid, string $phase, bool $lock = false): ?SubscriptionBillingAction
    {
        $action = SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->where('request_uuid', (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'everbranch:native-tax-phase:'.$uuid.':'.$phase))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($action) {
            $this->assertAction($action);
            abort_unless($action->kind === 'shopify_tax_'.$phase, 409);
        }

        return $action;
    }

    private function requiredAction(SubscriptionBillingAgreement $row, string $uuid, string $phase, bool $lock = false): SubscriptionBillingAction
    {
        $action = $this->action($row, $uuid, $phase, $lock);
        abort_unless($action, 409, 'Retain the original signed native tax calculation evidence.');

        return $action;
    }

    private function assertAction(SubscriptionBillingAction $action): void
    {
        $hash = $action->after_state['authorization_hash'] ?? null;
        abort_unless(is_string($hash) && hash_equals(SubscriptionBillingTermsHistory::seal($action), $hash), 409, 'The retained native tax evidence changed.');
    }

    private function append(SubscriptionBillingAgreement $row, User $actor, string $uuid, string $phase, array $request, array $after): SubscriptionBillingAction
    {
        $action = new SubscriptionBillingAction(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id,
            'request_uuid' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'everbranch:native-tax-phase:'.$uuid.':'.$phase), 'kind' => 'shopify_tax_'.$phase,
            'actor_type' => 'user', 'actor_reference' => (string) $actor->id, 'expected_revision' => $row->revision,
            'request' => $request, 'before_state' => [], 'after_state' => $after]);
        $action->after_state = $after + ['authorization_hash' => SubscriptionBillingTermsHistory::seal($action)];
        $action->save();

        return $action;
    }
}
