<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

test('outreach review is owner-only private and never sends messages', function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $owner = User::factory()->create(['email' => 'outreach-owner@everbranch.invalid', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    config()->set('retail_showroom.enabled', true);
    config()->set('retail_showroom.owner_email', $owner->email);
    $url = route('retail.outreach');
    $this->getJson($url)->assertUnauthorized();
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actingAs($member)->get($url)->assertForbidden();
    $response = $this->actingAs($owner)->get($url)->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('0 messages sent');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $campaign = $response->viewData('campaign');
    expect($campaign['sent_count'])->toBe(0)->and($campaign['drafts'])->toHaveCount(20);
    foreach ($campaign['drafts'] as $draft) {
        expect($draft['message'])->toContain('Modern Forestry', 'coming soon');
        $response->assertSee($draft['name']);
    }
    config()->set('retail_showroom.enabled', false);
    $this->get($url)->assertForbidden();
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
