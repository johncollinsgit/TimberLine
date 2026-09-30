<?php

namespace App\Services\Shopify;

use App\Models\IntegrationConnection;
use App\Models\ModernForestryFundraiserInvoicePackage;
use App\Models\ModernForestryFundraiserOrder;
use App\Services\Integrations\QuickBooks\QuickBooksConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModernForestryFundraiserQuickBooksService
{
    public function __construct(protected QuickBooksConnector $connector) {}

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

        $connection = IntegrationConnection::query()->forTenant($package->tenant_id)
            ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->sole();
        $client = $this->connector->client($connection);

        return DB::transaction(function () use ($package, $client, $send): ModernForestryFundraiserInvoicePackage {
            $locked = ModernForestryFundraiserInvoicePackage::query()->forTenant($package->tenant_id)->lockForUpdate()->findOrFail($package->id);
            if (! filled($locked->quickbooks_invoice_id)) {
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
                $lines[] = $this->line($proceeds, 'Candle proceeds — Order '.$reference, $itemId);
            }
            if ((int) $order->shipping_cents > 0) {
                $lines[] = $this->line((int) $order->shipping_cents, 'Shipping — Order '.$reference, $shippingItemId);
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
            'SalesItemLineDetail' => ['ItemRef' => ['value' => $itemId], 'Qty' => 1, 'UnitPrice' => $cents / 100],
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
}
