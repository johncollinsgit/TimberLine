<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

test('Start Here keeps its setup guide and workspace content inside one Flux main column', function (string $accountMode) {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $tenant = Tenant::create(['name' => 'Fictional Printing Workspace', 'slug' => 'start-layout-'.Str::lower(Str::random(10))]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct',
        'metadata' => ['account_mode' => $accountMode]]);
    $owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($owner, ['role' => 'owner', 'membership_active' => true]);
    $response = $this->actingAs($owner)->get(route('app.start', ['tenant' => $tenant->slug]));
    $response->assertOk()->assertSee('Open your tailored setup guide')->assertSee($tenant->name);
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $guide = $xpath->query('//a[contains(normalize-space(.), "Open your tailored setup guide")]')->item(0);
    expect($guide)->not->toBeNull()->and($guide->getAttribute('href'))->toBe(route('workspace-settings.setup', ['tenant' => $tenant->slug]));
    $main = $xpath->query('ancestor::*[@data-flux-main][1]', $guide)->item(0);
    expect($main)->not->toBeNull();
    expect($xpath->query('.//*[@data-start-here]', $main)->length)->toBe(1)
        ->and($xpath->query('.//header[contains(@class,"fb-workflow-header")]', $main)->length)->toBe(1)
        ->and($xpath->query('.//form[@action="'.route('app.setup-status.update', ['tenant' => $tenant->slug]).'"]', $main)->length)->toBe(1);
    // Flux creates shell grid columns for direct siblings of data-flux-main.
    // The setup CTA must remain inside main instead of auto-placing beside it.
    expect($xpath->query('../div[not(@data-flux-main)]//a[contains(normalize-space(.), "Open your tailored setup guide")]', $main)->length)->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
})->with(['demo', 'production']);
