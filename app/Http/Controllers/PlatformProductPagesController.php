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

    public function plans(TenantCommercialExperienceService $experienceService): Response
    {
        return response()->view('platform.plans', $experienceService->publicPlansPayload());
    }

    public function demo(): Response
    {
        return response()->view('platform.access-request', [
            'surface' => (array) config('product_surfaces.demo', []),
            'intent' => 'demo',
        ]);
    }

    public function start(): Response
    {
        return response()->view('platform.access-request', [
            'surface' => (array) config('product_surfaces.start_client', []),
            'intent' => 'production',
        ]);
    }

    public function requestSubmitted(): Response
    {
        $intent = strtolower(trim((string) request()->query('intent', 'production')));

        return response()->view('platform.request-submitted', [
            'intent' => in_array($intent, ['demo', 'production'], true) ? $intent : 'production',
        ]);
    }

    public function catalogFeed(TenantModuleCatalogService $catalogService): JsonResponse
    {
        return response()->json($catalogService->publicCatalogPayload());
    }
}
