<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RecordService
{
    public function validate(Space $space, string $kind, array $input): array
    {
        $definition = config('trajectory_records.'.$kind);
        abort_unless(is_array($definition), 422);
        abort_if(in_array($kind, ['payroll', 'reliance', 'business_funding_plan'], true) && $space->kind !== 'business', 422);
        abort_if(str_starts_with($kind, 'medical_') && $space->kind !== 'household', 422, 'Medical sharing belongs to a private household.');
        abort_if($kind === 'financial_observation' && $space->kind !== 'household', 422, 'Household financial observations belong to a private household.');
        abort_if($kind === 'company_support_source' && $space->kind !== 'household', 422, 'Company support sources belong to a private household.');
        $rules = [];
        foreach ($definition as $key => $field) {
            $rules[$key] = [$field['required'] ? 'required' : 'nullable'];
            $rules[$key] = [...$rules[$key], ...match ($field['type']) {
                'money', 'signed_money' => ['integer', 'between:-100000000000,100000000000'],
                'percent' => ['integer', 'between:0,10000'],
                'rate' => ['integer', 'between:0,100000'],
                'integer' => ['integer', 'min:0', 'max:1000000000'],
                'debt_record', 'recurring_record', 'medical_need_record', 'medical_bill_record', 'program_record' => ['integer', Rule::exists('trajectory_records', 'id')->where('space_id', $space->id)->where('kind', substr($field['type'], 0, -7))->where('active', true)],
                'account' => ['integer', Rule::exists('trajectory_accounts', 'id')->where('space_id', $space->id)],
                'business_space' => ['integer', Rule::exists('trajectory_spaces', 'id')->where('kind', 'business')],
                'decimal' => ['regex:/^\d{1,8}(\.\d{1,8})?$/', 'numeric', 'gt:0'],
                'date' => ['date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before:2200-01-01'],
                'month' => ['regex:/^(?:19|20|21)\d{2}-(?:0[1-9]|1[0-2])$/'],
                'boolean' => ['boolean'],
                'category' => [Rule::in(app(WorkspaceService::class)->categories($space))],
                'select' => [Rule::in($field['choices'])],
                default => ['string', 'max:160'],
            }];
            if ($field['type'] === 'money' && ! in_array($key, ['amount_cents', 'resale_adjustment_cents'], true)) {
                $rules[$key][] = 'min:0';
            }
            if ($kind === 'budget' && $key === 'ends_on') {
                $rules[$key][] = 'after_or_equal:effective_on';
            }
        }
        if ($kind === 'tax_remittance') {
            // Admission token for the bank facts actually shown to the reviewer;
            // distinct from the server-owned fingerprint stored with the record.
            $rules['expected_bank_source_fingerprint'] = ['required', 'string', 'regex:/^[a-f0-9]{64}$/'];
        }
        $data = Validator::make($input, $rules)->validate();
        foreach ($definition as $key => $field) {
            if (! isset($data[$key])) {
                continue;
            }
            if (in_array($field['type'], ['money', 'signed_money', 'percent', 'rate', 'integer', 'account', 'business_space', 'debt_record', 'recurring_record', 'medical_need_record', 'medical_bill_record', 'program_record'], true)) {
                $data[$key] = (int) $data[$key];
            }
            if ($field['type'] === 'boolean') {
                $data[$key] = (bool) $data[$key];
            }
        }
        if ($kind === 'tax_remittance') {
            $data['bank_source_fingerprint'] = app(SalesTaxService::class)->validateRemittance($space, $data);
            unset($data['expected_bank_source_fingerprint']);
        }
        if ($kind === 'company_support_source') {
            abort_unless(DB::table('trajectory_links')->where('household_id', $space->id)->where('business_id', $data['business_space_id'])->exists(), 422, 'Choose an explicitly linked business for this receipt source.');
            if ($data['source_type'] === 'category') {
                abort_unless(in_array($data['source_value'], app(WorkspaceService::class)->categories($space), true), 422, 'Choose an actual household category for this receipt source.');
            } else {
                $data['source_value'] = ClassificationService::merchant($data['source_value']);
                abort_unless($data['source_value'] !== '', 422, 'Enter a nonempty exact merchant.');
            }
        }
        if ($kind === 'debt_balance_observation') {
            abort_unless(in_array(Account::where('space_id', $space->id)->findOrFail($data['account_id'])->kind, ['credit', 'loan'], true), 422, 'Choose a debt account for this balance observation.');
            abort_unless($data['observed_on'] <= now($space->timezone)->toDateString(), 422, 'Use an actual balance date, not a future observation.');
        }
        if ($kind === 'budget') {
            abort_if($data['purpose'] === 'owner_support' && $space->kind !== 'business', 422, 'Owner-support budgets belong to a business space.');
            abort_if(($data['rollover'] ?? false) && empty($data['rollover_start_month']), 422, 'Choose the first rollover month.');
            abort_if(isset($data['opening_rollover_cents']) && ! ($data['rollover'] ?? false), 422, 'An opening rollover requires rollover to be enabled.');
            abort_if(($data['rollover'] ?? false) && ($data['reviewed'] ?? false) !== true && ($data['opening_rollover_cents'] ?? 0) !== 0, 422, 'Review the opening rollover before using it.');
        }
        if ($kind === 'business_funding_plan') {
            abort_unless($data['reviewed_on'] <= now($space->timezone)->toDateString(), 422, 'Use an actual review date.');
            abort_if($data['complete'] && $data['reviewed_on'] !== now($space->timezone)->toDateString(), 422, 'Re-review remaining receipts and commitments today before confirming this plan against current balances.');
        }
        if ($kind === 'financial_observation') {
            $today = now($space->timezone)->toDateString();
            if (($data['aggregation'] ?? 'period_total') === 'dated_item') {
                abort_unless(filled($data['source_id'] ?? null) && $data['period_start'] === $data['period_end'] && ! in_array($data['metric'], ['personal_credit_cards', 'personal_debt'], true), 422, 'A dated item needs a stable source reference and one actual transaction date.');
            }
            abort_unless($data['amount_cents'] >= 0 && $data['period_start'] <= $data['period_end'] && $data['period_end'] <= $data['observed_on'] && $data['observed_on'] <= $today, 422, 'Use nonnegative actual amounts and a completed evidence period.');
            if (in_array($data['metric'], ['personal_credit_cards', 'personal_debt'], true)) {
                abort_unless($data['period_start'] === $data['period_end'] && $data['period_end'] === $data['observed_on'], 422, 'A balance observation needs one actual balance date.');
            }
            if (isset($data['gross_cents'])) {
                abort_unless($data['metric'] === 'employee_pay' && $data['gross_cents'] >= $data['amount_cents'], 422, 'Gross employee pay must be at least net pay.');
            }
            if (isset($data['baseline_cents']) || isset($data['baseline_on'])) {
                abort_unless($data['metric'] === 'personal_debt' && isset($data['baseline_cents'], $data['baseline_on']) && $data['baseline_cents'] > 0 && $data['baseline_on'] <= $data['observed_on'] && $data['complete'], 422, 'Debt progress needs a positive dated baseline and complete coverage of the same debts.');
            }
        }
        if ($kind === 'debt' && ! empty($data['cash_cadence'])) {
            abort_unless(! empty($data['cash_payment_cents']) && ! empty($data['cash_next_due_on']), 422, 'A separate cash schedule needs its amount and next withdrawal date.');
        }
        if ($kind === 'receipt') {
            abort_unless($data['amount_cents'] > 0 && $data['purchased_on'] <= now($space->timezone)->toDateString(), 422, 'Use an actual receipt date and positive total.');
            if (! empty($data['transaction_id'])) {
                $tx = Transaction::where('space_id', $space->id)->findOrFail($data['transaction_id']);
                abort_unless(! $tx->pending && ! $tx->removed && $tx->amount_cents === -$data['amount_cents'], 422, 'Match a posted purchase with the same total.');
            }
        }
        if ($kind === 'interest_statement') {
            abort_unless($data['period_start'] <= $data['period_end'] && $data['period_end'] <= now($space->timezone)->toDateString(), 422, 'Use a completed interest observation period.');
            abort_unless(in_array(Account::findOrFail($data['account_id'])->kind, ['credit', 'loan'], true), 422, 'Choose a debt account.');
        }
        if ($kind === 'lender_interest_ytd') {
            $lenderAccount = Account::where('space_id', $space->id)->findOrFail($data['account_id']);
            abort_unless($lenderAccount->kind === 'credit', 422, 'Choose an owned credit account.');
            abort_unless(($data['currency'] ?? null) === $lenderAccount->currency, 422, 'Statement currency must match its owned credit account.');
            abort_unless(app(LenderInterestYtdService::class)->valid($data, now($space->timezone)->toDateString()), 422, 'Use a completed calendar-year lender observation, its original source hash and nonempty statement reference.');
        }
        if ($kind === 'payment_notice') {
            abort_unless(in_array(Account::findOrFail($data['account_id'])->kind, ['credit', 'loan'], true), 422, 'Choose a loan or credit account.');
            abort_unless($data['amount_cents'] > 0, 422, 'Payment must be positive.');
            if (! empty($data['payment_account_id'])) {
                abort_unless(Account::findOrFail($data['payment_account_id'])->kind === 'cash', 422, 'Choose a cash payment account.');
            }
        }
        if ($kind === 'medical_bill' && ! isset($data['billed_on'])) {
            $data['billed_on'] = null;
        }
        if ($kind === 'medical_bill' && ! isset($data['next_due_on'])) {
            $data['next_due_on'] = null;
        }
        if ($kind === 'debt' && ($data['interest_method'] ?? 'monthly') === 'actual_365') {
            abort_unless(! empty($data['interest_paid_through']) && $data['interest_paid_through'] < $data['next_due_on'], 422, 'Daily interest needs the previous paid-through date before the next payment.');
            abort_if(! empty($data['cash_cadence']), 422, 'Daily interest uses the loan payment cadence directly.');
        }
        if ($kind === 'debt') {
            foreach (['balance_cents', 'payment_cents', 'escrow_cents', 'fees_cents', 'cash_payment_cents'] as $field) {
                abort_if(($data[$field] ?? 0) < 0, 422, 'Debt amounts cannot be negative.');
            }
            abort_if(($data['opening_accrued_interest_cents'] ?? 0) < 0, 422, 'Opening accrued interest cannot be negative.');
        }
        if ($kind === 'metal') {
            abort_unless($data['purity_bps'] > 0, 422, 'Purity must be positive.');
        }
        if ($kind === 'goal' && $data['goal_type'] === 'debt_paydown') {
            abort_unless(! empty($data['debt_record_id']), 422, 'Choose the debt for this paydown goal.');
        }
        if ($kind === 'goal') {
            abort_unless($data['saved_cents'] <= $data['target_cents'], 422, 'Saved amount exceeds the target.');
        }
        if ($kind === 'payroll') {
            abort_unless($data['period_end'] >= $data['period_start'], 422);
        }
        if ($kind === 'debt' && ! empty($data['account_id'])) {
            $account = Account::findOrFail($data['account_id']);
            abort_unless($account->kind === ($data['kind'] === 'credit' ? 'credit' : 'loan'), 422, 'Debt type must match the linked account.');
        }
        if (! empty($data['transaction_id'])) {
            abort_unless(Transaction::where('space_id', $space->id)->whereKey($data['transaction_id'])->exists(), 422);
        }

        return $data;
    }

    public function save(User $user, Space $space, string $kind, string $name, array $input, ?Record $record = null, ?int $version = null): Record
    {
        app(FinanceAccess::class)->authorize($user, $space);
        $data = $this->validate($space, $kind, $input);
        $admission = $kind === 'tax_remittance' ? ['expected_bank_source_fingerprint' => $input['expected_bank_source_fingerprint']] : [];
        if ($kind === 'company_support_source') {
            app(FinanceAccess::class)->authorize($user, Space::where('kind', 'business')->findOrFail($data['business_space_id']));
        }
        if ($kind === 'asset' && ! empty($data['linked_business_space_id'])) {
            $business = Space::where('kind', 'business')->findOrFail($data['linked_business_space_id']);
            app(FinanceAccess::class)->authorize($user, $business);
            abort_unless($space->kind === 'household' && $data['asset_type'] === 'business_equity' && DB::table('trajectory_links')->where('household_id', $space->id)->where('business_id', $business->id)->exists(), 422, 'Choose a linked business for this equity valuation.');
        }

        return DB::transaction(function () use ($user, $space, $kind, $name, $data, $admission, $record, $version) {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            if ($record) {
                $record = Record::where('space_id', $space->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
                abort_unless($record->active && $record->kind === $kind && $record->version === $version, 409);
            }
            if ($kind === 'tax_remittance') {
                foreach (Record::where('space_id', $space->id)->where('kind', $kind)->where('active', true)->lockForUpdate()->get() as $other) {
                    abort_if($other->id !== $record?->id && ($other->data['transaction_id'] ?? null) === $data['transaction_id'], 409, 'This bank transaction already has tax evidence. Edit the existing record.');
                }
            }
            // Revalidate references while holding the space lock, including concurrent archive changes.
            $lockedData = $this->validate($space, $kind, [...$data, ...$admission]);
            if ($kind === 'tax_remittance') {
                $data = $lockedData;
            }
            if ($kind === 'company_support_source') {
                app(FinanceAccess::class)->authorize($user, Space::where('kind', 'business')->findOrFail($data['business_space_id']));
                foreach (Record::where('space_id', $space->id)->where('kind', $kind)->where('active', true)->lockForUpdate()->get() as $other) {
                    $value = $other->data['source_value'] ?? null;
                    if (($other->data['source_type'] ?? null) === 'merchant' && is_string($value)) {
                        $value = ClassificationService::merchant($value);
                    }
                    if ($other->id !== $record?->id && ($other->data['source_type'] ?? null) === $data['source_type'] && $value === $data['source_value']) {
                        abort_unless($record === null && $other->name === $name && $other->data == $data, 409, 'This receipt source already exists. Correct it with a versioned edit.');

                        return $other;
                    }
                }
            }
            if ($kind === 'debt_balance_observation') {
                foreach (Record::where('space_id', $space->id)->where('kind', $kind)->where('active', true)->lockForUpdate()->get() as $other) {
                    if ($other->id !== $record?->id && ($other->data['account_id'] ?? null) === $data['account_id'] && ($other->data['observed_on'] ?? null) === $data['observed_on']) {
                        abort_unless($record === null && $other->name === $name && $other->data == $data, 409, 'This debt balance date already has evidence. Correct it with a versioned edit.');

                        return $other;
                    }
                }
            }
            if ($kind === 'business_funding_plan' && ($data['complete'] ?? false)) {
                // Never accept a client snapshot. A new explicit attestation binds
                // the remaining receipts/costs to the exact locked source balances.
                $accounts = Account::where('space_id', $space->id)->orderBy('id')->lockForUpdate()->get();
                $terms = Record::where('space_id', $space->id)->where('kind', 'debt')->where('active', true)->orderBy('id')->lockForUpdate()->get();
                $data['funding_snapshot'] = app(DecisionSupportService::class)->fundingSnapshot($accounts, $terms);
            }
            if (str_starts_with($kind, 'medical_')) {
                $data = app(MedicalSharingService::class)->prepare($space, $kind, $data, $record);
            }
            if ($kind === 'interest_statement') {
                foreach (Record::where('space_id', $space->id)->where('kind', 'interest_statement')->where('active', true)->get() as $other) {
                    abort_if($other->id !== $record?->id && $other->data['account_id'] === $data['account_id'] && $other->data['period_start'] <= $data['period_end'] && $other->data['period_end'] >= $data['period_start'], 422, 'Interest observation periods for the same debt must not overlap. Edit the existing statement.');
                }
            }
            if ($kind === 'lender_interest_ytd') {
                foreach (Record::where('space_id', $space->id)->where('kind', $kind)->where('active', true)->lockForUpdate()->get() as $other) {
                    if ($other->id !== $record?->id && (($other->data['source_id'] ?? null) === $data['source_id'] || (($other->data['account_id'] ?? null) === $data['account_id'] && ($other->data['reported_year'] ?? null) === $data['reported_year'] && ($other->data['reported_through'] ?? null) === $data['reported_through']))) {
                        abort_unless($record === null && $other->name === $name && $other->data == $data, 409, 'This lender statement cutoff/reference already has evidence. Correct it with a versioned edit.');

                        return $other;
                    }
                }
            }
            if ($kind === 'debt' && ! empty($data['account_id'])) {
                foreach (Record::where('space_id', $space->id)->where('kind', 'debt')->where('active', true)->get() as $other) {
                    abort_if($other->id !== $record?->id && ($other->data['account_id'] ?? null) === $data['account_id'], 422, 'This account already has debt terms.');
                }
            }
            if ($kind === 'payroll') {
                foreach (Record::where('space_id', $space->id)->where('kind', 'payroll')->get() as $other) {
                    if ($other->id !== $record?->id && ($other->data['source_id'] ?? null) === $data['source_id']) {
                        abort_unless($other->data === $data, 409, 'Payroll source row changed; edit the existing row.');

                        return $other;
                    }
                }
            }
            if ($kind === 'budget' && $record && filled($record->data['source_id'] ?? null)) {
                abort_if($record->data['source_id'] !== ($data['source_id'] ?? null), 422, 'Keep the original stable source reference when editing an imported budget target.');
            }
            if ($kind === 'budget' && filled($data['source_id'] ?? null)) {
                foreach (Record::where('space_id', $space->id)->where('kind', 'budget')->get() as $other) {
                    if ($other->id !== $record?->id && ($other->data['source_id'] ?? null) === $data['source_id']) {
                        abort_unless($other->active && $other->name === $name && $other->data == $data, 409, 'This budget source already exists with different or archived data. Edit the existing target.');

                        return $other;
                    }
                }
            }
            if ($kind === 'financial_observation' && $record && filled($record->data['source_id'] ?? null)) {
                abort_if($record->data['source_id'] !== ($data['source_id'] ?? null), 422, 'Keep the original stable source reference when correcting evidence.');
            }
            if ($kind === 'financial_observation' && filled($data['source_id'] ?? null)) {
                foreach (Record::where('space_id', $space->id)->where('kind', $kind)->get() as $other) {
                    if ($other->id !== $record?->id && ($other->data['source_id'] ?? null) === $data['source_id']) {
                        abort_unless($other->data === $data, 409, 'This source reference already exists with different evidence. Edit the existing observation.');

                        return $other;
                    }
                }
            }
            if ($kind === 'metal') {
                abort_if($record && Event::where('space_id', $space->id)->where('record_id', $record->id)->where('action', 'metal_sale')->exists(), 422, 'A sold lot retains its original acquisition history. Add a new lot for another purchase.');
                abort_if($record && ! empty($record->data['transaction_id']) && ($data['transaction_id'] ?? null) !== $record->data['transaction_id'], 422, 'Keep the matched purchase reference.');
                if (! empty($data['transaction_id'])) {
                    $tx = Transaction::where('space_id', $space->id)->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
                    abort_unless(! $tx->pending && ! $tx->removed && $tx->amount_cents === -$data['cost_basis_cents'], 422, 'Purchase amount must match this lot’s total cost including fees. Use separate statement rows for multiple lots.');
                    foreach (Record::where('space_id', $space->id)->where('kind', 'metal')->get() as $other) {
                        abort_if($other->id !== $record?->id && ($other->data['transaction_id'] ?? null) === $tx->id, 422, 'This purchase already belongs to a lot.');
                    }
                }
            }
            if ($kind === 'debt' && ! empty($data['recurring_record_id'])) {
                abort_if(Record::where('space_id', $space->id)->where('active', true)->whereIn('kind', ['medical_bill', 'debt', 'medical_membership'])->get()->contains(fn ($r) => $r->id !== $record?->id && ($r->data['recurring_record_id'] ?? null) === $data['recurring_record_id']), 422, 'This schedule is already replaced.');
            }
            if ($record && $kind === 'tax_remittance') {
                app(SalesTaxService::class)->invalidateChangedBinding($record, $data, $user->id);
            }
            $before = $record?->data;
            $record ??= new Record(['space_id' => $space->id, 'kind' => $kind]);
            $record->fill(['name' => $name, 'data' => $data, 'version' => $record->exists ? $record->version + 1 : 1])->save();
            if ($kind === 'metal' && ! empty($data['transaction_id'])) {
                $tx = Transaction::where('space_id', $space->id)->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
                app(LedgerService::class)->classify($user, $space, $tx, ['version' => $tx->version, 'flow' => 'asset_transfer', 'category' => $tx->category, 'face_punched' => false, 'bullshit_spending' => false]);
            }
            app(MedicalSharingService::class)->syncMatch($user, $space, $record, $before);
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'save_'.$kind, 'record_id' => $record->id, 'before' => $before, 'after' => $data]);

            return $record;
        });
    }
}
