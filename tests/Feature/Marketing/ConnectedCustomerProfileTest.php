<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\BirthdayProfileService;
use App\Services\Marketing\TenantCustomerLookup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional customer shop', 'slug' => 'profile-'.Str::uuid()]);
    $this->owner = User::factory()->create(['email' => 'profile-'.Str::uuid().'@example.test', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Buyer', 'email' => 'buyer@example.test']);
    $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    $this->profileUrl = '/workspace/customers/'.$this->profile->id.'?tenant='.$this->tenant->slug;
});

afterEach(function () {
    Http::assertNothingSent();
});

test('the connected customer page renders linked purchases and rewards from the shared ledger', function () {
    TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'candle_cash_program_config', 'value' => ['label' => 'Candle Cash']]);
    CandleCashBalance::create(['marketing_profile_id' => $this->profile->id, 'balance' => 7.25]);
    CandleCashTransaction::create(['marketing_profile_id' => $this->profile->id, 'type' => 'earn', 'points' => 7,
        'candle_cash_delta' => 7.25, 'source' => 'admin', 'description' => 'PRIVATE source memo']);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'fictional-original-order', 'state' => 'COMPLETED',
        'closed_at' => now()->subDay(), 'location_id' => 'fictional-location', 'total_money_amount' => 1240, 'total_money_currency' => 'USD']);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'fictional-original-payment', 'square_order_id' => 'fictional-original-order',
        'status' => 'COMPLETED', 'amount_money' => 1240, 'currency' => 'USD', 'location_id' => 'fictional-location', 'created_at_source' => now()->subDay()]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id,
        'source_type' => 'square_order', 'source_id' => 'fictional-original-order']);

    $response = $this->get($this->profileUrl)->assertOk();
    $response->assertSee('Purchases &amp; Candle Cash', false)->assertSee('7.25 reward credits')
        ->assertSee('USD 12.40')->assertSee('Payment confirmed')->assertSee('Rewards earned')->assertDontSee('PRIVATE source memo');
    expect(CandleCashTransaction::count())->toBe(1)->and(CandleCashBalance::sole()->balance)->toBe(7.25)
        ->and(BirthdayRewardIssuance::count())->toBe(0);
});

test('the shared customer detail and page read the canonical birthday without issuing or copying rewards', function () {
    app(BirthdayProfileService::class)->captureForProfile($this->profile, [
        'birth_month' => 2, 'birth_day' => 29, 'birth_year' => 2000, 'source' => 'customer_signup',
    ], ['source' => 'customer_signup', 'is_uncertain' => true]);
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Retained alias',
        'merged_into_profile_id' => $this->profile->id, 'archived_at' => now()]);
    CustomerBirthdayProfile::create(['marketing_profile_id' => $alias->id, 'birth_month' => 12, 'birth_day' => 31, 'source' => 'old_alias']);
    $birthday = CustomerBirthdayProfile::where('marketing_profile_id', $this->profile->id)->firstOrFail();
    $birthday->update(['reward_last_issued_year' => 2025]);
    $before = $birthday->fresh()->getRawOriginal();

    $detail = app(TenantCustomerLookup::class)->details($this->tenant->id, $alias->id);
    expect($detail['customer']['id'])->toBe($this->profile->id)
        ->and($detail['birthday'])->toMatchArray(['contract_version' => 1, 'month' => 2, 'day' => 29, 'source' => 'customer_signup',
            'is_uncertain' => true, 'last_reward_issued_year' => 2025])
        ->and($detail['birthday'])->not->toHaveKeys(['year', 'full_date', 'metadata']);
    $this->getJson('/workspace/customers/'.$alias->id.'?tenant='.$this->tenant->slug)->assertOk()
        ->assertJsonPath('customer.id', $this->profile->id)->assertJsonPath('birthday.month', 2)
        ->assertJsonPath('birthday.day', 29)->assertJsonMissingPath('birthday.year');
    $this->get($this->profileUrl)->assertOk()->assertSee('02/29')->assertSee('Birthday needs review')
        ->assertSee('Last recorded birthday reward')->assertSee('2025');
    expect($birthday->fresh()->getRawOriginal())->toBe($before)
        ->and(CustomerBirthdayProfile::count())->toBe(2)->and(BirthdayRewardIssuance::count())->toBe(0)->and(CandleCashTransaction::count())->toBe(0);
});

test('missing birthdays stay unknown and foreign customers and inactive memberships cannot expose them', function () {
    $this->getJson($this->profileUrl)->assertOk()->assertJsonPath('birthday', null);
    $this->get($this->profileUrl)->assertOk()->assertSee('Birthday not recorded');
    $foreign = Tenant::create(['name' => 'Other fictional shop', 'slug' => 'other-'.Str::uuid()]);
    $otherProfile = MarketingProfile::create(['tenant_id' => $foreign->id, 'first_name' => 'Other customer', 'email' => $this->profile->email]);
    CustomerBirthdayProfile::create(['marketing_profile_id' => $otherProfile->id, 'birth_month' => 8, 'birth_day' => 19, 'source' => 'PRIVATE other tenant']);
    $this->getJson('/workspace/customers/'.$otherProfile->id.'?tenant='.$this->tenant->slug)->assertNotFound();
    $this->owner->tenants()->updateExistingPivot($this->tenant, ['membership_active' => false]);
    $this->getJson($this->profileUrl)->assertForbidden();
    expect(CustomerBirthdayProfile::count())->toBe(1)->and(BirthdayRewardIssuance::count())->toBe(0)->and(CandleCashTransaction::count())->toBe(0);
});
