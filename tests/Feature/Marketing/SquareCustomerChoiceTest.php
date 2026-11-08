<?php

use App\Http\Controllers\SquareCustomerConnectionController;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareCustomerIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => null]);
    $this->tenant = Tenant::create(['name' => 'Fictional customer choice shop', 'slug' => 'square-choice-'.Str::lower(Str::random(10))]);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($this->owner);
    app('request')->setLaravelSession(app('session.store'));
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
});

function squareChoiceIndex($test, mixed $query = null)
{
    $request = Request::create('/workspace/customers/square', 'GET', $query === null ? [] : ['customer_query' => $query]);
    $request->attributes->set('current_tenant', $test->tenant);
    $request->setUserResolver(fn () => $test->owner);

    return app(SquareCustomerConnectionController::class)->index($request, app(SquareConnectionContext::class), app(SquareCustomerIdentityService::class));
}

function squareChoiceProfile(int $tenantId, string $first, array $extra = []): MarketingProfile
{
    return MarketingProfile::create(['tenant_id' => $tenantId, 'first_name' => $first, 'last_name' => 'Choice',
        'email' => strtolower($first).'@fictional-choice.invalid', 'phone' => '+18645550123', ...$extra]);
}

test('source review selector names current tenant candidates and canonical survivors without selecting anyone', function () {
    $canonical = squareChoiceProfile($this->tenant->id, 'Cedar');
    $alias = squareChoiceProfile($this->tenant->id, 'Previous', ['merged_into_profile_id' => $canonical->id]);
    $archived = squareChoiceProfile($this->tenant->id, 'Archived', ['archived_at' => now()]);
    $foreignTenant = Tenant::create(['name' => 'Foreign choice shop', 'slug' => 'foreign-choice-'.Str::random(8)]);
    $foreign = squareChoiceProfile($foreignTenant->id, 'Foreign');
    $review = MarketingIdentityReview::create(['status' => 'pending', 'source_type' => 'square_customer', 'source_id' => 'FICTIONAL-REVIEW',
        'payload' => ['source_context' => ['tenant_id' => $this->tenant->id], 'candidate_profile_ids' => [$alias->id, $foreign->id, $archived->id]]]);
    $version = app(SquareCustomerIdentityService::class)->version($review);
    $response = squareChoiceIndex($this);
    $data = $response->original->getData();
    expect($data['reviewCustomerChoices'][$review->id])->toBe([['id' => $canonical->id, 'name' => 'Cedar Choice', 'email' => $canonical->email, 'phone' => $canonical->phone]])
        ->and($data['customerSearch']['customers'])->toBe([]);
    $html = $response->getContent();
    expect($html)->toContain('<select name="profile_id"', '<option value="" selected>Choose a customer</option>', 'Suggested from this source', 'Cedar Choice', $canonical->email, $canonical->phone, 'x-bind:required="decision === \'link_existing\'"')
        ->not->toContain('Existing canonical customer ID', 'Foreign Choice', 'Archived Choice', '<input name="profile_id"');
    expect(app(SquareCustomerIdentityService::class)->version($review->fresh()))->toBe($version)
        ->and(MarketingProfileLink::count())->toBe(0)->and(MarketingConsentEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('owner source customer search returns at most twenty safe tenant scoped contacts and named separate options', function () {
    $suggested = squareChoiceProfile($this->tenant->id, 'FernSuggested');
    for ($i = 0; $i < 24; $i++) {
        squareChoiceProfile($this->tenant->id, 'Fern'.$i);
    }
    $foreignTenant = Tenant::create(['name' => 'Other search shop', 'slug' => 'other-search-'.Str::random(8)]);
    squareChoiceProfile($foreignTenant->id, 'FernForeign', ['notes' => 'PRIVATE_CARD_OR_WALLET_DATA']);
    $review = MarketingIdentityReview::create(['status' => 'pending', 'source_type' => 'square_customer', 'source_id' => 'FICTIONAL-SEARCH-REVIEW',
        'payload' => ['source_context' => ['tenant_id' => $this->tenant->id], 'candidate_profile_ids' => [$suggested->id]]]);
    $response = squareChoiceIndex($this, ' Fern ');
    $data = $response->original->getData();
    expect($data['customerQuery'])->toBe('Fern')->and($data['customerSearch']['customers'])->toHaveCount(20)
        ->and($data['customerSearch']['has_more'])->toBeTrue()->and($data['customerSearch']['page'])->toBe(1);
    foreach ($data['customerSearch']['customers'] as $customer) {
        expect(array_keys($customer))->toBe(['id', 'name', 'email', 'phone']);
        expect($customer['name'])->not->toBe('FernForeign Choice');
    }
    $html = $response->getContent();
    expect($html)->toContain('Other matching customers', 'Suggested from this source', 'More records match.', 'name="customer_query"', 'name="tenant"')
        ->not->toContain('FernForeign Choice', 'PRIVATE_CARD_OR_WALLET_DATA');
    expect(substr_count($html, '<option value="'.$suggested->id.'">'))->toBe(1)
        ->and(MarketingProfileLink::count())->toBe(0)->and(MarketingIdentityReview::count())->toBe(1)->and(MarketingConsentEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('source customer search uses normalized phone matching and literal escaped wildcard text', function () {
    $own = squareChoiceProfile($this->tenant->id, 'Phone', ['phone' => '(864) 555-0123', 'normalized_phone' => '+18645550123']);
    expect(squareChoiceIndex($this, '8645550123')->original->getData()['customerSearch']['customers'][0]['id'])->toBe($own->id)
        ->and(squareChoiceIndex($this, '_%')->original->getData()['customerSearch']['customers'])->toBe([]);
    Http::assertNothingSent();
});

test('source customer search rejects malformed short and oversized queries before exposing choices', function (mixed $query) {
    try {
        squareChoiceIndex($this, $query);
        test()->fail('The source page must validate its bounded read-only customer query.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('customer_query');
    }
    Http::assertNothingSent();
})->with(['short' => 'x', 'trimmed short' => ' x ', 'oversized' => str_repeat('x', 121), 'array' => [['unsafe']]]);

test('source customer choice lookup remains owner only even when a member can read Core customers', function () {
    squareChoiceProfile($this->tenant->id, 'Private');
    $this->tenant->users()->updateExistingPivot($this->owner, ['role' => 'member']);
    try {
        squareChoiceIndex($this, 'Private');
        test()->fail('A regular member must not query the owner-only source association page.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(403);
    }
    Http::assertNothingSent();
});
