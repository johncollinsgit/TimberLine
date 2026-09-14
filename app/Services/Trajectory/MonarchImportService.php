<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MonarchImportService
{
    public function cashBalances(string $path, array $kinds): array
    {
        $handle = fopen($path, 'r');
        abort_unless($handle, 422, 'Cannot read balance CSV.');
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            abort_unless(is_array($headers) && ! array_diff(['Date', 'Balance', 'Account'], $headers), 422, 'Choose an original Monarch balance export.');
            $days = [];
            $count = 0;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                abort_unless(count($cells) === count($headers), 422, 'Unexpected balance columns.');
                $row = array_combine($headers, $cells);
                Validator::make($row, ['Date' => 'required|date_format:Y-m-d|before_or_equal:today', 'Balance' => 'required|regex:/^-?\d{1,10}(\.\d{1,2})?$/', 'Account' => 'required|string|max:160'])->validate();
                abort_if(++$count > 100000, 422, 'Too many balance observations.');
                // Never reinterpret a manual liability as a bank cash balance.
                if (($kinds[$row['Account']] ?? null) !== 'cash') {
                    continue;
                }
                $amount = Money::cents($row['Balance']);
                abort_if(isset($days[$row['Date']][$row['Account']]) && $days[$row['Date']][$row['Account']] !== $amount, 422, 'Conflicting balances for one account and date.');
                $days[$row['Date']][$row['Account']] = $amount;
            }
            ksort($days);

            return $days;
        } finally {
            fclose($handle);
        }
    }

    public function read(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'Cannot read the import file.']);
        }
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($headers) || array_diff(['Date', 'Merchant', 'Category', 'Account', 'Amount', 'Id'], $headers)) {
                throw ValidationException::withMessages(['file' => 'Choose the original Monarch transactions CSV.']);
            }
            $rows = [];
            $seen = [];
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                abort_unless(count($cells) === count($headers), 422, 'Unexpected CSV column count.');
                $source = array_combine($headers, $cells);
                Validator::make($source, ['Date' => 'required|date_format:Y-m-d|before_or_equal:today', 'Merchant' => 'required|string|max:160', 'Account' => 'required|string|max:160', 'Category' => 'required|string|max:160', 'Amount' => 'required|regex:/^-?\d{1,10}(\.\d{1,2})?$/', 'Id' => 'required|string|max:100'])->validate();
                $identity = $source['Account'].':'.$source['Id'];
                if (isset($seen[$identity])) {
                    abort_unless($seen[$identity] === $source, 422, 'A source ID has conflicting rows.');

                    continue;
                }
                $seen[$identity] = $source;
                $amount = Money::cents($source['Amount']);
                $category = $this->category($source['Category']);
                $sourceCategory = strtolower(trim($source['Category']));
                $flow = match (true) {
                    (bool) preg_match('/\b(CASHOUT|FUNDS TRANSFER|DEPOSIT@MOBILE)\b/i', $source['Original Statement'] ?? '') => 'transfer',
                    $sourceCategory === 'transfer', in_array($sourceCategory, ['emergency fund', 'retirement'], true) => 'transfer',
                    $sourceCategory === 'credit card payment' => 'card_payment',
                    $sourceCategory === 'interest' && $amount > 0 => 'income',
                    $amount > 0 && in_array($sourceCategory, ['paychecks', 'business income', 'other income'], true) => 'income',
                    $amount > 0 => 'refund', default => 'expense',
                };
                $rows[] = ['id' => $source['Id'], 'date' => $source['Date'], 'merchant' => $source['Merchant'], 'amount_cents' => $amount, 'category' => $category,
                    'source_provider' => 'monarch_csv', 'source_account' => $source['Account'], 'original' => $source,
                    'import_classification' => ['category' => $category, 'flow' => $flow, 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false,
                        'explanation' => 'Imported Monarch category: '.$source['Category'].'. Source status: '.($source['Reviewed'] ?? 'unknown').'. Review purpose; transfers/card payments excluded and positive expense-category amounts treated as refunds.']];
                abort_if(count($rows) > 20000, 422, 'Import at most 20,000 transactions at once.');
            }
            abort_if(! $rows, 422, 'No transactions found.');

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public function category(string $label): string
    {
        $label = strtolower(trim(explode('(', $label)[0]));

        if (str_starts_with($label, 'tithe')) {
            return 'giving';
        }

        return match ($label) {
            'mortgage', 'household', 'deck payment' => 'housing',
            'gas & electric', 'fiber internet', 'business utilities & communication' => 'utilities',
            'groceries' => 'groceries', 'gas', 'business auto expenses', 'auto maintenance', 'auto payment' => 'transport',
            'medical', 'dentist', 'samaritans', 'counseling', 'fitness' => 'health',
            'life insurance', 'business insurance', 'verity insurance' => 'insurance',
            'education', 'homeschool' => 'education', 'date night' => 'dining',
            'subscriptions' => 'subscriptions', 'fun money', 'soccer', 'prime video' => 'entertainment',
            'clothing', 'electronics', 'john allowance', 'sarah allowance' => 'shopping',
            'interest' => 'interest', 'financial fees', 'financial & legal services' => 'fees',
            'paychecks', 'other income', 'business income' => 'income',
            'gifts' => 'giving',
            'travel & vacation', 'christmas' => 'travel', 'taxes' => 'taxes',
            default => 'uncategorized',
        };
    }

    public function import(User $user, Space $space, array $rows, array $kinds): array
    {
        app(FinanceAccess::class)->authorize($user, $space);

        return DB::transaction(function () use ($user, $space, $rows, $kinds) {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $count = 0;
            $accounts = [];
            foreach (collect($rows)->groupBy('source_account') as $name => $group) {
                abort_unless(in_array($kinds[$name] ?? null, ['cash', 'credit', 'loan', 'investment'], true), 422, 'Review every imported account type.');
                $key = 'monarch:'.$space->id.':'.hash('sha256', $name);
                $account = Account::firstOrCreate(['source_key' => $key], ['space_id' => $space->id, 'name' => $name, 'kind' => $kinds[$name], 'balance_cents' => null]);
                $count += app(LedgerService::class)->ingest($account, $group->all());
                $earliest = $group->min('date');
                if (! $account->history_start || $earliest < $account->history_start->toDateString()) {
                    $account->update(['history_start' => $earliest]);
                }
                $accounts[$name] = $account->id;
            }
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'monarch_import', 'after' => ['rows' => count($rows), 'changed' => $count, 'accounts' => count($accounts)]]);

            return ['changed' => $count, 'accounts' => $accounts, 'rows' => count($rows)];
        });
    }
}
