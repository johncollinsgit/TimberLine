<?php

namespace App\Services\Marketing;

use Illuminate\Support\Str;

class EmailLinkAttributionService
{
    /**
     * @var array<int,string>
     */
    protected array $managedQueryKeys = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
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
    ];

    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function decorateSections(array $sections, array $context = []): array
    {
        $decorated = [];

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $moduleType = strtolower(trim((string) ($section['type'] ?? 'section')));
            $moduleContext = [
                ...$context,
                'module_type' => $moduleType !== '' ? $moduleType : 'section',
                'module_position' => $index + 1,
            ];

            if ($moduleType === 'text' && is_string($section['html'] ?? null)) {
                $section['html'] = $this->decorateHtml((string) $section['html'], $moduleContext);
            }

            foreach (['href'] as $field) {
                if (is_string($section[$field] ?? null)) {
                    $section[$field] = $this->decorateUrl((string) $section[$field], [
                        ...$moduleContext,
                        'link_label' => $section['label'] ?? $section['title'] ?? $section['text'] ?? null,
                        'product_id' => $section['productId'] ?? null,
                    ]);
                }
            }

            if ($moduleType === 'product_grid_4') {
                $products = [];
                foreach ((array) ($section['products'] ?? []) as $tileIndex => $product) {
                    if (! is_array($product)) {
                        continue;
                    }

                    if (is_string($product['href'] ?? null)) {
                        $product['href'] = $this->decorateUrl((string) $product['href'], [
                            ...$moduleContext,
                            'product_id' => $product['productId'] ?? null,
                            'tile_position' => $tileIndex + 1,
                            'link_label' => $product['title'] ?? $product['buttonLabel'] ?? 'Product',
                        ]);
                    }

                    $products[] = $product;
                }

                $section['products'] = $products;
            }

            $decorated[] = $section;
        }

        return $decorated;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function decorateHtml(string $html, array $context = []): string
    {
        if (trim($html) === '') {
            return $html;
        }

        return preg_replace_callback(
            '/(<a\b[^>]*\bhref\s*=\s*["\'])([^"\']+)(["\'])/i',
            function (array $matches) use ($context): string {
                $decorated = $this->decorateUrl((string) ($matches[2] ?? ''), $context);

                return (string) ($matches[1] ?? '') . $decorated . (string) ($matches[3] ?? '');
            },
            $html
        ) ?? $html;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function decorateText(string $text, array $context = []): string
    {
        if (trim($text) === '') {
            return $text;
        }

        return preg_replace_callback(
            '~https?://[^\s<>"]+~i',
            function (array $matches) use ($context): string {
                $raw = (string) ($matches[0] ?? '');
                $suffix = '';

                while ($raw !== '' && preg_match('/[\.,!?:;\)\]]$/', $raw) === 1) {
                    $suffix = substr($raw, -1) . $suffix;
                    $raw = substr($raw, 0, -1);
                }

                return $this->decorateUrl($raw, $context) . $suffix;
            },
            $text
        ) ?? $text;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function decorateUrl(string $url, array $context = []): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return $url;
        }

        $parts = parse_url($trimmed);
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

        foreach ($this->managedQueryKeys as $managedKey) {
            unset($query[$managedKey]);
        }

        $moduleType = $this->slug($context['module_type'] ?? null) ?? 'section';
        $modulePosition = $this->positiveInt($context['module_position'] ?? null);
        $tilePosition = $this->positiveInt($context['tile_position'] ?? null);
        $linkLabel = $this->cleanLabel($context['link_label'] ?? null);

        $query['utm_source'] = 'backstage';
        $query['utm_medium'] = 'email';
        $query['utm_campaign'] = $this->campaignKey($context);
        $query['utm_content'] = $this->utmContent($moduleType, $modulePosition, $tilePosition);
        $query['mf_channel'] = 'email';

        if ($sourceLabel = $this->slug($context['source_label'] ?? null)) {
            $query['mf_source_label'] = $sourceLabel;
        }
        if ($templateKey = $this->slug($context['template_key'] ?? null)) {
            $query['mf_template_key'] = $templateKey;
        }
        if ($campaignId = $this->positiveInt($context['campaign_id'] ?? null)) {
            $query['mf_campaign_id'] = $campaignId;
        }
        if ($deliveryId = $this->positiveInt($context['delivery_id'] ?? null)) {
            $query['mf_delivery_id'] = $deliveryId;
        }
        if ($profileId = $this->positiveInt($context['profile_id'] ?? null)) {
            $query['mf_profile_id'] = $profileId;
        }
        if ($recipientId = $this->positiveInt($context['campaign_recipient_id'] ?? null)) {
            $query['mf_campaign_recipient_id'] = $recipientId;
        }
        if ($moduleType !== '') {
            $query['mf_module_type'] = $moduleType;
        }
        if ($modulePosition !== null) {
            $query['mf_module_position'] = $modulePosition;
        }
        if ($productId = $this->slug($context['product_id'] ?? null)) {
            $query['mf_product_id'] = $productId;
        }
        if ($tilePosition !== null) {
            $query['mf_tile_position'] = $tilePosition;
        }
        if ($linkLabel !== null) {
            $query['mf_link_label'] = $linkLabel;
        }

        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $rebuilt = $scheme . '://' . strtolower((string) ($parts['host'] ?? ''));
        if (isset($parts['port'])) {
            $rebuilt .= ':' . (int) $parts['port'];
        }

        $path = (string) ($parts['path'] ?? '/');
        $rebuilt .= $path !== '' ? $path : '/';

        if ($queryString !== '') {
            $rebuilt .= '?' . $queryString;
        }

        if (! empty($parts['fragment'])) {
            $rebuilt .= '#' . (string) $parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function campaignKey(array $context): string
    {
        $campaignId = $this->positiveInt($context['campaign_id'] ?? null);
        if ($campaignId !== null) {
            return 'backstage-email-' . $campaignId;
        }

        return $this->slug($context['source_label'] ?? null)
            ?? $this->slug($context['template_key'] ?? null)
            ?? $this->slug($context['subject'] ?? null)
            ?? 'backstage-email';
    }

    protected function utmContent(string $moduleType, ?int $modulePosition, ?int $tilePosition): string
    {
        $segments = [];

        $segments[] = $moduleType !== '' ? $moduleType : 'section';
        if ($modulePosition !== null) {
            $segments[] = 's' . $modulePosition;
        }
        if ($tilePosition !== null) {
            $segments[] = 't' . $tilePosition;
        }

        return implode('-', $segments);
    }

    protected function cleanLabel(mixed $value): ?string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return Str::limit($string, 120, '');
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

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }
}
