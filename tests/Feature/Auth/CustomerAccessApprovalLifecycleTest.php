<?php

use App\Livewire\Admin\Users\UsersIndex;
use App\Models\CustomerAccessRequest;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use App\Services\Onboarding\CustomerAccessApprovalService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    seedWholesaleShopifyStoreForApprovalLifecycleTest();

    Http::fake(function (Request $request) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');

        if (str_contains($query, 'FindWholesaleCustomerByEmail')) {
            return Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'UpsertWholesaleCustomer')) {
            return Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/999',
                            'legacyResourceId' => '999',
                            'email' => 'default-wholesale@example.com',
                            'firstName' => 'Default',
                            'lastName' => 'Wholesale',
                            'phone' => null,
                            'tags' => ['wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'AddWholesaleCustomerTag')) {
            return Http::response([
                'data' => [
                    'tagsAdd' => [
                        'node' => [
                            'id' => 'gid://shopify/Customer/999',
                            'legacyResourceId' => '999',
                            'email' => 'default-wholesale@example.com',
                            'firstName' => 'Default',
                            'lastName' => 'Wholesale',
                            'phone' => null,
                            'tags' => ['wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('Unexpected Shopify request in approval lifecycle default fake.');
    });
});

function seedWholesaleShopifyStoreForApprovalLifecycleTest(): void
{
    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'tenant_id' => null,
            'shop_domain' => 'wholesale-test.myshopify.com',
            'access_token' => 'wholesale-token',
            'scopes' => 'read_customers,write_customers',
            'installed_at' => now(),
        ]
    );
}

test('non-admin users cannot perform approve/reject/resend actions', function (): void {
    Notification::fake();

    $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
    $request = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_PLATFORM_ACCESS,
        'status' => 'pending',
        'name' => 'Unauthorized Manager',
        'email' => 'ops-unauth@example.com',
        'requested_tenant_slug' => 'acme',
    ]);

    $service = app(CustomerAccessApprovalService::class);

    expect(fn () => $service->approve((int) $request->id, (int) $manager->id))
        ->toThrow(DomainException::class);
    Notification::assertNothingSent();
});

test('approve action is idempotent and does not duplicate membership or emails', function (): void {
    Notification::fake();
    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Acme Ops',
        'email' => 'ops-approve@example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
    ]);

    $service = app(CustomerAccessApprovalService::class);

    $service->approve((int) $accessRequest->id, (int) $approver->id);
    $service->approve((int) $accessRequest->id, (int) $approver->id);

    $tenant = Tenant::query()->where('slug', 'acme')->first();
    expect($tenant)->not->toBeNull();

    $user = User::query()->where('email', 'ops-approve@example.com')->first();
    expect($user)->not->toBeNull();

    expect($user->tenants()->where('tenants.id', (int) $tenant->id)->count())->toBe(1);

    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);
});

test('reject action blocks later activation', function (): void {
    Notification::fake();
    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Acme Ops',
        'email' => 'ops-reject@example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
    ]);

    $service = app(CustomerAccessApprovalService::class);
    $service->reject((int) $accessRequest->id, (int) $approver->id, 'Not a fit.');

    expect(fn () => $service->approve((int) $accessRequest->id, (int) $approver->id))
        ->toThrow(DomainException::class);

    Notification::assertNothingSent();
});

test('resend activation uses tenant host and is throttled for repeated clicks', function (): void {
    Notification::fake();
    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Acme Ops',
        'email' => 'ops-resend@example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
    ]);

    $service = app(CustomerAccessApprovalService::class);
    $service->approve((int) $accessRequest->id, (int) $approver->id);

    $user = User::query()->where('email', 'ops-resend@example.com')->firstOrFail();
    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);

    $service->resendActivation((int) $accessRequest->id, (int) $approver->id);
    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);

    CustomerAccessRequest::query()->whereKey((int) $accessRequest->id)->update([
        'activation_email_last_sent_at' => now()->subMinutes(5),
    ]);

    $service->resendActivation((int) $accessRequest->id, (int) $approver->id);
    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 2);

    Notification::assertSentTo($user, ApprovalPasswordSetupNotification::class, function (ApprovalPasswordSetupNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        expect((string) $mail->actionUrl)->toContain('://acme.theeverbranch.com/');

        return true;
    });
});

test('admin surface routes through Livewire component for approval actions', function (): void {
    Notification::fake();
    seedWholesaleShopifyStoreForApprovalLifecycleTest();

    $shopifyRequests = [];
    Http::fake(function (Request $request) use (&$shopifyRequests) {
        $payload = json_decode($request->body(), true);
        $shopifyRequests[] = [
            'query' => (string) data_get($payload, 'query', ''),
            'variables' => data_get($payload, 'variables', []),
        ];

        $query = (string) data_get($payload, 'query', '');
        if (str_contains($query, 'FindWholesaleCustomerByEmail')) {
            return Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Customer/123',
                                    'legacyResourceId' => '123',
                                    'email' => 'ops-livewire@example.com',
                                    'firstName' => 'Ops',
                                    'lastName' => 'Livewire',
                                    'phone' => null,
                                    'tags' => ['vip'],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'UpsertWholesaleCustomer')) {
            return Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/123',
                            'legacyResourceId' => '123',
                            'email' => 'ops-livewire@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'Livewire',
                            'phone' => null,
                            'tags' => ['vip'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'AddWholesaleCustomerTag')) {
            return Http::response([
                'data' => [
                    'tagsAdd' => [
                        'node' => [
                            'id' => 'gid://shopify/Customer/123',
                            'legacyResourceId' => '123',
                            'email' => 'ops-livewire@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'Livewire',
                            'phone' => null,
                            'tags' => ['vip', 'wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('Unexpected Shopify request during approval test.');
    });

    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
    app(\App\Support\Tenancy\TenantContext::class)->set((int) $tenant->id);
    $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])->get(route('admin.users'))->assertOk();

    $user = User::factory()->create([
        'email' => 'ops-livewire@example.com',
        'role' => 'manager',
        'is_active' => false,
        'requested_via' => 'customer_production',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_PLATFORM_ACCESS,
        'status' => 'pending',
        'name' => $user->name,
        'email' => $user->email,
        'requested_tenant_slug' => 'acme',
        'tenant_id' => $tenant->id,
        'user_id' => (int) $user->id,
    ]);

    Livewire::actingAs($admin)->test(UsersIndex::class)
        ->call('approveRequest', (int) CustomerAccessRequest::query()->where('email', $user->email)->value('id'));

    $user->refresh();
    expect((bool) $user->is_active)->toBeTrue();
    expect($shopifyRequests)->toBeEmpty();
});

test('approval still completes when Shopify sync fails', function (): void {
    Notification::fake();
    seedWholesaleShopifyStoreForApprovalLifecycleTest();

    $shopifyRequests = [];
    Http::fake(function (Request $request) use (&$shopifyRequests) {
        $payload = json_decode($request->body(), true);
        $shopifyRequests[] = (string) data_get($payload, 'query', '');

        $query = (string) data_get($payload, 'query', '');
        if (str_contains($query, 'FindWholesaleCustomerByEmail')) {
            return Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Customer/456',
                                    'legacyResourceId' => '456',
                                    'email' => 'ops-failure@example.com',
                                    'firstName' => 'Ops',
                                    'lastName' => 'Failure',
                                    'phone' => null,
                                    'tags' => ['vip'],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'UpsertWholesaleCustomer')) {
            return Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => null,
                        'userErrors' => [
                            [
                                'field' => ['email'],
                                'message' => 'Customer does not exist',
                            ],
                        ],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('Unexpected Shopify request during approval failure test.');
    });

    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_WHOLESALE_APPLICATION,
        'status' => 'pending',
        'name' => 'Ops Failure',
        'email' => 'ops-failure@example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
    ]);

    $service = app(CustomerAccessApprovalService::class);
    $service->approve((int) $accessRequest->id, (int) $approver->id);

    $accessRequest->refresh();
    $user = User::query()->where('email', 'ops-failure@example.com')->firstOrFail();

    expect((string) $accessRequest->status)->toBe('approved')
        ->and((bool) $user->is_active)->toBeTrue()
        ->and($shopifyRequests)->toHaveCount(2);

    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);
});
