<?php

namespace App\Services\Retail;

interface ShippingQuoteProvider
{
    public function quote(array $merchant, array $destination, array $packageGroups, array $shippingPolicy): array;
}
