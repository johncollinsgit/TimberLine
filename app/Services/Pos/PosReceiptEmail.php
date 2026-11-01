<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PlatformEmailMessage;
use App\Models\Tenant;
use App\Services\Mail\PlatformSesOutbox;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\PaymentJournal;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

/** Queues a receipt only for a cashier-entered contact; never associates a customer. */
final class PosReceiptEmail
{
    public function __construct(private PosStore $store, private PosAccess $access, private PosService $sales,
        private PaymentJournal $journal, private PlatformSesOutbox $outbox) {}

    public function queue(string $register, string $saleId): array
    {
        if (config('pos_live.receipt_email_enabled') !== true) {
            throw new PosException('receipt_email_unavailable', 503);
        }
        $scope = $this->access->register($register, 'quote');
        if (app(TenantContext::class)->id() !== $scope['tenant_id']) {
            throw new PosException('scope_invalid', 403);
        }
        $sale = $this->sales->sale($scope, $saleId);
        $snapshot = $this->sales->snapshot($sale);
        $payment = $sale->attempt_id ? $this->journal->get($scope['tenant_id'], $sale->attempt_id) : null;
        if ($sale->state !== 'succeeded' || ! $payment || $payment['kind'] !== 'payment' || $payment['state'] !== 'succeeded'
            || ! is_string($payment['provider_id'] ?? null) || $payment['provider_id'] === ''
            || $payment['sale_id'] !== $sale->id || $payment['request_id'] !== $sale->payment_request_id
            || (int) $payment['amount_minor'] !== (int) $sale->amount_minor
            || Contract::hash($payment['binding']) !== Contract::hash($snapshot['binding'])) {
            throw new PosException('paid_receipt_required');
        }

        // The contact and the immutable outbox intent share the default database.
        // The provider is called later by the outbox worker, never in this transaction.
        return $this->store->db->transaction(function () use ($scope, $sale, $register, $saleId, $payment): array {
            $association = $this->store->db->table('commerce_stock_receipt_associations')
                ->where('tenant_id', $scope['tenant_id'])->where('runtime_id', $this->store->runtime())
                ->where('sale_id', $sale->id)->lockForUpdate()->first();
            $contact = $association ? $this->store->decrypt($association->contact) : [];
            $email = strtolower(trim((string) ($contact['email'] ?? '')));
            if (strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new PosException('receipt_email_contact_required', 422);
            }
            if ($delivery = $this->findDelivery($scope['tenant_id'], $sale->id, $email)) {
                return $this->statusBody($delivery);
            }
            $receipt = $this->sales->receipt($register, $saleId);
            if (! hash_equals((string) $receipt['payment_reference'], (string) ($payment['provider_id'] ?? ''))) {
                throw new PosException('paid_receipt_required');
            }
            $identity = $this->identity($scope['tenant_id'], $sale->id, $this->outbox->recipientHash($email));
            $merchant = $this->safe(Tenant::query()->whereKey($scope['tenant_id'])->value('name'));
            if ($merchant === '') {
                throw new PosException('receipt_merchant_unavailable', 503);
            }
            $message = (new Email)->from((string) config('ses_migration.platform_from'))->to($email)
                ->subject('Your receipt from '.$merchant)->text($this->text($receipt, $merchant));
            $message->getHeaders()->addTextHeader('X-Everbranch-Logical-Message-ID', $identity);
            $ids = $this->outbox->enqueue($message, Envelope::create($message));
            $row = \App\Models\PlatformEmailDelivery::query()->findOrFail($ids[0]);

            return $this->statusBody($row);
        }, 3);
    }

    /** Read-only status; never enqueues or submits mail. */
    public function status(string $register, string $saleId): array
    {
        $scope = $this->access->register($register);
        if (app(TenantContext::class)->id() !== $scope['tenant_id']) {
            throw new PosException('scope_invalid', 403);
        }
        $sale = $this->sales->sale($scope, $saleId);
        $association = app(PosReceiptCustomers::class)->association($sale);
        $contact = $association ? $this->store->decrypt($association->contact) : [];
        $email = strtolower(trim((string) ($contact['email'] ?? '')));
        if (strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['state' => 'contact_required', 'enabled' => config('pos_live.receipt_email_enabled') === true];
        }
        $delivery = $this->findDelivery($scope['tenant_id'], $sale->id, $email);

        return $delivery ? $this->statusBody($delivery) : ['state' => 'not_requested', 'enabled' => config('pos_live.receipt_email_enabled') === true];
    }

    private function findDelivery(int $tenantId, string $saleId, string $email): ?\App\Models\PlatformEmailDelivery
    {
        // Check current and previous application keys so rotation does not duplicate an intent.
        $hashes = $this->outbox->recipientHashes($email);
        foreach ($hashes as $recipientHash) {
            $identity = $this->identity($tenantId, $saleId, $recipientHash);
            $existing = PlatformEmailMessage::where('group_key', hash('sha256', 'explicit:'.$identity))->first();
            if ($existing) {
                if ((int) $existing->tenant_id !== $tenantId) {
                    throw new PosException('receipt_email_identity_conflict');
                }
                $delivery = $existing->deliveries()->whereIn('recipient_hash', $hashes)->first();
                if (! $delivery) {
                    throw new PosException('receipt_email_identity_conflict');
                }

                return $delivery;
            }
        }

        return null;
    }

    private function statusBody(\App\Models\PlatformEmailDelivery $delivery): array
    {
        return ['delivery_id' => $delivery->id, 'state' => $delivery->state, 'event_state' => $delivery->event_state,
            'enabled' => config('pos_live.receipt_email_enabled') === true];
    }

    private function identity(int $tenantId, string $sale, string $recipientHash): string
    {
        return 'pos-receipt-'.hash('sha256', $tenantId.':'.$this->store->runtime().':'.$sale.':'.$recipientHash);
    }

    private function safe(mixed $value): string
    {
        return mb_substr(trim(preg_replace('/[\\p{C}]+/u', ' ', (string) $value)), 0, 150);
    }

    private function money(int $amount): string
    {
        return '$'.number_format($amount / 100, 2, '.', ',');
    }

    private function text(array $receipt, string $merchant): string
    {
        $lines = [$merchant, $this->safe($receipt['merchant_location']), 'Receipt '.$this->safe($receipt['receipt_number']), ''];
        foreach ($receipt['lines'] as $line) {
            $lines[] = (int) $line['quantity'].' × '.$this->safe($line['title']).(isset($line['subtotal_minor']) ? '  '.$this->money((int) $line['subtotal_minor']) : '');
            foreach ($line['components'] ?? [] as $component) {
                $lines[] = '  '.(int) $component['quantity'].' × '.$this->safe($component['title']);
            }
        }
        if (isset($receipt['subtotal_minor'])) {
            $lines[] = 'Subtotal: '.$this->money((int) $receipt['subtotal_minor']);
        }
        if (($receipt['discount_minor'] ?? 0) > 0) {
            $lines[] = 'Discounts: −'.$this->money((int) $receipt['discount_minor']);
        }
        if (isset($receipt['tax_minor'])) {
            $lines[] = 'Tax: '.$this->money((int) $receipt['tax_minor']);
        }
        $lines[] = 'Paid: '.$this->money((int) $receipt['amount_minor']);
        if (($receipt['refunded_minor'] ?? 0) > 0) {
            $lines[] = 'Refunded: '.$this->money((int) $receipt['refunded_minor']);
        }
        $lines[] = 'Payment reference: '.$this->safe($receipt['payment_reference']);
        $details = $receipt['payment_details'] ?? [];
        foreach (['card_brand', 'last_four', 'entry_method', 'application_name', 'application_identifier'] as $field) {
            if (! empty($details[$field])) {
                $lines[] = $this->safe($details[$field]);
            }
        }

        return implode("\n", $lines)."\n";
    }
}
