<?php

use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSetupStatus;
use App\Models\TenantSupportTicket;
use App\Models\TenantSupportTicketMessage;
use App\Models\User;

beforeEach(function (): void {
    $host = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'localhost';
    config()->set('tenancy.landlord.primary_host', $host);
    config()->set('tenancy.landlord.hosts', [$host]);
    config()->set('tenancy.landlord.operator_roles', ['platform_admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

function landlordSearchTenant(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Blue Ridge Workshop',
        'slug' => 'blue-ridge-workshop',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => ['account_mode' => 'production'],
    ]);
    TenantSetupStatus::query()->create([
        'tenant_id' => $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'manual',
        'shopify_connection_status' => 'not_connected',
        'mobile_interest' => 'undecided',
        'plan_interest' => 'growth',
        'billing_lane_interest' => 'stripe_direct',
        'landlord_review_status' => 'waiting_on_everbranch',
    ]);

    $creator = User::factory()->tenantAdmin()->create();
    $creator->tenants()->attach($tenant->id, ['role' => 'admin']);
    $ticket = TenantSupportTicket::query()->create([
        'tenant_id' => $tenant->id,
        'created_by_user_id' => $creator->id,
        'subject' => 'Cannot invite a crew member',
        'category' => 'access',
        'priority' => 'high',
        'status' => 'open',
        'last_activity_at' => now(),
    ]);

    TenantSupportTicketMessage::query()->create([
        'tenant_support_ticket_id' => $ticket->id,
        'tenant_id' => $tenant->id,
        'user_id' => $creator->id,
        'author_context' => 'tenant',
        'body' => 'PrivateMessageNeedle must never be indexed by landlord search.',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'OperationalSecretNeedle',
        'last_name' => 'Customer',
        'email' => 'operational-secret@example.test',
    ]);
    Order::query()->create([
        'tenant_id' => $tenant->id,
        'order_number' => 'OperationalSecretNeedle-100',
        'order_label' => 'OperationalSecretNeedle order',
        'status' => 'paid',
        'total_price' => 10,
        'ordered_at' => now(),
    ]);

    return [$tenant, $ticket, $creator];
}

test('landlord search returns control plane workspaces tickets and phrase actions', function (): void {
    [$tenant, $ticket] = landlordSearchTenant();
    $operator = User::factory()->platformAdmin()->create();

    $workspaceResults = collect($this->actingAs($operator)
        ->getJson(route('landlord.search', ['q' => 'Blue Ridge']))
        ->assertOk()
        ->assertJsonPath('context', 'landlord')
        ->json('results'));
    expect($workspaceResults->pluck('type'))->toContain('workspace')
        ->and(data_get($workspaceResults->firstWhere('type', 'workspace'), 'meta.tenant_id'))->toBe($tenant->id);

    $ticketResults = collect($this->getJson(route('landlord.search', ['q' => 'crew member']))
        ->assertOk()
        ->json('results'));
    expect($ticketResults->pluck('type'))->toContain('ticket')
        ->and(data_get($ticketResults->firstWhere('type', 'ticket'), 'meta.ticket_id'))->toBe($ticket->id);

    foreach (['add a user', 'see requested branches'] as $phrase) {
        $results = collect($this->getJson(route('landlord.search', ['q' => $phrase]))->assertOk()->json('results'));
        expect($results->pluck('type'))->toContain('action');
    }
});

test('landlord search does not query tenant operational records or support message bodies', function (): void {
    landlordSearchTenant();
    $operator = User::factory()->platformAdmin()->create();

    foreach (['OperationalSecretNeedle', 'PrivateMessageNeedle'] as $needle) {
        $response = $this->actingAs($operator)
            ->getJson(route('landlord.search', ['q' => $needle]))
            ->assertOk();

        expect($response->json('total'))->toBe(0)
            ->and(collect($response->json('results'))->pluck('type')->all())
            ->not->toContain('customer', 'order', 'task', 'message', 'workflow', 'report');
    }
});

test('non operators cannot call landlord search directly', function (): void {
    [$tenant, , $tenantUser] = landlordSearchTenant();

    $this->actingAs($tenantUser)
        ->getJson(route('landlord.search', ['q' => $tenant->name]))
        ->assertForbidden();
});
