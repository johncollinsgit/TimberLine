<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Space;
use Illuminate\Support\Collection;

class TaxReviewService
{
    public function review(Space $space, Collection $entries, Collection $records): array
    {
        $profile = $space->settings['tax_profile'] ?? [];
        $receipts = $records->where('kind', 'receipt')->pluck('data')->pluck('transaction_id')->filter()->all();
        $rows = [];
        foreach ($entries->where('flow', 'expense')->where('amount_cents', '<', 0)->groupBy('category') as $category => $group) {
            $reviewed = $group->where('reviewed', true)->count();
            $documented = $group->whereIn('id', $receipts)->count();
            $score = Money::ratio($reviewed * 50 + $documented * 50, 1, $group->count());
            $note = match ($category) {
                'dining', 'alcohol', 'entertainment' => 'Separate business meals from entertainment and personal use. Record business purpose and participants; category alone does not establish deductibility. IRC §274.',
                'transport', 'travel' => 'Separate personal travel, commuting, and business use. Retain mileage or travel records and the applicable deduction method. IRC §§162, 274.',
                'materials', 'shopping' => 'Distinguish supplies consumed, inventory, and capital assets. A purchase is not automatically a current expense. Review IRC §§263, 471 and any depreciation election.',
                'payroll' => 'Reconcile wages, employer taxes, and benefits to payroll evidence. Owner distributions are not payroll. IRC §162.',
                'interest' => 'Separate principal and interest, and trace how borrowed funds were used. Deductibility follows purpose and applicable limits. IRC §163.',
                'giving' => 'Review the recipient, entity treatment, and contribution limits. A gift is not automatically an ordinary operating expense. IRC §170.',
                default => 'Document the ordinary and necessary business purpose and separate any personal portion. IRC §§162, 262.',
            };
            $rows[] = ['category' => $category, 'amount_cents' => -(int) $group->sum('amount_cents'), 'count' => $group->count(), 'reviewed_count' => $reviewed, 'receipt_count' => $documented, 'readiness_score' => $score, 'rating' => $score >= 90 ? 'Documented for accountant review' : ($score >= 50 ? 'Needs supporting evidence' : 'Needs classification and evidence'), 'guidance' => $note, 'ids' => $group->pluck('id')->all()];
        }
        $sources = [
            ['label' => 'IRS business expense resources', 'url' => 'https://www.irs.gov/forms-pubs/guide-to-business-expense-resources'],
            ['label' => 'IRS Publication 334 — small business', 'url' => 'https://www.irs.gov/publications/p334'],
        ];
        $notes = ['Scores measure reviewed classifications and linked receipts, not deduction eligibility or an audit probability.', 'Tax year, entity election, business purpose, basis, and substantiation can change treatment. Send unresolved items to your accountant.'];
        if (($profile['federal_treatment'] ?? '') === 's_corporation') {
            $notes[] = 'S corporation: keep shareholder wages separate from distributions. Review reasonable compensation and shareholder basis before determining distribution tax treatment.';
            $sources[] = ['label' => 'IRS S corporation compensation', 'url' => 'https://www.irs.gov/businesses/small-businesses-self-employed/s-corporation-compensation-and-medical-insurance-issues'];
        }
        if (($profile['federal_treatment'] ?? '') === 'single_member_llc') {
            $notes[] = 'Single-owner LLC with no corporate election: owner draws do not establish business profit and are not a deductible wage paid to the owner. Reconcile taxable business activity separately.';
            $sources[] = ['label' => 'IRS single-member LLC treatment', 'url' => 'https://www.irs.gov/businesses/small-businesses-self-employed/single-member-limited-liability-companies'];
        }
        if (($profile['state'] ?? '') === 'SC') {
            $notes[] = 'South Carolina: verify the selected tax year’s federal conformity and state modifications under SC Code §§12-6-40, 12-6-50 and Article 9. Do not assume a new federal deduction is adopted automatically.';
            $sources[] = ['label' => 'South Carolina Code Title 12, Chapter 6', 'url' => 'https://www.scstatehouse.gov/code/t12c006.php'];
            $sources[] = ['label' => 'SCDOR business income taxes', 'url' => 'https://dor.sc.gov/business-income-tax'];
        } else {
            $notes[] = 'State-specific guidance is not yet verified for this profile. Federal review remains available; state tax treatment needs accountant review.';
        }

        return ['profile' => $profile, 'profile_complete' => ! empty($profile['state']) && ! empty($profile['federal_treatment']) && $profile['federal_treatment'] !== 'unknown', 'sources_checked_on' => '2026-09-19', 'sources' => $sources, 'notes' => $notes, 'categories' => $rows];
    }

    public function profitLoss(Collection $entries, array $ledger): array
    {
        $revenue = $entries->where('flow', 'income');
        $expense = $entries->filter(fn ($e) => in_array($e['flow'], ['expense', 'refund'], true) || ($e['flow'] === 'owner_wages' && $e['amount_cents'] < 0));
        $lines = $expense->groupBy('category')->map(fn ($rows, $category) => ['category' => $category, 'amount_cents' => -(int) $rows->sum('amount_cents'), 'ids' => $rows->pluck('id')->all()])->values()->all();
        $ready = $ledger['income_cents'] !== null && $ledger['expenses_cents'] !== null;

        return ['authoritative' => $ready, 'source' => $ready ? 'QuickBooks · selected report period' : 'Provisional transaction activity · incomplete accounting', 'income_cents' => $ready ? $ledger['income_cents'] : (int) $revenue->sum('amount_cents'), 'expenses_cents' => $ready ? $ledger['expenses_cents'] : -(int) $expense->sum('amount_cents'), 'net_cents' => $ready ? $ledger['income_cents'] - $ledger['expenses_cents'] : (int) $revenue->sum('amount_cents') + (int) $expense->sum('amount_cents'), 'observed_at' => $ledger['observed_at'], 'basis' => $ledger['basis'], 'revenue_ids' => $revenue->pluck('id')->all(), 'transaction_expense_lines' => $lines, 'unreviewed_count' => $entries->where('reviewed', false)->count(), 'note' => 'Transaction detail is supporting evidence and may not reconcile to QuickBooks accruals, inventory, depreciation or payroll. Transfers, loan proceeds, owner draws, asset sales and processor evidence are not added to operating revenue.'];
    }
}
