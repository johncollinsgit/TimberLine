<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use Carbon\CarbonImmutable;

/** Builds one authorized space's inputs; no balances or payment instructions are changed. */
class DebtScenarioSourceService
{
    public function build(Space $space, int $extraCents = 0, ?int $accountId = null): array
    {
        $today = CarbonImmutable::now($space->timezone)->startOfDay();
        $accounts = Account::where('space_id', $space->id)->get();
        if ($accountId !== null) {
            abort_unless($accounts->contains('id', $accountId), 404);
            $accounts = $accounts->where('id', $accountId);
        }
        $records = Record::where('space_id', $space->id)->where('active', true)->get();
        $terms = $records->where('kind', 'debt')->filter(fn ($r) => $r->data['confirmed'] ?? false);
        $debts = [];
        $missing = [];
        $mapped = [];
        foreach ($terms as $record) {
            $d = $record->data;
            $account = $accounts->firstWhere('id', $d['account_id'] ?? null);
            if ($accountId !== null && ! $account) {
                continue;
            }
            if (isset($d['account_id']) && ! $account) {
                $missing[] = $record->name.' — confirm its account in this space';

                continue;
            }
            $balance = $account ? $account->balance_cents : ($d['balance_cents'] ?? null);
            if ($account) {
                $mapped[] = $account->id;
                if ($balance !== 0 && (! $account->observed_at || $account->observed_at->lt($today->subDays(2)) || $account->observed_at->gt($today->endOfDay()))) {
                    $missing[] = $record->name.' — refresh its dated balance';
                }
            }
            if (! $account && $balance !== 0) {
                $observed = filled($d['observed_on'] ?? null) ? CarbonImmutable::parse($d['observed_on'], $space->timezone)->startOfDay() : null;
                if (! $observed || $observed->lt($today->subDays(2)) || $observed->gt($today)) {
                    $missing[] = $record->name.' — verify its current dated principal balance';
                }
            }
            $debts[] = [...$d, 'id' => $record->id, 'name' => $record->name, 'balance_cents' => $balance,
                'rate_millis' => $d['rate_millis'] ?? (isset($d['apr_bps']) ? $d['apr_bps'] * 10 : null)];
        }
        foreach ($accounts->whereIn('kind', ['credit', 'loan']) as $account) {
            if ($account->balance_cents === 0 || in_array($account->id, $mapped, true)) {
                continue;
            }
            $missing[] = $account->name.' — review principal balance, APR, required payment and next unpaid due date';
            $debts[] = ['id' => 'account-'.$account->id, 'account_id' => $account->id, 'name' => $account->name, 'balance_cents' => $account->balance_cents];
        }
        if ($space->kind === 'household' && $accountId === null) {
            foreach (app(MedicalSharingService::class)->summary($space, $records, $today)['debts'] as $medical) {
                // Overview cash timing can provisionally shift an overdue bill to
                // tomorrow. A payoff scenario must retain the actual unpaid date.
                $bill = $records->where('kind', 'medical_bill')->firstWhere('id', $medical['id']);
                $source = $bill?->data ?? [];
                $due = $source['next_due_on'] ?? null;
                $parsed = is_string($due) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $due) : false;
                if ($medical['balance_cents'] > 0 && (! ($source['confirmed'] ?? false)
                    || ! $parsed || $parsed->format('Y-m-d') !== $due || $due < $today->toDateString()
                    || ($source['payment_cents'] ?? 0) <= 0)) {
                    $missing[] = $medical['name'].' — verify its confirmed payment and actual next unpaid due date';
                }
                $debts[] = [...$medical, 'id' => 'medical-'.$medical['id'], 'rate_millis' => 0,
                    'next_due_on' => $due,
                    'payment_cadence' => 'monthly', 'interest_method' => 'monthly'];
            }
        }
        // Having terms for every recorded account does not establish a full debt inventory.
        $complete = false;

        return [...app(DebtScenarioService::class)->compare($debts, $today, $extraCents, $missing, $complete),
            'space_id' => $space->id, 'space_kind' => $space->kind, 'account_id' => $accountId,
            'scope_label' => $accountId === null ? $space->name.' · recorded debts' : 'Selected account · recorded debt',
            'coverage_note' => 'Only recorded debts are included. Completeness of every household or business obligation is not independently established.'];
    }
}
