<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use Illuminate\Support\Collection;

/** Bank remittances and filing evidence; never a sales-tax liability calculation. */
class SalesTaxService
{
    public function validateRemittance(Space $space, array $data): string
    {
        $tx = Transaction::where('space_id', $space->id)->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
        abort_unless(! $tx->pending && ! $tx->removed && $tx->amount_cents === $data['amount_cents'] && $tx->posted_on->toDateString() === $data['paid_on'], 422, 'Use the posted bank date and exact signed amount.');
        abort_unless($data['amount_cents'] !== 0 && ($data['movement'] === 'payment' ? $data['amount_cents'] < 0 : $data['amount_cents'] > 0), 422, 'Payments must be negative; returns and refunds must be positive bank credits.');
        $components = array_intersect_key($data, array_flip(['tax_principal_cents', 'interest_cents', 'penalty_cents', 'other_fees_cents']));
        $supplied = array_filter($components, fn ($value) => $value !== null);
        abort_unless($supplied === [] || (count($supplied) === 4 && min($supplied) >= 0 && array_sum($supplied) === abs($data['amount_cents'])), 422, 'Supply all four nonnegative components summing to the bank amount, or leave the entire split unknown.');
        $start = $data['filing_start'] ?? null;
        $end = $data['filing_end'] ?? null;
        abort_unless(($start === null && $end === null) || ($start !== null && $end !== null && $start <= $end), 422, 'Supply both filing dates in chronological order, or leave both unknown.');
        abort_unless($data['paid_on'] <= now($space->timezone)->toDateString(), 422, 'Future payments are not posted bank evidence.');

        $fingerprint = $this->bankFingerprint($space->id, $tx->account_id, $tx->id, $tx->posted_on->toDateString(), $tx->amount_cents, $tx->merchant);
        abort_unless(hash_equals($fingerprint, $data['expected_bank_source_fingerprint']), 409, 'Bank source changed. Refresh and review the current bank evidence before saving tax details.');

        return $fingerprint;
    }

    public function bankFingerprint(int $spaceId, int $accountId, int $transactionId, string $date, int $amount, string $merchant): string
    {
        return hash('sha256', json_encode([$spaceId, $accountId, $transactionId, $date, $amount, $merchant], JSON_THROW_ON_ERROR));
    }

    public function build(Space $space, Collection $entries, Collection $records, Collection $accounts): array
    {
        $evidence = $records->filter(fn ($r) => $r->space_id === $space->id && $r->active === true && $r->kind === 'tax_remittance')->groupBy(fn ($r) => $r->data['transaction_id'] ?? 0);
        $owned = $accounts->filter(fn ($a) => $a->space_id === $space->id)->keyBy('id');
        $rows = [];
        $seen = [];
        $paid = $returned = $refunded = 0;
        $confirmed = 0;
        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            $account = $owned->get($entry['account_id'] ?? null);
            if (! is_int($id) || isset($seen[$id]) || ! $account || ($entry['editable'] ?? false) !== true || ($entry['space_id'] ?? $space->id) !== $space->id || ($entry['pending'] ?? false) || ($entry['removed'] ?? false)) {
                continue;
            }
            $seen[$id] = true;
            $matches = $evidence->get($id, collect());
            $record = $matches->count() === 1 ? $matches->first() : null;
            $data = $record?->data ?? [];
            $amount = $entry['amount_cents'] ?? null;
            $valid = is_int($amount) && $amount !== 0 && ($data['amount_cents'] ?? null) === $amount && ($data['paid_on'] ?? null) === ($entry['date'] ?? null)
                && is_string($data['bank_source_fingerprint'] ?? null) && hash_equals($data['bank_source_fingerprint'], $this->bankFingerprint($space->id, $account->id, $id, $entry['date'], $amount, $entry['merchant']))
                && is_string($data['agency'] ?? null) && trim($data['agency']) !== ''
                && in_array($data['tax_type'] ?? null, ['unknown', 'sales_tax', 'income_tax', 'payroll_tax', 'other_tax'], true)
                && in_array($data['movement'] ?? null, $amount < 0 ? ['payment'] : ['returned_payment', 'refund'], true);
            $cashFlow = in_array($entry['flow'] ?? null, ['expense', 'refund'], true)
                || ($amount > 0 && in_array($entry['flow'] ?? null, ['income', 'unclassified_deposit'], true));
            $candidate = $cashFlow
                && (($entry['category'] ?? null) === 'taxes' || preg_match('/\b(treasury|revenue|dor|eftps|tax)\b/i', $entry['merchant'] ?? ''));
            if (! $record && ! $candidate && $matches->isEmpty()) {
                continue;
            }
            $reviewed = $valid && ($data['reviewed'] ?? false) === true;
            $type = $valid ? $data['tax_type'] : 'unknown';
            $movement = $valid ? $data['movement'] : ($amount < 0 ? 'payment' : 'unverified_credit');
            if ($reviewed && $type === 'sales_tax') {
                $confirmed++;
                if ($movement === 'payment') {
                    $paid -= $amount;
                } elseif ($movement === 'returned_payment') {
                    $returned += $amount;
                } else {
                    $refunded += $amount;
                }
            }
            $components = $valid ? array_intersect_key($data, array_flip(['tax_principal_cents', 'interest_cents', 'penalty_cents', 'other_fees_cents'])) : [];
            $components = array_filter($components, fn ($value) => is_int($value) && $value >= 0);
            $split = count($components) === 4 && array_sum($components) === abs($amount) ? $components : null;
            $rows[] = ['transaction_id' => $id, 'record_id' => $record?->id, 'date' => $entry['date'], 'account_id' => $account->id,
                'bank_source_fingerprint' => is_int($amount) ? $this->bankFingerprint($space->id, $account->id, $id, $entry['date'], $amount, $entry['merchant']) : null,
                'components' => $split, 'account' => $account->name, 'merchant' => $entry['merchant'], 'amount_cents' => $amount,
                'agency' => $valid ? $data['agency'] : null, 'jurisdiction' => $valid ? ($data['jurisdiction'] ?? null) : null,
                'tax_type' => $type, 'movement' => $movement, 'filing_start' => $valid ? ($data['filing_start'] ?? null) : null,
                'filing_end' => $valid ? ($data['filing_end'] ?? null) : null, 'reviewed' => $reviewed,
                'bank_reviewed' => ($entry['reviewed'] ?? false) === true, 'source' => $valid ? ($data['source'] ?? null) : null,
                'issue' => $matches->count() > 1 ? 'Conflicting tax evidence; edit duplicate records.' : ($record && ! $valid ? 'Bank source changed; recheck this evidence.' : (! $reviewed ? 'Tax type or filing evidence needs review.' : null))];
        }

        return ['rows' => $rows, 'confirmed_payment_cents' => $confirmed ? $paid : null, 'returned_payment_cents' => $confirmed ? $returned : null,
            'refund_cents' => $confirmed ? $refunded : null, 'net_remittance_cents' => $confirmed ? $paid - $returned - $refunded : null,
            'notes' => ['Totals include only reviewed sales-tax evidence matched to posted bank transactions in the selected report/account scope.',
                'An agency name alone does not establish sales tax. Income tax, payroll tax and unknown remittances stay separate.',
                'Returns and refunds are bank credits, not tax payments. Filing periods stay unknown until supported by a filing or receipt.',
                'Remittance cash may include interest, penalties or fees. A missing component split stays unknown, not zero.',
                'Net remittances are bank cash movements, not tax due, filing acceptance or proof that every jurisdiction is covered.']];
    }
}
