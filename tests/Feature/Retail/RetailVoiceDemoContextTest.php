<?php

use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Services\Retail\RetailShowroomService;
use App\Services\Retail\RetailVoiceDemoContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    $this->owner = User::factory()->create(['email' => 'context-owner@everbranch.invalid', 'is_active' => true, 'email_verified_at' => now()]);
    config()->set(['retail_showroom.enabled' => true, 'retail_showroom.owner_email' => $this->owner->email,
        'retail_showroom.voice_enabled' => true, 'services.openai.api_key' => 'synthetic-demo-context-key']);
    $this->showroom = app(RetailShowroomService::class);
    $this->showroom->seed($this->owner);
    $this->url = 'http://'.parse_url(route('retail.showroom'), PHP_URL_HOST).'/retail/showroom/api/voice/session';
    $this->sdp = "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n";
    $this->input = ['sdp' => $this->sdp, 'merchant' => 'joy-fudge-company', 'page' => 'customers'];
    $this->actingAs($this->owner);
    Http::fake(['api.openai.com/v1/realtime/calls' => Http::response($this->sdp, 201)]);
    $this->sample = ['merchant' => 'joy-fudge-company', 'customers' => [['id' => 'sample-1', 'points' => 162,
        'notes' => ['Prefers chocolate gifts.'], 'reward_entries' => [['order_id' => 'DEMO-ABC12345', 'earned' => 12, 'redeemed' => 0, 'is_demo' => true]]]],
        'orders' => [['id' => 'DEMO-ABC12345', 'is_demo' => true, 'status' => 'simulated', 'customer_id' => 'sample-1',
            'lines' => [['name' => 'Chocolate fudge', 'variant' => 'Standard', 'quantity' => 1, 'line_cents' => 1200]],
            'subtotal_cents' => 1200, 'shipping_cents' => 0, 'reward_credit_cents' => 0, 'total_cents' => 1200,
            'service' => 'Sample pickup', 'created_at' => '2026-10-17T12:00:00Z']]];
    // Match the actual variant title, avoiding a test-only catalog assumption.
    $this->sample['orders'][0]['lines'][0]['variant'] = $this->showroom->catalog('joy-fudge-company')['products'][0]['variants'][0]['title'];
});

function voiceDemoSentContext(): array
{
    $request = Http::recorded()->first()[0];
    $parts = collect($request->data())->keyBy('name');
    $session = json_decode($parts['session']['contents'], true);

    return [json_decode(explode("DEMO_CONTEXT_JSON:\n", $session['instructions'], 2)[1], true), $session];
}

test('voice reads only selected tenant fictional customers notes orders and rewards without mutations or contacts', function () {
    $slug = 'joy-fudge-company';
    $site = $this->showroom->site($slug);
    $customer = WebsiteCustomer::where('tenant_id', $site->tenant_id)->first();
    $notes = $customer->notes;
    $notes['private_notes'][] = ['body' => 'Prefers gift boxes with chocolate.', 'by' => 'PRIVATE STAFF IDENTITY', 'at' => now()->toIso8601String()];
    $customer->update(['notes' => $notes]);
    $order = $this->showroom->checkout($slug, ['lines' => [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1]],
        'destination' => ['zip' => '28604', 'state' => 'NC'], 'service' => 'pickup', 'customer_id' => $customer->id, 'redeem_points' => 100], $this->owner, 'context-order-request-1234');
    $other = WebsiteCustomer::where('tenant_id', $this->showroom->site('leather-link-studio')->tenant_id)->first();
    $other->update(['first_name' => 'OTHER TENANT PRIVATE PERSON', 'notes' => ['is_demo' => true, 'private_notes' => [['body' => 'OTHER TENANT NOTE']]]]);
    WebsiteCustomer::create(['tenant_id' => $site->tenant_id, 'email' => 'real@example.com', 'first_name' => 'REAL PERSON SECRET', 'status' => 'active', 'notes' => ['is_demo' => true, 'private_notes' => [['body' => 'REAL NOTE SECRET']]]]);
    WebsiteCustomer::create(['tenant_id' => $site->tenant_id, 'email' => 'not-demo@example.invalid', 'first_name' => 'NONDEMO PERSON SECRET', 'status' => 'active', 'notes' => ['is_demo' => false]]);
    $settings = $site->fresh()->settings;
    $settings['retail_demo']['orders'][] = [...$order, 'id' => 'DEMO-FOREIGN1', 'customer_id' => $other->id];
    $settings['retail_demo']['orders'][] = [...$order, 'id' => 'DEMO-NONDEMO1', 'is_demo' => false];
    $site->update(['settings' => $settings]);
    $before = [$site->fresh()->settings, $customer->fresh()->notes];
    $this->postJson($this->url, [...$this->input, 'focus_customer' => (string) $customer->id])->assertOk()
        ->assertJsonPath('context_summary.source', 'connected_demo')->assertJsonPath('context_summary.customers', 2)->assertJsonPath('context_summary.orders', 1);
    [$context, $session] = voiceDemoSentContext();
    expect($context['demo_records']['focused_customer']['name'])->toBe('Avery Morgan');
    expect($context['demo_records']['focused_customer']['reward_points'])->toBe(57);
    expect($context['demo_records']['focused_customer']['sample_staff_notes'])->toBe(['Prefers gift boxes with chocolate.']);
    expect($context['demo_records']['orders'][0]['id'])->toBe($order['id']);
    expect($context['demo_records']['orders'][0]['customer_id'])->toBe((string) $customer->id);
    expect($context['demo_records']['focused_customer']['reward_activity'][0])->toMatchArray(['earned' => 7, 'redeemed' => 100]);
    expect(json_encode($context))->not->toContain('REAL PERSON SECRET', 'REAL NOTE SECRET', 'NONDEMO PERSON SECRET', 'OTHER TENANT', '@', 'request_key', 'actor_id', 'PRIVATE STAFF IDENTITY');
    expect($session['tools'])->toBe([]);
    expect([$site->fresh()->settings, $customer->fresh()->notes])->toBe($before);
});

test('voice includes validated tablet sample activity with canonical fictional names and customer focus', function () {
    $this->postJson($this->url, [...$this->input, 'sample_activity' => $this->sample, 'focus_customer' => 'sample-1'])
        ->assertOk()->assertJsonPath('context_summary.source', 'tablet_sample')->assertJsonPath('context_summary.orders', 1)->assertJsonPath('context_summary.focused_customer', 'Avery Morgan');
    [$context] = voiceDemoSentContext();
    expect($context['demo_records']['customers'][0]['name'])->toBe(RetailVoiceDemoContext::DEVICE_CUSTOMERS['sample-1']);
    expect($context['demo_records']['customers'][0]['reward_points'])->toBe(162);
    expect($context['demo_records']['orders'][0]['total_cents'])->toBe(1200);
    expect($context['demo_records']['focused_customer']['sample_staff_notes'])->toBe(['Prefers chocolate gifts.']);
});

test('voice rejects cross business customer and nested private field injection before the provider', function () {
    $otherId = WebsiteCustomer::where('tenant_id', $this->showroom->site('leather-link-studio')->tenant_id)->value('id');
    $bad = [];
    foreach (['merchant' => 'leather-link-studio', 'tenant_id' => 999] as $key => $value) {
        $bad[] = [...$this->input, 'sample_activity' => [...$this->sample, $key => $value]];
    }
    foreach (['email' => 'real@example.com', 'name' => 'Real person', 'id' => (string) $otherId] as $key => $value) {
        $s = $this->sample;
        $s['customers'][0][$key] = $value;
        $bad[] = [...$this->input, 'sample_activity' => $s];
    }
    $bad[] = [...$this->input, 'focus_customer' => (string) $otherId];
    $bad[] = [...$this->input, 'merchant' => null, 'focus_customer' => 'sample-1'];
    $s = $this->sample;
    $s['orders'][0]['lines'][0]['name'] = 'Another merchant secret product';
    $bad[] = [...$this->input, 'sample_activity' => $s];
    $s = $this->sample;
    $s['orders'][0]['total_cents'] = 1;
    $bad[] = [...$this->input, 'sample_activity' => $s];
    $s = $this->sample;
    $s['orders'][0]['is_demo'] = false;
    $bad[] = [...$this->input, 'sample_activity' => $s];
    $s = $this->sample;
    $s['customers'][0]['notes'] = [str_repeat('x', 1001)];
    $bad[] = [...$this->input, 'sample_activity' => $s];
    foreach ($bad as $input) {
        Cache::flush();
        $response = $this->postJson($this->url, $input)->assertUnprocessable();
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    Http::assertNothingSent();
});

test('Sawyer voice includes all retained catalog products without querying its real customer workspace', function () {
    $this->postJson($this->url, [...$this->input, 'merchant' => 'sawyer-naturals', 'page' => 'store'])->assertOk()->assertJsonPath('context_summary.source', 'none');
    [$context] = voiceDemoSentContext();
    expect($context['merchant']['catalog_sample'])->toHaveCount(62);
    expect($context['demo_records']['customers'])->toBe([])->and($context['demo_records']['orders'])->toBe([]);
});
