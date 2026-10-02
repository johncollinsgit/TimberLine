<?php

namespace App\Services\Shopify;

use App\Models\IntegrationConnection;
use App\Models\ModernForestryFundraiserInvoicePackage;
use App\Models\ModernForestryFundraiserOrder;
use App\Models\Order;
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

        $connection = IntegrationConnection::query()->forTenant($package->tenant_id)
            ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->sole();
        $client = $this->connector->client($connection);

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
                if ((int) round(((float) ($liveInvoice['TotalAmt'] ?? -1)) * 100) !== (int) $locked->total_cents
                    || (string) data_get($liveInvoice, 'CustomerRef.value') !== trim((string) config('services.quickbooks.fundraiser_customer_id'))
                    || ! filter_var($liveInvoice['InvoiceLink'] ?? null, FILTER_VALIDATE_URL)
                ) {
                    throw ValidationException::withMessages(['quickbooks' => ['The live QuickBooks invoice amount, customer, or payable link could not be verified. Nothing was sent.']]);
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
        if (! filled($package->quickbooks_invoice_id)) {
            return null;
        }
        $connection = IntegrationConnection::query()->forTenant($package->tenant_id)
            ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->sole();
        $invoice = (array) data_get(
            $this->connector->client($connection)->invoiceWithPaymentLink((string) $package->quickbooks_invoice_id),
            'Invoice', []
        );
        $link = (string) ($invoice['InvoiceLink'] ?? '');
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        if ((int) round(((float) ($invoice['TotalAmt'] ?? -1)) * 100) !== (int) $package->total_cents
            || (string) data_get($invoice, 'CustomerRef.value') !== trim((string) config('services.quickbooks.fundraiser_customer_id'))
            || ! str_starts_with($link, 'https://')
            || ! ($host === 'intuit.com' || str_ends_with($host, '.intuit.com'))) {
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
            'AllowOnlineACHPayment' => true,
            'AllowOnlineCreditCardPayment' => true,
            'Line' => $lines,
        ];
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
