<?php

namespace App\Services\Shopify;

use App\Models\IntegrationConnection;
use App\Models\ModernForestryFundraiserInvoicePackage;
use App\Models\ModernForestryFundraiserOrder;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integrations\QuickBooks\QuickBooksConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModernForestryFundraiserQuickBooksService
{
    public function __construct(
        protected QuickBooksConnector $connector,
        protected ModernForestryFundraiserShopifyOrderService $shopifyOrders
    ) {}

    public function createAndMaybeSend(ModernForestryFundraiserInvoicePackage $package, bool $send): ModernForestryFundraiserInvoicePackage
    {
        if (! config('services.quickbooks.fundraiser_writes_enabled')) {
            throw ValidationException::withMessages(['quickbooks' => ['Fundraiser QuickBooks writes are disabled.']]);
        }
        if ($send && ! config('services.quickbooks.fundraiser_send_enabled')) {
            throw ValidationException::withMessages(['quickbooks' => ['Fundraiser QuickBooks sending is disabled.']]);
        }
        if ((int) $package->tax_cents !== 0) {
            throw ValidationException::withMessages(['tax' => ['A package with source tax requires manual QuickBooks tax review.']]);
        }
        if (strcasecmp($this->deliveryAddress(), (string) $package->payer_email) !== 0) {
            throw ValidationException::withMessages(['quickbooks' => ['The controlled QuickBooks address does not match the fundraiser payer email. Nothing was created or sent.']]);
        }

        if (Tenant::query()->whereKey($package->tenant_id)->value('slug') !== 'modern-forestry') {
            throw ValidationException::withMessages(['quickbooks' => ['This QuickBooks mapping is only approved for Modern Forestry.']]);
        }
        if ($send && ! filled($package->quickbooks_invoice_id)) {
            throw ValidationException::withMessages(['quickbooks' => ['Create and review the QuickBooks draft before using Send.']]);
        }

        $client = $this->approvedCompanyClient((int) $package->tenant_id);

        return DB::transaction(function () use ($package, $client, $send): ModernForestryFundraiserInvoicePackage {
            $locked = ModernForestryFundraiserInvoicePackage::query()->forTenant($package->tenant_id)->lockForUpdate()->findOrFail($package->id);
            $this->assertSourceOrdersCurrent($locked);
            if (! filled($locked->quickbooks_invoice_id)) {
                $existing = (array) data_get($client->query("select * from Invoice where DocNumber = '".str_replace("'", "''", (string) $locked->package_reference)."'"), 'QueryResponse.Invoice', []);
                if ($existing !== []) {
                    throw ValidationException::withMessages(['quickbooks' => ['An invoice with this number already exists in QuickBooks. Reconcile it before creating another.']]);
                }
                $response = $client->createInvoice($this->invoicePayload($locked), 'fundraiser-package-'.$locked->id.'-v1');
                $invoice = (array) data_get($response, 'Invoice', []);
                if (! filled($invoice['Id'] ?? null)) {
                    throw new \RuntimeException('QuickBooks did not return an invoice ID.');
                }
                $locked->forceFill([
                    'status' => 'quickbooks_created',
                    'quickbooks_invoice_id' => (string) $invoice['Id'],
                    'quickbooks_doc_number' => (string) ($invoice['DocNumber'] ?? $locked->package_reference),
                    'quickbooks_created_at' => now(),
                    'quickbooks_last_error' => null,
                ])->save();
            }

            if ($send && $locked->quickbooks_sent_at === null) {
                $liveInvoice = (array) data_get($client->invoiceWithPaymentLink((string) $locked->quickbooks_invoice_id), 'Invoice', []);
                $this->assertInvoiceMatchesPackage($liveInvoice, $locked);
                if (strcasecmp((string) ($liveInvoice['EmailStatus'] ?? ''), 'EmailSent') === 0) {
                    throw ValidationException::withMessages(['quickbooks' => ['QuickBooks shows this invoice was already emailed. Reconcile its delivery before retrying to avoid a duplicate email.']]);
                }
                if (($liveInvoice['AllowOnlineACHPayment'] ?? null) !== true
                    || ($liveInvoice['AllowOnlineCreditCardPayment'] ?? null) !== true) {
                    if (! filled($liveInvoice['SyncToken'] ?? null)) {
                        throw ValidationException::withMessages(['quickbooks' => ['QuickBooks did not return an invoice version. Nothing was sent.']]);
                    }
                    $client->updateInvoice([
                        'Id' => (string) $locked->quickbooks_invoice_id,
                        'SyncToken' => (string) $liveInvoice['SyncToken'],
                        'sparse' => true,
                        'AllowOnlineACHPayment' => true,
                        'AllowOnlineCreditCardPayment' => true,
                    ], 'fundraiser-package-'.$locked->id.'-enable-payments-v1');
                    $liveInvoice = (array) data_get($client->invoiceWithPaymentLink((string) $locked->quickbooks_invoice_id), 'Invoice', []);
                    $this->assertInvoiceMatchesPackage($liveInvoice, $locked);
                }
                if (($liveInvoice['AllowOnlineACHPayment'] ?? null) !== true
                    || ($liveInvoice['AllowOnlineCreditCardPayment'] ?? null) !== true
                    || ! $this->validIntuitLink((string) ($liveInvoice['InvoiceLink'] ?? ''))) {
                    throw ValidationException::withMessages(['quickbooks' => ['QuickBooks did not confirm both payment methods and a customer payment link. Nothing was sent.']]);
                }
                $client->sendInvoice((string) $locked->quickbooks_invoice_id, $this->deliveryAddress());
                $locked->forceFill([
                    'status' => 'sent',
                    'delivery_status' => 'sent',
                    'quickbooks_sent_at' => now(),
                    'quickbooks_last_error' => null,
                ])->save();
            }

            return $locked->fresh();
        });
    }

    public function verifiedPaymentLink(ModernForestryFundraiserInvoicePackage $package): ?string
    {
        if (! filled($package->quickbooks_invoice_id) || $package->quickbooks_sent_at === null) {
            return null;
        }
        $invoice = (array) data_get(
            $this->approvedCompanyClient((int) $package->tenant_id)->invoiceWithPaymentLink((string) $package->quickbooks_invoice_id),
            'Invoice', []
        );
        $link = (string) ($invoice['InvoiceLink'] ?? '');
        if ((int) round(((float) ($invoice['TotalAmt'] ?? -1)) * 100) !== (int) $package->total_cents
            || (string) data_get($invoice, 'CustomerRef.value') !== trim((string) config('services.quickbooks.fundraiser_customer_id'))
            || ($invoice['AllowOnlineACHPayment'] ?? null) !== true
            || ($invoice['AllowOnlineCreditCardPayment'] ?? null) !== true
            || ! $this->validIntuitLink($link)) {
            return null;
        }

        return $link;
    }

    /** @return array<string,mixed> */
    protected function invoicePayload(ModernForestryFundraiserInvoicePackage $package): array
    {
        $customerId = trim((string) config('services.quickbooks.fundraiser_customer_id'));
        $itemId = trim((string) config('services.quickbooks.fundraiser_item_id'));
        $shippingItemId = trim((string) config('services.quickbooks.fundraiser_shipping_item_id'));
        if ($customerId === '' || $itemId === '' || $shippingItemId === '') {
            throw ValidationException::withMessages(['quickbooks' => ['QuickBooks customer, fundraiser item, and shipping item mappings are required.']]);
        }

        $orders = ModernForestryFundraiserOrder::query()->forTenant($package->tenant_id)
            ->whereIn('id', (array) $package->order_ids)->orderBy('source_created_at')->get();
        $lines = [];
        foreach ($orders as $order) {
            $reference = $order->order_reference ?: $order->external_order_id;
            $proceeds = (int) $order->subtotal_cents - (int) $order->discount_cents;
            if ($proceeds > 0) {
                $lines[] = $this->line($proceeds, 'Candle proceeds - Order '.$reference, $itemId);
            }
            if ((int) $order->shipping_cents > 0) {
                $lines[] = $this->line((int) $order->shipping_cents, 'Shipping - Order '.$reference, $shippingItemId);
            }
        }

        return [
            'DocNumber' => $package->package_reference,
            'TxnDate' => $package->invoice_date?->toDateString(),
            'DueDate' => $package->due_date?->toDateString(),
            'CustomerRef' => ['value' => $customerId],
            'BillEmail' => ['Address' => $this->deliveryAddress()],
            'PrivateNote' => 'Created by Everbranch fundraiser reconciliation package '.$package->id.'.',
            'CustomerMemo' => ['value' => 'Monthly Bed Sheet Fundraiser reconciliation. Candle proceeds already reflect the agreed fundraiser share.'],
            'CurrencyRef' => ['value' => strtoupper((string) $package->currency)],
            'AllowOnlineACHPayment' => false,
            'AllowOnlineCreditCardPayment' => false,
            'Line' => $lines,
        ];
    }

    /** @param array<string,mixed> $invoice */
    protected function assertInvoiceMatchesPackage(array $invoice, ModernForestryFundraiserInvoicePackage $package): void
    {
        if ((string) ($invoice['Id'] ?? '') !== (string) $package->quickbooks_invoice_id
            || (string) ($invoice['DocNumber'] ?? '') !== (string) $package->package_reference
            || (int) round(((float) ($invoice['TotalAmt'] ?? -1)) * 100) !== (int) $package->total_cents
            || (string) data_get($invoice, 'CustomerRef.value') !== trim((string) config('services.quickbooks.fundraiser_customer_id'))
            || strcasecmp((string) data_get($invoice, 'BillEmail.Address'), $this->deliveryAddress()) !== 0) {
            throw ValidationException::withMessages(['quickbooks' => ['The live QuickBooks invoice number, amount, customer, or email differs from the approved package. Nothing was sent.']]);
        }
    }

    protected function validIntuitLink(string $link): bool
    {
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));

        return filter_var($link, FILTER_VALIDATE_URL)
            && str_starts_with($link, 'https://')
            && ($host === 'intuit.com' || str_ends_with($host, '.intuit.com'));
    }

    /** @return array<string,mixed> */
    protected function line(int $cents, string $description, string $itemId): array
    {
        return [
            'DetailType' => 'SalesItemLineDetail',
            'Description' => $description,
            'Amount' => $cents / 100,
            'SalesItemLineDetail' => ['ItemRef' => ['value' => $itemId], 'Qty' => 1, 'UnitPrice' => $cents / 100, 'TaxCodeRef' => ['value' => 'NON']],
        ];
    }

    protected function deliveryAddress(): string
    {
        $address = strtolower(trim((string) config('services.quickbooks.fundraiser_send_to')));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['quickbooks' => ['A valid controlled QuickBooks delivery address is required.']]);
        }

        return $address;
    }

    public function approvedCompanyClient(int $tenantId): \App\Services\Integrations\QuickBooks\QuickBooksOnlineClient
    {
        $connection = IntegrationConnection::query()->forTenant($tenantId)
            ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->sole();
        $expectedOwner = strtolower(trim((string) config('services.quickbooks.fundraiser_connected_by')));
        $owner = strtolower(trim((string) User::query()->whereKey($connection->connected_by_user_id)->value('email')));
        if ($expectedOwner === '' || $owner !== $expectedOwner) {
            throw ValidationException::withMessages(['quickbooks' => ['The connected QuickBooks account was not linked by the approved Modern Forestry operator. No invoice was changed.']]);
        }

        $client = $this->connector->client($connection);
        $companies = (array) data_get($client->query('select * from CompanyInfo'), 'QueryResponse.CompanyInfo', []);
        $company = count($companies) === 1 ? $companies[0] : [];
        if (strcasecmp(trim((string) data_get($company, 'CompanyName')), trim((string) config('services.quickbooks.fundraiser_company_name'))) !== 0
            || strcasecmp(trim((string) data_get($company, 'Email.Address')), trim((string) config('services.quickbooks.fundraiser_company_email'))) !== 0
            || ! filled(data_get($company, 'CompanyName'))
            || ! filled(data_get($company, 'Email.Address'))) {
            throw ValidationException::withMessages(['quickbooks' => ['The connected QuickBooks company is not the approved Modern Forestry company. No invoice was changed.']]);
        }

        return $client;
    }

    protected function assertSourceOrdersCurrent(ModernForestryFundraiserInvoicePackage $package): void
    {
        $orders = ModernForestryFundraiserOrder::query()->forTenant($package->tenant_id)
            ->whereIn('id', (array) $package->order_ids)->get();
        if ($orders->count() !== count((array) $package->order_ids)
            || $orders->sum('total_cents') !== (int) $package->total_cents) {
            throw ValidationException::withMessages(['orders' => ['The invoice queue no longer matches its source orders.']]);
        }
        foreach ($orders as $order) {
            if ($order->source === 'shopify' && ! (bool) data_get($order->source_payload, 'shipping_verified')) {
                throw ValidationException::withMessages(['shipping' => ['A purchased-label cost is missing.']]);
            }
            $this->shopifyOrders->assertCurrent($order);
        }
        if (preg_match('/^BSF-([A-Z]{3})-(\d{4})$/', (string) $package->package_reference, $matches) === 1) {
            $month = \Carbon\CarbonImmutable::createFromFormat('!M Y', $matches[1].' '.$matches[2], 'America/New_York');
            if ($month !== false) {
                $sourceIds = Order::query()->forTenant($package->tenant_id)
                    ->where('shopify_store_key', 'retail')
                    ->whereJsonContains('attribution_meta->order_tags', 'BSF')
                    ->whereBetween('ordered_at', [$month->startOfMonth()->utc(), $month->endOfMonth()->utc()])
                    ->where(function ($query): void {
                        $query->whereNull('refund_total')->orWhere('refund_total', '<=', 0);
                    })
                    ->pluck('shopify_order_id')->map(fn ($id): string => (string) $id)->all();
                $includedIds = $orders->where('source', 'shopify')->pluck('external_order_id')->map(fn ($id): string => (string) $id)->all();
                if (array_diff($sourceIds, $includedIds) !== []) {
                    throw ValidationException::withMessages(['orders' => ['New BSF Shopify orders are missing from this invoice package. Refresh and reconcile the month before creating or sending it.']]);
                }
            }
        }
    }
}
