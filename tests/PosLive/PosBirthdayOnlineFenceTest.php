<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Pos\PosBirthdayOnlineFence;
use App\Services\Pos\PosException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

it('serializes annual online operation claims and holds unknown results for review', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::create(['name' => 'Fictional birthday shop', 'slug' => 'birthday-'.Str::lower(Str::random(10)), 'status' => 'active']);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'birthday-'.Str::lower(Str::random(10)), 'status' => 'active']);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Test']);
    $birthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $profile->id, 'birth_month' => 4, 'birth_day' => 30]);
    $issuance = BirthdayRewardIssuance::create(['customer_birthday_profile_id' => $birthday->id, 'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026, 'reward_type' => 'discount_code', 'status' => 'issued']);
    DB::table('birthday_cycle_coverage')->insert(['tenant_id' => $tenant->id, 'canonical_profile_id' => $profile->id, 'cycle_year' => 2026,
        'issuance_id' => $issuance->id, 'legacy_covered' => false, 'created_at' => now(), 'updated_at' => now()]);
    $fence = app(PosBirthdayOnlineFence::class);
    $operation = (string) Str::uuid();
    expect(fn () => $fence->begin($tenant->id, $profile->id, 2026, $issuance->id, $operation))->toThrow(PosException::class, 'birthday_fence_disabled');
    config(['pos_live.birthday_channel_fence_enabled' => true]);
    expect(fn () => $fence->begin($other->id, $profile->id, 2026, $issuance->id, $operation))->toThrow(PosException::class, 'birthday_fence_review_required');
    $fence->begin($tenant->id, $profile->id, 2026, $issuance->id, $operation);
    $fence->begin($tenant->id, $profile->id, 2026, $issuance->id, $operation);
    expect(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(fn () => $fence->begin($tenant->id, $profile->id, 2026, $issuance->id, (string) Str::uuid()))->toThrow(PosException::class, 'birthday_fence_review_required');
    $fence->finish($tenant->id, $profile->id, 2026, $issuance->id, $operation, false);
    expect(DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenant->id)->value('state'))->toBe('review')
        ->and(fn () => $fence->begin($tenant->id, $profile->id, 2026, $issuance->id, $operation))->toThrow(PosException::class, 'birthday_fence_review_required')
        ->and(fn () => $fence->finish($tenant->id, $profile->id, 2026, $issuance->id, $operation, true))->toThrow(PosException::class, 'birthday_fence_review_required');
    Http::assertNothingSent();
});
