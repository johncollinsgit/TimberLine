<?php

namespace App\Http\Controllers;

use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use Illuminate\Http\Request;

final class PosStripeWebhookController extends Controller
{
    public function __invoke(Request $r, PosStore $store, PosService $service)
    {
        $require = static function (bool $condition, int $status): void {
            if (! $condition) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => 'webhook_rejected'], $status, ['Cache-Control' => 'no-store']));
            }
        };
        $require(config('pos_live.enabled') && config('pos_live.servicing_enabled') && is_string(config('pos_live.stripe_webhook_secret')) && strlen($r->getContent()) <= 262144, 403);
        $signature = (string) $r->header('Stripe-Signature');
        preg_match_all('/(?:^|,)t=(\d{1,12})(?=,|$)/', $signature, $times);
        preg_match_all('/(?:^|,)v1=([a-f0-9]{64})(?=,|$)/', $signature, $signatures);
        $require(count($times[1]) === 1 && abs(time() - (int) $times[1][0]) <= 300 && str_starts_with(config('pos_live.stripe_webhook_secret'), 'whsec_'), 403);
        $expected = hash_hmac('sha256', $times[1][0].'.'.$r->getContent(), config('pos_live.stripe_webhook_secret'));
        $require(collect($signatures[1])->contains(fn ($s) => hash_equals($expected, $s)), 403);
        try {
            $event = json_decode($r->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $require(false, 422);
        }
        $require(is_array($event) && is_string($event['id'] ?? null) && strlen($event['id']) <= 255, 422);
        $object = $event['data']['object'] ?? [];
        $id = $object['payment_intent'] ?? $object['action']['process_payment_intent']['payment_intent'] ?? $object['id'] ?? null;
        if (! is_string($id) || ! str_starts_with($id, 'pi_') || ! is_string($event['account'] ?? null) || ! is_bool($event['livemode'] ?? null)) {
            return response()->json(['accepted' => true]);
        }
        $query = $store->db->table('pos_live_stripe_operations')->where('runtime_id', $store->runtime());
        if (($object['object'] ?? '') === 'payment_intent' && is_string($object['metadata']['pos_request'] ?? null)) {
            $query->where('request_id', $object['metadata']['pos_request']);
        } else {
            $query->where('payment_id', $id);
        }
        foreach ($query->limit(2)->get() as $intent) {
            $sale = $store->rows('sales', (int) $intent->tenant_id)->where('id', $intent->id)->first();
            if (! $sale) {
                continue;
            } $snapshot = $service->snapshot($sale);
            $binding = $snapshot['binding'];
            if ($binding['provider'] !== 'stripe_terminal' || $binding['merchant_account'] !== $event['account'] || ($binding['environment'] === 'live') !== $event['livemode'] || ($intent->payment_id && $intent->payment_id !== $id)) {
                continue;
            }
            $service->enqueue((int) $sale->tenant_id, $sale->id, 'reconcile_'.hash('sha256', $event['id']), []);
        }

        return response()->json(['accepted' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
