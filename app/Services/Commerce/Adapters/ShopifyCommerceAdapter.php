<?php

namespace App\Services\Commerce\Adapters;

class ShopifyCommerceAdapter extends AbstractExternalCommerceAdapter
{
    public function provider(): string
    {
        return 'shopify';
    }

    protected function idPaths(): array
    {
        return ['admin_graphql_api_id', 'id'];
    }

    protected function updatedPaths(): array
    {
        return ['updated_at', 'updatedAt'];
    }
}
