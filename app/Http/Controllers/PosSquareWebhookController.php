<?php

namespace App\Http\Controllers;

use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use Illuminate\Http\Request;

final class PosSquareWebhookController extends Controller
{
    public function __invoke(Request $request, PosStore $store, PosService $service)
    {
        $raw = $request->getContent();
        $key = config('pos_live.square_webhook_signature_key');
        $url = config('pos_live.square_webhook_url');
        abort_unless(is_string($key) && $key !== '' && is_string($url) && str_starts_with($url, 'https://') && strlen($raw) <= 262144, 403);
        $expected = base64_encode(hash_hmac('sha256', $url.$raw, $key, true));
        abort_unless(hash_equals($expected, (string) $request->header('x-square-hmacsha256-signature')), 403);
        $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        abort_unless(is_string($event['event_id'] ?? null) && is_string($event['merchant_id'] ?? null), 422);
        $payment = data_get($event, 'data.object.payment');
        $refund = data_get($event, 'data.object.refund');
        if (is_array($payment) && is_string($payment['reference_id'] ?? null)) {
            $sales = $store->db->table('pos_live_sales')->where('runtime_id', $store->runtime())->where('payment_request_id', $payment['reference_id'])->limit(2)->get();
        } elseif (is_array($refund) && is_string($refund['payment_id'] ?? null)) {
            $sales = $store->db->table('pos_live_sales as s')->join('payment_core_operations as p', 'p.id', '=', 's.attempt_id')
                ->where('s.runtime_id', $store->runtime())->where('p.provider_id', $refund['payment_id'])->select('s.*')->limit(2)->get();
        } else {
            return response()->json(['accepted' => true]);
        }
        foreach ($sales as $sale) {
            $snapshot = $service->snapshot($sale);
            if ($snapshot['binding']['merchant_account'] !== $event['merchant_id']) {
                continue;
            }
            $effect = 'reconcile_'.substr(hash('sha256', $event['event_id']), 0, 64);
            $service->enqueue((int) $sale->tenant_id, $sale->id, $effect, []);
        }

        return response()->json(['accepted' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
