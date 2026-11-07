<?php

use App\Http\Controllers\CustomerStaffPreferenceController;
use App\Models\MarketingProfile;
use App\Models\TenantModuleEntitlement;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerPreferenceService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RequestRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->owner->forceFill(['email_verified_at' => now()])->save();
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Customer', 'notes' => 'Private staff-only fixture note']);
    $this->preferences = app(CustomerPreferenceService::class);
    $this->command = fn (array $changes) => ['request_id' => (string) Str::uuid(), 'expected_version' => $this->preferences->forStaff($this->profile, $this->tenant->id)['version'], 'customer_confirmed' => true, 'changes' => $changes];
    if (! Route::has('customers.profile')) {
        Route::get('/workspace/customers/{profile}', fn () => 'fixture')->name('customers.profile');
    }
    if (! Route::has('customers.preferences.update')) {
        Route::post('/workspace/customers/{profile}/preferences', CustomerStaffPreferenceController::class)->name('customers.preferences.update');
    }
    Route::getRoutes()->refreshNameLookups();
});

function customerPreferenceStaffRequest($test, array $input, bool $json = true, ?MarketingProfile $profile = null, $actor = null): Request
{
    $id = ($profile ?? $test->profile)->id;
    $request = Request::create('https://app.example.test/workspace/customers/'.$id.'/preferences', 'POST', $input, [], [], ['HTTP_ACCEPT' => $json ? 'application/json' : 'text/html']);
    $request->attributes->set('current_tenant', $test->tenant);
    $request->setUserResolver(fn () => $actor ?? $test->owner);
    $request->setLaravelSession(app('session.store'));
    $route = new RequestRoute('POST', 'workspace/customers/{profile}/preferences', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function customerPreferenceStaffWrite($test, Request $request)
{
    return app(CustomerStaffPreferenceController::class)($request, $test->preferences, app(CanonicalMarketingProfileResolver::class));
}

test('staff preference controller uses current tenant canonical customer and manager authority', function () {
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->profile->id]);
    $command = [...($this->command)(['favorite_scents' => ['Cedar'], 'receipt_email' => true]), 'tenant_id' => 99999, 'profile_id' => 99999];
    $response = customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, $command, true, $alias));
    expect($response->getData(true)['preferences']['profile_id'])->toBe($this->profile->id)
        ->and($response->getData(true)['preferences']['declared']['favorite_scents'])->toBe(['Cedar'])
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $member = teamMember($this->tenant, 'member');
    $member->forceFill(['email_verified_at' => now()])->save();
    expect(fn () => customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, ($this->command)(['receipt_email' => false]), true, null, $member)))->toThrow(HttpException::class);
    $this->owner->forceFill(['email_verified_at' => null])->save();
    expect(fn () => customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, ($this->command)(['receipt_email' => false]))))->toThrow(HttpException::class);
});

test('staff preference controller rejects foreign customers missing tenant and disabled customer capability', function () {
    [$other] = teamWorkspace();
    $foreign = MarketingProfile::create(['tenant_id' => $other->id]);
    expect(fn () => customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, ($this->command)(['receipt_email' => true]), true, $foreign)))->toThrow(HttpException::class);
    $missing = customerPreferenceStaffRequest($this, ($this->command)(['receipt_email' => true]));
    $missing->attributes->remove('current_tenant');
    expect(fn () => customerPreferenceStaffWrite($this, $missing))->toThrow(HttpException::class);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'customers', 'enabled_status' => 'disabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    expect(fn () => customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, ($this->command)(['receipt_email' => true]))))->toThrow(HttpException::class);
});

test('unchecked staff Apply fields preserve unknown scents names and tri-state choices', function () {
    $command = ($this->command)(['receipt_email' => '', 'receipt_sms' => '', 'rewards_communications' => '', 'preferred_channel' => '', 'first_name' => 'Do not apply']);
    $command['favorite_scents_text'] = '';
    $command['avoided_scents_text'] = 'Do not apply';
    $response = customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, $command, false));
    expect($response->getStatusCode())->toBe(302)->and($response->getTargetUrl())->toContain('/workspace/customers/'.$this->profile->id);
    $saved = $this->preferences->forStaff($this->profile, $this->tenant->id);
    expect($saved['declared']['favorite_scents'])->toBeNull()->and($saved['declared']['avoided_scents'])->toBeNull()
        ->and($saved['receipts']['email'])->toBeNull()->and($saved['receipts']['sms'])->toBeNull()
        ->and($saved['rewards']['communications'])->toBeNull()->and($saved['communication']['preferred_channel'])->toBeNull()
        ->and($this->profile->fresh()->first_name)->toBe('Fictional');
});

test('staff checked Apply can explicitly clear a scent list without granting consent', function () {
    $command = [...($this->command)(['email_marketing' => true, 'sms_marketing' => true, 'first_name' => 'Updated']),
        'apply_favorite_scents' => '1', 'favorite_scents_text' => '', 'apply_avoided_scents' => '1', 'avoided_scents_text' => "Pine\nCedar", 'apply_contact_names' => '1'];
    customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, $command, false));
    $saved = $this->preferences->forStaff($this->profile, $this->tenant->id);
    expect($saved['declared']['favorite_scents'])->toBe([])->and($saved['declared']['avoided_scents'])->toBe(['Pine', 'Cedar'])
        ->and($saved['communication']['email_marketing'])->toBe('unknown')->and($saved['communication']['sms_marketing'])->toBe('unknown')
        ->and($saved['contact']['first_name'])->toBe('Updated');
    expect($saved['provenance']['favorite_scents']['source'])->toBe('staff_customer_choice');
});

test('staff stale edits and missing customer attestation return private validation without overwrites', function () {
    $command = ($this->command)(['favorite_scents' => ['Cedar']]);
    $missing = $command;
    unset($missing['customer_confirmed']);
    $response = customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, $missing));
    expect($response->getStatusCode())->toBe(422)->and($response->getData(true)['errors'])->toHaveKey('customer_confirmed')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, $command));
    $stale = [...$command, 'request_id' => (string) Str::uuid(), 'changes' => ['favorite_scents' => ['Pine']]];
    $response = customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, $stale));
    expect($response->getStatusCode())->toBe(409)->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $html = [...$stale, 'apply_favorite_scents' => 1, 'favorite_scents_text' => 'Pine'];
    $response = customerPreferenceStaffWrite($this, customerPreferenceStaffRequest($this, $html, false));
    expect($response->getStatusCode())->toBe(302)->and(session()->get('errors')->getBag('customerPreferences')->has('preferences'))->toBeTrue()
        ->and(session()->getOldInput('request_id'))->toBe($stale['request_id']);
    expect($this->preferences->forStaff($this->profile, $this->tenant->id)['declared']['favorite_scents'])->toBe(['Cedar']);
});

test('staff preference partial exposes explicit Apply controls and a read-only role projection', function () {
    View::share('errors', new ViewErrorBag);
    $preferences = $this->preferences->forStaff($this->profile, $this->tenant->id);
    $editable = view('customers.preferences', ['preferences' => $preferences, 'tenant' => $this->tenant, 'profile' => $this->profile, 'canEditPreferences' => true])->render();
    expect($editable)->toContain('Declared favorite scents', 'Unknown — no current declared choice', 'Receipt delivery choices are separate', 'name="apply_favorite_scents"', 'name="apply_avoided_scents"', 'name="apply_contact_names"', 'name="customer_confirmed"', 'name="expected_version"', 'name="request_id"', 'name="revoke_sms_marketing"', 'existing verified subscription flow')
        ->not->toContain('staff-only', 'name="changes[email]"', 'name="changes[phone]"', 'name="changes[email_marketing]"', 'name="changes[sms_marketing]"', 'name="tenant_id"', 'name="profile_id"');
    $readonly = view('customers.preferences', ['preferences' => $preferences, 'tenant' => $this->tenant, 'profile' => $this->profile, 'canEditPreferences' => false])->render();
    expect($readonly)->toContain('active verified customer manager')->not->toContain('<form', 'name="request_id"', 'staff-only');
});
