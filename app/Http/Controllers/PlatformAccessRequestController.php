<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\CustomerAccessRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlatformAccessRequestController extends Controller
{
    public function store(Request $request, CustomerAccessRequestService $service): RedirectResponse
    {
        $validated = $request->validate([
            'intent' => ['required', 'string', 'in:demo,production'],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'requested_tenant_slug' => ['nullable', 'string', 'max:120'],
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
            'message' => (string) ($validated['message'] ?? ''),
            'preferred_plan_key' => (string) ($validated['preferred_plan_key'] ?? ''),
            'addons_interest' => (array) ($validated['addons_interest'] ?? []),
        ]);

        return redirect()
            ->route('platform.request-submitted', ['intent' => (string) $validated['intent']])
            ->with('status', 'Request received. We will email you once access is approved.');
    }
}
