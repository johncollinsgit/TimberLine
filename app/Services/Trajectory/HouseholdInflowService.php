<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Describe already included household bank receipts; never establish new income. */
class HouseholdInflowService
{
    public function build(Space $space, Collection $accounts, Collection $sources, array $cash, ?User $viewer): ?array
    {
        if ($space->kind !== 'household' || ! $viewer || ! app(FinanceAccess::class)->allows($viewer, $space)) {
            return null;
        }

        $owned = $accounts->where('space_id', $space->id)->where('kind', 'cash')->keyBy('id');
        $links = DB::table('trajectory_links')->where('household_id', $space->id)->pluck('business_id');
        $businesses = Space::whereIn('id', $links)->where('kind', 'business')->get()
            ->filter(fn ($business) => app(FinanceAccess::class)->allows($viewer, $business))->keyBy('id');
        $records = Record::where('space_id', $space->id)->where('kind', 'company_support_source')->where('active', true)->get();
        $support = app(SupportReceiptService::class);
        $parents = $types = $seen = [];
        $missing = false;
        $review = 0;
        foreach ($cash['inflows'] as $branch) {
            foreach ($branch['ids'] as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $source = $sources->get($id);
                if (! $source || $source->space_id !== $space->id || ! $owned->has($source->account_id)
                    || $source->amount_cents <= 0 || $source->pending || $source->removed) {
                    $missing = true;

                    continue;
                }
                $amount = $source->amount_cents;
                $key = $branch['purpose_key'];
                $label = $branch['purpose_label'];
                $kind = $branch['kind'];
                $type = $key;
                $typeLabel = $label;
                $preserve = false;
                if ($kind === 'review') {
                    $review += $amount;
                } elseif ($source->flow === 'loan_draw') {
                    [$key, $label, $typeLabel, $preserve] = ['loans', 'Loans', 'Borrowed cash', true];
                    $type = 'loans';
                } elseif ($source->flow === 'asset_sale' && $source->category === 'gold_and_silver') {
                    [$key, $label, $typeLabel, $preserve] = ['metals', 'Metals', 'Metal-sale proceeds', true];
                    $type = 'metals';
                } elseif ($source->flow === 'income' && in_array($source->category, ['odd_jobs', 'mowing_lawns'], true)) {
                    [$key, $label, $preserve] = ['odd_jobs', 'Odd jobs', true];
                    $type = 'odd_jobs:'.$source->category;
                    $typeLabel = $space->settings['income_categories'][$source->category] ?? ($source->category === 'mowing_lawns' ? 'Mowing lawns' : 'Odd jobs');
                } elseif (in_array($source->flow, ['income', 'owner_wages', 'owner_distribution'], true)) {
                    // Reuse the existing confirmed-source authority, including its
                    // merchant refinement, conflicting-mapping and business-access gates.
                    $receipt = ['id' => $id, 'space_id' => $space->id, 'amount_cents' => $amount,
                        'merchant' => $source->merchant, 'category' => $source->category, 'flow' => $source->flow,
                        'reviewed' => $source->reviewed, 'pending' => false, 'removed' => false];
                    $matched = $support->build($space, collect([$receipt]), $records, $businesses->keys()->all());
                    $company = collect($matched['business_totals'])->firstWhere('amount_cents', $amount);
                    if ($company && $businesses->has($company['business_space_id'])) {
                        $business = $businesses[$company['business_space_id']];
                        $role = $company['payroll_cents'] === $amount ? 'net_payroll' : ($company['draws_cents'] === $amount ? 'owner_draw' : 'other_support');
                        [$key, $label, $kind, $preserve] = ['company:'.$business->id, $business->name, 'support', true];
                        $type = $key.':'.$role;
                        $typeLabel = $business->name.' · '.match ($role) {
                            'net_payroll' => 'Net payroll', 'owner_draw' => 'Owner draws', default => 'Other support',
                        };
                    }
                }
                $this->add($parents, $key, $label, $kind, $amount, $id);
                $parents[$key]['purpose_key'] = 'household:'.$key;
                $parents[$key]['purpose_label'] = $label;
                $this->add($types, $type, $typeLabel, $kind === 'review' ? 'review' : 'receipt', $amount, $id);
                $types[$type]['preserve'] = $preserve;
                $types[$type]['merchants'] ??= [];
                $merchant = trim($source->merchant) ?: 'Unnamed receipt';
                $this->add($types[$type]['merchants'], ClassificationService::merchant($merchant), $merchant, 'receipt', $amount, $id);
            }
        }
        foreach ($types as &$type) {
            $type['merchants'] = array_values($type['merchants']);
        }
        unset($type);
        $total = (int) array_sum(array_column($parents, 'amount_cents'));
        $complete = ! $missing && ($cash['inflow_cents'] ?? null) !== null && $total === $cash['inflow_cents'];
        $families = [['key' => 'loans', 'label' => 'Loans'], ['key' => 'metals', 'label' => 'Metals'], ['key' => 'odd_jobs', 'label' => 'Odd jobs']];
        foreach ($businesses as $business) {
            if ($records->contains(fn ($r) => ($r->data['confirmed'] ?? false) === true && ($r->data['business_space_id'] ?? null) === $business->id)) {
                $families[] = ['key' => 'company:'.$business->id, 'label' => $business->name];
            }
        }

        return ['receipt_view' => true, 'inflows' => array_values($parents), 'outflows' => array_values($types),
            'inflow_cents' => $complete ? $total : null, 'outflow_cents' => $complete ? $total : null,
            'known_inflow_cents' => $total, 'known_outflow_cents' => $total, 'difference_cents' => $complete ? 0 : null,
            'review_inflow_cents' => $review, 'review_outflow_cents' => 0, 'receipt_count' => count($seen),
            'source_families' => array_map(fn ($family) => [...$family,
                'amount_cents' => $parents[$family['key']]['amount_cents'] ?? 0,
                'ids' => $parents[$family['key']]['ids'] ?? []], $families),
            'notes' => ['Actual posted receipts on household-owned cash accounts in the selected dates and account.',
                'Company receipts use confirmed source mappings and authorized linked businesses; net payroll, draws and other support remain distinct.',
                'Loans are borrowed cash and metals are one-time asset-sale proceeds. Neither becomes earned or recurring income.',
                'Medical shares remain bill reimbursements. Planned support, off-bank cash, internal transfers and company-paid personal purchases are excluded.',
                'A source with no reviewed receipts is a coverage gap to inspect, not proof that no money was received.']];
    }

    private function add(array &$groups, string $key, string $label, string $kind, int $amount, int $id): void
    {
        $groups[$key] ??= ['key' => $key, 'label' => $label, 'kind' => $kind, 'amount_cents' => 0, 'ids' => []];
        $groups[$key]['amount_cents'] += $amount;
        $groups[$key]['ids'][] = $id;
    }
}
