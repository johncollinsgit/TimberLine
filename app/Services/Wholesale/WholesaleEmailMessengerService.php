<?php

namespace App\Services\Wholesale;

use App\Models\WholesaleEmailMessengerDraft;
use App\Services\Shopify\ShopifyEmbeddedEmailComposerService;
use Illuminate\Validation\ValidationException;

class WholesaleEmailMessengerService
{
    public const DRAFT_NAME = 'Bring Modern Forestry to your store';

    public function __construct(protected ShopifyEmbeddedEmailComposerService $composer) {}

    /** @return array<string,mixed> */
    public function draft(int $tenantId, string $storeKey, ?int $actorId = null): array
    {
        $draft = WholesaleEmailMessengerDraft::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'store_key' => $storeKey, 'name' => self::DRAFT_NAME],
            [
                'subject' => 'Bring Modern Forestry to your store',
                'sections' => self::defaultSections(),
                'personalization' => ['first_name_token' => '{{ first_name | default: "there" }}'],
                'revision' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ],
        );

        // The first version of this draft used guessed `/cdn/shop/files/` names.
        // Those were not Shopify assets, so repair only those exact legacy values
        // when an existing tenant opens its saved draft. Merchant edits remain
        // untouched.
        if ($this->repairLegacyAssetUrls($draft)) {
            $draft->save();
        }

        return $this->payload($draft);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $tenantId, string $storeKey, array $input, ?int $actorId = null): array
    {
        $draft = WholesaleEmailMessengerDraft::query()
            ->where('tenant_id', $tenantId)->where('store_key', $storeKey)->where('name', self::DRAFT_NAME)->first();
        if (! $draft) {
            $this->draft($tenantId, $storeKey, $actorId);
            $draft = WholesaleEmailMessengerDraft::query()
                ->where('tenant_id', $tenantId)->where('store_key', $storeKey)->where('name', self::DRAFT_NAME)->firstOrFail();
        }

        $sections = $this->composer->normalizeSections($input['sections'] ?? []);
        if (count($sections) !== 16) {
            throw ValidationException::withMessages(['sections' => 'This approved draft has exactly 16 editable content blocks.']);
        }
        $revision = (int) ($input['revision'] ?? 0);
        if ($revision !== (int) $draft->revision) {
            throw ValidationException::withMessages(['revision' => 'This draft changed elsewhere. Reload before saving your edits.']);
        }

        $draft->forceFill([
            'subject' => trim((string) ($input['subject'] ?? '')),
            'sections' => $sections,
            'personalization' => is_array($input['personalization'] ?? null) ? $input['personalization'] : [],
            'revision' => $draft->revision + 1,
            'updated_by' => $actorId,
        ])->save();

        return $this->payload($draft->fresh());
    }

    /** @return array<string,mixed> */
    public function payload(WholesaleEmailMessengerDraft $draft): array
    {
        $composed = $this->composer->compose((string) $draft->subject, '', 'sections', $draft->sections, null);
        $footer = $this->lockedComplianceFooter();
        $html = str_replace('</body>', $footer.'</body>', (string) $composed['html']);

        return [
            'id' => (int) $draft->id,
            'name' => (string) $draft->name,
            'subject' => (string) $draft->subject,
            'sections' => array_values((array) $draft->sections),
            'personalization' => (array) $draft->personalization,
            'revision' => (int) $draft->revision,
            'rendered_html' => $html,
            'locked_footer' => true,
            'sender' => ['from_email' => 'info@theforestrystudio.com', 'from_name' => 'Modern Forestry'],
        ];
    }

    /** @param array<int,array<string,mixed>> $sections
     * @return array<int,array<string,mixed>>
     */
    public function sectionsForTestSend(array $sections): array
    {
        return [...$sections, [
            'id' => 'locked_compliance_footer',
            'type' => 'text',
            'html' => 'You are receiving this email because you opted in to Modern Forestry marketing. <a href="mailto:info@theforestrystudio.com?subject=Unsubscribe">Unsubscribe</a> · <a href="https://theforestrystudio.com/pages/privacy-policy">Privacy</a>',
        ]];
    }

    /** @return array<int,array<string,mixed>> */
    public static function defaultSections(): array
    {
        $site = 'https://theforestrystudio.com';
        $assets = [
            'hero' => 'https://cdn.shopify.com/s/files/1/2081/2479/files/SaleImage-darktheme_5f5f655b-77a9-49f5-b699-037f71dee79e.png?v=1762446943',
            'craft' => 'https://cdn.shopify.com/s/files/1/2081/2479/files/IMG_1086.jpg?v=1710945776',
            'retail' => 'https://cdn.shopify.com/s/files/1/2081/2479/files/MagnoliaBlossom16oz.png?v=1784741108',
        ];

        return [
            ['id' => 'hero', 'type' => 'image', 'imageUrl' => $assets['hero'], 'alt' => 'Modern Forestry candles', 'href' => $site.'/collections/all', 'padding' => '0 0 18px 0'],
            ['id' => 'welcome', 'type' => 'heading', 'text' => 'Bring Modern Forestry to your store', 'align' => 'center'],
            ['id' => 'greeting', 'type' => 'text', 'html' => 'Hi {{ first_name | default: "there" }}, we make quiet, beautiful candles for stores that value thoughtful goods.'],
            ['id' => 'craft-image', 'type' => 'image', 'imageUrl' => $assets['craft'], 'alt' => 'Hand-poured candle', 'href' => $site.'/collections/all', 'padding' => '8px 0 18px 0'],
            ['id' => 'craft-heading', 'type' => 'heading', 'text' => 'Hand-poured in small batches', 'align' => 'left'],
            ['id' => 'craft-copy', 'type' => 'text', 'html' => 'Our signature scents are designed to feel at home on a thoughtful shelf and in an everyday ritual.'],
            ['id' => 'shop-cta', 'type' => 'button', 'label' => 'Shop the candle collection', 'href' => $site.'/collections/all', 'align' => 'center'],
            ['id' => 'divider', 'type' => 'fading_divider', 'spacingTop' => 14, 'spacingBottom' => 18],
            ['id' => 'wholesale-heading', 'type' => 'heading', 'text' => 'Made for your shelves', 'align' => 'center'],
            ['id' => 'wholesale-copy', 'type' => 'text', 'html' => 'Offer your customers a modern take on forest-inspired fragrance, with merchandising support from our studio.'],
            ['id' => 'candle-grid', 'type' => 'product_grid_4', 'heading' => 'A few customer favorites', 'products' => [
                ['title' => 'Forest Spice', 'imageUrl' => $assets['craft'], 'href' => $site.'/products/forest-spice', 'buttonLabel' => 'View candle'],
                ['title' => 'Amber Fog', 'imageUrl' => 'https://cdn.shopify.com/s/files/1/2081/2479/files/AmberFog16oz.png?v=1784819810', 'href' => $site.'/products/amber-fog-new', 'buttonLabel' => 'View candle'],
                ['title' => 'Magnolia Blossom', 'imageUrl' => $assets['retail'], 'href' => $site.'/products/magnolia-blossom', 'buttonLabel' => 'View candle'],
                ['title' => 'Nightfall', 'imageUrl' => 'https://cdn.shopify.com/s/files/1/2081/2479/files/Nightfall16oz.png?v=1784902486', 'href' => $site.'/products/new-nightfall', 'buttonLabel' => 'View candle'],
            ]],
            ['id' => 'application-cta', 'type' => 'button', 'label' => 'Apply for wholesale', 'href' => $site.'/pages/wholesale', 'align' => 'center'],
            ['id' => 'retail-image', 'type' => 'image', 'imageUrl' => $assets['retail'], 'alt' => 'Modern Forestry stockist', 'href' => $site.'/pages/store-locator', 'padding' => '18px 0 12px 0'],
            ['id' => 'retail-copy', 'type' => 'text', 'html' => 'Already nearby? Find a shop carrying Modern Forestry.'],
            ['id' => 'locator-cta', 'type' => 'button', 'label' => 'Find a store', 'href' => $site.'/pages/store-locator', 'align' => 'center'],
            ['id' => 'instagram-cta', 'type' => 'button', 'label' => 'Follow Modern Forestry on Instagram', 'href' => 'https://www.instagram.com/theforestrystudio/', 'align' => 'center'],
        ];
    }

    protected function repairLegacyAssetUrls(WholesaleEmailMessengerDraft $draft): bool
    {
        $defaults = collect(self::defaultSections())->keyBy('id');
        $legacyImages = [
            'hero' => 'https://theforestrystudio.com/cdn/shop/files/modern-forestry-wholesale-hero.jpg',
            'craft-image' => 'https://theforestrystudio.com/cdn/shop/files/modern-forestry-candle-pour.jpg',
            'retail-image' => 'https://theforestrystudio.com/cdn/shop/files/modern-forestry-stockist.jpg',
        ];
        $legacyProducts = [
            'https://theforestrystudio.com/products/cedar-smoke-candle',
            'https://theforestrystudio.com/products/moss-amber-candle',
            'https://theforestrystudio.com/products/pine-citrus-candle',
            'https://theforestrystudio.com/products/sandalwood-fig-candle',
        ];
        $legacyProductImages = [
            'https://theforestrystudio.com/cdn/shop/files/cedar-smoke-candle.jpg',
            'https://theforestrystudio.com/cdn/shop/files/moss-amber-candle.jpg',
            'https://theforestrystudio.com/cdn/shop/files/pine-citrus-candle.jpg',
            'https://theforestrystudio.com/cdn/shop/files/sandalwood-fig-candle.jpg',
        ];
        $sections = array_values((array) $draft->sections);
        $changed = false;

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $id = (string) ($section['id'] ?? '');
            if (isset($legacyImages[$id]) && ($section['imageUrl'] ?? null) === $legacyImages[$id]) {
                $section['imageUrl'] = $defaults->get($id)['imageUrl'];
                $sections[$index] = $section;
                $changed = true;
            }

            if ($id === 'candle-grid' && is_array($section['products'] ?? null)) {
                $replacementProducts = (array) ($defaults->get('candle-grid')['products'] ?? []);
                foreach ($section['products'] as $productIndex => $product) {
                    if (! is_array($product) || ! isset($replacementProducts[$productIndex])) {
                        continue;
                    }

                    $replacement = $replacementProducts[$productIndex];
                    if (in_array($product['imageUrl'] ?? null, $legacyProductImages, true)) {
                        $product['imageUrl'] = $replacement['imageUrl'];
                        $changed = true;
                    }
                    if (in_array($product['href'] ?? null, $legacyProducts, true)) {
                        $product['href'] = $replacement['href'];
                        $changed = true;
                    }
                    $section['products'][$productIndex] = $product;
                }
                $sections[$index] = $section;
            }
        }

        if ($changed) {
            $draft->forceFill([
                'sections' => $sections,
                'revision' => (int) $draft->revision + 1,
            ]);
        }

        return $changed;
    }

    protected function lockedComplianceFooter(): string
    {
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:22px 0 0;font-family:Arial,sans-serif;font-size:11px;line-height:1.5;color:#64748b;text-align:center;border-top:1px solid #e2e8f0;">You are receiving this email because you opted in to Modern Forestry marketing. <a href="mailto:info@theforestrystudio.com?subject=Unsubscribe" style="color:#475569;">Unsubscribe</a> · <a href="https://theforestrystudio.com/pages/privacy-policy" style="color:#475569;">Privacy</a></td></tr></table>';
    }
}
