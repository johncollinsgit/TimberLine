<?php

use App\Models\CustomerAccessRequest;
use App\Models\ShopifyStore;
use App\Models\User;
use App\Notifications\WholesaleApplicationReviewNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);
});

function seedWholesaleShopifyStoreForStorefrontApplicationTests(): void
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

test('storefront wholesale application stores the applicant and notifies the review inbox', function (): void {
    seedWholesaleShopifyStoreForStorefrontApplicationTests();

    Notification::fake();
    $requests = [];

    Http::fake(function (Request $request) use (&$requests) {
        $payload = json_decode($request->body(), true);
        $query = (string) data_get($payload, 'query', '');
        $variables = (array) data_get($payload, 'variables', []);
        $requests[] = compact('query', 'variables');

        if (str_contains($query, 'FindWholesaleApplicationCustomerByEmail')) {
            return Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [],
                    ],
                ],
            ], 200);
        }

        if (str_contains($query, 'UpsertWholesaleApplicationCustomer')) {
            expect(data_get($variables, 'identifier.email'))->toBe('ops-review@example.com');

            return Http::response([
                'data' => [
                    'customerSet' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/456',
                            'legacyResourceId' => '456',
                            'email' => 'ops-review@example.com',
                            'firstName' => 'Ops',
                            'lastName' => 'Review',
                            'phone' => null,
                            'tags' => [],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200);
        }

        throw new \RuntimeException('Unexpected Shopify request during storefront wholesale application test.');
    });

    $this->withoutMiddleware()
        ->post(route('marketing.shopify.v1.wholesale.application'), [
            'intent' => 'production',
            'contact' => [
                'name' => 'Ops Review',
                'email' => 'ops-review@example.com',
                'phone' => '+1 555 555 1212',
                'company' => 'Review Shop',
                'store_type' => 'Gift Shop',
                'city' => 'Charleston',
                'state' => 'SC',
                'website' => 'https://review-shop.example.com',
                'position' => 'Owner',
                'referral' => 'Instagram',
                'business_info' => 'We love candles and tiny shelves.',
                'current_suppliers' => 'None',
                'address' => '123 Main St',
                'address2' => 'Suite 2',
                'zip' => '29401',
                'country' => 'United States',
                'retail_license_number' => 'RL-12345',
                'contact_preference' => 'Email',
                'agreement' => '1',
                'body' => "Wholesale Application\n---------------------\n\nContact\nName: Ops Review",
            ],
        ])
        ->assertNoContent();

    $requestRecord = CustomerAccessRequest::query()->firstOrFail();
    expect($requestRecord->intent)->toBe('production')
        ->and($requestRecord->status)->toBe('pending')
        ->and($requestRecord->email)->toBe('ops-review@example.com')
        ->and($requestRecord->name)->toBe('Ops Review')
        ->and((string) data_get($requestRecord->metadata, 'business_type'))->toBe('gift shop')
        ->and((string) data_get($requestRecord->metadata, 'phone'))->toBe('+1 555 555 1212')
        ->and((string) data_get($requestRecord->metadata, 'city'))->toBe('Charleston')
        ->and((string) data_get($requestRecord->metadata, 'agreement'))->toBe('1');

    $user = User::query()->where('email', 'ops-review@example.com')->first();
    expect($user)->not->toBeNull()
        ->and((bool) $user->is_active)->toBeFalse()
        ->and((string) $user->requested_via)->toBe('customer_production');

    Notification::assertSentOnDemand(WholesaleApplicationReviewNotification::class, function (
        WholesaleApplicationReviewNotification $notification,
        array $channels,
        \Illuminate\Notifications\AnonymousNotifiable $notifiable
    ): bool {
        $mailMessage = $notification->toMail($notifiable);
        $expectedUrl = route('admin.users', ['search' => 'ops-review@example.com']);

        expect($channels)->toContain('mail')
            ->and($notifiable->routes['mail'] ?? null)->toBe('modernforestryteam@gmail.com')
            ->and((string) $mailMessage->actionUrl)->toBe($expectedUrl)
            ->and(implode(' ', $mailMessage->introLines))->toContain('ops-review@example.com');

        return true;
    });

    expect($requests)->toHaveCount(2);
});
