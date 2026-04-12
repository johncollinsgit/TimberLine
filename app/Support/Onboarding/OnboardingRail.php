<?php

namespace App\Support\Onboarding;

enum OnboardingRail: string
{
    case Shopify = 'shopify';
    case Direct = 'direct';

    public function matchesVisibility(string $visibility): bool
    {
        $normalized = strtolower(trim($visibility));

        if ($normalized === '' || $normalized === 'both') {
            return true;
        }

        return $normalized === $this->value;
    }
}

