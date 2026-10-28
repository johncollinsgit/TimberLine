<?php

namespace App\Services\Wholesale\Labels;

use App\Models\ShopifyStore;
use App\Models\TenantWholesaleSetting;
use App\Models\User;
use App\Services\Shopify\ShopifyEmbeddedAppCredentials;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WholesaleLabelContext
{
    public function storefront(Request $request): array
    {
        abort_unless(config('wholesale_labels.enabled'), 503, 'Custom label uploads are temporarily unavailable.');
        $shop = strtolower((string) $request->query('shop'));
        abort_unless($shop === config('wholesale_labels.shop'), 403);
        $store = ShopifyStores::findByShopDomain($shop);
        abort_unless($store && $store['key'] === 'wholesale', 403);
        $params = $request->query();
        $signature = strtolower((string) ($params['signature'] ?? ''));
        unset($params['signature']);
        ksort($params);
        $canonical = '';
        foreach ($params as $key => $value) {
            $canonical .= $key.'='.(is_array($value) ? implode(',', $value) : $value);
        }
        $valid = false;
        foreach (app(ShopifyEmbeddedAppCredentials::class)->credentialsForStore($store) as $credential) {
            $valid = $valid || hash_equals(hash_hmac('sha256', $canonical, $credential['secret']), $signature);
        }
        $timestamp = (string) $request->query('timestamp');
        abort_unless($valid && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401);
        $customer = (string) $request->query('logged_in_customer_id');
        abort_unless(ctype_digit($customer) && (int) $customer > 0, 401, 'Sign in with your wholesale account.');
        $record = ShopifyStore::query()->where('shop_domain', $shop)->where('store_role', 'wholesale')->firstOrFail();
        abort_unless(TenantWholesaleSetting::query()->where('tenant_id', $record->tenant_id)->where('shopify_store_id', $record->id)->whereNotNull('confirmed_at')->exists(), 403);
        $origin = $request->header('Origin');
        abort_if($origin && ! in_array(strtolower((string) parse_url($origin, PHP_URL_HOST)), ['modernforestrywholesale.com', $shop], true), 403);
        $approved = Cache::remember('wl-customer:'.hash('sha256', $shop.'|'.$customer), 120, function () use ($store, $customer): bool {
            $result = (new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']))->query(
                'query LabelCustomer($id: ID!) { customer(id: $id) { tags } }',
                ['id' => 'gid://shopify/Customer/'.$customer]
            );

            return in_array('wholesale', array_map('strtolower', $result['customer']['tags'] ?? []), true);
        });
        abort_unless($approved, 403, 'This account needs wholesale approval.');

        return ['tenant_id' => (int) $record->tenant_id, 'shop_domain' => $shop, 'customer_id' => $customer];
    }

    public function canManage(?User $user, int $tenant): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }
        $membership = $user->tenants()->whereKey($tenant)->wherePivot('membership_active', true)->first();

        return $membership && (in_array($membership->pivot->role, ['owner', 'admin', 'wholesale_label_manager'], true)
            || $user->role === 'admin');
    }
}
