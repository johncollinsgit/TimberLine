<?php

namespace App\Services\Onboarding;

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;

class WholesaleApplicationReviewInboxResolver
{
    public function resolve(CustomerAccessRequest $request): string
    {
        $tenant = $this->resolveTenant($request);

        $tenantMetadataEmail = $this->normalizeEmail(
            data_get($tenant?->accessProfile?->metadata, 'admin.review_email')
        );
        if ($tenantMetadataEmail !== null) {
            return $tenantMetadataEmail;
        }

        $slug = strtolower(trim((string) ($tenant?->slug ?: $request->requested_tenant_slug ?: '')));
        if ($slug !== '') {
            $mapped = $this->normalizeEmail(
                data_get(config('product_surfaces.access_request.review_email_by_tenant_slug', []), $slug)
            );
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $this->fallback();
    }

    protected function resolveTenant(CustomerAccessRequest $request): ?Tenant
    {
        if ($request->relationLoaded('tenant') && $request->tenant instanceof Tenant) {
            $request->tenant->loadMissing('accessProfile');

            return $request->tenant;
        }

        if (is_numeric($request->tenant_id) && (int) $request->tenant_id > 0) {
            return Tenant::query()
                ->with('accessProfile')
                ->find((int) $request->tenant_id);
        }

        $slug = strtolower(trim((string) ($request->requested_tenant_slug ?? '')));
        if ($slug === '') {
            return null;
        }

        return Tenant::query()
            ->with('accessProfile')
            ->where('slug', $slug)
            ->first();
    }

    protected function fallback(): string
    {
        return $this->normalizeEmail(config('product_surfaces.access_request.review_email'))
            ?? 'modernforestryteam@gmail.com';
    }

    protected function normalizeEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
