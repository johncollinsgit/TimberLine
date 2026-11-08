<?php

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
});

test('retail offer connects the whole shop while preserving setup and payment readiness boundaries', function () {
    $this->get('http://theeverbranch.com/platform/retail')->assertOk()
        ->assertSeeText('A great shop is more than its checkout.')
        ->assertSeeText('Keep Square. Add the bigger picture.')
        ->assertSeeText('Bring more of your business together.')
        ->assertSeeText('Make birthdays feel personal.')
        ->assertSeeText('Your company’s pay rules.')
        ->assertSeeText('Record time. Review the hours.')
        ->assertSeeText('hourly pay or commission')
        ->assertSeeText('per diem and mileage')
        ->assertSeeText('hotel, meals, gifts')
        ->assertSeeText('they do not process payroll')
        ->assertSeeText('limited guided pilot for approved merchants and devices')
        ->assertSeeText('Importing records does not add Everbranch reward earning or redemption to an unmodified Square POS.')
        ->assertSeeText('Native Everbranch Website reward redemption is not yet offered as a general checkout feature.')
        ->assertSeeText('Importing records does not sign anyone up for marketing.')
        ->assertSee('ILLUSTRATION · FICTIONAL DATA')
        ->assertSee(route('platform.contact', ['intent' => 'walkthrough']), false)
        ->assertSee(route('platform.start'), false)
        ->assertSee(route('platform.plans'), false)
        ->assertDontSee('apps.apple.com', false)
        ->assertDontSee('/explore/modules/pos', false)
        ->assertDontSee('/explore/modules/team_work', false);
    expect(Tenant::count())->toBe(0)->and(TenantModuleEntitlement::count())->toBe(0)->and(CustomerAccessRequest::count())->toBe(0);
    Http::assertNothingSent();
});

test('retail branch links are governed by the canonical public catalog and premium product list', function () {
    $this->get('/platform/retail')->assertOk()->assertSee(route('platform.modules.show', ['module' => 'rewards']), false)->assertSee('id="retail-team"', false);
    config()->set('module_catalog.modules.rewards.visibility.public_site', false);
    config()->set('module_catalog.modules.birthdays.visibility.public_site', false);
    config()->set('module_catalog.offers.standard.premium_products', []);
    $this->get('/platform/retail')->assertOk()
        ->assertDontSee(route('platform.modules.show', ['module' => 'rewards']), false)
        ->assertDontSee(route('platform.modules.show', ['module' => 'birthdays']), false)
        ->assertDontSee('id="retail-team"', false)
        ->assertDontSee('href="#retail-team"', false)
        ->assertDontSeeText('Teams is priced separately.');
    Http::assertNothingSent();
});

test('retail offer is discoverable without replacing the existing fictional industry example', function (string $path) {
    $this->get($path)->assertOk()->assertSee(route('platform.retail'), false);
})->with(['/platform/promo', '/platform/plans', '/platform/contact', '/platform/examples/retail']);

test('reading the retail offer does not enable any independent dispatch or checkout gate', function () {
    config(['pos_live.enabled' => false, 'pos_live.in_person_rewards_enabled' => false, 'managed_website.enabled' => false, 'marketing.customer_profile.email_dispatch_enabled' => false, 'marketing.customer_profile.sms_dispatch_enabled' => false]);
    $before = [config('pos_live'), config('managed_website'), config('marketing.customer_profile.email_dispatch_enabled'), config('marketing.customer_profile.sms_dispatch_enabled')];
    $this->get('/platform/retail')->assertOk();
    expect([config('pos_live'), config('managed_website'), config('marketing.customer_profile.email_dispatch_enabled'), config('marketing.customer_profile.sms_dispatch_enabled')])->toBe($before);
    Http::assertNothingSent();
});
