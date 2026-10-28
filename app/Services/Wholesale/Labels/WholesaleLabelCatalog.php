<?php

namespace App\Services\Wholesale\Labels;

use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Support\Facades\Cache;

class WholesaleLabelCatalog
{
    public function verifyCandleGroups(array $owner, array $groups): void
    {
        $ids = array_values(array_unique(array_map(fn ($group) => 'gid://shopify/ProductVariant/'.$group['variant_id'], $groups)));
        sort($ids);
        $variants = Cache::remember('wl-candle-groups:'.hash('sha256', $owner['shop_domain'].'|'.implode('|', $ids)), 60, function () use ($ids, $owner) {
            $store = ShopifyStores::findByShopDomain($owner['shop_domain']);
            abort_unless($store && $store['key'] === 'wholesale', 403);

            return (new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']))->query('query LabelCandleGroups($ids:[ID!]!){ nodes(ids:$ids){ ... on ProductVariant { id title product { title handle } } } }', ['ids' => $ids])['nodes'] ?? [];
        });
        $eligible = [];
        foreach ($variants as $variant) {
            if (! $variant || in_array($variant['product']['handle'] ?? '', ['custom-label-set-up', 'use-my-custom-label', 'barcodes'], true)) {
                continue;
            }
            $text = ($variant['title'] ?? '').' '.($variant['product']['title'] ?? '');
            if (! preg_match('/wax\s?melt|room\s?spray|flight|sample/i', $text) && preg_match('/candle|cotton wick|cedar wick|wood wick/i', $text)) {
                $eligible[] = $variant['id'];
            }
        }
        abort_unless(count(array_diff($ids, $eligible)) === 0, 422, 'Choose candle groups from this wholesale catalog.');
    }
}
