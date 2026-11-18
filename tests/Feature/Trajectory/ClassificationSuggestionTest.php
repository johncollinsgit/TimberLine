<?php

namespace Tests\Feature\Trajectory;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\AllocationDetail;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\ExpenseNotice;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AttributionService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassificationSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Space $space;

    private Account $account;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['trajectory.enabled' => true, 'trajectory_mobile.inbox_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
        [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
        $this->space->update(['timezone' => 'UTC']);
        $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'suggestions:checking', 'name' => 'Fictional checking (...1111)', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 500000]);
        $this->base = '/trajectory/spaces/'.$this->space->id.'/classification-suggestions';
        $this->actingAs($this->owner);
    }

    public function test_reviewed_exact_history_proposes_without_writing_or_learning(): void
    {
        $a = $this->purchase('history-a', 'Fictional grocer', 'groceries', true);
        $b = $this->purchase('history-b', 'Fictional grocer', 'groceries', true);
        $tx = $this->purchase('today', 'Fictional grocer');
        $before = $this->facts();
        $request = $this->postJson($this->base, $this->input($tx))->assertOk();
        $this->assertStringContainsString('no-store', $request->headers->get('Cache-Control'));
        $response = $request
            ->assertJsonPath('readonly', true)->assertJsonPath('rows.0.category', 'groceries')->assertJsonPath('rows.0.confidence', 'high')
            ->assertJsonPath('rows.0.evidence_count', 2)->assertJsonPath('rows.0.editable', true)->json('rows.0');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $response['evidence_ids']);
        $this->assertSame($before, $this->facts());
    }

    public function test_conflicting_categories_do_not_get_a_guess_and_mixed_merchants_are_explicit(): void
    {
        $this->purchase('food', 'Amazon', 'groceries', true);
        $this->purchase('supplies', 'Amazon', 'materials', true);
        $tx = $this->purchase('new', 'Amazon');
        $this->postJson($this->base, $this->input($tx))->assertOk()->assertJsonPath('rows.0.category', null)
            ->assertJsonPath('rows.0.has_conflicts', true)->assertJsonPath('rows.0.confidence', 'none');
        $this->purchase('walmart-history', 'Walmart', 'groceries', true);
        $mixed = $this->purchase('walmart-new', 'Walmart');
        $r = $this->postJson($this->base, $this->input($mixed))->assertOk()->assertJsonPath('rows.0.confidence', 'low')->json('rows.0');
        $this->assertStringContainsString('multiple purposes', $r['reason']);
    }

    public function test_title_hints_remain_low_confidence_and_unreviewed_history_is_not_evidence(): void
    {
        $this->purchase('unreviewed', 'McDonald’s', 'materials');
        $tx = $this->purchase('food', 'McDonald’s');
        $this->postJson($this->base, $this->input($tx))->assertOk()->assertJsonPath('rows.0.category', 'dining')
            ->assertJsonPath('rows.0.confidence', 'low')->assertJsonPath('rows.0.evidence_count', 0);
        $unknown = $this->purchase('unknown', 'Fictional x179');
        $this->postJson($this->base, $this->input($unknown))->assertOk()->assertJsonPath('rows.0.category', null)->assertJsonPath('rows.0.editable', true);
    }

    public function test_payment_account_context_wins_and_future_removed_and_pending_history_is_excluded(): void
    {
        $other = Account::create(['space_id' => $this->space->id, 'source_key' => 'suggestions:other', 'name' => 'Other card', 'kind' => 'credit', 'currency' => 'USD', 'balance_cents' => 10000]);
        $this->purchase('other', 'Fictional merchant', 'materials', true, $other);
        $good = $this->purchase('owned', 'Fictional merchant', 'groceries', true);
        $future = $this->purchase('future', 'Fictional merchant', 'shopping', true);
        $future->update(['posted_on' => '2026-11-19']);
        $pending = $this->purchase('pending', 'Fictional merchant', 'shopping', true);
        $pending->update(['pending' => true]);
        $removed = $this->purchase('removed', 'Fictional merchant', 'shopping', true);
        $removed->update(['removed' => true]);
        $tx = $this->purchase('new', 'Fictional merchant');
        $r = $this->postJson($this->base, $this->input($tx))->assertOk()->assertJsonPath('rows.0.category', 'groceries')->json('rows.0');
        $this->assertSame([$good->id], $r['evidence_ids']);
    }

    public function test_selected_program_and_current_economic_child_drive_history_not_raw_bank_category(): void
    {
        $service = app(AttributionService::class);
        $parent = $service->saveDefinition($this->owner, $this->space, 'category_definition', 'Shopping', ['key' => 'shopping', 'direction' => 'expense']);
        $child = $service->saveDefinition($this->owner, $this->space, 'category_definition', 'Clothing', ['key' => 'clothing', 'direction' => 'expense', 'parent_record_id' => $parent->id]);
        $program = $service->saveDefinition($this->owner, $this->space, 'program', 'Soccer', ['key' => 'soccer']);
        $history = $this->purchase('history', 'Fictional uniforms', 'shopping', true);
        $service->save($this->owner, $this->space, $history, [['space_id' => $this->space->id, 'amount_cents' => -1000, 'category' => 'shopping', 'subcategory_record_id' => $child->id, 'program_record_id' => $program->id]], 1);
        $this->purchase('different-program', 'Fictional uniforms', 'materials', true);
        $tx = $this->purchase('new', 'Fictional uniforms');
        $service->save($this->owner, $this->space, $tx, [['space_id' => $this->space->id, 'amount_cents' => -1000, 'category' => 'uncategorized', 'program_record_id' => $program->id]], 1);
        $detail = AllocationDetail::where('transaction_id', $tx->id)->where('active', true)->firstOrFail();
        $tx->refresh();
        $before = $this->facts();
        $this->postJson($this->base, [...$this->input($tx, $detail->id), 'program_id' => $program->id])->assertOk()
            ->assertJsonPath('rows.0.category', 'clothing')->assertJsonPath('rows.0.category_label', 'Clothing')->assertJsonPath('rows.0.evidence_count', 1);
        $this->assertSame($before, $this->facts());
        $this->postJson($this->base, $this->input($tx))->assertConflict();
    }

    public function test_pending_expense_and_refund_can_receive_proposals_without_posting_them(): void
    {
        $this->purchase('history', 'Fictional retailer', 'shopping', true);
        $tx = $this->purchase('pending', 'Fictional retailer');
        $tx->update(['pending' => true]);
        $this->postJson($this->base, $this->input($tx))->assertOk()->assertJsonPath('rows.0.pending', true)->assertJsonPath('rows.0.category', 'shopping');
        $refund = $this->purchase('refund', 'Fictional retailer');
        $refund->update(['amount_cents' => 1000, 'flow' => 'refund']);
        Allocation::where('transaction_id', $refund->id)->update(['amount_cents' => 1000]);
        $this->postJson($this->base, $this->input($refund))->assertOk()->assertJsonPath('rows.0.category', 'shopping');
        $this->assertTrue($tx->fresh()->pending);
    }

    public function test_special_movements_medical_bound_and_reviewed_purchases_are_not_editable_proposals(): void
    {
        foreach (['transfer', 'loan_draw', 'card_payment', 'medical_payment', 'sales_tax_remittance'] as $flow) {
            $tx = $this->purchase($flow, 'Chipotle');
            $tx->update(['flow' => $flow]);
            $this->postJson($this->base, $this->input($tx))->assertOk()->assertJsonPath('rows.0.editable', false)->assertJsonPath('rows.0.category', null);
        }
        $medical = $this->purchase('medical-bound', 'Chipotle');
        Record::create(['space_id' => $this->space->id, 'kind' => 'medical_payment', 'name' => 'Fictional provider', 'data' => ['transaction_id' => $medical->id], 'active' => true]);
        $this->postJson($this->base, $this->input($medical))->assertOk()->assertJsonPath('rows.0.editable', false);
        $reviewed = $this->purchase('already-reviewed', 'Chipotle', 'dining', true);
        $this->postJson($this->base, $this->input($reviewed))->assertOk()->assertJsonPath('rows.0.editable', false);
    }

    public function test_current_scope_and_version_validation_fail_closed(): void
    {
        $tx = $this->purchase('current', 'Fictional merchant');
        $this->postJson($this->base, ['rows' => [['id' => $tx->id, 'version' => 99]]])->assertConflict();
        $this->postJson($this->base, ['rows' => array_fill(0, 51, ['id' => $tx->id, 'version' => 1])])->assertUnprocessable();
        $this->postJson($this->base, ['rows' => array_fill(0, 2, ['id' => $tx->id, 'version' => 1])])->assertUnprocessable();
        $this->postJson($this->base, [...$this->input($tx), 'program_id' => 99999])->assertUnprocessable();
        $this->postJson($this->base, [...$this->input($tx), 'account_id' => 99999])->assertNotFound();
        $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->actingAs($stranger)->postJson($this->base, $this->input($tx))->assertForbidden();
    }

    public function test_shared_sources_are_visible_only_with_current_source_access(): void
    {
        $business = $this->business();
        $businessAccount = Account::create(['space_id' => $business->id, 'source_key' => 'suggestions:business', 'name' => 'Private business account', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 10000]);
        $tx = $this->purchase('shared', 'Private merchant', account: $businessAccount);
        app(AttributionService::class)->save($this->owner, $business, $tx, [['space_id' => $this->space->id, 'amount_cents' => -1000, 'category' => 'uncategorized']], 1);
        $tx->refresh();
        $detail = AllocationDetail::where('transaction_id', $tx->id)->where('space_id', $this->space->id)->where('active', true)->firstOrFail();
        $this->postJson($this->base, $this->input($tx, $detail->id))->assertOk()->assertJsonPath('rows.0.source_space_id', $business->id)->assertJsonPath('rows.0.editable', true);
        $business->update(['enabled' => false]);
        $r = $this->postJson($this->base, $this->input($tx, $detail->id))->assertOk()->assertJsonPath('rows.0.source_space_id', null)
            ->assertJsonPath('rows.0.editable', false)->assertJsonPath('rows.0.evidence_ids', [])->json();
        $this->assertStringNotContainsString('Private merchant', json_encode($r));
        $this->assertStringNotContainsString('Private business account', json_encode($r));
        $this->postJson($this->base, [...$this->input($tx, $detail->id), 'account_id' => $businessAccount->id])->assertNotFound();
    }

    public function test_exact_rules_are_proposals_and_conflicting_reviewed_evidence_is_not_overridden(): void
    {
        Record::create(['space_id' => $this->space->id, 'kind' => 'rule', 'name' => 'Fictional rule', 'active' => true,
            'data' => ['merchant' => 'Fictional merchant', 'classification' => ['category' => 'groceries', 'flow' => 'expense']]]);
        $tx = $this->purchase('rule', 'Fictional merchant');
        $this->postJson($this->base, $this->input($tx))->assertOk()->assertJsonPath('rows.0.category', 'groceries')->assertJsonPath('rows.0.evidence_count', 0);
        $this->assertSame('uncategorized', $tx->fresh()->category);
        $this->purchase('different', 'Fictional merchant', 'materials', true);
        $this->postJson($this->base, $this->input($tx))->assertOk()->assertJsonPath('rows.0.category', null)->assertJsonPath('rows.0.has_conflicts', true);
    }

    public function test_legacy_shared_bank_categories_are_not_household_economic_evidence(): void
    {
        $business = $this->business();
        $businessAccount = Account::create(['space_id' => $business->id, 'source_key' => 'suggestions:legacy-business', 'name' => 'Business source', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 10000]);
        $history = $this->purchase('legacy-reviewed', 'Fictional shared merchant', 'materials', true, $businessAccount);
        app(LedgerService::class)->split($this->owner, $business, $history, [['space_id' => $this->space->id, 'amount_cents' => -1000]], 1);
        $tx = $this->purchase('own-new', 'Fictional shared merchant');
        $this->postJson($this->base, $this->input($tx))->assertOk()->assertJsonPath('rows.0.category', null)->assertJsonPath('rows.0.evidence_count', 0);
        $history->refresh()->update(['reviewed' => false]);
        $this->postJson($this->base, $this->input($history))->assertOk()->assertJsonPath('rows.0.editable', false)->assertJsonPath('rows.0.category', null);
    }

    public function test_reviewed_parent_refinement_stays_within_actual_native_children(): void
    {
        $service = app(AttributionService::class);
        $parent = $service->saveDefinition($this->owner, $this->space, 'category_definition', 'Shopping', ['key' => 'shopping', 'direction' => 'expense']);
        $child = $service->saveDefinition($this->owner, $this->space, 'category_definition', 'Clothing', ['key' => 'clothing', 'direction' => 'expense', 'parent_record_id' => $parent->id]);
        $history = $this->purchase('child-history', 'Fictional clothing', 'shopping', true);
        $service->save($this->owner, $this->space, $history, [['space_id' => $this->space->id, 'category' => 'shopping', 'subcategory_record_id' => $child->id, 'amount_cents' => -1000]], 1);
        $reviewedParent = $this->purchase('parent-new', 'Fictional clothing', 'shopping', true);
        $this->postJson($this->base, $this->input($reviewedParent))->assertOk()->assertJsonPath('rows.0.category', 'clothing')->assertJsonPath('rows.0.editable', true);
        $this->assertSame('shopping', $reviewedParent->fresh()->category);
        $unrelated = $this->purchase('outside-parent', 'Fictional clothing', 'groceries', true);
        $this->postJson($this->base, $this->input($reviewedParent))->assertOk()->assertJsonPath('rows.0.category', null)->assertJsonPath('rows.0.has_conflicts', true);
        $this->postJson($this->base, $this->input($unrelated))->assertOk()->assertJsonPath('rows.0.editable', false);
    }

    private function purchase(string $key, string $merchant, string $category = 'uncategorized', bool $reviewed = false, ?Account $account = null): Transaction
    {
        $account ??= $this->account;
        app(LedgerService::class)->ingest($account, [['id' => $key, 'date' => '2026-11-17', 'merchant' => $merchant, 'amount_cents' => -1000, 'category' => $category]]);
        $tx = Transaction::where('source_key', $account->source_key.':'.$key)->firstOrFail();
        $tx->update(['category' => $category, 'reviewed' => $reviewed, 'flow' => 'expense']);

        return $tx;
    }

    private function input(Transaction $tx, ?int $detailId = null): array
    {
        return ['rows' => [['id' => $tx->id, 'version' => $tx->version, 'attribution_detail_id' => $detailId]]];
    }

    private function facts(): array
    {
        return [Transaction::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all(),
            Allocation::orderBy('id')->get()->toArray(), AllocationDetail::orderBy('id')->get()->toArray(),
            Account::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all(), Event::count(), Record::count(), ExpenseNotice::count()];
    }

    private function business(): Space
    {
        $tenant = Tenant::create(['slug' => 'suggestions-business', 'name' => 'Fictional business']);
        TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
        $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
        [, $space] = app(PilotService::class)->prepare($this->owner, $tenant, true);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);

        return $space;
    }
}
