<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\Http\Client\Response;

/** One attempt per operation; ambiguous mutations require reconciliation, not a blind retry. */
final class PosBirthdayShopifyClient extends ShopifyGraphqlClient
{
    protected function requestWithRetry(string $url, array $payload): Response
    {
        return $this->request()->post($url, $payload)->throw();
    }
}
