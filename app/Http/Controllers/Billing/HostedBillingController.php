<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\StripeHostedBillingService;
use App\Services\Billing\TenantBillingNextStepResolver;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HostedBillingController extends Controller
{
    public function checkout(
        Request $request,
        TenantCommercialExperienceService $experienceService,
        TenantBillingNextStepResolver $nextStepResolver,
        StripeHostedBillingService $stripeService
    ): RedirectResponse {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        abort_unless($request->user() instanceof User, 403);

        $journey = $experienceService->merchantJourneyPayload((int) $tenant->id);
        $billingInterest = is_array($journey['billing_interest'] ?? null) ? (array) $journey['billing_interest'] : [];
        $nextStep = $nextStepResolver->resolveForTenantId((int) $tenant->id, $billingInterest);

        if (($nextStep['mode'] ?? null) !== 'hosted_checkout') {
            return back()->with('status_error', 'Hosted checkout is not available for this tenant yet.');
        }

        $result = $stripeService->createCheckoutSession($tenant, $request->user(), $billingInterest);
        if (! ($result['ok'] ?? false) || ! filled($result['url'] ?? null)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe checkout session could not be created.'));
        }

        return redirect()->away((string) $result['url']);
    }

    public function portal(
        Request $request,
        TenantBillingNextStepResolver $nextStepResolver,
        StripeHostedBillingService $stripeService,
        TenantCommercialExperienceService $experienceService
    ): RedirectResponse {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        abort_unless($request->user() instanceof User, 403);

        $journey = $experienceService->merchantJourneyPayload((int) $tenant->id);
        $billingInterest = is_array($journey['billing_interest'] ?? null) ? (array) $journey['billing_interest'] : [];
        $nextStep = $nextStepResolver->resolveForTenantId((int) $tenant->id, $billingInterest);

        if (($nextStep['mode'] ?? null) !== 'billing_portal') {
            return back()->with('status_error', 'Billing portal is not available for this tenant yet.');
        }

        $result = $stripeService->createBillingPortalSession($tenant, $request->user());
        if (! ($result['ok'] ?? false) || ! filled($result['url'] ?? null)) {
            return back()->with('status_error', (string) ($result['message'] ?? 'Stripe billing portal session could not be created.'));
        }

        return redirect()->away((string) $result['url']);
    }
}
