<?php

namespace App\Services\Pos;

use App\Jobs\Pos\RefreshSquareEventSource;
use App\Services\Marketing\SquareSourceRefresh;

/** Called only after the existing Square HMAC verification; never attributes a sale to a guessed event. */
final class PosSquareSourceIngress
{
    public function accept(array $event): void
    {
        if (config('pos_live.external_sales_ingest_enabled') !== true) {
            return;
        }
        $type = $event['type'] ?? null;
        [$kind, $object] = match ($type) {
            'payment.created', 'payment.updated' => ['payment', data_get($event, 'data.object.payment')],
            'refund.created', 'refund.updated' => ['refund', data_get($event, 'data.object.refund')],
            'order.created' => ['order', data_get($event, 'data.object.order_created')],
            'order.updated' => ['order', data_get($event, 'data.object.order_updated')],
            'order.fulfillment.updated' => ['order', data_get($event, 'data.object.order_fulfillment_updated')],
            'customer.created', 'customer.updated' => ['customer', data_get($event, 'data.object.customer')],
            default => [null, null],
        };
        if (! is_array($object) || ! $kind) {
            return;
        }
        $id = $kind === 'refund' ? ($object['payment_id'] ?? null) : ($object[$kind === 'order' ? 'order_id' : 'id'] ?? null);
        $merchant = $event['merchant_id'] ?? null;
        $eventId = $event['event_id'] ?? null;
        $location = $object['location_id'] ?? null;
        foreach ([$id, $merchant, $eventId] as $value) {
            if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]{1,192}$/D', $value)) {
                return;
            }
        }
        if ($location !== null && (! is_string($location) || ! preg_match('/^[A-Za-z0-9_-]{1,192}$/D', $location))) {
            return;
        }
        $environment = (string) config('pos_live.square_environment');
        if (! in_array($environment, ['production', 'sandbox'], true)) {
            return;
        }
        foreach (app(SquareSourceRefresh::class)->webhookTargets($merchant, $environment, $location) as $target) {
            RefreshSquareEventSource::dispatch($target['tenant_id'], $target['binding'], $kind === 'refund' ? 'payment' : $kind,
                $id, $eventId, app(PosStore::class)->runtime());
        }
    }
}
