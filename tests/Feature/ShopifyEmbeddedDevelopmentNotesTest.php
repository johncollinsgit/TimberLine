<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\DevelopmentChangeLog;
use App\Models\DevelopmentNote;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

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

test('historical unowned development rows remain preserved and inaccessible to either tenant', function () {
    $alpha = Tenant::query()->create(['name' => 'Alpha Retail', 'slug' => 'development-legacy-alpha']);
    $beta = Tenant::query()->create(['name' => 'Beta Retail', 'slug' => 'development-legacy-beta']);
    config()->set('shopify_embedded.development_notes.allowed_shop_domains', [
        'alpha-retail.myshopify.com',
        'beta-retail.myshopify.com',
    ]);
    config()->set('shopify_embedded.development_notes.allowed_admin_emails', ['development-admin@example.test']);
    config()->set('shopify_embedded.development_notes.allowed_shopify_admin_user_ids', []);
    config()->set('shopify_embedded.development_notes.strict_email_identity', true);

    // The retired global-admin workspace had no tenant ownership. Keep its rows
    // unowned rather than assigning them to whichever shop an admin opens next.
    $legacyNote = [
        'tenant_id' => null,
        'title' => 'Historical global note',
        'body' => 'Preserve this global workspace content.',
        'created_by' => null,
        'updated_by' => null,
        'shopify_admin_user_id' => null,
        'shopify_admin_email' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $legacyNoteId = DB::table('development_notes')->insertGetId($legacyNote);
    $legacyChange = [
        'tenant_id' => null,
        'title' => 'Historical global change',
        'summary' => 'Preserve this global change log content.',
        'area' => str_repeat('legacy-area-', 15),
        'created_by' => null,
        'shopify_admin_user_id' => null,
        'shopify_admin_email' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $legacyChangeId = DB::table('development_change_logs')->insertGetId($legacyChange);

    $contexts = [];
    foreach ([[$alpha, 'alpha-retail.myshopify.com'], [$beta, 'beta-retail.myshopify.com']] as [$tenant, $shopDomain]) {
        $note = DevelopmentNote::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Scoped note '.$tenant->slug,
            'body' => 'Owned by this tenant.',
        ]);
        $change = DevelopmentChangeLog::query()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Scoped change '.$tenant->slug,
            'summary' => 'Owned by this tenant.',
        ]);
        $contexts[] = [$tenant, $shopDomain, $note->id, $change->id];
    }

    foreach ($contexts as [$tenant, $shopDomain, $noteId, $changeId]) {
        // Exercise the retail-only workspace against each independently verified
        // retail shop configuration, including its server-owned tenant mapping.
        configureEmbeddedRetailStore($tenant->id);
        config()->set('services.shopify.stores.retail.shop', $shopDomain);
        ShopifyStore::query()->where('store_key', 'retail')->update(['shop_domain' => $shopDomain]);
        $this->withHeaders([
            'Authorization' => 'Bearer '.retailShopifySessionToken([
                'email' => 'development-admin@example.test',
            ]),
        ]);

        $this->getJson(route('shopify.app.api.development-notes.bootstrap', [], false))
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonCount(1, 'data.notes')
            ->assertJsonPath('data.notes.0.id', $noteId)
            ->assertJsonCount(1, 'data.change_logs')
            ->assertJsonPath('data.change_logs.0.id', $changeId);

        $this->patchJson(route('shopify.app.api.development-notes.notes.update', ['note' => $legacyNoteId], false), [
            'title' => 'Attempted reassignment',
            'body' => 'This must not replace the historical row.',
        ])->assertNotFound();
        $this->deleteJson(route('shopify.app.api.development-notes.notes.destroy', ['note' => $legacyNoteId], false))
            ->assertNotFound();

        // Change logs expose append-only creation, with no edit/delete endpoint.
        $legacyChangeUrl = route('shopify.app.api.development-notes.change-logs.store', [], false).'/'.$legacyChangeId;
        $this->patchJson($legacyChangeUrl, ['title' => 'Attempted rewrite', 'summary' => 'Must remain unchanged.'])
            ->assertNotFound();
        $this->deleteJson($legacyChangeUrl)->assertNotFound();

        $this->assertDatabaseHas('development_notes', ['id' => $legacyNoteId] + $legacyNote);
        $this->assertDatabaseHas('development_change_logs', ['id' => $legacyChangeId] + $legacyChange);
    }
});
