<?php

use App\Models\AiBranchAccount;
use App\Models\FieldServiceFinancialDocument;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('field invoice preview is tenant scoped, read only, and never invokes a real provider', function (): void {
    Http::preventStrayRequests();

    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    Tenant::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $imported = FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'quickbooks',
        'document_type' => 'invoice',
        'external_id' => 'quickbooks:invoice:existing-123',
        'document_number' => 'QB-123',
        'status' => 'open',
        'total_amount' => 125.00,
        'balance' => 125.00,
    ]);

    $response = $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/invoices/preview', [
        'customer_name' => 'Example Customer',
        'customer_email' => 'customer@example.test',
        'invoice_provider' => 'everbranch_pay',
        'line_items' => [
            ['description' => 'Service call', 'quantity' => 2, 'unit_amount_cents' => 12500],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('preview.issued', false)
        ->assertJsonPath('preview.persisted', false)
        ->assertJsonPath('preview.invoice.number', 'PREVIEW')
        ->assertJsonPath('preview.invoice.total_cents', 25000)
        ->assertJsonPath('preview.tax.status', 'not_calculated')
        ->assertJsonPath('preview.invoice_provider.key', 'everbranch_pay')
        ->assertJsonPath('preview.invoice_provider.status', 'setup_unverified')
        ->assertJsonPath('preview.payment_processing.processor', 'stripe')
        ->assertJsonPath('preview.payment_processing.payment_link_created', false)
        ->assertJsonPath('preview.delivery.status', 'not_sent')
        ->assertJsonPath('preview.accounting_sync.status', 'not_selected');

    expect(FieldServiceFinancialDocument::query()->count())->toBe(1)
        ->and(FieldServiceFinancialDocument::query()->first()->is($imported))->toBeTrue()
        ->and($tenant->brandProfile()->exists())->toBeFalse();
    Http::assertNothingSent();
    $this->postJson('/api/mobile/v1/workspaces/other-workspace/field-service/invoices/preview', [
        'customer_name' => 'Other Customer',
        'line_items' => [['description' => 'Service call', 'quantity' => 1, 'unit_amount_cents' => 5000]],
    ])->assertNotFound();
});

test('field invoice preview is limited to tenant financial administrators', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    $employee = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);

    $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/invoices/preview', [
        'customer_name' => 'Example Customer',
        'invoice_provider' => 'quickbooks',
        'line_items' => [['description' => 'Service call', 'quantity' => 1, 'unit_amount_cents' => 5000]],
    ])->assertForbidden();
});

test('field invoice preview is unavailable when field service is explicitly disabled', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'unavailable', 'enabled_status' => 'disabled', 'entitlement_source' => 'test']);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/invoices/preview', [
        'customer_name' => 'Example Customer',
        'invoice_provider' => 'everbranch_pay',
        'line_items' => [['description' => 'Service call', 'quantity' => 1, 'unit_amount_cents' => 5000]],
    ])->assertForbidden();
});

test('quickbooks can be selected as invoice provider without writing to accounting', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/invoices/preview', [
        'customer_name' => 'Example Customer',
        'invoice_provider' => 'quickbooks',
        'line_items' => [['description' => 'Service call', 'quantity' => 1, 'unit_amount_cents' => 5000]],
    ])->assertOk()
        ->assertJsonPath('preview.invoice_provider.key', 'quickbooks')
        ->assertJsonPath('preview.accounting_sync.provider', 'quickbooks')
        ->assertJsonPath('preview.accounting_sync.status', 'not_synced')
        ->assertJsonPath('preview.payment_processing.processor', null)
        ->assertJsonPath('preview.payment_processing.status', 'not_selected')
        ->assertJsonPath('preview.invoice_provider.invoice_created', false);
    Http::assertNothingSent();
});

function invoiceDraftAssistantReady(Tenant $tenant): void
{
    config(['ai_branch.api_key' => 'test-only', 'ai_branch.rates_valid_until' => now()->addMonth()->toIso8601String()]);
    TenantAccessProfile::where('tenant_id', $tenant->id)->firstOrFail()
        ->forceFill(['metadata' => ['field_crew_assistant' => ['enabled' => true, 'monthly_cap_cents' => 2500]]])->save();
    TenantModuleEntitlement::firstOrCreate(['tenant_id' => $tenant->id, 'module_key' => 'ai_branch'], ['availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 2500, 'approved_at' => now()]);
}

test('invoice draft conversation applies only user-supported details, replays safely, and writes no invoice', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    invoiceDraftAssistantReady($tenant);
    $admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    Sanctum::actingAs($admin, ['mobile:read', 'mobile:write']);
    $answer = ['reply' => 'I added Jane and the outlet work. What email should I put on the draft?',
        'field_updates' => [['field' => 'customer_name', 'value' => 'Jane Doe', 'evidence' => 'Make an invoice for Jane Doe']],
        'update_line_items' => [], 'add_line_items' => [['description' => 'Replace 2 outlets', 'quantity' => 2, 'unit_amount_cents' => 12500, 'evidence' => 'Replace 2 outlets for $125 each.']]];
    Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_test', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]]], 'usage' => ['input_tokens' => 400, 'output_tokens' => 80, 'input_tokens_details' => ['cached_tokens' => 0]]])]);
    $requestId = (string) \Illuminate\Support\Str::uuid();
    $payload = ['question' => 'Make an invoice for Jane Doe. Replace 2 outlets for $125 each.', 'request_id' => $requestId, 'history' => [],
        'current_draft' => ['customer_name' => '', 'customer_email' => null, 'invoice_provider' => 'everbranch_pay', 'due_date' => null, 'memo' => 'Manual note stays', 'line_items' => []]];
    $path = '/api/mobile/v1/workspaces/preview-electric/field-service/web/invoice-draft-assistant';
    $response = $this->postJson($path, $payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('draft.customer_name', 'Jane Doe')->assertJsonPath('draft.customer_email', null)
        ->assertJsonPath('draft.memo', 'Manual note stays')->assertJsonPath('draft.line_items.0.quantity', 2)
        ->assertJsonPath('draft.line_items.0.unit_amount_cents', 12500)->assertJsonPath('monthly_cap_cents', 2500);
    $this->postJson($path, $payload)->assertOk()->assertExactJson($response->json());
    expect(TenantAiUsageEvent::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(FieldServiceFinancialDocument::where('tenant_id', $tenant->id)->count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses');
    $other = Tenant::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
    $this->postJson('/api/mobile/v1/workspaces/'.$other->slug.'/field-service/web/invoice-draft-assistant', $payload)->assertNotFound();
    Http::assertSentCount(1);
});

test('invoice draft assistant rejects invented customer and line values and denies employees before provider use', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    invoiceDraftAssistantReady($tenant);
    $employee = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);
    $path = '/api/mobile/v1/workspaces/preview-electric/field-service/web/invoice-draft-assistant';
    $payload = ['question' => 'Prepare an invoice but I have not chosen the customer or price.', 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'history' => [],
        'current_draft' => ['customer_name' => '', 'customer_email' => null, 'invoice_provider' => 'everbranch_pay', 'due_date' => null, 'memo' => '', 'line_items' => []]];
    $this->postJson($path, $payload)->assertForbidden();
    Http::assertNothingSent();

    $employee->tenants()->updateExistingPivot($tenant->id, ['role' => 'admin']);
    Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_test', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['reply' => 'I guessed the details.', 'field_updates' => [['field' => 'customer_name', 'value' => 'Jane Doe', 'evidence' => 'Jane Doe'], ['field' => 'customer_email', 'value' => 'jane@example.com', 'evidence' => 'jane@example.com']], 'update_line_items' => [], 'add_line_items' => [['description' => 'Install panel', 'quantity' => 1, 'unit_amount_cents' => 880000, 'evidence' => 'Install panel for $80']]])]]]], 'usage' => ['input_tokens' => 400, 'output_tokens' => 60, 'input_tokens_details' => ['cached_tokens' => 0]]])]);
    $payload['request_id'] = (string) \Illuminate\Support\Str::uuid();
    $this->postJson($path, $payload)->assertOk()->assertJsonPath('draft.customer_name', '')->assertJsonPath('draft.customer_email', null)
        ->assertJsonPath('draft.line_items', [])->assertJsonPath('reply', 'I left unclear details unchanged. Please state the exact customer, quantity, and price you want.');
});

test('invoice draft keeps manual edits and does not replay old evidence or duplicate prior lines', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    invoiceDraftAssistantReady($tenant);
    $admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    Sanctum::actingAs($admin, ['mobile:read', 'mobile:write']);
    Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_test', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
        'reply' => 'I updated the quantity.', 'field_updates' => [['field' => 'customer_name', 'value' => 'Alice', 'evidence' => 'invoice for Alice']],
        'update_line_items' => [['index' => 0, 'quantity' => 3, 'evidence' => 'Quantity is 3 outlets']],
        'add_line_items' => [['description' => 'Replace 2 outlets', 'quantity' => 2, 'unit_amount_cents' => 12500, 'evidence' => 'Replace 2 outlets for $125 each']],
    ])]]]], 'usage' => ['input_tokens' => 400, 'output_tokens' => 60, 'input_tokens_details' => ['cached_tokens' => 0]]])]);
    $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/web/invoice-draft-assistant', [
        'question' => 'Quantity is 3 outlets.', 'request_id' => (string) \Illuminate\Support\Str::uuid(),
        'history' => [['role' => 'user', 'text' => 'Make an invoice for Alice. Replace 2 outlets for $125 each.'], ['role' => 'assistant', 'text' => 'What quantity?']],
        'current_draft' => ['customer_name' => 'Manual customer', 'customer_email' => null, 'invoice_provider' => 'everbranch_pay', 'due_date' => null,
            'memo' => 'Manual note', 'line_items' => [['description' => 'Replace outlets', 'quantity' => null, 'unit_amount_cents' => null]]],
    ])->assertOk()->assertJsonPath('draft.customer_name', 'Manual customer')->assertJsonPath('draft.memo', 'Manual note')
        ->assertJsonPath('draft.line_items.0.quantity', 3)->assertJsonPath('draft.line_items.0.unit_amount_cents', null)
        ->assertJsonCount(1, 'draft.line_items');
});

test('invoice draft rejects negative, extra precision, and oversized prices', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    invoiceDraftAssistantReady($tenant);
    $admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    Sanctum::actingAs($admin, ['mobile:read', 'mobile:write']);
    Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_test', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
        'reply' => 'I added both lines.', 'field_updates' => [], 'update_line_items' => [],
        'add_line_items' => [['description' => 'Panel work', 'quantity' => 1, 'unit_amount_cents' => 8099, 'evidence' => 'Panel work for -$80.999'],
            ['description' => 'Outlet work', 'quantity' => 1, 'unit_amount_cents' => 800000000, 'evidence' => 'Outlet work for $80000000']],
    ])]]]], 'usage' => ['input_tokens' => 400, 'output_tokens' => 60, 'input_tokens_details' => ['cached_tokens' => 0]]])]);
    $this->postJson('/api/mobile/v1/workspaces/preview-electric/field-service/web/invoice-draft-assistant', [
        'question' => 'Panel work for -$80.999. Outlet work for $80000000.', 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'history' => [],
        'current_draft' => ['customer_name' => '', 'customer_email' => null, 'invoice_provider' => 'everbranch_pay', 'due_date' => null, 'memo' => '', 'line_items' => []],
    ])->assertOk()->assertJsonPath('draft.line_items', [])->assertJsonPath('reply', 'I left unclear details unchanged. Please state the exact customer, quantity, and price you want.');
});

test('live invoice draft session uses mocked client delegation and blocks employees', function (): void {
    Http::preventStrayRequests();
    $tenant = Tenant::query()->create(['name' => 'Preview Electric', 'slug' => 'preview-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    invoiceDraftAssistantReady($tenant);
    $employee = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    Sanctum::actingAs($employee, ['mobile:read', 'mobile:write']);
    $path = '/api/mobile/v1/workspaces/preview-electric/field-service/web/assistant/live-invoice-draft';
    $offer = "v=0\r\no=- 431 3 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n";
    $this->postJson($path, ['sdp' => $offer])->assertForbidden();
    Http::assertNothingSent();

    $employee->tenants()->updateExistingPivot($tenant->id, ['role' => 'admin']);
    Http::fake(['api.openai.com/v1/live/sessions' => Http::response(['session' => ['id' => 'live_test'], 'transport' => ['sdp' => "v=0\r\no=- 732 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n"]])]);
    $this->postJson($path, ['sdp' => $offer])->assertOk()->assertJsonPath('session_id', 'live_test');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/live/sessions'
        && data_get($request->data(), 'session.delegation.type') === 'client'
        && data_get($request->data(), 'transport.sdp') === $offer
        && str_contains((string) data_get($request->data(), 'session.instructions'), 'editable invoice preview'));
});
