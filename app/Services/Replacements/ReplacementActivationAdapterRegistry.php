<?php

namespace App\Services\Replacements;

use App\Models\ReplacementModule;
use App\Services\Replacements\Contracts\ReplacementActivationAdapter;

class ReplacementActivationAdapterRegistry
{
    /** @var array<int,ReplacementActivationAdapter> */
    private array $adapters;

    public function __construct(DatabaseProviderActivationAdapter $databaseProvider)
    {
        $this->adapters = [$databaseProvider];
    }

    public function for(ReplacementModule $module): ?ReplacementActivationAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($module)) {
                return $adapter;
            }
        }

        return null;
    }
}
