<?php

namespace App\Services\Wholesale;

use App\Models\Tenant;
use App\Services\Tenancy\TenantBrandProfileService;

class WholesaleCustomerOutreachDraft
{
    public function __construct(private WholesaleOutreachDigest $digest, private WholesaleOutreachProductCatalog $catalog, private TenantBrandProfileService $brands) {}

    /** Recheck current eligibility so an old Monday link cannot revive snoozed advice. */
    public function forCustomer(Tenant $tenant, string $accountKey): array
    {
        $row = collect($this->digest->snapshot($tenant)['rows'])->firstWhere('account_key', $accountKey);
        if (! $row) {
            throw new \DomainException('This account is no longer on the current outreach list. Check its latest order and follow-up status before contacting it.');
        }
        $brand = $this->brands->previewPresentationFor($tenant);
        $evidence = collect($row['product_evidence']);
        $ids = $evidence->pluck('product_id')->filter()->unique()->take(4);
        $catalogIssue = false;
        try {
            $catalog = $this->catalog->products((int) $tenant->id, $ids->all());
        } catch (\Throwable $error) {
            report($error);
            $catalogIssue = true;
            $catalog = ['products' => [], 'storefront_url' => $tenant->slug === 'modern-forestry' ? 'https://modernforestrywholesale.com/' : null];
        }
        $products = $ids->map(function ($id) use ($catalog, $evidence): ?array {
            $product = $catalog['products'][(string) $id] ?? null;
            if (! $product) {
                return null;
            }
            $variants = $evidence->where('product_id', $id)->map(fn ($item) => $item['quantity'].' × '.($item['variant'] ?: $item['title']));

            return [...$product, 'title' => preg_replace('/^Wholesale\s+/i', '', $product['title']), 'previous_order' => $variants->take(3)->join(' · ').($variants->count() > 3 ? ' · and other variants' : '')];
        })->filter()->values()->all();
        $isForestry = $tenant->slug === 'modern-forestry';
        $brand['display_name'] = $isForestry ? 'Modern Forestry' : $brand['display_name'];
        $brand['logo_url'] = $isForestry ? 'https://modernforestrywholesale.com/cdn/shop/t/4/assets/modern-forestry-header-logo.png?v=151527550753401185811784061842' : null;
        $brand['primary_color'] = $isForestry ? '#294333' : $brand['primary_color'];
        $brand['accent_color'] = $isForestry ? '#9B6B45' : $brand['accent_color'];
        $firstName = explode(' ', trim($row['buyer']))[0] ?: 'there';
        $message = 'I wanted to check in with you at '.$row['company'].'. How has your last selection been selling?';
        $message .= "\n\nI’ve pulled together a few things from your most recent order below. If you’re thinking about a restock, I’d be happy to help you put together the right mix for your shelves.";

        return [
            'recipient' => $row['email'], 'subject' => $row['draft_subject'],
            'greeting' => 'Hi '.$firstName.',', 'message' => $message,
            'order_number' => $row['order_number'], 'order_date' => $row['order_date'],
            'items' => $row['items'], 'products' => $products, 'brand' => $brand,
            'storefront_url' => $catalog['storefront_url'], 'catalog_issue' => $catalogIssue,
            'contact_email' => $isForestry ? 'info@theforestrystudio.com' : null,
        ];
    }
}
