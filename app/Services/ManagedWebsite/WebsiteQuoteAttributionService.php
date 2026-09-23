<?php

namespace App\Services\ManagedWebsite;

use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;

/**
 * Keeps connected-site quote requests in the Website Commerce lane. A quote is
 * linked only when both the normalized email and phone match a Website customer.
 */
class WebsiteQuoteAttributionService
{
    public function link(WebsiteCustomer $customer, ?WebsiteOrder $order = null): void
    {
        $email = strtolower(trim((string) $customer->email));
        $phone = $this->phone($customer->phone);
        if ($email === '' || $phone === '') {
            return;
        }

        FormSubmission::query()
            ->forTenantId((int) $customer->tenant_id)
            ->where('source', 'managed_website_quote')
            ->whereRaw('LOWER(submitter_email) = ?', [$email])
            ->when($order !== null, fn ($query) => $query->where('submitted_at', '<=', $order->created_at))
            ->get()
            ->filter(fn (FormSubmission $submission): bool => hash_equals($phone, $this->phone($submission->submitter_phone)))
            ->each(function (FormSubmission $submission) use ($customer, $order): void {
                $metadata = (array) ($submission->metadata ?? []);
                $current = (array) data_get($metadata, 'quote_attribution', []);
                $metadata['quote_attribution'] = [
                    'website_customer_id' => $customer->id,
                    'website_order_id' => $order?->id ?? data_get($current, 'website_order_id'),
                    'matched_at' => now()->toIso8601String(),
                    'match' => 'exact_email_and_phone',
                ];
                $submission->forceFill(['metadata' => $metadata])->save();
            });
    }

    public function synchronize(Tenant $tenant): void
    {
        FormSubmission::query()
            ->forTenant($tenant)
            ->where('source', 'managed_website_quote')
            ->orderBy('id')
            ->each(function (FormSubmission $submission) use ($tenant): void {
                $email = strtolower(trim((string) $submission->submitter_email));
                $phone = $this->phone($submission->submitter_phone);
                if ($email === '' || $phone === '') {
                    return;
                }

                $customer = WebsiteCustomer::query()
                    ->forTenant($tenant)
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->get()
                    ->first(fn (WebsiteCustomer $candidate): bool => hash_equals($phone, $this->phone($candidate->phone)));
                if (! $customer) {
                    return;
                }

                $order = WebsiteOrder::query()
                    ->forTenant($tenant)
                    ->where('website_customer_id', $customer->id)
                    ->where('created_at', '>=', $submission->submitted_at)
                    ->oldest('created_at')
                    ->first();
                $this->link($customer, $order);
            });
    }

    private function phone(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?: '';
    }
}
