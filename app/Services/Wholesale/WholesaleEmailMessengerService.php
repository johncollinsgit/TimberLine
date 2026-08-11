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
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ],
        );

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

        return [
            ['id' => 'hero', 'type' => 'image', 'imageUrl' => $site.'/cdn/shop/files/modern-forestry-wholesale-hero.jpg', 'alt' => 'Modern Forestry candles', 'href' => $site.'/collections/wholesale', 'padding' => '0 0 18px 0'],
            ['id' => 'welcome', 'type' => 'heading', 'text' => 'Bring Modern Forestry to your store', 'align' => 'center'],
            ['id' => 'greeting', 'type' => 'text', 'html' => 'Hi {{ first_name | default: "there" }}, we make quiet, beautiful candles for stores that value thoughtful goods.'],
            ['id' => 'craft-image', 'type' => 'image', 'imageUrl' => $site.'/cdn/shop/files/modern-forestry-candle-pour.jpg', 'alt' => 'Hand-poured candle', 'href' => $site.'/collections/candles', 'padding' => '8px 0 18px 0'],
            ['id' => 'craft-heading', 'type' => 'heading', 'text' => 'Hand-poured in small batches', 'align' => 'left'],
            ['id' => 'craft-copy', 'type' => 'text', 'html' => 'Our signature scents are designed to feel at home on a thoughtful shelf and in an everyday ritual.'],
            ['id' => 'shop-cta', 'type' => 'button', 'label' => 'Shop the candle collection', 'href' => $site.'/collections/candles', 'align' => 'center'],
            ['id' => 'divider', 'type' => 'fading_divider', 'spacingTop' => 14, 'spacingBottom' => 18],
            ['id' => 'wholesale-heading', 'type' => 'heading', 'text' => 'Made for your shelves', 'align' => 'center'],
            ['id' => 'wholesale-copy', 'type' => 'text', 'html' => 'Offer your customers a modern take on forest-inspired fragrance, with merchandising support from our studio.'],
            ['id' => 'candle-grid', 'type' => 'product_grid_4', 'heading' => 'A few customer favorites', 'products' => [
                ['title' => 'Cedar + Smoke', 'imageUrl' => $site.'/cdn/shop/files/cedar-smoke-candle.jpg', 'href' => $site.'/products/cedar-smoke-candle', 'buttonLabel' => 'View candle'],
                ['title' => 'Moss + Amber', 'imageUrl' => $site.'/cdn/shop/files/moss-amber-candle.jpg', 'href' => $site.'/products/moss-amber-candle', 'buttonLabel' => 'View candle'],
                ['title' => 'Pine + Citrus', 'imageUrl' => $site.'/cdn/shop/files/pine-citrus-candle.jpg', 'href' => $site.'/products/pine-citrus-candle', 'buttonLabel' => 'View candle'],
                ['title' => 'Sandalwood + Fig', 'imageUrl' => $site.'/cdn/shop/files/sandalwood-fig-candle.jpg', 'href' => $site.'/products/sandalwood-fig-candle', 'buttonLabel' => 'View candle'],
            ]],
            ['id' => 'application-cta', 'type' => 'button', 'label' => 'Apply for wholesale', 'href' => $site.'/pages/wholesale', 'align' => 'center'],
            ['id' => 'retail-image', 'type' => 'image', 'imageUrl' => $site.'/cdn/shop/files/modern-forestry-stockist.jpg', 'alt' => 'Modern Forestry stockist', 'href' => $site.'/pages/store-locator', 'padding' => '18px 0 12px 0'],
            ['id' => 'retail-copy', 'type' => 'text', 'html' => 'Already nearby? Find a shop carrying Modern Forestry.'],
            ['id' => 'locator-cta', 'type' => 'button', 'label' => 'Find a store', 'href' => $site.'/pages/store-locator', 'align' => 'center'],
            ['id' => 'instagram-cta', 'type' => 'button', 'label' => 'Follow Modern Forestry on Instagram', 'href' => 'https://www.instagram.com/theforestrystudio/', 'align' => 'center'],
        ];
    }

    protected function lockedComplianceFooter(): string
    {
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:22px 0 0;font-family:Arial,sans-serif;font-size:11px;line-height:1.5;color:#64748b;text-align:center;border-top:1px solid #e2e8f0;">You are receiving this email because you opted in to Modern Forestry marketing. <a href="mailto:info@theforestrystudio.com?subject=Unsubscribe" style="color:#475569;">Unsubscribe</a> · <a href="https://theforestrystudio.com/pages/privacy-policy" style="color:#475569;">Privacy</a></td></tr></table>';
    }
}
