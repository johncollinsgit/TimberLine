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
        abort_if(in_array($kind, ['payroll', 'reliance'], true) && $space->kind !== 'business', 422);
        $rules = [];
        foreach ($definition as $key => $field) {
            $rules[$key] = [$field['required'] ? 'required' : 'nullable'];
            $rules[$key] = [...$rules[$key], ...match ($field['type']) {
                'money' => ['integer', 'between:-100000000000,100000000000'],
                'percent' => ['integer', 'between:0,10000'],
                'integer' => ['integer', 'min:0', 'max:1000000000'],
                'debt_record', 'recurring_record' => ['integer', Rule::exists('trajectory_records', 'id')->where('space_id', $space->id)->where('kind', $field['type'] === 'debt_record' ? 'debt' : 'recurring')->where('active', true)],
                'account' => ['integer', Rule::exists('trajectory_accounts', 'id')->where('space_id', $space->id)],
                'decimal' => ['regex:/^\d{1,8}(\.\d{1,8})?$/', 'numeric', 'gt:0'],
                'date' => ['date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before:2200-01-01'],
                'boolean' => ['boolean'],
                'category' => [Rule::in(config('trajectory.categories'))],
                'select' => [Rule::in($field['choices'])],
                default => ['string', 'max:160'],
            }];
            if ($field['type'] === 'money' && ! in_array($key, ['amount_cents', 'resale_adjustment_cents'], true)) {
                $rules[$key][] = 'min:0';
            }
        }
        $data = Validator::make($input, $rules)->validate();
        foreach ($definition as $key => $field) {
            if (! isset($data[$key])) {
                continue;
            }
            if (in_array($field['type'], ['money', 'percent', 'integer', 'account', 'debt_record', 'recurring_record'], true)) {
                $data[$key] = (int) $data[$key];
            }
            if ($field['type'] === 'boolean') {
                $data[$key] = (bool) $data[$key];
            }
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
            abort_unless(in_array(Account::findOrFail($data['account_id'])->kind, ['credit', 'loan'], true), 422, 'Link a credit or loan account.');
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
        if ($kind === 'asset' && ! empty($data['linked_business_space_id'])) {
            $business = Space::where('kind', 'business')->findOrFail($data['linked_business_space_id']);
            app(FinanceAccess::class)->authorize($user, $business);
            abort_unless($space->kind === 'household' && $data['asset_type'] === 'business_equity' && DB::table('trajectory_links')->where('household_id', $space->id)->where('business_id', $business->id)->exists(), 422, 'Choose a linked business for this equity valuation.');
        }

        return DB::transaction(function () use ($user, $space, $kind, $name, $data, $record, $version) {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            if ($record) {
                $record = Record::where('space_id', $space->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
                abort_unless($record->kind === $kind && $record->version === $version, 409);
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
            $before = $record?->data;
            $record ??= new Record(['space_id' => $space->id, 'kind' => $kind]);
            $record->fill(['name' => $name, 'data' => $data, 'version' => $record->exists ? $record->version + 1 : 1])->save();
            if ($kind === 'metal' && ! empty($data['transaction_id'])) {
                $tx = Transaction::where('space_id', $space->id)->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
                app(LedgerService::class)->classify($user, $space, $tx, ['version' => $tx->version, 'flow' => 'asset_transfer', 'category' => $tx->category, 'face_punched' => false, 'bullshit_spending' => false]);
            }
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'save_'.$kind, 'record_id' => $record->id, 'before' => $before, 'after' => $data]);

            return $record;
        });
    }
}
