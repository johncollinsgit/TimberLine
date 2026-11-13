<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantEmailSetting;
use App\Models\User;
use App\Services\Marketing\MarketingApprovalService;
use App\Services\Marketing\MarketingEmailExecutionService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['marketing.email.enabled' => true, 'marketing.email.dry_run' => false]);
    Http::preventStrayRequests();
    Http::fake(['https://api.sendgrid.com/*' => Http::response([], 202, ['X-Message-Id' => 'fixture-html-email'])]);
    $this->tenant = Tenant::create(['name' => 'Forest Goods', 'slug' => 'html-email-fixture']);
    TenantEmailSetting::create([
        'tenant_id' => $this->tenant->id, 'email_provider' => 'sendgrid', 'email_enabled' => true,
        'from_name' => 'Forest Goods', 'from_email' => 'hello@example.test', 'provider_status' => 'configured',
        'provider_config' => ['api_key' => 'SG.fixture-only', 'verified_sender_email' => 'hello@example.test'],
    ]);
    $this->html = '<html><body><h1 style="color:#234238">Meet us this weekend</h1><img src="https://media.example.test/candle.png" alt="Candle"><a href="#unsubscribe-preview">Unsubscribe</a></body></html>';
    $this->campaign = MarketingCampaign::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Weekend invitation', 'status' => 'active', 'channel' => 'email',
        'message_html' => $this->html, 'message_subject' => 'Find us at the weekend fair',
    ]);
    $variant = MarketingCampaignVariant::create([
        'campaign_id' => $this->campaign->id, 'name' => 'Invitation', 'message_text' => 'Hi {{first_name}}, visit us this weekend.', 'status' => 'active',
    ]);
    $this->profile = MarketingProfile::factory()->create([
        'tenant_id' => $this->tenant->id, 'first_name' => 'Alex', 'email' => 'alex@example.test',
        'normalized_email' => 'alex@example.test', 'accepts_email_marketing' => true,
    ]);
    $this->recipient = MarketingCampaignRecipient::create([
        'campaign_id' => $this->campaign->id, 'marketing_profile_id' => $this->profile->id,
        'variant_id' => $variant->id, 'channel' => 'email', 'status' => 'queued_for_approval',
        'recommendation_snapshot' => ['email_design' => ['html_sha256' => hash('sha256', $this->html)]],
    ]);
    $this->approver = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $this->recipient = app(MarketingApprovalService::class)->approveRecipient($this->recipient, $this->approver->id);
});

test('approved HTML campaign delivers branded HTML and text with a recipient unsubscribe footer', function (): void {
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($this->recipient, ['tenant_id' => $this->tenant->id]);

    expect($result['outcome'])->toBe('sent');
    Http::assertSent(function ($request): bool {
        $content = collect($request['content'])->keyBy('type');
        $html = $content['text/html']['value'];

        return $request['personalizations'][0]['subject'] === 'Find us at the weekend fair'
            && str_contains($content['text/plain']['value'], 'Hi Alex, visit us this weekend.')
            && str_contains($html, '<h1 style="color:#234238">')
            && str_contains($html, 'https://media.example.test/candle.png')
            && str_contains($html, '/email/unsubscribe?token=')
            && ! str_contains($html, '#unsubscribe-preview');
    });
    $delivery = MarketingEmailDelivery::where('marketing_campaign_recipient_id', $this->recipient->id)->firstOrFail();
    expect($delivery->metadata['content_format'])->toBe('html')
        ->and($delivery->metadata['html_sha256'])->toBe(hash('sha256', $this->html));
});

test('changed or removed reviewed email HTML fails before provider delivery', function (?string $replacement): void {
    $this->campaign->update(['message_html' => $replacement]);
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($this->recipient, ['tenant_id' => $this->tenant->id]);

    expect($result['reason'])->toBe('email_design_changed')
        ->and(MarketingEmailDelivery::count())->toBe(0)
        ->and($this->recipient->fresh()->send_attempt_count)->toBe(0);
    Http::assertNothingSent();
})->with(['changed' => '<html><body>Different artwork</body></html>', 'removed' => null]);

test('campaign without HTML retains plain text delivery', function (): void {
    $this->campaign->update(['message_html' => null]);
    $this->recipient->update(['recommendation_snapshot' => []]);
    $this->recipient = app(MarketingApprovalService::class)->approveRecipient($this->recipient, $this->approver->id);
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($this->recipient, ['tenant_id' => $this->tenant->id]);

    expect($result['outcome'])->toBe('sent');
    Http::assertSent(function ($request): bool {
        $plain = collect($request['content'])->firstWhere('type', 'text/plain');

        return str_contains($plain['value'], 'Hi Alex, visit us this weekend.');
    });
    expect(MarketingEmailDelivery::firstOrFail()->metadata['content_format'])->toBe('text');
});

test('HTML campaign still respects revoked email consent', function (): void {
    $this->profile->update(['accepts_email_marketing' => false]);
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($this->recipient, ['tenant_id' => $this->tenant->id]);

    expect($result['reason'])->toBe('email_not_consented')->and(MarketingEmailDelivery::count())->toBe(0);
    Http::assertNothingSent();
});
