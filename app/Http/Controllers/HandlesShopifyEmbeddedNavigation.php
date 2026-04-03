<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyEmbeddedShellPayloadBuilder;

trait HandlesShopifyEmbeddedNavigation
{
    protected function embeddedAppNavigation(string $activeSection, ?string $activeChild = null, ?int $tenantId = null): array
    {
        /** @var ShopifyEmbeddedShellPayloadBuilder $payloadBuilder */
        $payloadBuilder = app(ShopifyEmbeddedShellPayloadBuilder::class);

        return $payloadBuilder->appNavigation($activeSection, $activeChild, $tenantId, request());
    }

    /**
     * @return array<string,string>
     */
    protected function embeddedDisplayLabels(?int $tenantId): array
    {
        /** @var ShopifyEmbeddedShellPayloadBuilder $payloadBuilder */
        $payloadBuilder = app(ShopifyEmbeddedShellPayloadBuilder::class);

        return $payloadBuilder->displayLabels($tenantId, request());
    }

    /**
     * @return array<string,array{
     *   module_key:string,
     *   label:string,
     *   classification:string,
     *   has_access:bool,
     *   access_sources:array<int,string>,
     *   setup_status:string,
     *   coming_soon:bool,
     *   ui_state:string,
     *   upgrade_prompt_eligible:bool
     * }>
     */
    protected function embeddedNavigationModuleStates(?int $tenantId): array
    {
        /** @var ShopifyEmbeddedShellPayloadBuilder $payloadBuilder */
        $payloadBuilder = app(ShopifyEmbeddedShellPayloadBuilder::class);

        return $payloadBuilder->moduleStates($tenantId, request());
    }
}
