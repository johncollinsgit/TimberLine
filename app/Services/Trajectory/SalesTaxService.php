<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
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

    /** Resolve only owned bank facts; no foreign source data is disclosed through allocations. */
    public function decorateEntries(Space $space, array $entries): array
    {
        $ids = collect($entries)->where('flow', EconomicActivityService::TAX_FLOW)->where('editable', true)->pluck('id');
        if (! collect($entries)->contains(fn ($e) => $e['flow'] === EconomicActivityService::TAX_FLOW)) {
            return $entries;
        }
        $sources = Transaction::where('space_id', $space->id)->whereIn('id', $ids)->get()->keyBy('id');
        $allocations = Allocation::whereIn('transaction_id', $ids)->get()->groupBy('transaction_id');
        $records = Record::where('space_id', $space->id)->where('kind', 'tax_remittance')->where('active', true)->get();
        $accounts = Account::where('space_id', $space->id)->get()->keyBy('id');
        foreach ($entries as &$entry) {
            if ($entry['flow'] !== EconomicActivityService::TAX_FLOW) {
                continue;
            }
            $tx = $sources->get($entry['id']);
            $parts = $allocations->get($entry['id'], collect());
            $whole = $tx && $parts->count() === 1 && $parts->first()->space_id === $space->id && $parts->first()->amount_cents === $tx->amount_cents;
            $purpose = $whole && $entry['amount_cents'] === $tx->amount_cents
                ? $this->purpose($space, $tx, $records, $accounts) : ['evidence_valid' => false, 'components' => null, 'issue' => 'Review owned bank evidence and allocation before applying sales-tax purpose.'];
            $purpose['valid'] = $purpose['evidence_valid'] && $entry['reviewed'];
            $entry['tax_purpose'] = $purpose;
            if (! $purpose['evidence_valid']) {
                $entry['reviewed'] = false;
                $entry['explanation'] = 'Sales-tax bank evidence changed; review the purpose again.';
            }
        }
        unset($entry);

        return $entries;
    }

    public function purpose(Space $space, Transaction $tx, ?Collection $records = null, ?Collection $accounts = null): array
    {
        $records ??= Record::where('space_id', $space->id)->where('kind', 'tax_remittance')->where('active', true)->get();
        $accounts ??= Account::where('space_id', $space->id)->get()->keyBy('id');
        $entry = ['id' => $tx->id, 'space_id' => $tx->space_id, 'account_id' => $tx->account_id, 'date' => $tx->posted_on->toDateString(),
            'amount_cents' => $tx->amount_cents, 'merchant' => $tx->merchant, 'flow' => $tx->flow, 'category' => $tx->category,
            'reviewed' => $tx->reviewed, 'editable' => $tx->space_id === $space->id, 'pending' => $tx->pending, 'removed' => $tx->removed];
        $row = $this->build($space, collect([$entry]), $records, $accounts)['rows'][0] ?? null;
        $valid = ($accounts->get($tx->account_id)?->kind === 'cash') && ($row['reviewed'] ?? false) && ($row['tax_type'] ?? null) === 'sales_tax';

        return ['evidence_valid' => $valid, 'components' => $valid ? $row['components'] : null,
            'record_id' => $row['record_id'] ?? null, 'movement' => $valid ? $row['movement'] : null,
            'issue' => $valid ? (($row['components'] ?? null) === null ? 'Sales-tax economic split is unknown.' : null) : 'Sales-tax evidence is missing, changed or not reviewed.'];
    }

    public function admitClassification(Space $space, Transaction $tx, array $data, bool $learn): void
    {
        if (($data['flow'] ?? null) !== EconomicActivityService::TAX_FLOW) {
            abort_if($this->purpose($space, $tx)['evidence_valid'], 422, 'Edit or unconfirm the sales-tax evidence before changing its bank purpose.');

            return;
        }
        abort_if($learn, 422, 'Sales-tax purpose needs receipt evidence for each payment; do not remember an agency merchant rule.');
        abort_unless(($data['category'] ?? null) === 'taxes' && ! ($data['face_punched'] ?? false) && ! ($data['bullshit_spending'] ?? false), 422, 'Use Taxes with receipt-backed sales-tax purpose; components are reported separately.');
        abort_unless($this->purpose($space, $tx)['evidence_valid'], 422, 'First save reviewed sales-tax evidence matching this posted bank source.');
        $allocations = Allocation::where('transaction_id', $tx->id)->lockForUpdate()->get();
        abort_unless($allocations->count() === 1 && $allocations->first()->space_id === $space->id && $allocations->first()->amount_cents === $tx->amount_cents, 422, 'Sales-tax purpose requires the full owned bank movement, not a cross-space allocation.');
    }

    /** A changed/archived binding cannot leave the old bank purpose silently reviewed. */
    public function invalidateChangedBinding(Record $record, ?array $newData, ?int $actorId): void
    {
        if ($record->kind !== 'tax_remittance') {
            return;
        }
        $keys = ['transaction_id', 'paid_on', 'amount_cents', 'tax_type', 'movement', 'reviewed', 'bank_source_fingerprint', 'tax_principal_cents', 'interest_cents', 'penalty_cents', 'other_fees_cents'];
        $old = array_intersect_key($record->data, array_flip($keys));
        $new = $newData === null ? null : array_intersect_key($newData, array_flip($keys));
        ksort($old);
        if ($new !== null) {
            ksort($new);
        }
        if ($new !== null && $old === $new) {
            return;
        }
        $tx = Transaction::where('space_id', $record->space_id)->whereKey($record->data['transaction_id'] ?? 0)->lockForUpdate()->first();
        if ($tx && $tx->flow === EconomicActivityService::TAX_FLOW && $tx->reviewed) {
            $before = $tx->only(['reviewed', 'version']);
            $tx->update(['reviewed' => false, 'version' => $tx->version + 1, 'explanation' => 'Sales-tax evidence changed; review this bank purpose again.']);
            Event::create(['space_id' => $record->space_id, 'actor_id' => $actorId, 'record_id' => $record->id, 'action' => 'tax_bank_purpose_needs_review', 'before' => $before, 'after' => ['source_transaction_id' => $tx->id, 'reviewed' => false, 'version' => $tx->version]]);
        }
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
                'bank_reviewed' => ($entry['reviewed'] ?? false) === true, 'bank_flow' => $entry['flow'] ?? null, 'source' => $valid ? ($data['source'] ?? null) : null,
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
