<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Jobs\Trajectory\SyncBank;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Invite;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\MetalsService;
use App\Services\Trajectory\Money;
use App\Services\Trajectory\PlaidService;
use App\Services\Trajectory\RecordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TrajectoryController extends Controller
{
    public function index(Request $request, FinanceAccess $access)
    {
        abort_unless(config('trajectory.enabled'), 404);

        return response()->view('trajectory.index', ['spaces' => $access->spaces($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function dashboard(Request $request, Space $space, FinanceAccess $access, DashboardService $dashboard)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['range' => ['nullable', Rule::in(['day', 'week', 'month', 'year', 'all', 'custom'])], 'scenario_id' => ['nullable', 'integer'], 'from' => 'required_if:range,custom|nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:1900-01-01', 'through' => 'required_if:range,custom|nullable|date_format:Y-m-d|after_or_equal:from|before_or_equal:today', 'seasonal' => 'sometimes|boolean']);

        return response()->json($dashboard->build($space, $data['range'] ?? 'month', $data['scenario_id'] ?? null, $data['from'] ?? null, $data['through'] ?? null, (bool) ($data['seasonal'] ?? false)))->header('Cache-Control', 'private, no-store');
    }

    public function evidence(Request $request, Space $space, FinanceAccess $access, LedgerService $ledger)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['ids' => 'required_without_all:date,account_id|array|min:1|max:5000', 'ids.*' => 'integer', 'date' => 'required_without_all:ids,account_id|date_format:Y-m-d|before_or_equal:today', 'account_id' => 'sometimes|integer']);

        if (isset($data['account_id'])) {
            Account::where('space_id', $space->id)->findOrFail($data['account_id']);
        }

        return response()->json($ledger->entries($space, $data['date'] ?? null, $data['date'] ?? null, $data['ids'] ?? null, $data['account_id'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function account(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['name' => 'required|string|max:160', 'kind' => ['required', Rule::in(['cash', 'credit', 'loan', 'investment'])], 'balance_cents' => 'required|integer|between:-100000000000,100000000000', 'observed_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'history_start' => 'nullable|date_format:Y-m-d|before_or_equal:today']);
        $account = Account::create(['space_id' => $space->id, 'source_key' => 'manual:'.Str::uuid(), 'name' => $data['name'], 'kind' => $data['kind'], 'balance_cents' => $data['balance_cents'], 'observed_at' => $data['observed_on'], 'history_start' => $data['history_start'] ?? null]);
        Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'action' => 'account_created', 'record_id' => $account->id]);

        return response()->json($account, 201);
    }

    public function updateAccount(Request $request, Space $space, Account $account, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($account->space_id === $space->id, 404);
        abort_if($account->connection_id, 422, 'Connected balances refresh from the bank.');
        $data = $request->validate(['balance_cents' => 'required|integer|between:-100000000000,100000000000', 'observed_on' => 'required|date_format:Y-m-d|before_or_equal:today']);
        Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'action' => 'balance_updated', 'record_id' => $account->id, 'before' => $account->only(['balance_cents', 'observed_at']), 'after' => $data]);
        $account->update(['balance_cents' => $data['balance_cents'], 'observed_at' => $data['observed_on']]);

        return response()->json(['ok' => true]);
    }

    public function classify(Request $request, Space $space, Transaction $transaction, LedgerService $ledger)
    {
        $data = $request->validate(['version' => 'required|integer', 'category' => ['required', Rule::in(config('trajectory.categories'))], 'flow' => ['required', Rule::in(['expense', 'income', 'refund', 'transfer', 'card_payment', 'asset_transfer', 'owner_wages', 'owner_distribution', 'reimbursement', 'debt_payment', 'duplicate'])], 'face_punched' => 'required|boolean', 'bullshit_spending' => 'required|boolean', 'learn' => 'sometimes|boolean']);

        return response()->json($ledger->classify($request->user(), $space, $transaction, $data, $data['learn'] ?? false));
    }

    public function bulkClassify(Request $request, Space $space, LedgerService $ledger)
    {
        $data = $request->validate([
            'transactions' => 'required|array|min:2|max:100',
            'transactions.*.id' => 'required|integer|distinct',
            'transactions.*.version' => 'required|integer|min:1',
            'category' => ['required', Rule::in(config('trajectory.categories'))],
            'flow' => ['required', Rule::in(['expense', 'income', 'refund', 'transfer', 'card_payment', 'asset_transfer', 'owner_wages', 'owner_distribution', 'reimbursement', 'debt_payment', 'duplicate'])],
            'face_punched' => 'required|boolean',
            'bullshit_spending' => 'required|boolean',
        ]);

        return response()->json(['updated_ids' => $ledger->bulkClassify($request->user(), $space, $data['transactions'], $data)]);
    }

    public function undo(Request $request, Space $space, Transaction $transaction, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($transaction->space_id === $space->id, 404);
        $request->validate(['version' => 'required|integer']);
        DB::transaction(function () use ($request, $space, $transaction): void {
            $tx = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            app(\App\Services\Trajectory\MedicalSharingService::class)->assertUnbound($tx);
            abort_unless($tx->version === (int) $request->input('version'), 409);
            $event = Event::where('space_id', $space->id)->where('record_id', $tx->id)->whereIn('action', ['classify', 'undo_classify'])->latest('id')->first();
            abort_unless($event && $event->action === 'classify' && ($event->after['version'] ?? null) === $tx->version, 409, 'Only the latest classification can be undone.');
            if (! empty($event->after['created_rule_id'])) {
                Record::where('space_id', $space->id)->where('kind', 'rule')->whereKey($event->after['created_rule_id'])->update(['active' => false]);
            }
            $before = $tx->only(array_keys($event->before));
            $tx->update([...$event->before, 'version' => $tx->version + 1]);
            Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'action' => 'undo_classify', 'record_id' => $tx->id, 'before' => $before, 'after' => $tx->only(array_keys($before))]);
        });

        return response()->json(['ok' => true]);
    }

    public function split(Request $request, Space $space, Transaction $transaction, LedgerService $ledger)
    {
        $data = $request->validate(['version' => 'required|integer', 'splits' => 'required|array|min:1|max:10', 'splits.*.space_id' => 'required|integer', 'splits.*.amount_cents' => 'required|integer']);
        $ledger->split($request->user(), $space, $transaction, $data['splits'], $data['version']);

        return response()->json(['ok' => true]);
    }

    public function saveRecord(Request $request, Space $space, RecordService $records, ?Record $record = null)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(config('trajectory_records')))], 'name' => 'required|string|max:160', 'data' => 'required|array', 'version' => 'nullable|integer']);

        return response()->json($records->save($request->user(), $space, $data['kind'], $data['name'], $data['data'], $record, $data['version'] ?? null));
    }

    public function archiveRecord(Request $request, Space $space, Record $record, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($record->space_id === $space->id, 404);
        $data = $request->validate(['version' => 'required|integer']);
        DB::transaction(function () use ($record, $space, $request, $data): void {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $record = Record::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->active && $record->version === $data['version'], 409);
            app(\App\Services\Trajectory\MedicalSharingService::class)->archive($request->user(), $space, $record);
            $record->update(['active' => false, 'version' => $record->version + 1]);
            Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'action' => 'archive_'.$record->kind, 'record_id' => $record->id]);
        });

        return response()->json(['ok' => true]);
    }

    public function sellMetal(Request $request, Space $space, Record $record, FinanceAccess $access, MetalsService $metals)
    {
        $access->authorize($request->user(), $space);
        abort_unless($record->space_id === $space->id, 404);
        $data = $request->validate(['quantity' => 'required|numeric|gt:0', 'proceeds_cents' => 'required|integer|min:0', 'version' => 'required|integer', 'transaction_id' => 'nullable|integer']);
        $metals->sell($request->user(), $record, (string) $data['quantity'], $data['proceeds_cents'], $data['version'], $data['transaction_id'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function previewImport(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['file' => 'required|file|max:5120|mimes:csv,txt,xlsx', 'kind' => ['required', Rule::in(['transactions', 'payroll', 'monarch'])], 'account_id' => 'nullable|integer']);
        $account = null;
        if ($data['kind'] === 'transactions') {
            $account = Account::where('space_id', $space->id)->whereKey($data['account_id'] ?? 0)->firstOrFail();
        }
        abort_if($data['kind'] === 'payroll' && $space->kind !== 'business', 422);
        $file = $request->file('file');
        if ($data['kind'] === 'monarch') {
            $rows = app(\App\Services\Trajectory\MonarchImportService::class)->read($file->getRealPath());
            $token = Str::random(48);
            Cache::put('trajectory:import:'.hash('sha256', $token), encrypt(['user_id' => $request->user()->id, 'space_id' => $space->id, 'kind' => 'monarch', 'rows' => $rows]), now()->addMinutes(20));

            return response()->json(['token' => $token, 'count' => count($rows), 'preview' => array_slice($rows, 0, 15), 'accounts' => array_values(array_unique(array_column($rows, 'source_account')))]);
        }
        if ($file->getClientOriginalExtension() === 'xlsx') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx;
            $reader->setReadDataOnly(true);
            $book = $reader->load($file->getRealPath());
            abort_if($book->getActiveSheet()->getHighestDataRow() > 5001, 422, 'Import at most 5,000 rows.');
            $raw = $book->getActiveSheet()->toArray(null, false, false, false);
            $book->disconnectWorksheets();
        } else {
            $handle = fopen($file->getRealPath(), 'r');
            $raw = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $raw[] = $row;
                abort_if(count($raw) > 5001, 422, 'Import at most 5,000 rows.');
            }
            fclose($handle);
        }
        $headers = array_map(fn ($h) => trim(ltrim((string) $h, "\xEF\xBB\xBF")), array_shift($raw) ?? []);
        abort_if(count(array_unique($headers)) !== count($headers), 422, 'Duplicate column names.');
        $rows = [];
        foreach ($raw as $index => $cells) {
            if (! array_filter($cells, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            abort_unless(count($cells) === count($headers), 422, 'Column count differs on row '.($index + 2).'.');
            $row = array_combine($headers, $cells);
            if ($data['kind'] === 'transactions') {
                if (isset($row['id'])) {
                    $row['id'] = (string) $row['id'];
                }
                $row = Validator::make($row, ['id' => 'required|string|max:100', 'date' => 'required|date_format:Y-m-d|before_or_equal:today', 'merchant' => 'required|string|max:160', 'amount' => 'required|regex:/^-?\d{1,10}(\.\d{1,2})?$/', 'category' => ['nullable', Rule::in(config('trajectory.categories'))]])->validate();
                $rows[] = ['id' => $row['id'], 'date' => $row['date'], 'merchant' => $row['merchant'], 'amount_cents' => Money::cents($row['amount']), 'category' => $row['category'] ?? null];
            } else {
                $row = app(RecordService::class)->validate($space, 'payroll', $row);
                $rows[] = $row;
            }
        }
        abort_if(! $rows, 422, 'The file contains no records.');
        $token = Str::random(48);
        Cache::put('trajectory:import:'.hash('sha256', $token), encrypt(['user_id' => $request->user()->id, 'space_id' => $space->id, 'account_id' => $account?->id, 'kind' => $data['kind'], 'rows' => $rows]), now()->addMinutes(20));

        return response()->json(['token' => $token, 'count' => count($rows), 'preview' => array_slice($rows, 0, 15), 'columns' => $headers]);
    }

    public function confirmImport(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $request->validate(['token' => 'required|string|max:100', 'account_kinds' => 'sometimes|array|max:100', 'account_kinds.*' => ['required', Rule::in(['cash', 'credit', 'loan', 'investment'])]]);
        $key = 'trajectory:import:'.hash('sha256', $request->input('token'));
        $cached = Cache::get($key);
        abort_unless($cached, 410, 'Import preview expired.');
        $payload = decrypt($cached);
        abort_unless($payload['user_id'] === $request->user()->id && $payload['space_id'] === $space->id, 403);
        DB::transaction(function () use ($payload, $request, $space): void {
            if ($payload['kind'] === 'monarch') {
                app(\App\Services\Trajectory\MonarchImportService::class)->import($request->user(), $space, $payload['rows'], $request->input('account_kinds', []));
            } elseif ($payload['kind'] === 'transactions') {
                $account = Account::where('space_id', $space->id)->findOrFail($payload['account_id']);
                app(LedgerService::class)->ingest($account, $payload['rows']);
                $account->update(['history_start' => Transaction::where('account_id', $account->id)->min('posted_on')]);
            } else {
                foreach ($payload['rows'] as $row) {
                    app(RecordService::class)->save($request->user(), $space, 'payroll', $row['employee'], $row);
                }
            }
            Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'action' => 'import_'.$payload['kind'], 'after' => ['row_count' => count($payload['rows'])]]);
        });
        Cache::forget($key);

        return response()->json(['ok' => true, 'count' => count($payload['rows'])]);
    }

    public function linkBank(Request $request, Space $space, FinanceAccess $access, PlaidService $plaid)
    {
        $access->authorize($request->user(), $space);
        $request->validate(['connection_id' => 'nullable|integer']);
        $connection = $request->input('connection_id') ? Connection::where('space_id', $space->id)->findOrFail($request->input('connection_id')) : null;
        // A disconnected Item has had its provider token revoked. Start a fresh
        // Link session instead of silently attempting an invalid update session.
        if ($connection?->status === 'disconnected') {
            $connection = null;
        }

        return response()->json($plaid->link($space, $connection));
    }

    public function exchangeBank(Request $request, Space $space, FinanceAccess $access, PlaidService $plaid)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['public_token' => 'required|string|max:500', 'institution_name' => 'nullable|string|max:160']);
        $connection = $plaid->exchange($space, $data['public_token'], $data['institution_name'] ?? null);
        SyncBank::dispatch($connection->id);

        return response()->json(['ok' => true]);
    }

    public function syncBank(Request $request, Space $space, Connection $connection, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($connection->space_id === $space->id, 404);
        $data = $request->validate(['institution_name' => 'nullable|string|max:160']);
        if (filled($data['institution_name'] ?? null)) {
            $connection->update(['institution_name' => $data['institution_name']]);
        }
        SyncBank::dispatch($connection->id);

        return response()->json(['ok' => true]);
    }

    public function disconnectBank(Request $request, Space $space, Connection $connection, FinanceAccess $access, PlaidService $plaid)
    {
        $access->authorize($request->user(), $space);
        abort_unless($connection->space_id === $space->id, 404);
        $plaid->call('/item/remove', ['access_token' => $connection->access_token]);
        $connection->update(['status' => 'disconnected', 'access_token' => '']);

        return response()->json(['ok' => true]);
    }

    public function invite(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($space->kind === 'household' && $space->owner_user_id === $request->user()->id, 403);
        $data = $request->validate(['email' => 'required|email|max:254']);
        $token = Str::random(48);
        Invite::create(['space_id' => $space->id, 'email' => strtolower($data['email']), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);

        return response()->json(['invite_url' => route('trajectory.index', ['invite' => $token]), 'expires_at' => now()->addDays(7)->toIso8601String()]);
    }

    public function acceptInvite(Request $request)
    {
        abort_unless(config('trajectory.enabled') && $request->user()->email_verified_at, 403);
        $request->validate(['token' => 'required|string|max:100']);
        DB::transaction(function () use ($request): void {
            $invite = Invite::where('token_hash', hash('sha256', $request->input('token')))->lockForUpdate()->firstOrFail();
            abort_unless(! $invite->accepted_at && $invite->expires_at->isFuture() && strtolower($request->user()->email) === $invite->email, 403);
            $space = Space::findOrFail($invite->space_id);
            abort_unless($space->enabled && $space->kind === 'household', 403);
            DB::table('trajectory_members')->insertOrIgnore(['space_id' => $space->id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $invite->update(['accepted_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    public function revokeMember(Request $request, Space $space, int $user, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($space->kind === 'household' && $space->owner_user_id === $request->user()->id, 403);
        DB::table('trajectory_members')->where('space_id', $space->id)->where('user_id', $user)->delete();

        return response()->json(['ok' => true]);
    }

    public function linkSpaces(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['business_id' => 'required|integer']);
        $business = Space::findOrFail($data['business_id']);
        $access->authorize($request->user(), $business);
        abort_unless($space->kind === 'household' && $space->owner_user_id === $request->user()->id && $business->kind === 'business', 403);
        DB::table('trajectory_links')->updateOrInsert(['household_id' => $space->id, 'business_id' => $business->id], ['created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['discretionary_categories' => 'required|array', 'discretionary_categories.*' => [Rule::in(config('trajectory.categories'))]]);
        $space->update(['settings' => [...($space->settings ?? []), ...$data]]);

        return response()->json(['ok' => true]);
    }

    public function reconciliation(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);

        return response()->json(app(\App\Services\Trajectory\ReconciliationService::class)->candidates($request->user(), $space));
    }

    public function reconcile(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['left_id' => 'required|integer', 'right_id' => 'required|integer', 'left_version' => 'required|integer', 'right_version' => 'required|integer', 'kind' => ['required', Rule::in(['transfer', 'card_payment', 'owner_wages', 'owner_distribution', 'duplicate'])]]);
        app(\App\Services\Trajectory\ReconciliationService::class)->confirm($request->user(), $data);

        return response()->json(['ok' => true]);
    }

    public function combined(Request $request, Space $space)
    {
        $data = $request->validate(['business_id' => 'required|integer']);

        return response()->json(app(\App\Services\Trajectory\ReconciliationService::class)->combined($request->user(), $space->id, (int) $data['business_id']));
    }

    public function verifySms(Request $request, Space $space)
    {
        $data = $request->validate(['phone' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'consent' => 'required|accepted']);
        app(\App\Services\Trajectory\SmsService::class)->verify($request->user(), $space, $data['phone']);

        return response()->json(['ok' => true]);
    }

    public function confirmSms(Request $request, Space $space)
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        app(\App\Services\Trajectory\SmsService::class)->confirm($request->user(), $space, $data['code']);

        return response()->json(['ok' => true]);
    }
}
