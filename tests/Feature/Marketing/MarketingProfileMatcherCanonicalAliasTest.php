<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\MarketingProfileMatcher;

beforeEach(function (): void {
    Tenant::query()->create(['id' => 1, 'name' => 'Primary Tenant', 'slug' => 'primary-tenant']);
    Tenant::query()->create(['id' => 2, 'name' => 'Other Tenant', 'slug' => 'other-tenant']);
});

test('alias and survivor identifier matches deduplicate to the canonical customer', function (): void {
    $survivor = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'alias@example.com',
        'normalized_email' => 'alias@example.com',
        'phone' => '5551112222',
        'normalized_phone' => '+15551112222',
    ]);
    MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'alias@example.com',
        'normalized_email' => 'alias@example.com',
        'phone' => '5551112222',
        'normalized_phone' => '+15551112222',
        'merged_into_profile_id' => $survivor->id,
        'merged_at' => now(),
    ]);

    $match = app(MarketingProfileMatcher::class)->match('alias@example.com', '5551112222', 1);

    expect($match['outcome'])->toBe('matched')
        ->and($match['reason'])->toBe('exact_email_phone')
        ->and($match['profile']?->id)->toBe($survivor->id)
        ->and($match['email_matches']->pluck('id')->all())->toBe([$survivor->id])
        ->and($match['phone_matches']->pluck('id')->all())->toBe([$survivor->id]);
});

test('multi-level alias chains resolve to the final survivor', function (): void {
    $survivor = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'chain@example.com',
        'normalized_email' => 'chain@example.com',
    ]);
    $middleAlias = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'chain@example.com',
        'normalized_email' => 'chain@example.com',
        'merged_into_profile_id' => $survivor->id,
        'merged_at' => now(),
    ]);
    MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'chain@example.com',
        'normalized_email' => 'chain@example.com',
        'merged_into_profile_id' => $middleAlias->id,
        'merged_at' => now(),
    ]);

    $match = app(MarketingProfileMatcher::class)->match('chain@example.com', null, 1);

    expect($match['outcome'])->toBe('matched')
        ->and($match['profile']?->id)->toBe($survivor->id)
        ->and($match['email_matches']->pluck('id')->all())->toBe([$survivor->id]);
});

test('distinct canonical survivors sharing an identifier remain ambiguous', function (): void {
    $first = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'ambiguous@example.com',
        'normalized_email' => 'ambiguous@example.com',
    ]);
    $second = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'ambiguous@example.com',
        'normalized_email' => 'ambiguous@example.com',
    ]);

    $match = app(MarketingProfileMatcher::class)->match('ambiguous@example.com', null, 1);

    expect($match['outcome'])->toBe('review')
        ->and($match['reason'])->toBe('ambiguous_exact_match')
        ->and($match['profile'])->toBeNull()
        ->and($match['email_matches']->pluck('id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

test('email and phone resolving to different survivors remain blocked', function (): void {
    $emailProfile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'conflict@example.com',
        'normalized_email' => 'conflict@example.com',
    ]);
    $phoneProfile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'phone' => '5553334444',
        'normalized_phone' => '+15553334444',
    ]);

    $match = app(MarketingProfileMatcher::class)->match('conflict@example.com', '5553334444', 1);

    expect($match['outcome'])->toBe('review')
        ->and($match['reason'])->toBe('email_phone_conflict')
        ->and($match['email_matches']->pluck('id')->all())->toBe([$emailProfile->id])
        ->and($match['phone_matches']->pluck('id')->all())->toBe([$phoneProfile->id]);
});

test('tenant isolation and null-tenant legacy fallback remain unchanged', function (): void {
    $tenantProfile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'scoped@example.com',
        'normalized_email' => 'scoped@example.com',
    ]);
    MarketingProfile::factory()->create([
        'tenant_id' => 2,
        'email' => 'scoped@example.com',
        'normalized_email' => 'scoped@example.com',
    ]);
    $legacyProfile = MarketingProfile::factory()->create([
        'tenant_id' => null,
        'email' => 'legacy@example.com',
        'normalized_email' => 'legacy@example.com',
    ]);

    $scoped = app(MarketingProfileMatcher::class)->match('scoped@example.com', null, 1);
    $legacy = app(MarketingProfileMatcher::class)->match('legacy@example.com', null, 1);

    expect($scoped['outcome'])->toBe('matched')
        ->and($scoped['profile']?->id)->toBe($tenantProfile->id)
        ->and($legacy['outcome'])->toBe('matched')
        ->and($legacy['profile']?->id)->toBe($legacyProfile->id);
});

test('cross-tenant and cyclic aliases fail closed', function (): void {
    $otherTenantSurvivor = MarketingProfile::factory()->create([
        'tenant_id' => 2,
        'email' => 'cross-tenant@example.com',
        'normalized_email' => 'cross-tenant@example.com',
    ]);
    MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'cross-tenant@example.com',
        'normalized_email' => 'cross-tenant@example.com',
        'merged_into_profile_id' => $otherTenantSurvivor->id,
        'merged_at' => now(),
    ]);

    $cycleOne = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'cycle@example.com',
        'normalized_email' => 'cycle@example.com',
    ]);
    $cycleTwo = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'cycle@example.com',
        'normalized_email' => 'cycle@example.com',
        'merged_into_profile_id' => $cycleOne->id,
        'merged_at' => now(),
    ]);
    $cycleOne->forceFill([
        'merged_into_profile_id' => $cycleTwo->id,
        'merged_at' => now(),
    ])->save();

    $crossTenant = app(MarketingProfileMatcher::class)->match('cross-tenant@example.com', null, 1);
    $cycle = app(MarketingProfileMatcher::class)->match('cycle@example.com', null, 1);

    expect($crossTenant['outcome'])->toBe('review')
        ->and($crossTenant['reason'])->toBe('canonical_alias_resolution_failed')
        ->and($cycle['outcome'])->toBe('review')
        ->and($cycle['reason'])->toBe('canonical_alias_resolution_failed');
});

test('broken alias chains cannot be ignored beside a valid survivor', function (): void {
    $survivor = MarketingProfile::factory()->create(['tenant_id' => 1]);
    $brokenAlias = new MarketingProfile([
        'tenant_id' => 1,
        'merged_into_profile_id' => PHP_INT_MAX,
    ]);
    $brokenAlias->forceFill(['id' => PHP_INT_MAX - 1]);

    $resolver = app(CanonicalMarketingProfileResolver::class);

    expect($resolver->canonical($brokenAlias, 1))->toBeNull()
        ->and($resolver->oneCanonical(collect([$survivor, $brokenAlias]), 1))->toBeNull();
});
