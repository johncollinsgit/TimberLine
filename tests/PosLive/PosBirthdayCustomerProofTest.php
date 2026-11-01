<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Pos\PosBirthdayCustomerProof;
use App\Services\Pos\PosException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

it('keeps birthday proof default off and binds only explicitly accepted separate terms once', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::create(['name' => 'Fictional birthday shop', 'slug' => 'proof-'.Str::lower(Str::random(10)), 'status' => 'active']);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'proof-'.Str::lower(Str::random(10)), 'status' => 'active']);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'birthday_club_config', 'value' => ['enabled' => true]]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Test']);
    $birthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $profile->id, 'birth_month' => 4, 'birth_day' => 30]);
    $issuance = BirthdayRewardIssuance::create(['customer_birthday_profile_id' => $birthday->id, 'marketing_profile_id' => $profile->id,
        'cycle_year' => 2026, 'reward_type' => 'discount_code', 'status' => 'issued', 'reward_value' => '10.00', 'expires_at' => now()->addDay()]);
    DB::table('birthday_cycle_coverage')->insert(['tenant_id' => $tenant->id, 'canonical_profile_id' => $profile->id, 'cycle_year' => 2026,
        'issuance_id' => $issuance->id, 'legacy_covered' => false, 'created_at' => now(), 'updated_at' => now()]);
    $proofs = app(PosBirthdayCustomerProof::class);
    expect(fn () => $proofs->termsForAuthenticatedProfile($profile, 2026))->toThrow(PosException::class, 'birthday_authorization_disabled');
    config(['pos_live.birthday_authorization_enabled' => true, 'pos_live.runtime_id' => 'birthday-fixture']);
    $terms = $proofs->termsForAuthenticatedProfile($profile, 2026);
    expect($terms['amount_minor'])->toBe(1000)->and($terms['disclosure_version'])->toBe('birthday_one_use_v1');
    expect(fn () => $proofs->issueForAuthenticatedProfile($profile, 2026, $terms['terms_hash'], false))->toThrow(PosException::class, 'birthday_disclosure_changed')
        ->and(fn () => $proofs->issueForAuthenticatedProfile($profile, 2026, str_repeat('a', 64), true))->toThrow(PosException::class, 'birthday_disclosure_changed');
    $issued = $proofs->issueForAuthenticatedProfile($profile, 2026, $terms['terms_hash'], true);
    $token = substr($issued['qr_payload'], strlen('everbranch-birthday:v1:'));
    expect($issued['qr_payload'])->toStartWith('everbranch-birthday:v1:')
        ->and(DB::table('pos_birthday_authorizations')->where('tenant_id', $tenant->id)->value('token_hash'))->toBe(hash('sha256', $token));
    $sale = str_repeat('a', 32);
    expect(fn () => $proofs->bind($tenant->id, $issued['qr_payload'], $sale))->toThrow(PosException::class, 'birthday_authorization_invalid');
    expect(fn () => DB::transaction(fn () => $proofs->bind($other->id, $issued['qr_payload'], $sale)))->toThrow(PosException::class, 'birthday_authorization_invalid');
    DB::transaction(fn () => $proofs->bind($tenant->id, $issued['qr_payload'], $sale));
    DB::transaction(fn () => $proofs->bind($tenant->id, $issued['qr_payload'], $sale));
    expect(fn () => DB::transaction(fn () => $proofs->bind($tenant->id, $issued['qr_payload'], str_repeat('b', 32))))->toThrow(PosException::class, 'birthday_authorization_invalid');
    expect(fn () => DB::transaction(fn () => $proofs->bind($tenant->id, 'everbranch-reward:v1:'.str_repeat('a', 43), $sale)))->toThrow(PosException::class, 'birthday_authorization_invalid');
    $second = $proofs->issueForAuthenticatedProfile($profile, 2026, $terms['terms_hash'], true);
    $issuance->update(['reward_value' => '11.00']);
    expect(fn () => DB::transaction(fn () => $proofs->bind($tenant->id, $second['qr_payload'], str_repeat('c', 32))))->toThrow(PosException::class, 'birthday_disclosure_changed');
    $terms = $proofs->termsForAuthenticatedProfile($profile, 2026);
    $third = $proofs->issueForAuthenticatedProfile($profile, 2026, $terms['terms_hash'], true);
    $this->travel(121)->seconds();
    expect(fn () => DB::transaction(fn () => $proofs->bind($tenant->id, $third['qr_payload'], str_repeat('d', 32))))->toThrow(PosException::class, 'birthday_authorization_invalid');
    Http::assertNothingSent();
});
