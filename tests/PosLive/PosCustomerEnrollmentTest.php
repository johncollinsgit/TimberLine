<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SqlitePaymentJournal;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Real provider boundaries must be exercised without a test-owned outer transaction.
uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional POS shop', 'slug' => 'pos-fixture-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'pos',
        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['pos_live.enabled' => true, 'pos_live.collection_enabled' => true, 'pos_live.servicing_enabled' => true,
        'pos_live.native_client_enabled' => true, 'pos_live.runtime_id' => 'fixture_runtime',
        'pos_live.square_application_id' => 'sandbox-synthetic-app', 'pos_live.square_environment' => 'sandbox',
        'pos_live.tenant_ids' => [$this->tenant->id]]);
    $this->store = app(PosStore::class);
    $this->connection = str_repeat('c', 32);
    $this->register = str_repeat('a', 32);
    $this->item = str_repeat('b', 32);
    $this->store->rows('connections', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->connection, 'merchant_id' => 'fixture_merchant', 'provider_location_id' => 'fixture_location',
        'environment' => 'sandbox', 'credentials' => $this->store->encrypt(['access_token' => 'fixture-token', 'expires_at' => now()->addDay()->toIso8601String()]),
        'active' => true, 'verified_at' => now(),
    ]));
    $this->store->rows('registers', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->register, 'name' => 'Fictional counter', 'location_id' => 'fixture_counter', 'location_name' => 'Fictional store',
        'connection_id' => $this->connection, 'catalog_source' => 'native', 'active' => true, 'collection_enabled' => true,
        'servicing_enabled' => true, 'configuration' => $this->store->encrypt(['catalog_reviewed' => true, 'tax_reviewed' => true,
            'tax_valid_until' => now()->addDay()->toIso8601String()]),
    ]));
    $this->store->rows('assignments', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'register_id' => $this->register, 'user_id' => $this->actor->id, 'device_id' => 'fixture_device_1234', 'role' => 'admin', 'active' => true,
    ]));
    $this->store->rows('items', $this->tenant->id)->insert($this->store->values($this->tenant->id, [
        'id' => $this->item, 'location_id' => 'fixture_counter', 'sku' => 'FICTIONAL-1', 'title' => 'Fictional merchandise',
        'kind' => 'merchandise', 'unit_amount_minor' => 1000, 'tax_bps' => 700, 'tax_basis' => 'Fictional test rate, not merchant tax advice',
        'tax_reviewed_at' => now(), 'reviewed_by' => $this->actor->id, 'revision' => str_repeat('d', 64), 'tracked' => true, 'quantity' => 1, 'active' => true,
    ]));
    $journal = DB::connection()->getDriverName() === 'mysql'
        ? new \App\Services\PaymentsCore\MySqlPaymentJournal(DB::connection(), app('encrypter'))
        : new SqlitePaymentJournal(new PDO('sqlite::memory:'));
    if ($journal instanceof SqlitePaymentJournal) {
        $journal->installForTesting();
    }
    app()->instance(PaymentJournal::class, $journal);
    $this->journal = $journal;
    $this->token = $this->actor->createToken('POS fixture', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'], now()->addDay())->plainTextToken;
    $this->withToken($this->token)->withHeader('X-Pos-Device', 'fixture_device_1234');
    $this->base = '/api/mobile/v1/workspaces/'.$this->tenant->slug.'/pos/registers/'.$this->register;
    Http::fake([
        '*/v2/locations/fixture_location' => Http::response(['location' => ['id' => 'fixture_location', 'merchant_id' => 'fixture_merchant',
            'country' => 'US', 'currency' => 'USD', 'status' => 'ACTIVE', 'capabilities' => ['CREDIT_CARD_PROCESSING']]]),
        '*/v2/merchants/fixture_merchant' => Http::response(['merchant' => ['id' => 'fixture_merchant', 'status' => 'ACTIVE']]),
    ]);
    $this->profile = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ada', 'last_name' => 'Buyer', 'email' => 'ada@example.invalid']);
    $this->selection = ['customer_profile_id' => $this->profile->id, 'request_id' => (string) Str::uuid(), 'items' => [['item_id' => $this->item, 'quantity' => 1, 'revision' => str_repeat('d', 64)]]];
});

function configureEnrollment($test): array
{
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $test->tenant->id, 'key' => 'birthday_club_config', 'value' => ['enabled' => true]]);
    $settings = $test->getJson($test->base.'/customer-enrollment')->assertOk()->json();

    return $test->postJson($test->base.'/customer-enrollment/settings', [
        'version' => $settings['version'], 'birthday_enabled' => true,
        'email_enabled' => true, 'email_wording' => 'I agree to promotional email from Fictional POS shop.',
        'sms_enabled' => true, 'sms_wording' => 'I agree to promotional texts from Fictional POS shop. Message and data rates may apply. Message frequency varies. Reply STOP to opt out.',
        'confirmed' => true,
    ])->assertOk()->json();
}

it('defaults enrollment off and requires reviewed tenant-specific disclosures', function (): void {
    $settings = $this->getJson($this->base.'/customer-enrollment')->assertOk()->json();
    expect($settings['birthday_enabled'])->toBeFalse()->and($settings['email_enabled'])->toBeFalse()->and($settings['sms_enabled'])->toBeFalse();
    $url = $this->base.'/customers/'.$this->profile->id.'/enrollment';
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'version' => $settings['version'], 'email_opt_in' => true])->assertStatus(422);
    expect(\App\Models\MarketingConsentEvent::where('marketing_profile_id', $this->profile->id)->count())->toBe(0);
    $this->postJson($this->base.'/customer-enrollment/settings', ['version' => $settings['version'], 'birthday_enabled' => false,
        'email_enabled' => false, 'sms_enabled' => true, 'sms_wording' => 'Texts please', 'confirmed' => true])->assertStatus(422)->assertJsonPath('error.code', 'enrollment_wording_invalid');
});

it('records selected-customer birthday and separate opt-ins once with reviewed wording and timestamps', function (): void {
    $this->profile->update(['phone' => '+12025550123', 'normalized_phone' => '+12025550123']);
    $settings = configureEnrollment($this);
    expect($settings['birthday_enabled'])->toBeTrue()->and($settings['email_enabled'])->toBeTrue()->and($settings['sms_enabled'])->toBeTrue();
    $url = $this->base.'/customers/'.$this->profile->id.'/enrollment';
    $input = ['request_id' => (string) Str::uuid(), 'version' => $settings['version'], 'birthday' => ['month' => 4, 'day' => 30], 'email_opt_in' => true, 'sms_opt_in' => true];
    $this->postJson($url, $input)->assertOk()->assertJsonPath('birthday_saved', true)->assertJsonPath('email_opt_in_recorded', true)->assertJsonPath('sms_opt_in_recorded', true);
    $this->postJson($url, $input)->assertOk();
    expect($this->profile->fresh()->accepts_email_marketing)->toBeTrue()->and($this->profile->fresh()->accepts_sms_marketing)->toBeTrue();
    $events = \App\Models\MarketingConsentEvent::where('tenant_id', $this->tenant->id)->where('marketing_profile_id', $this->profile->id)->where('source_type', 'everbranch_pay_customer_choice')->get();
    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->occurred_at)->not->toBeNull()->and($event->details['version'])->toBe($settings['version'])
            ->and($event->details['wording'])->toBe($settings[$event->channel.'_wording']);
    }
    $birthday = \App\Models\CustomerBirthdayProfile::where('marketing_profile_id', $this->profile->id)->firstOrFail();
    expect($birthday->birth_month)->toBe(4)->and($birthday->birth_day)->toBe(30)->and($birthday->source)->toBe('everbranch_pay')
        ->and($birthday->audits()->count())->toBe(1);
    $input['birthday']['day'] = 29;
    $this->postJson($url, $input)->assertStatus(409)->assertJsonPath('error.code', 'customer_enrollment_request_conflict');
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $foreign = \App\Models\MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Other']);
    $this->postJson($this->base.'/customers/'.$foreign->id.'/enrollment', $input)->assertNotFound();
});

it('never turns unchecked choices into opt-outs and preserves existing suppression and birthday', function (): void {
    $this->profile->update(['phone' => '+12025550123', 'normalized_phone' => '+12025550123', 'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false, 'sms_opted_out_at' => now()->subDay()]);
    $settings = configureEnrollment($this);
    $url = $this->base.'/customers/'.$this->profile->id.'/enrollment';
    $birthday = ['request_id' => (string) Str::uuid(), 'version' => $settings['version'], 'birthday' => ['month' => 2, 'day' => 29], 'email_opt_in' => false, 'sms_opt_in' => false];
    $this->postJson($url, $birthday)->assertOk();
    expect($this->profile->fresh()->accepts_email_marketing)->toBeTrue()->and($this->profile->fresh()->sms_opted_out_at)->not->toBeNull();
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'version' => $settings['version'], 'sms_opt_in' => true])->assertStatus(409)->assertJsonPath('error.code', 'customer_consent_review_required');
    $birthday['request_id'] = (string) Str::uuid();
    $birthday['birthday']['day'] = 28;
    $this->postJson($url, $birthday)->assertStatus(409)->assertJsonPath('error.code', 'birthday_change_requires_review');
    expect(\App\Models\MarketingConsentEvent::where('tenant_id', $this->tenant->id)->where('source_type', 'everbranch_pay_customer_choice')->count())->toBe(0);
});

it('refuses unreviewed settings, stale disclosures and non-admin configuration', function (): void {
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'pos_customer_enrollment', 'value' => [
        'email_enabled' => true, 'email_wording' => 'I agree to promotional email from Fictional POS shop.',
    ]]);
    $unreviewed = $this->getJson($this->base.'/customer-enrollment')->assertOk()->json();
    expect($unreviewed['email_enabled'])->toBeFalse();
    $reviewed = $this->postJson($this->base.'/customer-enrollment/settings', ['version' => $unreviewed['version'],
        'birthday_enabled' => false, 'email_enabled' => true, 'email_wording' => 'I agree to promotional email from Fictional POS shop. Weekly.',
        'sms_enabled' => false, 'sms_wording' => '', 'confirmed' => true])->assertOk()->json();
    $this->postJson($this->base.'/customers/'.$this->profile->id.'/enrollment', ['request_id' => (string) Str::uuid(),
        'version' => $unreviewed['version'], 'email_opt_in' => true])->assertStatus(409)->assertJsonPath('error.code', 'enrollment_settings_changed');
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $this->getJson($this->base.'/customer-enrollment')->assertOk()->assertJsonPath('can_manage', false);
    $this->postJson($this->base.'/customer-enrollment/settings', ['version' => $reviewed['version'],
        'birthday_enabled' => false, 'email_enabled' => false, 'sms_enabled' => false, 'confirmed' => true])->assertForbidden();
    expect(\App\Models\MarketingConsentEvent::where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('does not enable a tenant birthday program from a global fallback', function (): void {
    \App\Models\MarketingSetting::updateOrCreate(['key' => 'birthday_club_config'], ['value' => ['enabled' => true]]);
    $current = $this->getJson($this->base.'/customer-enrollment')->assertOk()->json();
    $configured = $this->postJson($this->base.'/customer-enrollment/settings', ['version' => $current['version'],
        'birthday_enabled' => true, 'email_enabled' => false, 'sms_enabled' => false, 'confirmed' => true])->assertOk()->json();
    expect($configured['birthday_configured'])->toBeTrue()->and($configured['birthday_enabled'])->toBeFalse();
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'birthday_club_config', 'value' => ['enabled' => true]]);
    $enabled = $this->getJson($this->base.'/customer-enrollment')->assertOk()->json();
    expect($enabled['birthday_enabled'])->toBeTrue()->and($enabled['version'])->not->toBe($configured['version']);
});

it('records an affirmative email choice without enrolling or revoking text updates', function (): void {
    $this->profile->update(['phone' => '+12025550123', 'normalized_phone' => '+12025550123']);
    $settings = configureEnrollment($this);
    $input = ['request_id' => (string) Str::uuid(), 'version' => $settings['version'], 'email_opt_in' => true, 'sms_opt_in' => false];
    $url = $this->base.'/customers/'.$this->profile->id.'/enrollment';
    $this->postJson($url, $input)->assertOk()->assertJsonPath('email_opt_in_recorded', true)->assertJsonPath('sms_opt_in_recorded', false);
    expect($this->profile->fresh()->accepts_email_marketing)->toBeTrue()->and($this->profile->fresh()->accepts_sms_marketing)->toBeFalse()
        ->and($this->profile->fresh()->sms_opted_out_at)->toBeNull();
    expect(\App\Models\MarketingConsentEvent::where('tenant_id', $this->tenant->id)->where('marketing_profile_id', $this->profile->id)->pluck('channel')->all())->toBe(['email']);
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'version' => $settings['version'], 'birthday' => []])->assertStatus(422);
});

it('allows customer creation only for explicitly enabled tenant staff assigned to the register and device', function (): void {
    $settings = $this->getJson($this->base.'/customer-enrollment')->assertOk()->json();
    expect($settings['cashier_create_enabled'])->toBeFalse();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $create = ['request_id' => (string) Str::uuid(), 'first_name' => 'Fictional buyer', 'email' => 'new@example.invalid'];
    $this->getJson($this->base.'/customer-enrollment')->assertOk()->assertJsonPath('can_create_customers', false);
    $this->postJson($this->base.'/customers', $create)->assertForbidden();
    $this->postJson($this->base.'/customer-enrollment/settings', ['version' => $settings['version'], 'cashier_create_enabled' => true,
        'birthday_enabled' => false, 'email_enabled' => false, 'sms_enabled' => false, 'confirmed' => true])->assertForbidden();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'admin']);
    $enabled = $this->postJson($this->base.'/customer-enrollment/settings', ['version' => $settings['version'], 'cashier_create_enabled' => true,
        'birthday_enabled' => false, 'email_enabled' => false, 'sms_enabled' => false, 'confirmed' => true])->assertOk()->json();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $this->getJson($this->base.'/customer-enrollment')->assertOk()->assertJsonPath('can_create_customers', true);
    $created = $this->postJson($this->base.'/customers', $create)->assertOk()->json('customer.id');
    $this->postJson($this->base.'/customers', $create)->assertOk()->assertJsonPath('customer.id', $created);
    expect(\App\Models\MarketingProfile::where('tenant_id', $this->tenant->id)->where('email', 'new@example.invalid')->count())->toBe(1);
    $this->withHeader('X-Pos-Device', 'unassigned-device')->postJson($this->base.'/customers', [...$create, 'request_id' => (string) Str::uuid()])->assertNotFound();
    $this->withHeader('X-Pos-Device', 'fixture_device_1234');
    $other = Tenant::create(['name' => 'Other tenant', 'slug' => 'other-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->actor->tenants()->attach($other, ['role' => 'member', 'membership_active' => true]);
    $otherConnection = str_replace('-', '', (string) Str::uuid());
    $otherRegister = str_replace('-', '', (string) Str::uuid());
    $this->store->rows('connections', $other->id)->insert($this->store->values($other->id, [
        'id' => $otherConnection, 'merchant_id' => 'other_merchant', 'provider_location_id' => 'other_location', 'environment' => 'sandbox', 'active' => true,
        'credentials' => $this->store->encrypt(['access_token' => 'fixture-token']),
    ]));
    $this->store->rows('registers', $other->id)->insert($this->store->values($other->id, [
        'id' => $otherRegister, 'name' => 'Other register', 'location_id' => 'other', 'location_name' => 'Other',
        'connection_id' => $otherConnection, 'catalog_source' => 'native', 'active' => true, 'configuration' => $this->store->encrypt([]),
    ]));
    $this->store->rows('assignments', $other->id)->insert($this->store->values($other->id, [
        'register_id' => $otherRegister, 'user_id' => $this->actor->id, 'device_id' => 'fixture_device_1234', 'role' => 'cashier', 'active' => true,
    ]));
    TenantModuleEntitlement::create(['tenant_id' => $other->id, 'module_key' => 'pos', 'availability_status' => 'available', 'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    $otherBase = '/api/mobile/v1/workspaces/'.$other->slug.'/pos/registers/'.$otherRegister;
    $this->getJson($otherBase.'/customer-enrollment')->assertOk()->assertJsonPath('can_create_customers', false);
    $this->postJson($otherBase.'/customers', [...$create, 'request_id' => (string) Str::uuid()])->assertForbidden();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'admin']);
    $this->postJson($this->base.'/customer-enrollment/settings', ['version' => $enabled['version'], 'cashier_create_enabled' => false,
        'birthday_enabled' => false, 'email_enabled' => false, 'sms_enabled' => false, 'confirmed' => true])->assertOk();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $this->postJson($this->base.'/customers', [...$create, 'request_id' => (string) Str::uuid()])->assertForbidden();
});

it('records an assigned administrator review without linking a wallet or trusting contact matches', function (): void {
    config(['pos_live.existing_wallet_review_enabled' => true]);
    $service = app(\App\Services\Pos\PosExistingWalletReview::class);
    $subject = 'gid://shopify/Customer/771';
    $request = $service->request($this->tenant->id, $subject, (string) Str::uuid());
    $url = $this->base.'/wallet-link-reviews/'.$request['reference'];
    $details = $this->getJson($url)->assertOk()->assertJsonPath('shopify_subject', $subject)
        ->assertJsonPath('linked', false)->assertJsonPath('finalization_available', false)->json();
    expect($details['disclosure'])->toContain('independently verified');
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    $input = ['customer_profile_id' => $this->profile->id, 'evidence' => 'owner_verified_independent_evidence',
        'confirmed' => true, 'disclosure_hash' => $details['disclosure_hash']];
    $this->getJson($url)->assertForbidden();
    $this->postJson($url, $input)->assertForbidden();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'admin']);
    $this->withHeader('X-Pos-Device', 'unassigned-device')->postJson($url, $input)->assertNotFound();
    $this->withHeader('X-Pos-Device', 'fixture_device_1234');
    $foreignTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $foreign = \App\Models\MarketingProfile::create(['tenant_id' => $foreignTenant->id, 'first_name' => 'Other']);
    expect(fn () => $service->customerStatus($foreignTenant->id, $request['reference'], $subject))
        ->toThrow(\App\Services\Pos\PosException::class, 'wallet_review_unavailable');
    $this->postJson($url, [...$input, 'customer_profile_id' => $foreign->id])->assertNotFound();
    $this->postJson($url, [...$input, 'confirmed' => false])->assertStatus(422);
    $this->postJson($url, [...$input, 'disclosure_hash' => str_repeat('0', 64)])->assertStatus(422);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('state', 'review_recorded')->assertJsonPath('linked', false);
    $this->postJson($url, $input)->assertOk();
    $other = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other buyer']);
    $this->postJson($url, [...$input, 'customer_profile_id' => $other->id])->assertConflict();
    expect($this->store->rows('events', $this->tenant->id)->where('sale_id', $request['reference'])->where('code', 'wallet_link_reviewed')->count())->toBe(1)
        ->and(\App\Models\MarketingProfileLink::where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and($service->customerStatus($this->tenant->id, $request['reference'], $subject)['linked'])->toBeFalse();
    $this->travel(25)->hours();
    expect($service->customerStatus($this->tenant->id, $request['reference'], $subject)['state'])->toBe('expired');
    $scope = ['tenant_id' => $this->tenant->id, 'actor_id' => $this->actor->id, 'role' => 'admin',
        'register' => $this->store->rows('registers', $this->tenant->id)->where('id', $this->register)->first()];
    expect(fn () => $service->review($scope, $request['reference'], $this->profile->id, 'owner_verified_independent_evidence', true, $details['disclosure_hash']))
        ->toThrow(\App\Services\Pos\PosException::class, 'wallet_review_expired');
});
