<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ModernForestryFundraiserInvoiceSettingsService;
use App\Services\Shopify\ModernForestryFundraiserOrderIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ModernForestryFundraiserZapierController extends Controller
{
    public function store(
        Request $request,
        ModernForestryFundraiserInvoiceSettingsService $settings,
        ModernForestryFundraiserOrderIntakeService $intake
    ): JsonResponse {
        $tenant = $intake->modernForestryTenant();
        if (! $settings->hasValidZapierWebhookSecret(
            (int) $tenant->id,
            $request->header('X-Everbranch-Fundraiser-Token')
        )) {
            return response()->json(['ok' => false, 'message' => 'Invalid fundraiser webhook credential.'], 401);
        }

        $configuration = $settings->forTenant((int) $tenant->id);
        if (! (bool) ($configuration['configured'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => 'Fundraiser contacts must be configured in the verified Modern Forestry Shopify app before orders can be accepted.',
            ], 422);
        }

        try {
            $received = $intake->receive($request->json()->all());
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Fundraiser order could not be accepted.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $order = $received['order'];

        return response()->json([
            'ok' => true,
            'message' => $received['duplicate']
                ? 'This fundraiser order was already received; no duplicate was created.'
                : 'Fundraiser order received for manual accounting review.',
            'data' => [
                'order_id' => $order->id,
                'order_reference' => $order->order_reference ?: $order->external_order_id,
                'status' => $order->status,
                'duplicate' => $received['duplicate'],
            ],
        ], $received['duplicate'] ? 200 : 201);
    }
}
