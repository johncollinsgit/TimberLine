<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function candidates(User $user, Space $space): array
    {
        $access = app(FinanceAccess::class);
        $access->authorize($user, $space);
        $ids = $access->spaces($user)->pluck('id')->all();
        $rows = Transaction::whereIn('space_id', $ids)->where('removed', false)->where('pending', false)->whereNotIn('flow', ['medical_payment', 'medical_share', 'medical_membership'])->where('posted_on', '>=', now()->subDays(90))->latest('posted_on')->limit(1500)->get();
        $candidates = [];
        foreach ($rows->where('space_id', $space->id) as $a) {
            foreach ($rows as $b) {
                if ($a->id >= $b->id || $a->account_id === $b->account_id || abs($a->posted_on->diffInDays($b->posted_on)) > 5) {
                    continue;
                }
                if (in_array($a->flow, ['transfer', 'card_payment', 'owner_distribution', 'owner_wages', 'duplicate'], true) && $a->reviewed) {
                    continue;
                }
                if ($a->amount_cents === -$b->amount_cents && $a->amount_cents !== 0) {
                    $candidates[] = ['left' => $a->only(['id', 'space_id', 'merchant', 'amount_cents', 'version']), 'right' => $b->only(['id', 'space_id', 'merchant', 'amount_cents', 'version']), 'kind' => 'transfer', 'explanation' => 'Opposite amounts on different accounts within five days. Confirm the purpose.'];
                } elseif ($a->amount_cents === $b->amount_cents && ClassificationService::merchant($a->merchant) === ClassificationService::merchant($b->merchant)) {
                    $candidates[] = ['left' => $a->only(['id', 'space_id', 'merchant', 'amount_cents', 'version']), 'right' => $b->only(['id', 'space_id', 'merchant', 'amount_cents', 'version']), 'kind' => 'duplicate', 'explanation' => 'Matching amount and merchant on different sources. Review before excluding either.'];
                }
                if (count($candidates) >= 30) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    public function confirm(User $user, array $input): void
    {
        DB::transaction(function () use ($user, $input): void {
            $ids = [$input['left_id'], $input['right_id']];
            sort($ids);
            $rows = Transaction::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($rows->count() === 2, 422);
            $left = $rows->firstWhere('id', $input['left_id']);
            $right = $rows->firstWhere('id', $input['right_id']);
            foreach ($rows as $tx) {
                app(FinanceAccess::class)->authorize($user, Space::findOrFail($tx->space_id));
                app(MedicalSharingService::class)->assertUnbound($tx);
            }
            abort_unless($left->version === $input['left_version'] && $right->version === $input['right_version'], 409);
            abort_unless($left->account_id !== $right->account_id, 422);
            if ($input['kind'] === 'duplicate') {
                abort_unless($left->amount_cents === $right->amount_cents, 422);
            } else {
                abort_unless($left->amount_cents === -$right->amount_cents, 422);
            }
            foreach ($rows as $tx) {
                $before = $tx->only(['flow', 'reviewed', 'explanation', 'version']);
                $flow = $input['kind'] === 'duplicate' ? ($tx->id === $right->id ? 'duplicate' : $tx->flow) : $input['kind'];
                $tx->update(['flow' => $flow, 'reviewed' => true, 'explanation' => 'Matched and reviewed across accounts.', 'version' => $tx->version + 1]);
                Event::create(['space_id' => $tx->space_id, 'actor_id' => $user->id, 'action' => 'reconcile', 'record_id' => $tx->id, 'before' => $before, 'after' => ['flow' => $flow, 'paired_transaction_id' => $tx->id === $left->id ? $right->id : $left->id]]);
            }
        });
    }

    public function combined(User $user, int $householdId, int $businessId): array
    {
        $house = Space::findOrFail($householdId);
        $business = Space::findOrFail($businessId);
        $access = app(FinanceAccess::class);
        $access->authorize($user, $house);
        $access->authorize($user, $business);
        abort_unless(DB::table('trajectory_links')->where('household_id', $house->id)->where('business_id', $business->id)->exists(), 403);
        $start = now($house->timezone)->startOfMonth()->toDateString();
        $end = now($house->timezone)->toDateString();
        $personal = collect(app(LedgerService::class)->entries($house, $start, $end));
        $company = collect(app(LedgerService::class)->entries($business, $start, $end));
        $ownerIn = $personal->whereIn('flow', ['owner_wages', 'owner_distribution'])->filter(fn ($r) => $r['amount_cents'] > 0);
        $companyOut = $company->whereIn('flow', ['owner_wages', 'owner_distribution'])->filter(fn ($r) => $r['amount_cents'] < 0);
        // Only explicitly reconciled transaction pairs are eliminated; unmatched transfers stay visible.
        $matched = 0;
        foreach ($ownerIn as $row) {
            $event = Event::where('space_id', $house->id)->where('action', 'reconcile')->where('record_id', $row['id'])->latest('id')->first();
            $pair = $event?->after['paired_transaction_id'] ?? null;
            if ($pair && $companyOut->contains('id', $pair)) {
                $matched += $row['amount_cents'];
            }
        }

        $personalSummary = app(DashboardService::class)->build($house)['summary'];
        $businessSummary = app(DashboardService::class)->build($business)['summary'];
        $equity = \App\Models\Trajectory\Record::where('space_id', $house->id)->where('kind', 'asset')->where('active', true)->get()->filter(fn ($r) => ($r->data['asset_type'] ?? null) === 'business_equity');
        $excludedEquity = (int) $equity->filter(fn ($r) => ($r->data['linked_business_space_id'] ?? null) === $business->id)->sum(fn ($r) => $r->data['value_cents']);
        $ambiguous = $equity->contains(fn ($r) => empty($r->data['linked_business_space_id']));
        $combinedWorth = $ambiguous ? null : $personalSummary['net_worth_cents'] + $businessSummary['net_worth_cents'] - $excludedEquity;

        return ['net_worth_cents' => $combinedWorth, 'excluded_business_equity_cents' => $excludedEquity, 'net_worth_complete' => ! $ambiguous && $personalSummary['net_worth_complete'] && $businessSummary['net_worth_complete'], 'net_worth_setup' => $ambiguous ? 'Link every business-equity valuation to its business before combining net worth.' : null, 'household' => $house->name, 'business' => $business->name, 'owner_income_cents' => (int) $ownerIn->sum('amount_cents'), 'company_owner_outflow_cents' => -(int) $companyOut->sum('amount_cents'), 'eliminated_transfers_cents' => $matched, 'unmatched_owner_income_cents' => (int) $ownerIn->sum('amount_cents') - $matched, 'household_spending_cents' => -(int) $personal->whereIn('flow', ['expense', 'refund', 'medical_payment', 'medical_membership'])->sum('amount_cents'), 'external_income_cents' => (int) $personal->merge($company)->where('flow', 'income')->sum('amount_cents'), 'external_spending_cents' => -(int) $personal->merge($company)->whereIn('flow', ['expense', 'refund', 'medical_payment', 'medical_membership'])->sum('amount_cents')];
    }
}
