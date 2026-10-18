<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\WebsiteProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsiteProductCsvService
{
    public function __construct(private readonly WebsiteCommerceService $commerce) {}

    public function export(TenantSite $site): StreamedResponse
    {
        $filename = Str::slug($site->tenant?->name ?: 'website').'-products-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($site): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['handle', 'title', 'product_type', 'description', 'status', 'retail_price', 'wholesale_price', 'sku', 'image_url', 'track_inventory', 'inventory_quantity', 'is_available', 'variant_id', 'variant_title', 'shipping_weight_ounces', 'shipping_length_inches', 'shipping_width_inches', 'shipping_height_inches']);

            WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->with('variants')->orderBy('title')->each(function (WebsiteProduct $product) use ($output): void {
                foreach ($product->variants->isEmpty() ? collect([null]) : $product->variants as $variant) {
                    fputcsv($output, [
                        $product->handle,
                        $product->title,
                        $product->product_type,
                        $product->description,
                        $product->status,
                        number_format(($variant?->price_cents ?? 0) / 100, 2, '.', ''),
                        $variant?->wholesale_price_cents === null ? '' : number_format($variant->wholesale_price_cents / 100, 2, '.', ''),
                        $variant?->sku,
                        data_get($product->media, '0', ''),
                        $product->track_inventory ? '1' : '0',
                        $variant?->inventory_quantity,
                        $variant?->is_available ? '1' : '0',
                        $variant?->id,
                        $variant?->title,
                        $variant?->shipping_weight_ounces,
                        $variant?->shipping_length_inches,
                        $variant?->shipping_width_inches,
                        $variant?->shipping_height_inches,
                    ]);
                }
            });
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{created:int,updated:int} */
    public function import(TenantSite $site, UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['catalog' => 'The CSV file could not be read.']);
        }

        $header = fgetcsv($handle);
        $columns = collect(is_array($header) ? $header : [])->map(fn ($value): string => Str::snake(strtolower(trim((string) $value))))->all();
        foreach (['title', 'retail_price'] as $required) {
            if (! in_array($required, $columns, true)) {
                fclose($handle);
                throw ValidationException::withMessages(['catalog' => 'The CSV must include title and retail_price columns.']);
            }
        }

        try {
            return DB::transaction(function () use ($site, $handle, $columns): array {
                TenantSite::query()->forTenantId($site->tenant_id)->whereKey($site->id)->lockForUpdate()->firstOrFail();
                $createdIds = [];
                $updatedIds = [];
                $rowNumber = 1;

                while (($values = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    if ($rowNumber > 1001) {
                        throw ValidationException::withMessages(['catalog' => 'Imports are limited to 1,000 variant rows at a time.']);
                    }
                    if (count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                        continue;
                    }

                    $row = array_combine($columns, array_pad(array_slice($values, 0, count($columns)), count($columns), ''));
                    $title = trim((string) ($row['title'] ?? ''));
                    $retail = trim((string) ($row['retail_price'] ?? ''));
                    $type = trim((string) ($row['product_type'] ?? 'physical')) ?: 'physical';
                    $status = trim((string) ($row['status'] ?? 'draft')) ?: 'draft';
                    $slug = Str::slug(trim((string) ($row['handle'] ?? '')) ?: $title);
                    if ($title === '' || $slug === '' || ! is_numeric($retail) || (float) $retail < 0 || ! in_array($type, ['physical', 'service', 'quote'], true) || ! in_array($status, ['draft', 'active', 'archived'], true)) {
                        throw ValidationException::withMessages(['catalog' => "Row {$rowNumber} has an invalid title, price, type, or status."]);
                    }

                    $product = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('handle', $slug)->first();
                    if ($product?->status === 'active' || $status === 'active') {
                        abort_unless(app(ManagedWebsiteService::class)->publishingEnabled(), 423, 'Publishing is paused. Active products cannot change right now.');
                    }
                    $variantId = trim((string) ($row['variant_id'] ?? ''));
                    $variantTitle = trim((string) ($row['variant_title'] ?? ''));
                    $sku = trim((string) ($row['sku'] ?? ''));
                    $variant = null;
                    if ($variantId !== '') {
                        if (! ctype_digit($variantId) || ! $product || ! ($variant = $product->variants()->forTenantId($site->tenant_id)->find((int) $variantId))) {
                            throw ValidationException::withMessages(['catalog' => "Row {$rowNumber} has a variant that does not belong to this product in this website."]);
                        }
                    } elseif ($product && $variantTitle !== '') {
                        $variant = $product->variants()->forTenantId($site->tenant_id)
                            ->where('title', $variantTitle)->when($sku !== '', fn ($query) => $query->where('sku', $sku))->first();
                    }
                    $data = [
                        'handle' => $slug,
                        'title' => $title,
                        'product_type' => $type,
                        'description' => trim((string) ($row['description'] ?? '')),
                        'status' => $status,
                        'price' => $retail,
                        'wholesale_price' => $this->optionalMoney($row['wholesale_price'] ?? null, $rowNumber),
                        'sku' => $sku,
                        'media' => $this->imageMedia($row['image_url'] ?? null, $rowNumber),
                        'track_inventory' => $this->boolean($row['track_inventory'] ?? false),
                        'inventory_quantity' => max(0, (int) ($row['inventory_quantity'] ?? 0)),
                        'is_available' => $this->boolean($row['is_available'] ?? true, true),
                    ];
                    // The CSV's single cover image must not discard the rest
                    // of an existing gallery when the exported cover is unchanged.
                    if ($product && $data['media'] === array_slice((array) $product->media, 0, 1)) {
                        $data['media'] = $product->media;
                    }
                    if ($variant) {
                        $data['variant_id'] = $variant->id;
                    } elseif ($variantTitle !== '') {
                        $data['new_variant'] = true;
                    }
                    if ($variantTitle !== '') {
                        $data['variant_title'] = $variantTitle;
                    }
                    // Old files and blank cells must not erase measured weights
                    // or dimensions. Clear a measurement explicitly in the editor.
                    $measurements = [];
                    foreach (['shipping_weight_ounces', 'shipping_length_inches', 'shipping_width_inches', 'shipping_height_inches'] as $field) {
                        if (filled($row[$field] ?? null)) {
                            $measurements[$field] = $row[$field];
                        }
                    }
                    $validation = Validator::make($measurements, [
                        'shipping_weight_ounces' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
                        'shipping_length_inches' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                        'shipping_width_inches' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                        'shipping_height_inches' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                    ]);
                    if ($validation->fails()) {
                        throw ValidationException::withMessages(['catalog' => "Row {$rowNumber}: ".$validation->errors()->first()]);
                    }
                    $data += $validation->validated();
                    if ($product) {
                        $data['id'] = $product->id;
                    }
                    $saved = $this->commerce->saveProduct($site, $data);
                    if ($product) {
                        $updatedIds[$saved->id] = true;
                    } else {
                        $createdIds[$saved->id] = true;
                    }
                }

                return ['created' => count($createdIds), 'updated' => count(array_diff_key($updatedIds, $createdIds))];
            });
        } finally {
            fclose($handle);
        }
    }

    private function optionalMoney(mixed $value, int $rowNumber): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0) {
            throw ValidationException::withMessages(['catalog' => "Row {$rowNumber} has an invalid wholesale price."]);
        }

        return $value;
    }

    private function boolean(mixed $value, bool $default = false): bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /** @return array<int,string> */
    private function imageMedia(mixed $value, int $rowNumber): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        if (! filter_var($value, FILTER_VALIDATE_URL) || ! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw ValidationException::withMessages(['catalog' => "Row {$rowNumber} has an invalid image URL."]);
        }

        return [$value];
    }
}
