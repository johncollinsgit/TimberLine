<?php

namespace App\Services\Trajectory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class ChaseImportService
{
    public function read(string $path): array
    {
        $h = fopen($path, 'r');
        abort_unless($h, 422, 'Cannot read Chase CSV.');
        try {
            $headers = fgetcsv($h, 0, ',', '"', '');
            abort_unless(is_array($headers) && ! array_diff(['Transaction Date', 'Post Date', 'Description', 'Category', 'Type', 'Amount'], $headers), 422, 'Choose an original Chase activity CSV.');
            $rows = [];
            $occurrences = [];
            while (($cells = fgetcsv($h, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                abort_unless(count($cells) === count($headers), 422, 'Unexpected Chase CSV columns.');
                $r = array_combine($headers, $cells);
                Validator::make($r, ['Post Date' => 'required|date_format:m/d/Y|before_or_equal:today', 'Description' => 'required|string|max:160', 'Type' => 'required|in:Sale,Payment,Fee,Return,Adjustment,Reversal', 'Amount' => 'required|regex:/^-?\d{1,10}(\.\d{1,2})?$/'])->validate();
                $amount = Money::cents($r['Amount']);
                $interest = str_contains(strtoupper($r['Description']), 'INTEREST CHARGE');
                $category = $interest ? 'interest' : match ($r['Category']) {
                    'Shopping' => 'shopping','Education' => 'education','Groceries' => 'groceries','Food & Drink' => 'dining','Gas' => 'transport','Travel' => 'travel','Fees & Adjustments' => 'fees',default => 'uncategorized'
                };
                $subscription = (bool) preg_match('/MICROSOFT.*(XBOX\s*GAME\s*PASS|ULTIMATE\s*1\s*MONT|365)/i', $r['Description']);
                if ($subscription && ! $interest) {
                    $category = 'subscriptions';
                }
                $flow = $r['Type'] === 'Payment' ? 'card_payment' : ($amount > 0 ? 'refund' : 'expense');
                // Identical legitimate rows retain occurrence counts; mutable category/memo fields are not identities.
                $key = hash('sha256', json_encode([$r['Transaction Date'], $r['Post Date'], $r['Description'], $r['Type'], $r['Amount']]));
                $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
                $rows[] = ['id' => $key.':'.$occurrences[$key], 'date' => CarbonImmutable::createFromFormat('m/d/Y', $r['Post Date'])->toDateString(), 'merchant' => $r['Description'], 'amount_cents' => $amount, 'category' => $category, 'source_provider' => 'chase_csv', 'original' => $r,
                    'import_classification' => ['category' => $category, 'flow' => $flow, 'reviewed' => false, 'face_punched' => false, 'bullshit_spending' => false, 'explanation' => $interest ? 'Chase explicitly identifies an interest charge.' : ($subscription ? 'Statement names a Microsoft recurring product; active status and renewal require review.' : 'Chase transaction type: '.$r['Type'].'. Imported category: '.$r['Category'].'. Review purpose; card payments excluded.')]];
                abort_if(count($rows) > 20000, 422, 'Too many rows.');
            }
            abort_if(! $rows, 422, 'No posted transactions found.');

            return $rows;
        } finally {
            fclose($h);
        }
    }
}
