<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\DevelopmentChangeLog;
use App\Models\DevelopmentNote;
use App\Models\Tenant;

function developmentNotesApiHeaders(array $tokenOverrides = []): array
{
    return [
        'Authorization' => 'Bearer '.retailShopifySessionToken($tokenOverrides),
    ];
}

beforeEach(function () {
    $this->withoutVite();
});

test('shopify embedded development notes route renders internal workspace shell', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-development-notes-view',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.development-notes', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Internal Workspace')
        ->assertSeeText('Project Notes')
        ->assertSeeText('Change Log');
});

test('shopify embedded development notes access endpoint requires bearer token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-development-notes-access-auth',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this->getJson(route('shopify.app.api.development-notes.access', [], false))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('shopify embedded development notes api denies non-allowlisted identities', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-development-notes-identity-block',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('shopify_embedded.development_notes.allowed_shop_domains', ['modernforestry.myshopify.com']);
    config()->set('shopify_embedded.development_notes.allowed_admin_emails', ['tableviewfarms@gmail.com']);
    config()->set('shopify_embedded.development_notes.allowed_shopify_admin_user_ids', []);
    config()->set('shopify_embedded.development_notes.strict_email_identity', true);

    $this
        ->withHeaders(developmentNotesApiHeaders([
            'email' => 'not-allowlisted@example.test',
        ]))
        ->getJson(route('shopify.app.api.development-notes.bootstrap', [], false))
        ->assertStatus(403)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('reason', 'identity_not_allowlisted');
});

test('shopify embedded development notes supports note crud and newest-first change logs', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-development-notes-crud',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('shopify_embedded.development_notes.allowed_shop_domains', ['modernforestry.myshopify.com']);
    config()->set('shopify_embedded.development_notes.allowed_admin_emails', ['tableviewfarms@gmail.com']);
    config()->set('shopify_embedded.development_notes.allowed_shopify_admin_user_ids', []);
    config()->set('shopify_embedded.development_notes.strict_email_identity', true);

    $headers = developmentNotesApiHeaders([
        'email' => 'tableviewfarms@gmail.com',
    ]);

    $created = $this
        ->withHeaders($headers)
        ->postJson(route('shopify.app.api.development-notes.notes.store', [], false), [
            'title' => 'Initial scope capture',
            'body' => 'Phase checklist confirmed for migration baseline.',
        ]);

    $created->assertStatus(201)
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.note.title', 'Initial scope capture');

    $noteId = (int) $created->json('data.note.id');
    expect($noteId)->toBeGreaterThan(0);

    $this->assertDatabaseHas('development_notes', [
        'id' => $noteId,
        'tenant_id' => $tenant->id,
        'title' => 'Initial scope capture',
    ]);

    $this
        ->withHeaders($headers)
        ->patchJson(route('shopify.app.api.development-notes.notes.update', ['note' => $noteId], false), [
            'title' => 'Updated scope capture',
            'body' => 'Phase checklist confirmed and updated after kickoff review.',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.note.title', 'Updated scope capture');

    $this
        ->withHeaders($headers)
        ->postJson(route('shopify.app.api.development-notes.change-logs.store', [], false), [
            'title' => 'Created development notes workspace',
            'summary' => 'Added foundational notes and change log pages.',
            'area' => 'Shopify Embedded',
        ])
        ->assertStatus(201)
        ->assertJsonPath('ok', true);

    $this
        ->withHeaders($headers)
        ->postJson(route('shopify.app.api.development-notes.change-logs.store', [], false), [
            'title' => 'Added strict identity allowlist',
            'summary' => 'Restricted API access to configured admin identity.',
            'area' => 'Auth',
        ])
        ->assertStatus(201)
        ->assertJsonPath('ok', true);

    $bootstrap = $this
        ->withHeaders($headers)
        ->getJson(route('shopify.app.api.development-notes.bootstrap', [], false));

    $bootstrap->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.notes.0.title', 'Updated scope capture')
        ->assertJsonPath('data.change_logs.0.title', 'Added strict identity allowlist');

    $this
        ->withHeaders($headers)
        ->deleteJson(route('shopify.app.api.development-notes.notes.destroy', ['note' => $noteId], false))
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(DevelopmentNote::query()->whereKey($noteId)->exists())->toBeFalse();
    expect(DevelopmentChangeLog::query()->where('tenant_id', $tenant->id)->count())->toBe(2);
});
