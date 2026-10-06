<?php

namespace App\Services\Commerce\Adapters;

class WooCommerceAdapter extends AbstractExternalCommerceAdapter
{
    public function provider(): string
    {
        return 'woocommerce';
    }

    protected function idPaths(): array
    {
        return ['id', 'guid.rendered'];
    }

    protected function updatedPaths(): array
    {
        return ['date_modified_gmt', 'date_modified', 'modified_gmt'];
    }

    protected function parentPaths(): array
    {
        return ['parent_id', 'product_id'];
    }
}
