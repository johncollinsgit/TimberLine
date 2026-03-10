<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingProfile;
use App\Models\MarketingMessageTemplate;

class MarketingTemplateRenderer
{
    public function __construct(
        protected MarketingProfileAnalyticsService $analyticsService
    ) {
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function renderText(string $text, MarketingProfile $profile, array $extra = []): string
    {
        $variables = $this->variablesForProfile($profile, $extra);

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $matches) use ($variables): string {
            $key = strtolower((string) ($matches[1] ?? ''));
            $value = $variables[$key] ?? '';
            return trim((string) $value);
        }, $text) ?? $text;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function variablesForProfile(MarketingProfile $profile, array $extra = []): array
    {
        $metrics = $this->analyticsService->metricsForProfile($profile);
        $firstName = trim((string) $profile->first_name);
        if ($firstName === '') {
            $firstName = 'there';
        }

        $values = [
            'first_name' => $firstName,
            'event_name' => (string) ($metrics['last_event_name'] ?? ''),
            'favorite_scent' => '',
            'last_product_name' => '',
            'coupon_code' => '',
            'days_since_last_order' => (string) ($metrics['days_since_last_order'] ?? ''),
            'total_orders' => (string) ($metrics['total_orders'] ?? 0),
            'total_spent' => (string) ($metrics['total_spent'] ?? 0),
        ];

        foreach ($extra as $key => $value) {
            $values[strtolower((string) $key)] = $value;
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function renderTemplate(MarketingMessageTemplate $template, MarketingProfile $profile, array $extra = []): string
    {
        return $this->renderText($template->template_text, $profile, $extra);
    }

    public function renderCampaignMessage(
        MarketingCampaign $campaign,
        string $messageText,
        MarketingProfile $profile
    ): string {
        return $this->renderText($messageText, $profile, [
            'coupon_code' => $campaign->coupon_code ?: '',
        ]);
    }
}
