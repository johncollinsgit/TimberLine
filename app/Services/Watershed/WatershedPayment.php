<?php

namespace App\Services\Watershed;

use App\Models\Tenant;
use App\Services\Billing\TenantPaymentsReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WatershedPayment
{
    public function __construct(private WatershedStore $store, private WatershedRegistration $registration) {}

    private function stripe()
    {
        abort_unless(filled(config('services.stripe.secret')), 423, 'Card payments are not available. Please contact leadership.');

        return Http::asForm()->withBasicAuth(config('services.stripe.secret'), '')->timeout(20);
    }

    public function reconcile(int $tenant, string $id): void
    {
        $i = $this->store->row('invoices', $tenant, $id);
        if (! $i->checkout_id || $i->status === 'paid') {
            return;
        }
        $response = $this->stripe()->get('https://api.stripe.com/v1/checkout/sessions/'.$i->checkout_id, ['expand' => ['payment_intent']]);
        abort_unless($response->successful(), 503, 'Payment status could not be checked. Please try again later.');
        $o = $response->json();
        if (($o['payment_status'] ?? '') !== 'paid') {
            return;
        }
        DB::transaction(function () use ($tenant, $id, $o) {
            $i = $this->store->query('invoices', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($i->status === 'paid') {
                return;
            }
            $pi = $o['payment_intent'] ?? [];
            $valid = is_array($pi) && ($o['id'] ?? '') === $i->checkout_id && ($o['metadata']['watershed_invoice_id'] ?? '') === $i->id && ($o['currency'] ?? '') === 'usd' && (int) ($o['amount_total'] ?? -1) === $i->amount_cents && ($pi['transfer_data']['destination'] ?? '') === $i->account_id && ($pi['on_behalf_of'] ?? '') === $i->account_id && ($pi['status'] ?? '') === 'succeeded';
            if (! $valid) {
                $this->store->query('invoices', $tenant)->where('id', $id)->update(['status' => 'review']);
                $this->store->event($tenant, 'payment_review', ['invoice_id' => $id]);

                return;
            }
            $this->store->query('invoices', $tenant)->where('id', $id)->update(['status' => 'paid', 'payment_intent_id' => $pi['id'], 'paid_at' => now(), 'updated_at' => now()]);
            $a = $this->store->row('applications', $tenant, $i->application_id);
            $this->store->query('applications', $tenant)->where('id', $a->id)->where('status', 'accepted')->update(['status' => 'registered', 'updated_at' => now()]);
            $f = app(\App\Services\Soccer\SoccerStore::class)->row('families', $tenant, $a->family_id);
            $this->store->event($tenant, 'payment_confirmed', ['invoice_id' => $id, 'payment_intent' => $pi['id'], 'amount_cents' => $i->amount_cents], key: 'paid:'.$id);
            $this->registration->notify($tenant, $f->email, 'Your Watershed registration is complete', 'Your payment of $'.number_format($i->amount_cents / 100, 2).' is confirmed. Your family’s approved classes and signed releases are available in your account.', 'paid:'.$id);
            $this->registration->leaders($tenant, 'Watershed payment confirmed', 'An accepted family has completed payment. View the private leadership workspace for the registration and class rosters.', 'paid:'.$id);
        }, 3);
    }

    public function checkout(int $tenant, string $family, string $id): string
    {
        $i = $this->store->row('invoices', $tenant, $id);
        $a = $this->store->row('applications', $tenant, $i->application_id);
        abort_unless($a->family_id === $family, 403);
        if ($i->checkout_id) {
            $this->reconcile($tenant, $id);
        }
        $ready = app(TenantPaymentsReadinessService::class)->readinessFor(Tenant::findOrFail($tenant));
        abort_unless($ready['ready'], 423, 'Card payments are not ready. Please contact leadership.');
        $reserved = DB::transaction(function () use ($tenant, $family, $id, $ready) {
            $i = $this->store->query('invoices', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            $a = $this->store->row('applications', $tenant, $i->application_id);
            abort_unless($a->family_id === $family && $a->status === 'accepted' && in_array($i->status, ['unpaid', 'creating', 'open', 'unknown'], true), 422, 'This invoice is no longer payable.');
            $account = $ready['account']->provider_account_id;
            abort_if($i->account_id && $i->account_id !== $account, 409, 'The payment account changed. Please contact leadership.');
            if ($i->checkout_id) {
                $old = $this->stripe()->get('https://api.stripe.com/v1/checkout/sessions/'.$i->checkout_id);
                abort_unless($old->successful(), 503, 'The previous payment must be checked before trying again.');
                if ($old->json('status') === 'open') {
                    return ['url' => $old->json('url')];
                }
                abort_unless($old->json('status') === 'expired' && $old->json('payment_status') === 'unpaid', 409, 'Please wait for the previous payment to be confirmed.');
                $i->attempt_id = null;
            }
            if ($i->attempt_id && $i->checkout_request) {
                return ['attempt' => $i->attempt_id, 'request' => $this->store->decrypt($i->checkout_request)];
            }
            $attempt = (string) Str::uuid();
            $request = ['mode' => 'payment', 'expires_at' => now()->addMinutes(31)->timestamp, 'payment_method_types' => ['card'], 'success_url' => config('watershed.origin').'/register?payment=received', 'cancel_url' => config('watershed.origin').'/register', 'metadata' => ['watershed_invoice_id' => $i->id, 'tenant_id' => (string) $tenant], 'payment_intent_data' => ['metadata' => ['watershed_invoice_id' => $i->id], 'on_behalf_of' => $account, 'transfer_data' => ['destination' => $account]], 'line_items' => [['price_data' => ['currency' => 'usd', 'product_data' => ['name' => 'The Watershed semester registration'], 'unit_amount' => $i->amount_cents], 'quantity' => 1]]];
            $this->store->query('invoices', $tenant)->where('id', $id)->update(['attempt_id' => $attempt, 'checkout_request' => $this->store->encrypt($request), 'checkout_id' => null, 'account_id' => $account, 'status' => 'creating', 'updated_at' => now()]);

            return ['attempt' => $attempt, 'request' => $request];
        }, 3);
        if (isset($reserved['url'])) {
            return $reserved['url'];
        }
        try {
            $response = $this->stripe()->withHeaders(['Idempotency-Key' => 'watershed-'.$reserved['attempt']])->post('https://api.stripe.com/v1/checkout/sessions', $reserved['request']);
            if (! $response->successful() || ! $response->json('id') || ! $response->json('url')) {
                throw new \RuntimeException('Unconfirmed provider response');
            }
        } catch (\Throwable $e) {
            $this->store->query('invoices', $tenant)->where('id', $id)->where('attempt_id', $reserved['attempt'])->update(['status' => 'unknown', 'updated_at' => now()]);
            abort(503, 'Checkout could not be confirmed. Your saved payment attempt will be checked on retry.');
        }
        $this->store->query('invoices', $tenant)->where('id', $id)->where('attempt_id', $reserved['attempt'])->whereIn('status', ['creating', 'unknown'])->update(['status' => 'open', 'checkout_id' => $response->json('id'), 'updated_at' => now()]);

        return $response->json('url');
    }

    public function webhook(int $tenant, array $object): void
    {
        $id = $object['metadata']['watershed_invoice_id'] ?? null;
        if (! $id) {
            return;
        }$i = $this->store->query('invoices',$tenant)->where('id',$id)->first();
        if ($i && $i->checkout_id === ($object['id'] ?? null)) {
            $this->reconcile($tenant,$id);
        }
    }
}
