<?php

namespace App\Http\Controllers;

use App\Models\ShopifyPrivacyWebhookEvent;
use App\Services\Shopify\ShopifyWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

class ShopifyPrivacyWebhookController extends Controller
{
    public function customersDataRequest(Request $request, ShopifyWebhookVerifier $verifier): Response
    {
        return $this->handle($request, $verifier, 'customers/data_request');
    }

    public function customersRedact(Request $request, ShopifyWebhookVerifier $verifier): Response
    {
        return $this->handle($request, $verifier, 'customers/redact');
    }

    public function shopRedact(Request $request, ShopifyWebhookVerifier $verifier): Response
    {
        return $this->handle($request, $verifier, 'shop/redact');
    }

    protected function handle(Request $request, ShopifyWebhookVerifier $verifier, string $expectedTopic): Response
    {
        $payload = $request->getContent();
        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain')));
        $headerTopic = strtolower(trim((string) $request->header('X-Shopify-Topic')));
        $topic = $headerTopic !== '' ? $headerTopic : $expectedTopic;

        if ($topic !== $expectedTopic) {
            return response('Unexpected topic.', 422);
        }

        if (! $verifier->isValid($payload, $hmac, $shopDomain)) {
            return response('Invalid signature.', 401);
        }

        $data = json_decode($payload, true);
        if (! is_array($data) || $data === []) {
            return response('Invalid payload.', 422);
        }

        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id')) ?: null;
        $attributes = [
            'topic' => $topic,
            'shop_domain' => $this->summaryString($data['shop_domain'] ?? $shopDomain),
            'webhook_id' => $webhookId,
            'payload_hash' => hash('sha256', $payload),
            'payload_summary' => $this->payloadSummary($data, $request, $topic),
            'status' => ShopifyPrivacyWebhookEvent::STATUS_MANUAL_REVIEW_REQUIRED,
            'action_required' => true,
            'handled_at' => now(),
            'notes' => 'Conservative PR 11 handler recorded privacy webhook evidence for manual review; no destructive redaction or deletion was performed.',
        ];

        if ($webhookId !== null) {
            ShopifyPrivacyWebhookEvent::query()->updateOrCreate(
                ['webhook_id' => $webhookId],
                $attributes
            );
        } else {
            ShopifyPrivacyWebhookEvent::query()->updateOrCreate(
                [
                    'topic' => $topic,
                    'payload_hash' => $attributes['payload_hash'],
                ],
                $attributes
            );
        }

        return response('ok', 200);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function payloadSummary(array $payload, Request $request, string $topic): array
    {
        $customer = Arr::get($payload, 'customer');
        $customer = is_array($customer) ? $customer : [];
        $ordersToRedact = Arr::get($payload, 'orders_to_redact');
        $ordersToRedact = is_array($ordersToRedact) ? array_values($ordersToRedact) : [];

        return array_filter([
            'topic' => $topic,
            'shop_id' => $this->summaryString($payload['shop_id'] ?? null),
            'shop_domain' => $this->summaryString($payload['shop_domain'] ?? $request->header('X-Shopify-Shop-Domain')),
            'customer_id' => $this->summaryString($customer['id'] ?? $payload['customer_id'] ?? null),
            'customer_email_hash' => $this->hashIdentifier($customer['email'] ?? $payload['customer_email'] ?? null),
            'customer_phone_hash' => $this->hashIdentifier($customer['phone'] ?? $payload['customer_phone'] ?? null),
            'orders_to_redact_count' => count($ordersToRedact),
            'orders_to_redact' => $this->summaryList($ordersToRedact),
            'webhook_id' => $this->summaryString($request->header('X-Shopify-Webhook-Id')),
            'event_id' => $this->summaryString($request->header('X-Shopify-Event-Id')),
            'api_version' => $this->summaryString($request->header('X-Shopify-API-Version')),
            'triggered_at' => $this->summaryString($request->header('X-Shopify-Triggered-At')),
            'manual_review_required' => true,
            'destructive_action_performed' => false,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function summaryString(mixed $value): ?string
    {
        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function hashIdentifier(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return hash('sha256', $normalized);
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    protected function summaryList(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => $this->summaryString($value),
            array_slice($values, 0, 50)
        )));
    }
}
