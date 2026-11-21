<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderNotification;
use App\Services\Marketing\Messaging\TenantMessagingAccountResolver;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use Illuminate\Support\Str;

/** Private recipient policy and metered SMS transport for merchant-only notices. */
class WebsiteMerchantPurchaseAlerts
{
    public function contacts(int $tenantId, string $channel): array
    {
        if (! config('managed_website.merchant_purchase_alerts_enabled')) {
            return [];
        }

        return collect((array) config("managed_website.merchant_purchase_alert_contacts.{$tenantId}.{$channel}", []))
            ->filter(fn ($value) => is_string($value))
            ->map(fn (string $value) => $channel === 'email' ? strtolower(trim($value)) : trim($value))
            ->filter(fn (string $value) => $channel === 'email'
                ? filter_var($value, FILTER_VALIDATE_EMAIL) && ! preg_match('/[\r\n]/', $value)
                : preg_match('/^\+[1-9]\d{7,14}$/D', $value))
            ->unique()->values()->all();
    }

    public function channel(string $kind): ?string
    {
        return match ($kind) {
            'merchant_paid_email' => 'email',
            'merchant_paid_sms' => 'sms',
            default => null,
        };
    }

    public function isPaidOrder(WebsiteOrder $order): bool
    {
        return in_array($order->source, ['native', 'staff_quote', 'staff_draft_invoice'], true)
            && $order->payment_status === 'paid' && $order->paid_at !== null && $order->cancelled_at === null
            && $order->total_cents > 0;
    }

    public function smsIdentity(int $tenantId): ?array
    {
        if (config('marketing.sms.dry_run')) {
            return null;
        }
        try {
            $resolution = app(TenantMessagingAccountResolver::class)->resolve($tenantId, 'sms');
        } catch (\RuntimeException) {
            return null;
        }

        $account = $resolution['account'];
        $token = data_get($account?->credentials, 'auth_token');
        if ($resolution['mode'] !== 'tenant' || $resolution['provider'] !== 'twilio_subaccount'
            || ! $account || $account->tenant_id !== $tenantId || ! is_string($token) || empty(trim($token))
            || (! preg_match('/^MG[a-f0-9]{32}$/iD', (string) $account->provider_resource_id)
                && ! preg_match('/^\+[1-9]\d{7,14}$/D', (string) $account->sender_identifier))) {
            return null;
        }

        return ['sms_account_id' => $account->id, 'sms_sender_fingerprint' => $account->smsSenderFingerprint()];
    }

    public function snapshot(WebsiteOrder $order, Tenant $tenant, string $channel, string $recipient): array
    {
        $brand = Str::limit(Str::squish(strip_tags($tenant->brandProfile?->display_name ?: $tenant->name)), 120, '');
        $number = Str::limit(Str::squish(strip_tags($order->number)), 60, '');
        $kind = 'merchant_paid_'.$channel;

        return [
            'kind' => $kind, 'recipient' => $recipient, 'brand' => $brand, 'order_number' => $number,
            'heading' => 'Payment confirmed', 'subject' => 'Paid order '.$number.' · '.$brand,
            'message' => 'A customer paid for this order. Open Orders in Everbranch for details.',
            'currency' => $order->currency, 'total_cents' => $order->total_cents, 'refunded_cents' => $order->refunded_cents,
        ];
    }

    public function sendSms(WebsiteOrderNotification $notification): string
    {
        $snapshot = $notification->snapshot;
        $number = preg_replace('/[^a-zA-Z0-9._-]/', '', Str::ascii($snapshot['order_number']));
        $amount = number_format($snapshot['total_cents'] / 100, 2, '.', '');
        $currency = strtoupper($snapshot['currency']);
        $message = "Everbranch: Payment confirmed for order {$number}. {$currency} {$amount}. View orders in Everbranch.";
        $result = app(TenantMessagingGateway::class)->sendSms($notification->tenant_id, $snapshot['recipient'], $message, [
            'idempotency_key' => 'website-merchant-purchase:'.$notification->transition_key,
            'ledger_source_type' => 'website_merchant_purchase_alert',
            'source_id' => $notification->id,
            'expected_tenant_sms_account' => [$snapshot['sms_account_id'], $snapshot['sms_sender_fingerprint']],
        ]);

        if (! ($result['success'] ?? false) || ($result['dry_run'] ?? false) || blank($result['provider_message_id'] ?? null)) {
            throw new \RuntimeException('The SMS transport did not confirm acceptance.');
        }

        return (string) $result['provider_message_id'];
    }
}
