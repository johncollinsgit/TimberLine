<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingMessageDelivery;
use App\Services\Marketing\EmbeddedMessagingCampaignDispatchService;
use App\Services\Marketing\MarketingDeliveryTrackingService;
use App\Services\Marketing\Messaging\TenantMessagingAccountResolver;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Marketing\TwilioIncomingMessageService;
use App\Services\Marketing\TwilioProviderClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
    public function status(
        Request $request,
        TwilioProviderClient $twilioSmsService,
        TenantMessagingGateway $messagingGateway,
        TenantMessagingAccountResolver $accountResolver,
        MarketingDeliveryTrackingService $trackingService,
        EmbeddedMessagingCampaignDispatchService $dispatchService
    ): JsonResponse {
        $payload = $request->all();

        $signature = (string) $request->header('X-Twilio-Signature', '');
        $requestUrl = (string) $request->fullUrl();
        $accountSid = trim((string) ($payload['AccountSid'] ?? ''));
        $tenantAccount = (bool) config('features.tenant_messaging_platform') && $accountSid !== ''
            ? $messagingGateway->validateTwilioCallback($accountSid, $requestUrl, $payload, $signature)
            : null;
        $legacyAccountSid = trim((string) config('marketing.twilio.account_sid'));
        $legacyAllowed = ! (bool) config('features.tenant_messaging_platform') || $accountResolver->isLegacyTenant(1);
        $validLegacySignature = $tenantAccount === null && $legacyAllowed
            && ($accountSid === '' || $accountSid === $legacyAccountSid)
            && $twilioSmsService->validateSignature($requestUrl, $payload, $signature);
        if ($tenantAccount === null && ! $validLegacySignature) {
            Log::warning('marketing twilio callback signature validation failed', [
                'url' => $requestUrl,
                'payload' => $payload,
            ]);

            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 403);
        }

        $callbackTenantId = $tenantAccount?->tenant_id
            ?? ((bool) config('features.tenant_messaging_platform') ? 1 : null);
        $result = $trackingService->handleTwilioCallback($payload, $callbackTenantId);

        $deliveryId = (int) ($result['delivery_id'] ?? 0);
        if ($deliveryId > 0) {
            $delivery = MarketingMessageDelivery::query()
                ->when($tenantAccount !== null, fn ($query) => $query->where('tenant_id', $tenantAccount->tenant_id))
                ->find($deliveryId);
            if ($delivery instanceof MarketingMessageDelivery) {
                $dispatchService->handleTwilioDeliveryCallback(
                    delivery: $delivery,
                    providerStatus: (string) ($result['status'] ?? ''),
                    errorCode: trim((string) ($payload['ErrorCode'] ?? '')) ?: null,
                    errorMessage: trim((string) ($payload['ErrorMessage'] ?? '')) ?: null
                );
            }
        }

        return response()->json([
            'ok' => true,
            'matched' => (bool) $result['matched'],
            'delivery_id' => $result['delivery_id'],
            'status' => $result['status'],
        ]);
    }

    public function inbound(
        Request $request,
        TwilioProviderClient $twilioSmsService,
        TenantMessagingGateway $messagingGateway,
        TenantMessagingAccountResolver $accountResolver,
        TwilioIncomingMessageService $incomingMessageService
    ): Response {
        $payload = $request->all();

        $signature = (string) $request->header('X-Twilio-Signature', '');
        $requestUrl = (string) $request->fullUrl();
        $accountSid = trim((string) ($payload['AccountSid'] ?? ''));
        $tenantAccount = (bool) config('features.tenant_messaging_platform') && $accountSid !== ''
            ? $messagingGateway->validateTwilioCallback($accountSid, $requestUrl, $payload, $signature)
            : null;
        $legacyAccountSid = trim((string) config('marketing.twilio.account_sid'));
        $legacyAllowed = ! (bool) config('features.tenant_messaging_platform') || $accountResolver->isLegacyTenant(1);
        $validLegacySignature = $tenantAccount === null && $legacyAllowed
            && ($accountSid === '' || $accountSid === $legacyAccountSid)
            && $twilioSmsService->validateSignature($requestUrl, $payload, $signature);
        if ($tenantAccount === null && ! $validLegacySignature) {
            Log::warning('marketing twilio inbound signature validation failed', [
                'url' => $requestUrl,
                'payload' => $payload,
            ]);

            return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 403, [
                'Content-Type' => 'application/xml',
            ]);
        }

        $callbackTenantId = $tenantAccount?->tenant_id
            ?? ((bool) config('features.tenant_messaging_platform') ? 1 : null);
        $incomingMessageService->handleInbound($payload, $callbackTenantId);

        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
