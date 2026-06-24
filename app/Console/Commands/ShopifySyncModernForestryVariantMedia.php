<?php

namespace App\Console\Commands;

use App\Services\Shopify\ModernForestryVariantMediaClassifier;
use App\Services\Shopify\ShopifyCliAdminClient;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ShopifySyncModernForestryVariantMedia extends Command
{
    protected $signature = 'shopify:sync-modern-forestry-variant-media
        {--store=retail : Shopify store key or myshopify domain}
        {--transport=admin-token : Shopify GraphQL transport: admin-token or cli}
        {--apply : Apply live Shopify media changes}
        {--image-dir=/Users/johncollins/Downloads : Directory containing 4oz.png, 8oz.png, 16oz.png, Wood Wick.png, and Wax Melt.png}
        {--page-size=50 : Products fetched per Admin API request}
        {--limit= : Optional product limit for partial audits}';

    protected $description = 'Audit and optionally attach Modern Forestry canonical size/form images to matching Shopify variants.';

    public function __construct(
        protected ModernForestryVariantMediaClassifier $classifier
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $pageSize = max(1, min(100, (int) $this->option('page-size')));
        $limit = $this->positiveInt($this->option('limit'));
        $imageDir = (string) $this->option('image-dir');
        $transport = $this->transport();

        try {
            $store = $this->resolveStore((string) $this->option('store'), requiresToken: $transport === 'admin-token');
            $images = $this->imageFiles($imageDir, requirePresent: $apply);
            $client = $this->client($store, $transport);
            $products = $this->fetchProducts($client, $pageSize, $limit);
            $plan = $this->buildPlan($products);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('store='.$store['shop']);
        $this->line('transport='.$transport);
        $this->line('mode='.($apply ? 'apply' : 'dry-run'));
        $this->line('products_scanned='.count($products));
        $this->renderSummary($plan, $images);

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to upload and append missing variant media.');

            return self::SUCCESS;
        }

        try {
            $applied = $this->applyPlan($client, $plan, $images);
        } catch (Throwable $exception) {
            $this->error('Apply failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Applied media updates: products=%d variants=%d uploads=%d',
            $applied['products'],
            $applied['variants'],
            $applied['uploads']
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{shop:string,token:string,api_version:string}
     */
    protected function resolveStore(string $storeOption, bool $requiresToken): array
    {
        $storeOption = trim($storeOption) !== '' ? trim($storeOption) : 'retail';
        $store = ShopifyStores::find($storeOption);

        if (! is_array($store) && str_contains($storeOption, '.')) {
            $retail = ShopifyStores::find('retail');
            if (is_array($retail) && trim((string) ($retail['shop'] ?? '')) === $storeOption) {
                $store = $retail;
            }
        }

        if (! is_array($store)) {
            if (str_contains($storeOption, '.')) {
                return [
                    'shop' => $storeOption,
                    'token' => '',
                    'api_version' => (string) config('services.shopify.api_version', '2026-01'),
                ];
            }

            throw new RuntimeException('Shopify store is not configured: '.$storeOption);
        }

        $shop = trim((string) ($store['shop'] ?? ''));
        $token = trim((string) ($store['token'] ?? ''));

        if ($shop === '' || ($requiresToken && $token === '')) {
            throw new RuntimeException('Shopify store credentials are incomplete for '.$storeOption);
        }

        return [
            'shop' => $shop,
            'token' => $token,
            'api_version' => (string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01')),
        ];
    }

    protected function transport(): string
    {
        $transport = trim((string) $this->option('transport'));
        $transport = $transport !== '' ? $transport : 'admin-token';

        if (! in_array($transport, ['admin-token', 'cli'], true)) {
            throw new RuntimeException('Unsupported transport: '.$transport.'. Use admin-token or cli.');
        }

        return $transport;
    }

    /**
     * @param  array{shop:string,token:string,api_version:string}  $store
     */
    protected function client(array $store, string $transport): ShopifyGraphqlClient|ShopifyCliAdminClient
    {
        if ($transport === 'cli') {
            return new ShopifyCliAdminClient(base_path(), $store['api_version']);
        }

        return new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']);
    }

    /**
     * @return array<string,string>
     */
    protected function imageFiles(string $imageDir, bool $requirePresent): array
    {
        $images = $this->classifier->imageFiles($imageDir);

        foreach ($images as $canonical => $path) {
            if (! File::exists($path)) {
                $message = "Missing {$canonical} image: {$path}";
                if ($requirePresent) {
                    throw new RuntimeException($message);
                }

                $this->warn($message);
            }
        }

        return $images;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fetchProducts(ShopifyGraphqlClient|ShopifyCliAdminClient $client, int $pageSize, ?int $limit): array
    {
        $products = [];
        $after = null;

        do {
            $variables = ['first' => $pageSize];
            if ($after !== null) {
                $variables['after'] = $after;
            }

            $data = $this->query($client, $this->productsQuery(), $variables);
            $connection = $data['products'] ?? [];
            $nodes = is_array($connection) ? ($connection['nodes'] ?? []) : [];

            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    if (is_array($node)) {
                        $products[] = $node;
                    }

                    if ($limit !== null && count($products) >= $limit) {
                        break 2;
                    }
                }
            }

            $pageInfo = is_array($connection) ? ($connection['pageInfo'] ?? []) : [];
            $after = is_scalar($pageInfo['endCursor'] ?? null) ? (string) $pageInfo['endCursor'] : null;
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
        } while ($hasNextPage && $after !== null);

        return $products;
    }

    /**
     * @param  array<int,array<string,mixed>>  $products
     * @return array{products:array<string,array<string,mixed>>,matched:int,already:int,missing:int,skipped:int,ambiguous:int}
     */
    public function buildPlan(array $products): array
    {
        $plan = [
            'products' => [],
            'matched' => 0,
            'already' => 0,
            'missing' => 0,
            'skipped' => 0,
            'ambiguous' => 0,
        ];

        foreach ($products as $product) {
            $productId = (string) ($product['id'] ?? '');
            $productTitle = (string) ($product['title'] ?? '');
            $productMedia = $this->mediaByCanonical(data_get($product, 'media.nodes', []));
            $variants = data_get($product, 'variants.nodes', []);

            if ($productId === '' || ! is_array($variants)) {
                continue;
            }

            foreach ($variants as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $variantTitle = $this->variantTitle($variant);
                if ($this->classifier->isAmbiguous($variantTitle)) {
                    $plan['ambiguous']++;
                    $this->line('ambiguous variant skipped: product="'.$productTitle.'" variant="'.$variantTitle.'"');
                    continue;
                }

                $canonical = $this->classifier->classify($variantTitle);
                if ($canonical === null) {
                    $plan['skipped']++;
                    continue;
                }

                $plan['matched']++;
                $variantHasMedia = $this->mediaContainsCanonical(data_get($variant, 'media.nodes', []), $canonical);

                if ($variantHasMedia) {
                    $plan['already']++;
                    continue;
                }

                $plan['missing']++;
                $plan['products'][$productId] ??= [
                    'id' => $productId,
                    'title' => $productTitle,
                    'handle' => (string) ($product['handle'] ?? ''),
                    'media' => $productMedia,
                    'variants' => [],
                ];

                $plan['products'][$productId]['variants'][] = [
                    'id' => (string) ($variant['id'] ?? ''),
                    'title' => (string) ($variant['title'] ?? ''),
                    'canonical' => $canonical,
                    'needsProductMedia' => ! isset($productMedia[$canonical]),
                ];
            }
        }

        return $plan;
    }

    /**
     * @param  array{products:array<string,array<string,mixed>>}  $plan
     * @param  array<string,string>  $images
     * @return array{products:int,variants:int,uploads:int}
     */
    protected function applyPlan(ShopifyGraphqlClient|ShopifyCliAdminClient $client, array $plan, array $images): array
    {
        $updatedProducts = 0;
        $updatedVariants = 0;
        $uploads = 0;

        foreach ($plan['products'] as $product) {
            $productId = (string) $product['id'];
            $mediaByCanonical = is_array($product['media'] ?? null) ? $product['media'] : [];
            $updatedProduct = false;

            foreach ($product['variants'] as $variant) {
                $canonical = (string) $variant['canonical'];
                $mediaId = $mediaByCanonical[$canonical] ?? null;

                if (! is_string($mediaId) || $mediaId === '') {
                    $mediaId = $this->uploadProductMedia($client, $productId, $canonical, $images[$canonical] ?? null);
                    $mediaByCanonical[$canonical] = $mediaId;
                    $uploads++;
                }

                try {
                    $this->waitForReadyMedia($client, $productId, [$mediaId]);
                    $this->appendVariantMedia($client, $productId, [
                        [
                            'variantId' => (string) $variant['id'],
                            'mediaIds' => [$mediaId],
                        ],
                    ]);
                    $updatedVariants++;
                    $updatedProduct = true;
                } catch (RuntimeException $exception) {
                    if (str_contains($exception->getMessage(), 'already has attached media')) {
                        $this->line('variant already linked, skipping: product="'.$product['title'].'" variant="'.(string) $variant['title'].'" canonical="'.$canonical.'"');
                        continue;
                    }

                    throw $exception;
                }
            }

            if ($updatedProduct) {
                $updatedProducts++;
            }
        }

        return [
            'products' => $updatedProducts,
            'variants' => $updatedVariants,
            'uploads' => $uploads,
        ];
    }

    protected function uploadProductMedia(ShopifyGraphqlClient|ShopifyCliAdminClient $client, string $productId, string $canonical, ?string $path): string
    {
        if ($path === null || ! File::exists($path)) {
            throw new RuntimeException("Missing image file for {$canonical}.");
        }

        $resourceUrl = $this->stagedUpload($client, $path);
        $data = $this->query($client, $this->productUpdateMediaMutation(), [
            'product' => ['id' => $productId],
            'media' => [
                [
                    'originalSource' => $resourceUrl,
                    'mediaContentType' => 'IMAGE',
                    'alt' => $this->classifier->alt($canonical),
                ],
            ],
        ]);

        $this->assertNoUserErrors(data_get($data, 'productUpdate.userErrors', []), 'productUpdate');
        $mediaByCanonical = $this->mediaByCanonical(data_get($data, 'productUpdate.product.media.nodes', []));
        $mediaId = $mediaByCanonical[$canonical] ?? null;

        if (! is_string($mediaId) || $mediaId === '') {
            throw new RuntimeException("Could not resolve uploaded media ID for {$canonical} on product {$productId}.");
        }

        return $mediaId;
    }

    /**
     * @param  array<int,string>  $mediaIds
     */
    protected function waitForReadyMedia(ShopifyGraphqlClient|ShopifyCliAdminClient $client, string $productId, array $mediaIds): void
    {
        $mediaIds = array_values(array_filter(array_unique($mediaIds)));
        if ($mediaIds === []) {
            return;
        }

        $lastStatuses = [];
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $data = $this->query($client, $this->productMediaStatusQuery(), ['id' => $productId]);
            $nodes = data_get($data, 'product.media.nodes', []);
            $statuses = [];

            foreach ((array) $nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $id = (string) ($node['id'] ?? '');
                if (in_array($id, $mediaIds, true)) {
                    $statuses[$id] = (string) ($node['status'] ?? '');
                }
            }

            $lastStatuses = $statuses;
            $readyIds = array_keys(array_filter(
                $statuses,
                static fn (string $status): bool => in_array($status, ['READY', 'UPLOADED'], true)
            ));

            if (count($readyIds) === count($mediaIds)) {
                return;
            }

            if ($attempt < 12) {
                sleep(2);
            }
        }

        throw new RuntimeException(sprintf(
            'Media was not ready for variant attachment on product %s: %s',
            $productId,
            json_encode($lastStatuses, JSON_THROW_ON_ERROR)
        ));
    }

    protected function stagedUpload(ShopifyGraphqlClient|ShopifyCliAdminClient $client, string $path): string
    {
        $filename = basename($path);
        $data = $this->query($client, $this->stagedUploadsMutation(), [
            'input' => [
                [
                    'filename' => $filename,
                    'mimeType' => 'image/png',
                    'httpMethod' => 'POST',
                    'resource' => 'PRODUCT_IMAGE',
                ],
            ],
        ]);

        $this->assertNoUserErrors(data_get($data, 'stagedUploadsCreate.userErrors', []), 'stagedUploadsCreate');
        $target = data_get($data, 'stagedUploadsCreate.stagedTargets.0');

        if (! is_array($target)) {
            throw new RuntimeException('Shopify did not return a staged upload target.');
        }

        $url = (string) ($target['url'] ?? '');
        $resourceUrl = (string) ($target['resourceUrl'] ?? '');
        $parameters = [];

        foreach ((array) ($target['parameters'] ?? []) as $parameter) {
            if (is_array($parameter) && isset($parameter['name'], $parameter['value'])) {
                $parameters[(string) $parameter['name']] = (string) $parameter['value'];
            }
        }

        if ($url === '' || $resourceUrl === '') {
            throw new RuntimeException('Shopify staged upload target was incomplete.');
        }

        $file = fopen($path, 'r');
        if ($file === false) {
            throw new RuntimeException('Could not open image file: '.$path);
        }

        try {
            Http::attach('file', $file, $filename)->post($url, $parameters)->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException('Staged upload failed: '.$exception->getMessage(), 0, $exception);
        } finally {
            fclose($file);
        }

        return $resourceUrl;
    }

    /**
     * @param  array<int,array{variantId:string,mediaIds:array<int,string>}>  $variantMedia
     */
    protected function appendVariantMedia(ShopifyGraphqlClient|ShopifyCliAdminClient $client, string $productId, array $variantMedia): void
    {
        $data = $this->query($client, $this->appendVariantMediaMutation(), [
            'productId' => $productId,
            'variantMedia' => $variantMedia,
        ]);

        $this->assertNoUserErrors(data_get($data, 'productVariantAppendMedia.userErrors', []), 'productVariantAppendMedia');
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    protected function query(ShopifyGraphqlClient|ShopifyCliAdminClient $client, string $query, array $variables = []): array
    {
        if ($client instanceof ShopifyCliAdminClient) {
            return $client->query((string) $this->resolveStore((string) $this->option('store'), requiresToken: false)['shop'], $query, $variables);
        }

        return $client->query($query, $variables);
    }

    /**
     * @param  array<int,mixed>  $errors
     */
    protected function assertNoUserErrors(array $errors, string $operation): void
    {
        if ($errors === []) {
            return;
        }

        $messages = array_map(static function (mixed $error): string {
            if (! is_array($error)) {
                return (string) $error;
            }

            $field = is_array($error['field'] ?? null) ? implode('.', $error['field']) : (string) ($error['field'] ?? '');
            $message = (string) ($error['message'] ?? 'Unknown user error');

            return trim($field.' '.$message);
        }, $errors);

        throw new RuntimeException($operation.' user errors: '.implode(' | ', array_filter($messages)));
    }

    protected function renderSummary(array $plan, array $images): void
    {
        $this->line('matched_variants='.$plan['matched']);
        $this->line('already_attached='.$plan['already']);
        $this->line('missing_media='.$plan['missing']);
        $this->line('skipped_unmatched='.$plan['skipped']);
        $this->line('skipped_ambiguous='.$plan['ambiguous']);
        $this->line('products_needing_writes='.count($plan['products']));

        foreach ($images as $canonical => $path) {
            $this->line('image_'.$canonical.'='.$path);
        }

        foreach ($plan['products'] as $product) {
            $this->line(sprintf(
                'needs product="%s" handle="%s" variants=%d',
                (string) ($product['title'] ?? ''),
                (string) ($product['handle'] ?? ''),
                count((array) ($product['variants'] ?? []))
            ));
        }
    }

    /**
     * @param  array<int,mixed>  $mediaNodes
     * @return array<string,string>
     */
    protected function mediaByCanonical(mixed $mediaNodes): array
    {
        if (! is_array($mediaNodes)) {
            return [];
        }

        $media = [];
        foreach ($mediaNodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            foreach ([
                ModernForestryVariantMediaClassifier::CANONICAL_4OZ,
                ModernForestryVariantMediaClassifier::CANONICAL_8OZ,
                ModernForestryVariantMediaClassifier::CANONICAL_16OZ,
                ModernForestryVariantMediaClassifier::CANONICAL_WOOD_WICK_8OZ,
                ModernForestryVariantMediaClassifier::CANONICAL_WOOD_WICK_16OZ,
                ModernForestryVariantMediaClassifier::CANONICAL_WAX_MELT,
            ] as $canonical) {
                if ($this->nodeHasMarker($node, $canonical)) {
                    $id = (string) ($node['id'] ?? '');
                    if ($id !== '') {
                        $media[$canonical] = $id;
                    }
                }
            }
        }

        return $media;
    }

    protected function mediaContainsCanonical(mixed $mediaNodes, string $canonical): bool
    {
        if (! is_array($mediaNodes)) {
            return false;
        }

        foreach ($mediaNodes as $node) {
            if (is_array($node) && $this->nodeHasMarker($node, $canonical)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $node
     */
    protected function nodeHasMarker(array $node, string $canonical): bool
    {
        $marker = $this->classifier->altMarker($canonical);
        $haystack = implode(' ', array_filter([
            (string) ($node['alt'] ?? ''),
            (string) data_get($node, 'image.altText', ''),
            (string) data_get($node, 'image.url', ''),
        ]));

        return str_contains($haystack, $marker);
    }

    /**
     * @param  array<string,mixed>  $variant
     */
    protected function variantTitle(array $variant): string
    {
        $parts = [(string) ($variant['title'] ?? '')];

        foreach ((array) ($variant['selectedOptions'] ?? []) as $option) {
            if (is_array($option)) {
                $parts[] = (string) ($option['name'] ?? '');
                $parts[] = (string) ($option['value'] ?? '');
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    protected function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function productsQuery(): string
    {
        return <<<'GRAPHQL'
query ModernForestryVariantMediaProducts($first: Int!, $after: String) {
  products(first: $first, after: $after, query: "status:active", sortKey: UPDATED_AT, reverse: true) {
    nodes {
      id
      title
      handle
      media(first: 50) {
        nodes {
          id
          alt
          status
          mediaContentType
          ... on MediaImage {
            image {
              url
              altText
            }
          }
        }
      }
      variants(first: 100) {
        nodes {
          id
          title
          selectedOptions {
            name
            value
          }
          media(first: 10) {
            nodes {
              id
              alt
              mediaContentType
              ... on MediaImage {
                image {
                  url
                  altText
                }
              }
            }
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GRAPHQL;
    }

    protected function stagedUploadsMutation(): string
    {
        return <<<'GRAPHQL'
mutation ModernForestryStagedUploads($input: [StagedUploadInput!]!) {
  stagedUploadsCreate(input: $input) {
    stagedTargets {
      url
      resourceUrl
      parameters {
        name
        value
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;
    }

    protected function productUpdateMediaMutation(): string
    {
        return <<<'GRAPHQL'
mutation ModernForestryProductMedia($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
  productUpdate(product: $product, media: $media) {
    product {
      id
      media(first: 50) {
        nodes {
          id
          alt
          status
          mediaContentType
          ... on MediaImage {
            image {
              url
              altText
            }
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;
    }

    protected function productMediaStatusQuery(): string
    {
        return <<<'GRAPHQL'
query ModernForestryProductMediaStatus($id: ID!) {
  product(id: $id) {
    id
    media(first: 100) {
      nodes {
        id
        status
      }
    }
  }
}
GRAPHQL;
    }

    protected function appendVariantMediaMutation(): string
    {
        return <<<'GRAPHQL'
mutation ModernForestryAppendVariantMedia($productId: ID!, $variantMedia: [ProductVariantAppendMediaInput!]!) {
  productVariantAppendMedia(productId: $productId, variantMedia: $variantMedia) {
    product {
      id
    }
    productVariants {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;
    }
}
