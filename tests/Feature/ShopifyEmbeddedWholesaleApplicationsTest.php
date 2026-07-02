<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CustomerAccessRequest;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Tenant;
use App\Models\TenantForm;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();
    configureEmbeddedWholesaleStore();
});

function seedEmbeddedWholesaleApplication(string $email = 'jane@example.com'): CustomerAccessRequest
{
    $tenant = Tenant::query()->firstOrCreate([
        'slug' => 'modern-forestry-wholesale',
    ], [
        'name' => 'Modern Forestry Wholesale',
    ]);

    $template = FormTemplate::query()->firstOrCreate([
        'key' => 'wholesale_application',
    ], [
        'name' => 'Wholesale Application',
        'status' => 'active',
        'visibility' => 'internal',
        'handler_key' => 'wholesale_application',
    ]);

    $form = TenantForm::query()->firstOrCreate([
        'tenant_id' => (int) $tenant->id,
        'slug' => 'wholesale-application',
    ], [
        'form_template_id' => (int) $template->id,
        'name' => 'Wholesale Application',
        'status' => 'active',
        'channel' => 'wholesale_storefront',
    ]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Jane Buyer',
        'email' => $email,
        'company' => 'Jane Shop',
        'requested_tenant_slug' => 'modern-forestry-wholesale',
        'tenant_id' => (int) $tenant->id,
        'message' => 'Interested in carrying the line.',
        'metadata' => [
            'phone' => '+1 555 111 2222',
            'city' => 'Greenville',
            'state' => 'SC',
            'website' => 'https://jane-shop.example.com',
            'agreement' => true,
        ],
    ]);

    FormSubmission::query()->create([
        'tenant_id' => (int) $tenant->id,
        'tenant_form_id' => (int) $form->id,
        'customer_access_request_id' => (int) $accessRequest->id,
        'status' => 'submitted',
        'source' => 'wholesale_storefront',
        'source_key' => 'customer_access_request:' . (int) $accessRequest->id,
        'submitted_at' => now(),
        'submitter_name' => 'Jane Buyer',
        'submitter_email' => $email,
        'submitter_company' => 'Jane Shop',
        'payload' => [
            'phone' => '+1 555 111 2222',
            'company' => 'Jane Shop',
            'website' => 'https://jane-shop.example.com',
            'address' => '12 Main Street',
            'city' => 'Greenville',
            'state' => 'SC',
            'zip' => '29601',
            'country' => 'United States',
            'agreement' => true,
        ],
    ]);

    return $accessRequest;
}

test('shopify embedded wholesale app home renders the wholesale applications inbox', function (): void {
    seedEmbeddedWholesaleApplication();

    $response = $this->get(route('shopify.app.wholesale'));

    $response->assertOk()
        ->assertSeeText('Wholesale Applications')
        ->assertSeeText('Applications')
        ->assertSeeText('Review applications in one place')
        ->assertSeeText('Jane Buyer')
        ->assertDontSeeText('Fast loyalty snapshot for recent program activity.')
        ->assertDontSeeText('AI Assistant')
        ->assertDontSeeText('Customers')
        ->assertDontSeeText('Messages')
        ->assertDontSeeText('Rewards')
        ->assertDontSeeText('Edit App')
        ->assertDontSeeText('Settings');
});

test('shopify embedded wholesale app detail renders captured application fields', function (): void {
    $accessRequest = seedEmbeddedWholesaleApplication();

    $response = $this->get(route('shopify.app.wholesale.applications.show', [
        'accessRequest' => $accessRequest,
        'store_key' => 'wholesale',
    ]));

    $response->assertOk()
        ->assertSeeText('Wholesale Application Review')
        ->assertSeeText('Application summary')
        ->assertSeeText('Business overview')
        ->assertSeeText('Store location')
        ->assertSeeText('Compliance')
        ->assertSeeText('System record')
        ->assertSeeText('Greenville')
        ->assertSeeText('Jane Shop')
        ->assertSeeText('Interested in carrying the line.');
});

test('shopify embedded wholesale app uses embedded app client id when configured', function (): void {
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-admin-client-id');
    config()->set('services.shopify.stores.wholesale.embedded_client_id', 'wholesale-embedded-client-id');
    config()->set('services.shopify.stores.wholesale.embedded_client_secret', 'wholesale-embedded-client-secret');

    seedEmbeddedWholesaleApplication();

    $response = $this->get(route('shopify.app.wholesale', [
        'store_key' => 'wholesale',
    ]));

    $response->assertOk()
        ->assertSee('<meta name="shopify-api-key" content="wholesale-embedded-client-id">', false);
});

test('shopify embedded wholesale app detail renders approval controls and client-side identity bootstrap placeholders', function (): void {
    $accessRequest = seedEmbeddedWholesaleApplication();

    $response = $this->get(route('shopify.app.wholesale.applications.show', array_merge([
        'accessRequest' => $accessRequest,
        'store_key' => 'wholesale',
    ], wholesaleEmbeddedSignedQuery())));

    $response->assertOk()
        ->assertSeeText('Finishing Shopify admin verification')
        ->assertSeeText('Approve application')
        ->assertSee('shopify_session_token', false);
});

test('shopify embedded wholesale app can approve through a mapped shopify admin identity', function (): void {
    Notification::fake();
    Http::fake(function (HttpRequest $request) {
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
                            'id' => 'gid://shopify/Customer/789',
                            'legacyResourceId' => '789',
                            'email' => 'approve-me@example.com',
                            'firstName' => 'Approve',
                            'lastName' => 'Me',
                            'phone' => null,
                            'tags' => [],
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
                            'id' => 'gid://shopify/Customer/789',
                            'legacyResourceId' => '789',
                            'email' => 'approve-me@example.com',
                            'firstName' => 'Approve',
                            'lastName' => 'Me',
                            'phone' => null,
                            'tags' => ['wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new RuntimeException('Unexpected Shopify request during embedded wholesale approval test.');
    });

    $accessRequest = seedEmbeddedWholesaleApplication('approve-me@example.com');
    $actor = User::factory()->create([
        'email' => 'ops-review@example.com',
        'role' => 'admin',
        'is_active' => true,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken([
            'email' => 'ops-review@example.com',
        ]),
        'Accept' => 'application/json',
    ])->post(route('shopify.app.wholesale.applications.approve', [
        'accessRequest' => $accessRequest,
        'store_key' => 'wholesale',
    ]), [
        'decision_note' => 'Looks good.',
    ]);

    $response->assertOk()
        ->assertJsonPath('ok', true);

    $accessRequest->refresh();
    $user = User::query()->where('email', 'approve-me@example.com')->firstOrFail();

    expect((string) $accessRequest->status)->toBe('approved')
        ->and((string) ($accessRequest->decision_note ?? ''))->toBe('Looks good.')
        ->and((bool) $user->is_active)->toBeTrue();

    Http::assertSent(function (HttpRequest $request): bool {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');

        return str_contains($query, 'AddWholesaleCustomerTag');
    });

    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);
    expect($actor->email)->toBe('ops-review@example.com');
});

test('shopify embedded wholesale app auto provisions the current shopify admin as a wholesale operator for approval', function (): void {
    Notification::fake();
    Http::fake(function (HttpRequest $request) {
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
                            'id' => 'gid://shopify/Customer/901',
                            'legacyResourceId' => '901',
                            'email' => 'blocked@example.com',
                            'firstName' => 'Blocked',
                            'lastName' => 'Example',
                            'phone' => null,
                            'tags' => [],
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
                            'id' => 'gid://shopify/Customer/901',
                            'legacyResourceId' => '901',
                            'email' => 'blocked@example.com',
                            'firstName' => 'Blocked',
                            'lastName' => 'Example',
                            'phone' => null,
                            'tags' => ['wholesale'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new RuntimeException('Unexpected Shopify request during embedded wholesale operator auto-provision test.');
    });

    $accessRequest = seedEmbeddedWholesaleApplication('blocked@example.com');

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken([
            'email' => 'missing-operator@example.com',
        ]),
        'Accept' => 'application/json',
    ])->post(route('shopify.app.wholesale.applications.approve', [
        'accessRequest' => $accessRequest,
        'store_key' => 'wholesale',
    ]), [
        'decision_note' => 'Looks good.',
    ]);

    $response->assertOk()
        ->assertJsonPath('ok', true);

    $accessRequest->refresh();
    $actor = User::query()->where('email', 'missing-operator@example.com')->first();

    $applicant = User::query()->where('email', 'blocked@example.com')->firstOrFail();

    expect($actor)->not->toBeNull()
        ->and((string) $actor->role)->toBe('admin')
        ->and((bool) $actor->is_active)->toBeTrue()
        ->and((string) $accessRequest->status)->toBe('approved');

    Notification::assertSentToTimes($applicant, ApprovalPasswordSetupNotification::class, 1);
});
