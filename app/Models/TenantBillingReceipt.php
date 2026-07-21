<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantBillingReceipt extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider_calculated_tax' => 'boolean', 'billing_period_starts_at' => 'datetime',
            'billing_period_ends_at' => 'datetime', 'billed_at' => 'datetime', 'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopeStripeActivityRecorded(Builder $query, bool $livemode): Builder
    {
        return $query
            ->where('provider', 'stripe')
            ->where('total_amount_cents', '>', 0)
            ->whereNotNull('source_event_id')
            ->where('source_event_id', '!=', '')
            ->whereDoesntHave('billingOrder', fn (Builder $orders): Builder => $orders->where('metadata->validation_only', true))
            ->whereExists(function ($events) use ($livemode): void {
                $events->selectRaw('1')
                    ->from('stripe_webhook_events')
                    ->whereColumn('stripe_webhook_events.event_id', 'tenant_billing_receipts.source_event_id')
                    ->whereColumn('stripe_webhook_events.tenant_id', 'tenant_billing_receipts.tenant_id')
                    ->where('stripe_webhook_events.event_type', 'like', 'invoice.%')
                    ->where('stripe_webhook_events.status', 'like', 'processed%')
                    ->where('stripe_webhook_events.livemode', $livemode)
                    ->whereNotNull('stripe_webhook_events.processed_at');
            });
    }

    public function scopeStripePaymentConfirmed(Builder $query, bool $livemode): Builder
    {
        return $query
            ->stripeActivityRecorded($livemode)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereExists(function ($events): void {
                $events->selectRaw('1')
                    ->from('stripe_webhook_events')
                    ->whereColumn('stripe_webhook_events.event_id', 'tenant_billing_receipts.source_event_id')
                    ->whereColumn('stripe_webhook_events.tenant_id', 'tenant_billing_receipts.tenant_id')
                    ->whereIn('stripe_webhook_events.event_type', ['invoice.paid', 'invoice.payment_succeeded']);
            });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAuthorization::class, 'subscription_authorization_id');
    }

    public function billingOrder(): BelongsTo
    {
        return $this->belongsTo(TenantBillingOrder::class, 'tenant_billing_order_id');
    }

    public function directInvoice(): BelongsTo
    {
        return $this->belongsTo(TenantDirectInvoice::class, 'tenant_direct_invoice_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(TenantBillingRefund::class);
    }
}
