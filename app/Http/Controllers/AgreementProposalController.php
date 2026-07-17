<?php

namespace App\Http\Controllers;

use App\Services\Agreements\AgreementAcceptanceService;
use App\Services\Agreements\AgreementProposalAccessService;
use App\Services\Billing\AgreementStripeCheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AgreementProposalController extends Controller
{
    public function show(Request $request, string $token, AgreementProposalAccessService $access): View
    {
        $agreement = $access->resolve($token);

        $order = $agreement->billingOrders->sortByDesc('id')->first();

        return view('agreements.proposal', ['agreement' => $agreement, 'token' => $token, 'unlocked' => $access->isUnlocked($request, $agreement), 'billingOrder' => $order, 'checkoutAvailable' => $order ? app(AgreementStripeCheckoutService::class)->availableFor($order) : false]);
    }

    public function unlock(Request $request, string $token, AgreementProposalAccessService $access): RedirectResponse
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $agreement = $access->resolve($token);
        if (! $access->unlock($request, $agreement, (string) $validated['password'])) {
            throw ValidationException::withMessages(['password' => 'The proposal password is incorrect.']);
        }

        return redirect()->route('proposals.show', ['token' => $token]);
    }

    public function accept(Request $request, string $token, AgreementProposalAccessService $access, AgreementAcceptanceService $acceptanceService): RedirectResponse
    {
        $agreement = $access->resolve($token);
        abort_unless($access->isUnlocked($request, $agreement), 403);
        if ($agreement->acceptance) {
            return redirect()->route('proposals.show', ['token' => $token])->with('status', 'This exact agreement version was already accepted.');
        }
        $validated = $request->validate([
            'signer_legal_name' => ['required', 'string', 'max:255'],
            'signer_title' => ['required', 'string', 'max:255'],
            'signer_email' => ['required', 'email', 'max:255'],
            'electronic_signature_value' => ['required', 'string', 'max:255'],
            'authorized_to_bind' => ['accepted'],
            'accepted_scope' => ['accepted'],
            'accepted_pricing' => ['accepted'],
            'accepted_subscription' => ['accepted'],
            'accepted_hourly_rate' => ['accepted'],
            'accepted_termination' => ['accepted'],
            'electronic_consent' => ['accepted'],
        ]);
        if (mb_strtolower(trim((string) $validated['electronic_signature_value'])) !== mb_strtolower(trim((string) $validated['signer_legal_name']))) {
            throw ValidationException::withMessages(['electronic_signature_value' => 'Type the signer’s full legal name exactly as entered above.']);
        }
        $acceptanceService->accept($agreement, $validated, $request);

        return redirect()->route('proposals.show', ['token' => $token])->with('status', 'Agreement accepted. Your permanent copy is ready.');
    }

    public function download(Request $request, string $token, AgreementProposalAccessService $access): BinaryFileResponse
    {
        $agreement = $access->resolve($token);
        abort_unless($access->isUnlocked($request, $agreement), 403);
        $acceptance = $agreement->acceptance;
        abort_unless($acceptance && filled($acceptance->snapshot_path), 404);

        return Storage::disk('local')->download((string) $acceptance->snapshot_path, 'everbranch-agreement-'.$agreement->id.'.html', ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function checkout(Request $request, string $token, AgreementProposalAccessService $access, AgreementStripeCheckoutService $checkout): RedirectResponse
    {
        $agreement = $access->resolve($token);
        abort_unless($access->isUnlocked($request, $agreement), 403);
        abort_unless($agreement->acceptance && (int) $agreement->acceptance->agreement_version_id === (int) $agreement->current_version_id, 409);
        $order = $agreement->billingOrders()->where('agreement_version_id', $agreement->current_version_id)->firstOrFail();
        $result = $checkout->create($order, $token);
        if (! $result['ok'] || ! filled($result['url'])) {
            return redirect()->route('proposals.show', ['token' => $token])->with('status_error', $result['message'] ?? 'Secure checkout is not available.');
        }

        return redirect()->away((string) $result['url']);
    }
}
