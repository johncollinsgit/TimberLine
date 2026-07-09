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
        ->assertSeeText('Requests, fixes, and what shipped.')
        ->assertSeeText('QA: confirm Google appears beside Facebook on the live sign-in sheet')
        ->assertDontSeeText('Everbranch Admin')
        ->assertDontSeeText('Client request triage');
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
        ->assertSeeText('Let me reorder my favorite candle faster');

    $ticket = ClientProjectTicket::query()
        ->where('client_project_id', $project->id)
        ->where('title', 'Let me reorder my favorite candle faster')
        ->firstOrFail();

    expect($ticket->status)->toBe('new')
        ->and($ticket->customer_visible)->toBeTrue()
        ->and($ticket->metadata['source'] ?? null)->toBe('modern_forestry_public_feedback_board')
        ->and(ClientProjectTicket::query()->where('client_project_id', $project->id)->count())->toBe(16);
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
