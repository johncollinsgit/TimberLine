<?php

use App\Jobs\SendTeamShowTextRecipient;
use App\Models\MarketingCampaign;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\TeamOperationRecord;
use App\Models\TenantModuleEntitlement;
use App\Services\Marketing\MarketingDeliveryTrackingService;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\TeamShowTextAudience;
use App\Services\Marketing\TwilioIncomingMessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 11, 20)->setTimezone('America/New_York')->setTime(13, 0));
    config([
        'team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true,
        'team_operations.show_texts_enabled' => true, 'marketing.sms.enabled' => true, 'marketing.sms.dry_run' => false,
        'marketing.twilio.enabled' => true, 'marketing.twilio.account_sid' => 'AC_TEST', 'marketing.twilio.auth_token' => 'fictional',
        'marketing.twilio.from_number' => '+15550001234', 'marketing.twilio.messaging_service_sid' => null, 'marketing.twilio.senders' => [],
        'features.tenant_messaging_platform' => false,
    ]);
    [$this->tenant, $this->owner] = teamWorkspace();
    foreach (['sms', 'messaging', 'customers', 'integrations'] as $module) {
        TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => $module,
            'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    }
    $area = studioArea($this->tenant);
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $area->id,
        'kind' => 'event', 'title' => 'Fictional Holiday Market', 'source_key' => hash('sha256', Str::uuid()),
        'status' => 'accepted', 'version' => 1, 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-22',
        'data' => ['city' => 'Charleston', 'state' => 'SC', 'address' => '1 Fictional Hall', 'booth' => '2311', 'ticket_url' => 'https://tickets.example.test/market']]);
    $this->params = ['id' => $this->event->id, 'tenant' => $this->tenant->slug];
    $this->body = 'See you at booth 2311. Tickets: https://tickets.example.test/market Reply STOP to opt out.';
    Storage::fake('public', ['url' => 'https://assets.example.test/storage']);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'MM'.str_repeat('a', 32), 'status' => 'sent', 'from' => '+15550001234'], 201)]);
});

function showTextBuyer($tenant, string $phone, array $profileOverrides = [], array $orderOverrides = [], array $evidenceOverrides = []): MarketingProfile
{
    $profile = MarketingProfile::create(array_replace(['tenant_id' => $tenant->id, 'first_name' => 'Fictional Buyer',
        'phone' => $phone, 'normalized_phone' => app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->normalizePhone($phone), 'accepts_sms_marketing' => true], $profileOverrides));
    $id = (string) random_int(100000, 999999999);
    $order = Order::create(array_replace(['tenant_id' => $tenant->id, 'customer_name' => 'Fictional buyer', 'source' => 'retail', 'shopify_store_key' => 'shared',
        'shopify_order_id' => $id, 'shipping_city' => 'Charleston', 'shipping_province_code' => 'SC', 'shipping_zip' => '29418',
        'attribution_meta' => ['customer_commerce' => array_replace(['tenant_id' => $tenant->id, 'store_key' => 'shared',
            'shopify_order_id' => $id, 'source_name' => 'web', 'financial_status' => 'paid', 'total_price' => 30, 'refund_total' => 0], $evidenceOverrides)]], $orderOverrides));
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'order', 'source_id' => (string) $order->id]);

    return $profile;
}

function showTextReview($test, array $overrides = []): string
{
    $response = $test->actingAs($test->owner)->post(route('team.events.show-text.review', $test->params), $overrides + [
        'body' => $test->body, 'store' => 'shared', 'groups' => ['show', 'nearby', 'online'], 'cities' => 'Charleston', 'state' => 'SC',
    ])->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

    return $params['review'] ?? throw new RuntimeException(json_encode(session('errors')?->all()));
}

test('Teams uses confirmed local shipments and SMS consent without importing unrelated addresses', function () {
    $keep = showTextBuyer($this->tenant, '+15550001001');
    showTextBuyer($this->tenant, '+15550001001');
    showTextBuyer($this->tenant, '+15550001002', ['accepts_sms_marketing' => false]);
    showTextBuyer($this->tenant, '+15550001003', [], ['shipping_province_code' => 'WV']);
    showTextBuyer($this->tenant, '+15550001004', [], [], ['financial_status' => 'pending']);
    showTextBuyer($this->tenant, '+15550001005', [], [], ['refund_total' => 30]);
    [$foreign] = teamWorkspace();
    showTextBuyer($foreign, '+15550001006');
    $service = app(TeamShowTextAudience::class);
    $context = $service->context($this->event);
    $settings = $service->settings($this->event, $context);
    $audience = $service->build($this->tenant, $this->event, $context, $settings, ['show', 'nearby', 'online']);
    expect(array_column($audience['rows'], 'id'))->toBe([$keep->id])
        ->and($audience['omitted'])->toBe(2)->and($audience['issues'])->not->toBeEmpty();
    $review = showTextReview($this, ['save_area' => true, 'zips' => '29418']);
    $this->get(route('team.events.show-text', $this->params + ['review' => $review]))->assertOk()->assertSee('Send to 1 customer');
    expect($service->settings($this->event, $context)['zips'])->toBe(['29418']);
});

test('repeated approval sends one approved photo with the ticket link and records delivery and replies', function () {
    $customer = showTextBuyer($this->tenant, '+15550001001');
    $file = UploadedFile::fake()->image('candle.png', 40, 40);
    $asset = $this->actingAs($this->owner)->postJson(route('team.events.show-text.photo', $this->params), ['photo' => $file, 'store' => 'shared'])->assertOk()->json();
    $review = showTextReview($this, ['photo_id' => $asset['id']]);
    $this->get(route('team.events.show-text', $this->params + ['review' => $review]))->assertOk()->assertSee($asset['url']);
    $send = route('team.events.show-text.send', $this->params);
    $first = $this->post($send, ['review' => $review, 'excluded' => '[]'])->assertRedirect();
    $this->post($send, ['review' => $review, 'excluded' => '[]'])->assertRedirect($first->headers->get('Location'));
    expect(MarketingCampaign::where('source_label', 'team_show_text')->count())->toBe(1);
    Queue::assertPushed(SendTeamShowTextRecipient::class, 1);
    $campaign = MarketingCampaign::where('source_label', 'team_show_text')->sole();
    $recipient = $campaign->recipients()->sole();
    expect($recipient->marketing_profile_id)->toBe($customer->id);
    $job = new SendTeamShowTextRecipient($this->tenant->id, $campaign->id, $recipient->id);
    $job->handle(app(MarketingSmsExecutionService::class));
    $job->handle(app(MarketingSmsExecutionService::class));
    Http::assertSentCount(1);
    $providerRequest = Http::recorded()->first()[0];
    expect($providerRequest['MediaUrl'])->toBe($asset['url'])
        ->and($providerRequest['SendAsMms'])->toBe('true');
    $delivery = $campaign->deliveries()->sole();
    $ticket = 'https://tickets.example.test/market';
    $trackedTicket = collect(data_get($delivery->provider_payload, 'tracked_links', []))->firstWhere('original', $ticket);
    expect(str_contains($providerRequest['Body'], $ticket)
        || ($trackedTicket && str_contains($providerRequest['Body'], $trackedTicket['tracked_url'])))->toBeTrue();
    app(MarketingDeliveryTrackingService::class)->handleTwilioCallback(['MessageSid' => $delivery->provider_message_id, 'MessageStatus' => 'delivered'], $this->tenant->id);
    $reply = ['MessageSid' => 'SM_FICTIONAL_REPLY', 'OriginalRepliedMessageSid' => $delivery->provider_message_id,
        'From' => '+15550001001', 'To' => '+15550001234', 'Body' => 'See you there!',
        'Timestamp' => $delivery->sent_at->copy()->addSecond()->toIso8601String()];
    app(TwilioIncomingMessageService::class)->handleInbound($reply, $this->tenant->id);
    app(TwilioIncomingMessageService::class)->handleInbound($reply, $this->tenant->id);
    $message = \App\Models\MessagingConversationMessage::where('provider_message_id', $reply['MessageSid'])->sole();
    expect($message->marketing_message_delivery_id)->toBe($delivery->id);
    $this->get(route('team.events.show-text.results', $this->params + ['campaign' => $campaign->id, 'status' => 'Replied']))
        ->assertOk()->assertSee('Delivered 1')->assertSee('Replied 1')->assertSee('View reply');
    app(TwilioIncomingMessageService::class)->handleInbound(array_replace($reply, ['MessageSid' => 'SM_FICTIONAL_STOP', 'Body' => 'STOP']), $this->tenant->id);
    $this->get(route('team.events.show-text.results', $this->params + ['campaign' => $campaign->id, 'status' => 'Opted out']))
        ->assertOk()->assertSee('Opted out 1')->assertSee('Replied 1');
});

test('an opt-out after review or just before the worker prevents sending', function () {
    $profile = showTextBuyer($this->tenant, '+15550001001');
    $review = showTextReview($this);
    $profile->update(['accepts_sms_marketing' => false, 'sms_opted_out_at' => now()]);
    $this->post(route('team.events.show-text.send', $this->params), ['review' => $review])->assertStatus(409);
    Queue::assertNothingPushed();
    $profile->update(['accepts_sms_marketing' => true, 'sms_opted_out_at' => null]);
    $review = showTextReview($this);
    $this->post(route('team.events.show-text.send', $this->params), ['review' => $review])->assertRedirect();
    $campaign = MarketingCampaign::where('source_label', 'team_show_text')->sole();
    $recipient = $campaign->recipients()->sole();
    $profile->update(['accepts_sms_marketing' => false, 'sms_opted_out_at' => now()]);
    (new SendTeamShowTextRecipient($this->tenant->id, $campaign->id, $recipient->id))->handle(app(MarketingSmsExecutionService::class));
    Http::assertNothingSent();
    expect($recipient->fresh()->status)->toBe('skipped');
});

test('a preview queues only the owner and a different tenant cannot read the show', function () {
    showTextBuyer($this->tenant, '+15550001001');
    $ownerProfile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => $this->owner->email,
        'normalized_email' => strtolower($this->owner->email), 'phone' => '+15550001002', 'normalized_phone' => '5550001002', 'accepts_sms_marketing' => true]);
    $review = showTextReview($this);
    $this->post(route('team.events.show-text.preview', $this->params), ['review' => $review])->assertRedirect();
    $run = MarketingCampaign::where('source_label', 'team_show_text')->sole();
    expect($run->recipients()->sole()->marketing_profile_id)->toBe($ownerProfile->id);
    expect(data_get($run->target_snapshot, 'team_show_text.preview'))->toBeTrue();
    [$otherTenant, $otherOwner] = teamWorkspace();
    $this->actingAs($otherOwner)->get(route('team.events.show-text', ['id' => $this->event->id, 'tenant' => $otherTenant->slug]))->assertStatus(403);
});
