<?php

namespace App\Http\Controllers;

use App\Notifications\WholesaleApplicationReviewNotification;
use App\Services\Onboarding\CustomerAccessRequestService;
use App\Services\Shopify\ShopifyWholesaleApplicationCustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Throwable;

class PlatformAccessRequestController extends Controller
{
    public function store(Request $request, CustomerAccessRequestService $service): RedirectResponse
    {
        $businessTypeKeys = array_keys((array) config('product_surfaces.access_request.business_types', []));
        $teamSizeKeys = array_keys((array) config('product_surfaces.access_request.team_sizes', []));
        $timelineKeys = array_keys((array) config('product_surfaces.access_request.timelines', []));
        $importPathKeys = array_keys((array) config('product_surfaces.access_request.import_paths', []));
        $mobileInterestKeys = array_keys((array) config('product_surfaces.access_request.mobile_interests', []));

        $validated = $request->validate([
            'intent' => ['required', 'string', 'in:demo,production'],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'requested_tenant_slug' => ['nullable', 'string', 'max:120'],
            'business_type' => ['nullable', 'string', 'max:80', Rule::in($businessTypeKeys)],
            'team_size' => ['nullable', 'string', 'max:80', Rule::in($teamSizeKeys)],
            'timeline' => ['nullable', 'string', 'max:80', Rule::in($timelineKeys)],
            'import_path' => ['nullable', 'string', 'max:80', Rule::in($importPathKeys)],
            'mobile_interest' => ['nullable', 'string', 'max:80', Rule::in($mobileInterestKeys)],
            'website' => ['nullable', 'url', 'max:300'],
            'message' => ['nullable', 'string', 'max:2000'],
            'preferred_plan_key' => ['nullable', 'string', 'max:80'],
            'addons_interest' => ['nullable', 'array'],
            'addons_interest.*' => ['string', 'max:120'],
        ]);

        $requestRecord = $service->submit([
            'intent' => (string) $validated['intent'],
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'company' => (string) ($validated['company'] ?? ''),
            'requested_tenant_slug' => (string) ($validated['requested_tenant_slug'] ?? ''),
            'business_type' => (string) ($validated['business_type'] ?? ''),
            'team_size' => (string) ($validated['team_size'] ?? ''),
            'timeline' => (string) ($validated['timeline'] ?? ''),
            'import_path' => (string) ($validated['import_path'] ?? ''),
            'mobile_interest' => (string) ($validated['mobile_interest'] ?? ''),
            'website' => (string) ($validated['website'] ?? ''),
            'message' => (string) ($validated['message'] ?? ''),
            'preferred_plan_key' => (string) ($validated['preferred_plan_key'] ?? ''),
            'addons_interest' => (array) ($validated['addons_interest'] ?? []),
        ]);

        try {
            Notification::route(
                'mail',
                (string) config('product_surfaces.access_request.review_email', 'modernforestryteam@gmail.com')
            )->notify(new WholesaleApplicationReviewNotification($requestRecord));
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()
            ->route('platform.request-submitted', ['intent' => (string) $validated['intent']])
            ->with('status', 'Request received. We will email you once access is approved.');
    }

    public function storeForWholesaleStorefront(
        Request $request,
        CustomerAccessRequestService $service,
        ShopifyWholesaleApplicationCustomerService $shopifyWholesaleApplicationCustomerService
    ) {
        $validated = $request->validate([
            'intent' => ['nullable', 'string', 'in:production,demo'],
            'contact.name' => ['required', 'string', 'max:190'],
            'contact.email' => ['required', 'email', 'max:190'],
            'contact.phone' => ['required', 'string', 'max:50'],
            'contact.company' => ['required', 'string', 'max:190'],
            'contact.store_type' => ['nullable', 'string', 'max:190'],
            'contact.city' => ['nullable', 'string', 'max:120'],
            'contact.state' => ['nullable', 'string', 'max:40'],
            'contact.website' => ['nullable', 'url', 'max:300'],
            'contact.position' => ['nullable', 'string', 'max:190'],
            'contact.referral' => ['nullable', 'string', 'max:190'],
            'contact.business_info' => ['nullable', 'string', 'max:4000'],
            'contact.current_suppliers' => ['nullable', 'string', 'max:190'],
            'contact.address' => ['nullable', 'string', 'max:190'],
            'contact.address2' => ['nullable', 'string', 'max:190'],
            'contact.zip' => ['nullable', 'string', 'max:40'],
            'contact.country' => ['nullable', 'string', 'max:120'],
            'contact.retail_license_number' => ['nullable', 'string', 'max:190'],
            'contact.contact_preference' => ['nullable', 'string', 'max:40'],
            'contact.agreement' => ['accepted'],
            'contact.body' => ['nullable', 'string', 'max:10000'],
        ]);

        $contact = (array) ($validated['contact'] ?? []);

        $requestRecord = $service->submit([
            'intent' => 'production',
            'name' => trim((string) ($contact['name'] ?? '')),
            'email' => trim((string) ($contact['email'] ?? '')),
            'company' => trim((string) ($contact['company'] ?? '')),
            'business_type' => trim((string) ($contact['store_type'] ?? '')),
            'website' => trim((string) ($contact['website'] ?? '')),
            'message' => trim((string) ($contact['body'] ?? $contact['business_info'] ?? '')),
            'phone' => trim((string) ($contact['phone'] ?? '')),
            'city' => trim((string) ($contact['city'] ?? '')),
            'state' => trim((string) ($contact['state'] ?? '')),
            'zip' => trim((string) ($contact['zip'] ?? '')),
            'country' => trim((string) ($contact['country'] ?? '')),
            'address' => trim((string) ($contact['address'] ?? '')),
            'address2' => trim((string) ($contact['address2'] ?? '')),
            'retail_license_number' => trim((string) ($contact['retail_license_number'] ?? '')),
            'position' => trim((string) ($contact['position'] ?? '')),
            'referral' => trim((string) ($contact['referral'] ?? '')),
            'current_suppliers' => trim((string) ($contact['current_suppliers'] ?? '')),
            'contact_preference' => trim((string) ($contact['contact_preference'] ?? '')),
            'agreement' => $request->boolean('contact.agreement'),
        ]);

        try {
            $shopifyWholesaleApplicationCustomerService->syncByEmail((string) $requestRecord->email, [
                'name' => (string) ($requestRecord->name ?: ($contact['name'] ?? '')),
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            Notification::route(
                'mail',
                (string) config('product_surfaces.access_request.review_email', 'modernforestryteam@gmail.com')
            )->notify(new WholesaleApplicationReviewNotification($requestRecord));
        } catch (Throwable $e) {
            report($e);
        }

        return response()->noContent();
    }
}
