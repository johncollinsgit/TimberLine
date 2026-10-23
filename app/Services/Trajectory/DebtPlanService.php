<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DebtPlanService
{
    public function inventory(Space $space, Collection $records, Collection $accounts): array
    {
        $monthly = fn (int $amount, string $cadence = 'monthly') => app(PlanningService::class)->monthly($amount, $cadence);
        $terms = $records->where('kind', 'debt');
        $rows = [];
        foreach ($accounts->whereIn('kind', ['credit', 'loan']) as $account) {
            $term = $terms->first(fn ($r) => ($r->data['account_id'] ?? null) === $account->id);
            $d = $term?->data ?? [];
            $rows[] = ['key' => 'account:'.$account->id, 'id' => $term?->id, 'account_id' => $account->id, 'name' => $account->name,
                'kind' => $d['kind'] ?? $account->kind, 'balance_cents' => $account->balance_cents === null ? null : max(0, $account->balance_cents),
                'observed_on' => $account->observed_at?->toDateString(), 'rate_millis' => ($d['confirmed'] ?? false) ? ($d['rate_millis'] ?? $d['apr_bps'] * 10) : null,
                'minimum_cents' => ($d['confirmed'] ?? false) ? $monthly($d['payment_cents'], $d['payment_cadence'] ?? 'monthly') : null];
        }
        foreach ($terms->filter(fn ($r) => empty($r->data['account_id'])) as $term) {
            $d = $term->data;
            $rows[] = ['key' => 'debt:'.$term->id, 'id' => $term->id, 'account_id' => null, 'name' => $term->name, 'kind' => $d['kind'],
                'balance_cents' => max(0, $d['balance_cents']), 'observed_on' => $d['observed_on'],
                'rate_millis' => $d['confirmed'] ? ($d['rate_millis'] ?? $d['apr_bps'] * 10) : null,
                'minimum_cents' => $d['confirmed'] ? $monthly($d['payment_cents'], $d['payment_cadence'] ?? 'monthly') : null];
        }
        foreach (app(MedicalSharingService::class)->summary($space, $records, CarbonImmutable::now($space->timezone))['debts'] as $debt) {
            $rows[] = ['key' => 'medical:'.$debt['id'], 'id' => $debt['id'], 'account_id' => null, 'name' => $debt['name'], 'kind' => 'medical',
                'balance_cents' => $debt['balance_cents'], 'observed_on' => now($space->timezone)->toDateString(), 'rate_millis' => 0,
                'minimum_cents' => $debt['payment_cents'] > 0 || $debt['balance_cents'] === 0 ? $debt['payment_cents'] : null];
        }

        return $rows;
    }

    public function build(Space $space, Collection $records, Collection $accounts, array $costs): array
    {
        $inventory = collect($this->inventory($space, $records, $accounts));
        $saved = $space->settings['debt_plan'] ?? null;
        $keys = $saved['included_keys'] ?? $inventory->where('kind', '!=', 'mortgage')->pluck('key')->all();
        $selected = $inventory->whereIn('key', $keys)->values();
        $missing = array_map(fn ($key) => 'Review missing debt '.$key.' before updating the plan.', array_values(array_diff($keys, $inventory->pluck('key')->all())));
        $balanceComplete = $selected->every(fn ($d) => $d['balance_cents'] !== null) && count($missing) === 0;
        $stale = $selected->filter(fn ($d) => ! $d['observed_on'] || $d['observed_on'] < now($space->timezone)->subDays(2)->toDateString())->pluck('name')->all();
        foreach ($selected as $debt) {
            if ($debt['balance_cents'] === null || ($debt['balance_cents'] > 0 && ($debt['rate_millis'] === null || $debt['minimum_cents'] === null))) {
                $missing[] = $debt['name'].' — review balance, rate and minimum payment.';
            }
        }
        $method = $saved['method'] ?? 'avalanche';
        $target = $saved['target_months'] ?? $space->settings['debt_target_months'] ?? 36;
        $targetOn = $saved['target_on'] ?? null;
        $remainingMonths = $targetOn ? max(1, (int) ceil(CarbonImmutable::now($space->timezone)->startOfDay()->diffInMonths(CarbonImmutable::parse($targetOn, $space->timezone), false))) : $target;
        $targetOverdue = $targetOn && $targetOn < now($space->timezone)->toDateString();
        $extra = $saved['extra_monthly_cents'] ?? $space->settings['debt_extra_cents'] ?? 0;
        $linkedGoals = (int) $records->where('kind', 'goal')->filter(fn ($r) => ($r->data['goal_type'] ?? '') === 'debt_paydown' && $selected->pluck('id')->contains($r->data['debt_record_id'] ?? null) && $r->data['saved_cents'] < $r->data['target_cents'])->sum(fn ($r) => $r->data['monthly_cents']);
        $strategy = app(DebtStrategyService::class)->compare($selected->filter(fn ($d) => $d['balance_cents'] > 0)->all(), $extra + $linkedGoals, $remainingMonths, $missing);
        $chosen = collect($strategy['strategies'])->firstWhere('method', $method);
        $minimum = (int) $selected->where('balance_cents', '>', 0)->sum('minimum_cents');
        $payment = $chosen ? ($saved ? max($chosen['monthly_budget_cents'], $chosen['target_payment_cents']) : $chosen['monthly_budget_cents']) : null;
        if ($payment !== null) {
            $ceiling = (int) $selected->sum(fn ($d) => max(0, $d['balance_cents'] ?? 0) + Money::ratio(max(0, $d['balance_cents'] ?? 0), $d['rate_millis'] ?? 0, 1200000));
            $payment = min($payment, $ceiling);
            $strategy['strategies'] = array_map(fn ($s) => [...app(DebtStrategyService::class)->simulate($selected->filter(fn ($d) => $d['balance_cents'] > 0)->all(), $payment, $s['method']), 'method' => $s['method'], 'target_payment_cents' => $s['target_payment_cents'], 'extra_to_target_cents' => $s['extra_to_target_cents']], $strategy['strategies']);
            $strategy['extra_monthly_cents'] = max(0, $payment - $minimum);
        }
        $projection = $payment === null ? null : app(DebtStrategyService::class)->simulate($selected->filter(fn ($d) => $d['balance_cents'] > 0)->all(), $payment, $method);
        $ongoing = max(0, $costs['fixed_monthly_cents'] + $costs['flexible_monthly_cents'] + $costs['goals_monthly_cents'] - $minimum - $linkedGoals);
        $excluded = $inventory->whereNotIn('key', $keys)->values();
        $costGaps = $excluded->filter(fn ($d) => $d['balance_cents'] === null || ($d['balance_cents'] > 0 && $d['minimum_cents'] === null))->map(fn ($d) => $d['name'].' — its ongoing payment is not yet known, even though it is outside this payoff goal.')->all();
        $required = $payment === null || $costGaps ? null : $ongoing + $payment;
        $remaining = $balanceComplete ? (int) $selected->sum('balance_cents') : null;
        $start = $saved ? array_sum($saved['starting_balances']) : null;
        $reduction = $start !== null && $remaining !== null ? $start - $remaining : null;
        $payoff = $projection && $projection['months'] !== null ? CarbonImmutable::now($space->timezone)->addMonthsNoOverflow($projection['months'])->toDateString() : null;

        return ['space_id' => $space->id, 'space_name' => $space->name, 'target_on' => $targetOn, 'remaining_target_months' => $remainingMonths, 'target_overdue' => (bool) $targetOverdue, 'version' => $saved['version'] ?? 0, 'saved' => $saved !== null, 'started_on' => $saved['started_on'] ?? null, 'starting_dates' => $saved['starting_dates'] ?? [],
            'included_keys' => $keys, 'inventory' => $inventory->all(), 'excluded_debts' => $excluded->all(), 'method' => $method, 'target_months' => $target, 'extra_monthly_cents' => $extra,
            'starting_cents' => $start, 'remaining_cents' => $remaining, 'reduction_cents' => $reduction,
            'progress_percent' => $reduction === null ? null : ($start > 0 ? max(0, min(100, round($reduction * 100 / $start, 1))) : ($remaining === 0 ? 100 : 0)),
            'balance_complete' => $balanceComplete, 'stale_accounts' => $stale, 'missing' => [...$missing, ...$costGaps], 'provisional' => ! $balanceComplete || count($stale) > 0 || count($missing) > 0 || count($costGaps) > 0 || $targetOverdue || ($costs['history_provisional'] ?? false),
            'minimum_monthly_cents' => $minimum, 'payment_monthly_cents' => $payment, 'extra_above_minimum_cents' => $payment === null ? null : max(0, $payment - $minimum),
            'ongoing_monthly_cents' => $ongoing, 'required_monthly_cents' => $required, 'expected_monthly_cents' => $costs['expected_monthly_cents'],
            'income_gap_cents' => $required === null ? null : max(0, $required - $costs['expected_monthly_cents']),
            'payoff_on' => $payoff, 'projected_interest_cents' => $projection['interest_cents'] ?? null, 'strategy' => $strategy,
            'assumption' => 'Fixed reviewed rates and no new unfunded debt. Living costs fund ongoing purchases; debt repayments are not a second expense. Daily cash timing uses separate assumptions.'];
    }

    public function save(User $user, Space $space, array $input): array
    {
        app(WorkspaceService::class)->manage($user, $space);

        return DB::transaction(function () use ($user, $space, $input) {
            $space = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $inventory = collect($this->inventory($space, Record::where('space_id', $space->id)->where('active', true)->get(), Account::where('space_id', $space->id)->get()))->keyBy('key');
            $data = Validator::make($input, ['version' => 'required|integer|min:0', 'included_keys' => 'present|array|max:200', 'included_keys.*' => ['required', 'string', 'distinct', Rule::in($inventory->keys()->all())], 'method' => 'required|in:avalanche,snowball', 'target_months' => 'required|integer|min:1|max:600', 'extra_monthly_cents' => 'required|integer|min:0|max:100000000000'])->validate();
            $old = $space->settings['debt_plan'] ?? null;
            abort_unless(($old['version'] ?? 0) === $data['version'], 409, 'The debt plan changed. Refresh before saving.');
            $starting = [];
            $startingDates = [];
            foreach ($data['included_keys'] as $key) {
                abort_if($inventory[$key]['balance_cents'] === null, 422, 'Record a balance for '.$inventory[$key]['name'].' before starting its thermometer.');
                $starting[$key] = $old['starting_balances'][$key] ?? $inventory[$key]['balance_cents'];
                $startingDates[$key] = $old['starting_dates'][$key] ?? $inventory[$key]['observed_on'] ?? now($space->timezone)->toDateString();
            }
            $targetOn = $old && ($old['target_months'] ?? null) === $data['target_months'] ? ($old['target_on'] ?? null) : null;
            $targetOn ??= CarbonImmutable::now($space->timezone)->addMonthsNoOverflow($data['target_months'])->toDateString();
            $plan = [...$data, 'target_on' => $targetOn, 'starting_balances' => $starting, 'starting_dates' => $startingDates, 'started_on' => $old['started_on'] ?? now($space->timezone)->toDateString(), 'version' => $data['version'] + 1];
            $space->update(['settings' => [...($space->settings ?? []), 'debt_plan' => $plan, 'debt_extra_cents' => $data['extra_monthly_cents'], 'debt_target_months' => $data['target_months']]]);
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'debt_plan_saved', 'before' => $old, 'after' => $plan]);

            return $plan;
        });
    }

    public function combine(array $personal, array $business): array
    {
        $sum = fn ($field) => $personal[$field] === null || $business[$field] === null ? null : $personal[$field] + $business[$field];
        $start = $sum('starting_cents');
        $remaining = $sum('remaining_cents');
        $reduction = $start === null || $remaining === null ? null : $start - $remaining;

        return ['saved' => $personal['saved'] && $business['saved'], 'starting_cents' => $start, 'remaining_cents' => $remaining, 'reduction_cents' => $reduction,
            'progress_percent' => $reduction === null ? null : ($start > 0 ? max(0, min(100, round($reduction * 100 / $start, 1))) : ($remaining === 0 ? 100 : 0)),
            'payoff_on' => $personal['payoff_on'] && $business['payoff_on'] ? max($personal['payoff_on'], $business['payoff_on']) : null,
            'payment_monthly_cents' => $sum('payment_monthly_cents'), 'extra_above_minimum_cents' => $sum('extra_above_minimum_cents'), 'projected_interest_cents' => $sum('projected_interest_cents'),
            'required_monthly_cents' => $sum('required_monthly_cents'), 'expected_monthly_cents' => $sum('expected_monthly_cents'), 'income_gap_cents' => $sum('income_gap_cents'),
            'provisional' => $personal['provisional'] || $business['provisional'], 'missing' => [...$personal['missing'], ...$business['missing']], 'stale_accounts' => [...$personal['stale_accounts'], ...$business['stale_accounts']],
            'baseline_dates' => [$personal['started_on'], $business['started_on']], 'assumption' => 'Summary of separate Personal and Business plans; payments remain allocated to their original space.'];
    }
}
