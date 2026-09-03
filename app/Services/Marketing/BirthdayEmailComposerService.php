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
        $defaultSections = $templateKey === 'birthday_email_followup'
            ? self::followupSections()
            : self::defaultSections();
        $sections = $this->composer->normalizeSections($config['birthday_email_sections'] ?? $defaultSections);
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
