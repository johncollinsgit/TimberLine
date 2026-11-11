<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Services\Marketing\CustomerSourceOwnershipReconciliation;
use App\Services\Marketing\SquareCustomerIdentityService;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

it('restores only proven original links and preserves conflicts, consent and rewards on replay', function (): void {
    [$tenant, $owner] = teamWorkspace();
    [$other, $otherOwner] = teamWorkspace();
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Original', 'accepts_email_marketing' => false]);
    $source = SquareCustomer::create(['tenant_id' => $tenant->id, 'square_customer_id' => 'original-customer']);
    $link = MarketingProfileLink::create(['marketing_profile_id' => $profile->id, 'source_type' => 'square_customer', 'source_id' => $source->square_customer_id, 'source_meta' => ['original' => 'retained']]);
    $unknown = MarketingProfileLink::create(['marketing_profile_id' => $profile->id, 'source_type' => 'manual_customer', 'source_id' => 'unknown-owner']);
    $service = app(CustomerSourceOwnershipReconciliation::class);
    expect($service->batch($tenant->id, $owner)['restored'])->toBe(1);
    expect($link->fresh()->tenant_id)->toBeNull();
    $result = $service->batch($tenant->id, $owner, apply: true);
    expect($result['restored'])->toBe(1)->and($result['review_required'])->toBe(1);
    expect($link->fresh()->tenant_id)->toBe($tenant->id)->and($link->fresh()->source_meta['original'])->toBe('retained')->and($unknown->fresh()->tenant_id)->toBeNull();
    expect($profile->fresh()->accepts_email_marketing)->toBeFalse();
    expect($service->batch($tenant->id, $owner, apply: true)['restored'])->toBe(0);
    expect(fn () => $service->batch($tenant->id, $otherOwner, apply: true))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $conflict = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Conflicting']);
    MarketingProfileLink::create(['marketing_profile_id' => $conflict->id, 'source_type' => 'square_customer', 'source_id' => 'original-customer']);
    $result = $service->batch($tenant->id, $owner, apply: true);
    expect(array_column($result['issues'], 'reason'))->toContain('conflicting_original_associations');
});

it('does not create an unmatched Square customer over an unresolved original source association', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Existing']);
    MarketingProfileLink::create(['marketing_profile_id' => $profile->id, 'source_type' => 'square_customer', 'source_id' => 'legacy-original']);
    $before = MarketingProfile::forTenantId($tenant->id)->count();
    $result = app(SquareCustomerIdentityService::class)->sync(['first_name' => 'Provider', 'source_links' => [['source_type' => 'square_customer', 'source_id' => 'legacy-original']]],
        $tenant->id, false, ['create_unmatched' => true, 'actor_id' => $owner->id]);
    expect($result['status'])->toBe('review')->and(MarketingProfile::forTenantId($tenant->id)->count())->toBe($before);
});
