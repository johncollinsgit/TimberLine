<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\CustomerAccessRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformAccessRequestController extends Controller
{
    public function store(Request $request, CustomerAccessRequestService $service): RedirectResponse
    {
        $businessTypeKeys = array_keys((array) config('product_surfaces.access_request.business_types', []));
        $teamSizeKeys = array_keys((array) config('product_surfaces.access_request.team_sizes', []));
        $timelineKeys = array_keys((array) config('product_surfaces.access_request.timelines', []));

        $validated = $request->validate([
            'intent' => ['required', 'string', 'in:demo,production'],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'requested_tenant_slug' => ['nullable', 'string', 'max:120'],
            'business_type' => ['nullable', 'string', 'max:80', Rule::in($businessTypeKeys)],
            'team_size' => ['nullable', 'string', 'max:80', Rule::in($teamSizeKeys)],
            'timeline' => ['nullable', 'string', 'max:80', Rule::in($timelineKeys)],
            'website' => ['nullable', 'url', 'max:300'],
            'message' => ['nullable', 'string', 'max:2000'],
            'preferred_plan_key' => ['nullable', 'string', 'max:80'],
            'addons_interest' => ['nullable', 'array'],
            'addons_interest.*' => ['string', 'max:120'],
        ]);

        $service->submit([
            'intent' => (string) $validated['intent'],
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'company' => (string) ($validated['company'] ?? ''),
            'requested_tenant_slug' => (string) ($validated['requested_tenant_slug'] ?? ''),
            'business_type' => (string) ($validated['business_type'] ?? ''),
            'team_size' => (string) ($validated['team_size'] ?? ''),
            'timeline' => (string) ($validated['timeline'] ?? ''),
            'website' => (string) ($validated['website'] ?? ''),
            'message' => (string) ($validated['message'] ?? ''),
            'preferred_plan_key' => (string) ($validated['preferred_plan_key'] ?? ''),
            'addons_interest' => (array) ($validated['addons_interest'] ?? []),
        ]);

        return redirect()
            ->route('platform.request-submitted', ['intent' => (string) $validated['intent']])
            ->with('status', 'Request received. We will email you once access is approved.');
    }
}
