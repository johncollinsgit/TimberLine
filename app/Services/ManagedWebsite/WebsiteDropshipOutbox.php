<?php

namespace App\Services\ManagedWebsite;

use App\Jobs\SendWebsiteDropshipDraft;
use App\Models\WebsiteOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Stores IDs and supplier approval, while the original order retains buyer data. */
class WebsiteDropshipOutbox
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly WebsiteDropshipDraftHandoff $handoff) {}

    /** Called when the native Stripe transaction has accepted payment and inventory. */
    public function record(WebsiteOrder $order, int $paymentId): void
    {
        $destination = $this->handoff->automaticDestination($order);
        if ($destination === null) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The supplier outbox requires an accepted payment transaction.');
        }

        $payment = $order->payments()->forTenantId($order->tenant_id)->whereKey($paymentId)->first();
        if ($order->payment_status !== 'paid' || ! $payment || $payment->provider !== 'stripe' || $payment->status !== 'paid'
            || data_get($payment->metadata, 'checkout_version') !== 1 || data_get($payment->metadata, 'livemode') !== true
            || data_get($payment->metadata, 'paid_confirmed') !== true || data_get($payment->metadata, 'inventory_committed') !== true) {
            return;
        }

        // The order lock serializes native accepted payment transitions.
        DB::table('website_dropship_outbox')->upsert([
            'tenant_id' => $order->tenant_id, 'tenant_site_id' => $order->tenant_site_id,
            'website_order_id' => $order->id, 'website_payment_id' => $payment->id,
            ...$destination, 'status' => 'pending', 'next_attempt_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], ['website_order_id'], ['website_order_id']);
    }

    public function scan(int $limit): array
    {
        $result = ['queued' => 0, 'review' => 0, 'deferred' => 0];
        if (config('website_dropshipping.shopify_drafts_enabled') !== true) {
            return $result;
        }

        $now = now();
        $rows = DB::table('website_dropship_outbox')->where('next_attempt_at', '<=', $now)
            ->whereIn('status', ['pending', 'queued', 'running'])->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get();
        foreach ($rows as $row) {
            $due = DB::table('website_dropship_outbox')->where('id', $row->id)->where('status', $row->status)
                ->where('next_attempt_at', '<=', $now)->where('dispatch_token', $row->dispatch_token);
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                $result['review'] += $due->update([
                    'status' => 'review', 'next_attempt_at' => null, 'dispatch_token' => null, 'updated_at' => now(),
                ]);

                continue;
            }

            $token = (string) Str::uuid();
            if (! $due->update([
                'status' => 'queued', 'dispatch_token' => $token, 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now(),
            ])) {
                continue;
            }

            try {
                Bus::dispatch(new SendWebsiteDropshipDraft($row->id, $token));
                $result['queued']++;
            } catch (Throwable) {
                $result['deferred']++;
                // Keep the lease in case the queue accepted the job even though
                // acknowledgment failed. The next scan recovers expired leases.
            }
        }

        return $result;
    }

    public function deliver(int $id, string $token): void
    {
        if (config('website_dropshipping.shopify_drafts_enabled') !== true) {
            return;
        }

        $attempt = DB::table('website_dropship_outbox')->where('id', $id)->where('dispatch_token', $token);
        if (! (clone $attempt)->where('status', 'queued')->where('next_attempt_at', '>', now())->update([
            'status' => 'running', 'attempts' => DB::raw('attempts + 1'),
            'next_attempt_at' => now()->addMinutes(2), 'updated_at' => now(),
        ])) {
            return;
        }
        $row = $attempt->first();
        if (! $row) {
            return;
        }

        try {
            $this->dispatchOrder($row, $attempt);
        } catch (Throwable) {
            // Provider exception text can contain shipping details. Leave a
            // scoped status, and reconcile an existing claim on the next run.
            $this->finish($attempt, $row->attempts >= self::MAX_ATTEMPTS ? 'review' : 'pending');
        }
    }

    private function dispatchOrder(object $row, Builder $attempt): void
    {
        $order = WebsiteOrder::query()->forTenantId($row->tenant_id)->where('tenant_site_id', $row->tenant_site_id)->find($row->website_order_id);
        $destination = ['supplier_key' => $row->supplier_key, 'shop_id' => $row->shop_id, 'shop_domain' => $row->shop_domain];
        if (! $order || $this->handoff->automaticDestination($order) !== $destination) {
            $this->finish($attempt, 'review');

            return;
        }

        if ($this->handoff->alreadyAttempted($row->tenant_id, $row->tenant_site_id, $row->website_order_id)) {
            $this->handoff->reconcile($row->tenant_id, $row->tenant_site_id, $row->website_order_id, $destination);
        } else {
            $payment = $order->payments()->forTenantId($row->tenant_id)->latest('id')->first();
            if ($payment?->id !== $row->website_payment_id || $payment->status !== 'paid'
                || $order->payment_status !== 'paid' || ! empty($payment->metadata['refunds'])) {
                $this->finish($attempt, 'review');

                return;
            }
            // A refund from Stripe's dashboard may precede its local webhook.
            app(WebsitePaymentService::class)->reconcileRefunds($payment);
            if (! empty($payment->fresh()->metadata['refunds'])) {
                $this->finish($attempt, 'review');

                return;
            }
            $this->handoff->submit($row->tenant_id, $row->tenant_site_id, $row->website_order_id, $destination);
        }

        $this->finish($attempt, 'submitted');
    }

    private function finish(Builder $attempt, string $status): void
    {
        $attempt->update([
            'status' => $status, 'dispatch_token' => null, 'updated_at' => now(),
            'next_attempt_at' => $status === 'pending' ? now()->addMinutes(2) : null,
        ]);
    }
}
