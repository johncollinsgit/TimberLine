<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyCliAdminClient;
use App\Services\Shopify\WholesaleCandlePriceVariantClassifier;
use Illuminate\Support\Facades\File;

class ShopifyUpdateWholesaleCandlePrices extends ShopifyUpdateCandlePrices
{
    protected $signature = 'shopify:update-wholesale-candle-prices
        {--store= : Store domain to audit and update. Defaults to the configured wholesale store}
        {--apply : Apply live price changes after building the audit workbook}
        {--output= : Optional final workbook path}
        {--page-size=100 : Products fetched per Admin API request}
        {--limit= : Optional product limit for debugging or partial audits}';

    protected $description = 'Audit and optionally update wholesale candle pricing in Shopify using the local Shopify CLI app auth.';

    public function __construct(
        ShopifyCliAdminClient $shopify,
        WholesaleCandlePriceVariantClassifier $classifier,
    ) {
        parent::__construct($shopify, $classifier);
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
        $base = $outputDirectory.'/shopify-wholesale-candle-price-report-'.$timestamp.'.xlsx';

        return [
            'audit' => $apply ? $this->withSuffix($base, '-audit') : $base,
            'final' => $apply ? $this->withSuffix($base, '-final') : $base,
        ];
    }

    protected function defaultStoreDomain(): string
    {
        return trim((string) (
            config('services.shopify.stores.wholesale.shop')
            ?? config('services.shopify.wholesale.shop')
            ?? ''
        ));
    }

    protected function baselinePriceForCategory(string $category): ?string
    {
        return match ($category) {
            '4 oz candle' => '6.00',
            '8 oz candle' => '9.00',
            '16 oz candle' => '14.00',
            'wax melt' => '3.00',
            '8 oz wood wick candle' => '10.00',
            '16 oz wood wick candle' => '15.00',
            default => null,
        };
    }
}
