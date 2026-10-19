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
            $this->events->record($tenant, $i->season_id, $i->family_id, 'payment_confirmed', ['invoice_id' => $id, 'summary' => $regs->isNotEmpty() ? 'Family payment confirmed; children registered.' : 'Payment confirmed; no player registrations changed.'], $actor, waitlist: $regs->isNotEmpty(), familyText: $regs->isNotEmpty() && config('soccer.notification_mode') === 'email' ? 'Wren Soccer: your payment of $'.number_format($amount / 100, 2).' is confirmed. Your children have registered places. View your family dashboard: '.config('soccer.origin').'/spring' : null);
        }, 3);
    }

    public function refund(int $tenant, string $invoice, int $amount, string $reason, int $actor, bool $venmoCompleted, ?string $paymentId = null): string
    {
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
