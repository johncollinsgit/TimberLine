<?php

namespace App\Services\Shopify;

use Illuminate\Support\Str;

class ShopifyEmbeddedEmailComposerService
{
    public const MODE_SECTIONS = 'sections';
    public const MODE_LEGACY_HTML = 'legacy_html';

    /**
     * @param  mixed  $sections
     * @return array{
     *   mode:string,
     *   sections:array<int,array<string,mixed>>,
     *   legacy_html:?string,
     *   html:string
     * }
     */
    public function compose(string $subject, string $body, ?string $mode, mixed $sections, ?string $legacyHtml): array
    {
        $resolvedMode = $this->normalizedMode($mode);
        $normalizedSections = $this->normalizeSections($sections);
        $resolvedLegacyHtml = $this->nullableString($legacyHtml);
        $resolvedSubject = $this->nullableString($subject) ?? 'Message from Backstage';
        $resolvedBody = trim($body);

        if ($resolvedMode === self::MODE_LEGACY_HTML && $resolvedLegacyHtml !== null) {
            return [
                'mode' => self::MODE_LEGACY_HTML,
                'sections' => $normalizedSections,
                'legacy_html' => $resolvedLegacyHtml,
                'html' => $this->renderLegacyHtml($resolvedLegacyHtml, $resolvedSubject, $resolvedBody),
            ];
        }

        if ($normalizedSections === [] && $resolvedBody !== '') {
            $normalizedSections[] = [
                'id' => (string) Str::uuid(),
                'type' => 'text',
                'html' => $this->plainTextToHtml($resolvedBody),
            ];
        }

        return [
            'mode' => self::MODE_SECTIONS,
            'sections' => $normalizedSections,
            'legacy_html' => $resolvedLegacyHtml,
            'html' => $this->renderSectionsHtml($resolvedSubject, $resolvedBody, $normalizedSections),
        ];
    }

    /**
     * @param  mixed  $sections
     * @return array<int,array<string,mixed>>
     */
    public function normalizeSections(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        $rows = [];
        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $normalized = $this->normalizeSection($section, $index);
            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $section
     * @return array<string,mixed>|null
     */
    protected function normalizeSection(array $section, int $index): ?array
    {
        $type = strtolower(trim((string) ($section['type'] ?? '')));
        if (! in_array($type, ['image', 'product', 'product_grid_4', 'button', 'text', 'divider', 'fading_divider', 'heading', 'spacer'], true)) {
            return null;
        }

        $id = $this->nullableString($section['id'] ?? null) ?? ('section_' . ($index + 1));

        return match ($type) {
            'image' => [
                'id' => $id,
                'type' => 'image',
                'imageUrl' => $this->nullableString($section['imageUrl'] ?? null),
                'alt' => $this->nullableString($section['alt'] ?? null),
                'href' => $this->nullableString($section['href'] ?? null),
                'padding' => $this->nullableString($section['padding'] ?? null) ?? '12px 0',
            ],
            'product' => [
                'id' => $id,
                'type' => 'product',
                'productId' => $this->nullableString($section['productId'] ?? null),
                'title' => $this->nullableString($section['title'] ?? null),
                'imageUrl' => $this->nullableString($section['imageUrl'] ?? null),
                'price' => $this->nullableString($section['price'] ?? null),
                'href' => $this->nullableString($section['href'] ?? null),
                'buttonLabel' => $this->nullableString($section['buttonLabel'] ?? null) ?? 'View product',
            ],
            'product_grid_4' => [
                'id' => $id,
                'type' => 'product_grid_4',
                'heading' => $this->nullableString($section['heading'] ?? null),
                'products' => collect((array) ($section['products'] ?? []))
                    ->map(function (mixed $product): ?array {
                        if (! is_array($product)) {
                            return null;
                        }

                        return [
                            'productId' => $this->nullableString($product['productId'] ?? null),
                            'title' => $this->nullableString($product['title'] ?? null),
                            'imageUrl' => $this->nullableString($product['imageUrl'] ?? null),
                            'price' => $this->nullableString($product['price'] ?? null),
                            'href' => $this->nullableString($product['href'] ?? null),
                            'buttonLabel' => $this->nullableString($product['buttonLabel'] ?? null) ?? 'Shop now',
                        ];
                    })
                    ->filter()
                    ->take(4)
                    ->values()
                    ->all(),
            ],
            'button' => [
                'id' => $id,
                'type' => 'button',
                'label' => $this->nullableString($section['label'] ?? null) ?? 'Learn more',
                'href' => $this->nullableString($section['href'] ?? null),
                'align' => in_array(strtolower(trim((string) ($section['align'] ?? 'center'))), ['left', 'center', 'right'], true)
                    ? strtolower(trim((string) ($section['align'] ?? 'center')))
                    : 'center',
            ],
            'text' => [
                'id' => $id,
                'type' => 'text',
                'html' => $this->nullableString($section['html'] ?? null) ?? '',
            ],
            'heading' => [
                'id' => $id,
                'type' => 'heading',
                'text' => $this->nullableString($section['text'] ?? null) ?? 'Heading',
                'align' => in_array(strtolower(trim((string) ($section['align'] ?? 'left'))), ['left', 'center', 'right'], true)
                    ? strtolower(trim((string) ($section['align'] ?? 'left')))
                    : 'left',
            ],
            'spacer' => [
                'id' => $id,
                'type' => 'spacer',
                'height' => max(4, min(80, (int) ($section['height'] ?? 20))),
            ],
            'divider' => [
                'id' => $id,
                'type' => 'divider',
            ],
            'fading_divider' => [
                'id' => $id,
                'type' => 'fading_divider',
                'spacingTop' => max(0, min(48, (int) ($section['spacingTop'] ?? 12))),
                'spacingBottom' => max(0, min(48, (int) ($section['spacingBottom'] ?? 12))),
            ],
            default => null,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     */
    protected function renderSectionsHtml(string $subject, string $body, array $sections): string
    {
        $rows = [];
        $rows[] = '<tr><td style="padding:8px 0 18px 0;"><h1 style="margin:0;font-family:Arial,sans-serif;font-size:24px;line-height:1.25;color:#0f172a;">' . $this->escapeHtml($subject) . '</h1></td></tr>';

        foreach ($sections as $section) {
            $row = $this->renderSection($section);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $hasBodySection = collect($sections)->contains(function (array $section): bool {
            $type = strtolower(trim((string) ($section['type'] ?? '')));

            return in_array($type, ['text', 'heading'], true);
        });

        if (! $hasBodySection && trim($body) !== '') {
            $rows[] = '<tr><td style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">' . $this->plainTextToHtml($body) . '</td></tr>';
        }

        if (count($rows) === 1) {
            $rows[] = '<tr><td style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">Your email content will appear here.</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f3f4f6;padding:18px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #dbe2ea;border-radius:12px;padding:22px;">' . implode('', $rows) . '</table></td></tr></table></body></html>';
    }

    /**
     * @param  array<string,mixed>  $section
     */
    protected function renderSection(array $section): ?string
    {
        $type = strtolower(trim((string) ($section['type'] ?? '')));

        if ($type === 'image') {
            $imageUrl = $this->safeUrl($section['imageUrl'] ?? null, ['http', 'https']);
            if ($imageUrl === null) {
                return null;
            }

            $alt = $this->escapeHtml((string) ($section['alt'] ?? 'Image'));
            $padding = $this->escapeHtml((string) ($section['padding'] ?? '12px 0'));
            $imageTag = '<img src="' . $this->escapeHtml($imageUrl) . '" alt="' . $alt . '" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:10px;" />';

            $href = $this->safeUrl($section['href'] ?? null);
            if ($href !== null) {
                $imageTag = '<a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">' . $imageTag . '</a>';
            }

            return '<tr><td style="padding:' . $padding . ';">' . $imageTag . '</td></tr>';
        }

        if ($type === 'product') {
            return '<tr><td style="padding:12px 0;">' . $this->renderProductCard($section) . '</td></tr>';
        }

        if ($type === 'product_grid_4') {
            $products = collect((array) ($section['products'] ?? []))
                ->filter(fn (mixed $product): bool => is_array($product))
                ->take(4)
                ->values();

            if ($products->isEmpty()) {
                return null;
            }

            $rows = [];
            $heading = $this->nullableString($section['heading'] ?? null);
            if ($heading !== null) {
                $rows[] = '<tr><td colspan="2" style="padding:0 0 14px 0;font-family:Arial,sans-serif;font-size:18px;font-weight:700;line-height:1.3;color:#0f172a;">'
                    . $this->escapeHtml($heading)
                    . '</td></tr>';
            }

            foreach ($products->chunk(2) as $pair) {
                $cells = [];

                foreach ($pair as $product) {
                    $cells[] = '<td valign="top" width="50%" style="width:50%;padding:0 8px 16px 8px;">'
                        . $this->renderProductCard((array) $product, compactMode: true)
                        . '</td>';
                }

                while (count($cells) < 2) {
                    $cells[] = '<td valign="top" width="50%" style="width:50%;padding:0 8px 16px 8px;">&nbsp;</td>';
                }

                $rows[] = '<tr>' . implode('', $cells) . '</tr>';
            }

            return '<tr><td style="padding:8px 0 12px 0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">'
                . implode('', $rows)
                . '</table></td></tr>';
        }

        if ($type === 'button') {
            $href = $this->safeUrl($section['href'] ?? null);
            if ($href === null) {
                return null;
            }

            $align = strtolower(trim((string) ($section['align'] ?? 'center')));
            if (! in_array($align, ['left', 'center', 'right'], true)) {
                $align = 'center';
            }

            $label = $this->escapeHtml((string) ($section['label'] ?? 'Learn more'));

            return '<tr><td style="padding:12px 0;text-align:' . $align . ';"><a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#10633f;color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:10px 16px;border-radius:999px;">' . $label . '</a></td></tr>';
        }

        if ($type === 'text') {
            $html = $this->sanitizeRichTextHtml((string) ($section['html'] ?? ''));
            if ($html === '') {
                return null;
            }

            return '<tr><td style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">' . $html . '</td></tr>';
        }

        if ($type === 'heading') {
            $text = $this->escapeHtml((string) ($section['text'] ?? 'Heading'));
            if ($text === '') {
                return null;
            }

            $align = strtolower(trim((string) ($section['align'] ?? 'left')));
            if (! in_array($align, ['left', 'center', 'right'], true)) {
                $align = 'left';
            }

            return '<tr><td style="padding:0 0 10px 0;font-family:Arial,sans-serif;font-size:22px;font-weight:700;line-height:1.3;color:#0f172a;text-align:' . $align . ';">' . $text . '</td></tr>';
        }

        if ($type === 'spacer') {
            $height = max(4, min(80, (int) ($section['height'] ?? 20)));

            return '<tr><td aria-hidden="true" style="font-size:0;line-height:0;height:' . $height . 'px;">&nbsp;</td></tr>';
        }

        if ($type === 'divider') {
            return '<tr><td style="padding:10px 0;"><hr style="margin:0;border:0;border-top:1px solid #dbe2ea;" /></td></tr>';
        }

        if ($type === 'fading_divider') {
            $spacingTop = max(0, min(48, (int) ($section['spacingTop'] ?? 12)));
            $spacingBottom = max(0, min(48, (int) ($section['spacingBottom'] ?? 12)));

            return '<tr><td style="padding:' . $spacingTop . 'px 0 ' . $spacingBottom . 'px 0;">'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
                . '<td width="20%" style="width:20%;border-top:1px solid #eef2f7;font-size:0;line-height:0;">&nbsp;</td>'
                . '<td width="60%" style="width:60%;border-top:1px solid #dbe2ea;font-size:0;line-height:0;">&nbsp;</td>'
                . '<td width="20%" style="width:20%;border-top:1px solid #eef2f7;font-size:0;line-height:0;">&nbsp;</td>'
                . '</tr></table>'
                . '</td></tr>';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $section
     */
    protected function renderProductCard(array $section, bool $compactMode = false): string
    {
        $title = $this->escapeHtml((string) ($section['title'] ?? 'Product'));
        $price = $this->escapeHtml((string) ($section['price'] ?? ''));
        $href = $this->safeUrl($section['href'] ?? null);
        $buttonLabel = $this->escapeHtml((string) ($section['buttonLabel'] ?? 'View product'));
        $imageUrl = $this->safeUrl($section['imageUrl'] ?? null, ['http', 'https']);

        $parts = [];
        if ($imageUrl !== null) {
            $imageTag = '<img src="' . $this->escapeHtml($imageUrl) . '" alt="' . $title . '" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:10px;" />';
            if ($href !== null) {
                $imageTag = '<a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">' . $imageTag . '</a>';
            }
            $parts[] = '<tr><td style="padding:0 0 10px 0;">' . $imageTag . '</td></tr>';
        }

        $parts[] = '<tr><td style="padding:0 0 4px 0;font-family:Arial,sans-serif;font-size:' . ($compactMode ? '16px' : '18px') . ';font-weight:700;line-height:1.3;color:#0f172a;">' . $title . '</td></tr>';
        if ($price !== '') {
            $parts[] = '<tr><td style="padding:0 0 10px 0;font-family:Arial,sans-serif;font-size:14px;line-height:1.4;color:#334155;">' . $price . '</td></tr>';
        }

        if ($href !== null) {
            $parts[] = '<tr><td style="padding:0;"><a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#10633f;color:#ffffff;font-family:Arial,sans-serif;font-size:13px;font-weight:700;text-decoration:none;padding:10px 16px;border-radius:999px;">' . $buttonLabel . '</a></td></tr>';
        }

        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;">'
            . implode('', $parts)
            . '</table>';
    }

    protected function renderLegacyHtml(string $templateHtml, string $subject, string $body): string
    {
        $messageHtml = '<p>' . $this->plainTextToHtml($body) . '</p>';

        return str_ireplace(
            ['@{{subject}}', '@{{ message_body }}', '@{{message_body}}'],
            [$this->escapeHtml($subject), $messageHtml, $messageHtml],
            $this->stripScriptTags($templateHtml)
        );
    }

    protected function normalizedMode(?string $mode): string
    {
        $resolved = strtolower(trim((string) $mode));

        return $resolved === self::MODE_LEGACY_HTML
            ? self::MODE_LEGACY_HTML
            : self::MODE_SECTIONS;
    }

    protected function plainTextToHtml(string $value): string
    {
        $escaped = $this->escapeHtml($value);
        $escaped = str_replace(["\r\n", "\r"], "\n", $escaped);

        return nl2br($escaped, false);
    }

    protected function sanitizeRichTextHtml(string $value): string
    {
        $sanitized = $this->stripScriptTags($value);
        $sanitized = preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/i", '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/i', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/javascript\s*:/i', '', $sanitized) ?? $sanitized;

        return trim((string) strip_tags($sanitized, '<p><br><strong><b><em><i><u><a><ul><ol><li><span>'));
    }

    protected function stripScriptTags(string $value): string
    {
        return preg_replace('/<\s*(script|style)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $value) ?? $value;
    }

    /**
     * @param  array<int,string>  $schemes
     */
    protected function safeUrl(mixed $value, array $schemes = ['http', 'https', 'mailto', 'tel']): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        $pattern = '/^(' . implode('|', array_map('preg_quote', $schemes)) . '):/i';

        return preg_match($pattern, $url) === 1
            ? $url
            : null;
    }

    protected function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function nullableString(mixed $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }
}
