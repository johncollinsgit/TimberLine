<?php

namespace App\Services\Billing;

use App\Models\TenantDirectInvoice;
use App\Services\Marketing\MessagingContactChannelStateService;
use App\Services\Marketing\TwilioSmsService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class DirectInvoiceSmsReminderService
{
    public function __construct(
        protected TwilioSmsService $sms,
        protected LandlordOperatorActionAuditService $audit,
        protected MarketingIdentityNormalizer $identityNormalizer,
        protected MessagingContactChannelStateService $channelStates,
    ) {}

    /** @return array{ok:bool,message:string} */
    public function send(TenantDirectInvoice $invoice, string $phone, string $idempotencyKey, bool $consentConfirmed, ?int $actorId, string $purpose = 'reminder'): array
    {
        $purpose = $purpose === 'initial_delivery' ? 'initial_delivery' : 'reminder';
        $normalizedPhone = $this->identityNormalizer->toE164($phone);
        if ($normalizedPhone === null) {
            return $this->failed($invoice, $actorId, $idempotencyKey, 'Enter a valid 10-digit US phone number.', purpose: $purpose);
        }
        if (! $consentConfirmed) {
            return $this->failed($invoice, $actorId, $idempotencyKey, 'Confirm that the customer agreed to receive this billing text.', purpose: $purpose);
        }
        if ($this->channelStates->resolveSmsStatus((int) $invoice->tenant_id, null, $normalizedPhone) === 'unsubscribed') {
            return $this->failed($invoice, $actorId, $idempotencyKey, 'This phone number has opted out of text messages.', purpose: $purpose);
        }
        if (! in_array((string) $invoice->status, ['open', 'payment_failed'], true) || ! str_starts_with((string) $invoice->provider_invoice_id, 'in_')) {
            return $this->failed($invoice, $actorId, $idempotencyKey, 'Only an open Stripe invoice can be delivered by text.', purpose: $purpose);
        }

        try {
            $providerInvoice = $this->retrieveProviderInvoice((string) $invoice->provider_invoice_id);
            $providerStatus = strtolower(trim((string) ($providerInvoice['status'] ?? '')));
            $amountRemaining = max(0, (int) ($providerInvoice['amount_remaining'] ?? $providerInvoice['amount_due'] ?? 0));
            $hostedUrl = $this->safeUrl($providerInvoice['hosted_invoice_url'] ?? null);
            if ($providerStatus !== 'open' || $amountRemaining < 1 || $hostedUrl === null) {
                return $this->failed($invoice, $actorId, $idempotencyKey, 'Stripe no longer reports this invoice as open with an amount due. No text was sent.', purpose: $purpose);
            }

            $claim = $this->claim($invoice, $idempotencyKey, $normalizedPhone, $purpose);
            if (! $claim) {
                return ['ok' => true, 'message' => $purpose === 'initial_delivery' ? 'This invoice text was already processed.' : 'This invoice reminder was already processed.'];
            }

            $name = trim((string) $invoice->customer_name);
            $firstName = trim((string) str($name)->before(' ')) ?: 'there';
            $number = trim((string) ($providerInvoice['number'] ?? $invoice->provider_invoice_number)) ?: 'your invoice';
            $currency = strtoupper(trim((string) ($providerInvoice['currency'] ?? $invoice->currency ?? 'USD')));
            $amount = number_format($amountRemaining / 100, 2);
            $message = $purpose === 'initial_delivery'
                ? "Everbranch: Hi {$firstName}, invoice {$number} for {$currency} {$amount} is ready. View and pay securely: {$hostedUrl} Reply STOP to unsubscribe."
                : "Everbranch: Hi {$firstName}, this is a reminder that invoice {$number} for {$currency} {$amount} is awaiting payment. View and pay securely: {$hostedUrl} Reply STOP to unsubscribe.";
            $result = $this->sms->sendSms($normalizedPhone, $message, [
                'tenant_id' => (int) $invoice->tenant_id,
                'source_type' => $purpose === 'initial_delivery' ? 'direct_invoice_delivery' : 'direct_invoice_reminder',
                'ledger_source_type' => $purpose === 'initial_delivery' ? 'direct_invoice_delivery' : 'direct_invoice_reminder',
                'source_id' => (int) $invoice->id,
                'idempotency_key' => 'direct-invoice-'.$purpose.':'.$invoice->id.':'.$idempotencyKey,
            ]);
            $ok = (bool) ($result['success'] ?? false);
            $this->completeClaim($invoice, $idempotencyKey, $normalizedPhone, $result, $ok);
            $this->audit->record(
                (int) $invoice->tenant_id,
                $actorId,
                $purpose === 'initial_delivery' ? 'tenant_billing.direct_invoice.sms_delivery' : 'tenant_billing.direct_invoice.sms_reminder',
                status: $ok ? 'success' : 'failed',
                targetType: 'tenant_direct_invoice',
                targetId: $invoice->id,
                context: $this->auditContext($invoice, $idempotencyKey, $normalizedPhone, $purpose),
                confirmation: ['billing_sms_consent_confirmed' => true],
                result: [
                    'provider' => $result['provider'] ?? null,
                    'provider_message_id' => $result['provider_message_id'] ?? null,
                    'error_code' => $result['error_code'] ?? null,
                ],
            );

            $errorMessage = trim((string) ($result['error_message'] ?? '')) ?: 'The text message could not be sent.';

            return $ok
                ? ['ok' => true, 'message' => $purpose === 'initial_delivery' ? 'Invoice text sent.' : 'Invoice reminder text sent.']
                : ['ok' => false, 'message' => $errorMessage];
        } catch (Throwable $exception) {
            $this->completeClaim($invoice, $idempotencyKey, $normalizedPhone, ['error_code' => 'reminder_exception'], false);

            return $this->failed($invoice, $actorId, $idempotencyKey, 'The invoice text could not be sent. Stripe or messaging is currently unavailable.', $normalizedPhone, $exception->getMessage(), $purpose);
        }
    }

    /** @return array<string,mixed> */
    protected function retrieveProviderInvoice(string $providerInvoiceId): array
    {
        $secret = trim((string) config('services.stripe.secret'));
        if (! str_starts_with($secret, 'sk_test_') && ! str_starts_with($secret, 'sk_live_')) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        /** @var Response $response */
        $response = Http::acceptJson()
            ->timeout(max(5, (int) config('services.stripe.timeout', 20)))
            ->retry(1, 250, throw: false)
            ->withBasicAuth($secret, '')
            ->get(rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/').'/v1/invoices/'.urlencode($providerInvoiceId));
        $payload = is_array($response->json()) ? $response->json() : [];
        if ($response->failed() || ($payload['object'] ?? null) !== 'invoice' || ($payload['id'] ?? null) !== $providerInvoiceId) {
            throw new \RuntimeException(trim((string) data_get($payload, 'error.message')) ?: 'Stripe could not verify this invoice.');
        }

        return $payload;
    }

    protected function claim(TenantDirectInvoice $invoice, string $idempotencyKey, string $phone, string $purpose): bool
    {
        return DB::transaction(function () use ($invoice, $idempotencyKey, $phone, $purpose): bool {
            $locked = TenantDirectInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) $locked->metadata;
            $reminders = array_values((array) ($metadata['sms_invoice_reminders'] ?? []));
            if (collect($reminders)->contains(fn (mixed $entry): bool => is_array($entry) && hash_equals((string) ($entry['key'] ?? ''), $idempotencyKey))) {
                return false;
            }
            $reminders[] = [
                'key' => $idempotencyKey,
                'status' => 'sending',
                'purpose' => $purpose,
                'requested_at' => now()->toIso8601String(),
                'phone_last_four' => substr($phone, -4),
            ];
            $metadata['sms_invoice_reminders'] = array_slice($reminders, -20);
            $locked->forceFill(['metadata' => $metadata])->save();

            return true;
        });
    }

    /** @param array<string,mixed> $result */
    protected function completeClaim(TenantDirectInvoice $invoice, string $idempotencyKey, string $phone, array $result, bool $ok): void
    {
        DB::transaction(function () use ($invoice, $idempotencyKey, $phone, $result, $ok): void {
            $locked = TenantDirectInvoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }
            $metadata = (array) $locked->metadata;
            $reminders = collect((array) ($metadata['sms_invoice_reminders'] ?? []))->map(function (mixed $entry) use ($idempotencyKey, $result, $ok): mixed {
                if (! is_array($entry) || ($entry['key'] ?? null) !== $idempotencyKey) {
                    return $entry;
                }

                return [...$entry,
                    'status' => $ok ? 'sent' : 'failed',
                    'completed_at' => now()->toIso8601String(),
                    'provider_message_id' => $result['provider_message_id'] ?? null,
                    'error_code' => $result['error_code'] ?? null,
                ];
            })->values()->all();
            $metadata['sms_invoice_reminders'] = $reminders;
            if ($ok) {
                $metadata['last_sms_reminder_at'] = now()->toIso8601String();
                $metadata['last_sms_reminder_phone_last_four'] = substr($phone, -4);
                $metadata['sms_reminder_count'] = max(0, (int) ($metadata['sms_reminder_count'] ?? 0)) + ((bool) ($result['idempotent_replay'] ?? false) ? 0 : 1);
                $locked->customer_phone = $phone;
            }
            $locked->metadata = $metadata;
            $locked->save();
        });
    }

    /** @return array{ok:bool,message:string} */
    protected function failed(TenantDirectInvoice $invoice, ?int $actorId, string $idempotencyKey, string $message, ?string $phone = null, ?string $detail = null, string $purpose = 'reminder'): array
    {
        $this->audit->record(
            (int) $invoice->tenant_id,
            $actorId,
            $purpose === 'initial_delivery' ? 'tenant_billing.direct_invoice.sms_delivery' : 'tenant_billing.direct_invoice.sms_reminder',
            status: 'failed',
            targetType: 'tenant_direct_invoice',
            targetId: $invoice->id,
            context: [...$this->auditContext($invoice, $idempotencyKey, $phone, $purpose), 'error' => $detail ?? $message],
        );

        return ['ok' => false, 'message' => $message];
    }

    /** @return array<string,mixed> */
    protected function auditContext(TenantDirectInvoice $invoice, string $idempotencyKey, ?string $phone, string $purpose): array
    {
        return [
            'provider_invoice_id' => (string) $invoice->provider_invoice_id,
            'idempotency_key' => $idempotencyKey,
            'purpose' => $purpose,
            'phone_last_four' => $phone ? substr($phone, -4) : null,
            'phone_hash' => $phone ? hash('sha256', $phone) : null,
        ];
    }

    protected function safeUrl(mixed $url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }
}
