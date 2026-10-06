<?php

namespace App\Services\Bud;

class TrajectoryBudService
{
    /** The authorized controller supplies this context; never accept client figures. */
    public function respond(string $question, array $data): array
    {
        $q = mb_strtolower($question);
        $p = $data['planning'];
        $money = fn ($v) => $v === null ? 'not yet available' : '$'.number_format($v / 100, 2);
        $links = [];
        if (preg_match('/tax|deduct|accountant/', $q)) {
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
        } elseif (preg_match('/income|need|afford|cost|spend|trajectory|shortfall|cash/', $q)) {
            $reply = 'Your expected monthly income is '.$money($p['expected_monthly_cents']).', starting from reviewed history plus your overrides. Current planned monthly needs are '.$money($p['required_monthly_cents']).': '.$money($p['fixed_monthly_cents']).' committed costs, '.$money($p['flexible_monthly_cents']).' adjustable spending, and '.$money($p['goals_monthly_cents']).' goals, plus '.$money($data['workspace_settings']['debt_extra_cents'] ?? 0).' extra debt payments. The estimated income gap is '.$money($p['income_gap_cents']).'. '.($p['provisional'] ? 'This is provisional because history or debt terms still need review.' : 'Review the assumptions before changing your plan.');
            $links[] = ['label' => 'Adjust income and scenarios', 'tab' => 'planning'];
        } else {
            $reply = 'I can explain the figures for '.$data['space']['name'].'. Ask about expected income, monthly costs, last year’s spending, borrowing, debt payoff, category anomalies, or tax review. I do not have enough context to answer that specific question yet.';
        }

        return ['reply' => $reply, 'links' => $links, 'mode' => 'Bud Core · calculated from your selected finance space', 'space_id' => $data['space']['id']];
    }
}
