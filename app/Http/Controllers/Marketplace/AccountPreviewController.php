<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceAccountPreview;
use Illuminate\Http\Request;

class AccountPreviewController extends Controller
{
    public function __invoke(Request $request, MarketplaceAccountPreview $preview)
    {
        abort_unless(strtolower($request->getHost()) === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)), 404);
        abort_unless($preview->allows($request->user()), 404);
        // This personal preview never selects a tenant, store or any operational record.
        abort_if($request->hasAny(['tenant', 'tenant_id', 'store', 'store_id']), 404);
        $tabs = [
            'overview' => 'Overview', 'makers' => 'Makers', 'listings' => 'Products',
            'orders' => 'Sales', 'customers' => 'Customers', 'discounts' => 'Discounts',
            'markets' => 'Markets & shows', 'pickups' => 'Pickups', 'rates' => 'Maker terms',
            'statements' => 'Statements', 'payments' => 'Payments',
            'connections' => 'Connections', 'activity' => 'Activity',
        ];
        $tab = $request->query('tab', 'overview');
        abort_unless(is_string($tab) && array_key_exists($tab, $tabs), 404);

        // UI state is scalar and bounded, and never selects an operational record.
        foreach (['q' => 160, 'status' => 40, 'detail' => 80] as $key => $max) {
            $value = $request->query($key, '');
            abort_unless(is_string($value) && mb_strlen($value) <= $max, 404);
        }

        return response()->view('marketplace.account-preview', ['tabs' => $tabs, 'tab' => $tab, 'examples' => $preview->examples()])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'same-origin');
    }
}
