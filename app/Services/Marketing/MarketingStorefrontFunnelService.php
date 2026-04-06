<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketingStorefrontFunnelService
{
    /**
     * @var array<string,string>
     */
    protected array $eventAliases = [
        'session_start' => 'session_started',
        'session_started' => 'session_started',
        'landing_page' => 'landing_page_viewed',
        'landing_page_view' => 'landing_page_viewed',
        'landing_page_viewed' => 'landing_page_viewed',
        'page_view' => 'landing_page_viewed',
        'product_view' => 'product_viewed',
        'product_viewed' => 'product_viewed',
        'wishlist_add' => 'wishlist_added',
        'wishlist_added' => 'wishlist_added',
        'cart_add' => 'add_to_cart',
        'add_to_cart' => 'add_to_cart',
        'checkout_start' => 'checkout_started',
        'checkout_started' => 'checkout_started',
        'checkout_complete' => 'checkout_completed',
        'checkout_completed' => 'checkout_completed',
        'purchase' => 'checkout_completed',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @param  array{store_key:?string,tenant_id:?int}  $storeContext
     * @return array{event:MarketingStorefrontEvent,event_type:string,state:string,identity_status:string}
     */
    public function record(
        Request $request,
        array $payload,
        array $storeContext,
        ?MarketingProfile $profile = null
    ): array {
        $eventType = $this->normalizeEventType($payload['event_type'] ?? null);
        if ($eventType === null) {
            throw new \InvalidArgumentException('Unsupported funnel event type.');
        }

        $storeKey = $this->nullableString($storeContext['store_key'] ?? null);
        if ($storeKey === null) {
            throw new \InvalidArgumentException('Storefront funnel tracking requires a verified store context.');
        }

        $tenantId = is_numeric($storeContext['tenant_id'] ?? null) && (int) ($storeContext['tenant_id'] ?? 0) > 0
            ? (int) $storeContext['tenant_id']
            : (is_numeric($profile?->tenant_id ?? null) && (int) ($profile?->tenant_id ?? 0) > 0 ? (int) $profile->tenant_id : null);

        $pageUrl = $this->nullableString($payload['page_url'] ?? $payload['landing_page'] ?? $payload['current_url'] ?? null);
        $landingPage = $this->nullableString($payload['landing_page'] ?? null) ?? $pageUrl;
        $referrer = $this->nullableString($payload['referrer'] ?? $payload['referring_site'] ?? null);
        $campaignSignals = $this->campaignSignalsFromUrl($pageUrl)
            ?: $this->campaignSignalsFromUrl($landingPage)
            ?: $this->campaignSignalsFromUrl($referrer);

        $sessionKey = $this->nullableString($payload['session_key'] ?? $payload['session_id'] ?? null);
        $checkoutToken = $this->nullableString($payload['checkout_token'] ?? null);
        $cartToken = $this->nullableString($payload['cart_token'] ?? null);
        $requestKey = $this->nullableString($payload['request_key'] ?? null);
        $productId = $this->nullableString($payload['product_id'] ?? null)
            ?? $this->nullableString($campaignSignals['mf_product_id'] ?? null);
        $moduleType = $this->nullableString($payload['module_type'] ?? null)
            ?? $this->nullableString($campaignSignals['mf_module_type'] ?? null);
        $linkLabel = $this->nullableString($payload['link_label'] ?? null)
            ?? $this->nullableString($campaignSignals['mf_link_label'] ?? null);

        $meta = array_filter([
            'store_key' => $storeKey,
            'shop_domain' => $this->nullableString($payload['shop'] ?? $request->query('shop') ?? $request->header('X-Shopify-Shop-Domain')),
            'page_url' => $pageUrl,
            'page_path' => $this->urlPath($pageUrl),
            'landing_page' => $landingPage,
            'landing_path' => $this->urlPath($landingPage),
            'referrer' => $referrer,
            'referrer_path' => $this->urlPath($referrer),
            'page_type' => $this->nullableString($payload['page_type'] ?? null),
            'session_key' => $sessionKey,
            'client_id' => $this->nullableString($payload['client_id'] ?? null),
            'guest_token' => $this->nullableString($payload['guest_token'] ?? null),
            'product_id' => $productId,
            'product_handle' => $this->nullableString($payload['product_handle'] ?? null),
            'product_title' => $this->nullableString($payload['product_title'] ?? null),
            'variant_id' => $this->nullableString($payload['variant_id'] ?? null),
            'quantity' => $this->positiveInt($payload['quantity'] ?? null),
            'cart_token' => $cartToken,
            'checkout_token' => $checkoutToken,
            'currency' => $this->nullableString($payload['currency'] ?? null),
            'value_cents' => $this->positiveInt($payload['value_cents'] ?? null),
            'module_type' => $moduleType,
            'module_position' => $this->positiveInt($payload['module_position'] ?? ($campaignSignals['mf_module_position'] ?? null)),
            'tile_position' => $this->positiveInt($payload['tile_position'] ?? ($campaignSignals['mf_tile_position'] ?? null)),
            'link_label' => $linkLabel,
            'utm_source' => $this->nullableString($campaignSignals['utm_source'] ?? null),
            'utm_medium' => $this->nullableString($campaignSignals['utm_medium'] ?? null),
            'utm_campaign' => $this->nullableString($campaignSignals['utm_campaign'] ?? null),
            'utm_content' => $this->nullableString($campaignSignals['utm_content'] ?? null),
            'utm_term' => $this->nullableString($campaignSignals['utm_term'] ?? null),
            'mf_channel' => $this->nullableString($campaignSignals['mf_channel'] ?? null),
            'mf_source_label' => $this->nullableString($campaignSignals['mf_source_label'] ?? null),
            'mf_template_key' => $this->nullableString($campaignSignals['mf_template_key'] ?? null),
            'mf_campaign_id' => $this->positiveInt($campaignSignals['mf_campaign_id'] ?? null),
            'mf_delivery_id' => $this->positiveInt($campaignSignals['mf_delivery_id'] ?? null),
            'mf_profile_id' => $this->positiveInt($campaignSignals['mf_profile_id'] ?? null),
            'mf_campaign_recipient_id' => $this->positiveInt($campaignSignals['mf_campaign_recipient_id'] ?? null),
            'mf_module_type' => $this->nullableString($campaignSignals['mf_module_type'] ?? null),
            'mf_module_position' => $this->positiveInt($campaignSignals['mf_module_position'] ?? null),
            'mf_product_id' => $this->nullableString($campaignSignals['mf_product_id'] ?? null),
            'mf_tile_position' => $this->positiveInt($campaignSignals['mf_tile_position'] ?? null),
            'mf_link_label' => $this->nullableString($campaignSignals['mf_link_label'] ?? null),
            'properties' => is_array($payload['properties'] ?? null) ? $payload['properties'] : null,
            'payload_meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : null,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        $identifier = $checkoutToken
            ?? $cartToken
            ?? ($productId !== null ? $eventType . ':' . $productId : null)
            ?? ($sessionKey !== null ? $eventType . ':' . $sessionKey : null)
            ?? $eventType;

        $event = MarketingStorefrontEvent::query()->create([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'status' => 'ok',
            'source_surface' => 'shopify_storefront',
            'endpoint' => '/' . ltrim((string) $request->path(), '/'),
            'request_key' => $requestKey,
            'signature_mode' => (string) $request->attributes->get('marketing_storefront_auth_mode', 'unknown'),
            'marketing_profile_id' => $profile?->id,
            'source_type' => 'shopify_storefront_funnel',
            'source_id' => $identifier,
            'meta' => $meta,
            'occurred_at' => $this->asDateTime($payload['occurred_at'] ?? null) ?? now(),
            'resolution_status' => 'resolved',
        ]);

        return [
            'event' => $event,
            'event_type' => $eventType,
            'state' => $eventType,
            'identity_status' => $profile ? 'resolved' : 'anonymous',
        ];
    }

    protected function normalizeEventType(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $this->eventAliases[$normalized] ?? null;
    }

    /**
     * @return array<string,string>
     */
    protected function campaignSignalsFromUrl(?string $url): array
    {
        if ($url === null) {
            return [];
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['query'])) {
            return [];
        }

        parse_str((string) $parts['query'], $query);
        if (! is_array($query)) {
            return [];
        }

        $signals = [];
        foreach ([
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'mf_channel',
            'mf_source_label',
            'mf_template_key',
            'mf_campaign_id',
            'mf_delivery_id',
            'mf_profile_id',
            'mf_campaign_recipient_id',
            'mf_module_type',
            'mf_module_position',
            'mf_product_id',
            'mf_tile_position',
            'mf_link_label',
        ] as $key) {
            $value = $this->nullableString($query[$key] ?? null);
            if ($value !== null) {
                $signals[$key] = $value;
            }
        }

        return $signals;
    }

    protected function urlPath(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        return $this->nullableString($parts['path'] ?? null);
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function asDateTime(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
