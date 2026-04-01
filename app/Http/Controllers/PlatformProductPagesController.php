<?php

namespace App\Http\Controllers;

use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PlatformProductPagesController extends Controller
{
    public function promo(TenantCommercialExperienceService $experienceService): Response
    {
        return response()->view('platform.promo', $experienceService->promoPayload());
    }

    public function contact(): Response
    {
        return response()->view('platform.contact', [
            'contact' => (array) config('product_surfaces.contact', []),
        ]);
    }

    public function catalogFeed(TenantModuleCatalogService $catalogService): JsonResponse
    {
        return response()->json($catalogService->publicCatalogPayload());
    }
}
