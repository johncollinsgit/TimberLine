<?php

namespace App\Services\Billing;

use App\Models\TenantBillingOrder;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class AgreementStripeScheduleService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    /** @return array{ok:bool,status:string,message:?string} */
    public function configure(TenantBillingOrder $order): array
    {
        $order->loadMissing('authorization');
        $promo = collect((array) $order->line_items)->firstWhere('payment_timing', 'recurring_current');
        $standard = collect((array) $order->line_items)->firstWhere('payment_timing', 'recurring_future');
        $cycles = (int) ($promo['cycles'] ?? 0);
        if (! is_array($promo) || ! is_array($standard) || $cycles < 1) {
            return ['ok' => true, 'status' => 'not_required', 'message' => null];
        }
        if (filled($order->provider_schedule_id)) {
            return ['ok' => true, 'status' => 'already_configured', 'message' => null];
        }
        $subscriptionId = trim((string) $order->provider_subscription_id);
        if ($subscriptionId === '') {
            return $this->fail($order, 'Stripe did not provide the subscription needed for the promotional schedule.');
        }

        $subscriptionResponse = $this->request()->get($this->apiBase().'/v1/subscriptions/'.$subscriptionId, ['expand[0]' => 'items.data.price']);
        $subscription = is_array($subscriptionResponse->json()) ? $subscriptionResponse->json() : [];
        $promoPriceId = trim((string) data_get($subscription, 'items.data.0.price.id', ''));
        $productId = trim((string) data_get($subscription, 'items.data.0.price.product.id', data_get($subscription, 'items.data.0.price.product', '')));
        $start = (int) ($subscription['start_date'] ?? $subscription['current_period_start'] ?? 0);
        if ($subscriptionResponse->failed() || $promoPriceId === '' || $productId === '' || $start < 1) {
            return $this->fail($order, 'Stripe subscription details were incomplete; promotional schedule activation is blocked.');
        }

        $priceResponse = $this->request()->withHeaders(['Idempotency-Key' => 'agreement-order-'.(int) $order->id.'-standard-price-v1'])->post($this->apiBase().'/v1/prices', [
            'product' => $productId,
            'currency' => strtolower((string) $order->currency),
            'unit_amount' => (int) $standard['amount_cents'],
            'recurring[interval]' => 'month',
            'metadata[billing_order_id]' => (string) $order->id,
            'metadata[phase]' => 'standard',
        ]);
        $standardPriceId = trim((string) data_get($priceResponse->json(), 'id', ''));
        if ($priceResponse->failed() || $standardPriceId === '') {
            return $this->fail($order, 'Stripe could not create the approved standard recurring price.');
        }

        $createResponse = $this->request()->withHeaders(['Idempotency-Key' => 'agreement-order-'.(int) $order->id.'-schedule-v1'])->post($this->apiBase().'/v1/subscription_schedules', ['from_subscription' => $subscriptionId]);
        $scheduleId = trim((string) data_get($createResponse->json(), 'id', ''));
        if ($createResponse->failed() || $scheduleId === '') {
            return $this->fail($order, 'Stripe could not create the approved subscription schedule.');
        }

        $end = CarbonImmutable::createFromTimestamp($start)->addMonthsNoOverflow($cycles)->getTimestamp();
        $updateResponse = $this->request()->withHeaders(['Idempotency-Key' => 'agreement-order-'.(int) $order->id.'-schedule-phases-v1'])->post($this->apiBase().'/v1/subscription_schedules/'.$scheduleId, [
            'end_behavior' => 'release',
            'phases[0][start_date]' => $start,
            'phases[0][end_date]' => $end,
            'phases[0][items][0][price]' => $promoPriceId,
            'phases[0][items][0][quantity]' => 1,
            'phases[0][proration_behavior]' => 'none',
            'phases[1][start_date]' => $end,
            'phases[1][items][0][price]' => $standardPriceId,
            'phases[1][items][0][quantity]' => 1,
            'phases[1][proration_behavior]' => 'none',
            'metadata[billing_order_id]' => (string) $order->id,
            'metadata[agreement_version_id]' => (string) $order->agreement_version_id,
        ]);
        if ($updateResponse->failed()) {
            return $this->fail($order, 'Stripe created the schedule but could not lock its approved promotional phases.');
        }

        $order->forceFill(['provider_schedule_id' => $scheduleId, 'metadata' => [...(array) $order->metadata, 'schedule_status' => 'configured', 'schedule_phase_ends_at' => CarbonImmutable::createFromTimestamp($end)->toIso8601String()]])->save();
        if ($order->authorization) {
            $order->authorization->forceFill(['metadata' => [...(array) $order->authorization->metadata, 'schedule_status' => 'configured', 'provider_schedule_id' => $scheduleId]])->save();
        }
        $this->audit->record((int) $order->tenant_id, null, 'tenant_billing.stripe_schedule.configure', status: 'success', targetType: 'tenant_billing_order', targetId: $order->id, context: ['stripe_schedule_id' => $scheduleId, 'promotional_cycles' => $cycles]);

        return ['ok' => true, 'status' => 'configured', 'message' => null];
    }

    protected function fail(TenantBillingOrder $order, string $message): array
    {
        $order->forceFill(['metadata' => [...(array) $order->metadata, 'schedule_status' => 'failed', 'schedule_error' => $message]])->save();
        if ($order->authorization) {
            $order->authorization->forceFill(['status' => 'schedule_failed', 'metadata' => [...(array) $order->authorization->metadata, 'schedule_status' => 'failed']])->save();
        }
        $this->audit->record((int) $order->tenant_id, null, 'tenant_billing.stripe_schedule.configure', status: 'failed', targetType: 'tenant_billing_order', targetId: $order->id, context: ['message' => $message]);

        return ['ok' => false, 'status' => 'failed', 'message' => $message];
    }

    protected function request(): PendingRequest
    {
        return Http::asForm()->acceptJson()->timeout(max(5, (int) config('services.stripe.timeout', 20)))->retry(1, 250, throw: false)->withBasicAuth((string) config('services.stripe.secret'), '');
    }

    protected function apiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/');
    }
}
