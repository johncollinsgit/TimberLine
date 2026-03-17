<?php

namespace App\Services\Shopify\Dashboard;

class ShopifyEmbeddedDashboardProfitEstimator
{
    /**
     * These default rates keep the model predictable until a real cost-of-goods feed exists.
     *
     * @param  array<string,float|int|null>  $inputs
     * @return array<string,mixed>
     */
    public function estimate(array $inputs): array
    {
        $revenue = round((float) ($inputs['revenue'] ?? 0), 2);
        $discounts = round((float) ($inputs['discounts'] ?? 0), 2);
        $refunds = round((float) ($inputs['refunds'] ?? 0), 2);
        $shippingCost = round((float) ($inputs['shipping_cost'] ?? ($revenue * 0.06)), 2);
        $packagingCost = round((float) ($inputs['packaging_cost'] ?? ($revenue * 0.02)), 2);
        $otherCost = round((float) ($inputs['other_cost'] ?? ($revenue * 0.01)), 2);
        $productCost = round((float) ($inputs['product_cost'] ?? ($revenue * 0.42)), 2);

        $net = round(
            $revenue - $discounts - $refunds - $shippingCost - $packagingCost - $otherCost - $productCost,
            2
        );

        return [
            'revenue' => $revenue,
            'discounts' => $discounts,
            'refunds' => $refunds,
            'productCost' => $productCost,
            'shippingCost' => $shippingCost,
            'packagingCost' => $packagingCost,
            'otherCost' => $otherCost,
            'netProfit' => $net,
            'assumptions' => [
                'product_cost_rate' => array_key_exists('product_cost', $inputs) ? null : 0.42,
                'shipping_cost_rate' => array_key_exists('shipping_cost', $inputs) ? null : 0.06,
                'packaging_cost_rate' => array_key_exists('packaging_cost', $inputs) ? null : 0.02,
                'other_cost_rate' => array_key_exists('other_cost', $inputs) ? null : 0.01,
            ],
        ];
    }
}
