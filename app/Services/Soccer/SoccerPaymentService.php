<?php

namespace App\Services\Soccer;

use App\Models\Tenant;
use App\Services\Billing\TenantPaymentsReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SoccerPaymentService
{
    public function __construct(private SoccerStore $store, private SoccerEvents $events) {}

    private function stripe()
    {
        return Http::asForm()->withBasicAuth(config('services.stripe.secret'), '')->timeout(20);
    }

    public function checkout(int $tenant, string $id): string
    {
        $t = Tenant::findOrFail($tenant);
        $ready = app(TenantPaymentsReadinessService::class)->readinessFor($t);
        abort_unless($ready['ready'], 423, 'Card payments are not ready. Please use Venmo or contact Sarah.');

        return DB::transaction(function () use ($tenant, $id, $ready) {
            $i = $this->store->row('invoices', $tenant, $id);
            $this->store->lockSeason($tenant, $i->season_id);
            $i = $this->store->row('invoices', $tenant, $id);
            abort_unless($i->status === 'unpaid' && CarbonImmutable::parse($i->expires_at)->gt(now()), 422, 'This invoice is paid or expired.');
            app(SoccerPayPalService::class)->assertNoInFlightPayment($tenant, $id);
            if ($i->checkout_id) {
                $old = $this->stripe()->get('https://api.stripe.com/v1/checkout/sessions/'.$i->checkout_id);
                if ($old->successful() && $old->json('status') === 'open') {
                    return $old->json('url');
                }
            }
            $key = 'wren-checkout-'.$id.'-'.($i->checkout_id ?? 'first');
            $response = $this->stripe()->withHeaders(['Idempotency-Key' => $key])->post('https://api.stripe.com/v1/checkout/sessions', ['mode' => 'payment', 'payment_method_types' => ['card'], 'success_url' => config('soccer.origin').'/spring?payment=received', 'cancel_url' => config('soccer.origin').'/spring', 'metadata' => ['soccer_invoice_id' => $id, 'tenant_id' => (string) $tenant], 'payment_intent_data' => ['metadata' => ['soccer_invoice_id' => $id], 'on_behalf_of' => $ready['account']->provider_account_id, 'transfer_data' => ['destination' => $ready['account']->provider_account_id]], 'line_items' => [['price_data' => ['currency' => 'usd', 'product_data' => ['name' => 'Wren Family Soccer registration'], 'unit_amount' => $i->amount_cents], 'quantity' => 1]]]);
            abort_unless($response->successful() && $response->json('id') && $response->json('url'), 502, 'Checkout could not be started.');
            $this->store->query('invoices', $tenant)->where('id', $id)->update(['checkout_id' => $response->json('id'), 'provider' => 'stripe', 'updated_at' => now()]);

            return $response->json('url');
        }, 3);
    }

    /** Record an organizer-verified receipt. A link click or parent declaration cannot call this path. */
    public function confirmVenmo(int $tenant, string $id, int $amount, ?string $reference, string $reason, int $actor): string
    {
        $this->assertOrganizer($tenant, $actor);
        abort_unless($amount > 0 && trim($reason) !== '', 422, 'Confirm the received amount and verification evidence.');
        $reference = trim((string) $reference);
        $key = $reference !== '' ? 'reference:'.hash('sha256', $reference) : 'owner-attestation:'.$id;

        return DB::transaction(function () use ($tenant, $id, $amount, $reference, $reason, $actor, $key) {
            $invoice = $this->lockedInvoice($tenant, $id);
            if ($existing = $this->store->query('payments', $tenant)->where('provider', 'venmo')->where('provider_key', $key)->first()) {
                abort_unless($existing->invoice_id === $id && (int) $existing->amount_cents === $amount, 409, 'This verification already belongs to another payment.');

                return $invoice->status === 'paid' && $invoice->provider === 'venmo' ? 'paid' : 'review';
            }
            app(SoccerPayPalService::class)->assertNoInFlightPayment($tenant, $id);
            $this->retireStripeCheckout($tenant, $id);
            $this->paid($tenant, $id, 'venmo', $key, $amount, [
                'verified_by' => $actor,
                'verification_source' => $reference !== '' ? 'organizer_verified_transfer' : 'organizer_attestation',
                'transfer_reference' => $reference !== '' ? $reference : null,
                'reason' => trim($reason),
                'verified_at' => now()->toIso8601String(),
            ], $actor);

            return $this->store->row('invoices', $tenant, $id)->provider === 'venmo' && $this->store->row('invoices', $tenant, $id)->status === 'paid' ? 'paid' : 'review';
        }, 3);
    }

    /** Waiving fees preserves the original obligation in audit evidence and never invents a receipt. */
    public function waive(int $tenant, string $id, string $reason, int $actor, ?int $expectedAmount = null): void
    {
        $this->assertOrganizer($tenant, $actor);
        abort_unless(trim($reason) !== '', 422, 'A reason for the organizer waiver is required.');
        $settledByStripe = DB::transaction(function () use ($tenant, $id, $reason, $actor, $expectedAmount) {
            $invoice = $this->lockedInvoice($tenant, $id);
            if ($invoice->status === 'waived') {
                return false;
            }
            abort_unless($expectedAmount === null || (int) $invoice->amount_cents === $expectedAmount, 409, 'Invoice amount changed; review before waiving fees.');
            abort_unless($invoice->status === 'unpaid' && CarbonImmutable::parse($invoice->expires_at)->gt(now()), 422, 'Only a current unpaid offer can be waived.');
            app(SoccerPayPalService::class)->assertNoInFlightPayment($tenant, $id);
            if ($this->retireStripeCheckout($tenant, $id)) {
                return true;
            }
            $invoice = $this->store->row('invoices', $tenant, $id);
            $registrations = $this->store->query('registrations', $tenant)->where('offer_group', $invoice->offer_group)->get();
            abort_unless($registrations->isNotEmpty() && $registrations->every(fn ($r) => $r->status === 'offered' || $r->status === 'registered' && $r->waived), 422, 'Review the current places before waiving this offer.');
            abort_if($this->store->query('payments', $tenant)->where('invoice_id', $id)->exists(), 409, 'Review the recorded payment before waiving this invoice.');
            $original = $registrations->map(fn ($r) => ['registration_id' => $r->id, 'amount_cents' => (int) $r->amount_cents, 'waived' => (bool) $r->waived])->all();
            $this->store->query('invoices', $tenant)->where('id', $id)->update(['status' => 'waived', 'amount_cents' => 0, 'provider' => null, 'paid_at' => null, 'updated_at' => now()]);
            $this->store->query('registrations', $tenant)->where('offer_group', $invoice->offer_group)->update(['status' => 'registered', 'waived' => true, 'amount_cents' => 0, 'payment_status' => 'waived', 'offer_expires_at' => null, 'updated_at' => now()]);
            $this->events->record($tenant, $invoice->season_id, $invoice->family_id, 'organizer_fee_waived', ['invoice_id' => $id, 'registration_ids' => $registrations->pluck('id')->all(), 'original_amount_cents' => (int) $invoice->amount_cents, 'original_registrations' => $original, 'reason' => trim($reason), 'summary' => 'Organizer waived the remaining registration fees; no payment was received.'], $actor, key: 'organizer-waiver:'.$id, familyText: 'Wren Soccer: your remaining registration fees have been waived. Your children have registered places. View your family dashboard: '.config('soccer.origin').'/spring');

            return false;
        }, 3);
        // Reconciliation must commit even when a competing card payment prevents the waiver.
        abort_if($settledByStripe, 409, 'A card payment was already received and reconciled. No fees were waived.');
    }

    /** Caller may already hold this same season lock; all checkout paths use the lock too. */
    public function retireStripeCheckout(int $tenant, string $id): bool
    {
        return DB::transaction(function () use ($tenant, $id) {
            $invoice = $this->lockedInvoice($tenant, $id);
            if (! $invoice->checkout_id) {
                return false;
            }
            try {
                $response = $this->stripe()->get('https://api.stripe.com/v1/checkout/sessions/'.$invoice->checkout_id);
                abort_unless($response->successful(), 502, 'Card payment status could not be verified. No offline settlement was applied.');
                $session = $response->json();
                $this->assertCheckoutIdentity($tenant, $invoice, $session);
                if (($session['status'] ?? null) === 'open' && ($session['payment_status'] ?? null) === 'unpaid') {
                    // A failed expiration can mean payment won the race. Retrieve again before deciding.
                    $this->stripe()->withHeaders(['Idempotency-Key' => 'wren-retire-checkout-'.$invoice->checkout_id])->post('https://api.stripe.com/v1/checkout/sessions/'.$invoice->checkout_id.'/expire');
                    $response = $this->stripe()->get('https://api.stripe.com/v1/checkout/sessions/'.$invoice->checkout_id);
                    abort_unless($response->successful(), 502, 'Card checkout could not be closed safely. Recheck before confirming offline settlement.');
                    $session = $response->json();
                    $this->assertCheckoutIdentity($tenant, $invoice, $session);
                }
                if (($session['payment_status'] ?? null) === 'paid') {
                    $intent = $session['payment_intent'] ?? null;
                    abort_unless(is_string($intent) && $intent !== '' && isset($session['amount_total']), 502, 'Paid card checkout needs provider reconciliation.');
                    $this->paid($tenant, $id, 'stripe', 'intent:'.$intent, (int) $session['amount_total'], ['payment_intent' => $intent, 'checkout_id' => $invoice->checkout_id, 'source' => 'offline_settlement_reconciliation']);

                    return true;
                }
                abort_unless(($session['status'] ?? null) === 'expired' && ($session['payment_status'] ?? null) === 'unpaid', 409, 'A card payment may be in progress. Reconcile it before offline settlement.');

                return false;
            } catch (\Illuminate\Http\Client\ConnectionException $error) {
                abort(502, 'Card payment status is uncertain. No offline settlement was applied; recheck before retrying.');
            }
        }, 3);
    }

    private function lockedInvoice(int $tenant, string $id): object
    {
        $invoice = $this->store->row('invoices', $tenant, $id);
        $this->store->lockSeason($tenant, $invoice->season_id);

        return $this->store->query('invoices', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function assertOrganizer(int $tenant, int $actor): void
    {
        $organization = Tenant::findOrFail($tenant);
        $access = app(SoccerAccess::class);
        $user = \App\Models\User::find($actor);
        abort_unless($user?->email_verified_at && $access->enabled($organization) && $access->admin($organization, $user), 403);
    }

    private function assertCheckoutIdentity(int $tenant, object $invoice, array $session): void
    {
        abort_unless(($session['id'] ?? null) === $invoice->checkout_id && ($session['currency'] ?? null) === 'usd' && data_get($session, 'metadata.soccer_invoice_id') === $invoice->id && (string) data_get($session, 'metadata.tenant_id') === (string) $tenant, 502, 'Card checkout ownership could not be verified.');
    }

    public function paid(int $tenant, string $id, string $provider, string $key, int $amount, array $evidence, ?int $actor = null): void
    {
        DB::transaction(function () use ($tenant, $id, $provider, $key, $amount, $evidence, $actor) {
            $i = $this->store->row('invoices', $tenant, $id);
            $this->store->lockSeason($tenant, $i->season_id);
            $i = $this->store->row('invoices', $tenant, $id);
            if ($this->store->query('payments', $tenant)->where('provider', $provider)->where('provider_key', $key)->exists()) {
                return;
            }
            $this->store->insert('payments', $tenant, ['invoice_id' => $id, 'provider' => $provider, 'provider_key' => $key, 'amount_cents' => $amount, 'evidence' => $this->store->encrypt($evidence)]);
            $regs = $this->store->query('registrations', $tenant)->where('offer_group', $i->offer_group)->get();
            $valid = $amount === $i->amount_cents && $i->status === 'unpaid' && CarbonImmutable::parse($i->expires_at)->gt(now()) && $regs->every(fn ($r) => $r->status === 'offered' || $r->status === 'registered' && $r->waived);
            if (! $valid) {
                $this->events->record($tenant, $i->season_id, $i->family_id, 'payment_needs_review', ['invoice_id' => $id, 'summary' => 'Late, duplicate, or mismatched payment needs review.'], $actor, waitlist: $regs->isNotEmpty());

                return;
            }
            $this->store->query('invoices', $tenant)->where('id', $id)->update(['status' => 'paid', 'provider' => $provider, 'payment_intent_id' => $provider === 'stripe' ? ($evidence['payment_intent'] ?? null) : null, 'paid_at' => now(), 'updated_at' => now()]);
            $this->store->query('registrations', $tenant)->where('offer_group', $i->offer_group)->update(['status' => 'registered', 'offer_expires_at' => null, 'payment_status' => 'paid', 'updated_at' => now()]);
            $this->store->query('registrations', $tenant)->where('offer_group', $i->offer_group)->where('waived', true)->update(['payment_status' => 'waived']);
            $this->events->record($tenant, $i->season_id, $i->family_id, 'payment_confirmed', ['invoice_id' => $id, 'registration_ids' => $regs->pluck('id')->all(), 'summary' => $regs->isNotEmpty() ? 'Family payment confirmed; children registered.' : 'Payment confirmed; no player registrations changed.'], $actor, waitlist: $regs->isNotEmpty(), familyText: $regs->isNotEmpty() && config('soccer.notification_mode') === 'email' ? 'Wren Soccer: your payment of $'.number_format($amount / 100, 2).' is confirmed. Your children have registered places. View your family dashboard: '.config('soccer.origin').'/spring' : null);
        }, 3);
    }

    public function refund(int $tenant, string $invoice, int $amount, string $reason, int $actor, bool $venmoCompleted, ?string $paymentId = null): string
    {
        $selected = $this->store->query('payments', $tenant)->where('invoice_id', $invoice)->when($paymentId, fn ($q) => $q->where('id', $paymentId), fn ($q) => $q->where('provider', $this->store->row('invoices', $tenant, $invoice)->provider))->oldest('created_at')->first();
        if ($selected?->provider === 'paypal') {
            return app(SoccerPayPalService::class)->refund($tenant, $invoice, $amount, $reason, $actor, $selected->id);
        }
        $id = DB::transaction(function () use ($tenant, $invoice, $amount, $reason, $actor, $venmoCompleted, $paymentId) {
            $i = $this->store->query('invoices', $tenant)->where('id', $invoice)->lockForUpdate()->firstOrFail();
            $payment = $this->store->query('payments', $tenant)->where('invoice_id', $invoice)->when($paymentId, fn ($q) => $q->where('id', $paymentId), fn ($q) => $q->where('provider', $i->provider)->where('amount_cents', $i->amount_cents))->oldest('created_at')->first();
            abort_unless($payment && ($paymentId || $i->status === 'paid'), 422, 'Select a recorded payment to refund.');
            $paidEvidence = $this->store->decrypt($payment->evidence);
            abort_unless($payment->provider === 'stripe' && ! empty($paidEvidence['payment_intent']) || $payment->provider === 'venmo' && $venmoCompleted, 422, 'A Stripe payment reference or confirmation of the completed Venmo refund is required.');
            $pending = $this->store->query('refunds', $tenant)->where('invoice_id', $invoice)->whereIn('status', ['pending', 'uncertain', 'succeeded'])->get()->filter(fn ($refund) => ($this->store->decrypt($refund->evidence)['payment_id'] ?? null) === $payment->id)->sum('amount_cents');
            abort_unless($amount > 0 && $amount <= $payment->amount_cents - $pending, 422, 'Refund exceeds this payment’s remaining refundable amount.');

            return $this->store->insert('refunds', $tenant, ['invoice_id' => $invoice, 'amount_cents' => $amount, 'status' => 'pending', 'evidence' => $this->store->encrypt(['reason' => $reason, 'actor' => $actor, 'payment_id' => $payment->id])]);
        }, 3);
        $i = $this->store->row('invoices', $tenant, $invoice);
        $refundEvidence = $this->store->decrypt($this->store->row('refunds', $tenant, $id)->evidence);
        $payment = $this->store->row('payments', $tenant, $refundEvidence['payment_id']);
        $paidEvidence = $this->store->decrypt($payment->evidence);
        $success = $payment->provider === 'venmo';
        $providerKey = null;
        if (! $success) {
            try {
                $r = $this->stripe()->withHeaders(['Idempotency-Key' => 'wren-refund-'.$id])->post('https://api.stripe.com/v1/refunds', ['payment_intent' => $paidEvidence['payment_intent'], 'amount' => $amount, 'reverse_transfer' => 'true']);
                $providerKey = $r->json('id');
                $success = $r->successful() && $r->json('status') === 'succeeded';
                $status = $success ? 'succeeded' : ($r->successful() ? 'pending' : 'failed');
            } catch (\Throwable $e) {
                $status = 'uncertain';
            }
        } else {
            $status = 'succeeded';
        }
        DB::transaction(function () use ($tenant, $invoice, $id, $success, $status, $providerKey, $amount, $actor) {
            $this->store->query('invoices', $tenant)->where('id', $invoice)->lockForUpdate()->firstOrFail();
            $this->store->query('refunds', $tenant)->where('id', $id)->update(['status' => $status, 'provider_key' => $providerKey, 'updated_at' => now()]);
            if ($success) {
                $this->store->query('invoices', $tenant)->where('id', $invoice)->increment('refunded_cents', $amount);
            }
            $this->events->record($tenant, null, null, 'refund_'.$status, ['invoice_id' => $invoice, 'refund_id' => $id, 'amount_cents' => $amount], $actor);
        });

        return $id;
    }
}
