<?php

use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingReceipt;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Onboarding\SelfServiceTrialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    Mail::fake();
    Http::preventStrayRequests();
    config()->set([
        'app.url' => 'https://app.theeverbranch.com',
        'self_service.registration_enabled' => true, 'self_service.trial_enabled' => true,
        'marketplace.enabled' => false, 'marketplace.checkout_enabled' => false,
        'marketplace.host' => '', 'marketplace.account_preview_user_ids' => [],
    ]);
    $this->owner = User::factory()->create([
        'is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'generic'],
    ]);
    $this->actingAs($this->owner)->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Private trial unrelated to example data', 'template_key' => 'generic', 'start_path' => 'self',
    ])->assertRedirect();
    $this->tenant = $this->owner->tenants()->sole();
    $this->actingAs($this->owner->refresh());
    expect(app(SelfServiceTrialService::class)->status($this->tenant)['state'])->toBe('active');
    $this->previewUrl = 'https://app.theeverbranch.com/marketplace/preview';
});

test('an active or expired trial may view only its separately granted static preview without tenant queries', function (bool $expired): void {
    if ($expired) {
        $this->travel(15)->days();
    }
    $memberships = DB::table('tenant_user')->get()->toJson();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    // The trial itself is never a Marketplace preview grant.
    $this->get($this->previewUrl)->assertNotFound();
    config()->set('marketplace.account_preview_user_ids', [$this->owner->id]);
    $response = $this->withSession(['tenant_id' => $this->tenant->id])
        ->get($this->previewUrl.'?tab=orders&detail=statement-willow')
        ->assertOk()->assertSeeText('Private preview · Example data')->assertSeeText('Example workspace')
        ->assertSeeText('Read only · Storefront off · Payments off')
        ->assertDontSeeText($this->tenant->name)->assertDontSee('<form', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSessionHas('tenant_id', $this->tenant->id);
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    foreach ($queries as $query) {
        expect($query)->not->toMatch('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i')
            ->and($query)->not->toMatch('/(?:from|join)\s+["`]?tenant(?:s|_)/i')
            ->and($query)->not->toContain('landlord_operator_actions', 'marketplace_', 'website_', 'shopify');
    }

    // No adjacent merchant route or method-spoofed write inherits the exception.
    $this->get('https://app.theeverbranch.com/marketplace/operations')->assertStatus(423);
    $this->post($this->previewUrl)->assertStatus(405);
    $this->post($this->previewUrl, ['_method' => 'GET'])->assertStatus(423);
    config()->set('marketplace.account_preview_user_ids', []);
    $this->get($this->previewUrl)->assertNotFound();
    expect(DB::table('tenant_user')->get()->toJson())->toBe($memberships)
        ->and(app(SelfServiceTrialService::class)->status($this->tenant)['state'])->toBe($expired ? 'expired' : 'active')
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($this->tenant))->toBeFalse()
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->count())->toBe(0)
        ->and(TenantBillingReceipt::withoutGlobalScopes()->count())->toBe(0)
        ->and(TenantBillingFulfillment::withoutGlobalScopes()->count())->toBe(0)
        ->and(config('marketplace.enabled'))->toBeFalse()
        ->and(config('marketplace.checkout_enabled'))->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
})->with(['active' => false, 'expired' => true]);
