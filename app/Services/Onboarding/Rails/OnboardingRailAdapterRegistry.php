<?php

namespace App\Services\Onboarding\Rails;

use App\Support\Onboarding\OnboardingRail;
use RuntimeException;

class OnboardingRailAdapterRegistry
{
    /**
     * @param  array<int,OnboardingRailAdapter>  $adapters
     */
    public function __construct(
        protected array $adapters
    ) {
    }

    public function forRail(OnboardingRail $rail): OnboardingRailAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->rail() === $rail) {
                return $adapter;
            }
        }

        throw new RuntimeException('No onboarding rail adapter registered for rail='.$rail->value);
    }
}

