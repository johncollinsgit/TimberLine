<?php

use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Services\Marketing\CustomerCommerceLinkService;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Service merchant', 'slug' => 'commerce-link-merchant']);
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'approved_at' => now()]);
    $this->admin->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    $this->profile = MarketingProfile::query()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Verified customer']);
    $this->customer = WebsiteCustomer::query()->create(['tenant_id' => $this->tenant->id, 'email' => 'verified@example.com', 'status' => 'active']);
});

test('reviewed source linking is audited and replay safe and never merges source customers or consent', function (): void {
    $service = app(CustomerCommerceLinkService::class);
    $before = $this->customer->fresh()->getRawOriginal();
    $link = $service->link($this->admin, $this->tenant->id, $this->profile, 'website_customer', (string) $this->customer->id, 'Verified the source account with the customer.');
    $again = $service->link($this->admin, $this->tenant->id, $this->profile, 'website_customer', '00'.$this->customer->id, 'Verified the source account with the customer.');
    expect($again->id)->toBe($link->id)
        ->and($this->customer->fresh()->getRawOriginal())->toBe($before)
        ->and($this->profile->fresh()->accepts_email_marketing)->toBeFalse()
        ->and(MarketingIdentityReview::query()->count())->toBe(1)
        ->and(MarketingIdentityReview::query()->first()->reviewed_by)->toBe($this->admin->id)
        ->and(app(CustomerCommerceSnapshotService::class)->forWebsiteCustomer($this->customer, $this->tenant->id)['customer_id'])->toBe($this->profile->id);
});

test('membership and source tenant bounds are enforced even outside HTTP middleware', function (): void {
    $service = app(CustomerCommerceLinkService::class);
    $this->admin->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect(fn () => $service->link($this->admin, $this->tenant->id, $this->profile, 'website_customer', (string) $this->customer->id, 'Verified account.'))->toThrow(HttpException::class);
    $this->admin->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => true, 'role' => 'member']);
    expect($service->canLink($this->admin, $this->tenant->id))->toBeFalse();
    $this->admin->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'admin']);
    $other = Tenant::query()->create(['name' => 'Other merchant', 'slug' => 'other-commerce-link']);
    $customer = WebsiteCustomer::query()->create(['tenant_id' => $other->id, 'email' => 'same@example.com']);
    expect(fn () => $service->link($this->admin, $this->tenant->id, $this->profile, 'website_customer', (string) $customer->id, 'Verified account.'))->toThrow(ModelNotFoundException::class);
    expect(MarketingProfileLink::query()->count())->toBe(0);
});

test('link conflicts cannot reassign a purchase to a different customer', function (): void {
    $service = app(CustomerCommerceLinkService::class);
    $service->link($this->admin, $this->tenant->id, $this->profile, 'website_customer', (string) $this->customer->id, 'Verified source customer.');
    $other = MarketingProfile::query()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other customer']);
    expect(fn () => $service->link($this->admin, $this->tenant->id, $other, 'website_customer', (string) $this->customer->id, 'Trying another customer.'))->toThrow(ValidationException::class);
    expect(MarketingProfileLink::query()->first()->marketing_profile_id)->toBe($this->profile->id)
        ->and(MarketingIdentityReview::query()->count())->toBe(1);
});

test('staff customer page displays the shared section and only an authorized owner can submit source review', function (): void {
    $this->actingAs($this->admin)->withSession(['tenant_id' => $this->tenant->id])
        ->get(route('marketing.customers.show', $this->profile))->assertOk()
        ->assertSeeText('Purchases')->assertSeeText('Connect a customer or purchase source');
    $this->actingAs($this->admin)->withSession(['tenant_id' => $this->tenant->id])
        ->post(route('marketing.customers.commerce-sources.store', $this->profile), [
            'source_type' => 'website_customer', 'source_id' => (string) $this->customer->id,
            'reason' => 'Verified source customer account.', 'identity_confirmed' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
    expect(MarketingProfileLink::query()->count())->toBe(1);
});

test('fresh reviewer authority exactly matches current user and tenant membership policy', function (string $role, ?string $membershipRole, bool $active, bool $membershipActive, bool $wrongTenant) {
    $service = app(CustomerCommerceLinkService::class);
    $actor = User::factory()->create(['role' => $role, 'is_active' => $active, 'email_verified_at' => null]);
    if ($membershipRole !== null) {
        $tenant = $wrongTenant ? Tenant::create(['name' => 'Other reviewer tenant', 'slug' => 'reviewer-other']) : $this->tenant;
        $actor->tenants()->attach($tenant->id, ['role' => $membershipRole, 'membership_active' => $membershipActive]);
    }
    $expected = $service->canLink(User::find($actor->id), $this->tenant->id);
    \Illuminate\Support\Facades\DB::enableQueryLog();
    \Illuminate\Support\Facades\DB::flushQueryLog();
    $actual = $service->canLinkReviewer($actor->id, $this->tenant->id);
    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();
    expect($actual)->toBe($expected)->and($queries)->toHaveCount(1);
})->with([
    'tenant owner' => ['member', 'owner', true, true, false],
    'legacy owner' => ['member', 'tenant_owner', true, true, false],
    'tenant admin' => ['member', 'admin', true, true, false],
    'inactive membership' => ['member', 'owner', true, false, false],
    'inactive actor' => ['member', 'admin', false, true, false],
    'ordinary member' => ['member', 'member', true, true, false],
    'global admin without tenant grant' => ['admin', null, true, true, false],
    'wrong tenant owner' => ['member', 'owner', true, true, true],
    'active platform admin' => ['platform_admin', null, true, false, false],
    'inactive platform admin' => ['platform_admin', null, false, true, false],
    'case differs in global role' => ['Platform_admin', null, true, true, false],
    'case differs in tenant role' => ['member', 'Admin', true, true, false],
]);

test('reviewer authority stays fresh after revocation and rejects missing or invalid identities', function () {
    $service = app(CustomerCommerceLinkService::class);
    expect($service->canLinkReviewer($this->admin->id, $this->tenant->id))->toBeTrue();
    $this->admin->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect($service->canLinkReviewer($this->admin->id, $this->tenant->id))->toBeFalse();
    $this->admin->update(['role' => 'platform_admin']);
    expect($service->canLinkReviewer($this->admin->id, $this->tenant->id))->toBeTrue()
        ->and($service->canLinkReviewer($this->admin->id, $this->tenant->id + 100000))->toBeTrue();
    $this->admin->update(['is_active' => false]);
    expect($service->canLinkReviewer($this->admin->id, $this->tenant->id))->toBeFalse()
        ->and($service->canLinkReviewer(99999999, $this->tenant->id))->toBeFalse()
        ->and($service->canLinkReviewer(null, $this->tenant->id))->toBeFalse()
        ->and($service->canLinkReviewer($this->admin->id, 0))->toBeFalse();
});
