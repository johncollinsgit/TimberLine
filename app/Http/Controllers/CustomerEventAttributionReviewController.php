<?php

namespace App\Http\Controllers;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CustomerEventAttributionReview;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;

class CustomerEventAttributionReviewController extends Controller
{
    public function __invoke(Request $request, string $profile)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'customers'), 403);
        $customer = MarketingProfile::forTenantId($tenant->id)->findOrFail($profile);
        $result = app(CustomerEventAttributionReview::class)->review($request->user(), $tenant->id, $customer, $request->all());
        if (! $request->expectsJson()) {
            return redirect()->route('customers.profile', ['tenant' => $tenant->slug, 'profile' => $profile])->with('toast', ['style' => 'success', 'message' => 'Event attribution evidence reviewed.']);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
