<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;
use RuntimeException;

class MarketingTouchShopifyPageCache extends Command
{
    protected $signature = 'marketing:touch-shopify-page-cache
        {store=retail : Shopify store key (retail|wholesale)}
        {--handle=rewards : Shopify page handle to touch}
        {--dry-run : Resolve the page id but skip pageUpdate mutation}';

    protected $description = 'Touch a Shopify online store page via Admin API to force canonical storefront cache regeneration.';

    public function handle(): int
    {
        $storeKey = strtolower(trim((string) $this->argument('store')));
        $handle = strtolower(trim((string) $this->option('handle')));
        $dryRun = (bool) $this->option('dry-run');

        if ($storeKey === '' || $handle === '') {
            $this->error('Both store and handle are required.');

            return self::FAILURE;
        }

        $store = ShopifyStores::find($storeKey);
        if (! $store) {
            foreach (ShopifyStores::unresolvedMessages($storeKey) as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $shop = trim((string) ($store['shop'] ?? ''));
        $token = trim((string) ($store['token'] ?? ''));
        if ($shop === '' || $token === '') {
            $this->error("Store '{$storeKey}' is missing shop domain or access token.");

            return self::FAILURE;
        }

        $client = new ShopifyGraphqlClient(
            shopDomain: $shop,
            accessToken: $token,
            apiVersion: (string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01'))
        );

        $page = $this->lookupPage($client, $handle);
        if (! $page) {
            $this->error("No Shopify page found for handle '{$handle}' on {$shop}.");

            return self::FAILURE;
        }

        $pageId = (string) ($page['id'] ?? '');
        $title = (string) ($page['title'] ?? '');
        $body = (string) ($page['body'] ?? '');

        $this->line("store={$storeKey}");
        $this->line("shop={$shop}");
        $this->line("handle={$handle}");
        $this->line("page_id={$pageId}");
        $this->line('mode=' . ($dryRun ? 'dry-run' : 'touch'));

        if ($dryRun) {
            return self::SUCCESS;
        }

        $updatedAt = $this->touchPage($client, $pageId, $title, $body);

        $this->info('Shopify page touched successfully.');
        if ($updatedAt !== '') {
            $this->line("updated_at={$updatedAt}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function lookupPage(ShopifyGraphqlClient $client, string $handle): ?array
    {
        $query = <<<'GQL'
query TouchPageLookup($query: String!) {
  pages(first: 1, query: $query) {
    nodes {
      id
      handle
      title
      body
      updatedAt
    }
  }
}
GQL;

        $payload = $client->query($query, [
            'query' => 'handle:' . $handle,
        ]);

        $nodes = data_get($payload, 'pages.nodes');
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }

        $page = $nodes[0];

        return is_array($page) ? $page : null;
    }

    protected function touchPage(
        ShopifyGraphqlClient $client,
        string $pageId,
        string $title,
        string $body
    ): string {
        $mutation = <<<'GQL'
mutation TouchPage($id: ID!, $page: PageUpdateInput!) {
  pageUpdate(id: $id, page: $page) {
    page {
      id
      updatedAt
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

        $payload = $client->query($mutation, [
            'id' => $pageId,
            'page' => [
                'title' => $title,
                'body' => $body,
            ],
        ]);

        $errors = data_get($payload, 'pageUpdate.userErrors', []);
        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)
                ->map(function ($error): string {
                    if (! is_array($error)) {
                        return trim((string) $error);
                    }

                    $field = is_array($error['field'] ?? null)
                        ? implode('.', array_map('strval', $error['field']))
                        : trim((string) ($error['field'] ?? ''));
                    $detail = trim((string) ($error['message'] ?? 'unknown_error'));

                    return $field !== '' ? "{$field}: {$detail}" : $detail;
                })
                ->filter()
                ->values()
                ->implode(' | ');

            throw new RuntimeException('Shopify page touch failed: ' . ($message !== '' ? $message : 'unknown error'));
        }

        return trim((string) data_get($payload, 'pageUpdate.page.updatedAt', ''));
    }
}
