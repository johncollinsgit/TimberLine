<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Pos\PosBirthdayEntitlementEvidence;
use App\Services\Pos\PosException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

it('keeps birthday evidence default-off and never treats an online issuance as POS redeemable', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $reader = app(PosBirthdayEntitlementEvidence::class);
    expect($reader->inspect(0, 0, 0))->toBe(['state' => 'disabled', 'redeemable' => false]);
    config(['pos_live.birthday_pos_evidence_enabled' => true]);
    $tenant = Tenant::create(['name' => 'Fixture birthday', 'slug' => 'birth-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $other = Tenant::create(['name' => 'Other tenant', 'slug' => 'birth-'.Str::lower(Str::random(8)), 'status' => 'active']);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'birthday_club_config', 'value' => ['enabled' => true]]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Ada', 'email' => 'ada@example.invalid']);
    $birthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $profile->id, 'birth_month' => 6, 'birth_day' => 1]);
    expect(fn () => $reader->inspect($other->id, $profile->id, 2026))->toThrow(PosException::class, 'customer_not_found');
    expect($reader->inspect($tenant->id, $profile->id, 2026))->toBe(['state' => 'not_issued', 'redeemable' => false]);
    $issuance = BirthdayRewardIssuance::create(['customer_birthday_profile_id' => $birthday->id, 'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026, 'reward_type' => 'discount_code', 'status' => 'claimed', 'reward_value' => 10,
        'reward_code' => 'fixture-private-code', 'discount_sync_status' => 'synced', 'expires_at' => now()->addDay()]);
    expect($reader->inspect($tenant->id, $profile->id, 2026))->toBe(['state' => 'coverage_review_required', 'redeemable' => false]);
    DB::table('birthday_cycle_coverage')->insert(['tenant_id' => $tenant->id, 'canonical_profile_id' => $profile->id,
        'cycle_year' => 2026, 'issuance_id' => $issuance->id, 'legacy_covered' => false, 'created_at' => now(), 'updated_at' => now()]);
    $original = $issuance->fresh()->toArray();
    $result = $reader->inspect($tenant->id, $profile->id, 2026);
    expect($result)->toBe(['state' => 'channel_coordination_required', 'redeemable' => false, 'issuance_id' => $issuance->id, 'cycle_year' => 2026])
        ->and($result)->not->toHaveKey('reward_code')->and($issuance->fresh()->toArray())->toBe($original)
        ->and(BirthdayRewardIssuance::count())->toBe(1);
    $issuance->update(['status' => 'redeemed', 'redeemed_at' => now()]);
    expect($reader->inspect($tenant->id, $profile->id, 2026))->toBe(['state' => 'unavailable', 'redeemable' => false]);
    $issuance->update(['status' => 'claimed', 'redeemed_at' => null, 'expires_at' => now()->subDay()]);
    expect($reader->inspect($tenant->id, $profile->id, 2026))->toBe(['state' => 'unavailable', 'redeemable' => false]);
    $issuance->update(['status' => 'claimed', 'expires_at' => now()->addDay(), 'reward_type' => 'candle_cash']);
    expect($reader->inspect($tenant->id, $profile->id, 2026))->toBe(['state' => 'unsupported_reward_type', 'redeemable' => false]);
    $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Alias']);
    DB::table('marketing_profiles')->where('id', $alias->id)->update(['merged_into_profile_id' => $profile->id]);
    expect($reader->inspect($tenant->id, $profile->id, 2026))->toBe(['state' => 'identity_review_required', 'redeemable' => false]);
    Http::assertNothingSent();
});
