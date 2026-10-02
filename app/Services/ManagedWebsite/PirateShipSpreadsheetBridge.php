<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteFulfillmentLine;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pirate Ship has no API. This bridge uses its supported address spreadsheet
 * upload and shipment export while keeping Website fulfillment tenant-owned.
 */
class PirateShipSpreadsheetBridge
{
    public function __construct(private readonly WebsiteCommerceService $commerce) {}

    public function enabledFor(Tenant $tenant): bool
    {
        return $this->commerce->enabledFor($tenant)
            && (bool) config('managed_website.pirate_ship_bridge_enabled', false)
            && in_array((int) $tenant->id, (array) config('managed_website.pirate_ship_bridge_tenant_ids', []), true);
    }

    public function export(Tenant $tenant, TenantSite $site): StreamedResponse
    {
        abort_unless($this->enabledFor($tenant) && (int) $site->tenant_id === (int) $tenant->id, 423);

        return response()->streamDownload(function () use ($tenant, $site): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Order ID', 'Full Name', 'Address Line 1', 'Address Line 2', 'City', 'State', 'Zip', 'Country', 'Email', 'Phone', 'Order Items', 'Order Value', 'Requested Shipping Service']);
            WebsiteOrder::query()->forTenant($tenant)->where('tenant_site_id', $site->id)
                ->where('payment_status', 'paid')->where('fulfillment_method', 'ship')->where('fulfillment_status', 'unfulfilled')
                ->whereDoesntHave('shipments')->with('lines')->orderBy('id')->chunkById(100, function ($orders) use ($stream): void {
                    foreach ($orders as $order) {
                        $address = (array) $order->shipping_address;
                        if (empty($address['name']) || empty($address['street1']) || empty($address['city']) || empty($address['state']) || empty($address['zip'])) {
                            continue;
                        }
                        $buyer = (array) $order->customer_snapshot;
                        $values = [
                            $order->number, $address['name'], $address['street1'], $address['street2'] ?? '',
                            $address['city'], $address['state'], $address['zip'], $address['country'] ?? 'US',
                            $buyer['email'] ?? '', $buyer['phone'] ?? '',
                            $order->lines->map(fn ($line) => $line->quantity.' × '.$line->title)->implode('; '),
                            number_format($order->total_cents / 100, 2, '.', ''),
                            (string) data_get($order->shipping_rate_snapshot, 'service', ''),
                        ];
                        fputcsv($stream, array_map($this->safeCsvCell(...), $values));
                    }
                });
            fclose($stream);
        }, 'everbranch-pirate-ship-orders-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array{imported:int,already_present:int} */
    public function importTracking(Tenant $tenant, TenantSite $site, User $actor, UploadedFile $file): array
    {
        abort_unless($this->enabledFor($tenant) && (int) $site->tenant_id === (int) $tenant->id, 423);
        $stream = fopen($file->getRealPath(), 'r');
        if (! $stream) {
            throw ValidationException::withMessages(['file' => 'Unable to read the CSV file.']);
        }
        try {
            $headers = fgetcsv($stream);
            if (! is_array($headers)) {
                throw ValidationException::withMessages(['file' => 'The CSV file is empty.']);
            }
            $normalized = array_map(fn ($value) => strtolower(trim((string) $value)), $headers);
            $orderColumn = array_search('order id', $normalized, true);
            $trackingColumn = array_search('tracking number', $normalized, true);
            $carrierColumn = array_search('carrier', $normalized, true);
            $serviceColumn = array_search('service', $normalized, true);
            if ($orderColumn === false || $trackingColumn === false || $carrierColumn === false) {
                throw ValidationException::withMessages(['file' => 'Include Order ID, Tracking Number, and Carrier columns in the Pirate Ship export.']);
            }
            $rows = [];
            while (($columns = fgetcsv($stream)) !== false) {
                if (count($rows) >= 500) {
                    throw ValidationException::withMessages(['file' => 'Import up to 500 tracking rows at a time.']);
                }
                if (count($columns) === 1 && trim((string) $columns[0]) === '') {
                    continue;
                }
                $number = trim((string) ($columns[$orderColumn] ?? ''));
                $tracking = strtoupper(trim((string) ($columns[$trackingColumn] ?? '')));
                $carrier = strtoupper(trim((string) ($columns[$carrierColumn] ?? '')));
                $service = $serviceColumn === false ? '' : trim((string) ($columns[$serviceColumn] ?? ''));
                if (! preg_match('/^WEB-[A-Z0-9]{8}$/', $number) || ! preg_match('/^[A-Z0-9]{8,40}$/', $tracking) || ! in_array($carrier, ['USPS', 'UPS'], true) || strlen($service) > 120 || isset($rows[$number])) {
                    throw ValidationException::withMessages(['file' => 'Each row needs one unique Everbranch Order ID, a valid tracking number, and USPS or UPS as the carrier.']);
                }
                $rows[$number] = compact('number', 'tracking', 'carrier', 'service');
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The CSV contains no tracking rows.']);
        }

        return DB::transaction(function () use ($rows, $tenant, $site, $actor): array {
            $imported = 0;
            $already = 0;
            foreach ($rows as $row) {
                $order = WebsiteOrder::query()->forTenant($tenant)->where('tenant_site_id', $site->id)
                    ->where('number', $row['number'])->lockForUpdate()->first();
                if (! $order || $order->payment_status !== 'paid' || $order->fulfillment_method !== 'ship') {
                    throw ValidationException::withMessages(['file' => 'Order '.$row['number'].' is not a paid shipping order in this workspace. Nothing was imported.']);
                }
                $existing = WebsiteShipment::query()->forTenant($tenant)->where('website_order_id', $order->id)->first();
                if ($existing) {
                    if ($existing->provider === 'pirate_ship' && $existing->tracking_number === $row['tracking']) {
                        $already++;
                        continue;
                    }
                    throw ValidationException::withMessages(['file' => 'Order '.$row['number'].' already has a different shipment. Nothing was imported.']);
                }
                if ($order->fulfillment_status !== 'unfulfilled') {
                    throw ValidationException::withMessages(['file' => 'Order '.$row['number'].' has already been fulfilled. Nothing was imported.']);
                }
                $fulfillment = WebsiteFulfillment::query()->create([
                    'tenant_id' => $tenant->id, 'website_order_id' => $order->id, 'status' => 'fulfilled', 'method' => 'ship',
                    'note' => 'Tracking imported from Pirate Ship spreadsheet.', 'fulfilled_by_user_id' => $actor->id, 'fulfilled_at' => now(),
                ]);
                foreach ($order->lines as $line) {
                    WebsiteFulfillmentLine::query()->create([
                        'tenant_id' => $tenant->id, 'website_fulfillment_id' => $fulfillment->id,
                        'website_order_line_id' => $line->id, 'quantity' => $line->quantity,
                    ]);
                }
                $trackingUrl = $row['carrier'] === 'USPS'
                    ? 'https://tools.usps.com/go/TrackConfirmAction?tLabels='.$row['tracking']
                    : 'https://www.ups.com/track?tracknum='.$row['tracking'];
                $shipment = WebsiteShipment::query()->create([
                    'tenant_id' => $tenant->id, 'website_order_id' => $order->id, 'website_fulfillment_id' => $fulfillment->id,
                    'provider' => 'pirate_ship', 'carrier' => $row['carrier'], 'service' => $row['service'] ?: null,
                    'tracking_number' => $row['tracking'], 'tracking_url' => $trackingUrl, 'status' => 'label_purchased',
                    'destination' => $order->shipping_address, 'currency' => 'usd', 'purchased_at' => now(),
                ]);
                WebsiteShipmentEvent::query()->create([
                    'tenant_id' => $tenant->id, 'website_shipment_id' => $shipment->id,
                    'provider_event_id' => 'csv-'.hash('sha256', $order->number.$row['tracking']),
                    'event_type' => 'tracking_imported', 'status' => 'label_purchased',
                    'message' => 'Tracking imported by Everbranch staff from Pirate Ship.',
                    'payload' => ['carrier' => $row['carrier'], 'tracking_number' => $row['tracking']], 'occurred_at' => now(),
                ]);
                $order->forceFill(['fulfillment_status' => 'fulfilled', 'fulfilled_at' => now()])->save();
                WebsiteOrderEvent::query()->create([
                    'tenant_id' => $tenant->id, 'website_order_id' => $order->id, 'user_id' => $actor->id,
                    'event_type' => 'tracking_imported', 'visibility' => 'staff',
                    'message' => 'Pirate Ship tracking imported.', 'data' => ['carrier' => $row['carrier'], 'tracking_number' => $row['tracking']],
                ]);
                $imported++;
            }

            return ['imported' => $imported, 'already_present' => $already];
        });
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^\s*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
