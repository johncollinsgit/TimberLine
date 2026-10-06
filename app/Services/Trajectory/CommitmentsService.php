<?php

namespace App\Services\Trajectory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CommitmentsService
{
    public function summary(Collection $records, Collection $entries, Collection $accounts, CarbonImmutable $today): array
    {
        $subscriptions = [];
        foreach ($records->where('kind', 'recurring') as $r) {
            $d = $r->data;
            if ($d['category'] !== 'subscriptions' || $d['amount_cents'] >= 0) {
                continue;
            }
            $monthly = match ($d['cadence']) {
                'monthly' => -$d['amount_cents'], 'weekly' => Money::ratio(-$d['amount_cents'], 52, 12),
                'biweekly' => Money::ratio(-$d['amount_cents'], 26, 12), 'quarterly' => Money::ratio(-$d['amount_cents'], 1, 3), 'yearly' => Money::ratio(-$d['amount_cents'], 1, 12), default => null,
            };
            $subscriptions[] = ['id' => $r->id, 'name' => $r->name, 'merchant' => $d['merchant'], 'account_id' => $d['account_id'], 'amount_cents' => -$d['amount_cents'], 'monthly_cents' => $monthly, 'cadence' => $d['cadence'], 'next_due_on' => $d['next_due_on'], 'confirmed' => $d['confirmed'], 'evidence_ids' => [], 'source' => 'Your recurring schedule'];
        }
        foreach ($entries->filter(fn ($e) => $e['category'] === 'subscriptions' && $e['amount_cents'] < 0 && $e['editable'] && ! $e['face_punched'] && $e['flow'] === 'expense')->groupBy(fn ($e) => $e['account_id'].':'.ClassificationService::merchant($e['merchant'])) as $rows) {
            $last = $rows->sortBy('date')->last();
            if (collect($subscriptions)->contains(fn ($s) => $s['account_id'] === $last['account_id'] && ClassificationService::merchant($s['merchant']) === ClassificationService::merchant($last['merchant']))) {
                continue;
            }
            $subscriptions[] = ['id' => null, 'name' => $last['merchant'], 'merchant' => $last['merchant'], 'account_id' => $last['account_id'], 'amount_cents' => -$last['amount_cents'], 'monthly_cents' => null, 'cadence' => 'unknown', 'next_due_on' => null, 'last_paid_on' => $last['date'], 'confirmed' => false, 'evidence_ids' => $rows->pluck('id')->all(), 'source' => 'Imported subscription category; active status and renewal need review'];
        }
        foreach ($records->where('kind', 'subscription') as $record) {
            $d = $record->data;
            $subscriptions[] = ['id' => $record->id, 'name' => $record->name, 'merchant' => $d['merchant'], 'account_id' => $d['account_id'] ?? null, 'amount_cents' => $d['amount_cents'] ?? null, 'monthly_cents' => null, 'cadence' => $d['cadence'], 'next_due_on' => null, 'last_paid_on' => null, 'confirmed' => false, 'status' => $d['status'], 'evidence_ids' => [], 'source' => $d['source']];
        }
        $payments = [];
        foreach ($records->where('kind', 'payment_notice') as $r) {
            $payments[] = ['id' => $r->id, 'name' => $r->name, ...$r->data, 'status' => $r->data['due_on'] < $today->toDateString() ? 'past_due_review' : 'upcoming'];
        }
        foreach ($records->where('kind', 'debt') as $r) {
            $d = $r->data;
            if ($d['balance_cents'] <= 0 || collect($payments)->contains(fn ($p) => ($p['account_id'] ?? null) === ($d['account_id'] ?? -1))) {
                continue;
            }
            $payments[] = ['id' => $r->id, 'name' => $r->name, 'account_id' => $d['account_id'] ?? null, 'payment_account_id' => $d['payment_account_id'] ?? null, 'amount_cents' => $d['cash_payment_cents'] ?? ($d['payment_cents'] + $d['escrow_cents'] + $d['fees_cents']), 'due_on' => $d['cash_next_due_on'] ?? $d['next_due_on'], 'confirmed' => $d['confirmed'], 'source' => 'Debt payment plan', 'status' => $d['next_due_on'] < $today->toDateString() ? 'past_due_review' : 'upcoming'];
        }
        foreach ($accounts->whereIn('kind', ['credit', 'loan'])->where('balance_cents', '>', 0) as $a) {
            if (collect($payments)->contains('account_id', $a->id)) {
                continue;
            }
            $payments[] = ['id' => null, 'name' => $a->name, 'account_id' => $a->id, 'payment_account_id' => null, 'amount_cents' => null, 'due_on' => null, 'confirmed' => false, 'source' => 'Payment terms missing', 'status' => 'setup_required'];
        }
        usort($payments, fn ($a, $b) => strcmp($a['due_on'] ?? '9999', $b['due_on'] ?? '9999'));

        return ['subscriptions' => $subscriptions, 'subscription_monthly_cents' => (int) collect($subscriptions)->where('confirmed', true)->sum('monthly_cents'), 'payments' => $payments];
    }
}
