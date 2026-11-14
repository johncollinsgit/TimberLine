<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantCommercialOverride;
use App\Models\User;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Billing\SelfServiceStandardPurchaseService;
use App\Services\Onboarding\BranchSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SelfServicePurchaseController extends Controller
{
    public function show(Request $request, SelfServiceStandardPurchaseService $purchases, SelfServicePurchaseEligibility $eligibility, BranchSelectionService $selection): Response
    {
        [$tenant, $user] = $this->context($request);
        $tenant->loadMissing('setupStatus');
        $branches = $selection->normalize((array) $request->session()->get(BranchSelectionService::SESSION_KEY, $tenant->setupStatus?->module_interests ?? []), false);
        $pending = $this->pending($tenant);
        $quote = $purchases->quoteForReview($tenant, $user, $branches);
        if ($pending) {
            $branches = $selection->normalize((array) $quote['branches'], false);
        }

        return response()->view('onboarding.self-service-purchase', [
            'tenant' => $tenant, 'quote' => $quote, 'modules' => $selection->modules(), 'selected' => $branches,
            'paid' => $eligibility->paidForTenant($tenant),
            'purchased' => $eligibility->hasVerifiedPurchase($tenant),
            'pending' => $pending, 'ready' => $purchases->readiness()['ready'],
        ]);
    }

    public function checkout(Request $request, SelfServiceStandardPurchaseService $purchases, BranchSelectionService $selection): RedirectResponse
    {
        [$tenant, $user] = $this->context($request);
        $input = $request->validate([
            'branches' => ['nullable', 'array', 'max:50'], 'branches.*' => ['string', 'max:80'],
            'quote_hash' => ['required', 'string', 'size:64'], 'terms_accepted' => ['accepted'],
        ]);
        $branches = $selection->normalize((array) ($input['branches'] ?? []));
        $request->session()->put(BranchSelectionService::SESSION_KEY, $branches);
        $result = $purchases->checkout($tenant, $user, $branches, [
            'terms_accepted' => true, 'quote_hash' => $input['quote_hash'],
            'ip' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
        if (! $result['ok'] || ! $result['url']) {
            return back()->withInput()->with('status_error', $result['message'] ?: 'Checkout is unavailable. Your choices are saved.');
        }

        return redirect()->away($result['url']);
    }

    public function status(Request $request, SelfServicePurchaseEligibility $eligibility): JsonResponse
    {
        [$tenant] = $this->context($request);

        return response()->json(['paid' => $eligibility->paidForTenant($tenant), 'pending' => $this->pending($tenant)])
            ->header('Cache-Control', 'no-store');
    }

    public function portal(Request $request, SelfServiceStandardPurchaseService $purchases): RedirectResponse
    {
        [$tenant, $user] = $this->context($request);
        $result = $purchases->createBillingPortal($tenant, $user);
        if (! $result['ok'] || ! $result['url']) {
            return back()->with('status_error', $result['message'] ?: 'Billing could not open. Try again shortly.');
        }

        return redirect()->away($result['url']);
    }

    public function branches(Request $request, SelfServiceStandardPurchaseService $purchases): RedirectResponse
    {
        [$tenant, $user] = $this->context($request);
        $input = $request->validate(['branches' => ['required', 'array', 'max:50'], 'branches.*' => ['string', 'max:80']]);
        $purchases->activateIncludedBranches($tenant, $user, $input['branches']);

        if (in_array('managed_website', $input['branches'], true)) {
            return redirect()->route('managed-website.index', ['tenant' => $tenant->slug])->with('status', 'Website is included. Choose your design.');
        }

        return back()->with('status', 'Included branches added.');
    }

    private function context(Request $request): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof User && $user->is_active && $user->hasVerifiedEmail(), 403);
        abort_unless($user->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'owner')->wherePivot('membership_active', true)->exists(), 403);

        return [$tenant, $user];
    }

    private function pending(Tenant $tenant): bool
    {
        $mapping = TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->value('billing_mapping');

        return in_array(data_get($mapping, 'self_service_purchase.status'), ['creating', 'checkout_pending', 'pending', 'authorized'], true);
    }
}
