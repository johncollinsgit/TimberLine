<?php

use App\Http\Controllers\WebsiteShopperAccountController;
use App\Models\LandlordOperatorAction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\TenantMarketingSetting;
use App\Models\TenantSite;
use App\Models\WebsiteCustomer;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\Marketing\CustomerPreferenceService;
use App\Services\Marketing\MessagingContactChannelStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->owner->forceFill(['email_verified_at' => now()])->save();
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Customer',
        'email' => 'fictional@example.test', 'normalized_email' => 'fictional@example.test', 'phone' => '8645550101', 'normalized_phone' => '8645550101',
        'accepts_email_marketing' => false, 'accepts_sms_marketing' => false, 'notes' => 'Private staff-only fixture note']);
    $this->customer = WebsiteCustomer::create(['tenant_id' => $this->tenant->id, 'email' => 'fictional@example.test', 'status' => 'active']);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => 'website_customer', 'source_id' => (string) $this->customer->id]);
    $this->preferences = app(CustomerPreferenceService::class);
    $this->command = fn (array $changes, ?string $version = null) => ['request_id' => (string) Str::uuid(), 'expected_version' => $version ?? $this->preferences->forCustomer($this->profile, $this->tenant->id)['version'], 'changes' => $changes];
});

test('unknown preferences stay unknown and explicit scents are distinct from purchase inference', function () {
    $empty = $this->preferences->forWebsiteCustomer($this->customer, $this->tenant->id);
    expect($empty['declared']['favorite_scents'])->toBeNull()->and($empty['receipts']['email'])->toBeNull()
        ->and($empty['rewards']['membership_status'])->toBe('unknown');
    $saved = $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['favorite_scents' => [' Cedar ', 'Cedar'], 'avoided_scents' => [], 'receipt_email' => true, 'rewards_communications' => false]));
    expect($saved['declared']['favorite_scents'])->toBe(['Cedar'])->and($saved['declared']['avoided_scents'])->toBe([])
        ->and($saved['communication']['email_marketing'])->toBe('unknown')->and($saved['communication']['sms_marketing'])->toBe('unknown')
        ->and($saved['receipts']['email'])->toBeTrue()->and($saved['receipts']['grants_marketing_consent'])->toBeFalse()
        ->and($saved['rewards']['communications'])->toBeFalse()->and($saved['revision'])->toBe(1);
    $audit = LandlordOperatorAction::where('action_type', 'customer_preferences_update')->firstOrFail();
    expect($audit->context['source'])->toBe('website_account')->and($audit->after_state['preferences']['favorite_scents'])->toBe(['Cedar']);
    expect(json_encode($saved))->not->toContain('staff-only', 'actor_id', 'command_hash', 'recorded_choices');
    Http::assertNothingSent();
});

test('same request retries once and changed retry or stale edits cannot overwrite choices', function () {
    $command = ($this->command)(['favorite_scents' => ['Pine']]);
    $first = $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, $command);
    $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, $command);
    expect(LandlordOperatorAction::where('action_type', 'customer_preferences_update')->count())->toBe(1);
    expect(fn () => $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, array_replace($command, ['changes' => ['favorite_scents' => ['Cedar']]])))->toThrow(HttpException::class);
    expect(fn () => $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['receipt_sms' => true], $command['expected_version'])))->toThrow(HttpException::class);
    expect($this->preferences->forCustomer($this->profile, $this->tenant->id)['version'])->toBe($first['version']);
});

test('current unsubscribe wins over stale opt in and even a successful opt in retry', function () {
    $command = ($this->command)(['email_marketing' => true]);
    $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, $command);
    app(MessagingContactChannelStateService::class)->markEmailStatus($this->tenant->id, $this->profile->fresh(), $this->profile->email, 'unsubscribed', 'customer_unsubscribe');
    $retry = $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, $command);
    expect($retry['communication']['email_marketing'])->toBe('unsubscribed')->and($this->profile->fresh()->accepts_email_marketing)->toBeFalse();
    expect(fn () => $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['email_marketing' => true], $command['expected_version'])))->toThrow(HttpException::class);
    $receipt = $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['receipt_email' => true, 'preferred_channel' => 'email']));
    expect($receipt['communication']['email_marketing'])->toBe('unsubscribed');
});

test('text receipts cannot opt in and text unsubscribe revokes the existing channel state', function () {
    app(MessagingContactChannelStateService::class)->markSmsSubscribed($this->tenant->id, $this->profile, $this->profile->phone, 'existing_verified_fixture');
    $saved = $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['sms_marketing' => false, 'receipt_sms' => true]));
    expect($saved['receipts']['sms'])->toBeTrue()->and($saved['communication']['sms_marketing'])->toBe('unsubscribed');
    expect(fn () => $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['sms_marketing' => true])))->toThrow(ValidationException::class);
    expect($this->profile->fresh()->accepts_sms_marketing)->toBeFalse();
});

test('identity contacts require verification and rejected combined changes roll back', function () {
    foreach (['email' => 'another@example.test', 'phone' => '8645550102'] as $field => $value) {
        expect(fn () => $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)([$field => $value, 'favorite_scents' => ['Cedar']])))->toThrow(ValidationException::class);
    }
    expect($this->preferences->forCustomer($this->profile, $this->tenant->id)['declared']['favorite_scents'])->toBeNull();
    expect($this->profile->fresh()->email)->toBe('fictional@example.test');
});

test('verified email opt in rejects ambiguous identities and bounced or suppressed addresses', function () {
    foreach (['bounced', 'suppressed'] as $status) {
        app(MessagingContactChannelStateService::class)->markEmailStatus($this->tenant->id, $this->profile->fresh(), $this->profile->email, $status, 'fixture_delivery');
        expect(fn () => $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['email_marketing' => true])))->toThrow(ValidationException::class);
    }
    app(MessagingContactChannelStateService::class)->markEmailStatus($this->tenant->id, $this->profile->fresh(), $this->profile->email, 'unknown', 'fixture_delivery');
    MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => $this->profile->email, 'normalized_email' => $this->profile->normalized_email]);
    expect(fn () => $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['email_marketing' => true])))->toThrow(ValidationException::class);
    expect($this->profile->fresh()->accepts_email_marketing)->toBeFalse();
});

test('staff writes require an active verified manager and expressly reported customer choice', function () {
    $command = ($this->command)(['favorite_scents' => ['Cedar']]);
    expect(fn () => $this->preferences->updateForStaff($this->profile, $this->tenant->id, $this->owner, $command))->toThrow(ValidationException::class);
    $command['customer_confirmed'] = true;
    $member = teamMember($this->tenant, 'member');
    $member->forceFill(['email_verified_at' => now()])->save();
    expect(fn () => $this->preferences->updateForStaff($this->profile, $this->tenant->id, $member, $command))->toThrow(HttpException::class);
    $saved = $this->preferences->updateForStaff($this->profile, $this->tenant->id, $this->owner, $command);
    expect($saved['provenance']['favorite_scents']['actor_id'])->toBe((string) $this->owner->id);
    expect($this->preferences->forCustomer($this->profile, $this->tenant->id)['provenance']['favorite_scents'])->not->toHaveKey('actor_id');
    expect(fn () => $this->preferences->updateForStaff($this->profile, $this->tenant->id, $this->owner, [...($this->command)(['email_marketing' => true]), 'customer_confirmed' => true]))->toThrow(ValidationException::class);
});

test('tenant scope explicit associations and canonical alias conflicts fail closed', function () {
    [$otherTenant] = teamWorkspace();
    expect($this->preferences->forWebsiteCustomer($this->customer, $otherTenant->id))->toBeNull();
    expect(fn () => $this->preferences->forCustomer($this->profile, $otherTenant->id))->toThrow(HttpException::class);
    $unlinked = WebsiteCustomer::create(['tenant_id' => $this->tenant->id, 'email' => 'unlinked@example.test', 'status' => 'active']);
    expect($this->preferences->forWebsiteCustomer($unlinked, $this->tenant->id))->toBeNull();
    expect(fn () => $this->preferences->updateForWebsiteCustomer($unlinked, $this->tenant->id, ($this->command)(['receipt_email' => true])))->toThrow(HttpException::class);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    MarketingProfileLink::where('source_type', 'website_customer')->where('source_id', (string) $this->customer->id)->update(['marketing_profile_id' => $other->id]);
    $other->update(['merged_into_profile_id' => $other->id]);
    expect($this->preferences->forWebsiteCustomer($this->customer, $this->tenant->id))->toBeNull();
    $other->update(['merged_into_profile_id' => $this->profile->id]);
    expect($this->preferences->forWebsiteCustomer($this->customer, $this->tenant->id)['profile_id'])->toBe($this->profile->id);
    $other->update(['merged_into_profile_id' => MarketingProfile::create(['tenant_id' => $otherTenant->id])->id]);
    expect($this->preferences->forWebsiteCustomer($this->customer, $this->tenant->id))->toBeNull();
});

test('retained alias declarations conflict until an explicit current choice resolves them', function () {
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $aliasCommand = ['request_id' => (string) Str::uuid(), 'expected_version' => $this->preferences->forCustomer($alias, $this->tenant->id)['version'], 'changes' => ['favorite_scents' => ['Pine']]];
    $this->preferences->updateForCustomer($alias, $this->tenant->id, $aliasCommand, 'authenticated_customer');
    $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['favorite_scents' => ['Cedar']]));
    $oldVersion = $this->preferences->forCustomer($this->profile, $this->tenant->id)['version'];
    $alias->update(['merged_into_profile_id' => $this->profile->id]);
    $conflicted = $this->preferences->forCustomer($this->profile, $this->tenant->id);
    expect($conflicted['declared']['favorite_scents'])->toBeNull()->and($conflicted['issues'])->toContain('alias_preference_review_required')->and($conflicted['version'])->not->toBe($oldVersion);
    $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['receipt_email' => true]));
    expect($this->preferences->forCustomer($this->profile, $this->tenant->id)['declared']['favorite_scents'])->toBeNull();
    $resolved = $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['favorite_scents' => ['Cedar']]));
    expect($resolved['declared']['favorite_scents'])->toBe(['Cedar'])->and($resolved['issues'])->toBe([]);
    expect($this->preferences->forCustomer($alias, $this->tenant->id)['declared']['favorite_scents'])->toBe(['Cedar']);
});

test('customer private projection and audit omit staff notes identities and raw contact values', function () {
    $saved = $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['first_name' => 'Updated Fictional', 'favorite_scents' => ['Cedar']]));
    expect($saved['contact']['first_name'])->toBe('Updated Fictional');
    $audit = LandlordOperatorAction::where('action_type', 'customer_preferences_update')->firstOrFail();
    expect(json_encode($audit->toArray()))->not->toContain('Updated Fictional', 'fictional@example.test', '8645550101', 'staff-only');
    expect(json_encode($saved))->not->toContain('notes', 'actor_id', 'command_hash', 'source_id');
    expect(TenantMarketingSetting::where('tenant_id', $this->tenant->id)->where('key', 'like', 'customer_preferences.%')->count())->toBe(1);
});

test('Website preference API binds to verified host and current session without client identity selection', function () {
    $site = TenantSite::create(['tenant_id' => $this->tenant->id, 'status' => 'published', 'public_enabled' => true, 'subdomain' => 'fictional-shop']);
    $websites = Mockery::mock(ManagedWebsiteService::class);
    $websites->shouldReceive('publicPage')->with(Mockery::on(fn ($tenant) => $tenant->id === $this->tenant->id), '')->andReturn(['site' => $site]);
    $websites->shouldReceive('publicHostAllowed')->with(Mockery::on(fn ($value) => $value->id === $site->id), 'shop.example.test')->andReturnTrue();
    app()->instance(ManagedWebsiteService::class, $websites);
    // Exercise the real controller with a host resolved by middleware, without activating Website rollout.
    $request = Request::create('https://shop.example.test/account/preferences', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->attributes->set('host_tenant', $this->tenant);
    $request->setLaravelSession(app('session.store'));
    $controller = app(WebsiteShopperAccountController::class);
    expect(fn () => $controller->preferences($request, $websites, $this->preferences))->toThrow(HttpException::class);
    $request->session()->put('website_shopper_'.$site->id, $this->customer->id);
    $response = $controller->preferences($request, $websites, $this->preferences);
    expect($response->getData(true)['preferences']['profile_id'])->toBe($this->profile->id)->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $foreign = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $command = ($this->command)(['favorite_scents' => ['Pine']]);
    $write = Request::create('https://shop.example.test/account/preferences', 'POST', [...$command, 'profile_id' => $foreign->id, 'tenant_id' => 999999], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $write->attributes->set('host_tenant', $this->tenant);
    $write->setLaravelSession($request->session());
    $controller->updatePreferences($write, $websites, $this->preferences);
    expect($this->preferences->forCustomer($this->profile, $this->tenant->id)['declared']['favorite_scents'])->toBe(['Pine']);
    expect($this->preferences->forCustomer($foreign, $this->tenant->id)['declared']['favorite_scents'])->toBeNull();
    $request->attributes->remove('host_tenant');
    expect(fn () => $controller->preferences($request, $websites, $this->preferences))->toThrow(HttpException::class);
});

test('Website preference form renders private explicit choices and excludes account switching fields', function () {
    $this->withoutVite();
    $site = TenantSite::create(['tenant_id' => $this->tenant->id, 'status' => 'published', 'public_enabled' => true, 'subdomain' => 'fictional-form']);
    if (! \Illuminate\Support\Facades\Route::has('managed-website.store.account.preferences')) {
        \Illuminate\Support\Facades\Route::get('/account/preferences', [WebsiteShopperAccountController::class, 'preferences'])->name('managed-website.store.account.preferences');
    }
    if (! \Illuminate\Support\Facades\Route::has('managed-website.store.account.preferences.update')) {
        \Illuminate\Support\Facades\Route::post('/account/preferences', [WebsiteShopperAccountController::class, 'updatePreferences'])->name('managed-website.store.account.preferences.update');
    }
    \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
    \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);
    $websites = Mockery::mock(ManagedWebsiteService::class);
    $websites->shouldReceive('publicPage')->andReturn(['site' => $site]);
    $websites->shouldReceive('publicHostAllowed')->andReturnTrue();
    $request = Request::create('https://shop.example.test/account/preferences');
    $request->attributes->set('host_tenant', $this->tenant);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('website_shopper_'.$site->id, $this->customer->id);
    $response = app(WebsiteShopperAccountController::class)->preferences($request, $websites, $this->preferences);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->getContent())->toContain('Your declared scent preferences', 'Most-purchased scents', 'name="expected_version"', 'name="request_id"', 'name="changes[receipt_sms]"', 'Unsubscribe from email marketing')
        ->not->toContain('staff-only', 'name="profile_id"', 'name="tenant_id"', 'name="changes[email]"', 'name="changes[phone]"');
});

test('no marketing contact revokes both channels and receipt choices still remain independent', function () {
    app(MessagingContactChannelStateService::class)->markEmailStatus($this->tenant->id, $this->profile, $this->profile->email, 'subscribed', 'existing_fixture');
    app(MessagingContactChannelStateService::class)->markSmsSubscribed($this->tenant->id, $this->profile, $this->profile->phone, 'existing_fixture');
    $saved = $this->preferences->updateForWebsiteCustomer($this->customer, $this->tenant->id, ($this->command)(['preferred_channel' => 'none', 'receipt_email' => true, 'receipt_sms' => true]));
    expect($saved['communication']['email_marketing'])->toBe('unsubscribed')->and($saved['communication']['sms_marketing'])->toBe('unsubscribed')
        ->and($saved['receipts']['email'])->toBeTrue()->and($saved['receipts']['sms'])->toBeTrue();
});
