<?php

use App\Models\CustomerAccessRequest;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantForm;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();
});

function seedWholesaleShopifyStoreForWholesaleInboxTests(): void
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

test('admin can browse the wholesale application inbox', function (): void {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry Wholesale',
        'slug' => 'modern-forestry-wholesale',
    ]);

    $template = FormTemplate::query()->create([
        'key' => 'wholesale_application',
        'name' => 'Wholesale Application',
        'status' => 'active',
        'visibility' => 'internal',
        'handler_key' => 'wholesale_application',
    ]);

    $form = TenantForm::query()->create([
        'tenant_id' => (int) $tenant->id,
        'form_template_id' => (int) $template->id,
        'slug' => 'wholesale-application',
        'name' => 'Wholesale Application',
        'status' => 'active',
        'channel' => 'wholesale_storefront',
    ]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Jane Buyer',
        'email' => 'jane@example.com',
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
        'submitter_email' => 'jane@example.com',
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
        'normalized_payload' => [
            'phone' => '+1 555 111 2222',
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.wholesale.applications'))
        ->assertOk()
        ->assertSeeText('Review applications in one place')
        ->assertSee('Jane Buyer')
        ->assertSee('Jane Shop');

    $this->actingAs($admin)
        ->get(route('admin.wholesale.applications.show', $accessRequest))
        ->assertOk()
        ->assertSeeText('Application details')
        ->assertSee('jane@example.com')
        ->assertSee('Greenville')
        ->assertSeeText('Open approval workspace');
});

test('admin can approve wholesale application directly from the inbox detail page', function (): void {
    Notification::fake();
    seedWholesaleShopifyStoreForWholesaleInboxTests();

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

        throw new RuntimeException('Unexpected Shopify request during wholesale inbox approval test.');
    });

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry Wholesale',
        'slug' => 'modern-forestry-wholesale',
    ]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Approve Me',
        'email' => 'approve-me@example.com',
        'company' => 'Approval Shop',
        'requested_tenant_slug' => 'modern-forestry-wholesale',
        'tenant_id' => (int) $tenant->id,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.wholesale.applications.approve', $accessRequest), [
            'decision_note' => 'Looks good.',
        ])
        ->assertRedirect(route('admin.wholesale.applications.show', $accessRequest));

    $accessRequest->refresh();
    $user = User::query()->where('email', 'approve-me@example.com')->firstOrFail();

    expect((string) $accessRequest->status)->toBe('approved')
        ->and((string) ($accessRequest->decision_note ?? ''))->toBe('Looks good.')
        ->and((bool) $user->is_active)->toBeTrue();

    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);
});

test('admin can reject wholesale application directly from the inbox detail page', function (): void {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry Wholesale',
        'slug' => 'modern-forestry-wholesale',
    ]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Reject Me',
        'email' => 'reject-me@example.com',
        'company' => 'Rejected Shop',
        'requested_tenant_slug' => 'modern-forestry-wholesale',
        'tenant_id' => (int) $tenant->id,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.wholesale.applications.reject', $accessRequest), [
            'rejection_note' => 'Not the right fit.',
        ])
        ->assertRedirect(route('admin.wholesale.applications.show', $accessRequest));

    $accessRequest->refresh();

    expect((string) $accessRequest->status)->toBe('rejected')
        ->and((string) ($accessRequest->rejection_note ?? ''))->toBe('Not the right fit.');
});
