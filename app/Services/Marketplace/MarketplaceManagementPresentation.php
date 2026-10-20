<?php

namespace App\Services\Marketplace;

/**
 * Presentation only. Never resolve identities, tenants, stores or entitlements here.
 */
class MarketplaceManagementPresentation
{
    public static function groups(string $role = 'operator'): array
    {
        if ($role === 'maker') {
            return [
                'Your business' => ['products' => 'Products', 'orders' => 'Orders & fulfillment', 'earnings' => 'Earnings & statements'],
                'Sell together' => ['markets' => 'Markets & shows', 'agreements' => 'Accepted terms'],
                'Manage' => ['business-details' => 'Business details', 'storefronts' => 'Shared storefronts', 'connection' => 'Everbranch connection'],
            ];
        }

        return [
            'Workspace' => ['overview' => 'Overview', 'makers' => 'Makers', 'listings' => 'Products'],
            'Selling' => ['orders' => 'Sales & orders', 'customers' => 'Customers', 'discounts' => 'Discounts'],
            'Markets' => ['markets' => 'Markets & shows', 'pickups' => 'Pickups'],
            'Finance' => ['rates' => 'Maker terms', 'statements' => 'Supplier statements', 'payments' => 'Payment setup'],
            'Manage' => ['connections' => 'Connections', 'activity' => 'Activity'],
        ];
    }
}
