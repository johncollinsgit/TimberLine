<?php

use App\Models\CustomerServiceMembershipVisit;
use App\Models\MarketingProfile;
use App\Models\ServicePlanTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\ServiceMembershipService;

test('service plan offers preserve an immutable version, accept selected addons, and generate one due job', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Membership Tenant', 'slug' => 'membership-tenant']);
    $owner = User::factory()->create(['role' => 'admin']);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    $customer = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Avery', 'last_name' => 'Stone', 'email' => 'avery@example.test']);
    $template = ServicePlanTemplate::query()->create(['tenant_id' => $tenant->id, 'created_by_user_id' => $owner->id, 'slug' => 'complete-care', 'name' => 'Complete Care']);
    $service = app(ServiceMembershipService::class);

    $version = $service->createVersion($tenant, $template, $owner, [
        'price' => 299, 'billing_frequency' => 'annual', 'visit_interval_days' => 1,
        'visit_title' => 'Complete Care inspection', 'priority' => 'priority', 'benefits' => ['Annual inspection'], 'terms' => 'Customer terms',
    ], [['name' => 'Surge protection', 'price' => 49, 'billing_frequency' => 'one_time', 'max_quantity' => 1]]);
    $offerResult = $service->createOffer($tenant, $customer, $version, $owner, now()->addDay());
    $accepted = $service->acceptOffer($offerResult['offer'], [['id' => $version->addons->first()->id, 'quantity' => 1]], 'Avery Stone', '127.0.0.1', 'Pest');
    $membership = $service->activateOffer($accepted, $owner, 'QB-1001');
    $membership->forceFill(['next_visit_due_on' => now()->toDateString()])->save();

    expect($accepted->status)->toBe('accepted')
        ->and($membership->status)->toBe('active')
        ->and(data_get($membership->snapshot, 'plan.price'))->toBe(299);

    expect($service->generateDueVisits((int) $tenant->id, 0))->toBe(['visits_created' => 1, 'jobs_created' => 1]);
    expect($service->generateDueVisits((int) $tenant->id, 0))->toBe(['visits_created' => 0, 'jobs_created' => 0]);
    expect(CustomerServiceMembershipVisit::query()->forTenantId($tenant->id)->count())->toBe(1)
        ->and(CustomerServiceMembershipVisit::query()->forTenantId($tenant->id)->firstOrFail()->job)->not->toBeNull();
});

test('service plan offers refuse expired acceptance', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Expired Offer Tenant', 'slug' => 'expired-offer-tenant']);
    $owner = User::factory()->create(['role' => 'admin']);
    $customer = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Avery', 'email' => 'avery@example.test']);
    $template = ServicePlanTemplate::query()->create(['tenant_id' => $tenant->id, 'created_by_user_id' => $owner->id, 'slug' => 'annual', 'name' => 'Annual']);
    $service = app(ServiceMembershipService::class);
    $version = $service->createVersion($tenant, $template, $owner, ['price' => 99, 'billing_frequency' => 'annual', 'visit_interval_days' => 365, 'visit_title' => 'Annual service', 'priority' => 'normal']);
    $offer = $service->createOffer($tenant, $customer, $version, $owner, now()->subMinute())['offer'];

    expect(fn () => $service->acceptOffer($offer, [], 'Avery Stone', null, null))->toThrow(InvalidArgumentException::class);
});
