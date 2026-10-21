<?php

namespace App\Http\Controllers;

use App\Models\PosPaymentAttempt;
use App\Models\PosRefund;
use App\Models\PosRegister;
use App\Models\Tenant;
use App\Models\WebsiteOrder;
use App\Services\Billing\TenantPaymentSetupAccess;
use App\Services\Billing\TenantPaymentsReadinessService;
use App\Services\PointOfSale\PosAccess;
use App\Services\PointOfSale\PosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PointOfSaleController extends Controller
{
    public function setup(Request $request, PosAccess $access, TenantPaymentSetupAccess $paymentAccess, TenantPaymentsReadinessService $payments): Response
    {
        $tenant = $this->tenant($request);
        $actor = $request->user();
        $access->authorize($tenant, $actor);
        $registerManager = true;
        try {
            $access->authorize($tenant, $actor, true);
        } catch (HttpException) {
            $registerManager = false;
        }
        $registers = PosRegister::query()->forTenantId($tenant->id)->where('active', true)
            ->when(! $registerManager, fn ($query) => $query->whereJsonContains('cashier_user_ids', (int) $actor->id))
            ->orderBy('id')->limit(20)->get()
            ->filter(function ($register) use ($tenant, $actor, $access): bool {
                try {
                    $access->register($tenant, $actor, $register->id);

                    return true;
                } catch (HttpException) {
                    return false;
                }
            })->values();
        $canManagePayments = $paymentAccess->allows($tenant, $actor);
        $connection = null;
        if ($canManagePayments) {
            // Saved local evidence only. Rendering this page never refreshes Stripe.
            $readiness = $payments->readinessFor($tenant);
            $account = $readiness['account'];
            $connection = [
                'label' => match ($account?->status) {
                    'ready' => 'Connection saved', 'onboarding' => 'Setup in progress', 'restricted' => 'Needs attention',
                    'disabled' => 'Disconnected', default => 'Not connected',
                },
                'present' => $account !== null, 'detailsSubmitted' => (bool) $account?->details_submitted,
                'cardsEnabled' => (bool) $account?->charges_enabled, 'payoutsEnabled' => (bool) $account?->payouts_enabled,
                'onlineChecksReady' => (bool) $readiness['ready'], 'checkedAt' => $account?->last_checked_at
                    ? $account->last_checked_at->copy()->utc()->format('M j, Y H:i').' UTC' : null,
            ];
        }

        return $this->privateView('pos.setup', [
            'tenant' => $tenant, 'registers' => $registers, 'canManagePayments' => $canManagePayments, 'connection' => $connection,
            'paymentSetupUrl' => $canManagePayments && $paymentAccess->enabledFor($tenant)
                ? route('tenant-payments.index', ['tenant' => $tenant->slug]) : null,
        ]);
    }

    public function register(Request $request, int $register, PosAccess $access, PosService $sales): Response
    {
        $tenant = $this->tenant($request);
        $record = $access->register($tenant, $request->user(), $register);
        $catalog = $sales->catalog($tenant, $request->user(), $register);
        $attempts = PosPaymentAttempt::query()->forTenantId($tenant->id)->where('pos_register_id', $record->id)->latest('id')->limit(20)->get();
        $blocked = PosPaymentAttempt::query()->forTenantId($tenant->id)->where('pos_register_id', $record->id)
            ->whereIn('status', ['creating', 'awaiting_reader', 'processing', 'unknown', 'review'])->exists();

        return $this->privateView('pos.register', [
            'tenant' => $tenant, 'register' => $record, 'catalog' => $catalog, 'attempts' => $attempts,
            'blocked' => $blocked, 'requestId' => old('request_id', (string) Str::uuid()), 'freshRequestId' => (string) Str::uuid(),
            'requestLookupUrl' => route('pos.request-status', ['register' => $record->id, 'requestId' => '00000000-0000-0000-0000-000000000000', 'tenant' => $tenant->slug]),
        ]);
    }

    public function requestStatus(Request $request, int $register, string $requestId, PosAccess $access, PosService $sales): Response
    {
        $tenant = $this->tenant($request);
        $access->register($tenant, $request->user(), $register);
        $attempt = PosPaymentAttempt::query()->forTenantId($tenant->id)->where('pos_register_id', $register)->where('request_id', $requestId)->first();
        if ($attempt) {
            $sales->inspectAttempt($tenant, $request->user(), $attempt->id);
        }

        return response()->json(['record' => $attempt ? [
            'id' => $attempt->id, 'request_id' => $attempt->request_id, 'status' => $attempt->status,
            'url' => route('pos.show', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]),
        ] : null])->withHeaders($this->privateHeaders());
    }

    public function start(Request $request, int $register, PosAccess $access, PosService $sales): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $access->register($tenant, $request->user(), $register);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'lines' => ['required', 'array', 'max:100'],
            'customer_id' => ['prohibited'], 'website_customer_id' => ['prohibited'],
            'lines.*.variant_id' => ['required', 'integer', 'min:1'], 'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);
        $data['lines'] = array_values(array_filter($data['lines'], fn ($line) => (int) $line['quantity'] > 0));
        $attempt = $sales->start($tenant, $request->user(), $register, $data);

        return $this->saleRedirect($tenant, $attempt->id);
    }

    public function show(Request $request, int $attempt, PosAccess $access): Response
    {
        [$tenant, $record, $register, $order] = $this->sale($request, $attempt, $access);
        $refunds = PosRefund::query()->forTenantId($tenant->id)->where('pos_payment_attempt_id', $record->id)->orderBy('id')->get();
        $canRefund = true;
        try {
            $access->register($tenant, $request->user(), $register->id, true);
        } catch (HttpException) {
            $canRefund = false;
        }

        return $this->privateView('pos.sale', [
            'tenant' => $tenant, 'attempt' => $record, 'register' => $register, 'order' => $order,
            'refunds' => $refunds, 'canRefund' => $canRefund, 'refundRequestId' => old('request_id', (string) Str::uuid()), 'freshRefundRequestId' => (string) Str::uuid(),
            'refundRequestRecords' => $canRefund ? $refunds->map(fn ($refund) => ['id' => $refund->id, 'request_id' => $refund->request_id,
                'status' => $refund->status, 'url' => '#refund-'.$refund->id])->values()->all() : [],
        ]);
    }

    public function reconcile(Request $request, int $attempt, PosAccess $access, PosService $sales): RedirectResponse
    {
        [$tenant] = $this->sale($request, $attempt, $access);
        $sales->reconcile($tenant, $request->user(), $attempt);

        return $this->saleRedirect($tenant, $attempt);
    }

    public function cancel(Request $request, int $attempt, PosAccess $access, PosService $sales): RedirectResponse
    {
        [$tenant] = $this->sale($request, $attempt, $access);
        $sales->cancel($tenant, $request->user(), $attempt);

        return $this->saleRedirect($tenant, $attempt);
    }

    public function simulate(Request $request, int $attempt, PosAccess $access, PosService $sales): RedirectResponse
    {
        [$tenant] = $this->sale($request, $attempt, $access);
        $data = $request->validate(['outcome' => ['required', 'in:approved,declined,unknown,cancelled']]);
        $sales->simulate($tenant, $request->user(), $attempt, $data['outcome']);

        return $this->saleRedirect($tenant, $attempt);
    }

    public function refund(Request $request, int $attempt, PosAccess $access, PosService $sales): RedirectResponse
    {
        [$tenant, , $register] = $this->sale($request, $attempt, $access);
        $access->register($tenant, $request->user(), $register->id, true);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'amount' => ['required', 'string', 'regex:/^(0|[1-9]\d{0,6})(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        [$whole, $fraction] = array_pad(explode('.', $data['amount'], 2), 2, '');
        $sales->refund($tenant, $request->user(), $attempt, [
            'request_id' => $data['request_id'], 'amount_cents' => ((int) $whole * 100) + (int) str_pad($fraction, 2, '0'), 'reason' => $data['reason'],
        ]);

        return $this->saleRedirect($tenant, $attempt)->with('pos_refund_saved', true);
    }

    public function reconcileRefund(Request $request, int $refund, PosAccess $access, PosService $sales): RedirectResponse
    {
        [$tenant, $record, $attempt] = $this->refundRecord($request, $refund, $access);
        $sales->reconcileRefund($tenant, $request->user(), $record->id);

        return $this->saleRedirect($tenant, $attempt->id);
    }

    public function simulateRefund(Request $request, int $refund, PosAccess $access, PosService $sales): RedirectResponse
    {
        [$tenant, $record, $attempt] = $this->refundRecord($request, $refund, $access);
        $data = $request->validate(['outcome' => ['required', 'in:approved,declined,unknown']]);
        $sales->simulateRefund($tenant, $request->user(), $record->id, $data['outcome']);

        return $this->saleRedirect($tenant, $attempt->id);
    }

    public function receipt(Request $request, int $attempt, PosAccess $access): Response
    {
        [$tenant, $record, $register, $order] = $this->sale($request, $attempt, $access);
        abort_unless($record->status === 'succeeded' && $record->stock_committed_at
            && in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true), 409, 'A receipt is available only after this simulated sale is verified.');
        $refundedCents = PosRefund::query()->forTenantId($tenant->id)->where('pos_payment_attempt_id', $record->id)->where('status', 'succeeded')->sum('amount_cents');

        return $this->privateView('pos.receipt', [
            'tenant' => $tenant, 'attempt' => $record, 'register' => $register, 'order' => $order, 'refundedCents' => (int) $refundedCents,
        ]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && $request->user(), 403);

        return $tenant;
    }

    private function sale(Request $request, int $id, PosAccess $access): array
    {
        $tenant = $this->tenant($request);
        $attempt = app(PosService::class)->inspectAttempt($tenant, $request->user(), $id);
        $register = $access->register($tenant, $request->user(), $attempt->pos_register_id);
        $order = WebsiteOrder::query()->forTenantId($tenant->id)->where('tenant_site_id', $register->tenant_site_id)
            ->where('source', 'pos_simulation')->with('lines')->findOrFail($attempt->website_order_id);

        return [$tenant, $attempt, $register, $order];
    }

    private function refundRecord(Request $request, int $id, PosAccess $access): array
    {
        $tenant = $this->tenant($request);
        $access->authorize($tenant, $request->user(), true);
        $refund = PosRefund::query()->forTenantId($tenant->id)->findOrFail($id);
        [, $attempt, $register] = $this->sale($request, $refund->pos_payment_attempt_id, $access);
        $access->register($tenant, $request->user(), $register->id, true);

        return [$tenant, $refund, $attempt];
    }

    private function saleRedirect(Tenant $tenant, int $id): RedirectResponse
    {
        return redirect()->route('pos.show', ['attempt' => $id, 'tenant' => $tenant->slug]);
    }

    private function privateView(string $view, array $data): Response
    {
        return response()->view($view, $data)->withHeaders($this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
        ];
    }
}
