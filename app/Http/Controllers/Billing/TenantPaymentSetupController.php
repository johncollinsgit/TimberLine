<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Billing\TenantPaymentSetupAccess;
use App\Services\Billing\TenantPaymentsReadinessService;
use App\Services\Billing\TenantPayPalConnectionService;
use App\Services\Billing\TenantStripeConnectionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TenantPaymentSetupController extends Controller
{
    public function index(Request $request, TenantPayPalConnectionService $paypal, TenantPaymentsReadinessService $stripe)
    {
        $tenant = $this->tenant($request);
        $account = $tenant->paymentAccount()->first();
        $connection = $paypal->connection($tenant);

        return response()->view('billing.payment-setup', [
            'tenant' => $tenant, 'account' => $account, 'paypalConnection' => $connection,
            'stripeCanStart' => $stripe->canStartOnboarding($tenant),
            'stripeCollectionReady' => $stripe->canCollectCustomerPayments($tenant),
            'stripeEmbedded' => config('tenant_payments.stripe_embedded_enabled') && filled(config('services.stripe.publishable_key')),
            'paypalConfigured' => $paypal->configured(), 'paypalSandbox' => config('tenant_payments.paypal.environment') === 'sandbox',
            'paypalFresh' => $connection?->last_synced_at?->gt(now()->subMinutes(15)) ?? false,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function stripeStart(Request $request, TenantStripeConnectionService $stripe)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $url = $this->provider(fn () => $stripe->hosted($tenant, $request->user(), $data['email'], route('tenant-payments.index', ['tenant' => $tenant->slug])));

        return redirect()->away($url)->header('Referrer-Policy', 'no-referrer');
    }

    public function stripeSession(Request $request, TenantStripeConnectionService $stripe)
    {
        $tenant = $this->tenant($request);
        $secret = $this->provider(fn () => $stripe->embedded($tenant, $request->user()));

        return response()->json(['client_secret' => $secret])->header('Cache-Control', 'no-store, private');
    }

    public function stripeRefresh(Request $request, TenantStripeConnectionService $stripe)
    {
        $tenant = $this->tenant($request);
        $this->provider(fn () => $stripe->refresh($tenant));

        return to_route('tenant-payments.index', ['tenant' => $tenant->slug])->with('status', 'Stripe verification checked.');
    }

    public function paypalStart(Request $request, TenantPayPalConnectionService $paypal)
    {
        $tenant = $this->tenant($request);
        $request->validate(['share_data_consent' => ['required', 'accepted']]);
        $state = Str::random(48);
        $request->session()->put('tenant_payments.paypal.'.$tenant->id, [
            'state_hash' => hash('sha256', $state), 'user_id' => $request->user()->id, 'expires' => now()->addMinutes(30)->timestamp,
        ]);
        $url = $this->provider(fn () => $paypal->start($tenant, $request->user(), route('tenant-payments.paypal.return', ['tenant' => $tenant->slug, 'setup_state' => $state])));

        return redirect()->away($url)->header('Referrer-Policy', 'no-referrer');
    }

    public function paypalReturn(Request $request, TenantPayPalConnectionService $paypal)
    {
        $tenant = $this->tenant($request);
        $session = $request->session()->get('tenant_payments.paypal.'.$tenant->id);
        abort_unless(is_array($session) && ($session['user_id'] ?? null) === $request->user()->id
            && ($session['expires'] ?? 0) > now()->timestamp
            && hash_equals((string) ($session['state_hash'] ?? ''), hash('sha256', (string) $request->query('setup_state'))), 403, 'PayPal setup expired. Start the connection again.');
        // Keep a valid state retryable if the provider is temporarily unavailable.
        $connection = $this->provider(fn () => $paypal->refresh($tenant, $request->user()));
        $request->session()->forget('tenant_payments.paypal.'.$tenant->id);

        return to_route('tenant-payments.index', ['tenant' => $tenant->slug])->with('status',
            $connection->isConnected() ? 'PayPal account verified. Checkout activation is a separate step.' : 'PayPal needs more information. Complete setup in PayPal, then check again.');
    }

    public function paypalRefresh(Request $request, TenantPayPalConnectionService $paypal)
    {
        $tenant = $this->tenant($request);
        $this->provider(fn () => $paypal->refresh($tenant, $request->user()));

        return to_route('tenant-payments.index', ['tenant' => $tenant->slug])->with('status', 'PayPal verification checked.');
    }

    public function paypalDisconnect(Request $request, TenantPayPalConnectionService $paypal)
    {
        $tenant = $this->tenant($request);
        $paypal->disconnect($tenant, $request->user());
        $request->session()->forget('tenant_payments.paypal.'.$tenant->id);

        return to_route('tenant-payments.index', ['tenant' => $tenant->slug])->with('status', 'PayPal disconnected in Everbranch. You can also remove Everbranch permissions in PayPal.');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        $access = app(TenantPaymentSetupAccess::class);
        abort_unless($tenant instanceof Tenant && $access->allows($tenant, $request->user()), 403);
        abort_unless($access->enabledFor($tenant), 404);

        return $tenant;
    }

    private function provider(callable $action): mixed
    {
        try {
            return $action();
        } catch (ConnectionException) {
            $status = 502;
            $message = 'The payment provider could not be reached. Your setup is saved; please try again.';
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [409, 423, 502], true)) {
                throw $error;
            }
            $status = $error->getStatusCode();
            $message = $error->getMessage() ?: 'Payment setup is unavailable. Please try again or contact support.';
        }
        if (request()->expectsJson()) {
            abort($status, $message);
        }
        throw ValidationException::withMessages(['payments' => $message]);
    }
}
