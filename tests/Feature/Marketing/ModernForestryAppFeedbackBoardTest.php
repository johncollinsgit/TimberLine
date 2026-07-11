<?php

use App\Models\ClientProject;
use App\Models\ClientProjectTicket;
use App\Models\Tenant;
use Database\Seeders\ModernForestryAppFeedbackSeeder;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'feedback-proxy-secret');
});

test('modern forestry app feedback board renders for signed storefront customers', function (): void {
    $this->seed(ModernForestryAppFeedbackSeeder::class);

    $query = modernForestryFeedbackSignedQuery([
        'shop' => 'theforestrystudio.myshopify.com',
        'path_prefix' => '/apps/forestry',
    ], 'feedback-proxy-secret');

    $this->get(route('marketing.shopify.v1.feedback', $query))
        ->assertOk()
        ->assertSeeText('Help shape what ships next.')
        ->assertSee('modern-forestry-app-home.png')
        ->assertSeeText('Most requested.')
        ->assertSeeText('Google and Facebook sign-in check')
        ->assertSee('data-ticket-open', false)
        ->assertSee('/apps/forestry/feedback/', false)
        ->assertDontSeeText('Everbranch Admin')
        ->assertDontSeeText('Client request triage')
        ->assertDontSeeText('ephemeral auth session');
});

test('modern forestry app feedback board accepts add only storefront submissions', function (): void {
    $this->seed(ModernForestryAppFeedbackSeeder::class);

    $tenant = Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
    $project = ClientProject::query()
        ->where('tenant_id', $tenant->id)
        ->where('title', 'Modern Forestry App Request Board')
        ->firstOrFail();

    $query = modernForestryFeedbackSignedQuery([
        'shop' => 'theforestrystudio.myshopify.com',
        'path_prefix' => '/apps/forestry',
    ], 'feedback-proxy-secret');

    $this->post(route('marketing.shopify.v1.feedback.store', $query), [
        'request_type' => 'feature',
        'title' => 'Let me reorder my favorite candle faster',
        'detail' => 'I want one tap from the app home screen to reorder a previous candle.',
        'name' => 'A beta customer',
        'email' => 'customer@example.com',
        'website' => '',
    ])
        ->assertOk()
        ->assertSeeText('Thanks. Your request was added to the board.')
        ->assertSee('data-modal-status', false)
        ->assertSeeText('Close')
        ->assertSeeText('Let me reorder my favorite candle faster');

    $ticket = ClientProjectTicket::query()
        ->where('client_project_id', $project->id)
        ->where('title', 'Let me reorder my favorite candle faster')
        ->firstOrFail();

    expect($ticket->status)->toBe('new')
        ->and($ticket->customer_visible)->toBeTrue()
        ->and($ticket->metadata['source'] ?? null)->toBe('modern_forestry_public_feedback_board')
        ->and(ClientProjectTicket::query()->where('client_project_id', $project->id)->count())->toBe(17);
});

test('modern forestry app feedback tickets are clickable and accept anonymous votes and comments', function (): void {
    $this->seed(ModernForestryAppFeedbackSeeder::class);

    $ticket = ClientProjectTicket::query()
        ->where('title', 'Offer another way to log in besides Shopify credentials')
        ->firstOrFail();

    $query = modernForestryFeedbackSignedQuery([
        'shop' => 'theforestrystudio.myshopify.com',
        'path_prefix' => '/apps/forestry',
    ], 'feedback-proxy-secret');

    $this->get(route('marketing.shopify.v1.feedback.show', ['ticket' => $ticket] + $query))
        ->assertOk()
        ->assertSeeText('Customers asked for familiar sign-in choices instead of only email-code login.')
        ->assertSeeText('Google and Facebook sign-in options have been enabled on the hosted sign-in screen.')
        ->assertSeeText('Add a comment');

    $this->post(route('marketing.shopify.v1.feedback.vote', ['ticket' => $ticket] + $query), [])
        ->assertOk()
        ->assertSeeText('Vote counted.')
        ->assertSee('data-modal-status', false)
        ->assertSeeText('Close')
        ->assertSeeText('1 votes');

    $this->post(route('marketing.shopify.v1.feedback.vote', ['ticket' => $ticket] + $query), [])
        ->assertOk();

    expect($ticket->feedbackVotes()->count())->toBe(1);

    $this->post(route('marketing.shopify.v1.feedback.comments.store', ['ticket' => $ticket] + $query), [
        'author_name' => 'Candle tester',
        'body' => 'Could Apple sign-in be an option too?',
        'website' => '',
    ])
        ->assertOk()
        ->assertSeeText('Comment added.')
        ->assertSee('data-modal-status', false)
        ->assertSeeText('Close')
        ->assertSeeText('Could Apple sign-in be an option too?');

    expect($ticket->publicComments()->count())->toBe(1);
});

/**
 * @param  array<string,mixed>  $params
 * @return array<string,mixed>
 */
function modernForestryFeedbackSignedQuery(array $params, string $secret): array
{
    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = (string) $key.'='.(is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value));
    }

    return [...$params, 'signature' => hash_hmac('sha256', implode('', $pairs), $secret)];
}
