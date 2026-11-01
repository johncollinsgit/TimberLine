<?php

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashTask;
use App\Models\CandleCashTransaction;
use App\Models\CustomerBirthdayProfile;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\BirthdayClubService;
use App\Services\Marketing\BirthdayEmailDispatchService;
use App\Services\Marketing\BirthdayProfileService;
use App\Services\Marketing\BirthdayRewardEngineService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function clubCustomer(array $attributes = []): array
{
    $tenant = Tenant::create(['name' => 'Birthday Club test', 'slug' => 'club-'.bin2hex(random_bytes(6))]);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'birthday_club_config', 'value' => ['enabled' => true, 'signup_bonus_enabled' => false]]);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'birthday_reward_config', 'value' => ['enabled' => true, 'reward_type' => 'discount_code', 'reward_value' => 10, 'claim_window_days_after' => 30]]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => 'fictional-'.bin2hex(random_bytes(6)).'@example.test']);
    $birthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $profile->id, 'birth_month' => now()->month, 'birth_day' => now()->day, ...$attributes]);

    return [$tenant, $profile, $birthday];
}

function clubGift(MarketingProfile $profile, CustomerBirthdayProfile $birthday, array $attributes = []): BirthdayRewardIssuance
{
    return BirthdayRewardIssuance::create([
        'marketing_profile_id' => $profile->id, 'customer_birthday_profile_id' => $birthday->id,
        'cycle_year' => now()->year, 'reward_type' => 'discount_code', 'reward_value' => 10,
        'status' => 'issued', 'reward_code' => 'BDAY-TEST-'.bin2hex(random_bytes(4)), 'expires_at' => now()->addDays(10), ...$attributes,
    ]);
}

test('durable pos annual evidence blocks online issuance even with the pos feature disabled', function () {
    config(['pos_live.in_person_birthday_enabled' => false]);
    [$tenant, $profile, $birthday] = clubCustomer();
    $coverage = DB::table('birthday_cycle_coverage')->insertGetId([
        'tenant_id' => $tenant->id, 'canonical_profile_id' => $profile->id, 'cycle_year' => now()->year,
        'legacy_covered' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $hold = DB::table('pos_birthday_annual_holds')->insertGetId([
        'runtime_id' => 'fixture_runtime', 'tenant_id' => $tenant->id, 'coverage_id' => $coverage, 'sale_id' => str_repeat('a', 32),
        'state' => 'held', 'amount_minor' => 500, 'policy_version' => str_repeat('b', 64), 'authorization_reference' => str_repeat('c', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['held', 'consumed', 'review', 'released', 'unknown'] as $state) {
        DB::table('pos_birthday_annual_holds')->where('id', $hold)->update(['state' => $state]);
        $result = app(BirthdayRewardEngineService::class)->issueAnnualReward($birthday, ['send_email' => false]);
        expect($result['ok'])->toBeFalse()->and($result['error'])->toBe('birthday_pos_hold_exists')
            ->and(BirthdayRewardIssuance::count())->toBe(0);
    }
});

test('club-disabled issuance respects durable POS annual holds without changing other legacy issuance', function () {
    config(['pos_live.in_person_birthday_enabled' => false]);
    [$tenant, $profile, $birthday] = clubCustomer();
    TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'birthday_club_config')->update(['value' => json_encode(['enabled' => false])]);
    app(TenantMarketingSettingsResolver::class)->flushArrayCache();
    $coverage = DB::table('birthday_cycle_coverage')->insertGetId([
        'tenant_id' => $tenant->id, 'canonical_profile_id' => $profile->id, 'cycle_year' => now()->year,
        'legacy_covered' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $hold = DB::table('pos_birthday_annual_holds')->insertGetId([
        'runtime_id' => 'fixture_runtime', 'tenant_id' => $tenant->id, 'coverage_id' => $coverage, 'sale_id' => str_repeat('a', 32),
        'state' => 'held', 'amount_minor' => 500, 'policy_version' => str_repeat('b', 64), 'authorization_reference' => str_repeat('c', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $engine = app(BirthdayRewardEngineService::class);
    foreach (['held', 'consumed', 'review', 'released', 'unknown'] as $state) {
        DB::table('pos_birthday_annual_holds')->where('id', $hold)->update(['state' => $state]);
        $result = $engine->issueAnnualReward($birthday, ['send_email' => false]);
        expect($result['ok'])->toBeFalse()->and($result['error'])->toBe('birthday_pos_hold_exists')
            ->and(BirthdayRewardIssuance::count())->toBe(0);
    }
    $other = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => 'different-'.bin2hex(random_bytes(4)).'@example.test']);
    $otherBirthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $other->id, 'birth_month' => now()->month, 'birth_day' => now()->day]);
    $first = $engine->issueAnnualReward($otherBirthday, ['send_email' => false]);
    $second = $engine->issueAnnualReward($otherBirthday->fresh(), ['send_email' => false]);
    expect($first['state'])->toBe('birthday_reward_ready')->and($second['issuance']->id)->toBe($first['issuance']->id)
        ->and(BirthdayRewardIssuance::count())->toBe(1);
});

test('club-disabled guard sees held coverage across verified customer aliases and isolates tenants', function () {
    config(['pos_live.in_person_birthday_enabled' => false]);
    [$tenant, $profile, $birthday] = clubCustomer();
    TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'birthday_club_config')->update(['value' => json_encode(['enabled' => false])]);
    app(TenantMarketingSettingsResolver::class)->flushArrayCache();
    $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => 'alias-'.bin2hex(random_bytes(4)).'@example.test']);
    foreach ([$profile, $alias] as $linked) {
        CustomerExternalProfile::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $linked->id, 'provider' => 'shopify', 'integration' => 'fixture-'.$linked->id, 'store_key' => 'retail', 'external_customer_id' => 'fixture-shared']);
    }
    $aliasBirthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $alias->id, 'birth_month' => now()->month, 'birth_day' => now()->day]);
    $coverage = DB::table('birthday_cycle_coverage')->insertGetId(['tenant_id' => $tenant->id, 'canonical_profile_id' => $alias->id, 'cycle_year' => now()->year, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_birthday_annual_holds')->insert(['runtime_id' => 'fixture_runtime', 'tenant_id' => $tenant->id, 'coverage_id' => $coverage,
        'sale_id' => str_repeat('a', 32), 'state' => 'held', 'amount_minor' => 500, 'policy_version' => str_repeat('b', 64),
        'authorization_reference' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now()]);
    expect(app(BirthdayRewardEngineService::class)->issueAnnualReward($birthday, ['send_email' => false])['error'])->toBe('birthday_pos_hold_exists')
        ->and(app(BirthdayRewardEngineService::class)->issueAnnualReward($aliasBirthday, ['send_email' => false])['error'])->toBe('birthday_pos_hold_exists');
    [$otherTenant, $otherProfile, $otherBirthday] = clubCustomer();
    TenantMarketingSetting::where('tenant_id', $otherTenant->id)->where('key', 'birthday_club_config')->update(['value' => json_encode(['enabled' => false])]);
    app(TenantMarketingSettingsResolver::class)->flushArrayCache();
    expect(app(BirthdayRewardEngineService::class)->issueAnnualReward($otherBirthday, ['send_email' => false])['state'])->toBe('birthday_reward_ready');
});

test('club annual coverage preserves different reward types and prevents a replacement', function () {
    [$tenant, $profile, $birthday] = clubCustomer();
    $old = clubGift($profile, $birthday, ['reward_type' => 'candle_cash', 'status' => 'claimed', 'reward_code' => null, 'candle_cash_awarded' => 50]);
    $before = $old->fresh()->getRawOriginal();
    $result = app(BirthdayRewardEngineService::class)->issueAnnualReward($birthday, ['send_email' => false]);
    expect($result['issuance']->id)->toBe($old->id)
        ->and(BirthdayRewardIssuance::count())->toBe(1)
        ->and($old->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('birthday_cycle_coverage')->count())->toBe(1);
});

test('club repeats regular and catchup issuance without changing code or expiry', function () {
    [, , $birthday] = clubCustomer();
    $engine = app(BirthdayRewardEngineService::class);
    $first = $engine->issueAnnualReward($birthday, ['send_email' => false]);
    $again = $engine->issueAnnualReward($birthday->fresh(), ['send_email' => false, 'issuance_metadata' => ['catchup_campaign_key' => 'birthday-catchup-'.now()->year]]);
    expect($again['issuance']->id)->toBe($first['issuance']->id)
        ->and($again['issuance']->reward_code)->toBe($first['issuance']->reward_code)
        ->and($again['issuance']->expires_at->toIso8601String())->toBe($first['issuance']->expires_at->toIso8601String())
        ->and(BirthdayRewardIssuance::count())->toBe(1);
});

test('verified retail identity aliases share annual coverage and retain grandfathered gifts', function () {
    [$tenant, $profile, $birthday] = clubCustomer();
    $other = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => 'alias@example.test']);
    $otherBirthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $other->id, 'birth_month' => now()->month, 'birth_day' => now()->day]);
    foreach ([$profile, $other] as $linked) {
        CustomerExternalProfile::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $linked->id, 'provider' => 'shopify', 'integration' => 'shopify-'.$linked->id, 'store_key' => 'retail', 'external_customer_id' => '123456']);
    }
    $first = clubGift($profile, $birthday);
    $second = clubGift($other, $otherBirthday);
    $club = app(BirthdayClubService::class);
    expect($club->identity($profile))->toBe($club->identity($other))
        ->and($club->issuances($profile)->pluck('id')->all())->toContain($first->id, $second->id);
    app(BirthdayRewardEngineService::class)->issueAnnualReward($otherBirthday, ['send_email' => false]);
    expect(BirthdayRewardIssuance::count())->toBe(2);
});

test('same email alone never shares customer gifts and retained merge aliases do', function () {
    [$tenant, $profile, $birthday] = clubCustomer();
    $other = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => $profile->email]);
    $gift = clubGift($profile, $birthday);
    $club = app(BirthdayClubService::class);
    expect($club->issuances($other))->toHaveCount(0);
    $other->forceFill(['merged_into_profile_id' => $profile->id])->save();
    expect($club->issuances($other)->pluck('id')->all())->toContain($gift->id);
});

test('previous year usable gift remains primary in January and spent gifts stay historical', function () {
    $this->travelTo(\Carbon\Carbon::parse('2027-01-02 12:00:00'));
    [, $profile, $birthday] = clubCustomer(['birth_month' => 12, 'birth_day' => 25]);
    $gift = clubGift($profile, $birthday, ['cycle_year' => 2026, 'expires_at' => now()->addDays(20)]);
    $status = app(BirthdayRewardEngineService::class)->statusForProfile($birthday);
    expect($status['issuance']->id)->toBe($gift->id);
    $gift->update(['status' => 'redeemed', 'redeemed_at' => now()]);
    expect(app(BirthdayClubService::class)->issuances($profile)->first()->id)->toBe($gift->id);
});

test('expired and cancelled annual gifts never create a replacement', function (string $status) {
    [, $profile, $birthday] = clubCustomer();
    clubGift($profile, $birthday, ['status' => $status, 'expires_at' => now()->subDay()]);
    app(BirthdayRewardEngineService::class)->issueAnnualReward($birthday, ['send_email' => false]);
    expect(BirthdayRewardIssuance::count())->toBe(1);
})->with(['expired', 'cancelled', 'redeemed']);

test('new birthday signup is denied at both system and customer task entrypoints', function () {
    [, $profile] = clubCustomer();
    $task = CandleCashTask::firstOrCreate(['handle' => 'birthday-signup'], ['title' => 'Birthday signup', 'enabled' => true, 'auto_award' => true, 'reward_amount' => 2, 'verification_mode' => 'system_event']);
    $service = app(CandleCashTaskService::class);
    expect($service->awardSystemTask($profile, $task)['error'])->toBe('birthday_signup_retired')
        ->and($service->submitCustomerTask($profile, $task)['error'])->toBe('birthday_signup_retired')
        ->and(CandleCashTransaction::where('marketing_profile_id', $profile->id)->count())->toBe(0);
});

test('birthday correction preserves a saved year', function () {
    [, $profile, $birthday] = clubCustomer(['birth_year' => 1984]);
    $result = app(BirthdayProfileService::class)->captureForProfile($profile, ['birth_month' => 5, 'birth_day' => 6]);
    expect($result->birth_year)->toBe(1984)->and($result->birth_month)->toBe(5);
});

test('coverage reconciliation is read only by default and apply changes coverage only', function () {
    [$tenant, $profile, $birthday] = clubCustomer();
    $gift = clubGift($profile, $birthday);
    $before = $gift->fresh()->getRawOriginal();
    Http::preventStrayRequests();
    $this->artisan('birthday-club:reconcile', ['--tenant-id' => $tenant->id])->assertSuccessful();
    expect(DB::table('birthday_cycle_coverage')->count())->toBe(0);
    $this->artisan('birthday-club:reconcile', ['--tenant-id' => $tenant->id, '--apply-coverage' => true])->assertSuccessful();
    $this->artisan('birthday-club:reconcile', ['--tenant-id' => $tenant->id, '--apply-coverage' => true])->assertSuccessful();
    expect(DB::table('birthday_cycle_coverage')->count())->toBe(1)
        ->and($gift->fresh()->getRawOriginal())->toBe($before)
        ->and(BirthdayMessageEvent::count())->toBe(0);
});

test('historical primary email suppresses a second primary through a different template', function (string $family) {
    [, $profile, $birthday] = clubCustomer();
    $gift = clubGift($profile, $birthday);
    BirthdayMessageEvent::create(['event_key' => $family.$gift->id.':birthday_email_catchup_2026', 'marketing_profile_id' => $profile->id, 'customer_birthday_profile_id' => $birthday->id, 'birthday_reward_issuance_id' => $gift->id, 'campaign_type' => 'birthday_email', 'channel' => 'email', 'status' => 'sent']);
    Http::preventStrayRequests();
    $result = app(BirthdayEmailDispatchService::class)->sendIssuanceEmail($gift, ['template_key' => 'birthday_email_primary', 'force' => true]);
    expect($result['already_recorded'])->toBeTrue()->and($result['attempted'])->toBeFalse()->and(BirthdayMessageEvent::count())->toBe(1);
})->with(['birthday_email:', 'birthday-email:']);

test('unique verified retail catchup ownership shares coverage without merging profiles', function () {
    [$tenant, $profile, $birthday] = clubCustomer();
    $profile->forceFill(['normalized_email' => $profile->email])->save();
    $gift = clubGift($profile, $birthday, ['metadata' => ['catchup_campaign_key' => 'birthday-catchup-'.now()->year]]);
    $linked = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => $profile->email, 'normalized_email' => $profile->email]);
    $linkedBirthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $linked->id, 'birth_month' => now()->month, 'birth_day' => now()->day]);
    CustomerExternalProfile::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $linked->id, 'provider' => 'shopify', 'integration' => 'shopify', 'store_key' => 'retail', 'external_customer_id' => '987654', 'normalized_email' => $profile->email]);
    $club = app(BirthdayClubService::class);
    expect($club->identity($profile))->toBe($club->identity($linked));
    $result = app(BirthdayRewardEngineService::class)->issueAnnualReward($linkedBirthday, ['send_email' => false]);
    expect($result['issuance']->id)->toBe($gift->id)->and(BirthdayRewardIssuance::count())->toBe(1)
        ->and($linked->fresh()->merged_into_profile_id)->toBeNull();

    // Ownership survives corrected birthday/email; it cannot move to a new account reusing the email.
    $linked->update(['email' => 'corrected@example.test', 'normalized_email' => 'corrected@example.test']);
    $linkedBirthday->update(['birth_month' => 1, 'birth_day' => 2]);
    CustomerExternalProfile::where('marketing_profile_id', $linked->id)->update(['normalized_email' => 'corrected@example.test']);
    $replacement = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => $profile->email, 'normalized_email' => $profile->email]);
    CustomerExternalProfile::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $replacement->id, 'provider' => 'shopify', 'integration' => 'shopify', 'store_key' => 'retail', 'external_customer_id' => '222222', 'normalized_email' => $profile->email]);
    expect($club->issuances($linked)->pluck('id')->all())->toContain($gift->id)
        ->and($club->issuances($replacement))->toHaveCount(0);
    $again = app(BirthdayRewardEngineService::class)->issueAnnualReward($linkedBirthday->fresh(), ['send_email' => false]);
    expect($again['issuance']->id)->toBe($gift->id)->and(BirthdayRewardIssuance::count())->toBe(1);
});

test('signed club API keeps old gifts accessible and rejects another customer issuance without effects', function () {
    require_once __DIR__.'/../ShopifyEmbeddedTestHelpers.php';
    [$tenant, $profile, $birthday] = clubCustomer();
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'club-test-secret');
    configureStorefrontRetailStoreContext();
    \App\Models\ShopifyStore::updateOrCreate(['store_key' => 'retail'], ['tenant_id' => $tenant->id, 'shop_domain' => 'retail.example.myshopify.com', 'access_token' => 'fixture-only', 'installed_at' => now()]);
    \App\Models\MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'shopify_customer', 'source_id' => 'retail:12345']);
    CustomerExternalProfile::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'provider' => 'shopify', 'integration' => 'shopify', 'store_key' => 'retail', 'external_customer_id' => '12345']);
    $old = clubGift($profile, $birthday, ['cycle_year' => now()->year - 1, 'status' => 'claimed', 'discount_sync_status' => 'synced', 'shopify_discount_id' => '111', 'expires_at' => now()->addDays(2)]);
    $current = clubGift($profile, $birthday, ['expires_at' => now()->addDays(20)]);
    $other = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => 'another@example.test']);
    $otherBirthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $other->id, 'birth_month' => now()->month, 'birth_day' => now()->day]);
    $foreign = clubGift($other, $otherBirthday);
    $params = ['shop' => 'retail.example.myshopify.com', 'timestamp' => (string) time(), 'logged_in_customer_id' => '12345'];
    ksort($params);
    $params['signature'] = hash_hmac('sha256', collect($params)->map(fn ($v, $k) => $k.'='.$v)->implode(''), 'club-test-secret');
    Http::preventStrayRequests();
    $this->getJson(route('marketing.shopify.v1.birthday.status', $params))->assertOk()
        ->assertJsonPath('data.reward.issuance.id', $old->id)->assertJsonCount(2, 'data.reward.issuances');
    $this->postJson(route('marketing.shopify.v1.birthday.claim', $params), ['issuance_id' => $foreign->id])->assertNotFound();
    $this->postJson(route('marketing.shopify.v1.birthday.claim', $params), ['issuance_id' => $old->id])->assertOk()->assertJsonPath('data.reward.issuance.id', $old->id);
    expect(BirthdayRewardIssuance::count())->toBe(3)->and($current->fresh()->status)->toBe('issued');
});

test('policy activation is preview first tenant scoped and preserves annual rewards and global tasks', function () {
    [$tenant, $profile, $birthday] = clubCustomer();
    TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'birthday_club_config')->delete();
    app(TenantMarketingSettingsResolver::class)->flushArrayCache();
    \App\Models\ShopifyStore::create(['tenant_id' => $tenant->id, 'store_key' => 'retail', 'shop_domain' => 'modernforestry.myshopify.com', 'access_token' => 'fixture-only', 'installed_at' => now()]);
    $task = CandleCashTask::where('handle', 'birthday-signup')->firstOrFail();
    $beforeTask = $task->getRawOriginal();
    $gift = clubGift($profile, $birthday);
    $beforeGift = $gift->fresh()->getRawOriginal();
    $this->artisan('birthday-club:configure', ['--tenant-id' => $tenant->id])->assertSuccessful();
    expect(TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'birthday_club_config')->exists())->toBeFalse();
    $this->artisan('birthday-club:configure', ['--tenant-id' => $tenant->id, '--apply' => true])->assertSuccessful();
    expect(app(BirthdayClubService::class)->signupEnabled($tenant->id))->toBeFalse()
        ->and($task->fresh()->getRawOriginal())->toBe($beforeTask)
        ->and($gift->fresh()->getRawOriginal())->toBe($beforeGift)
        ->and(DB::table('landlord_operator_actions')->where('action_type', 'birthday_club_consolidation')->count())->toBe(1)
        ->and(BirthdayMessageEvent::count())->toBe(0);
});

test('retired signup keeps approved commitments but rejects new manual awards', function () {
    [$tenant, $profile] = clubCustomer();
    TenantMarketingSetting::where('tenant_id', $tenant->id)->where('key', 'birthday_club_config')->update(['value' => json_encode(['enabled' => true, 'signup_bonus_enabled' => false, 'cutover_at' => now()->toIso8601String()])]);
    app(TenantMarketingSettingsResolver::class)->flushArrayCache();
    $task = CandleCashTask::where('handle', 'birthday-signup')->firstOrFail();
    $approved = \App\Models\CandleCashTaskCompletion::create(['candle_cash_task_id' => $task->id, 'marketing_profile_id' => $profile->id, 'status' => 'approved', 'reviewed_at' => now()->subDay(), 'completion_key' => 'approved-legacy-'.$profile->id, 'reward_amount' => 2]);
    app(CandleCashTaskService::class)->approveCompletion($approved);
    expect($approved->fresh()->status)->toBe('awarded')->and(CandleCashTransaction::where('marketing_profile_id', $profile->id)->count())->toBe(1);
    $new = \App\Models\CandleCashTaskCompletion::create(['candle_cash_task_id' => $task->id, 'marketing_profile_id' => $profile->id, 'status' => 'pending', 'completion_key' => 'new-disabled-'.$profile->id, 'reward_amount' => 2]);
    expect(fn () => app(CandleCashTaskService::class)->approveCompletion($new))->toThrow(\RuntimeException::class, 'retired');
    expect(CandleCashTransaction::where('marketing_profile_id', $profile->id)->count())->toBe(1);
});

test('anonymous signed proxy cannot read claim or overwrite a birthday by supplying its profile id', function () {
    require_once __DIR__.'/../ShopifyEmbeddedTestHelpers.php';
    [$tenant, $profile, $birthday] = clubCustomer();
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'club-test-secret');
    configureStorefrontRetailStoreContext();
    \App\Models\ShopifyStore::updateOrCreate(['store_key' => 'retail'], ['tenant_id' => $tenant->id, 'shop_domain' => 'retail.example.myshopify.com', 'access_token' => 'fixture-only', 'installed_at' => now()]);
    CustomerExternalProfile::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'provider' => 'shopify', 'integration' => 'shopify', 'store_key' => 'retail', 'external_customer_id' => '12345']);
    $gift = clubGift($profile, $birthday);
    $before = $birthday->fresh()->getRawOriginal();
    $params = ['shop' => 'retail.example.myshopify.com', 'timestamp' => (string) time(), 'marketing_profile_id' => $profile->id, 'logged_in_customer_id' => ''];
    ksort($params);
    $params['signature'] = hash_hmac('sha256', collect($params)->map(fn ($v, $k) => $k.'='.$v)->implode(''), 'club-test-secret');
    Http::preventStrayRequests();
    $this->getJson(route('marketing.shopify.v1.birthday.status', $params))->assertOk()->assertJsonPath('data.profile_id', null)->assertJsonPath('data.reward.issuance', null);
    $this->postJson(route('marketing.shopify.v1.birthday.claim', $params), ['issuance_id' => $gift->id])->assertStatus(422);
    $this->postJson(route('marketing.shopify.v1.birthday.capture', $params), ['marketing_profile_id' => $profile->id, 'birth_month' => 1, 'birth_day' => 1])->assertStatus(422);
    expect($birthday->fresh()->getRawOriginal())->toBe($before)->and(BirthdayMessageEvent::count())->toBe(0);
});
