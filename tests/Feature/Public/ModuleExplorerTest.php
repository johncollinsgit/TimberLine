<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;

test('anonymous visitors can browse only public safe modules without tenant data', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Secret Customer Workspace',
        'slug' => 'secret-customer-workspace',
    ]);
    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Sensitive',
        'last_name' => 'Customer',
        'email' => 'sensitive.customer@example.test',
    ]);

    $this->get(route('platform.modules.explore'))
        ->assertOk()
        ->assertSeeText('Module Explorer')
        ->assertSeeText('Workflow Automations')
        ->assertSeeText('Request a guided walkthrough')
        ->assertSee('/platform/contact?intent=walkthrough', false)
        ->assertDontSeeText('Secret Customer Workspace')
        ->assertDontSeeText('Sensitive Customer')
        ->assertDontSee('sensitive.customer@example.test')
        ->assertDontSeeText('Squarespace commerce connector placeholder');
});

test('public module detail explains price dependencies data and next steps', function (): void {
    $this->get(route('platform.modules.show', ['module' => 'workflow_automations']))
        ->assertOk()
        ->assertSeeText('Workflow Automations')
        ->assertSeeText('Price')
        ->assertSeeText('$29.00/month')
        ->assertSeeText('Google Calendar')
        ->assertSeeText('Data it uses')
        ->assertSeeText('Workflow run history')
        ->assertSeeText('What happens after you add it')
        ->assertSeeText('See it in a guided walkthrough');
});

test('internal and placeholder modules cannot be opened through the public explorer', function (string $module): void {
    $this->get(route('platform.modules.show', ['module' => $module]))
        ->assertNotFound();
})->with(['squarespace', 'square']);

test('public catalog response includes product exploration metadata without tenant identifiers', function (): void {
    $response = $this->getJson(route('platform.catalog.feed'))
        ->assertOk()
        ->assertJsonMissingPath('tenant_id');

    $module = collect($response->json('modules'))->firstWhere('key', 'workflow_automations');

    expect($module)->toBeArray()
        ->and($module)->toHaveKeys([
            'category',
            'setup_effort',
            'required_integrations',
            'dependencies',
            'is_standalone',
            'data_used',
            'industry_relevance',
            'primary_actions',
            'purchase',
        ]);
});
