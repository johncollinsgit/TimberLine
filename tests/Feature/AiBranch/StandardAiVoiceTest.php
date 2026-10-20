<?php

use App\Models\AiBranchAccount;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBillingSubscription;
use App\Models\TenantMessagingCreditAccount;
use App\Models\TenantMessagingLedgerEntry;
use App\Models\User;
use App\Services\AiBranch\AiBranchAccessPurchase;
use App\Services\AiBranch\AiBranchChat;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['ai_branch.standard_enabled' => true, 'ai_branch.existing_tenants' => [], 'ai_branch.api_key' => 'dedicated-test-key',
        'ai_branch.rates_valid_until' => now()->addMonth()->toIso8601String()]);
});

function standardAiTenant($test, string $slug = 'existing-ai-voice'): array
{
    $tenant = Tenant::create(['name' => 'Fictional AI workspace', 'slug' => $slug]);
    $owner = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    config(['ai_branch.existing_tenants' => [...config('ai_branch.existing_tenants'), $tenant->id => $tenant->slug]]);
    $test->artisan('ai-branch:include-existing', ['--apply' => true, '--authorization' => 'owner-approved-existing-tenants'])->assertSuccessful();

    return [$tenant, $owner];
}

function approveStandardAi($test, Tenant $tenant, User $owner, string $model = 'gpt-6-luna', string $cap = '5.00'): void
{
    $test->actingAs($owner)->postJson('/ai-branch/settings?tenant='.$tenant->slug,
        ['enabled' => true, 'budget_dollars' => $cap, 'model' => $model, 'confirmed' => true])->assertRedirect();
}

test('existing access is included without enabling usage and fixed rollout cannot grandfather future tenants', function () {
    [$tenant, $owner] = standardAiTenant($this);
    $future = Tenant::create(['name' => 'Future workspace', 'slug' => 'future-ai-voice']);
    $account = AiBranchAccount::where('tenant_id', $tenant->id)->sole();
    expect($account->enabled)->toBeFalse()->and($account->budget_cents)->toBe(0);
    $this->actingAs($owner)->get('/ai-branch?tenant='.$tenant->slug)->assertOk()->assertSeeText('AI access included')->assertSeeText('AI model');
    $this->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertForbidden();
    $this->artisan('ai-branch:include-existing', ['--apply' => true, '--authorization' => 'repeat-review'])->assertSuccessful();
    expect(AiBranchAccount::where('tenant_id', $future->id)->exists())->toBeFalse()
        ->and(TenantBillingSubscription::count())->toBe(0)->and(TenantAccessAddon::count())->toBe(0);
    Http::assertNothingSent();
});

test('owners approve model and positive customer cap and prepaid credit is required before provider dispatch', function () {
    [$tenant, $owner] = standardAiTenant($this);
    $url = '/ai-branch/settings?tenant='.$tenant->slug;
    $this->actingAs($owner)->postJson($url, ['enabled' => true, 'budget_dollars' => '0', 'model' => 'gpt-6-luna', 'confirmed' => true])->assertStatus(422);
    $this->postJson($url, ['enabled' => true, 'budget_dollars' => '5', 'model' => 'arbitrary-expensive-model', 'confirmed' => true])->assertStatus(422);
    approveStandardAi($this, $tenant, $owner);
    $conversation = app(AiBranchChat::class)->start($tenant, $owner);
    $this->postJson('/ai-branch/conversations/'.$conversation->id.'/messages?tenant='.$tenant->slug,
        ['question' => 'Hello', 'request_id' => (string) Str::uuid()])->assertStatus(429);
    expect(TenantAiUsageEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('settlement charges exactly twice cached-aware model cost from shared credit once and cannot invoice it again', function () {
    [$tenant, $owner] = standardAiTenant($this);
    approveStandardAi($this, $tenant, $owner);
    app(TenantMessagingUsageService::class)->fund($tenant->id, 1000000, 'fictional-test-credit');
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp_usage', 'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A scoped response.']]]],
        'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 100], 'output_tokens' => 100]])]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $owner);
    $uuid = (string) Str::uuid();
    $chat->ask($tenant, $owner, $conversation->id, 'Hello', $uuid);
    $chat->ask($tenant, $owner, $conversation->id, 'Hello', $uuid);
    $event = TenantAiUsageEvent::sole();
    $credit = TenantMessagingCreditAccount::sole();
    expect($event->provider_cost_micros)->toBe(141)->and($event->buyer_charge_micros)->toBe(282)
        ->and($credit->balance_micros)->toBe(999718)->and($credit->reserved_micros)->toBe(0)
        ->and(TenantMessagingLedgerEntry::where('entry_type', 'usage_settlement')->count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['model'] === 'gpt-6-luna' && $request['reasoning']['effort'] === 'none' && $request['store'] === false);
    $event->update(['occurred_at' => now()->subMonths(2)]);
    expect(app(\App\Services\Billing\TenantAiUsageInvoiceService::class)->invoiceClosedMonths($tenant->slug, false)['groups'])->toBe(0);
});

test('an uncertain model request holds both cap and wallet reservation until reviewed no-charge reconciliation', function () {
    [$tenant, $owner] = standardAiTenant($this);
    approveStandardAi($this, $tenant, $owner);
    app(TenantMessagingUsageService::class)->fund($tenant->id, 1000000, 'fictional-test-credit');
    Http::fake(['api.openai.com/*' => Http::response([], 500)]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $owner);
    $uuid = (string) Str::uuid();
    expect(fn () => $chat->ask($tenant, $owner, $conversation->id, 'Hello', $uuid))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $chat->ask($tenant, $owner, $conversation->id, 'Hello', $uuid))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $event = TenantAiUsageEvent::sole();
    $credit = TenantMessagingCreditAccount::sole();
    expect($event->status)->toBe('uncertain')->and($credit->reserved_micros)->toBeGreaterThan(0);
    $event->update(['created_at' => now()->subMinutes(3)]);
    $operator = User::factory()->create(['role' => 'platform_admin', 'is_active' => true]);
    $this->artisan('ai-branch:reconcile-usage', ['event' => $event->id, '--actor' => $operator->id, '--evidence' => 'provider-confirmed-no-charge', '--no-charge' => true, '--apply' => true])->assertSuccessful();
    expect($credit->fresh()->reserved_micros)->toBe(0)->and($credit->fresh()->balance_micros)->toBe(1000000);
    Http::assertSentCount(1);
});

test('model changes invalidate old context and an admin cannot approve owner usage terms', function () {
    [$tenant, $owner] = standardAiTenant($this);
    approveStandardAi($this, $tenant, $owner);
    $conversation = app(AiBranchChat::class)->start($tenant, $owner);
    approveStandardAi($this, $tenant, $owner, 'gpt-6.1-sol');
    expect(fn () => app(AiBranchChat::class)->conversation($tenant, $owner, $conversation->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $owner->tenants()->updateExistingPivot($tenant->id, ['role' => 'admin']);
    $this->actingAs($owner)->postJson('/ai-branch/settings?tenant='.$tenant->slug,
        ['enabled' => true, 'budget_dollars' => '10', 'model' => 'gpt-6-astra', 'confirmed' => true])->assertForbidden();
    Http::assertNothingSent();
});

test('future tenant checkout uses exact 20 dollar price and never grants access from a redirect', function () {
    $tenant = Tenant::create(['name' => 'Fictional Future', 'slug' => 'future-twenty-ai']);
    $owner = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    config(['services.stripe.secret' => 'sk_test_ai20', 'services.stripe.api_base' => 'https://api.stripe.com',
        'commercial.billing_readiness.lifecycle_mutations_enabled' => true, 'ai_branch.model' => 'gpt-6-luna',
        'ai_branch.input_micros_per_million' => 100000, 'ai_branch.output_micros_per_million' => 500000]);
    Http::fake(['api.stripe.com/v1/prices*' => Http::response(['data' => [['id' => 'price_ai20', 'lookup_key' => 'addon_ai_branch_access_monthly', 'active' => true, 'livemode' => false,
        'currency' => 'usd', 'unit_amount' => 2000, 'billing_scheme' => 'per_unit', 'recurring' => ['interval' => 'month', 'interval_count' => 1], 'metadata' => ['purchase_key' => 'addon.ai_branch_access']]], 'has_more' => false]),
        'api.stripe.com/v1/customers/search*' => Http::response(['data' => [], 'has_more' => false]),
        'api.stripe.com/v1/checkout/sessions*' => fn () => Http::response(['id' => 'cs_test_ai20', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_ai20', 'mode' => 'subscription', 'currency' => 'usd', 'amount_subtotal' => 2000,
            'livemode' => false, 'status' => 'open', 'metadata' => ['ai_purchase_request_id' => data_get(TenantAccessProfile::first()->metadata, 'ai_branch_purchase.request_id')]])]);
    $result = app(AiBranchAccessPurchase::class)->prepare($tenant, $owner, 'owner-reviewed-20-dollar-access');
    expect($result['monthly_cents'])->toBe(2000)->and($result['paid'])->toBeFalse()->and(TenantBillingSubscription::count())->toBe(0)->and(AiBranchAccount::count())->toBe(0);
    $this->actingAs($owner)->get('/ai-branch?tenant='.$tenant->slug)->assertOk()->assertSeeText('$20/month access');
    $this->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertNotFound();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/sessions') && $request['line_items[0][price]'] === 'price_ai20');
});

test('a paid future workspace uses the same native memory adapter and loses access on failed renewal', function () {
    $tenant = Tenant::create(['name' => 'Fictional Future', 'slug' => 'future-paid-ai']);
    $owner = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_access', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    $subscription = TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_future20',
        'purchase_key' => 'addon.ai_branch_access', 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
    approveStandardAi($this, $tenant, $owner);
    \Laravel\Sanctum\Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant';
    $this->getJson($base)->assertOk()->assertJsonPath('commercial.model', 'gpt-6-luna')->assertJsonPath('context.workspace.name', $tenant->name);
    $this->postJson($base.'/memories', ['body' => 'Prefer concise answers.', 'confirmed' => true])->assertCreated();
    $this->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertOk()->assertJsonPath('memories.0.body', 'Prefer concise answers.');
    $subscription->update(['status' => 'past_due']);
    $this->getJson($base)->assertForbidden();
    Http::assertNothingSent();
});

test('preparing the 20 dollar access price previews first and reuses its existing price without customer writes', function () {
    config(['services.stripe.secret' => 'sk_test_ai20', 'services.stripe.api_base' => 'https://api.stripe.com']);
    $saved = null;
    Http::fake(function ($request) use (&$saved) {
        if ($request->method() === 'GET') {
            return Http::response(['data' => $saved ? [$saved] : [], 'has_more' => false]);
        }
        expect($request->url())->toBe('https://api.stripe.com/v1/prices');
        $saved = ['id' => 'price_ai20', 'active' => true, 'livemode' => false, 'currency' => 'usd', 'unit_amount' => 2000,
            'lookup_key' => 'addon_ai_branch_access_monthly', 'billing_scheme' => 'per_unit', 'recurring' => ['interval' => 'month', 'interval_count' => 1],
            'metadata' => ['purchase_key' => 'addon.ai_branch_access']];

        return Http::response($saved);
    });
    $this->artisan('ai-branch:prepare-access-price')->assertSuccessful();
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    $args = ['--apply' => true, '--authorization' => 'approved-20-dollar-future-access'];
    $this->artisan('ai-branch:prepare-access-price', $args)->assertSuccessful();
    $this->artisan('ai-branch:prepare-access-price', $args)->assertSuccessful();
    expect(Http::recorded(fn ($request) => $request->method() === 'POST'))->toHaveCount(1);
    expect(TenantBillingSubscription::count())->toBe(0);
});

test('the focused soccer shell retains the included AI setup entry without granting family access', function () {
    [$tenant, $owner] = standardAiTenant($this);
    config(['soccer.enabled' => true]);
    TenantAccessProfile::where('tenant_id', $tenant->id)->firstOrFail()->update(['metadata' => ['workspace_focus' => 'soccer']]);
    \App\Models\TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => 'soccer', 'enabled_override' => true]);
    $this->actingAs($owner)->get('/ai-branch?tenant='.$tenant->slug)->assertOk()->assertSee('AI Branch');
    $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($member)->get('/ai-branch?tenant='.$tenant->slug)->assertForbidden();
    Http::assertNothingSent();
});

test('an owner funds shared AI credit through a guarded checkout while messaging stays off and payment is required once', function () {
    [$tenant, $owner] = standardAiTenant($this);
    config(['features.tenant_messaging_credit_checkout' => false, 'services.stripe.secret' => 'sk_test_service_credit',
        'services.stripe.webhook_secret' => 'whsec_service_credit', 'services.stripe.api_base' => 'https://api.stripe.com',
        'commercial.billing_readiness.lifecycle_mutations_enabled' => true]);
    $url = '/ai-branch/credit-checkout?tenant='.$tenant->slug;
    $args = ['pack_cents' => 2500, 'confirmed' => true];
    $this->actingAs($owner)->postJson($url, $args)->assertForbidden();
    approveStandardAi($this, $tenant, $owner);
    $admin = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    $this->actingAs($admin)->postJson($url, $args)->assertForbidden();
    Http::assertNothingSent();
    $owner->update(['role' => 'employee']);
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_shared_ai', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_shared_ai',
        'mode' => 'payment', 'currency' => 'usd', 'status' => 'open', 'amount_subtotal' => 2500, 'livemode' => false,
        'metadata' => ['tenant_id' => (string) $tenant->id, 'pack_cents' => '2500', 'credit_scope' => 'ai_shared']])]);
    $this->actingAs($owner)->postJson($url, $args)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_shared_ai');
    expect(TenantMessagingCreditAccount::count())->toBe(0)->and(config('features.tenant_messaging_credit_checkout'))->toBeFalse();
    Http::assertSent(fn ($request) => $request['payment_method_types[0]'] === 'card' && $request['metadata[credit_scope]'] === 'ai_shared');
    $event = ['id' => 'evt_shared_ai_credit', 'type' => 'checkout.session.completed', 'livemode' => false, 'created' => time(),
        'data' => ['object' => ['id' => 'cs_test_shared_ai', 'mode' => 'payment', 'currency' => 'usd', 'amount_total' => 2500, 'payment_status' => 'paid',
            'metadata' => ['purpose' => 'messaging_credit', 'credit_scope' => 'ai_shared', 'tenant_id' => (string) $tenant->id, 'pack_cents' => '2500']]]];
    $ingest = function (array $event) {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();

        return app(\App\Services\Billing\StripeWebhookIngestService::class)->ingest($payload, 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_service_credit'));
    };
    $wrongMode = [...$event, 'livemode' => true];
    expect($ingest($wrongMode)['status_code'])->toBe(400);
    expect($ingest($event)['message'])->toBe('processed_credit');
    $event['id'] = 'evt_shared_ai_credit_second';
    $event['type'] = 'checkout.session.async_payment_succeeded';
    expect($ingest($event)['message'])->toBe('processed_credit');
    expect(TenantMessagingCreditAccount::sole()->balance_micros)->toBe(25000000)
        ->and(TenantMessagingLedgerEntry::where('entry_type', 'credit_funding')->count())->toBe(1);
});

test('admin views explain owner-only setup and the tenant audit records the missing cap approver', function () {
    [$tenant, $owner] = standardAiTenant($this);
    $tenant->users()->updateExistingPivot($owner->id, ['role' => 'admin']);
    $this->actingAs($owner)->get('/ai-branch?tenant='.$tenant->slug)->assertOk()
        ->assertSeeText('Only a workspace owner can approve AI models and spending caps.')
        ->assertDontSeeText('Save settings')->assertDontSeeText('Review credit purchase');
    expect(\Illuminate\Support\Facades\Artisan::call('ai-branch:audit-voice', ['--json' => true]))->toBe(0);
    $audit = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
    expect($audit['tenants'][0]['cap_approvers'])->toBe(0)->and($audit['tenants'][0]['setup_ready'])->toBeFalse();
    Http::assertNothingSent();
});
