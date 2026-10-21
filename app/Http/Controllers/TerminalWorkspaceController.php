<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\TenantPaymentSetupAccess;
use App\Services\Billing\TenantPaymentsReadinessService;
use App\Services\PointOfSale\PosAccess;
use App\Services\PointOfSale\TerminalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TerminalWorkspaceController extends Controller
{
    public function entry(Request $request, TerminalWorkspaceAccess $access): Response
    {
        if ($request->user()) {
            $access->actor($request);

            return redirect()->route('terminal.workspaces');
        }
        $access->reset($request);
        // Existing password/Google/2FA flows consume the ordinary intended URL.
        $request->session()->put('url.intended', route('terminal.workspaces', absolute: false));

        return $this->view('terminal.entry');
    }

    public function workspaces(Request $request, TerminalWorkspaceAccess $access): Response
    {
        $actor = $access->actor($request);
        $access->clear($request);

        return $this->view('terminal.workspaces', [
            'actor' => $actor,
            'workspaces' => $access->workspaces($actor)->map(fn (Tenant $tenant): array => $access->summary($tenant)),
        ]);
    }

    public function select(Request $request, TerminalWorkspaceAccess $access): RedirectResponse
    {
        $actor = $access->actor($request);
        abort_unless(array_diff(array_keys($request->all()), ['_token', 'tenant_id']) === [], 422, 'Select only an authorized workspace.');
        $data = $request->validate(['tenant_id' => ['required', 'integer', 'min:1']]);
        $access->select($request, $actor, (int) $data['tenant_id']);

        return redirect()->route('terminal.register');
    }

    public function clear(Request $request, TerminalWorkspaceAccess $access): RedirectResponse
    {
        $access->actor($request);
        $access->clear($request);

        return redirect()->route('terminal.workspaces');
    }

    public function register(Request $request, TerminalWorkspaceAccess $access, TenantPaymentSetupAccess $paymentAccess, TenantPaymentsReadinessService $payments): Response
    {
        $actor = $access->actor($request);
        $tenant = $access->selected($request, $actor);
        if (! $tenant) {
            return redirect()->route('terminal.workspaces');
        }

        $canManagePayments = $paymentAccess->allows($tenant, $actor);
        $connection = null;
        if ($canManagePayments) {
            // A saved, tenant-scoped projection only. Never refresh a provider here.
            $readiness = $payments->readinessFor($tenant);
            $account = $readiness['account'];
            $connection = [
                'present' => $account !== null,
                'label' => match ($account?->status) {
                    'ready' => 'Connection saved', 'restricted' => 'Saved connection needs attention',
                    'onboarding' => 'Setup in progress', 'disabled' => 'Disconnected', default => 'Not connected',
                },
                'cardsEnabled' => (bool) $account?->charges_enabled,
                'payoutsEnabled' => (bool) $account?->payouts_enabled,
                'checkedAt' => $account?->last_checked_at
                    ? $account->last_checked_at->copy()->utc()->format('M j, Y H:i').' UTC' : null,
                'onlineChecksReady' => (bool) $readiness['ready'],
                'routingLabel' => match ($account?->routingMode()) {
                    'direct' => 'Direct merchant payments (saved routing)',
                    'destination' => 'Platform destination payments (saved routing)',
                    default => 'Routing not verified',
                },
                'identityLabel' => 'Saved merchant connection for '.$tenant->name,
            ];
        }

        return $this->view('terminal.register', [
            'actor' => $actor, 'workspace' => $access->summary($tenant),
            'canManagePayments' => $canManagePayments, 'connection' => $connection,
            'practiceUrl' => $this->practiceUrl($tenant, $actor),
            'expiresInMinutes' => (int) (TerminalWorkspaceAccess::IDLE_SECONDS / 60),
        ]);
    }

    private function practiceUrl(Tenant $tenant, User $actor): ?string
    {
        try {
            app(PosAccess::class)->authorize($tenant, $actor);

            return route('pos.setup', ['tenant' => $tenant->slug]);
        } catch (HttpException) {
            return null;
        }
    }

    private function view(string $view, array $data = []): Response
    {
        // Standalone terminal pages must not inherit worker-retained Livewire assets.
        app('livewire')->flushState();
        $nonce = base64_encode(random_bytes(24));

        return response()->view($view, [...$data, 'nonce' => $nonce])->withHeaders([
            'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'none'; style-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; worker-src 'none'; frame-src 'none'; font-src 'none'",
        ]);
    }
}
