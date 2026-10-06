<?php

namespace App\Services\Commerce\Adapters;

class WixCommerceAdapter extends AbstractExternalCommerceAdapter
{
    public function provider(): string
    {
        return 'wix';
    }

    protected function idPaths(): array
    {
        return ['_id', 'id'];
    }

    protected function updatedPaths(): array
    {
        return ['_updatedDate', 'updatedDate', 'updated_at'];
    }

    protected function parentPaths(): array
    {
        return ['productId', 'parentId'];
    }
}
