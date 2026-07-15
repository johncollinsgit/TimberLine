<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleAccount;
use App\Models\WholesaleFollowUp;
use App\Models\WholesaleProspect;
use App\Services\Wholesale\WholesaleProspectWorkflowService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin']);
    $this->service = app(WholesaleProspectWorkflowService::class);
});

function workflowProspect(int $tenantId, array $overrides = []): WholesaleProspect
{
    return WholesaleProspect::query()->create(array_merge([
        'tenant_id' => $tenantId,
        'public_id' => (string) Str::uuid(),
        'business_name' => 'Candidate Shop',
        'status' => 'newly_discovered',
        'discovery_source' => 'google_places',
        'fit_score' => 70,
        'fit_confidence' => 75,
        'fit_explanation' => ['score' => 70, 'positive_signals' => ['Gift retailer']],
    ], $overrides));
}

test('prospect conversion preserves research closes tasks and is idempotent for an existing account', function (): void {
    $first = workflowProspect($this->tenant->id, ['existing_customer_match' => str_repeat('a', 64), 'notes' => 'Buyer prefers regional products.']);
    $this->service->apply($first, $this->actor, ['action' => 'schedule_follow_up', 'due_at' => now()->addDay(), 'note' => 'Review line sheet.']);
    $converted = $this->service->apply($first, $this->actor, ['action' => 'convert']);
    $second = workflowProspect($this->tenant->id, ['existing_customer_match' => str_repeat('a', 64)]);
    $secondConverted = $this->service->apply($second, $this->actor, ['action' => 'convert']);

    expect($converted->status)->toBe('converted')
        ->and($converted->convertedAccount->conversion_snapshot['notes'])->toBe('Buyer prefers regional products.')
        ->and($secondConverted->converted_wholesale_account_id)->toBe($converted->converted_wholesale_account_id)
        ->and(WholesaleAccount::query()->forAllTenants()->count())->toBe(1)
        ->and(WholesaleFollowUp::query()->forAllTenants()->firstOrFail()->status)->toBe('completed');
});

test('possible duplicates must be resolved before conversion', function (): void {
    $prospect = workflowProspect($this->tenant->id, ['duplicate_status' => 'possible_match']);

    expect(fn () => $this->service->apply($prospect, $this->actor, ['action' => 'convert']))
        ->toThrow(DomainException::class, 'Resolve the possible duplicate');

    expect(WholesaleAccount::query()->forAllTenants()->count())->toBe(0);
});

test('do not contact blocks new contact activity', function (): void {
    $prospect = workflowProspect($this->tenant->id, ['do_not_contact' => true]);

    expect(fn () => $this->service->apply($prospect, $this->actor, ['action' => 'record_contact_attempt']))
        ->toThrow(DomainException::class, 'do not contact');
});
