<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Cumulative lender context only. Never a period charge, cash payment or projection input. */
class LenderInterestYtdService
{
    public function valid(array $data, string $today): bool
    {
        foreach (['reported_through', 'observed_on'] as $key) {
            $date = $data[$key] ?? null;
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                return false;
            }
        }

        return is_int($data['reported_year'] ?? null) && $data['reported_year'] >= 1900
            && $data['reported_year'] === (int) substr($data['reported_through'], 0, 4)
            && $data['reported_through'] <= $data['observed_on'] && $data['observed_on'] <= $today
            && is_int($data['interest_charged_cents'] ?? null) && $data['interest_charged_cents'] >= 0 && $data['interest_charged_cents'] <= 100000000000
            && is_string($data['currency'] ?? null) && (bool) preg_match('/^[A-Z]{3}$/D', $data['currency'])
            && is_string($data['source_sha256'] ?? null) && (bool) preg_match('/^[a-f0-9]{64}$/D', $data['source_sha256'])
            && is_string($data['source_id'] ?? null) && trim($data['source_id']) !== ''
            && is_string($data['source'] ?? null) && trim($data['source']) !== '' && is_bool($data['reviewed'] ?? null);
    }

    public function build(Space $space, Collection $accounts, Collection $records, string $from, string $through, ?User $viewer): ?array
    {
        if (! $viewer || ! app(FinanceAccess::class)->allows($viewer, $space)) {
            return null;
        }
        $today = CarbonImmutable::now($space->timezone)->toDateString();
        $available = $from <= $today;
        $cutoff = $available ? min($through, $today) : $through;
        $defaultYear = (int) substr($cutoff, 0, 4);
        $owned = $accounts->where('space_id', $space->id)->where('kind', 'credit')->sortBy('id');
        $eligible = $records->filter(fn ($r) => $available && $r->space_id === $space->id && $r->kind === 'lender_interest_ytd' && $r->active
            && $owned->contains('id', $r->data['account_id'] ?? null) && $this->valid($r->data, $today) && $r->data['reported_through'] <= $cutoff);
        $years = collect($eligible->map(fn ($r) => $r->data['reported_year'])->values()->all())->push($defaultYear)->unique()->sortDesc()->values();
        $reports = [];
        foreach ($years as $year) {
            $rows = [];
            foreach ($owned as $account) {
                $candidates = $eligible->filter(fn ($r) => $r->data['account_id'] === $account->id && $r->data['reported_year'] === $year && $r->data['reviewed'] === true);
                $latest = $candidates->isEmpty() ? collect() : $candidates->filter(fn ($r) => $r->data['reported_through'] === $candidates->max(fn ($r) => $r->data['reported_through']));
                $values = collect($latest->map(fn ($r) => $r->data['interest_charged_cents'])->values()->all());
                $conflict = collect($latest->map(fn ($r) => $r->data['currency'].':'.$r->data['interest_charged_cents'])->values()->all())->uniqueStrict()->count() > 1;
                $currency = $latest->isNotEmpty() ? $latest->first()->data['currency'] : null;
                $currencyMismatch = $currency !== null && $currency !== $account->currency;
                $amount = $currency && ! $conflict && ! $currencyMismatch && $values->isNotEmpty() ? $values->first() : null;
                $rows[] = ['account_id' => $account->id, 'account' => $account->name, 'currency' => $currency,
                    'reported_year' => $year, 'reported_through' => $latest->isNotEmpty() ? $latest->first()->data['reported_through'] : null,
                    'interest_charged_cents' => $amount, 'conflict' => $conflict,
                    'issue' => $conflict ? 'Conflicting lender totals for the same cutoff.' : ($currencyMismatch ? 'Statement currency no longer matches this account.' : ($amount === null ? 'No usable reviewed lender total at this cutoff.' : null)),
                    'sources' => $latest->map(fn ($r) => ['record_id' => $r->id, 'name' => $r->name, 'source' => $r->data['source'], 'source_id' => $r->data['source_id'], 'source_sha256' => $r->data['source_sha256'], 'observed_on' => $r->data['observed_on']])->values()->all()];
            }
            $known = collect($rows)->whereNotNull('interest_charged_cents');
            $currencies = $known->pluck('currency')->unique()->values();
            $reports[] = ['year' => $year, 'rows' => $rows, 'reported_accounts' => $known->count(), 'owned_accounts' => $owned->count(),
                'currency' => $currencies->count() === 1 ? $currencies->first() : null,
                'reported_subset_cents' => $known->isNotEmpty() && $currencies->count() === 1 ? (int) $known->sum('interest_charged_cents') : null,
                'different_cutoffs' => $known->pluck('reported_through')->unique()->count() > 1, 'different_currencies' => $currencies->count() > 1];
        }

        return ['available' => $available, 'cutoff' => $cutoff, 'default_year' => $defaultYear, 'years' => $reports,
            'note' => 'Calendar-year cumulative lender reports, separate from selected-period charges, cash interest paid and future estimates. Other lenders and charges after each statement cutoff may be missing.'];
    }
}
