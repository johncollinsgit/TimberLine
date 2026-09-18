<?php

namespace App\Services\Trajectory;

use Illuminate\Support\Collection;

class IncomeSourceService
{
    /** Keep earned income separate from transfers, borrowed cash, and asset sales. */
    public function summarize(Collection $entries): array
    {
        $sources = [
            'company_support' => $this->row('Company support', 'Money paid from your company.', true),
            'other_income' => $this->row('Other classified income', 'Income not labeled as company support.', true),
            'metals_sales' => $this->row('Gold & silver sales', 'Asset-sale proceeds; excluded from earned income.', false),
            'loan_draws' => $this->row('Loan draws', 'Borrowed cash; excluded from earned income.', false),
            'transfers' => $this->row('Transfers', 'Money moved between accounts; excluded from earned income.', false),
            'unclassified_deposits' => $this->row('Deposits needing purpose', 'Cash received without enough evidence to call income.', false),
            'refunds' => $this->row('Refunds & reimbursements', 'Returned or reimbursed money; excluded from earned income.', false),
        ];

        foreach ($entries->filter(fn (array $entry): bool => $entry['amount_cents'] > 0) as $entry) {
            $key = match (true) {
                $entry['flow'] === 'asset_sale' => 'metals_sales',
                $entry['flow'] === 'loan_draw' => 'loan_draws',
                in_array($entry['flow'], ['transfer', 'card_payment', 'debt_payment', 'duplicate'], true) => 'transfers',
                $entry['flow'] === 'unclassified_deposit' => 'unclassified_deposits',
                in_array($entry['flow'], ['refund', 'reimbursement'], true) => 'refunds',
                $this->isCompanySupport($entry) => 'company_support',
                in_array($entry['flow'], ['income', 'owner_wages', 'owner_distribution'], true) => 'other_income',
                default => null,
            };
            if (! $key) {
                continue;
            }
            $sources[$key]['amount_cents'] += $entry['amount_cents'];
            $sources[$key]['count']++;
            $sources[$key]['ids'][] = $entry['id'];
        }

        $sources = array_values(array_filter($sources, fn (array $source): bool => $source['amount_cents'] > 0));

        return [
            'earned_income_cents' => (int) collect($sources)->where('counts_as_income', true)->sum('amount_cents'),
            'sources' => $sources,
        ];
    }

    private function row(string $name, string $detail, bool $countsAsIncome): array
    {
        return ['key' => str($name)->slug()->toString(), 'name' => $name, 'detail' => $detail, 'counts_as_income' => $countsAsIncome, 'amount_cents' => 0, 'count' => 0, 'ids' => []];
    }

    private function isCompanySupport(array $entry): bool
    {
        return in_array($entry['flow'], ['owner_wages', 'owner_distribution'], true)
            || (bool) preg_match('/\bmodern\s+forestry\b/i', $entry['merchant']);
    }
}
