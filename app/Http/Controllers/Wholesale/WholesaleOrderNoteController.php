<?php

namespace App\Http\Controllers\Wholesale;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\TenantWholesaleSetting;
use App\Services\Shopify\ShopifyEmbeddedAppCredentials;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Shopify\App\ShopifyApp;

class WholesaleOrderNoteController extends Controller
{
    public function handle(Request $request)
    {
        $store = ShopifyStores::find('wholesale');
        abort_unless($store, 503);
        $raw = ['method' => $request->method(), 'headers' => array_map(fn ($values) => implode(', ', $values), $request->headers->all()), 'url' => $request->fullUrl(), 'body' => $request->getContent()];
        $result = null;
        foreach (app(ShopifyEmbeddedAppCredentials::class)->credentialsForStore($store) as $credential) {
            $result = (new ShopifyApp($credential['client_id'], $credential['secret']))->verifyCheckoutUIExtReq($raw);
            if ($result->ok) {
                break;
            }
        }
        $headers = ['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Methods' => 'POST, OPTIONS', 'Access-Control-Allow-Headers' => 'Authorization, Content-Type', 'Cache-Control' => 'private, no-store'];
        if (! $result || ! $result->ok || $request->isMethod('OPTIONS')) {
            return response($result?->response->body ?? '', $result?->response->status ?? 401, array_merge((array) ($result?->response->headers ?? []), $headers));
        }
        abort_unless(config('wholesale_labels.order_notes_enabled'), 503, 'Order requests are temporarily unavailable.');
        abort_unless($result->shop.'.myshopify.com' === config('wholesale_labels.shop'), 403);
        $claims = $result->idToken->claims;
        abort_unless(preg_match('~^gid://shopify/Customer/([0-9]+)$~', (string) ($claims['sub'] ?? ''), $match), 401, 'Sign in to leave a request for this order.');
        abort_unless(isset($claims['exp'], $claims['nbf']) && $claims['exp'] > time() && $claims['exp'] <= time() + 360, 401);
        $customer = $match[1];
        $mapping = ShopifyStore::query()->where('shop_domain', config('wholesale_labels.shop'))->where('store_role', 'wholesale')->firstOrFail();
        abort_unless(TenantWholesaleSetting::query()->where('tenant_id', $mapping->tenant_id)->where('shopify_store_id', $mapping->id)->whereNotNull('confirmed_at')->exists(), 403);
        $data = $request->validate(['order_id' => ['required', 'regex:~^gid://shopify/Order/[0-9]+$~'], 'note' => ['required', 'string', 'max:2000']]);
        $note = trim($data['note']);
        abort_if($note === '', 422, 'Enter your request before saving.');

        return Cache::lock('wholesale-order-note:'.hash('sha256', $result->shop.'|'.$data['order_id']), 40)->block(5, function () use ($store, $data, $note, $customer, $headers, $mapping) {
            $api = new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']);
            $response = $api->query('query WholesaleOrderNote($id:ID!){ order(id:$id){ id note customer{id} cancelledAt createdAt buyerNote:metafield(namespace:"forestry",key:"wholesale_order_note"){id value} } }', ['id' => $data['order_id']]);
            $order = $response['order'] ?? null;
            if (! $order) {
                return response()->json(['message' => 'Your order is still being created. Please try again in a moment.'], 409, $headers + ['Retry-After' => '3']);
            }
            abort_unless(($order['customer']['id'] ?? '') === 'gid://shopify/Customer/'.$customer, 404);
            abort_if($order['cancelledAt'] || \Illuminate\Support\Carbon::parse($order['createdAt'])->lt(now()->subDays(30)), 422, 'Please contact us directly about this order.');
            $previous = $order['buyerNote']['value'] ?? '';
            if ($previous !== $note) {
                $existing = trim((string) $order['note']);
                $oldBlock = $previous !== '' ? "[Wholesale order request]\n".$previous : '';
                if ($oldBlock !== '' && str_ends_with($existing, $oldBlock)) {
                    $existing = trim(substr($existing, 0, -strlen($oldBlock)));
                }
                $combined = ($existing !== '' ? $existing."\n\n" : '')."[Wholesale order request]\n".$note;
                $saved = $api->query('mutation SaveWholesaleOrderNote($input:OrderInput!){orderUpdate(input:$input){order{id} userErrors{field message}}}', ['input' => ['id' => $data['order_id'], 'note' => $combined, 'metafields' => [['namespace' => 'forestry', 'key' => 'wholesale_order_note', 'type' => 'multi_line_text_field', 'value' => $note] + (! empty($order['buyerNote']['id']) ? ['id' => $order['buyerNote']['id']] : [])]]]);
                abort_if(! empty($saved['orderUpdate']['userErrors']) || empty($saved['orderUpdate']['order']['id']), 502, 'Your request could not be saved. Please try again.');
            }
            $local = Order::query()->where('tenant_id', $mapping->tenant_id)->where('shopify_store_key', 'wholesale')->where('shopify_order_id', basename($data['order_id']))->first();
            if ($local) {
                $meta = $local->attribution_meta ?? [];
                $meta['buyer_order_request'] = $note;
                $local->forceFill(['attribution_meta' => $meta])->saveQuietly();
            }

            return response()->json(['ok' => true], 200, $headers);
        });
    }
}
