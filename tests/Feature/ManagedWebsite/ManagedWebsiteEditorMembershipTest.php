<?php

use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Models\TenantCommercialOverride;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Billing\SelfServiceStandardPurchaseService;
use App\Services\Billing\StripeWebhookIngestService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.publishing_enabled' => true,
        'managed_website.public_render_enabled' => true, 'managed_website.screenshot_enabled' => false]);
});

/** The existing Standard purchase fixture pattern: fake provider + genuine signed callback. */
function websiteMembershipConfirmFixture(Tenant $tenant, User $owner): void
{
    config()->set(['self_service_purchase.enabled' => true, 'self_service_purchase.webhook_verified' => true,
        'services.stripe.secret' => 'sk_test_membership_fixture', 'services.stripe.account_id' => 'acct_membership_fixture',
        'services.stripe.webhook_secret' => 'whsec_membership_fixture', 'services.stripe.api_base' => 'https://api.stripe.com']);
    $intent = fn (): array => (array) data_get(TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()?->billing_mapping, 'self_service_purchase');
    $session = function (bool $paid) use ($intent, $tenant): array {
        $saved = $intent();

        return ['id' => 'cs_membership_fixture', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/membership-fixture',
            'mode' => 'subscription', 'status' => $paid ? 'complete' : 'open', 'payment_status' => $paid ? 'paid' : 'unpaid',
            'client_reference_id' => $saved['id'], 'currency' => 'usd', 'amount_subtotal' => 79800, 'livemode' => false,
            'customer' => $paid ? 'cus_membership_fixture' : null, 'invoice' => $paid ? 'in_membership_fixture' : null, 'subscription' => $paid ? 'sub_membership_fixture' : null,
            'metadata' => ['purpose' => 'self_service_standard', 'purchase_id' => $saved['id'], 'quote_hash' => $saved['quote']['quote_hash'], 'tenant_id' => (string) $tenant->id]];
    };
    Http::fake(function (Request $request) use ($intent, $session) {
        $body = match (parse_url($request->url(), PHP_URL_PATH)) {
            '/v1/account' => ['id' => 'acct_membership_fixture', 'charges_enabled' => true],
            '/v1/tax/settings' => ['status' => 'active'],
            '/v1/checkout/sessions', '/v1/checkout/sessions/cs_membership_fixture' => $session($request->method() !== 'POST'),
            '/v1/subscriptions/sub_membership_fixture' => ['id' => 'sub_membership_fixture', 'object' => 'subscription', 'status' => 'active', 'livemode' => false,
                'customer' => 'cus_membership_fixture', 'latest_invoice' => 'in_membership_fixture', 'current_period_end' => now()->addMonth()->timestamp,
                'metadata' => ['purchase_id' => $intent()['id']], 'items' => ['data' => [['quantity' => 1, 'price' => ['currency' => 'usd', 'unit_amount' => 29900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]]],
            '/v1/invoices/in_membership_fixture' => ['id' => 'in_membership_fixture', 'object' => 'invoice', 'status' => 'paid', 'paid' => true, 'livemode' => false,
                'currency' => 'usd', 'customer' => 'cus_membership_fixture', 'subscription' => 'sub_membership_fixture', 'payment_intent' => 'pi_membership_fixture',
                'subtotal' => 79800, 'total' => 79800, 'amount_paid' => 79800, 'amount_remaining' => 0, 'status_transitions' => ['paid_at' => now()->timestamp]],
            '/v1/payment_intents/pi_membership_fixture' => ['id' => 'pi_membership_fixture', 'status' => 'succeeded', 'amount_received' => 79800, 'currency' => 'usd',
                'customer' => 'cus_membership_fixture', 'livemode' => false, 'latest_charge' => ['id' => 'ch_membership_fixture', 'amount_refunded' => 0, 'disputed' => false]],
            default => throw new RuntimeException('Unexpected provider call in the website membership fixture.'),
        };

        return Http::response($body);
    });
    $purchase = app(SelfServiceStandardPurchaseService::class);
    $quote = $purchase->quote($tenant, $owner, ['managed_website']);
    expect($purchase->checkout($tenant, $owner, ['managed_website'], ['terms_accepted' => true, 'quote_hash' => $quote['quote_hash']])['ok'])->toBeTrue();
    $payload = json_encode(['id' => 'evt_membership_fixture', 'type' => 'checkout.session.completed', 'livemode' => false,
        'created' => now()->timestamp, 'data' => ['object' => $session(true)]], JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_membership_fixture');
    app(StripeWebhookIngestService::class)->ingest($payload, $signature);
    expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue()
        ->and(TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
}

function websiteMembershipFixture(bool $paid = false): array
{
    $tenant = Tenant::query()->create(['name' => 'Fictional Member Studio', 'slug' => 'fictional-member-studio']);
    $owner = User::factory()->tenantAdmin()->create(['approved_at' => now()]);
    $owner->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
    if ($paid) {
        TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct',
            'source' => 'self_service_registration', 'metadata' => ['self_service_registration' => true]]);
        websiteMembershipConfirmFixture($tenant, $owner);
    } else {
        TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available',
            'enabled_status' => 'enabled', 'billing_status' => 'add_on_paid', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
        config()->set('managed_website.editor_tenant_ids', [$tenant->id]);
    }
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $owner);
    $page = $site->pages()->where('slug', '/')->firstOrFail();

    return [$tenant, $owner, $site, $page];
}

test('a global admin cannot edit an invited website through a viewer or inactive membership', function (bool $paid, string $role, bool $active): void {
    [$tenant, $owner, $site, $page] = websiteMembershipFixture($paid);
    $other = Tenant::query()->create(['name' => 'Fictional owned workspace', 'slug' => 'fictional-owned-workspace']);
    $actor = User::factory()->tenantAdmin()->create(['approved_at' => now()]);
    $actor->tenants()->attach($other, ['role' => 'owner', 'membership_active' => true]);
    $actor->tenants()->attach($tenant, ['role' => $role, 'membership_active' => $active]);
    $url = fn (string $path): string => 'https://app.theeverbranch.com'.$path.'?tenant='.$tenant->slug;
    $draft = $page->draft_version_id;
    $siteDraft = $site->draft_site_version_id;
    $providerCalls = Http::recorded()->count();
    $financial = [TenantBillingReceipt::withoutGlobalScopes()->count(), TenantBillingFulfillment::withoutGlobalScopes()->count(), TenantBillingSubscription::withoutGlobalScopes()->count(), StripeWebhookEvent::query()->count()];
    $this->actingAs($actor);
    foreach (['/website', '/website/editor/'.$page->id, '/website/editor/'.$page->id.'/preview-site', '/website/editor/'.$page->id.'/history',
        '/website/products', '/website/collections', '/website/connected', '/website/media'] as $path) {
        $this->getJson($url($path))->assertForbidden();
    }
    $this->putJson($url('/website/editor/'.$page->id), ['current_version_id' => $draft, 'title' => 'Forbidden member edit', 'blocks' => [['type' => 'text', 'body' => 'Must stay private']], 'seo' => []])->assertForbidden();
    $this->putJson($url('/website/editor/theme'), ['current_version_id' => $siteDraft, 'settings' => ['theme_name' => 'Forbidden style'], 'navigation' => []])->assertForbidden();
    foreach (['/website/media', '/website/pages', '/website/publish', '/website/domains', '/website/editor/'.$page->id.'/history/'.$draft.'/restore', '/website/history/site/'.$siteDraft.'/restore'] as $path) {
        $this->postJson($url($path), ['current_version_id' => $draft])->assertForbidden();
    }
    expect($page->fresh()->draft_version_id)->toBe($draft)->and($site->fresh()->draft_site_version_id)->toBe($siteDraft)
        ->and($site->media()->count())->toBe(0)->and(Http::recorded()->count())->toBe($providerCalls)
        ->and([TenantBillingReceipt::withoutGlobalScopes()->count(), TenantBillingFulfillment::withoutGlobalScopes()->count(), TenantBillingSubscription::withoutGlobalScopes()->count(), StripeWebhookEvent::query()->count()])->toBe($financial);
})->with(['legacy viewer' => [false, 'viewer', true], 'legacy inactive admin' => [false, 'admin', false], 'paid viewer' => [true, 'viewer', true], 'paid inactive admin' => [true, 'admin', false]]);

test('active website editors retain private editing while publishing remains owner or admin only', function (string $role): void {
    [$tenant, $owner, $site, $page] = websiteMembershipFixture();
    $actor = User::factory()->tenantAdmin()->create(['approved_at' => now()]);
    $actor->tenants()->attach($tenant, ['role' => $role, 'membership_active' => true]);
    $url = fn (string $path): string => 'https://app.theeverbranch.com'.$path.'?tenant='.$tenant->slug;
    $this->actingAs($actor)->get($url('/website/editor/'.$page->id))->assertOk();
    $this->putJson($url('/website/editor/'.$page->id), ['current_version_id' => $page->draft_version_id,
        'title' => 'Approved member edit', 'blocks' => [['type' => 'text', 'body' => 'Saved by an active editor']], 'seo' => []])->assertOk();
    expect($page->fresh()->draftVersion->title)->toBe('Approved member edit');
    if (in_array($role, ['manager', 'marketing_manager'], true)) {
        $this->postJson($url('/website/publish'))->assertForbidden();
        expect($page->fresh()->published_version_id)->toBeNull()->and($site->fresh()->published_site_version_id)->toBeNull();
    }
    Http::assertNothingSent();
})->with(['owner', 'tenant_owner', 'admin', 'manager', 'marketing_manager']);

test('website membership cannot select an unrelated tenant or its page', function (): void {
    [$tenant, $owner, $site, $page] = websiteMembershipFixture();
    $other = Tenant::query()->create(['name' => 'Unrelated private studio', 'slug' => 'unrelated-private-studio']);
    $otherSite = app(ManagedWebsiteService::class)->createSite($other, null);
    $otherPage = $otherSite->pages()->where('slug', '/')->firstOrFail();
    $before = $otherPage->draft_version_id;
    $this->actingAs($owner)->getJson('https://app.theeverbranch.com/website/editor/'.$otherPage->id.'?tenant='.$tenant->slug)->assertNotFound();
    $this->getJson('https://app.theeverbranch.com/website/editor/'.$otherPage->id.'?tenant='.$other->slug)->assertForbidden();
    expect($otherPage->fresh()->draft_version_id)->toBe($before);
    Http::assertNothingSent();
});

test('editor membership restrictions do not gate the explicitly published public website', function (): void {
    [$tenant, $owner, $site, $page] = websiteMembershipFixture();
    app(ManagedWebsiteService::class)->publish($site, $owner);
    $viewer = User::factory()->tenantAdmin()->create();
    $viewer->tenants()->attach($tenant, ['role' => 'viewer', 'membership_active' => false]);
    $this->actingAs($viewer)->get('https://'.$tenant->slug.'.theeverbranch.com/')->assertOk()->assertSeeText($tenant->name);
    Http::assertNothingSent();
});
