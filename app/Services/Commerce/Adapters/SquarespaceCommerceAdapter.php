<?php

namespace App\Services\Commerce\Adapters;

class SquarespaceCommerceAdapter extends AbstractExternalCommerceAdapter
{
    public function provider(): string
    {
        return 'squarespace';
    }

    protected function idPaths(): array
    {
        return ['id', 'productId', 'orderNumber'];
    }

    protected function updatedPaths(): array
    {
        return ['updatedOn', 'updated_at', 'modifiedOn'];
    }

    protected function parentPaths(): array
    {
        return ['productId', 'parentId'];
    }
}
