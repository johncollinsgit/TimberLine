<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantDirectInvoice;

class TenantInvoiceBillingProfileService
{
    /** @return array<string,mixed> */
    public function defaultsFor(Tenant $tenant): array
    {
        $tenant->loadMissing('accessProfile');
        $metadata = (array) ($tenant->accessProfile?->metadata ?? []);
        $saved = (array) data_get($metadata, 'billing_contact', []);
        $lastInvoice = $tenant->directInvoices()->latest('id')->first();
        $owner = $tenant->users()->wherePivot('role', 'owner')->orderBy('users.id')->first();

        $address = (array) ($saved['billing_address'] ?? $lastInvoice?->billing_address ?? []);
        $address['country'] = strtoupper((string) ($address['country'] ?? 'US'));

        return [
            'customer_name' => (string) ($saved['customer_name'] ?? $lastInvoice?->customer_name ?? $tenant->name),
            'customer_email' => (string) ($saved['customer_email'] ?? $lastInvoice?->customer_email ?? data_get($metadata, 'admin.primary_contact_email') ?? $owner?->email ?? ''),
            'customer_phone' => (string) ($saved['customer_phone'] ?? $lastInvoice?->customer_phone ?? ''),
            'billing_address' => $address,
            'days_until_due' => (int) ($saved['days_until_due'] ?? $lastInvoice?->days_until_due ?? 30),
            'has_saved_profile' => $this->hasCompleteAddress($address)
                && filled($saved['customer_email'] ?? null),
        ];
    }

    public function remember(Tenant $tenant, TenantDirectInvoice $invoice): void
    {
        $profile = $tenant->accessProfile;
        if ($profile === null) {
            return;
        }

        $metadata = (array) ($profile->metadata ?? []);
        $metadata['billing_contact'] = [
            'customer_name' => $invoice->customer_name,
            'customer_email' => $invoice->customer_email,
            'customer_phone' => $invoice->customer_phone,
            'billing_address' => $invoice->billing_address,
            'days_until_due' => $invoice->days_until_due,
            'updated_at' => now()->toIso8601String(),
        ];
        $profile->forceFill(['metadata' => $metadata])->save();
    }

    /** @param array<string,mixed> $address */
    protected function hasCompleteAddress(array $address): bool
    {
        foreach (['line1', 'city', 'state', 'postal_code', 'country'] as $field) {
            if (! filled($address[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
