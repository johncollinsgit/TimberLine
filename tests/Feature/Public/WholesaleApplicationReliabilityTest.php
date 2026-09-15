<?php

use App\Models\CustomerAccessRequest;
use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WholesaleApplicationDecisionNotification;
use App\Notifications\WholesaleApplicationReviewNotification;
use App\Services\Forms\TenantFormSubmissionService;
use App\Services\Onboarding\CustomerAccessApprovalService;
use App\Services\Onboarding\WholesaleApplicationDeliveryService;
use App\Services\Shopify\ShopifyWholesaleCustomerApprovalService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    config(['product_surfaces.access_request.wholesale_storefront_tenant_slug' => 'modern-forestry']);
    $this->tenant = Tenant::create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $this->payload = ['contact' => ['name' => 'Test Buyer', 'company' => 'Test Shop', 'email' => 'buyer@example.com', 'phone' => '555-0100', 'agreement' => '1']];
    $this->url = route('marketing.shopify.v1.wholesale.application');
});

test('receipt means the tenant application form and notification outbox are saved and retries are deduplicated', function () {
    Notification::fake();
    $receipt = $this->postJson($this->url, $this->payload)->assertOk()->assertJsonPath('ok', true)->json('receipt');
    $this->postJson($this->url, $this->payload)->assertOk()->assertJsonPath('receipt', $receipt);
    expect(CustomerAccessRequest::count())->toBe(1)->and(FormSubmission::count())->toBe(1)->and(User::where('email', 'buyer@example.com')->exists())->toBeFalse();
    $application = CustomerAccessRequest::firstOrFail();
    expect($application->tenant_id)->toBe($this->tenant->id)->and(data_get($application->metadata, 'delivery.review.status'))->toBe('pending');
    $this->artisan('wholesale:deliver-applications')->assertSuccessful();
    $this->artisan('wholesale:deliver-applications')->assertSuccessful();
    Notification::assertSentOnDemandTimes(WholesaleApplicationReviewNotification::class, 1);
});

test('failed form persistence does not acknowledge or leave half an application', function () {
    $this->mock(TenantFormSubmissionService::class)->shouldReceive('recordWholesaleApplicationFromAccessRequest')->andThrow(new RuntimeException('database failure'));
    $this->postJson($this->url, $this->payload)->assertStatus(500);
    expect(CustomerAccessRequest::count())->toBe(0);
});

test('invalid and bot submissions do not create applications', function () {
    $this->postJson($this->url, ['contact' => ['email' => 'bad']])->assertUnprocessable();
    $this->payload['contact']['company_fax'] = 'bot';
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
    expect(CustomerAccessRequest::count())->toBe(0);
});

test('review email failure stays visible and scheduled retry can recover it', function () {
    $this->postJson($this->url, $this->payload)->assertOk();
    $id = CustomerAccessRequest::firstOrFail()->id;
    Notification::extend('mail', fn () => new class
    {
        public function send($notifiable, $notification)
        {
            throw new RuntimeException('mail unavailable');
        }
    });
    app(WholesaleApplicationDeliveryService::class)->deliver($id);
    expect(data_get(CustomerAccessRequest::find($id)->metadata, 'delivery.review.status'))->toBe('failed');
    Notification::fake();
    $this->travel(3)->minutes();
    $this->artisan('wholesale:deliver-applications')->assertSuccessful();
    expect(data_get(CustomerAccessRequest::find($id)->metadata, 'delivery.review.status'))->toBe('sent');
    Notification::assertSentOnDemandTimes(WholesaleApplicationReviewNotification::class, 1);
});

test('approving grants Shopify access without changing an existing user or adding tenant membership', function () {
    Notification::fake();
    $buyer = User::factory()->create(['email' => 'buyer@example.com', 'is_active' => false]);
    $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->postJson($this->url, $this->payload)->assertOk();
    $request = CustomerAccessRequest::firstOrFail();
    $this->mock(ShopifyWholesaleCustomerApprovalService::class)->shouldReceive('syncByEmail')->once()->andReturn(['customer_gid' => 'gid://shopify/Customer/123', 'customer_tags' => ['wholesale']]);
    $service = app(CustomerAccessApprovalService::class);
    $service->approve($request->id, $actor->id);
    $service->approve($request->id, $actor->id);
    expect($buyer->fresh()->is_active)->toBeFalse()->and($buyer->tenants()->count())->toBe(0);
    expect($request->fresh()->status)->toBe('approved');
    app(WholesaleApplicationDeliveryService::class)->deliver($request->id);
    Notification::assertSentOnDemandTimes(WholesaleApplicationDecisionNotification::class, 1);
});

test('Shopify failure leaves an application pending with no approval email', function () {
    Notification::fake();
    $this->postJson($this->url, $this->payload)->assertOk();
    $request = CustomerAccessRequest::firstOrFail();
    $actor = User::factory()->create(['role' => 'admin']);
    $this->mock(ShopifyWholesaleCustomerApprovalService::class)->shouldReceive('syncByEmail')->andThrow(new RuntimeException('unavailable'));
    expect(fn () => app(CustomerAccessApprovalService::class)->approve($request->id, $actor->id))->toThrow(DomainException::class);
    expect($request->fresh()->status)->toBe('pending')->and(data_get($request->fresh()->metadata, 'delivery.decision'))->toBeNull();
});

test('denial is idempotent emails once and cannot be overwritten by a repeated submission', function () {
    Notification::fake();
    $this->postJson($this->url, $this->payload)->assertOk();
    $request = CustomerAccessRequest::firstOrFail();
    $actor = User::factory()->create(['role' => 'admin']);
    $this->mock(ShopifyWholesaleCustomerApprovalService::class)->shouldNotReceive('syncByEmail');
    $service = app(CustomerAccessApprovalService::class);
    $service->reject($request->id, $actor->id, 'Internal note');
    $service->reject($request->id, $actor->id);
    $this->postJson($this->url, $this->payload)->assertUnprocessable();
    app(WholesaleApplicationDeliveryService::class)->deliver($request->id);
    app(WholesaleApplicationDeliveryService::class)->deliver($request->id);
    Notification::assertSentOnDemandTimes(WholesaleApplicationDecisionNotification::class, 1);
    expect(CustomerAccessRequest::count())->toBe(1)->and($request->fresh()->status)->toBe('rejected');
    $mail = (new WholesaleApplicationDecisionNotification($request->fresh()))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
    expect(implode(' ', $mail->introLines))->not->toContain('Internal note');
});
