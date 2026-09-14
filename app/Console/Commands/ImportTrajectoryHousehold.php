<?php

namespace App\Console\Commands;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\MonarchImportService;
use App\Services\Trajectory\RecordService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ImportTrajectoryHousehold extends Command
{
    protected $signature = 'trajectory:import-household {--owner= : Existing verified household owner} {--space= : Household space ID} {--transactions= : Private Monarch CSV path} {--observations= : Private JSON observations and budget targets} {--balances= : Private Monarch account balance CSV path} {--chase= : Private Chase activity CSV path} {--chase-account= : Observation key for the Chase card} {--apply : Apply validated import; default is a dry run}';

    protected $description = 'Import a private household history and dated observations without activating bank access or billing.';

    public function handle(MonarchImportService $importer, RecordService $records): int
    {
        $owner = User::where('email', $this->option('owner'))->firstOrFail();
        $space = Space::where('kind', 'household')->where('owner_user_id', $owner->id)->findOrFail($this->option('space'));
        app(FinanceAccess::class)->authorize($owner, $space);
        $rows = $importer->read($this->option('transactions'));
        $chaseRows = $this->option('chase') ? app(\App\Services\Trajectory\ChaseImportService::class)->read($this->option('chase')) : [];
        $input = json_decode(file_get_contents($this->option('observations')), true, 64, JSON_THROW_ON_ERROR);
        $data = Validator::make($input, [
            'accounts' => 'required|array|min:1|max:100', 'accounts.*.name' => 'required|string|max:160',
            'accounts.*.source_account' => 'nullable|string|max:160', 'accounts.*.key' => 'required|string|max:100',
            'accounts.*.kind' => ['required', Rule::in(['cash', 'credit', 'loan', 'investment'])],
            'accounts.*.balance_cents' => 'nullable|integer|between:-100000000000,100000000000',
            'accounts.*.observed_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'accounts.*.source' => 'required|string|max:160',
            'budgets' => 'sometimes|array|max:200', 'budgets.*.name' => 'required|string|max:160', 'budgets.*.data' => 'required|array',
            'records' => 'sometimes|array|max:200', 'records.*.key' => 'required|string|max:100', 'records.*.kind' => ['required', Rule::in(['medical_need', 'medical_bill', 'payment_notice', 'recurring', 'debt', 'interest_statement', 'receipt', 'metal', 'subscription'])], 'records.*.name' => 'required|string|max:160', 'records.*.data' => 'required|array', 'records.*.references' => 'sometimes|array',
            'observed_transactions' => 'sometimes|array|max:2000',
            'observed_transactions.*.key' => 'required|string|max:100|distinct',
            'observed_transactions.*.account_key' => 'required|string|max:100',
            'observed_transactions.*.date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'observed_transactions.*.merchant' => 'required|string|max:160',
            'observed_transactions.*.amount_cents' => 'required|integer|between:-100000000000,100000000000|not_in:0',
            'observed_transactions.*.category' => ['required', Rule::in(config('trajectory.categories'))],
            'observed_transactions.*.flow' => ['required', Rule::in(['expense', 'refund', 'income', 'card_payment', 'transfer'])],
            'observed_transactions.*.source' => 'required|string|max:500',
            'corrections' => 'sometimes|array|max:2000',
            'corrections.*.key' => 'required|string|max:100|distinct',
            'corrections.*.source_key' => 'required|string|max:500',
            'corrections.*.amount_cents' => 'required|integer',
            'corrections.*.category' => ['required', Rule::in(config('trajectory.categories'))],
            'corrections.*.flow' => ['required', Rule::in(['expense', 'refund', 'income', 'card_payment', 'debt_payment', 'transfer', 'asset_transfer'])],
            'corrections.*.source' => 'required|string|max:500',
            'coverage_notes' => 'required|array|max:30', 'coverage_notes.*' => 'string|max:500',
        ])->validate();
        $kinds = [];
        foreach ($data['accounts'] as $account) {
            abort_if($account['balance_cents'] !== null && empty($account['observed_on']), 422, 'A balance requires an observation date.');
            if (! empty($account['source_account'])) {
                $kinds[$account['source_account']] = $account['kind'];
            }
        }
        abort_if(array_diff(array_unique(array_column($rows, 'source_account')), array_keys($kinds)), 422, 'Every imported account needs an observed account type.');
        foreach ($data['budgets'] ?? [] as $budget) {
            $records->validate($space, 'budget', $budget['data']);
        }
        $cashBalances = $this->option('balances') ? $importer->cashBalances($this->option('balances'), $kinds) : [];
        $this->info(count($rows).' transactions, '.count($data['accounts']).' accounts, '.count($data['budgets'] ?? []).' budget targets validated.');
        DB::beginTransaction();
        try {
            DB::transaction(function () use ($owner, $space, $rows, $data, $kinds, $importer, $records, $chaseRows, $cashBalances) {
                Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
                $result = $importer->import($owner, $space, $rows, $kinds);
                $referenceIds = [];
                foreach ($data['accounts'] as $a) {
                    $account = ! empty($a['source_account']) ? Account::findOrFail($result['accounts'][$a['source_account']]) : Account::firstOrCreate(['source_key' => 'observation:'.$space->id.':'.hash('sha256', $a['key'])], ['space_id' => $space->id, 'name' => $a['name'], 'kind' => $a['kind']]);
                    $referenceIds[$a['key']] = $account->id;
                    // An older onboarding source must not overwrite a newer observed balance.
                    if (! $account->observed_at || ($a['observed_on'] && $account->observed_at->toDateString() < $a['observed_on'])) {
                        $before = $account->only(['balance_cents', 'observed_at']);
                        $account->update(['balance_cents' => $a['balance_cents'], 'observed_at' => $a['observed_on']]);
                        Event::create(['space_id' => $space->id, 'actor_id' => $owner->id, 'record_id' => $account->id, 'action' => 'onboarding_observation', 'before' => $before, 'after' => ['balance_cents' => $a['balance_cents'], 'observed_on' => $a['observed_on'], 'source' => $a['source']]]);
                    }
                }
                foreach ($cashBalances as $date => $balances) {
                    $snapshot = \App\Models\Trajectory\Snapshot::where('space_id', $space->id)->whereDate('observed_on', $date)->first() ?? \App\Models\Trajectory\Snapshot::create(['space_id' => $space->id, 'observed_on' => $date, 'data' => []]);
                    if (! isset($snapshot->data['imported_cash_cents'])) {
                        $snapshot->update(['data' => [...$snapshot->data, 'imported_cash_cents' => array_sum($balances), 'imported_cash_accounts' => $balances, 'cash_source' => 'Monarch balance export; imported cash accounts only']]);
                    }
                }
                if ($chaseRows) {
                    abort_unless(isset($referenceIds[$this->option('chase-account')]), 422, 'Resolve the Chase account observation key.');
                    $chaseAccount = Account::findOrFail($referenceIds[$this->option('chase-account')]);
                    abort_unless($chaseAccount->kind === 'credit', 422, 'Chase activity must belong to a card.');
                    app(\App\Services\Trajectory\LedgerService::class)->ingest($chaseAccount, $chaseRows);
                    $chaseAccount->update(['history_start' => min($chaseAccount->history_start?->toDateString() ?? '9999-12-31', min(array_column($chaseRows, 'date')))]);
                }
                foreach ($data['observed_transactions'] ?? [] as $row) {
                    abort_unless(isset($referenceIds[$row['account_key']]), 422, 'Resolve the observed transaction account.');
                    $account = Account::where('space_id', $space->id)->findOrFail($referenceIds[$row['account_key']]);
                    $id = 'observed:'.hash('sha256', $row['key']);
                    app(\App\Services\Trajectory\LedgerService::class)->ingest($account, [[
                        ...$row, 'id' => $id, 'source_provider' => 'observed_statement',
                        'import_classification' => ['category' => $row['category'], 'flow' => $row['flow'], 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false, 'explanation' => 'Transcribed from a dated statement: '.$row['source']],
                    ]]);
                    $referenceIds[$row['key']] = \App\Models\Trajectory\Transaction::where('account_id', $account->id)->where('source_key', $account->source_key.':'.$id)->sole()->id;
                    $account->update(['history_start' => min($account->history_start?->toDateString() ?? '9999-12-31', $row['date'])]);
                }
                foreach ($data['corrections'] ?? [] as $correction) {
                    $action = 'import_fix_'.hash('sha256', $correction['key']);
                    if (Event::where('space_id', $space->id)->where('action', $action)->exists()) {
                        continue;
                    }
                    $tx = \App\Models\Trajectory\Transaction::where('space_id', $space->id)->where('source_key', $correction['source_key'])->sole();
                    abort_unless($tx->amount_cents === $correction['amount_cents'], 422, 'Correction evidence must match the original amount.');
                    if (! $tx->reviewed) {
                        app(\App\Services\Trajectory\LedgerService::class)->classify($owner, $space, $tx, ['version' => $tx->version, 'category' => $correction['category'], 'flow' => $correction['flow'], 'face_punched' => false, 'bullshit_spending' => false]);
                    }
                    Event::create(['space_id' => $space->id, 'actor_id' => $owner->id, 'record_id' => $tx->id, 'action' => $action, 'after' => ['source' => $correction['source']]]);
                }
                foreach ($data['budgets'] ?? [] as $budget) {
                    // Replays never overwrite subsequent user edits.
                    $key = hash('sha256', $budget['name'].'|'.$budget['data']['effective_on'].'|'.($budget['data']['source'] ?? ''));
                    if (! Event::where('space_id', $space->id)->where('action', 'import_budget_'.$key)->exists()) {
                        $record = $records->save($owner, $space, 'budget', $budget['name'], $budget['data']);
                        Event::create(['space_id' => $space->id, 'actor_id' => $owner->id, 'record_id' => $record->id, 'action' => 'import_budget_'.$key]);
                    }
                }
                foreach ($data['records'] ?? [] as $item) {
                    $action = 'import_record_'.hash('sha256', $item['key']);
                    $existing = Event::where('space_id', $space->id)->where('action', $action)->first();
                    if ($existing) {
                        $referenceIds[$item['key']] = $existing->record_id;

                        continue;
                    }
                    $payload = $item['data'];
                    foreach ($item['references'] ?? [] as $field => $key) {
                        abort_unless(isset($referenceIds[$key]) && in_array($field, ['account_id', 'payment_account_id', 'medical_need_record_id', 'recurring_record_id', 'transaction_id'], true), 422, 'Unresolved onboarding reference.');
                        $payload[$field] = $referenceIds[$key];
                    }
                    $record = $records->save($owner, $space, $item['kind'], $item['name'], $payload);
                    $referenceIds[$item['key']] = $record->id;
                    Event::create(['space_id' => $space->id, 'actor_id' => $owner->id, 'record_id' => $record->id, 'action' => $action]);
                }
                $space->update(['settings' => [...($space->settings ?? []), 'coverage_notes' => $data['coverage_notes']]]);
                Event::create(['space_id' => $space->id, 'actor_id' => $owner->id, 'action' => 'household_onboarding', 'after' => ['transactions' => count($rows), 'accounts' => count($data['accounts']), 'budget_targets' => count($data['budgets'] ?? []), 'billing_impact' => 0]]);
            });
            if ($this->option('apply')) {
                DB::commit();
                $this->info('Private import applied. Source corrections and financial access boundaries preserved.');
            } else {
                DB::rollBack();
                $this->info('Dry run validated all records and references, then rolled back. No financial records changed.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return self::SUCCESS;
    }
}
