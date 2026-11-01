<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Mobile\ModernForestryRewardIdentity;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->forceCreate(['id' => 1, 'name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Customer']);
    $this->identity = app(ModernForestryRewardIdentity::class);
    $this->gid = 'gid://shopify/Customer/771';
    $this->session = new ModernForestryMobileCustomerSession($this->profile, 'fictional-session', [
        'source_type' => 'shopify_customer', 'shopify_customer_id' => $this->gid,
    ]);
});

it('requires an explicit authenticated account link rather than email, demo or an unrelated customer', function (): void {
    expect($this->identity->verified($this->session))->toBeFalse();
    $link = MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id,
        'source_type' => 'shopify_customer', 'source_id' => 'retail:771', 'match_method' => 'exact_email',
        'source_meta' => ['shopify_customer_gid' => $this->gid], 'confidence' => 0.9]);
    expect($this->identity->verified($this->session))->toBeFalse();
    $link->update(['match_method' => 'customer_verified_account_link', 'source_meta' => [
        'verification_method' => 'authenticated_customer_account', 'shopify_customer_gid' => $this->gid,
        'shopify_store_key' => 'retail',
        'verified_at' => now()->toIso8601String(),
    ]]);
    // A mutable import match label alone is never financial authorization.
    expect($this->identity->verified($this->session))->toBeFalse();
    $meta = ['verification_method' => 'new_profile_authenticated_customer_account', 'shopify_customer_gid' => $this->gid,
        'shopify_store_key' => 'retail', 'verified_profile_id' => $this->profile->id, 'verified_at' => now()->toIso8601String(),
        'signature' => app(\App\Services\Pos\PosVerifiedCustomerAccount::class)->signature(1, (int) $this->profile->id, 'retail', $this->gid)];
    MarketingProfileLink::create(['tenant_id' => 1, 'marketing_profile_id' => $this->profile->id,
        'source_type' => \App\Services\Pos\PosVerifiedCustomerAccount::SOURCE, 'source_id' => 'retail:771',
        'source_meta' => $meta, 'match_method' => 'customer_verified_account_link', 'confidence' => 1]);
    expect($this->identity->verified($this->session))->toBeTrue();
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other']);
    $otherSession = new ModernForestryMobileCustomerSession($other, 'fictional-session', $this->session->identity);
    $wrongSubject = new ModernForestryMobileCustomerSession($this->profile, 'fictional-session', [
        'source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/772',
    ]);
    $demo = new ModernForestryMobileCustomerSession($this->profile, 'fictional-session', [
        'source_type' => 'app_review_demo', 'shopify_customer_id' => $this->gid,
    ]);
    expect($this->identity->verified($otherSession))->toBeFalse()
        ->and($this->identity->verified($wrongSubject))->toBeFalse()
        ->and($this->identity->verified($demo))->toBeFalse();
    $external = CustomerExternalProfile::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id,
        'provider' => 'shopify', 'integration' => 'shopify_customer', 'store_key' => 'retail', 'external_customer_id' => '771', 'external_customer_gid' => $this->gid]);
    expect($this->identity->verified($this->session))->toBeFalse();
    $external->delete();
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id,
        'source_type' => 'shopify_customer', 'source_id' => $this->gid, 'match_method' => 'customer_verified_account_link',
        'source_meta' => ['verification_method' => 'authenticated_customer_account', 'shopify_customer_gid' => $this->gid,
            'shopify_store_key' => 'retail',
            'verified_at' => now()->toIso8601String()], 'confidence' => 1]);
    expect($this->identity->verified($this->session))->toBeFalse();
});

it('does not accept partial GraphQL data as fresh account authorization', function (): void {
    config(['services.shopify.customer_account.graphql_endpoint' => 'https://account.example.invalid/customer/graphql']);
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
        'data' => ['customer' => ['id' => 'gid://shopify/Customer/771']],
        'errors' => [['message' => 'fictional authorization failure']],
    ])]);
    $service = app(\App\Services\Mobile\ModernForestryMobileCustomerSessionService::class);
    $method = new ReflectionMethod($service, 'remoteCustomerIdentity');
    expect($method->invoke($service, 'fictional-account-token', [], true))->toBeNull();
});

it('rejects cross-tenant sessions and exposes no QR while rollout is disabled', function (): void {
    $other = Tenant::create(['name' => 'Other shop', 'slug' => 'other-shop']);
    $foreign = MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Foreign']);
    $foreignSession = new ModernForestryMobileCustomerSession($foreign, 'fictional-session', $this->session->identity);
    expect($this->identity->verified($foreignSession))->toBeFalse();
    $unauthorized = $this->postJson('/api/mobile/v1/modern-forestry/rewards/pos-authorization', [])->assertUnauthorized();
    expect($unauthorized->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $disabled = $this->withToken('mf-test-profile:'.$this->profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/rewards/pos-authorization', [])
        ->assertStatus(503)->assertJsonPath('error.code', 'in_person_rewards_unavailable')->assertJsonMissingPath('data.qr_payload');
    expect($disabled->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $this->withToken('mf-test-profile:'.$this->profile->id)->getJson('/api/mobile/v1/modern-forestry/rewards')
        ->assertOk()->assertJsonPath('data.pos_authorization_available', false);
});

it('links a newly created profile only after fresh Shopify authentication and explicit customer consent', function (): void {
    config(['pos_live.verified_customer_linking_enabled' => true,
        'services.shopify.customer_account.graphql_endpoint' => 'https://account.example.invalid/customer/graphql']);
    $this->profile->update(['email' => 'same@example.invalid', 'normalized_email' => 'same@example.invalid']);
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['data' => ['customer' => [
        'id' => $this->gid, 'firstName' => 'A', 'lastName' => 'Customer', 'emailAddress' => ['emailAddress' => 'same@example.invalid'],
    ]]])]);
    $url = '/api/mobile/v1/modern-forestry/rewards/verified-account';
    $this->withToken('fake-customer-account-token')->postJson($url, ['create_new_profile' => false])->assertStatus(422);
    $response = $this->withToken('fake-customer-account-token')->postJson($url, ['create_new_profile' => true])->assertOk();
    $newId = $response->json('data.profile_id');
    expect($newId)->not->toBe($this->profile->id)->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $linked = MarketingProfile::findOrFail($newId);
    expect($linked->email)->toBeNull()->and($linked->accepts_email_marketing)->toBeFalse();
    $this->withToken('fake-customer-account-token')->postJson($url, ['create_new_profile' => true])->assertOk()->assertJsonPath('data.profile_id', $newId);
    $session = new ModernForestryMobileCustomerSession($linked, 'fictional-session', ['source_type' => 'shopify_customer', 'shopify_customer_id' => $this->gid]);
    expect($this->identity->verified($session))->toBeTrue();
    $attestation = MarketingProfileLink::where('source_type', \App\Services\Pos\PosVerifiedCustomerAccount::SOURCE)->firstOrFail();
    $meta = $attestation->source_meta;
    $meta['signature'] = str_repeat('0', 64);
    $attestation->update(['source_meta' => $meta]);
    expect($this->identity->verified($session))->toBeFalse();
});

it('never promotes an email-derived or conflicting provider link to a verified wallet', function (): void {
    config(['pos_live.verified_customer_linking_enabled' => true,
        'services.shopify.customer_account.graphql_endpoint' => 'https://account.example.invalid/customer/graphql']);
    MarketingProfileLink::create(['tenant_id' => 1, 'marketing_profile_id' => $this->profile->id,
        'source_type' => 'shopify_customer', 'source_id' => 'retail:771', 'match_method' => 'exact_email']);
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['data' => ['customer' => ['id' => $this->gid]]])]);
    $this->withToken('fake-customer-account-token')->postJson('/api/mobile/v1/modern-forestry/rewards/verified-account', ['create_new_profile' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'customer_identity_review_required');
    expect(MarketingProfileLink::where('source_type', \App\Services\Pos\PosVerifiedCustomerAccount::SOURCE)->count())->toBe(0);
    $this->withToken('mf-test-profile:'.$this->profile->id)->postJson('/api/mobile/v1/modern-forestry/rewards/verified-account', ['create_new_profile' => true])->assertUnauthorized();
});

it('keeps account creation disabled without a reviewed rollout', function (): void {
    $count = MarketingProfile::count();
    $response = $this->withToken('fictional-token')->postJson('/api/mobile/v1/modern-forestry/rewards/verified-account', ['create_new_profile' => true])
        ->assertStatus(503)->assertJsonPath('error.code', 'customer_account_linking_unavailable');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->and(MarketingProfile::count())->toBe($count);
});
