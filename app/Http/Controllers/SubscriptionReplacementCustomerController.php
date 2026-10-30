<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionBillingCycle;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionReplacementCustomerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionReplacementCustomerController extends Controller
{
    public function show(Request $request, string $token, SubscriptionReplacementCustomerAccess $access)
    {
        $this->host($request);
        $session = $access->session($token);
        $row = $access->agreement($session);

        return $this->private(response()->view('subscriptions.replacement-customer', [
            'agreement' => $row, 'token' => $token, 'tenant' => Tenant::findOrFail($row->tenant_id),
            'actions' => collect(['pause', 'resume', 'skip', 'cancel'])->mapWithKeys(fn ($kind) => [$kind => $access->canChange($row, $kind)])->all(),
            'requestId' => (string) Str::uuid(), 'memberName' => $session->profile->first_name,
            'cycles' => SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->orderByDesc('cycle_date')->limit(12)->get(),
        ]));
    }

    public function change(Request $request, string $token, SubscriptionReplacementCustomerAccess $access, SubscriptionBillingAgreementService $service)
    {
        $this->host($request);
        $session = $access->session($token);
        $data = $request->validate(['action' => ['required', 'in:pause,resume,skip,cancel'],
            'request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:12']]);
        try {
            $action = $service->customerChange($session, $data['action'], $data['request_id'], (int) $data['revision'],
                isset($data['duration_months']) ? ['duration_months' => (int) $data['duration_months']] : []);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if (! in_array($error->getStatusCode(), [409, 423], true)) {
                throw $error;
            }

            return $this->private(redirect()->route('subscriptions.replacement.customer', ['token' => $token])->withErrors(['subscription' => $error->getMessage()]));
        }
        $state = $action->after_state;
        $message = match ($data['action']) {
            'pause' => $state['resume_on'] ? 'Paused. Your next scheduled billing date is '.$state['resume_on'].'.' : 'Your future subscription billing is paused.',
            'resume' => 'Your subscription resumes on '.$state['next_billing_date'].'.',
            'skip' => 'Your next cycle is skipped. The next scheduled billing date is '.$state['next_billing_date'].'.',
            'cancel' => 'Your future subscription billing is cancelled.',
        };
        if ($state['original_payment_requires_review'] ?? false) {
            $message .= ' A payment already started is still being verified. Contact our team about that payment.';
        }

        return $this->private(redirect()->route('subscriptions.replacement.customer', ['token' => $token])->with('subscription_status', $message));
    }

    private function host(Request $request): void
    {
        abort_unless($request->getHost() === parse_url((string) config('app.url'), PHP_URL_HOST), 404);
    }

    private function private($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('X-Frame-Options', 'DENY');
    }
}
