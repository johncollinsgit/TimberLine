<?php

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\AutomationWorkflowEngine;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\WorkflowProductService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function commerceExecutionTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => str($slug)->headline(), 'slug' => $slug]);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);

    return $tenant;
}

function commerceExecutionWorkflow(Tenant $tenant, string $template): AutomationWorkflow
{
    return AutomationWorkflow::query()->create([
        'tenant_id' => $tenant->id,
        'template_key' => $template,
        'name' => str($template)->headline(),
        'status' => AutomationWorkflow::STATUS_ACTIVE,
        'draft_definition' => [],
        'test_state' => [],
    ]);
}

test('shopify orders create configurable calendar events and replay without duplicates', function (): void {
    $tenant = commerceExecutionTenant('shopify-commerce-execution');
    $workflow = commerceExecutionWorkflow($tenant, 'shopify_order_to_google_calendar');
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'shopify',
        'external_account_id' => 'shopify-account-hash',
        'external_account_secret' => 'harbor-bakery.myshopify.com',
        'external_account_label' => 'Harbor Bakery',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'shopify-access-token',
        'metadata' => ['shop_domain' => 'harbor-bakery.myshopify.com'],
        'connected_at' => now(),
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'harbor-bakery.myshopify.com/admin/api/2026-07/graphql.json')) {
            return Http::response(['data' => ['orders' => [
                'edges' => [['cursor' => 'cursor-1', 'node' => [
                    'id' => 'gid://shopify/Order/9001',
                    'legacyResourceId' => '9001',
                    'name' => '#1042',
                    'createdAt' => '2026-07-18T14:00:00Z',
                    'updatedAt' => '2026-07-18T15:00:00Z',
                    'cancelledAt' => null,
                    'displayFinancialStatus' => 'PAID',
                    'displayFulfillmentStatus' => 'UNFULFILLED',
                    'email' => 'jamie@example.com',
                    'phone' => null,
                    'note' => 'Side door',
                    'currentTotalPriceSet' => ['shopMoney' => ['amount' => '84.00', 'currencyCode' => 'USD']],
                    'customer' => ['displayName' => 'Jamie Lee'],
                    'shippingAddress' => ['name' => 'Jamie Lee', 'address1' => '128 Evergreen Way', 'city' => 'Asheville', 'provinceCode' => 'NC', 'zip' => '28801', 'countryCodeV2' => 'US'],
                    'billingAddress' => null,
                    'lineItems' => ['nodes' => [['name' => 'Cedar Candle', 'quantity' => 2, 'sku' => 'CEDAR']]],
                    'customAttributes' => [],
                    'fulfillments' => [['createdAt' => '2026-07-21T13:30:00Z', 'estimatedDeliveryAt' => null, 'deliveredAt' => null, 'displayStatus' => 'PENDING']],
                ]]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]);
        }
        if ($request->method() === 'POST' && str_contains($request->url(), 'googleapis.com/calendar/v3/calendars/operations/events')) {
            return Http::response(['id' => 'google-event-1042']);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    $definition = [
        'enabled' => true,
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'required_module' => 'workflow_automations',
        'driver' => 'commerce_order_google_calendar',
        'trigger' => [
            'provider' => 'shopify',
            'connection_id' => $connection->id,
            'schedule_source' => 'fulfillment',
            'bootstrap_lookback_days' => 14,
            'modified_overlap_minutes' => 5,
        ],
        'action' => [
            'provider' => 'google_calendar',
            'calendar_id' => 'operations',
            'timezone' => 'America/New_York',
            'event_time_mode' => 'fixed_time',
            'default_start_time' => '09:15',
            'default_duration_minutes' => 90,
            'schedule_offset_days' => -1,
            'presentation' => [
                'title_template' => '{{source}} #{{order_number}} — {{customer_name}}',
                'description_fields' => ['items', 'total', 'status'],
                'location_source' => 'shipping_address',
                'color_id' => '10',
                'availability' => 'busy',
                'visibility' => 'private',
                'reminders' => 'none',
                'cancelled_order_behavior' => 'mark_cancelled',
            ],
        ],
        'credentials' => ['google_calendar_access_token' => 'google-access-token'],
    ];

    $first = app(AutomationWorkflowEngine::class)->runDefinition('workflow:'.$workflow->id, $definition);
    $second = app(AutomationWorkflowEngine::class)->runDefinition('workflow:'.$workflow->id, $definition);

    expect($first['ok'])->toBeTrue()
        ->and(data_get($first, 'counts.created'))->toBe(1)
        ->and(data_get($second, 'counts.unchanged'))->toBe(1)
        ->and(AutomationWorkflowLink::query()->where('automation_workflow_id', $workflow->id)->count())->toBe(1)
        ->and(AutomationWorkflowLink::query()->where('automation_workflow_id', $workflow->id)->value('destination_id'))->toBe('google-event-1042')
        ->and(AutomationWorkflowState::query()->where('automation_workflow_id', $workflow->id)->value('cursor'))->toContain('2026-07-18T15:00:00');

    Http::assertSentCount(3);
    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), 'googleapis.com/calendar')) {
            return false;
        }

        return $request['summary'] === 'Shopify #1042 — Jamie Lee'
            && $request['start']['dateTime'] === '2026-07-20T09:15:00-04:00'
            && $request['end']['dateTime'] === '2026-07-20T10:45:00-04:00'
            && $request['location'] === 'Jamie Lee, 128 Evergreen Way, Asheville, NC, 28801, US'
            && $request['colorId'] === '10'
            && $request['visibility'] === 'private'
            && $request['reminders']['useDefault'] === false;
    });
});

test('square refreshes access selects locations and creates an all day pickup event', function (): void {
    $tenant = commerceExecutionTenant('square-commerce-execution');
    $workflow = commerceExecutionWorkflow($tenant, 'square_order_to_google_calendar');
    config()->set('services.square.oauth_client_id', 'square-client');
    config()->set('services.square.oauth_client_secret', 'square-secret');
    config()->set('services.square.token_url', 'https://connect.squareup.com/oauth2/token');
    config()->set('services.square.api_base', 'https://connect.squareup.com');
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'square',
        'external_account_id' => 'square-account-hash',
        'external_account_secret' => 'merchant-55',
        'external_account_label' => 'Town Market',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'expired-square-token',
        'refresh_token' => 'square-refresh-token',
        'expires_at' => now()->subMinute(),
        'metadata' => ['locations' => [
            ['id' => 'location-a', 'label' => 'Uptown', 'status' => 'ACTIVE', 'address' => '1 North St'],
            ['id' => 'location-b', 'label' => 'Downtown', 'status' => 'ACTIVE', 'address' => '12 Main St'],
        ]],
        'connected_at' => now(),
    ]);

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://connect.squareup.com/oauth2/token') {
            return Http::response(['access_token' => 'fresh-square-token', 'refresh_token' => 'fresh-square-refresh', 'expires_at' => now()->addDays(30)->toIso8601String()]);
        }
        if ($request->url() === 'https://connect.squareup.com/v2/orders/search') {
            return Http::response(['orders' => [[
                'id' => 'square-order-77',
                'reference_id' => 'A-77',
                'location_id' => 'location-b',
                'state' => 'OPEN',
                'created_at' => '2026-07-18T15:00:00Z',
                'updated_at' => '2026-07-18T16:00:00Z',
                'line_items' => [['name' => 'Celebration cake', 'quantity' => '1']],
                'total_money' => ['amount' => 6500, 'currency' => 'USD'],
                'fulfillments' => [[
                    'state' => 'PROPOSED',
                    'pickup_details' => [
                        'pickup_at' => '2026-07-22T17:00:00Z',
                        'ready_at' => '2026-07-22T16:30:00Z',
                        'recipient' => ['display_name' => 'Morgan Reed', 'email_address' => 'morgan@example.com'],
                    ],
                ]],
            ]]]);
        }
        if ($request->method() === 'POST' && str_contains($request->url(), 'googleapis.com/calendar/v3')) {
            return Http::response(['id' => 'square-calendar-event']);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    $result = app(AutomationWorkflowEngine::class)->runDefinition('workflow:'.$workflow->id, [
        'enabled' => true,
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'required_module' => 'workflow_automations',
        'driver' => 'commerce_order_google_calendar',
        'trigger' => [
            'provider' => 'square',
            'connection_id' => $connection->id,
            'location_ids' => ['location-b'],
            'schedule_source' => 'pickup',
        ],
        'action' => [
            'provider' => 'google_calendar',
            'calendar_id' => 'pickup-calendar',
            'timezone' => 'America/Chicago',
            'event_time_mode' => 'all_day',
            'schedule_offset_days' => 1,
            'presentation' => [
                'title_template' => '{{source}} #{{order_number}}',
                'description_fields' => ['items', 'total'],
                'location_source' => 'pickup_location',
                'availability' => 'free',
                'visibility' => 'default',
                'reminders' => 'default',
                'cancelled_order_behavior' => 'mark_cancelled',
            ],
        ],
        'credentials' => ['google_calendar_access_token' => 'google-access-token'],
    ]);

    expect($result['ok'])->toBeTrue()
        ->and(data_get($result, 'counts.created'))->toBe(1)
        ->and($connection->fresh()->access_token)->toBe('fresh-square-token')
        ->and($connection->fresh()->refresh_token)->toBe('fresh-square-refresh');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://connect.squareup.com/v2/orders/search'
        && $request->hasHeader('Square-Version', '2026-05-20')
        && $request->hasHeader('Authorization', 'Bearer fresh-square-token')
        && $request['location_ids'] === ['location-b']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com/calendar/v3')
        && $request['summary'] === 'Square #A-77'
        && $request['start']['date'] === '2026-07-23'
        && $request['end']['date'] === '2026-07-24'
        && $request['location'] === 'Downtown, 12 Main St'
        && $request['transparency'] === 'transparent');
});

test('commerce draft settings reject connections and locations outside the tenant account', function (): void {
    $tenant = commerceExecutionTenant('commerce-draft-owner');
    $otherTenant = commerceExecutionTenant('commerce-draft-forged');
    $user = User::factory()->create(['role' => 'marketing_manager', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'marketing_manager']);
    config()->set('automation_workflows.templates.square_order_to_google_calendar.launchable', true);
    $workflow = app(WorkflowProductService::class)->create($tenant->id, 'square_order_to_google_calendar', $user);
    $foreignConnection = IntegrationConnection::query()->create([
        'tenant_id' => $otherTenant->id,
        'provider' => 'square',
        'external_account_id' => 'foreign-square-account',
        'external_account_label' => 'Other merchant',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'foreign-token',
        'metadata' => ['locations' => [['id' => 'foreign-location', 'label' => 'Other store', 'status' => 'ACTIVE']]],
        'connected_at' => now(),
    ]);

    expect(fn () => app(WorkflowProductService::class)->updateDraft($workflow, [
        'trigger_connection_id' => $foreignConnection->id,
        'location_ids' => ['foreign-location'],
    ], $user))->toThrow(AutomationWorkflowException::class, 'selected Square connection is unavailable');

    $ownedConnection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'square',
        'external_account_id' => 'owned-square-account',
        'external_account_label' => 'My merchant',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'owned-token',
        'metadata' => ['locations' => [['id' => 'owned-location', 'label' => 'My store', 'status' => 'ACTIVE']]],
        'connected_at' => now(),
    ]);

    expect(fn () => app(WorkflowProductService::class)->updateDraft($workflow, [
        'trigger_connection_id' => $ownedConnection->id,
        'location_ids' => ['foreign-location'],
    ], $user))->toThrow(AutomationWorkflowException::class, 'Choose only active locations');

    $workflow = app(WorkflowProductService::class)->updateDraft($workflow, [
        'trigger_connection_id' => $ownedConnection->id,
        'location_ids' => ['owned-location'],
    ], $user);
    expect(data_get($workflow->draft_definition, 'trigger.location_ids'))->toBe(['owned-location']);

    $workflow = app(WorkflowProductService::class)->updateDraft($workflow, [
        'trigger_connection_id' => $ownedConnection->id,
        'location_ids' => [],
    ], $user);
    expect(data_get($workflow->draft_definition, 'trigger.location_ids'))->toBe([]);

    IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'google_calendar',
        'external_account_id' => 'calendar-account',
        'external_account_label' => 'Operations calendar account',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'calendar-token-that-must-not-render',
        'metadata' => ['calendars' => [['id' => 'operations', 'summary' => 'Operations', 'primary' => true]]],
        'connected_at' => now(),
    ]);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.show', $workflow))
        ->assertOk()
        ->assertSeeText('Event timing')
        ->assertSeeText('Locations')
        ->assertSeeText('My store')
        ->assertSeeText('Cancelled orders')
        ->assertDontSee('owned-token')
        ->assertDontSee('calendar-token-that-must-not-render');
});

test('squarespace orders rotate access and create configurable delivery events', function (): void {
    $tenant = commerceExecutionTenant('squarespace-commerce-execution');
    $workflow = commerceExecutionWorkflow($tenant, 'squarespace_order_to_google_calendar');
    config()->set('services.squarespace.oauth_client_id', 'squarespace-client');
    config()->set('services.squarespace.oauth_client_secret', 'squarespace-secret');
    config()->set('services.squarespace.token_url', 'https://login.squarespace.com/api/1/login/oauth/provider/tokens');
    config()->set('services.squarespace.api_base', 'https://api.squarespace.com');
    config()->set('services.squarespace.user_agent', 'Everbranch Order Calendar/1.0');
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'squarespace',
        'external_account_id' => 'squarespace-account-hash',
        'external_account_secret' => 'website-22',
        'external_account_label' => 'Harbor Squarespace',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'expired-squarespace-access',
        'refresh_token' => 'squarespace-refresh-1',
        'expires_at' => now()->subMinute(),
        'metadata' => ['site_url' => 'https://harbor.example'],
        'connected_at' => now(),
    ]);

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://login.squarespace.com/api/1/login/oauth/provider/tokens') {
            return Http::response([
                'access_token' => 'squarespace-access-2',
                'refresh_token' => 'squarespace-refresh-2',
                'access_token_expires_at' => (string) now()->addMinutes(30)->getTimestamp(),
            ]);
        }
        if (str_starts_with($request->url(), 'https://api.squarespace.com/1.0/commerce/orders')) {
            return Http::response(['pagination' => ['hasNextPage' => false], 'result' => [[
                'id' => 'ss-order-12', 'orderNumber' => '1204',
                'createdOn' => '2026-07-18T14:00:00Z', 'modifiedOn' => '2026-07-18T16:00:00Z',
                'paymentState' => 'PAID', 'fulfillmentStatus' => 'PENDING',
                'customerEmail' => 'casey@example.com',
                'shippingAddress' => ['firstName' => 'Casey', 'lastName' => 'Morgan', 'address1' => '44 Bay St', 'city' => 'Portland', 'state' => 'ME', 'postalCode' => '04101', 'countryCode' => 'US'],
                'lineItems' => [['productName' => 'Celebration box', 'quantity' => 1]],
                'grandTotal' => ['currency' => 'USD', 'value' => 72.50],
                'formSubmission' => [['label' => 'Requested delivery date', 'value' => '2026-07-24T15:00:00Z']],
            ]]]);
        }
        if ($request->method() === 'POST' && str_contains($request->url(), 'googleapis.com/calendar/v3')) {
            return Http::response(['id' => 'squarespace-calendar-event']);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    $result = app(AutomationWorkflowEngine::class)->runDefinition('workflow:'.$workflow->id, [
        'enabled' => true,
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'required_module' => 'workflow_automations',
        'driver' => 'commerce_order_google_calendar',
        'trigger' => ['provider' => 'squarespace', 'connection_id' => $connection->id, 'schedule_source' => 'delivery'],
        'action' => [
            'calendar_id' => 'delivery-calendar', 'timezone' => 'America/New_York',
            'event_time_mode' => 'source_time', 'default_duration_minutes' => 45,
            'presentation' => [
                'title_template' => '{{source}} #{{order_number}} — {{customer_name}}',
                'description_fields' => ['items', 'total'], 'location_source' => 'shipping_address',
                'availability' => 'busy', 'visibility' => 'default', 'reminders' => 'default',
                'cancelled_order_behavior' => 'mark_cancelled',
            ],
        ],
        'credentials' => ['google_calendar_access_token' => 'google-access-token'],
    ]);

    expect($result['ok'])->toBeTrue()
        ->and(data_get($result, 'counts.created'))->toBe(1)
        ->and($connection->fresh()->access_token)->toBe('squarespace-access-2')
        ->and($connection->fresh()->refresh_token)->toBe('squarespace-refresh-2');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.squarespace.com/1.0/commerce/orders')
        && $request->hasHeader('Authorization', 'Bearer squarespace-access-2')
        && $request->hasHeader('User-Agent', 'Everbranch Order Calendar/1.0'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com/calendar/v3')
        && $request['summary'] === 'Squarespace #1204 — Casey Morgan'
        && $request['start']['dateTime'] === '2026-07-24T11:00:00-04:00'
        && $request['location'] === 'Casey Morgan, 44 Bay St, Portland, ME, 04101, US');
});

test('wix orders use app instance access and create all day pickup events', function (): void {
    $tenant = commerceExecutionTenant('wix-commerce-execution');
    $workflow = commerceExecutionWorkflow($tenant, 'wix_order_to_google_calendar');
    config()->set('services.wix.app_id', 'wix-app');
    config()->set('services.wix.client_secret', 'wix-secret');
    config()->set('services.wix.token_url', 'https://www.wixapis.com/oauth2/token');
    config()->set('services.wix.api_base', 'https://www.wixapis.com');
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'wix',
        'external_account_id' => 'wix-account-hash',
        'external_account_secret' => 'wix-instance-88',
        'external_account_label' => 'Harbor Wix',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'expired-wix-access',
        'expires_at' => now()->subMinute(),
        'metadata' => ['site_url' => 'https://harbor-wix.example'],
        'connected_at' => now(),
    ]);

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://www.wixapis.com/oauth2/token') {
            return Http::response(['access_token' => 'wix-access-2', 'expires_in' => 14400]);
        }
        if ($request->url() === 'https://www.wixapis.com/ecom/v1/orders/search') {
            return Http::response(['orders' => [[
                'id' => 'wix-order-99', 'number' => '99', 'status' => 'APPROVED',
                'paymentStatus' => 'PAID', 'fulfillmentStatus' => 'NOT_FULFILLED',
                'createdDate' => '2026-07-18T14:00:00Z', 'updatedDate' => '2026-07-18T17:00:00Z',
                'buyerInfo' => ['email' => 'riley@example.com'],
                'lineItems' => [['quantity' => 2, 'productName' => ['original' => 'Picnic box']]],
                'currency' => 'USD', 'priceSummary' => ['total' => ['amount' => '96.00']],
                'businessLocation' => ['name' => 'Market counter'],
                'shippingInfo' => ['title' => 'Store pickup', 'logistics' => [
                    'deliveryTime' => '2026-07-27T16:00:00Z',
                    'shippingDestination' => [
                        'contactDetails' => ['firstName' => 'Riley', 'lastName' => 'Chen', 'phone' => '555-0100'],
                        'address' => ['addressLine' => '8 Market St', 'city' => 'Boston', 'subdivision' => 'MA', 'postalCode' => '02108', 'country' => 'US'],
                    ],
                ]],
            ]], 'metadata' => ['cursors' => ['next' => null]]]);
        }
        if ($request->method() === 'POST' && str_contains($request->url(), 'googleapis.com/calendar/v3')) {
            return Http::response(['id' => 'wix-calendar-event']);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    $result = app(AutomationWorkflowEngine::class)->runDefinition('workflow:'.$workflow->id, [
        'enabled' => true,
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'required_module' => 'workflow_automations',
        'driver' => 'commerce_order_google_calendar',
        'trigger' => ['provider' => 'wix', 'connection_id' => $connection->id, 'schedule_source' => 'pickup'],
        'action' => [
            'calendar_id' => 'pickup-calendar', 'timezone' => 'America/New_York', 'event_time_mode' => 'all_day',
            'presentation' => [
                'title_template' => '{{source}} #{{order_number}}',
                'description_fields' => ['items', 'total'], 'location_source' => 'pickup_location',
                'availability' => 'free', 'visibility' => 'default', 'reminders' => 'default',
                'cancelled_order_behavior' => 'mark_cancelled',
            ],
        ],
        'credentials' => ['google_calendar_access_token' => 'google-access-token'],
    ]);

    expect($result['ok'])->toBeTrue()
        ->and(data_get($result, 'counts.created'))->toBe(1)
        ->and($connection->fresh()->access_token)->toBe('wix-access-2');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.wixapis.com/ecom/v1/orders/search'
        && $request->hasHeader('Authorization', 'Bearer wix-access-2')
        && data_get($request->data(), 'search.filter.updatedDate.$gte') !== null);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com/calendar/v3')
        && $request['summary'] === 'Wix #99'
        && $request['start']['date'] === '2026-07-27'
        && $request['end']['date'] === '2026-07-28'
        && str_contains((string) $request['location'], 'Market counter'));
});
