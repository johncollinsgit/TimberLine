<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Support\Facades\Crypt;

/** Server-backed sales survive the phone's next-sale action, sign-out and reinstall. */
final class PosSalesDirectory
{
    public function __construct(private PosStore $store, private PosAccess $access, private PosService $sales) {}

    public function read(string $register, ?string $cursor = null, bool $unattributed = false): array
    {
        $scope = $this->access->register($register);
        $query = $this->store->rows('sales', $scope['tenant_id'])->where('register_id', $register)->where('state', 'succeeded');
        if ($scope['role'] === 'member') {
            $query->where('actor_id', $scope['actor_id']);
        }
        if ($cursor) {
            try {
                $before = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
                if ($before['tenant'] !== $scope['tenant_id'] || $before['runtime'] !== $this->store->runtime()
                    || $before['register'] !== $register || $before['actor'] !== $scope['actor_id']
                    || $before['unattributed'] !== $unattributed || $before['expires'] <= time()) {
                    throw new \RuntimeException;
                }
                $query->where(fn ($q) => $q->where('created_at', '<', $before['date'])
                    ->orWhere(fn ($q) => $q->where('created_at', $before['date'])->where('id', '<', $before['id'])));
            } catch (\Throwable) {
                throw new PosException('sales_cursor_invalid', 422);
            }
        }
        $found = $query->orderByDesc('created_at')->orderByDesc('id')->limit(201)->get();
        $rows = [];
        $scanned = 0;
        $last = null;
        $issues = [];
        foreach ($found->take(200) as $sale) {
            $last = $sale;
            $scanned++;
            try {
                $receipt = $this->sales->receipt($register, $sale->id);
                if ($unattributed && $receipt['customer'] !== null) {
                    continue;
                }
                $rows[] = ['id' => $sale->id, 'request_id' => $sale->request_id, 'number' => $receipt['receipt_number'], 'occurred_at' => $sale->created_at,
                    'amount_minor' => (int) $sale->amount_minor, 'tax_minor' => $receipt['tax_minor'],
                    'refunded_minor' => $receipt['refunded_minor'], 'currency' => 'USD', 'customer' => $receipt['customer'],
                    'tender' => match ($receipt['payment_source']) {
                        'everbranch_cash' => 'cash', 'everbranch_rewards_only' => 'rewards', default => 'card'
                    },
                    'lines' => $receipt['lines']];
            } catch (PosException) {
                $issues[] = 'Some receipts need payment or identity review.';
            }
            if (count($rows) === 20) {
                break;
            }
        }
        $more = $last && $found->count() > $scanned;
        $next = $more ? Crypt::encryptString(json_encode(['tenant' => $scope['tenant_id'], 'runtime' => $this->store->runtime(),
            'register' => $register, 'actor' => $scope['actor_id'], 'unattributed' => $unattributed,
            'date' => $last->created_at, 'id' => $last->id, 'expires' => time() + 1800], JSON_THROW_ON_ERROR)) : null;

        return ['sales' => $rows, 'next_cursor' => $next, 'review_items' => array_values(array_unique($issues)), 'as_of' => now()->toIso8601String()];
    }
}
