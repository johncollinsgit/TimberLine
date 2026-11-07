<?php

namespace App\Services\Pos;

use App\Models\Tenant;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\PaymentsCore\Contract;

/** Existing tenant mail route, with a committed one-shot intent before provider I/O. */
final class PosReceiptTenantEmail
{
    public function __construct(private PosStore $store, private PosAccess $access, private PosService $sales, private TenantMessagingGateway $gateway) {}

    public function send(string $register, string $id): array
    {
        $scope = $this->access->register($register, 'quote');
        $sale = $this->sales->sale($scope, $id);
        $original = $this->sales->snapshot($sale);
        $payment = app(PosSalePaymentEvidence::class)->read($sale, $original);
        if (! $payment || $sale->state !== 'succeeded' || $payment['state'] !== 'succeeded' || $payment['kind'] !== 'payment'
            || ! $payment['provider_id'] || $payment['sale_id'] !== $sale->id || $payment['request_id'] !== $sale->payment_request_id
            || (int) $payment['amount_minor'] !== (int) $sale->amount_minor || Contract::hash($payment['binding']) !== Contract::hash($original['binding'])) {
            throw new PosException('paid_receipt_required', 409);
        }
        $email = $this->email($sale);
        if (! $email) {
            throw new PosException('receipt_email_contact_required', 422);
        }
        $receipt = $this->sales->receipt($register, $id);
        $merchant = (string) Tenant::whereKey($scope['tenant_id'])->value('name');
        $body = app(PosReceiptEmail::class)->text($receipt, $merchant);
        $claimed = $this->store->db->transaction(function () use ($scope, $sale, $email, $body, $merchant) {
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if (app(PosReceiptEmail::class)->previousPlatformStatus($sale, $email) || $this->event($sale, 'receipt_tenant_email_claimed', $email)) {
                return false;
            }
            if ($this->email($sale) !== $email) {
                throw new PosException('receipt_contact_changed', 409);
            }
            $this->store->event($scope['tenant_id'], $sale->id, 'receipt_tenant_email_claimed', ['recipient' => $email, 'body' => $body,
                'subject' => 'Your receipt from '.$merchant, 'actor_id' => $scope['actor_id']]);

            return true;
        }, 3);
        if ($claimed) {
            try {
                $result = $this->gateway->sendEmail($scope['tenant_id'], $email, 'Your receipt from '.$merchant, $body,
                    ['tenant_id' => $scope['tenant_id'], 'message_type' => 'transactional', 'source_type' => 'pos_receipt',
                        'idempotency_key' => 'pos-receipt-email:'.hash('sha256', $scope['tenant_id'].':'.$this->store->runtime().':'.$id.':'.$email)]);
                $state = ($result['success'] ?? false) === true && filled($result['provider_message_id'] ?? $result['message_id'] ?? null) ? 'accepted' : 'unknown';
                $this->store->event($scope['tenant_id'], $sale->id, 'receipt_tenant_email_result', ['recipient' => $email, 'state' => $state]);
            } catch (\Throwable) { /* Possible acceptance: retain intent, never resend automatically. */
            }
        }

        return $this->status($register, $id);
    }

    public function status(string $register, string $id): array
    {
        $scope = $this->access->register($register);
        $sale = $this->sales->sale($scope, $id);
        $email = $this->email($sale);
        if ($email && ($prior = app(PosReceiptEmail::class)->previousPlatformStatus($sale, $email))) {
            return $prior;
        }
        $result = $email ? $this->event($sale, 'receipt_tenant_email_result', $email) : null;

        return ['enabled' => config('pos_live.receipt_email_enabled') === true, 'state' => ! $email ? 'contact_required' : ($result['state'] ?? ($this->event($sale, 'receipt_tenant_email_claimed', $email) ? 'unknown' : 'not_requested'))];
    }

    public function recordedStatus(object $sale, string $email): ?array
    {
        if (! $this->event($sale, 'receipt_tenant_email_claimed', $email)) {
            return null;
        }

        return ['enabled' => config('pos_live.receipt_email_enabled') === true,
            'state' => $this->event($sale, 'receipt_tenant_email_result', $email)['state'] ?? 'unknown'];
    }

    private function email(object $sale): ?string
    {
        $row = app(PosReceiptCustomers::class)->association($sale);
        $contact = $row ? $this->store->decrypt($row->contact) : [];
        $email = strtolower(trim((string) ($contact['email'] ?? '')));

        return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function event(object $sale, string $code, string $email): ?array
    {
        foreach ($this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('code', $code)->get() as $event) {
            $details = $this->store->decrypt($event->details);
            if (($details['recipient'] ?? null) === $email) {
                return $details;
            }
        }

        return null;
    }
}
