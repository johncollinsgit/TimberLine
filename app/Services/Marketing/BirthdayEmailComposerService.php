<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\TenantMarketingSetting;
use App\Services\Shopify\ShopifyEmbeddedEmailComposerService;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use Illuminate\Validation\ValidationException;

class BirthdayEmailComposerService
{
    public function __construct(
        protected ShopifyEmbeddedEmailComposerService $composer,
        protected TenantMarketingSettingsResolver $settings,
        protected MarketingTemplateRenderer $templates,
    ) {}

    /** @return array<string,mixed> */
    public function draft(int $tenantId): array
    {
        $config = $this->config($tenantId);
        $sections = $this->composer->normalizeSections($config['birthday_email_sections'] ?? self::defaultSections());
        if ($sections === []) {
            $sections = self::defaultSections();
        }

        return $this->payload($config, $sections);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $tenantId, array $input): array
    {
        $config = $this->config($tenantId);
        $revision = (int) ($input['revision'] ?? 0);
        if ($revision !== (int) ($config['birthday_email_composer_revision'] ?? 1)) {
            throw ValidationException::withMessages(['revision' => 'This email changed elsewhere. Reload before saving your edits.']);
        }
        $sections = $this->composer->normalizeSections($input['sections'] ?? []);
        if (count($sections) < 3 || count($sections) > 24) {
            throw ValidationException::withMessages(['sections' => 'Use between 3 and 24 email content blocks.']);
        }

        $config['birthday_email_subject'] = trim((string) ($input['subject'] ?? '')) ?: 'Happy Birthday from The Forestry Studio';
        $config['birthday_email_sections'] = $sections;
        $config['birthday_email_composer_revision'] = $revision + 1;
        $config['birthday_email_personalization'] = is_array($input['personalization'] ?? null) ? $input['personalization'] : [];
        // Retain a useful plaintext fallback for providers and customers that cannot display HTML.
        $config['birthday_email_body'] = $this->plainText($sections) ?: (string) ($config['birthday_email_body'] ?? 'Your birthday reward is ready.');

        TenantMarketingSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => 'birthday_campaign_config'],
            ['value' => $config],
        );
        $this->settings->flushArrayCache();

        return $this->payload($config, $sections);
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $extra
     * @return array{html:string,sections:array<int,array<string,mixed>>}
     */
    public function renderForDelivery(string $subject, array $config, MarketingProfile $profile, array $extra, string $templateKey = 'birthday_email_primary'): array
    {
        $isCatchup = $templateKey === 'birthday_email_catchup_2026';
        if ($isCatchup) {
            return ['html' => $this->renderCatchupHtml($profile, $extra), 'sections' => self::catchupSections()];
        }
        $defaultSections = $isCatchup
            ? self::catchupSections()
            : ($templateKey === 'birthday_email_followup' ? self::followupSections() : self::defaultSections());
        $sections = $this->composer->normalizeSections(
            $isCatchup ? $defaultSections : ($config['birthday_email_sections'] ?? $defaultSections)
        );
        if ($sections === []) {
            $sections = $defaultSections;
        }
        $rendered = $this->renderValue($sections, $profile, $extra);
        $composed = $this->composer->compose($subject, '', 'sections', $rendered, null);

        return [
            'html' => str_replace('</body>', $this->lockedComplianceFooter().'</body>', (string) $composed['html']),
            'sections' => $rendered,
        ];
    }

    /** @param array<int,array<string,mixed>> $sections
     * @return array<int,array<string,mixed>>
     */
    public function sectionsForTestSend(array $sections): array
    {
        return [...$sections, ['id' => 'locked_compliance_footer', 'type' => 'text', 'html' => 'You are receiving this email because you opted in to Modern Forestry marketing. <a href="mailto:info@theforestrystudio.com?subject=Unsubscribe">Unsubscribe</a> · <a href="https://theforestrystudio.com/pages/privacy-policy">Privacy</a>']];
    }

    /** @return array<int,array<string,mixed>> */
    public static function defaultSections(): array
    {
        $site = 'https://theforestrystudio.com';
        $hero = 'https://backstage.theforestrystudio.com/images/marketing/birthday-mountain-candle-hero.png';
        $candle = 'https://cdn.shopify.com/s/files/1/2081/2479/files/IMG_1086.jpg?v=1710945776';

        return [
            ['id' => 'birthday-hero', 'type' => 'image', 'imageUrl' => $hero, 'alt' => 'A warm Modern Forestry candle in a mountain cabin', 'href' => $site.'/collections/all', 'padding' => '0 0 18px 0'],
            ['id' => 'birthday-heading', 'type' => 'heading', 'text' => 'Happy Birthday, {{ first_name }}!', 'align' => 'center'],
            ['id' => 'birthday-intro', 'type' => 'text', 'html' => 'We hope your day feels warm, bright, and entirely yours.'],
            ['id' => 'birthday-candle', 'type' => 'image', 'imageUrl' => $candle, 'alt' => 'Hand-poured Modern Forestry candle', 'href' => $site.'/collections/all', 'padding' => '8px 0 18px 0'],
            ['id' => 'birthday-reward-heading', 'type' => 'heading', 'text' => 'Your birthday gift is ready', 'align' => 'center'],
            ['id' => 'birthday-reward-copy', 'type' => 'text', 'html' => '{{ birthday_reward_message }}'],
            ['id' => 'birthday-cta', 'type' => 'button', 'label' => '{{ birthday_cta_label }}', 'href' => '{{ reward_apply_url }}', 'align' => 'center'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function followupSections(): array
    {
        $sections = self::defaultSections();
        $sections[1]['text'] = 'Your birthday gift is still waiting, {{ first_name }}';
        $sections[2]['html'] = 'There is still time to make your birthday gift part of a slow, cozy evening.';
        $sections[4]['text'] = 'A little birthday time remains';

        return $sections;
    }

    /** @return array<int,array<string,mixed>> */
    public static function catchupSections(): array
    {
        return [
            ['id' => 'birthday-heading', 'type' => 'heading', 'text' => 'Happy belated birthday {{ first_name }}!', 'align' => 'center'],
            ['id' => 'birthday-apology', 'type' => 'text', 'html' => 'We are sorry your birthday message arrived late. Our system had a hiccup and you deserved better.'],
            ['id' => 'birthday-gratitude', 'type' => 'text', 'html' => 'As a small business every person who chooses Modern Forestry means so much to us. We appreciate you and we are grateful to be a small part of the moments you make your own.'],
            ['id' => 'birthday-gift', 'type' => 'heading', 'text' => 'A $10 birthday gift just for you', 'align' => 'center'],
            ['id' => 'birthday-reward', 'type' => 'text', 'html' => '{{ birthday_reward_message }}'],
            ['id' => 'birthday-expiry', 'type' => 'text', 'html' => 'Claim and use it by {{ expiry_date }}.'],
            ['id' => 'birthday-cta', 'type' => 'button', 'label' => '{{ birthday_cta_label }}', 'href' => '{{ reward_apply_url }}', 'align' => 'center'],
        ];
    }

    /** @param array<string,mixed> $extra */
    protected function renderCatchupHtml(MarketingProfile $profile, array $extra): string
    {
        $firstName = e(str_replace(',', '', trim((string) ($profile->first_name ?: 'friend'))));
        $expiry = e(str_replace(',', '', (string) ($extra['expiry_date'] ?? '14 days after this email')));
        $claimUrl = e((string) ($extra['reward_apply_url'] ?? 'https://theforestrystudio.com/pages/birthday-gift'));
        $logoUrl = 'https://app.theeverbranch.com/brand/modern-forestry-logo-white.png';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            .'<body style="margin:0;padding:24px 12px;background:#f2f0eb;color:#25362d;font-family:Arial,sans-serif;">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
            .'<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fffdf8;border:1px solid #e5e0d5;">'
            .'<tr><td align="center" style="background:#183d2e;padding:18px 24px 12px;">'
            .'<a href="https://theforestrystudio.com" style="text-decoration:none;"><img src="'.$logoUrl.'" width="170" alt="Modern Forestry" style="display:block;width:170px;max-width:100%;height:auto;border:0;"></a>'
            .'</td></tr>'
            .'<tr><td style="padding:38px 40px 30px;">'
            .'<p style="margin:0 0 14px;text-align:center;color:#6f805b;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">A little birthday note</p>'
            .'<h1 style="margin:0 0 26px;text-align:center;color:#183d2e;font-family:Georgia,serif;font-size:34px;line-height:1.2;font-weight:normal;">Happy belated birthday '.$firstName.'!</h1>'
            .'<p style="margin:0 0 20px;font-size:16px;line-height:1.7;">We are sorry your birthday message arrived late. Our system had a hiccup and you deserved better.</p>'
            .'<p style="margin:0 0 25px;font-size:16px;line-height:1.7;">As a small business every person who chooses Modern Forestry means so much to us. We appreciate you and we are grateful to be a small part of the moments you make your own.</p>'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f5ee;border:1px solid #dce7d8;"><tr><td align="center" style="padding:25px 24px;">'
            .'<p style="margin:0 0 8px;color:#506e50;font-size:12px;font-weight:bold;letter-spacing:1.6px;text-transform:uppercase;">A birthday gift for you</p>'
            .'<p style="margin:0 0 10px;color:#183d2e;font-family:Georgia,serif;font-size:38px;line-height:1.15;">$10 off</p>'
            .'<p style="margin:0;color:#364b3b;font-size:15px;line-height:1.5;">Sign in on your birthday gift page. We will add your coupon and show you the way to our candle bundles.</p>'
            .'</td></tr></table>'
            .'<p style="margin:24px 0;text-align:center;color:#475b49;font-size:14px;line-height:1.6;">Claim and use your coupon by <strong>'.$expiry.'</strong>.</p>'
            .'<p style="margin:0 0 23px;text-align:center;"><a href="'.$claimUrl.'" style="display:inline-block;background:#183d2e;color:#ffffff;padding:15px 29px;font-size:15px;font-weight:bold;text-decoration:none;">Open your birthday gift</a></p>'
            .'<p style="margin:0 0 23px;text-align:center;color:#5c6b5e;font-size:13px;line-height:1.6;">Your coupon can be used with eligible free shipping offers. It cannot be combined with Candle Cash or other discounts.</p>'
            .'<hr style="border:0;border-top:1px solid #e5e0d5;margin:28px 0 23px;">'
            .'<p style="margin:0;color:#25362d;font-size:16px;line-height:1.6;">Thank you for being here.<br>With gratitude<br><strong>The Modern Forestry team</strong></p>'
            .$this->lockedComplianceFooter()
            .'</td></tr></table></td></tr></table></body></html>';
    }

    /** @return array<string,mixed> */
    protected function config(int $tenantId): array
    {
        return $this->settings->array('birthday_campaign_config', $tenantId, []);
    }

    /** @param array<string,mixed> $config @param array<int,array<string,mixed>> $sections
     * @return array<string,mixed>
     */
    protected function payload(array $config, array $sections): array
    {
        $subject = trim((string) ($config['birthday_email_subject'] ?? '')) ?: 'Happy Birthday from The Forestry Studio';
        $composed = $this->composer->compose($subject, '', 'sections', $sections, null);

        return [
            'id' => 0, 'name' => 'Birthday email', 'subject' => $subject, 'sections' => $sections,
            'personalization' => (array) ($config['birthday_email_personalization'] ?? ['first_name_token' => '{{ first_name }}']),
            'revision' => max(1, (int) ($config['birthday_email_composer_revision'] ?? 1)),
            'rendered_html' => str_replace('</body>', $this->lockedComplianceFooter().'</body>', (string) $composed['html']),
            'locked_footer' => true, 'sender' => ['from_email' => 'info@theforestrystudio.com', 'from_name' => 'Modern Forestry'],
        ];
    }

    protected function renderValue(mixed $value, MarketingProfile $profile, array $extra): mixed
    {
        if (is_string($value)) {
            return $this->templates->renderText($value, $profile, $extra);
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->renderValue($item, $profile, $extra);
        }

        return $value;
    }

    /** @param array<int,array<string,mixed>> $sections */
    protected function plainText(array $sections): string
    {
        return trim(collect($sections)->map(fn ($section) => strip_tags((string) ($section['text'] ?? $section['html'] ?? $section['label'] ?? '')))->filter()->implode("\n\n"));
    }

    protected function lockedComplianceFooter(): string
    {
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:22px 0 0;font-family:Arial,sans-serif;font-size:11px;line-height:1.5;color:#64748b;text-align:center;border-top:1px solid #e2e8f0;">You are receiving this email because you opted in to Modern Forestry marketing. <a href="mailto:info@theforestrystudio.com?subject=Unsubscribe" style="color:#475569;">Unsubscribe</a> · <a href="https://theforestrystudio.com/pages/privacy-policy" style="color:#475569;">Privacy</a></td></tr></table>';
    }
}
