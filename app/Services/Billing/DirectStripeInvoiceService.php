<?php

namespace App\Services\Billing;

use App\Models\TenantCommercialOverride;
use App\Models\TenantDirectInvoice;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DirectStripeInvoiceService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    /** @return array{ok:bool,message:?string} */
    public function send(TenantDirectInvoice $invoice, ?int $actorId): array
    {
        return $this->deliver($invoice, $actorId, true);
    }

    /** @return array{ok:bool,message:?string} */
    public function prepareForText(TenantDirectInvoice $invoice, ?int $actorId): array
    {
        return $this->deliver($invoice, $actorId, false);
    }

    /** @return array{ok:bool,message:?string} */
    protected function deliver(TenantDirectInvoice $invoice, ?int $actorId, bool $email): array
    {
        $invoice->loadMissing('tenant');
        if ($blocker = $this->readinessBlocker($invoice)) {
            return ['ok' => false, 'message' => $blocker];
        }
        $canPrepare = in_array($invoice->status, ['draft', 'send_failed'], true);
        $canEmailPrepared = $email
            && $invoice->status === 'open'
            && filled($invoice->provider_invoice_id)
            && blank($invoice->sent_at);
        if (! $canPrepare && ! $canEmailPrepared) {
            return ['ok' => false, 'message' => $email
                ? 'Only a draft, failed invoice, or unsent open invoice can be emailed.'
                : 'Only a draft or failed invoice can be prepared for text delivery.'];
        }

        $invoice->forceFill(['status' => 'sending', 'failed_at' => null])->save();
        try {
            $customerId = $this->syncCustomer($invoice);
            $invoice->forceFill(['provider_customer_id' => $customerId])->save();

            $stripeInvoice = $this->createInvoice($invoice, $customerId);
            $providerInvoiceId = trim((string) ($stripeInvoice['id'] ?? $invoice->provider_invoice_id));
            if (! str_starts_with($providerInvoiceId, 'in_')) {
                throw new \RuntimeException('Stripe returned an invalid invoice identifier.');
            }
            $invoice->forceFill(['provider_invoice_id' => $providerInvoiceId])->save();

            if (blank($invoice->finalized_at)) {
                $this->createInvoiceItems($invoice, $customerId, $providerInvoiceId);
                $finalized = $this->stripeCall('post', '/v1/invoices/'.urlencode($providerInvoiceId).'/finalize', ['auto_advance' => 'false'], 'direct-invoice-'.$invoice->id.'-finalize-v1');
                $this->applyProviderInvoice($invoice, $finalized, 'open');
                $invoice->forceFill(['finalized_at' => now(), 'status' => 'open'])->save();
            }

            if ($email) {
                $sent = $this->stripeCall('post', '/v1/invoices/'.urlencode($providerInvoiceId).'/send', [], 'direct-invoice-'.$invoice->id.'-send-v1');
                $this->applyProviderInvoice($invoice, $sent, 'open');
                $invoice->forceFill(['sent_at' => now()])->save();
            } else {
                $invoice->forceFill(['status' => 'open'])->save();
            }

            $this->audit->record(
                (int) $invoice->tenant_id,
                $actorId,
                $email ? 'tenant_billing.direct_invoice.send' : 'tenant_billing.direct_invoice.prepare_text',
                targetType: 'tenant_direct_invoice',
                targetId: $invoice->id,
                context: [
                    'provider_invoice_id' => $providerInvoiceId,
                    'delivery_channel' => $email ? 'email' : 'text',
                    'automatic_tax_enabled' => $this->automaticTaxEnabled(),
                ],
            );

            return ['ok' => true, 'message' => null];
        } catch (\Throwable $exception) {
            $invoice->forceFill(['status' => 'send_failed', 'failed_at' => now(), 'metadata' => [...(array) $invoice->metadata, 'last_send_error' => $exception->getMessage()]])->save();
            $this->audit->record(
                (int) $invoice->tenant_id,
                $actorId,
                $email ? 'tenant_billing.direct_invoice.send' : 'tenant_billing.direct_invoice.prepare_text',
                status: 'failed',
                targetType: 'tenant_direct_invoice',
                targetId: $invoice->id,
                context: [
                    'provider_invoice_id' => $invoice->provider_invoice_id,
                    'delivery_channel' => $email ? 'email' : 'text',
                    'error' => $exception->getMessage(),
                ],
            );

            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return array{ok:bool,message:?string} */
    public function void(TenantDirectInvoice $invoice, ?int $actorId): array
    {
        if (! in_array($invoice->status, ['open', 'payment_failed', 'uncollectible'], true) || ! filled($invoice->provider_invoice_id)) {
            return ['ok' => false, 'message' => 'Only an open Stripe invoice can be voided.'];
        }
        if ($blocker = $this->readinessBlocker($invoice->loadMissing('tenant'))) {
            return ['ok' => false, 'message' => $blocker];
        }
        try {
            $object = $this->stripeCall('post', '/v1/invoices/'.urlencode((string) $invoice->provider_invoice_id).'/void', [], 'direct-invoice-'.$invoice->id.'-void-v1');
            $this->applyProviderInvoice($invoice, $object, 'void');
            $invoice->forceFill(['voided_at' => now()])->save();
            $this->audit->record((int) $invoice->tenant_id, $actorId, 'tenant_billing.direct_invoice.void', targetType: 'tenant_direct_invoice', targetId: $invoice->id, context: ['provider_invoice_id' => $invoice->provider_invoice_id]);

            return ['ok' => true, 'message' => null];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    public function availableFor(TenantDirectInvoice $invoice): bool
    {
        return $this->readinessBlocker($invoice->loadMissing('tenant')) === null;
    }

    public function blockerFor(TenantDirectInvoice $invoice): ?string
    {
        return $this->readinessBlocker($invoice->loadMissing('tenant'));
    }

    protected function syncCustomer(TenantDirectInvoice $invoice): string
    {
        $customerId = trim((string) $invoice->provider_customer_id);
        if ($customerId === '') {
            $customerId = trim((string) TenantDirectInvoice::withoutGlobalScopes()->where('tenant_id', $invoice->tenant_id)->where('customer_email', $invoice->customer_email)->whereNotNull('provider_customer_id')->latest('id')->value('provider_customer_id'));
        }
        if ($customerId === '') {
            $override = TenantCommercialOverride::query()->where('tenant_id', $invoice->tenant_id)->first();
            $customerId = trim((string) data_get($override?->billing_mapping, 'stripe.customer_reference'));
        }
        $address = (array) $invoice->billing_address;
        $payload = [
            'name' => (string) $invoice->customer_name,
            'email' => (string) $invoice->customer_email,
            'address[line1]' => (string) ($address['line1'] ?? ''),
            'address[city]' => (string) ($address['city'] ?? ''),
            'address[state]' => (string) ($address['state'] ?? ''),
            'address[postal_code]' => (string) ($address['postal_code'] ?? ''),
            'address[country]' => (string) ($address['country'] ?? 'US'),
            'metadata[tenant_id]' => (string) $invoice->tenant_id,
            'metadata[tenant_slug]' => (string) $invoice->tenant->slug,
            'metadata[source]' => 'everbranch_direct_invoice',
        ];
        if (filled($invoice->customer_phone)) {
            $payload['phone'] = (string) $invoice->customer_phone;
        }
        if (filled($address['line2'] ?? null)) {
            $payload['address[line2]'] = (string) $address['line2'];
        }
        $path = $customerId !== '' ? '/v1/customers/'.urlencode($customerId) : '/v1/customers';
        $object = $this->stripeCall('post', $path, $payload, 'direct-invoice-'.$invoice->id.'-customer-v1');
        $resolved = trim((string) ($object['id'] ?? $customerId));
        if (! str_starts_with($resolved, 'cus_')) {
            throw new \RuntimeException('Stripe returned an invalid customer identifier.');
        }

        return $resolved;
    }

    /** @return array<string,mixed> */
    protected function createInvoice(TenantDirectInvoice $invoice, string $customerId): array
    {
        if (filled($invoice->provider_invoice_id)) {
            return ['id' => $invoice->provider_invoice_id];
        }
        $payload = [
            'customer' => $customerId,
            'collection_method' => 'send_invoice',
            'days_until_due' => (int) $invoice->days_until_due,
            'auto_advance' => 'false',
            'currency' => strtolower((string) $invoice->currency),
            'payment_settings[payment_method_types][0]' => 'card',
            'payment_settings[payment_method_types][1]' => 'us_bank_account',
            'automatic_tax[enabled]' => $this->automaticTaxEnabled() ? 'true' : 'false',
            'metadata[purpose]' => 'direct_invoice',
            'metadata[tenant_id]' => (string) $invoice->tenant_id,
            'metadata[direct_invoice_id]' => (string) $invoice->id,
            'metadata[authorization_reference]' => (string) $invoice->authorization_reference,
        ];
        if (filled($invoice->memo)) {
            $payload['description'] = (string) $invoice->memo;
        }
        if (filled($invoice->footer)) {
            $payload['footer'] = (string) $invoice->footer;
        }

        return $this->stripeCall('post', '/v1/invoices', $payload, 'direct-invoice-'.$invoice->id.'-invoice-v1');
    }

    protected function createInvoiceItems(TenantDirectInvoice $invoice, string $customerId, string $providerInvoiceId): void
    {
        foreach ((array) $invoice->line_items as $index => $line) {
            $this->stripeCall('post', '/v1/invoiceitems', [
                'customer' => $customerId,
                'invoice' => $providerInvoiceId,
                'amount' => (int) ($line['amount_cents'] ?? 0),
                'currency' => strtolower((string) $invoice->currency),
                'description' => (string) ($line['description'] ?? ''),
                'metadata[purpose]' => 'direct_invoice',
                'metadata[tenant_id]' => (string) $invoice->tenant_id,
                'metadata[direct_invoice_id]' => (string) $invoice->id,
                'metadata[line_key]' => (string) ($line['key'] ?? 'line_'.($index + 1)),
                'metadata[category]' => (string) ($line['category'] ?? ''),
            ], 'direct-invoice-'.$invoice->id.'-item-'.$index.'-v1');
        }
    }

    /** @return array<string,mixed> */
    protected function stripeCall(string $method, string $path, array $payload, string $idempotencyKey): array
    {
        $request = $this->stripeRequest()->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        /** @var Response $response */
        $response = $request->{$method}($this->apiBase().$path, $payload);
        $json = is_array($response->json()) ? $response->json() : [];
        if ($response->failed()) {
            throw new \RuntimeException(trim((string) data_get($json, 'error.message')) ?: 'Stripe could not complete the invoice request.');
        }

        return $json;
    }

    /** @param array<string,mixed> $object */
    protected function applyProviderInvoice(TenantDirectInvoice $invoice, array $object, string $fallbackStatus): void
    {
        $status = strtolower(trim((string) ($object['status'] ?? $fallbackStatus)));
        $invoice->forceFill([
            'status' => in_array($status, TenantDirectInvoice::STATUSES, true) ? $status : $fallbackStatus,
            'provider_invoice_number' => $object['number'] ?? $invoice->provider_invoice_number,
            'provider_payment_intent_id' => $object['payment_intent'] ?? $invoice->provider_payment_intent_id,
            'provider_tax_cents' => max(0, (int) ($object['tax'] ?? collect((array) ($object['total_tax_amounts'] ?? []))->sum('amount'))),
            'provider_total_cents' => max(0, (int) ($object['total'] ?? $invoice->provider_total_cents)),
            'provider_amount_due_cents' => max(0, (int) ($object['amount_due'] ?? $invoice->provider_amount_due_cents)),
            'hosted_invoice_url' => $this->safeUrl($object['hosted_invoice_url'] ?? $invoice->hosted_invoice_url),
            'invoice_pdf_url' => $this->safeUrl($object['invoice_pdf'] ?? $invoice->invoice_pdf_url),
        ])->save();
    }

    protected function readinessBlocker(TenantDirectInvoice $invoice): ?string
    {
        if (! (bool) config('commercial.billing_readiness.direct_invoicing.enabled', false)) {
            return 'Stripe invoice sending is not enabled yet.';
        }
        $allowed = (array) config('commercial.billing_readiness.direct_invoicing.tenant_slugs', []);
        if (! in_array('*', $allowed, true) && ! in_array((string) $invoice->tenant?->slug, $allowed, true)) {
            return 'Stripe invoice sending is not enabled for this workspace yet.';
        }
        $secret = trim((string) config('services.stripe.secret'));
        if (! str_starts_with($secret, 'sk_test_') && ! str_starts_with($secret, 'sk_live_')) {
            return 'Stripe is not configured.';
        }
        if (str_starts_with($secret, 'sk_live_')) {
            if (! filled(config('services.stripe.webhook_secret'))) {
                return 'The live Stripe webhook secret is not configured.';
            }
            if (! (bool) config('commercial.billing_readiness.direct_invoicing.tax_decision_confirmed', false)) {
                return 'The required tax decision has not been confirmed.';
            }
            if (! (bool) config('commercial.billing_readiness.direct_invoicing.relay_payout_verified', false)) {
                return 'Stripe payouts to Relay have not been verified.';
            }
        }

        return null;
    }

    protected function automaticTaxEnabled(): bool
    {
        return (bool) config('commercial.billing_readiness.direct_invoicing.automatic_tax_enabled', false)
            && (bool) config('commercial.billing_readiness.direct_invoicing.tax_decision_confirmed', false);
    }

    protected function safeUrl(mixed $url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    protected function stripeRequest(): PendingRequest
    {
        return Http::asForm()->acceptJson()->timeout(max(5, (int) config('services.stripe.timeout', 20)))->retry(1, 250, throw: false)->withBasicAuth((string) config('services.stripe.secret'), '');
    }

    protected function apiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/');
    }
}
