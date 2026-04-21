<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingShortLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MessageClickTrackingService
{
    protected const TRACKING_VERSION = 'v1';

    public function __construct(
        protected MarketingLinkShortenerService $linkShortenerService,
        protected MessageOrderAttributionService $messageOrderAttributionService
    ) {
    }

    /**
     * @return array{
     *   message:string,
     *   links:array<int,array{
     *     original:string,
     *     destination_url:string,
     *     short_url:string,
     *     tracked_url:string,
     *     code:string
     *   }>
     * }
     */
    public function decorateSmsMessageForDelivery(
        MarketingMessageDelivery $delivery,
        string $message,
        ?int $createdBy = null
    ): array {
        if (
            trim($message) === ''
            || strtolower(trim((string) ($delivery->channel ?? ''))) !== 'sms'
            || ! (bool) config('marketing.links.enabled', true)
        ) {
            return [
                'message' => $message,
                'links' => [],
            ];
        }

        if (! preg_match_all('/https?:\/\/[^\s<>"\']+/i', $message, $matches, PREG_OFFSET_CAPTURE)) {
            return [
                'message' => $message,
                'links' => [],
            ];
        }

        $updated = $message;
        $links = [];
        $trackedByOriginal = [];
        $occurrences = $matches[0];

        // Replace from right to left so offsets remain stable.
        for ($i = count($occurrences) - 1; $i >= 0; $i--) {
            $raw = (string) ($occurrences[$i][0] ?? '');
            $offset = (int) ($occurrences[$i][1] ?? 0);
            if ($raw === '') {
                continue;
            }

            [$cleanUrl, $suffix] = $this->splitTrailingPunctuation($raw);
            if ($cleanUrl === '' || ! $this->isValidUrl($cleanUrl)) {
                continue;
            }

            $decoratedUrl = $this->enforceSmsAttribution($cleanUrl, $delivery);
            $tracked = $trackedByOriginal[$decoratedUrl] ?? null;
            if ($tracked === null) {
                $resolved = $this->resolveShortLink($decoratedUrl, $createdBy);
                if (! is_array($resolved)) {
                    continue;
                }

                $tracked = [
                    'original' => $cleanUrl,
                    'destination_url' => (string) ($resolved['destination_url'] ?? $decoratedUrl),
                    'short_url' => (string) ($resolved['short_url'] ?? $decoratedUrl),
                    'tracked_url' => $this->trackedShortUrl((string) ($resolved['code'] ?? ''), $delivery),
                    'code' => (string) ($resolved['code'] ?? ''),
                ];

                if ($tracked['code'] === '' || $tracked['tracked_url'] === '') {
                    continue;
                }

                $trackedByOriginal[$decoratedUrl] = $tracked;
                $links[] = $tracked;
            }

            $replacement = $tracked['tracked_url'].$suffix;
            $updated = substr_replace($updated, $replacement, $offset, strlen($raw));
        }

        return [
            'message' => $updated,
            'links' => array_values(array_reverse($links)),
        ];
    }

    public function recordClickFromShortLink(MarketingShortLink $link, Request $request): ?MarketingMessageEngagementEvent
    {
        if (! Schema::hasTable('marketing_message_engagement_events')) {
            return null;
        }

        $tracking = $this->trackingContextFromRequest($request);
        if (! is_array($tracking)) {
            return null;
        }

        $delivery = MarketingMessageDelivery::query()->whereKey((int) $tracking['delivery_id'])->first();
        if (! $delivery instanceof MarketingMessageDelivery) {
            return null;
        }

        if (strtolower(trim((string) ($delivery->channel ?? ''))) !== 'sms') {
            return null;
        }

        $deliveryId = (int) $delivery->id;
        $deliveryProfileId = $this->positiveInt($delivery->marketing_profile_id);
        $deliveryTenantId = $this->positiveInt($delivery->tenant_id);
        $deliveryStoreKey = $this->nullableString($delivery->store_key);

        $profileId = $this->positiveInt($tracking['profile_id'] ?? null) ?? $deliveryProfileId;
        $tenantId = $this->positiveInt($tracking['tenant_id'] ?? null) ?? $deliveryTenantId;
        $storeKey = $this->nullableString($tracking['store_key'] ?? null) ?? $deliveryStoreKey;

        if (
            $this->positiveInt($tracking['profile_id'] ?? null) !== null
            && $deliveryProfileId !== null
            && (int) $tracking['profile_id'] !== $deliveryProfileId
        ) {
            return null;
        }

        if (
            $this->positiveInt($tracking['tenant_id'] ?? null) !== null
            && $deliveryTenantId !== null
            && (int) $tracking['tenant_id'] !== $deliveryTenantId
        ) {
            return null;
        }

        if (
            $this->nullableString($tracking['store_key'] ?? null) !== null
            && $deliveryStoreKey !== null
            && strtolower(trim((string) $tracking['store_key'])) !== strtolower($deliveryStoreKey)
        ) {
            return null;
        }

        $expectedSignature = $this->trackingSignature(
            code: (string) $link->code,
            deliveryId: $deliveryId,
            profileId: $profileId,
            tenantId: $tenantId,
            storeKey: $storeKey,
            channel: 'sms'
        );

        if (! hash_equals($expectedSignature, (string) $tracking['signature'])) {
            return null;
        }

        $occurredAt = now();
        $destinationUrl = (string) $link->destination_url;
        $normalizedUrl = $this->normalizedUrl($destinationUrl);
        $eventHash = hash('sha256', implode('|', [
            'short_link_click',
            (string) $deliveryId,
            strtolower(trim((string) $link->code)),
            (string) Str::uuid(),
        ]));

        $event = MarketingMessageEngagementEvent::query()->create([
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'marketing_email_delivery_id' => null,
            'marketing_message_delivery_id' => $deliveryId,
            'marketing_profile_id' => $profileId,
            'channel' => 'sms',
            'event_type' => 'click',
            'event_hash' => $eventHash,
            'provider' => 'short_link',
            'provider_event_id' => null,
            'provider_message_id' => $this->nullableString($delivery->provider_message_id),
            'link_label' => $this->deriveLinkLabel($destinationUrl),
            'url' => $destinationUrl,
            'normalized_url' => $normalizedUrl,
            'url_domain' => $this->urlDomain($normalizedUrl ?? $destinationUrl),
            'ip_address' => $this->nullableString($request->ip()),
            'user_agent' => $this->nullableString($request->userAgent()),
            'payload' => [
                'tracking_version' => (string) ($tracking['version'] ?? self::TRACKING_VERSION),
                'tracking_channel' => 'sms',
                'short_code' => strtolower(trim((string) $link->code)),
                'query' => $request->query(),
            ],
            'occurred_at' => $occurredAt,
        ]);

        $this->messageOrderAttributionService->syncForClickEvent($event);

        return $event;
    }

    /**
     * @return array{
     *   code:string,
     *   destination_url:string,
     *   short_url:string
     * }|null
     */
    protected function resolveShortLink(string $url, ?int $createdBy = null): ?array
    {
        $existingCode = $this->existingShortCodeFromUrl($url);
        if ($existingCode !== null) {
            $existing = MarketingShortLink::query()
                ->where('code', $existingCode)
                ->first();

            if ($existing instanceof MarketingShortLink) {
                return [
                    'code' => (string) $existing->code,
                    'destination_url' => (string) $existing->destination_url,
                    'short_url' => $this->linkShortenerService->shortUrl((string) $existing->code),
                ];
            }
        }

        try {
            return $this->linkShortenerService->shortenUrl($url, $createdBy);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function enforceSmsAttribution(string $url, MarketingMessageDelivery $delivery): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $url;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (! is_array($query)) {
            $query = [];
        }

        foreach ([
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'mf_channel',
            'mf_source_label',
            'mf_campaign_id',
            'mf_delivery_id',
            'mf_profile_id',
            'mf_campaign_recipient_id',
        ] as $managedKey) {
            unset($query[$managedKey]);
        }

        $campaignKey = $this->smsCampaignKey($delivery);
        $query['utm_source'] = 'backstage';
        $query['utm_medium'] = 'sms';
        $query['utm_campaign'] = $campaignKey;
        $query['utm_content'] = 'sms-link';
        $query['mf_channel'] = 'sms';

        $sourceLabel = $this->slug($delivery->source_label ?? null);
        if ($sourceLabel !== null) {
            $query['mf_source_label'] = $sourceLabel;
        }
        if ($campaignId = $this->positiveInt($delivery->campaign_id ?? null)) {
            $query['mf_campaign_id'] = $campaignId;
        }
        if ($deliveryId = $this->positiveInt($delivery->id ?? null)) {
            $query['mf_delivery_id'] = $deliveryId;
        }
        if ($profileId = $this->positiveInt($delivery->marketing_profile_id ?? null)) {
            $query['mf_profile_id'] = $profileId;
        }
        if ($recipientId = $this->positiveInt($delivery->campaign_recipient_id ?? null)) {
            $query['mf_campaign_recipient_id'] = $recipientId;
        }

        ksort($query);
        $normalizedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $rebuilt = $scheme.'://'.strtolower((string) ($parts['host'] ?? ''));
        if (isset($parts['port'])) {
            $rebuilt .= ':'.(int) $parts['port'];
        }

        $path = trim((string) ($parts['path'] ?? ''));
        $rebuilt .= $path !== '' ? '/'.ltrim($path, '/') : '/';

        if ($normalizedQuery !== '') {
            $rebuilt .= '?'.$normalizedQuery;
        }

        if (! empty($parts['fragment'])) {
            $rebuilt .= '#'.(string) $parts['fragment'];
        }

        return $rebuilt;
    }

    protected function smsCampaignKey(MarketingMessageDelivery $delivery): string
    {
        $campaignId = $this->positiveInt($delivery->campaign_id ?? null);
        if ($campaignId !== null) {
            return 'backstage-sms-'.$campaignId;
        }

        $sourceLabel = $this->slug($delivery->source_label ?? null);
        if ($sourceLabel !== null) {
            return $sourceLabel;
        }

        return 'backstage-sms';
    }

    protected function slug(mixed $value): ?string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        $slug = Str::slug($string, '-');

        return $slug !== '' ? Str::limit($slug, 80, '') : null;
    }

    protected function trackedShortUrl(string $code, MarketingMessageDelivery $delivery): string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return '';
        }

        $deliveryId = (int) ($delivery->id ?? 0);
        if ($deliveryId <= 0) {
            return '';
        }

        $profileId = $this->positiveInt($delivery->marketing_profile_id);
        $tenantId = $this->positiveInt($delivery->tenant_id);
        $storeKey = $this->nullableString($delivery->store_key);

        $query = [
            'mt_v' => self::TRACKING_VERSION,
            'mt_ch' => 'sms',
            'mt_md' => $deliveryId,
            'mt_mp' => $profileId,
            'mt_t' => $tenantId,
            'mt_s' => $storeKey,
            'mt_sig' => $this->trackingSignature($code, $deliveryId, $profileId, $tenantId, $storeKey, 'sms'),
        ];

        $queryString = http_build_query(
            array_filter($query, static fn ($value): bool => $value !== null && $value !== ''),
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        if ($queryString === '') {
            return $this->linkShortenerService->shortUrl($code);
        }

        return $this->linkShortenerService->shortUrl($code).'?'.$queryString;
    }

    /**
     * @return array{
     *   version:string,
     *   delivery_id:int,
     *   profile_id:?int,
     *   tenant_id:?int,
     *   store_key:?string,
     *   signature:string
     * }|null
     */
    protected function trackingContextFromRequest(Request $request): ?array
    {
        $deliveryId = $this->positiveInt($request->query('mt_md'));
        $signature = $this->nullableString($request->query('mt_sig'));
        $channel = strtolower(trim((string) $request->query('mt_ch', 'sms')));

        if ($deliveryId === null || $signature === null || $channel !== 'sms') {
            return null;
        }

        return [
            'version' => strtolower(trim((string) $request->query('mt_v', self::TRACKING_VERSION))),
            'delivery_id' => $deliveryId,
            'profile_id' => $this->positiveInt($request->query('mt_mp')),
            'tenant_id' => $this->positiveInt($request->query('mt_t')),
            'store_key' => $this->nullableString($request->query('mt_s')),
            'signature' => $signature,
        ];
    }

    protected function trackingSignature(
        string $code,
        int $deliveryId,
        ?int $profileId,
        ?int $tenantId,
        ?string $storeKey,
        string $channel
    ): string {
        return hash_hmac('sha256', implode('|', [
            self::TRACKING_VERSION,
            strtolower(trim($code)),
            (string) max(0, $deliveryId),
            (string) max(0, (int) ($profileId ?? 0)),
            (string) max(0, (int) ($tenantId ?? 0)),
            strtolower(trim((string) ($storeKey ?? ''))),
            strtolower(trim($channel)),
        ]), $this->trackingSecret());
    }

    protected function trackingSecret(): string
    {
        $configured = trim((string) config('marketing.links.tracking_signature_key', ''));
        if ($configured !== '') {
            return $configured;
        }

        $appKey = trim((string) config('app.key', ''));
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        return $appKey !== '' ? $appKey : 'marketing-link-tracking';
    }

    protected function existingShortCodeFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $path = '/'.ltrim(trim((string) ($parts['path'] ?? '')), '/');
        $prefix = trim((string) config('marketing.links.path_prefix', 'go'), '/');
        $pattern = $prefix !== ''
            ? '#^/'.preg_quote($prefix, '#').'/([a-z0-9]{6,32})/?$#i'
            : '#^/([a-z0-9]{6,32})/?$#i';

        if (! preg_match($pattern, $path, $matches)) {
            return null;
        }

        return strtolower(trim((string) ($matches[1] ?? '')));
    }

    protected function deriveLinkLabel(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            $host = $this->nullableString((string) ($parts['host'] ?? null));

            return $host !== null ? Str::limit($host, 180) : null;
        }

        return Str::limit((string) urldecode((string) basename($path)), 180);
    }

    protected function normalizedUrl(?string $url): ?string
    {
        $url = $this->nullableString($url);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            return null;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? 'https')));
        $path = trim((string) ($parts['path'] ?? ''));
        $path = $path !== '' ? $path : '/';
        $path = '/'.ltrim($path, '/');

        $queryString = trim((string) ($parts['query'] ?? ''));
        if ($queryString === '') {
            return $scheme.'://'.$host.$path;
        }

        parse_str($queryString, $query);
        if (! is_array($query)) {
            return $scheme.'://'.$host.$path;
        }

        foreach ([
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'gclid',
            'fbclid',
            'msclkid',
            '_hsenc',
            '_hsmi',
        ] as $trackedKey) {
            unset($query[$trackedKey]);
        }

        if ($query === []) {
            return $scheme.'://'.$host.$path;
        }

        ksort($query);
        $normalizedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $normalizedQuery !== ''
            ? $scheme.'://'.$host.$path.'?'.$normalizedQuery
            : $scheme.'://'.$host.$path;
    }

    protected function urlDomain(?string $url): ?string
    {
        $url = $this->nullableString($url);
        if ($url === null) {
            return null;
        }

        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? '')));

        return $host !== '' ? $host : null;
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function splitTrailingPunctuation(string $raw): array
    {
        $suffix = '';
        $trimChars = '.,!?;:)]}';
        while ($raw !== '') {
            $last = substr($raw, -1);
            if ($last === false || ! str_contains($trimChars, $last)) {
                break;
            }

            $suffix = $last.$suffix;
            $raw = substr($raw, 0, -1);
        }

        return [$raw, $suffix];
    }

    protected function isValidUrl(string $url): bool
    {
        $valid = filter_var($url, FILTER_VALIDATE_URL);
        if ($valid === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
