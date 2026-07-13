<?php

use App\Models\FieldServiceEstimate;
use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceFinancialDocumentLine;
use App\Models\FieldServiceJob;
use App\Models\IntegrationConnection;
use App\Models\MarketingProfile;
use App\Models\QuickBooksReportingSetting;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Services\Dashboard\DashboardDateRange;
use App\Services\FieldService\FieldServiceEstimateService;
use App\Services\FieldService\PriceBookCandidateService;
use App\Services\FieldService\QuickBooksOwnerReportingService;
use App\Services\Search\GlobalSearchCoordinator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
});

function reportingWorkspace(): array
{
    $tenant = Tenant::query()->create(['name' => 'Reporting Electric', 'slug' => 'reporting-electric']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    foreach (['integrations', 'quickbooks', 'documents', 'estimator'] as $module) {
        TenantModuleEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'module_key' => $module,
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'included_in_plan',
            'entitlement_source' => 'test',
            'price_source' => 'catalog',
        ]);
    }
    $owner = User::factory()->tenantAdmin()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'admin']);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);

    return [$tenant, $owner, $member];
}

test('owner reporting uses reviewed pnl mappings and keeps finance away from team members', function (): void {
    [$tenant, $owner, $member] = reportingWorkspace();
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash('sha256', 'reporting-electric'),
        'external_account_secret' => 'realm-test',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addHour(),
    ]);
    QuickBooksReportingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'integration_connection_id' => $connection->id,
        'supplies_account_mappings' => [['label' => 'Job Supplies']],
        'wage_account_mappings' => [['label' => 'Wages']],
        'contract_labor_account_mappings' => [['label' => 'Contract Labor']],
        'owner_compensation_account_mappings' => [['label' => 'Nathan Owner Pay']],
        'mappings_reviewed_at' => now(),
        'mappings_reviewed_by_user_id' => $owner->id,
    ]);
    $range = app(DashboardDateRange::class)->resolve('1m');
    QuickBooksReportingSnapshot::query()->create([
        'tenant_id' => $tenant->id,
        'integration_connection_id' => $connection->id,
        'range_key' => '1m',
        'period_start' => $range['starts_at'],
        'period_end' => $range['ends_at'],
        'metrics' => ['total_income' => 100000, 'account_lines' => [
            ['label' => 'Job Supplies', 'amount' => 12000],
            ['label' => 'Wages', 'amount' => 30000],
            ['label' => 'Nathan Owner Pay', 'amount' => 8000],
            ['label' => 'Contract Labor', 'amount' => 20000],
        ]],
        'observed_at' => now(),
    ]);
    QuickBooksReportingSnapshot::query()->create([
        'tenant_id' => $tenant->id,
        'integration_connection_id' => $connection->id,
        'range_key' => '1m:prior_year',
        'period_start' => $range['starts_at']->subYearNoOverflow(),
        'period_end' => $range['ends_at']->subYearNoOverflow(),
        'metrics' => ['total_income' => 50000, 'account_lines' => []],
        'observed_at' => now(),
    ]);
    $customer = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Largest', 'last_name' => 'Customer']);
    FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'source' => 'quickbooks', 'document_type' => 'invoice',
        'external_id' => 'invoice:1', 'document_number' => '1', 'transaction_date' => now(), 'due_date' => now()->subDay(),
        'total_amount' => 2500, 'balance' => 900, 'metadata' => ['quickbooks' => ['job_link_status' => 'needs_review']],
    ]);
    FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Completed operation', 'status' => 'done', 'completed_at' => now()]);
    FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Next service call', 'status' => 'scheduled', 'scheduled_for' => now()->addDay(), 'service_address_line_1' => '10 Main St', 'assigned_user_id' => $member->id]);

    $report = app(QuickBooksOwnerReportingService::class)->report($tenant, '1m', false);
    expect(data_get($report, 'cards.supplies.amount'))->toBe(12000.0)
        ->and(data_get($report, 'cards.employee_labor.including_owner'))->toBe(30000.0)
        ->and(data_get($report, 'cards.employee_labor.excluding_owner'))->toBe(22000.0)
        ->and(data_get($report, 'cards.contract_labor.percent'))->toBe(20.0)
        ->and(data_get($report, 'cards.combined_labor.percent'))->toBe(50.0)
        ->and(data_get($report, 'cards.unpaid_invoices.overdue_amount'))->toBe(900.0)
        ->and(data_get($report, 'cards.jobs_completed.count'))->toBe(1)
        ->and(data_get($report, 'upcoming_jobs.0.title'))->toBe('Next service call');

    $this->actingAs($owner)->get(route('quickbooks.reports.index', ['tenant' => $tenant->slug]))->assertOk()->assertSeeText('Unpaid invoices');
    $this->actingAs($member)->get(route('quickbooks.reports.index', ['tenant' => $tenant->slug]))->assertForbidden();

    Sanctum::actingAs($member, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/modules/reporting?range=1m')
        ->assertOk()->assertJsonFragment(['label' => 'Upcoming jobs'])->assertJsonMissing(['label' => 'Unpaid invoices']);
});

test('documents stay tenant scoped and owner assets stay hidden from team search', function (): void {
    Storage::fake('local');
    [$tenant, $owner, $member] = reportingWorkspace();
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Kitchen service upgrade']);

    $this->actingAs($member)->post(route('documents.store', ['tenant' => $tenant->slug]), [
        'files' => [UploadedFile::fake()->createWithContent('panel-notes.txt', 'Main panel breaker schedule')],
        'job_ids' => [$job->id],
        'caption' => 'Panel schedule',
    ])->assertRedirect();
    $teamAsset = WorkspaceAsset::query()->forTenantId($tenant->id)->where('visibility', 'team')->firstOrFail();
    expect($teamAsset->jobs()->whereKey($job->id)->exists())->toBeTrue();

    $this->actingAs($owner)->post(route('documents.store', ['tenant' => $tenant->slug]), [
        'files' => [UploadedFile::fake()->createWithContent('invoice-private.txt', 'Owner financial attachment')],
        'visibility' => 'owner',
    ])->assertRedirect();
    $ownerAsset = WorkspaceAsset::query()->forTenantId($tenant->id)->where('visibility', 'owner')->firstOrFail();

    $memberSearch = app(GlobalSearchCoordinator::class)->search('invoice-private', ['tenant_id' => $tenant->id, 'user' => $member, 'limit' => 20]);
    expect(collect($memberSearch['results'])->pluck('title'))->not->toContain('invoice-private.txt');
    $ownerSearch = app(GlobalSearchCoordinator::class)->search('invoice-private', ['tenant_id' => $tenant->id, 'user' => $owner, 'limit' => 20]);
    expect(collect($ownerSearch['results'])->pluck('title'))->toContain('invoice-private.txt');

    $other = Tenant::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
    $owner->tenants()->attach($other->id, ['role' => 'admin']);
    $this->actingAs($owner)->get(route('documents.download', ['tenant' => $other->slug, 'asset' => $teamAsset]))->assertNotFound();
    $this->actingAs($member)->get(route('documents.download', ['tenant' => $tenant->slug, 'asset' => $ownerAsset]))->assertForbidden();

    $tenantOwner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $tenantOwner->tenants()->attach($tenant->id, ['role' => 'owner']);
    Sanctum::actingAs($tenantOwner, ['mobile:read', 'mobile:write']);
    $this->post('/api/mobile/v1/workspaces/'.$tenant->slug.'/modules/documents/actions/upload_assets', [
        'files' => [UploadedFile::fake()->createWithContent('owner-mobile.txt', 'Owner-only mobile upload')],
        'visibility' => 'owner',
    ])->assertCreated();
    expect(WorkspaceAsset::query()->forTenantId($tenant->id)->where('file_name', 'owner-mobile.txt')->value('visibility'))->toBe('owner');
});

test('quickbooks automation remains opt in and has hourly and weekly schedules', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'quickbooks:sync-enabled'))
        ->values();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('expression')->all())->toContain('35 * * * *', '50 2 * * 0')
        ->and(QuickBooksReportingSetting::query()->where('scheduled_sync_enabled', true)->count())->toBe(0);
});

test('estimator candidates require history and drafts snapshot approved pricing without quickbooks writes', function (): void {
    [$tenant, $owner, $member] = reportingWorkspace();
    foreach ([225, 250] as $index => $price) {
        $document = FieldServiceFinancialDocument::query()->create([
            'tenant_id' => $tenant->id, 'source' => 'quickbooks', 'document_type' => 'invoice', 'external_id' => 'invoice:'.($index + 1),
            'transaction_date' => now()->subMonths($index), 'total_amount' => $price, 'balance' => 0,
        ]);
        FieldServiceFinancialDocumentLine::query()->create([
            'tenant_id' => $tenant->id, 'field_service_financial_document_id' => $document->id, 'source_line_id' => (string) $index,
            'description' => 'Install dedicated 20 amp circuit', 'quantity' => 1, 'unit_price' => $price, 'amount' => $price,
        ]);
    }
    $candidates = app(PriceBookCandidateService::class)->rebuild($tenant);
    expect($candidates['candidates'])->toBe(1);
    $candidate = \App\Models\FieldServicePriceBookCandidate::query()->forTenantId($tenant->id)->firstOrFail();
    expect((float) $candidate->median_unit_price)->toBe(237.5);
    $item = app(PriceBookCandidateService::class)->approve($tenant, $candidate, $owner, 240);

    $estimate = FieldServiceEstimate::query()->create([
        'tenant_id' => $tenant->id, 'created_by_user_id' => $owner->id, 'estimate_number' => 'EST-TEST-1', 'status' => 'draft',
    ]);
    app(FieldServiceEstimateService::class)->saveLines($tenant, $estimate, [[
        'price_book_item_id' => $item->id, 'description' => $item->name, 'quantity' => 2, 'unit_price' => 240,
    ]]);
    $line = $estimate->fresh('lines')->lines->first();
    expect((float) $estimate->fresh()->total_amount)->toBe(480.0)
        ->and((float) data_get($line->source_snapshot, 'unit_price_at_selection'))->toBe(240.0)
        ->and(FieldServiceFinancialDocument::query()->forTenantId($tenant->id)->count())->toBe(2);

    $this->actingAs($member)->get(route('estimator.index', ['tenant' => $tenant->slug]))->assertForbidden();
    $this->actingAs($owner)->get(route('estimator.show', ['tenant' => $tenant->slug, 'estimate' => $estimate]))->assertOk()->assertSeeText('Install dedicated 20 amp circuit');
});
