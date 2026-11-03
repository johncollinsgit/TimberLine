<?php

namespace App\Enterprise;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\EverbranchMobileAuthorizationController;
use Illuminate\Http\Request;

final class PosMobileController extends Controller
{
    public function authorize(Request $request)
    {
        abort_unless($request->query('client_id') === 'everbranch-pos-ios' && $request->query('auth_method') === 'email', 422);

        return app(EverbranchMobileAuthorizationController::class)($request);
    }

    public function workspaces(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->is_active && $user->hasVerifiedEmail(), 403);

        return response()->json(['workspaces' => $user->tenants()->wherePivot('membership_active', true)
            ->orderBy('tenants.name')->get(['tenants.id', 'tenants.name', 'tenants.slug'])->map(fn ($tenant) => ['id' => (int) $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug])], 200, ['Cache-Control' => 'private, no-store']);
    }
}
