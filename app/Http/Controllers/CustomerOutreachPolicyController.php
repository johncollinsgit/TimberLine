<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Marketing\CustomerOutreachPolicy;
use Illuminate\Http\Request;

class CustomerOutreachPolicyController extends Controller
{
    public function __invoke(Request $request, CustomerOutreachPolicy $policies)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && $request->user(), 403);
        $policies->authorize($tenant, $request->user());
        if ($request->isMethod('post') || $request->isMethod('patch')) {
            $revision = $policies->revise($tenant, $request->user(), $request->all());
            if ($request->expectsJson()) {
                return response()->json(['revision' => $revision, 'editor' => $policies->editor($tenant, $request->user())])->header('Cache-Control', 'private, no-store');
            }

            return redirect()->back()->with('status', $revision['status'] === 'published' ? 'Outreach limits published. Campaign approval and delivery checks still apply.' : 'Outreach limits draft saved. The published policy remains in use.');
        }
        $editor = $policies->editor($tenant, $request->user());
        if ($request->expectsJson()) {
            return response()->json($editor)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('customers.outreach-policy', ['tenant' => $tenant, 'editor' => $editor])->header('Cache-Control', 'private, no-store');
    }
}
