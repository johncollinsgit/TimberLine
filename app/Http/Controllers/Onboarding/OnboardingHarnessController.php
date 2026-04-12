<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingHarnessController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless((bool) config('app.debug', false), 404);
        abort_unless((bool) config('features.internal_onboarding_harness', false), 404);

        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $tenantToken = trim((string) $request->query('tenant', $tenant->slug ?? ''));
        if ($tenantToken === '') {
            $tenantToken = (string) $tenant->id;
        }

        $contractUrl = route('onboarding.api.contract', array_filter([
            'tenant' => $tenantToken,
            'rail' => $request->query('rail'),
        ], static fn ($value): bool => $value !== null && trim((string) $value) !== ''));

        $autosaveUrl = route('onboarding.api.draft.autosave', [
            'tenant' => $tenantToken,
        ]);

        return view('admin.onboarding-harness', [
            'contractUrl' => $contractUrl,
            'autosaveUrl' => $autosaveUrl,
            'tenantToken' => $tenantToken,
        ]);
    }
}
