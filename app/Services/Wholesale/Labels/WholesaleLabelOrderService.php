<?php

namespace App\Services\Wholesale\Labels;

use App\Models\Order;
use App\Models\WholesaleLabelAssignment;
use App\Models\WholesaleLabelRequest;
use App\Models\WholesaleLabelRevision;
use Illuminate\Support\Facades\Schema;

class WholesaleLabelOrderService
{
    public static function property(array $line, string $name): ?string
    {
        foreach ($line['properties'] ?? [] as $key => $property) {
            if (is_array($property) && ($property['name'] ?? '') === $name) {
                return (string) ($property['value'] ?? '');
            }
            if ($key === $name && is_scalar($property)) {
                return (string) $property;
            }
        }

        return null;
    }

    public function capture(Order $order, array $payload): void
    {
        if (($order->shopify_store_key ?? $order->shopify_store) !== 'wholesale' || ! Schema::hasTable('wholesale_label_assignments')) {
            return;
        }
        $lines = $payload['line_items'] ?? [];
        $fees = array_values(array_filter($lines, fn ($line) => in_array((string) ($line['variant_id'] ?? ''), [config('wholesale_labels.setup_variant'), config('wholesale_labels.reuse_variant')], true)));
        $selected = array_values(array_filter($lines, fn ($line) => self::property($line, '_MF label request')));
        $selected = array_values(array_filter($selected, fn ($line) => ! in_array((string) ($line['variant_id'] ?? ''), [config('wholesale_labels.setup_variant'), config('wholesale_labels.reuse_variant')], true)));
        if (! $selected && ! collect($fees)->contains(fn ($line) => self::property($line, '_MF label request'))) {
            return;
        }
        $owner = ['tenant_id' => (int) $order->tenant_id, 'shop_domain' => config('wholesale_labels.shop'), 'customer_id' => (string) ($payload['customer']['id'] ?? '')];
        $library = app(WholesaleLabelLibrary::class);
        $receipt = count($fees) === 1 ? self::property($fees[0], '_MF label request') : null;
        $request = $receipt ? $library->owned(WholesaleLabelRequest::class, $owner)->whereKey($receipt)->lockForUpdate()->first() : null;
        $valid = $request && $selected && (int) ($fees[0]['quantity'] ?? 0) === 1
            && (string) $fees[0]['variant_id'] === config('wholesale_labels.'.($request->mode === 'new' ? 'setup' : 'reuse').'_variant')
            && self::property($fees[0], '_MF label mode') === $request->mode
            && self::property($fees[0], '_MF label revision') === ($request->mode === 'legacy' ? 'pending' : ($request->revision_id ?? 'pending'))
            && (! $request->shopify_order_id || $request->shopify_order_id === (string) $order->shopify_order_id);
        $revision = $valid && $request->revision_id ? $library->owned(WholesaleLabelRevision::class, $owner)->find($request->revision_id) : null;
        foreach ($selected as $line) {
            $matches = $valid && self::property($line, '_MF label request') === $request->id
                && self::property($line, '_MF label revision') === ($request->mode === 'legacy' ? 'pending' : ($request->revision_id ?? 'pending'))
                && self::property($line, '_MF label mode') === $request->mode
                && (int) $line['quantity'] >= 3 && (int) $line['quantity'] % 3 === 0
                && collect($request->groups)->contains(fn ($group) => (string) $group['variant_id'] === (string) ($line['variant_id'] ?? ''));
            $id = (string) ($line['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $assignment = $library->owned(WholesaleLabelAssignment::class, $owner)->firstOrNew(['shopify_order_id' => (string) $order->shopify_order_id, 'shopify_line_id' => $id]);
            if ($assignment->exists) {
                continue;
            }
            $assignment->fill($owner + ['order_id' => $order->id, 'request_id' => $matches ? $request->id : null,
                'revision_id' => $matches ? $revision?->id : null,
                'production_revision_id' => $matches && $revision?->status === 'approved' ? $revision->id : null,
                'status' => $matches && $revision?->status === 'approved' ? 'ready' : 'needs_review',
                'snapshot' => ['label' => self::property($line, 'Custom label'), 'variant_id' => (string) ($line['variant_id'] ?? ''), 'quantity' => (int) $line['quantity'], 'mode' => $matches ? $request->mode : 'invalid', 'valid_request' => (bool) $matches]])->save();
        }
        if ($valid) {
            $request->update(['shopify_order_id' => (string) $order->shopify_order_id, 'bound_at' => $request->bound_at ?? now(), 'status' => 'ordered']);
        }
        $hold = ! $valid || $library->owned(WholesaleLabelAssignment::class, $owner)->where('order_id', $order->id)->where('status', '!=', 'ready')->exists();
        $meta = $order->attribution_meta ?? [];
        $meta['custom_labels'] = ['hold' => $hold, 'receipt' => $valid ? $request->id : null, 'status' => $hold ? 'Artwork review required' : 'Artwork approved'];
        $order->forceFill(['attribution_meta' => $meta])->saveQuietly();
    }
}
