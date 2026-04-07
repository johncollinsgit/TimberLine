<?php

namespace App\Console\Commands;

use App\Services\Shopify\CandlePriceVariantClassifier;
use App\Services\Shopify\ShopifyCliAdminClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

class ShopifyUpdateCandlePrices extends Command
{
    protected $signature = 'shopify:update-candle-prices
        {--store= : Store domain to audit and update. Defaults to the configured retail store}
        {--apply : Apply live price changes after building the audit workbook}
        {--output= : Optional final workbook path}
        {--page-size=100 : Products fetched per Admin API request}
        {--limit= : Optional product limit for debugging or partial audits}';

    protected $description = 'Audit and optionally update candle pricing in Shopify using the local Shopify CLI app auth.';

    public function __construct(
        protected ShopifyCliAdminClient $shopify,
        protected CandlePriceVariantClassifier $classifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! class_exists(Xlsx::class)) {
            $this->error('PhpSpreadsheet is not installed. Install phpoffice/phpspreadsheet to export the Excel report.');

            return self::FAILURE;
        }

        $store = trim((string) $this->option('store'));
        $store = $store !== '' ? $store : $this->defaultStoreDomain();

        if ($store === '') {
            $this->error('No Shopify store domain is configured. Pass --store=modernforestry.myshopify.com.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $pageSize = max(1, min(250, (int) $this->option('page-size')));
        $limit = $this->optionalPositiveInt($this->option('limit'));

        $this->line("store={$store}");
        $this->line('mode='.($apply ? 'apply' : 'audit'));
        $this->line("page_size={$pageSize}");
        $this->line('limit='.($limit ?? 'all'));

        try {
            $products = $this->fetchProducts($store, $pageSize, $limit);
        } catch (Throwable $exception) {
            $this->error('Failed to fetch Shopify products: '.$exception->getMessage());

            return self::FAILURE;
        }

        $plan = $this->buildPlan($products);
        $paths = $this->resolveOutputPaths($apply, $this->option('output'));

        try {
            $this->writeWorkbook(
                $paths['audit'],
                $plan['unchanged_rows'],
                $plan['changed_rows']
            );
        } catch (Throwable $exception) {
            $this->error('Failed to write audit workbook: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Audit workbook written: '.$paths['audit']);
        $this->renderSummary($plan['summary']);

        if (! $apply) {
            return self::SUCCESS;
        }

        if ($plan['changed_rows'] === []) {
            $this->warn('No price changes were required. Copying the audit workbook as the final report.');
            File::copy($paths['audit'], $paths['final']);
            $this->info('Final workbook written: '.$paths['final']);

            return self::SUCCESS;
        }

        foreach ($plan['changed_rows'] as $row) {
            $this->line(sprintf(
                'candidate variant=%s product="%s" variant_title="%s" old=%s new=%s category="%s"',
                (string) ($row['Variant ID'] ?? ''),
                (string) ($row['Product Title'] ?? ''),
                (string) ($row['Variant Title'] ?? ''),
                (string) ($row['Old Price'] ?? ''),
                (string) ($row['New Price'] ?? ''),
                (string) ($row['Detected Category'] ?? '')
            ));
        }

        $applied = $this->applyUpdates($store, $plan['change_groups']);

        $finalUnchangedRows = array_merge($plan['unchanged_rows'], $applied['failed_rows']);
        $finalChangedRows = $applied['updated_rows'];

        try {
            $this->writeWorkbook(
                $paths['final'],
                $finalUnchangedRows,
                $finalChangedRows
            );
        } catch (Throwable $exception) {
            $this->error('Failed to write final workbook: '.$exception->getMessage());

            return self::FAILURE;
        }

        $finalSummary = $this->summarizeRows($finalChangedRows, $finalUnchangedRows);

        $this->info('Final workbook written: '.$paths['final']);
        $this->renderSummary($finalSummary);

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fetchProducts(string $store, int $pageSize, ?int $limit): array
    {
        $query = <<<'GRAPHQL'
query FetchProducts($first: Int!, $after: String) {
  products(first: $first, after: $after, sortKey: ID) {
    pageInfo {
      hasNextPage
      endCursor
    }
    nodes {
      id
      title
      handle
      productType
      tags
      status
      variants(first: 50) {
        nodes {
          id
          title
          price
          selectedOptions {
            name
            value
          }
        }
      }
    }
  }
}
GRAPHQL;

        $products = [];
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $payload = $this->shopify->query($store, $query, [
                'first' => $pageSize,
                'after' => $cursor,
            ]);

            $connection = $payload['products'] ?? null;
            if (! is_array($connection)) {
                throw new RuntimeException('Shopify products connection was missing from the Admin API response.');
            }

            $nodes = $connection['nodes'] ?? [];
            if (! is_array($nodes)) {
                throw new RuntimeException('Shopify products nodes were missing from the Admin API response.');
            }

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $products[] = $node;

                if ($limit !== null && count($products) >= $limit) {
                    $this->line('fetched_products='.count($products));

                    return array_slice($products, 0, $limit);
                }
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $hasNextPage ? (string) ($pageInfo['endCursor'] ?? '') : null;

            $this->line(sprintf(
                'fetched_page=%d cumulative_products=%d',
                $page,
                count($products)
            ));
        } while ($cursor !== null && $cursor !== '');

        return $products;
    }

    /**
     * @param  array<int,array<string,mixed>>  $products
     * @return array{
     *   changed_rows:array<int,array<string,mixed>>,
     *   unchanged_rows:array<int,array<string,mixed>>,
     *   change_groups:array<string,array<int,array<string,mixed>>>,
     *   summary:array<string,mixed>
     * }
     */
    protected function buildPlan(array $products): array
    {
        $changedRows = [];
        $unchangedRows = [];
        $changeGroups = [];

        foreach ($products as $product) {
            $variants = data_get($product, 'variants.nodes', []);
            if (! is_array($variants)) {
                continue;
            }

            foreach ($variants as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $classification = $this->classifier->classify($product, $variant);
                $oldPrice = $this->normalizeMoney($variant['price'] ?? null);
                $newPrice = $classification['new_price'];

                $baseRow = $this->reportRow($product, $variant, [
                    'Detected Category' => $classification['detected_category'],
                    'Old Price' => $oldPrice,
                    'New Price' => $newPrice,
                ]);

                if ($classification['detected_category'] === null || $newPrice === null) {
                    $unchangedRows[] = array_merge($baseRow, [
                        'Status' => 'Unchanged',
                        'Reason' => $classification['reason'],
                    ]);

                    continue;
                }

                if ($oldPrice === $newPrice) {
                    $unchangedRows[] = array_merge($baseRow, [
                        'Status' => 'Unchanged',
                        'Reason' => 'already at target price',
                    ]);

                    continue;
                }

                if (! $this->isBaselineEligible((string) $classification['detected_category'], $oldPrice)) {
                    $unchangedRows[] = array_merge($baseRow, [
                        'Status' => 'Unchanged',
                        'Reason' => 'special pricing context',
                    ]);

                    continue;
                }

                $changedRow = array_merge($baseRow, [
                    'Status' => 'Planned',
                    'Reason' => 'matched candle pricing rule',
                    '_product_id' => (string) ($product['id'] ?? ''),
                    '_variant_id' => (string) ($variant['id'] ?? ''),
                ]);

                $changedRows[] = $changedRow;
                $changeGroups[(string) ($product['id'] ?? '')][] = $changedRow;
            }
        }

        $this->sortRows($unchangedRows);
        $this->sortRows($changedRows);

        return [
            'changed_rows' => $changedRows,
            'unchanged_rows' => $unchangedRows,
            'change_groups' => $changeGroups,
            'summary' => $this->summarizeRows($changedRows, $unchangedRows),
        ];
    }

    /**
     * @param  array<string,array<int,array<string,mixed>>>  $changeGroups
     * @return array{
     *   updated_rows:array<int,array<string,mixed>>,
     *   failed_rows:array<int,array<string,mixed>>
     * }
     */
    protected function applyUpdates(string $store, array $changeGroups): array
    {
        $mutation = <<<'GRAPHQL'
mutation UpdateProductVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(
    productId: $productId
    variants: $variants
    allowPartialUpdates: false
  ) {
    productVariants {
      id
      title
      price
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $updatedRows = [];
        $failedRows = [];

        foreach ($changeGroups as $productId => $rows) {
            if ($productId === '') {
                foreach ($rows as $row) {
                    $failedRows[] = array_merge($this->stripInternalKeys($row), [
                        'Status' => 'Unchanged',
                        'Reason' => 'missing Shopify product id for update',
                    ]);
                }

                continue;
            }

            $variables = [
                'productId' => $productId,
                'variants' => array_values(array_map(
                    static fn (array $row): array => [
                        'id' => (string) ($row['_variant_id'] ?? ''),
                        'price' => (string) ($row['New Price'] ?? ''),
                    ],
                    $rows
                )),
            ];

            try {
                $payload = $this->shopify->mutation($store, $mutation, $variables);
                $result = data_get($payload, 'productVariantsBulkUpdate', []);
                $userErrors = is_array($result['userErrors'] ?? null) ? $result['userErrors'] : [];

                if ($userErrors !== []) {
                    $message = collect($userErrors)
                        ->map(function ($error): string {
                            if (! is_array($error)) {
                                return trim((string) $error);
                            }

                            $field = is_array($error['field'] ?? null)
                                ? implode('.', array_map('strval', $error['field']))
                                : null;
                            $text = trim((string) ($error['message'] ?? 'unknown Shopify mutation error'));

                            return $field ? "{$field}: {$text}" : $text;
                        })
                        ->filter()
                        ->implode(' | ');

                    foreach ($rows as $row) {
                        $failedRows[] = array_merge($this->stripInternalKeys($row), [
                            'Status' => 'Unchanged',
                            'Reason' => 'update failed: '.$message,
                        ]);
                    }

                    continue;
                }

                $pricesByVariantId = [];
                $productVariants = is_array($result['productVariants'] ?? null) ? $result['productVariants'] : [];
                foreach ($productVariants as $updatedVariant) {
                    if (! is_array($updatedVariant)) {
                        continue;
                    }

                    $variantId = (string) ($updatedVariant['id'] ?? '');
                    if ($variantId === '') {
                        continue;
                    }

                    $pricesByVariantId[$variantId] = $this->normalizeMoney($updatedVariant['price'] ?? null);
                }

                foreach ($rows as $row) {
                    $variantId = (string) ($row['_variant_id'] ?? '');
                    $reportedPrice = $pricesByVariantId[$variantId] ?? (string) ($row['New Price'] ?? '');

                    $updatedRows[] = array_merge($this->stripInternalKeys($row), [
                        'New Price' => $reportedPrice,
                        'Status' => 'Updated',
                        'Reason' => 'price updated',
                    ]);
                }
            } catch (Throwable $exception) {
                foreach ($rows as $row) {
                    $failedRows[] = array_merge($this->stripInternalKeys($row), [
                        'Status' => 'Unchanged',
                        'Reason' => 'update failed: '.$exception->getMessage(),
                    ]);
                }
            }
        }

        $this->sortRows($failedRows);
        $this->sortRows($updatedRows);

        return [
            'updated_rows' => $updatedRows,
            'failed_rows' => $failedRows,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $unchangedRows
     * @param  array<int,array<string,mixed>>  $changedRows
     */
    protected function writeWorkbook(string $path, array $unchangedRows, array $changedRows): void
    {
        $spreadsheet = new Spreadsheet();
        $unchangedSheet = $spreadsheet->getActiveSheet();
        $unchangedSheet->setTitle("Didn't Change");
        $this->fillSheet($unchangedSheet, $unchangedRows);

        $changedSheet = $spreadsheet->createSheet();
        $changedSheet->setTitle('Changed Prices');
        $this->fillSheet($changedSheet, $changedRows);

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    protected function fillSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows): void
    {
        $headers = $this->reportHeaders();
        $sheet->fromArray($headers, null, 'A1');

        $headerRange = 'A1:'.$sheet->getHighestColumn().'1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(
                array_map(
                    static fn (string $header): mixed => $row[$header] ?? '',
                    $headers
                ),
                null,
                'A'.$rowIndex
            );

            $rowIndex++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @return array<int,string>
     */
    protected function reportHeaders(): array
    {
        return [
            'Product Title',
            'Handle',
            'Product Type',
            'Tags',
            'Product Status',
            'Variant ID',
            'Variant Title',
            'Option Values',
            'Detected Category',
            'Old Price',
            'New Price',
            'Status',
            'Reason',
        ];
    }

    /**
     * @param  array<string,mixed>  $product
     * @param  array<string,mixed>  $variant
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function reportRow(array $product, array $variant, array $overrides = []): array
    {
        $selectedOptions = data_get($variant, 'selectedOptions', []);
        $optionValues = [];

        if (is_array($selectedOptions)) {
            foreach ($selectedOptions as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $name = trim((string) ($option['name'] ?? ''));
                $value = trim((string) ($option['value'] ?? ''));
                $formatted = trim(implode(': ', array_filter([$name, $value])));

                if ($formatted !== '') {
                    $optionValues[] = $formatted;
                }
            }
        }

        return array_merge([
            'Product Title' => (string) ($product['title'] ?? ''),
            'Handle' => (string) ($product['handle'] ?? ''),
            'Product Type' => (string) ($product['productType'] ?? ''),
            'Tags' => implode(', ', is_array($product['tags'] ?? null) ? $product['tags'] : []),
            'Product Status' => (string) ($product['status'] ?? ''),
            'Variant ID' => (string) ($variant['id'] ?? ''),
            'Variant Title' => (string) ($variant['title'] ?? ''),
            'Option Values' => implode(' | ', $optionValues),
            'Detected Category' => '',
            'Old Price' => '',
            'New Price' => '',
            'Status' => '',
            'Reason' => '',
        ], $overrides);
    }

    /**
     * @param  array<int,array<string,mixed>>  $changedRows
     * @param  array<int,array<string,mixed>>  $unchangedRows
     * @return array<string,mixed>
     */
    protected function summarizeRows(array $changedRows, array $unchangedRows): array
    {
        $byReason = [];

        foreach ($unchangedRows as $row) {
            $reason = trim((string) ($row['Reason'] ?? ''));
            $reason = $reason !== '' ? $reason : 'unspecified';
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }

        ksort($byReason);

        return [
            'total_scanned' => count($changedRows) + count($unchangedRows),
            'total_changed' => count($changedRows),
            'total_unchanged' => count($unchangedRows),
            'unchanged_by_reason' => $byReason,
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    protected function renderSummary(array $summary): void
    {
        $this->line('total_scanned='.(int) ($summary['total_scanned'] ?? 0));
        $this->line('total_changed='.(int) ($summary['total_changed'] ?? 0));
        $this->line('total_unchanged='.(int) ($summary['total_unchanged'] ?? 0));

        $byReason = is_array($summary['unchanged_by_reason'] ?? null)
            ? $summary['unchanged_by_reason']
            : [];

        foreach ($byReason as $reason => $count) {
            $this->line('unchanged_reason['.$reason.']='.(int) $count);
        }
    }

    /**
     * @param  mixed  $outputOption
     * @return array{audit:string,final:string}
     */
    protected function resolveOutputPaths(bool $apply, mixed $outputOption): array
    {
        $output = is_scalar($outputOption) ? trim((string) $outputOption) : '';
        $outputDirectory = base_path('output');
        File::ensureDirectoryExists($outputDirectory);

        if ($output !== '') {
            $finalPath = $this->normalizeOutputPath($output);
            File::ensureDirectoryExists(dirname($finalPath));

            return [
                'audit' => $apply ? $this->withSuffix($finalPath, '-audit') : $finalPath,
                'final' => $apply ? $finalPath : $finalPath,
            ];
        }

        $timestamp = now()->setTimezone(config('app.timezone', 'UTC'))->format('Ymd_His');
        $base = $outputDirectory.'/shopify-candle-price-report-'.$timestamp.'.xlsx';

        return [
            'audit' => $apply ? $this->withSuffix($base, '-audit') : $base,
            'final' => $apply ? $this->withSuffix($base, '-final') : $base,
        ];
    }

    protected function normalizeOutputPath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path(trim($path, '/'));
    }

    protected function withSuffix(string $path, string $suffix): string
    {
        $directory = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $extension = $extension !== '' ? '.'.$extension : '';

        return $directory.'/'.$filename.$suffix.$extension;
    }

    protected function defaultStoreDomain(): string
    {
        return trim((string) (
            config('services.shopify.stores.retail.shop')
            ?? config('services.shopify.retail.shop')
            ?? ''
        ));
    }

    protected function normalizeMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }

    protected function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    protected function isBaselineEligible(string $category, string $oldPrice): bool
    {
        if ($category === '' || $oldPrice === '') {
            return false;
        }

        $baseline = $this->baselinePriceForCategory($category);

        return $baseline !== null && $oldPrice === $baseline;
    }

    protected function baselinePriceForCategory(string $category): ?string
    {
        return match ($category) {
            '4 oz candle' => '12.00',
            '8 oz candle' => '18.00',
            '16 oz candle' => '28.00',
            'wax melt' => '6.00',
            '8 oz wood wick candle' => '20.00',
            '16 oz wood wick candle' => '30.00',
            default => null,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    protected function sortRows(array &$rows): void
    {
        usort($rows, function (array $left, array $right): int {
            return [
                (string) ($left['Product Title'] ?? ''),
                (string) ($left['Variant Title'] ?? ''),
                (string) ($left['Variant ID'] ?? ''),
            ] <=> [
                (string) ($right['Product Title'] ?? ''),
                (string) ($right['Variant Title'] ?? ''),
                (string) ($right['Variant ID'] ?? ''),
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function stripInternalKeys(array $row): array
    {
        unset($row['_product_id'], $row['_variant_id']);

        return $row;
    }
}
