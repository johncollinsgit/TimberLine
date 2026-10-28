<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPilotCycle;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionPilotExecutionService;
use Illuminate\Http\Request;

class SubscriptionPilotCustomerController extends Controller
{
    public function show(Request $request, string $token, SubscriptionPilotExecutionService $execution)
    {
        $this->host($request);
        $row = $execution->fromAccess($token);
        $notice = null;
        if ($request->query('return') === '1' && $row->setup_session_id) {
            try {
                $row = $execution->reconcileAuthorization($row);
            } catch (\Throwable) {
                $notice = 'Your payment setup is still being verified. Billing has not been activated.';
            }
        }

        return $this->private(response()->view('subscriptions.pilot-customer', ['enrollment' => $row, 'token' => $token, 'notice' => $notice,
            'tenant' => Tenant::findOrFail($row->tenant_id), 'cycles' => SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)
                ->where('enrollment_id', $row->id)->orderByDesc('cycle_date')->limit(12)->get()]));
    }

    public function mobileManage(Request $request, \App\Services\Mobile\ModernForestryMobileCustomerSessionService $sessions, SubscriptionPilotExecutionService $execution)
    {
        $session = $sessions->resolveFromRequest($request);
        abort_unless($session, 401);
        $row = app(\App\Services\Subscriptions\SubscriptionPilotMobileAccess::class)->enrollment($session);
        abort_unless($row, 404);

        return $this->private(response()->json(['url' => $execution->issueAccess($row)]));
    }

    public function authorizePayment(Request $request, string $token, SubscriptionPilotExecutionService $execution)
    {
        $this->host($request);
        $row = $execution->fromAccess($token);
        $data = $request->validate(['terms_hash' => ['required', 'string', 'size:64'], 'accepted' => ['accepted']]);
        $url = $execution->beginAuthorization($row, $token, $data['terms_hash'], $request->boolean('accepted'), (string) $request->ip());

        return $this->private(redirect()->away($url));
    }

    public function stop(Request $request, string $token, SubscriptionPilotExecutionService $execution)
    {
        $this->host($request);
        $row = $execution->fromAccess($token);
        $data = $request->validate(['action' => ['required', 'in:pause,cancel']]);
        $execution->stop($row, $data['action']);

        return $this->private(redirect()->route('subscriptions.pilot.customer', ['token' => $token])
            ->with('pilot_customer_status', 'Future subscription charges are '.($data['action'] === 'cancel' ? 'cancelled' : 'paused').'. A payment already processing may need support review.'));
    }

    public function authentication(Request $request, string $token, int $cycle, SubscriptionPilotExecutionService $execution)
    {
        $this->host($request);
        $row = $execution->fromAccess($token);
        $payment = SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->where('enrollment_id', $row->id)->findOrFail($cycle);

        return $this->private(response()->json($execution->authentication($row, $payment)));
    }

    public function confirmPayment(Request $request, string $token, int $cycle, SubscriptionPilotExecutionService $execution)
    {
        $this->host($request);
        $row = $execution->fromAccess($token);
        $payment = SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->where('enrollment_id', $row->id)->findOrFail($cycle);
        $current = $execution->reconcilePayment($payment);

        return $this->private(response()->json(['status' => $current->payment_status, 'shopify_status' => $current->handoff_status]));
    }

    private function host(Request $request): void
    {
        abort_unless($request->getHost() === parse_url((string) config('app.url'), PHP_URL_HOST), 404);
    }

    private function private($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('Referrer-Policy','no-referrer')
            ->header('X-Robots-Tag','noindex, nofollow')->header('X-Frame-Options','DENY');
    }
}
