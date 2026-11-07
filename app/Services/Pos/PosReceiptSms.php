<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Tenant;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Marketing\MessagingContactChannelStateService;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\PaymentJournal;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Support\Tenancy\TenantContext;

/** Explicit transactional receipt request. A durable claim prevents a second send after uncertainty. */
final class PosReceiptSms
{
    public function __construct(private PosStore $store, private PosAccess $access, private PosService $sales,
        private PaymentJournal $journal, private TenantMessagingGateway $sms) {}

    public function send(string $register, string $id, bool $customerRequested, string $expectedPhone): array
    {
        if (config('pos_live.receipt_sms_enabled') !== true) {
            throw new PosException('receipt_sms_unavailable', 503);
        }
        if (! $customerRequested) {
            throw new PosException('receipt_sms_request_required', 422);
        }
        $scope = $this->access->register($register, 'quote');
        if (app(TenantContext::class)->id() !== $scope['tenant_id']) {
            throw new PosException('scope_invalid', 403);
        }
        $sale = $this->sales->sale($scope, $id);
        $receipt = $this->sales->receipt($register, $id);
        $snapshot = $this->sales->snapshot($sale);
        $payment = app(PosSalePaymentEvidence::class)->read($sale, $snapshot);
        if (! $payment || $sale->state !== 'succeeded' || $payment['kind'] !== 'payment' || $payment['state'] !== 'succeeded'
            || ! is_string($payment['provider_id'] ?? null) || $payment['provider_id'] === ''
            || $payment['sale_id'] !== $sale->id || $payment['request_id'] !== $sale->payment_request_id
            || (int) $payment['amount_minor'] !== (int) $sale->amount_minor
            || Contract::hash($payment['binding']) !== Contract::hash($snapshot['binding'])) {
            throw new PosException('paid_receipt_required');
        }
        $phone = $this->phone($sale);
        if (! $phone || app(MarketingIdentityNormalizer::class)->toE164($expectedPhone) !== $phone) {
            throw new PosException('receipt_sms_contact_required', 422);
        }
        if (app(MessagingContactChannelStateService::class)->resolveSmsStatus($scope['tenant_id'], null, $phone) === 'unsubscribed') {
            throw new PosException('receipt_sms_opted_out', 422);
        }
        $body = app(PosReceiptEmail::class)->text($receipt, (string) Tenant::whereKey($scope['tenant_id'])->value('name'));
        // Full receipts are never silently truncated. Long baskets can use email or native sharing.
        if (mb_strlen($body) > 1400) {
            throw new PosException('receipt_sms_too_long_use_email', 422);
        }
        $hash = hash_hmac('sha256', $phone, $this->store->runtime().':'.$scope['tenant_id'].':'.$id);
        $claimed = $this->store->db->transaction(function () use ($scope, $sale, $hash, $phone, $body): bool {
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if ($this->record($sale, 'receipt_sms_claimed', $hash)) {
                return false;
            }
            // Freeze the customer-confirmed recipient and text before contacting the provider.
            if ($phone !== $this->phone($sale)) {
                throw new PosException('receipt_contact_changed', 409);
            }
            $this->store->event($scope['tenant_id'], $sale->id, 'receipt_sms_claimed', ['recipient_hash' => $hash,
                'phone' => $phone, 'body' => $body, 'actor_id' => $scope['actor_id'], 'customer_requested' => true]);

            return true;
        }, 3);
        if (! $claimed) {
            return $this->result($sale, $hash);
        }
        try {
            $result = $this->sms->sendSms($scope['tenant_id'], $phone, $body, ['tenant_id' => $scope['tenant_id'], 'source_type' => 'pos_receipt',
                'idempotency_key' => 'pos-receipt:'.$hash]);
            $state = ($result['success'] ?? false) === true && ! empty($result['provider_message_id']) ? 'accepted' : 'not_confirmed';
            $this->store->event($scope['tenant_id'], $sale->id, 'receipt_sms_result', ['recipient_hash' => $hash,
                'state' => $state, 'provider_message_id' => $result['provider_message_id'] ?? null]);
        } catch (\Throwable) {
            // The claim remains durable. Retrying this request never sends another message.
            return ['state' => 'not_confirmed', 'enabled' => true];
        }

        return $this->result($sale, $hash);
    }

    public function status(string $register, string $id): array
    {
        $scope = $this->access->register($register);
        $sale = $this->sales->sale($scope, $id);
        $phone = $this->phone($sale);
        if (! $phone) {
            return ['state' => 'contact_required', 'enabled' => config('pos_live.receipt_sms_enabled') === true];
        }
        $hash = hash_hmac('sha256', $phone, $this->store->runtime().':'.$scope['tenant_id'].':'.$id);

        return $this->record($sale, 'receipt_sms_claimed', $hash) ? $this->result($sale, $hash)
            : ['state' => 'not_requested', 'enabled' => config('pos_live.receipt_sms_enabled') === true];
    }

    private function phone(object $sale): ?string
    {
        $association = app(PosReceiptCustomers::class)->association($sale);
        $contact = $association ? $this->store->decrypt($association->contact) : [];

        return app(MarketingIdentityNormalizer::class)->toE164($contact['phone'] ?? null);
    }

    private function record(object $sale, string $code, string $hash): ?array
    {
        foreach ($this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('code', $code)->orderByDesc('id')->get() as $row) {
            $details = $this->store->decrypt($row->details);
            if (($details['recipient_hash'] ?? null) === $hash) {
                return $details;
            }
        }

        return null;
    }

    private function result(object $sale, string $hash): array
    {
        $result = $this->record($sale, 'receipt_sms_result', $hash);

        return ['state' => $result['state'] ?? 'not_confirmed', 'enabled' => config('pos_live.receipt_sms_enabled') === true];
    }
}
