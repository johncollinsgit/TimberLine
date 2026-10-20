<?php

namespace App\Services\Marketplace;

use App\Models\User;

/** Account-only example-data access; never an authorization for a merchant workspace. */
class MarketplaceAccountPreview
{
    public function allows(?User $user): bool
    {
        return $user !== null
            && $user->is_active
            && $user->email_verified_at !== null
            && in_array((int) $user->getAuthIdentifier(), (array) config('marketplace.account_preview_user_ids', []), true);
    }

    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').route('marketplace.preview', [], false);
    }

    /** Fictional in-memory scenario. No operational model, provider or tenant reads. */
    public function examples(): array
    {
        $row = fn (string $id, string $title, string $subtitle, string $status, string $amount, array $details, array $links = [], ?string $image = null): array => compact('id', 'title', 'subtitle', 'status', 'amount', 'details', 'links', 'image');

        return [
            'makers' => [
                $row('maker-cedar', 'Cedar & Clay Studio', 'Ceramics · Greenville, SC · 2 example products', 'Approved', '', ['Business model' => 'Independent handmade goods · online and markets', 'Products' => 'Everyday bowl approved; speckled mug awaiting review', 'Terms' => 'Example accepted 15% merchandise share; existing real agreements stay authoritative', 'Market' => 'Neighborhood Makers Market · approved · booth A1'], ['View products' => 'listings&q=Cedar', 'View market lineup' => 'markets&detail=market-neighborhood']),
                $row('maker-willow', 'Willow Workshop', 'Woodwork & textiles · Travelers Rest, SC · 2 example products', 'Approved', '', ['Business model' => 'Mixed maker goods and local services', 'Products' => 'Maple serving board; canvas market tote', 'Market' => 'Neighborhood Makers Market · approved · booth A2', 'Supplier statement' => '$40.80 example tote earnings in review; no payment recorded'], ['View products' => 'listings&q=Willow', 'View statement' => 'statements&detail=statement-willow']),
                $row('maker-juniper', 'Juniper Paper Co.', 'Paper goods · Greer, SC · application to review', 'Pending', '', ['Application' => 'Fictional application; no real identity or message', 'Next operator step' => 'Review business details and maker eligibility in the real authorized console'], []),
            ],
            'listings' => [
                $row('product-bowl', 'Everyday bowl', 'Cedar & Clay Studio · Ceramics · 12 units available', 'Published', '$36.00', ['Price' => '$36.00 each', 'Allocated stock' => '12 example units · revision 2 published', 'Example order' => 'EX-101 · 1 bowl · ready for pickup', 'Maker share' => '$30.60 per unit at the example 15% terms'], ['View maker' => 'makers&detail=maker-cedar', 'View order' => 'orders&detail=order-101'], 'studio.jpg'),
                $row('product-board', 'Maple serving board', 'Willow Workshop · Woodwork · 8 units available', 'Published', '$58.00', ['Price' => '$58.00 each', 'Allocated stock' => '8 example units · revision 1 published', 'Example order' => 'EX-102 · 1 board · preparing shipment', 'Maker share' => '$49.30 per unit; shipping credit reviewed separately'], ['View maker' => 'makers&detail=maker-willow', 'View order' => 'orders&detail=order-102'], 'retail.jpg'),
                $row('product-tote', 'Canvas market tote', 'Willow Workshop · Textiles · 20 units available', 'Published', '$24.00', ['Price' => '$24.00 each', 'Example order' => 'EX-103 · 2 totes · collected', 'Example statement' => '2 × $20.40 = $40.80, in review; no bank payment'], ['View order' => 'orders&detail=order-103', 'View statement' => 'statements&detail=statement-willow']),
                $row('product-mug', 'Speckled morning mug', 'Cedar & Clay Studio · Ceramics · awaiting approval', 'Pending', '$28.00', ['Price' => '$28.00 proposed', 'Publication' => 'Not published · revision 1', 'Next operator step' => 'Review product and accepted terms before approval; no stock committed by this preview'], ['View maker' => 'makers&detail=maker-cedar']),
            ],
            'orders' => [
                $row('order-101', 'EX-101 · Example buyer A', 'Cedar & Clay · 1 everyday bowl · market pickup', 'Ready', '$38.16', ['Merchandise' => '$36.00 · no discount', 'Shipping / illustrative tax' => '$0.00 / $2.16', 'Fulfillment' => 'Ready at Neighborhood Makers Market · booth A1', 'Collection' => 'A real maker must verify the buyer code; no preview code or payment evidence'], ['View bowl' => 'listings&detail=product-bowl', 'View pickup' => 'pickups&detail=pickup-101']),
                $row('order-102', 'EX-102 · Example buyer B', 'Willow Workshop · 1 serving board · shipping', 'Preparing', '$67.84', ['Merchandise' => '$58.00 · no discount', 'Shipping / illustrative tax' => '$6.00 / $3.84', 'Fulfillment' => 'Preparing shipment · shipping allocation still needs review', 'Payment status' => 'Fictional only; never provider evidence'], ['View board' => 'listings&detail=product-board']),
                $row('order-103', 'EX-103 · Example buyer C', 'Willow Workshop · 2 market totes · market pickup', 'Collected', '$50.88', ['Merchandise' => '2 × $24.00 = $48.00 · no discount', 'Shipping / illustrative tax' => '$0.00 / $2.88', 'Supplier earnings' => '2 × $20.40 = $40.80 · example statement review', 'Collection' => 'Illustrative completed state; no actual collection evidence'], ['View statement' => 'statements&detail=statement-willow']),
            ],
            'customers' => [
                $row('buyer-a', 'Example buyer A', '1 fictional order · market pickup', 'Example', '$38.16', ['Privacy' => 'No real buyer name, email, address or account', 'Order' => 'EX-101 · everyday bowl'], ['View order' => 'orders&detail=order-101']),
                $row('buyer-b', 'Example buyer B', '1 fictional order · shipping', 'Example', '$67.84', ['Privacy' => 'No real buyer or shipping data', 'Order' => 'EX-102 · maple serving board'], ['View order' => 'orders&detail=order-102']),
                $row('buyer-c', 'Example buyer C', '1 fictional order · collected', 'Example', '$50.88', ['Privacy' => 'No real buyer data', 'Order' => 'EX-103 · 2 totes'], ['View order' => 'orders&detail=order-103']),
            ],
            'markets' => [
                $row('market-neighborhood', 'Neighborhood Makers Market', 'SAT NOV 14 · 10 AM–2 PM Eastern · illustrative Greenville venue', 'Example', '2 makers', ['Date and venue' => 'Illustrative only; no real date, venue or booth is reserved', 'Approved lineup' => 'Cedar & Clay · booth A1; Willow Workshop · booth A2', 'Pickup window' => 'Example 10 AM–2 PM; real orders retain their original instructions', 'Next operator step' => 'Create/edit the event and approve participation in the authorized console'], ['View ready pickup' => 'pickups&detail=pickup-101', 'View maker lineup' => 'makers']),
            ],
            'pickups' => [
                $row('pickup-101', 'EX-101 · Everyday bowl', 'Neighborhood Makers Market · booth A1 · Cedar & Clay', 'Ready', '1 unit', ['Collection' => 'Ready · buyer code required in the real maker workspace', 'Location' => 'Illustrative Greenville venue · no real instructions', 'Review holds' => 'Instruction changes in a real paid order require review'], ['View order' => 'orders&detail=order-101', 'View market' => 'markets&detail=market-neighborhood']),
                $row('pickup-103', 'EX-103 · Canvas market tote', 'Neighborhood Makers Market · booth A2 · Willow Workshop', 'Collected', '2 units', ['Collection' => 'Illustrative completed state only', 'Supplier amount' => '$40.80 example earnings in statement review'], ['View order' => 'orders&detail=order-103']),
            ],
            'statements' => [
                $row('statement-willow', 'Willow Workshop · Example statement', 'EX-103 · 2 collected totes · review before approval', 'Review', '$40.80', ['Calculation' => '2 × $24.00 merchandise less 15% = $40.80', 'Payment recorded' => 'No · no bank reference or payment confirmation', 'Real process' => 'Review fulfilled/reconciled earnings → approve → make bank transfer separately → record reference', 'Excluded' => 'EX-102 board is still preparing; no automatic payout'], ['View source order' => 'orders&detail=order-103', 'View maker' => 'makers&detail=maker-willow']),
            ],
            'rates' => [
                $row('terms-default', 'Default maker agreement', 'Example merchandise share · no listing or monthly maker fee', 'Example', '15%', ['Example' => '$36.00 bowl → $30.60 maker / $5.40 marketplace', 'Authority' => 'Real accepted versions and product/maker overrides control real checkouts', 'Shipping' => 'Reviewed separately; standard processing is a marketplace expense', 'Platform offer' => 'Maker supplier terms are separate from an Everbranch software subscription'], ['View example bowl' => 'listings&detail=product-bowl']),
            ],
            'discounts' => [
                $row('discount-none', 'No discounts in this scenario', 'All three example orders use their listed merchandise prices', 'Example', '$0.00', ['Maker protection' => 'Marketplace-funded promotions preserve agreed maker earnings', 'Real setup' => 'Configure approved subsidy limits in Payment setup; preview cannot create a discount'], ['View orders' => 'orders']),
            ],
            'connections' => [
                $row('connection-cedar', 'Cedar & Clay · Standalone maker', 'Direct maker product entry · no staff workspace required', 'Example', '', ['Catalog' => 'Fictional native listings only', 'Subscription' => 'Standalone makers do not need a paid Everbranch subscription', 'External stores' => 'Sharing a public storefront link does not connect or sync its catalog'], ['View maker' => 'makers&detail=maker-cedar']),
                $row('connection-willow', 'Willow Workshop · Explicit source approval', 'Example Everbranch source relationship', 'Example', '', ['Real process' => 'An existing owner/admin explicitly authorizes products and dedicated stock allocation', 'Preview boundary' => 'No actual workspace, source catalog, provider or entitlement is read'], ['View maker' => 'makers&detail=maker-willow']),
            ],
            'activity' => [
                $row('activity-review', 'Statement waiting for review', 'Illustrative sequence · EX-103 collected → supplier review', 'Example', '', ['Source' => 'Static example data, not an audit event', 'Next step' => 'Review the $40.80 supplier statement; no bank payment recorded'], ['View statement' => 'statements&detail=statement-willow']),
                $row('activity-product', 'Product awaiting review', 'Illustrative sequence · Cedar & Clay submitted speckled mug', 'Example', '', ['Source' => 'Static example data; no webhook, notification or message sent'], ['View product' => 'listings&detail=product-mug']),
            ],
        ];
    }
}
