<?php

namespace App\Services\Bud;

use App\Services\Trajectory\Money;

class TrajectoryBudService
{
    /** The authorized controller supplies this context; never accept client figures. */
    public function respond(string $question, array $data): array
    {
        $q = mb_strtolower($question);
        $p = $data['planning'];
        $support = $data['decision_support'] ?? [];
        $money = fn ($v) => $v === null ? 'not yet available' : '$'.number_format($v / 100, 2);
        $links = [];
        if (! empty($data['scope_filter']['account_id'])) {
            return [
                'reply' => 'This is a single-account view for '.$data['range']['start'].' through '.$data['range']['end'].'. Recorded income is '.$money($data['summary']['income_cents'] ?? null).' and spending is '.$money($data['summary']['spending_cents'] ?? null).'. Household-wide needs, business distributions, metal holdings and budget changes are outside this filtered view. Choose All accounts to review the full household plan.',
                'links' => [['label' => 'Review account activity', 'tab' => 'transactions']], 'proposals' => [],
                'mode' => 'Bud Core · selected account only', 'space_id' => $data['space']['id'],
                'scope' => ['kind' => $data['space']['kind'], 'range' => $data['range'], 'account_id' => $data['scope_filter']['account_id'], 'provisional' => true],
                'execution' => 'No changes made.',
            ];
        }
        $proposals = $this->budgetProposals($question, $data);
        if ($proposals) {
            $proposal = $proposals[0];
            $reply = 'Prepared a monthly '.$proposal['data']['category'].' spending target of '.$money($proposal['data']['monthly_cents']).' for '.$data['space']['name'].'. Review the proposed change before saving. No budget, bank balance or transaction has changed.';
            $links[] = ['label' => 'Review budget', 'tab' => 'budget'];
        } elseif (preg_match('/budget|spending target/', $q) && preg_match('/set|change|update|reduce|increase|cut/', $q)) {
            $reply = 'Choose one spending category and an exact monthly dollar target, for example “Set groceries budget to $400 per month.” If several budgets use that category, open the budget and choose the record to edit. I will prepare a draft for review; saving uses the current record version.';
            $links[] = ['label' => 'Open budgets', 'tab' => 'budget'];
        } elseif (preg_match('/metal|gold|silver|coin|bullion|when.*sell|sell.*when/', $q)) {
            $month = $support['month'] ?? null;
            $reply = $month ? 'For '.$month['label'].', recorded metals proceeds are '.$money($month['metals_proceeds_cents']).'; the remaining recorded gap after those proceeds is '.$money($month['after_metals_gap_cents']).'. These are asset-sale proceeds, separate from recurring income. ' : 'Metals are an asset-sale bridge, separate from recurring income. ';
            $quotes = $this->financialContext($data)['metals'];
            $reply .= $quotes['quotes_usable_now'] ? 'The current valuation uses dated quotes; a dealer’s executable bid, fees, taxes and remaining lot quantities still determine actual proceeds.' : 'A current executable sale estimate is unavailable: refresh dated metal quotes and verify the remaining lots first.';
            $reply .= ' Compare the cash need and due dates with verified holdings and dealer bids. I cannot predict a best sale date or execute a sale or transfer.';
            $links[] = ['label' => 'Review metal lots and quotes', 'tab' => 'assets'];
        } elseif (preg_match('/business.*pay|company.*pay|pay us|pay me|distribution|owner.?draw|modern forestry/', $q)) {
            $businesses = $support['businesses'] ?? [];
            $reply = $businesses ? implode("\n\n", array_map(function ($business) use ($money): string {
                $capacity = $business['capacity_scenario']['capacity_cents'] ?? null;
                $amount = $business['expected_distribution_cents'];
                $status = $amount !== null ? 'conditional distribution estimate '.$money($amount) : ($capacity !== null ? 'conditional capacity scenario '.$money($capacity).'; no distribution is confirmed' : 'distribution capacity is not established');

                return $business['name'].': '.$status.'. '.$business['detail'];
            }, $businesses)) : 'No distribution-capacity estimate is available from an authorized linked business. Select its business space or explicitly link an authorized business to this household first.';
            $reply .= ' Business profit and bank cash do not automatically establish money available to the household. Preserve payroll, tax, debt and operating reserves; count a distribution as received only once it actually reaches the household.';
            $links[] = ['label' => 'Review business funding', 'tab' => 'business'];
        } elseif (preg_match('/upcoming|next.*bill|bill.*due|due.*bill|what.*owe/', $q)) {
            $bills = $support['upcoming_bills'] ?? [];
            $reply = $bills ? implode("\n", array_map(fn ($bill) => $bill['due_on'].' · '.$bill['name'].' · '.$money($bill['amount_cents']).' · '.$bill['confidence'], array_slice($bills, 0, 10))) : 'No verified upcoming bill schedule is available in this selected space. Review bill amounts, next unpaid due dates and debt terms before relying on the plan.';
            $reply .= "\n\nOnly recorded scheduled items are shown; missing bills and provisional estimates remain a coverage limit.";
            $links[] = ['label' => 'Review bills and due dates', 'tab' => 'bills'];
        } elseif (preg_match('/tax|deduct|accountant/', $q)) {
            $reply = $data['space']['kind'] === 'business' ? implode("\n\n", $data['tax_review']['notes']) : 'Select a business to review its tax profile and evidence. Your household categories alone do not establish a business deduction.';
            $links[] = ['label' => 'Open tax review', 'tab' => 'tax'];
        } elseif (preg_match('/debt|snowball|avalanche|interest|pay.?off/', $q)) {
            $d = $p['debt_strategy'];
            $reply = $d['status'] === 'setup_required' ? 'A complete payoff comparison needs these missing terms: '.implode('; ', $d['missing']).'.' : implode("\n", array_map(fn ($s) => ucfirst($s['method']).': '.($s['months'] === null ? 'not paid off within 50 years' : $s['months'].' months').'; projected interest '.$money($s['interest_cents']).'; payment to reach '.$d['target_months'].' months: '.$money($s['target_payment_cents']).'/month.', $d['strategies']));
            $links[] = ['label' => 'Compare payoff methods', 'tab' => 'planning'];
        } elseif (preg_match('/anomal|unusual|categor|mistake/', $q)) {
            $reply = count($data['anomalies']).' category mismatches have title-context or reviewed-history evidence to investigate. Each separates category fit, your usual treatment, and available business-practice guidance. Mark a legitimate exception as normal or apply the suggested category.';
            $links[] = ['label' => 'Review anomalies', 'tab' => 'anomalies'];
        } elseif (preg_match('/last year|borrow|previous year/', $q)) {
            $reply = 'Recorded spending for '.$p['last_year_from'].' through '.$p['last_year_through'].' is '.$money($p['last_year_spending_cents']).'. Recorded loan draws during the previous calendar year total '.$money($p['prior_year_borrowing_cents']).'. A draw does not by itself show why money was borrowed. Incomplete imports limit this comparison.';
            $links[] = ['label' => 'Open history and plans', 'tab' => 'planning'];
        } elseif (preg_match('/income|need|afford|cost|spend|trajectory|shortfall|cash|month/', $q) && isset($support['month'], $support['ongoing'])) {
            $month = $support['month'];
            $ongoing = $support['ongoing'];
            $reply = $month['label'].': received '.$money($month['received_cents']).', spent '.$money($month['spent_cents']).', and planned cash needs '.$money($month['needed_cents']).'. The recorded gap is '.$money($month['gap_cents']).'; after separately recorded metals proceeds it is '.$money($month['after_metals_gap_cents']).'. Ongoing monthly needs are '.$money($ongoing['monthly_needed_cents']).'. '.$ongoing['basis'];
            if (! $month['coverage_complete'] || ! $ongoing['coverage_complete']) {
                $reply .= ' Coverage is incomplete; these figures are recorded planning evidence, not a complete household income or lender DTI calculation.';
            }
            $reply .= ' Expected income is a scenario, distinct from cash already received. Full-month needs and partial-month actuals have different timing.';
            $links[] = ['label' => 'Review monthly plan', 'tab' => 'planning'];
        } elseif (preg_match('/income|need|afford|cost|spend|trajectory|shortfall|cash/', $q)) {
            $reply = 'Your expected monthly income is '.$money($p['expected_monthly_cents']).', starting from reviewed history plus your overrides. Current planned monthly needs are '.$money($p['required_monthly_cents']).': '.$money($p['fixed_monthly_cents']).' committed costs, '.$money($p['flexible_monthly_cents']).' adjustable spending, and '.$money($p['goals_monthly_cents']).' goals, plus '.$money($data['workspace_settings']['debt_extra_cents'] ?? 0).' extra debt payments. The estimated income gap is '.$money($p['income_gap_cents']).'. '.($p['provisional'] ? 'This is provisional because history or debt terms still need review.' : 'Review the assumptions before changing your plan.');
            $links[] = ['label' => 'Adjust income and scenarios', 'tab' => 'planning'];
        } else {
            $reply = 'I can explain the figures for '.$data['space']['name'].'. Ask about expected income, monthly costs, last year’s spending, borrowing, debt payoff, category anomalies, or tax review. I do not have enough context to answer that specific question yet.';
        }

        return [
            'reply' => $reply, 'links' => $links, 'proposals' => $proposals,
            'mode' => 'Bud Core · calculated from your selected finance space',
            'space_id' => $data['space']['id'],
            'scope' => ['kind' => $data['space']['kind'], 'range' => $data['range'], 'provisional' => (bool) ($data['coverage']['provisional'] ?? true)],
            'execution' => 'No changes made. Proposals require review and the existing authorized save action.',
        ];
    }

    /** Minimal server-calculated financial facts for a separately authorized provider. */
    public function financialContext(array $data): array
    {
        $pick = fn (array $value, array $keys): array => array_intersect_key($value, array_flip($keys));
        $records = $this->records($data);
        $lots = array_map(fn (array $lot): array => $pick($lot, ['id', 'name', 'metal', 'quantity', 'weight', 'unit', 'weight_basis', 'purity_bps', 'acquired_on', 'cost_basis_cents', 'fine_troy_ounces', 'value_cents', 'gain_cents', 'resale_value_cents', 'resale_adjustment_cents']), $data['metals'] ?? []);
        $quotes = [];
        foreach ($data['quotes']['prices'] ?? [] as $metal => $quote) {
            if (in_array($metal, ['gold', 'silver'], true)) {
                $quotes[$metal] = $pick($quote, ['price_cents', 'timestamp', 'source', 'unit']);
            }
        }
        $fresh = ($data['quotes']['status'] ?? '') === 'current' && count($quotes) > 0 && count($lots) > 0;
        foreach ($quotes as $quote) {
            $fresh = $fresh && ($quote['price_cents'] ?? 0) > 0 && ($quote['timestamp'] ?? 0) >= time() - 180 && ($quote['timestamp'] ?? PHP_INT_MAX) <= time() + 60;
        }
        foreach ($lots as $lot) {
            $fresh = $fresh && isset($quotes[$lot['metal'] ?? '']);
        }

        $context = [
            'space' => $pick($data['space'], ['id', 'name', 'kind', 'timezone']),
            'range' => $data['range'],
            'coverage' => $pick($data['coverage'] ?? [], ['provisional', 'history_days', 'blockers']),
            'summary' => $pick($data['summary'] ?? [], ['income_cents', 'spending_cents', 'cash_cents', 'net_worth_cents', 'net_worth_complete']),
            'planning' => $pick($data['planning'] ?? [], ['expected_monthly_cents', 'required_monthly_cents', 'income_gap_cents', 'fixed_monthly_cents', 'flexible_monthly_cents', 'goals_monthly_cents', 'provisional', 'monthly', 'last_year_spending_cents', 'last_year_from', 'last_year_through', 'assumptions']),
            'period_comparison' => $data['period_comparison'] ?? null,
            'decision_support' => $data['decision_support'] ?? null,
            'budgets' => array_values(array_map(fn (array $record): array => $pick($record, ['id', 'space_id', 'kind', 'name', 'version', 'data']), array_filter($records, fn (array $record): bool => $record['kind'] === 'budget'))),
            'metals' => ['lots' => $lots, 'quotes' => $quotes, 'quote_status' => $data['quotes']['status'] ?? 'unavailable', 'quotes_usable_now' => $fresh, 'basis_status' => 'Recorded lot costs; completeness and independent verification are not established.', 'execution' => 'Read-only planning. No trade, transfer or future price guarantee.'],
            'constraints' => [
                'Only this authorized finance space and explicitly authorized linked estimates are included.',
                'Projections, proposed software revenue and expected distributions are not collected cash or qualifying income.',
                'Asset sales, borrowing, transfers, reimbursements and recurring earned income remain distinct.',
                'Budget changes require an explicit draft review and existing scoped/versioned save; no trade or transfer can be executed.',
            ],
        ];
        if (! empty($data['scope_filter']['account_id'])) {
            unset($context['planning'], $context['decision_support'], $context['budgets'], $context['metals']);
            $context['scope_filter'] = ['account_id' => $data['scope_filter']['account_id'], 'limitation' => 'Single account only; no household-wide plan, budgets, metals or business distributions.'];
        }

        return $context;
    }

    /** Parse explicit user targets, never model-created amounts or implicit spending cuts. */
    public function budgetProposals(string $question, array $data): array
    {
        if (! empty($data['scope_filter']['account_id'])) {
            return [];
        }
        if (preg_match_all('/\b(?:set|change|update)\b/i', $question) > 1) {
            return [];
        }
        if (preg_match('/\b(weekly|daily|quarterly|yearly|annual|per week|per day|per year)\b/i', $question)) {
            return [];
        }
        $pattern = '/\b(?:set|change|update)\s+(?:(?:(?:the|my|our)\s+)?budget\s+for\s+([a-z][a-z _-]{1,60})|(?:(?:the|my|our)\s+)?([a-z][a-z _-]{1,60})\s+budget)\s+to\s+\$?([0-9]{1,9}(?:\.[0-9]{1,2})?)(?:\s*(?:per\s+month|monthly))?\s*[.!?]?$/i';
        if (! preg_match($pattern, trim($question), $match)) {
            return [];
        }
        $requested = $this->categoryName(trim($match[1] ?: $match[2]));
        $categories = array_values(array_filter($data['category_options'] ?? [], fn ($category): bool => is_string($category) && $this->categoryName($category) === $requested));
        if (count($categories) !== 1) {
            return [];
        }
        $category = $categories[0];
        if (in_array($category, ['income', 'owner_wages', 'owner_distribution', 'loan_draw', 'asset_sale', 'transfer', 'card_payment', 'debt_payment'], true)) {
            return [];
        }
        $spaceId = $data['space']['id'];
        $matches = array_values(array_filter($this->records($data), fn (array $record): bool => $record['kind'] === 'budget' && ($record['space_id'] ?? null) === $spaceId && ($record['data']['category'] ?? null) === $category && ($record['data']['purpose'] ?? null) === 'spending'));
        if (count($matches) > 1) {
            return [];
        }
        $record = $matches[0] ?? null;
        if ($record && ! isset($record['version'])) {
            return [];
        }
        $cents = Money::cents($match[3]);
        $effective = now($data['space']['timezone'] ?? 'UTC')->toDateString();
        $draft = [
            ...($record['data'] ?? []), 'category' => $category, 'monthly_cents' => $cents,
            'purpose' => 'spending', 'effective_on' => $effective,
            'source' => 'Explicit user-requested monthly target; review before saving.', 'reviewed' => false,
        ];

        return [[
            'type' => 'budget', 'mode' => $record ? 'update' : 'create', 'space_id' => $spaceId,
            'record_id' => $record['id'] ?? null, 'expected_version' => $record['version'] ?? null,
            'name' => $record['name'] ?? ucfirst(str_replace('_', ' ', $category)).' monthly budget',
            'data' => $draft, 'requires_review' => true,
            'changes' => ['monthly_cents' => ['before' => $record['data']['monthly_cents'] ?? null, 'after' => $cents]],
        ]];
    }

    private function categoryName(string $category): string
    {
        return mb_strtolower(trim(preg_replace('/[_\s-]+/', ' ', $category)));
    }

    private function records(array $data): array
    {
        $records = [];
        foreach ($data['records'] ?? [] as $record) {
            $row = is_array($record) ? $record : $record->toArray();
            if (($row['space_id'] ?? null) === $data['space']['id'] && ($row['active'] ?? true)) {
                $records[] = $row;
            }
        }

        return $records;
    }
}
