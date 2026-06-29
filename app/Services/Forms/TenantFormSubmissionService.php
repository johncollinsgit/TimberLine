<?php

namespace App\Services\Forms;

use App\Models\CustomerAccessRequest;
use App\Models\FormSubmission;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class TenantFormSubmissionService
{
    public function __construct(
        protected TenantFormProvisioningService $provisioningService
    ) {
    }

    public function recordWholesaleApplicationFromAccessRequest(CustomerAccessRequest $request): ?FormSubmission
    {
        $tenant = $this->resolveTenant($request);
        if (! $tenant instanceof Tenant) {
            return null;
        }

        return DB::transaction(function () use ($tenant, $request): FormSubmission {
            $form = $this->provisioningService->ensureWholesaleApplicationFormForTenant($tenant);
            $metadata = is_array($request->metadata ?? null) ? $request->metadata : [];
            $payload = [
                'name' => (string) ($request->name ?? ''),
                'email' => (string) ($request->email ?? ''),
                'company' => (string) ($request->company ?? ''),
                'message' => (string) ($request->message ?? ''),
                'business_type' => (string) ($metadata['business_type'] ?? ''),
                'website' => (string) ($metadata['website'] ?? ''),
                'phone' => (string) ($metadata['phone'] ?? ''),
                'city' => (string) ($metadata['city'] ?? ''),
                'state' => (string) ($metadata['state'] ?? ''),
                'zip' => (string) ($metadata['zip'] ?? ''),
                'country' => (string) ($metadata['country'] ?? ''),
                'address' => (string) ($metadata['address'] ?? ''),
                'address2' => (string) ($metadata['address2'] ?? ''),
                'retail_license_number' => (string) ($metadata['retail_license_number'] ?? ''),
                'position' => (string) ($metadata['position'] ?? ''),
                'referral' => (string) ($metadata['referral'] ?? ''),
                'current_suppliers' => (string) ($metadata['current_suppliers'] ?? ''),
                'contact_preference' => (string) ($metadata['contact_preference'] ?? ''),
                'agreement' => (bool) ($metadata['agreement'] ?? false),
            ];

            return FormSubmission::query()->updateOrCreate(
                [
                    'customer_access_request_id' => (int) $request->id,
                ],
                [
                    'tenant_id' => (int) $tenant->id,
                    'tenant_form_id' => (int) $form->id,
                    'user_id' => $request->user_id ? (int) $request->user_id : null,
                    'status' => 'submitted',
                    'source' => 'wholesale_storefront',
                    'source_key' => 'customer_access_request:' . (int) $request->id,
                    'submitted_at' => $request->created_at ?? now(),
                    'submitter_name' => $request->name,
                    'submitter_email' => $request->email,
                    'submitter_phone' => (string) ($metadata['phone'] ?? '') ?: null,
                    'submitter_company' => $request->company,
                    'payload' => $payload,
                    'normalized_payload' => $payload,
                    'metadata' => [
                        'requested_tenant_slug' => (string) ($request->requested_tenant_slug ?? ''),
                        'intent' => (string) ($request->intent ?? 'production'),
                        'customer_access_request_status' => (string) ($request->status ?? 'pending'),
                    ],
                ]
            );
        });
    }

    protected function resolveTenant(CustomerAccessRequest $request): ?Tenant
    {
        if ($request->relationLoaded('tenant') && $request->tenant instanceof Tenant) {
            return $request->tenant;
        }

        if (is_numeric($request->tenant_id) && (int) $request->tenant_id > 0) {
            return Tenant::query()->find((int) $request->tenant_id);
        }

        $slug = strtolower(trim((string) ($request->requested_tenant_slug ?? '')));
        if ($slug === '') {
            return null;
        }

        return Tenant::query()->where('slug', $slug)->first();
    }
}
