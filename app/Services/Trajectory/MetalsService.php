<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MetalsService
{
    public function quotes(): array
    {
        if (! config('trajectory.goldapi_key')) {
            return ['status' => 'not_connected', 'prices' => []];
        }

        return Cache::remember('trajectory:metals:quotes:v1', 60, function (): array {
            $prices = [];
            foreach (['gold' => 'XAU', 'silver' => 'XAG'] as $metal => $symbol) {
                try {
                    $response = Http::timeout(8)->withHeaders(['x-access-token' => config('trajectory.goldapi_key')])->get('https://www.goldapi.io/api/price/'.$symbol.'/USD');
                    if (! $response->successful() || ! is_numeric($response->json('price')) || $response->json('price') <= 0 || ! is_numeric($response->json('timestamp'))) {
                        continue;
                    }
                    $prices[$metal] = ['price_cents' => Money::cents($response->json('price')), 'timestamp' => (int) $response->json('timestamp'), 'source' => 'GoldAPI', 'unit' => 'troy_ounce'];
                } catch (\Throwable) { /* Preserve last observed quote without exposing provider credentials. */
                }
            }
            if ($prices) {
                Cache::put('trajectory:metals:last', $prices, now()->addDays(7));
            } else {
                $prices = Cache::get('trajectory:metals:last', []);
            }

            return ['status' => $prices ? (collect($prices)->every(fn ($p) => $p['timestamp'] >= time() - 180) ? 'current' : 'stale_or_market_closed') : 'unavailable', 'prices' => $prices];
        });
    }

    public function value(array $lot, ?int $priceCents): array
    {
        $quantity = BigDecimal::of($lot['quantity']);
        $grams = BigDecimal::of($lot['weight']);
        if ($lot['unit'] === 'troy_ounce') {
            $grams = $grams->multipliedBy('31.1034768');
        }
        $fineOunces = $grams->multipliedBy($quantity)->multipliedBy(($lot['weight_basis'] ?? 'gross') === 'fine' ? 10000 : $lot['purity_bps'])->dividedBy('311034.768', 12, RoundingMode::HALF_UP);
        $value = $priceCents === null ? null : $fineOunces->multipliedBy($priceCents)->toScale(0, RoundingMode::HALF_UP)->toInt();

        return ['fine_troy_ounces' => (string) $fineOunces, 'value_cents' => $value, 'cost_basis_cents' => $lot['cost_basis_cents'], 'gain_cents' => $value === null ? null : $value - $lot['cost_basis_cents'], 'resale_value_cents' => $value === null ? null : $value + ($lot['resale_adjustment_cents'] ?? 0)];
    }

    public function sell(User $user, Record $record, string $quantity, int $proceeds, int $version, ?int $transactionId = null): void
    {
        $space = \App\Models\Trajectory\Space::findOrFail($record->space_id);
        app(FinanceAccess::class)->authorize($user, $space);
        DB::transaction(function () use ($user, $space, $record, $quantity, $proceeds, $version, $transactionId): void {
            if ($transactionId) {
                $tx = \App\Models\Trajectory\Transaction::where('space_id', $space->id)->whereKey($transactionId)->lockForUpdate()->firstOrFail();
                abort_unless(! $tx->pending && ! $tx->removed && $tx->amount_cents === $proceeds && ! in_array($tx->flow, ['asset_transfer', 'asset_sale'], true), 422, 'Choose an unmatched deposit equal to sale proceeds.');
                app(LedgerService::class)->classify($user, $space, $tx, ['version' => $tx->version, 'category' => $tx->category, 'flow' => 'asset_sale', 'face_punched' => false, 'bullshit_spending' => false]);
            }
            $record = Record::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->kind === 'metal' && $record->version === $version, 409);
            $data = $record->data;
            $sold = BigDecimal::of($quantity);
            $total = BigDecimal::of($data['quantity']);
            abort_unless($sold->isPositive() && $sold->isLessThanOrEqualTo($total), 422, 'Quantity exceeds the remaining lot.');
            $basis = BigDecimal::of($data['cost_basis_cents'])->multipliedBy($sold)->dividedBy($total, 0, RoundingMode::HALF_UP)->toInt();
            $next = [...$data, 'quantity' => (string) $total->minus($sold), 'cost_basis_cents' => $data['cost_basis_cents'] - $basis];
            $record->update(['data' => $next, 'version' => $version + 1]);
            Event::create(['space_id' => $record->space_id, 'actor_id' => $user->id, 'action' => 'metal_sale', 'record_id' => $record->id, 'before' => $data, 'after' => ['remaining' => $next, 'proceeds_cents' => $proceeds, 'transaction_id' => $transactionId, 'sold_basis_cents' => $basis, 'gain_cents' => $proceeds - $basis]]);
        });
    }
}
