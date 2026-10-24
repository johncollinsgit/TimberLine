<?php

namespace App\Services\Pos;

use App\Models\User;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\SaleSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/** A prepared basket is not a payment or a stock reservation. */
final class PosHandoffs
{
    public function __construct(private PosStore $store, private PosAccess $access, private PosCatalog $catalog, private PosService $pos) {}

    public function prepare(string $register, array $input): array
    {
        $scope = $this->access->register($register, 'catalog');
        $old = $this->store->rows('handoffs', $scope['tenant_id'])->where('request_id', $input['request_id'])->first();
        if ($old) {
            $this->assertCreator($scope, $old);
            if ($old->register_id === $register && $old->state === 'cancelled' && $this->payload($old)['input'] === null) {
                return $this->projection($old);
            }
            if ($old->register_id !== $register || Contract::hash($this->payload($old)['input']) !== Contract::hash($input)) {
                throw new PosException('request_conflict');
            }

            return $this->projection($old);
        }
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (! $scope['register']->active || empty($settings['tax_valid_until']) || strtotime($settings['tax_valid_until']) <= time()) {
            throw new PosException('register_or_tax_unavailable', 503);
        }
        $terms = $this->catalog->terms($scope, $input);
        $id = bin2hex(random_bytes(16));
        $payload = ['input' => $input, 'terms_hash' => Contract::hash($terms),
            'binding_hash' => $this->access->binding($scope)->fingerprint(), 'lines' => $terms['display'],
            'amount_minor' => (new SaleSnapshot($id, 'usd', $terms['lines']))->amountMinor];
        try {
            $this->store->rows('handoffs', $scope['tenant_id'])->insert($this->store->values($scope['tenant_id'], [
                'id' => $id, 'register_id' => $register, 'actor_id' => $scope['actor_id'], 'device_id' => $scope['device_id'],
                'request_id' => $input['request_id'], 'payload' => $this->store->encrypt($payload), 'payload_hash' => Contract::hash($payload),
                'state' => 'ready', 'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]));
        } catch (QueryException $error) {
            if (! $this->store->rows('handoffs', $scope['tenant_id'])->where('request_id', $input['request_id'])->exists()) {
                throw $error;
            }

            return $this->prepare($register, $input);
        }

        return $this->status($register, $id);
    }

    public function status(string $register, string $id): array
    {
        $scope = $this->access->register($register);
        $row = $this->row($scope, $id);
        $this->assertCreator($scope, $row);

        return $this->projection($row);
    }

    public function cancel(string $register, string $id): array
    {
        $scope = $this->access->register($register);

        return $this->store->db->transaction(function () use ($scope, $id): array {
            $row = $this->row($scope, $id, true);
            $this->assertCreator($scope, $row);
            if ($row->state === 'claimed') {
                throw new PosException('handoff_already_on_iphone');
            }
            $this->store->rows('handoffs', $scope['tenant_id'])->where('id', $id)->update(['state' => 'cancelled']);

            return ['cancelled' => true];
        });
    }

    public function cancelRequest(string $register, string $requestId): array
    {
        $scope = $this->access->register($register);
        $row = $this->store->rows('handoffs', $scope['tenant_id'])->where('request_id', $requestId)->first();
        if ($row) {
            return $this->cancel($register, $row->id);
        }
        // A cancellation tombstone wins against a delayed preparation request.
        // It cannot appear in the native inbox or acquire a payment operation.
        $payload = ['input' => null, 'lines' => [], 'amount_minor' => 0];
        try {
            $this->store->rows('handoffs', $scope['tenant_id'])->insert($this->store->values($scope['tenant_id'], [
                'id' => bin2hex(random_bytes(16)), 'register_id' => $register, 'actor_id' => $scope['actor_id'], 'device_id' => $scope['device_id'],
                'request_id' => $requestId, 'payload' => $this->store->encrypt($payload), 'payload_hash' => Contract::hash($payload),
                'state' => 'cancelled', 'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]));
        } catch (QueryException $error) {
            if (! $this->store->rows('handoffs', $scope['tenant_id'])->where('request_id', $requestId)->exists()) {
                throw $error;
            }

            return $this->cancelRequest($register, $requestId);
        }

        return ['cancelled' => true];
    }

    public function inbox(string $register): array
    {
        $scope = $this->access->register($register, 'catalog');
        if ($this->access->isWeb()) {
            throw new PosException('native_payment_app_required', 403);
        }
        $rows = $this->store->rows('handoffs', $scope['tenant_id'])->where('register_id', $register)
            ->where('expires_at', '>', now())->where(function ($q) use ($scope): void {
                $q->where('state', 'ready')->orWhere(fn ($q) => $q->where('state', 'claimed')->where('claimed_by', $scope['actor_id'])->where('claimed_device', $scope['device_id']));
            })->orderBy('created_at')->limit(50)->get();

        return ['handoffs' => $rows->filter(fn ($row) => $this->creatorActive($scope, $row))
            ->map(fn ($row) => $this->projection($row))->filter(fn ($value) => ! $value['can_leave'])->values()->all()];
    }

    public function claim(string $register, string $id): array
    {
        $scope = $this->access->register($register, 'quote'); // Explicit native payment token, assigned install.
        $key = 'pos-handoff-'.hash('sha256', $this->store->runtime().':'.$scope['tenant_id'].':'.$register.':'.$id);

        return Cache::lock($key, 180)->block(5, fn () => $this->claimLocked($register, $id));
    }

    private function claimLocked(string $register, string $id): array
    {
        $scope = $this->access->register($register, 'quote');
        $row = $this->store->db->transaction(function () use ($scope, $id): object {
            $row = $this->row($scope, $id, true);
            if (! $this->creatorActive($scope, $row)) {
                throw new PosException('preparing_staff_access_revoked', 403);
            }
            if (! in_array($row->state, ['ready', 'claimed'], true)) {
                throw new PosException('handoff_unavailable');
            }
            if ($row->state === 'claimed') {
                if ((int) $row->claimed_by !== $scope['actor_id'] || $row->claimed_device !== $scope['device_id']) {
                    throw new PosException('handoff_claimed_by_another_device');
                }
            } else {
                if (strtotime($row->expires_at) <= time()) {
                    throw new PosException('handoff_expired');
                }
                $this->store->rows('handoffs', $scope['tenant_id'])->where('id', $id)->update([
                    'state' => 'claimed', 'claimed_by' => $scope['actor_id'], 'claimed_device' => $scope['device_id'],
                ]);
            }

            return $row;
        }, 3);
        // No Shopify/provider HTTP while holding a database transaction.
        $payload = $this->payload($row);
        if (! hash_equals($payload['binding_hash'], $this->access->binding($scope)->fingerprint())) {
            throw new PosException('merchant_binding_changed');
        }
        $existing = $this->store->rows('sales', $scope['tenant_id'])->where('request_id', $row->request_id)->first();
        if (! $existing && strtotime($row->expires_at) <= time()) {
            throw new PosException('handoff_expired');
        }
        try {
            $sale = $this->pos->quote($register, $payload['input'], $payload['terms_hash']);
        } catch (PosException $error) {
            // Only these definitive, pre-insert validation failures retire a basket.
            // Ambiguous commerce/HTTP/database outcomes retain the original claim.
            if (in_array($error->blocker, ['catalog_changed', 'handoff_terms_changed', 'quote_not_collectible', 'tax_review_expired'], true) && ! $existing) {
                $this->store->rows('handoffs', $scope['tenant_id'])->where('id', $id)->update(['state' => 'invalid']);
            }
            throw $error;
        }
        $this->store->rows('handoffs', $scope['tenant_id'])->where('id', $id)->update(['sale_id' => $sale['sale_id']]);

        return $sale;
    }

    public function receipt(string $register, string $id): array
    {
        $scope = $this->access->register($register);
        $row = $this->row($scope, $id);
        $this->assertCreator($scope, $row);
        $sale = $this->linkedSale($row);
        if (! $sale) {
            throw new PosException('receipt_not_confirmed');
        }

        return $this->pos->receipt($register, $sale->id);
    }

    private function row(array $scope, string $id, bool $lock = false): object
    {
        $query = $this->store->rows('handoffs', $scope['tenant_id'])->where('register_id', $scope['register']->id)->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new PosException('handoff_unavailable', 404);
        }

        return $row;
    }

    private function assertCreator(array $scope, object $row): void
    {
        if ((int) $row->actor_id !== $scope['actor_id'] || $row->device_id !== $scope['device_id']) {
            throw new PosException('handoff_unavailable', 404);
        }
    }

    private function creatorActive(array $scope, object $row): bool
    {
        $user = User::find($row->actor_id);
        $member = $this->store->db->table('tenant_user')->where('tenant_id', $scope['tenant_id'])->where('user_id', $row->actor_id)->first();

        return $user?->is_active && $user->hasVerifiedEmail() && $member?->membership_active
            && in_array($member->role, ['owner', 'tenant_owner', 'admin', 'manager', 'member'], true)
            && $this->store->rows('assignments', $scope['tenant_id'])->where('register_id', $row->register_id)
                ->where('user_id', $row->actor_id)->where('device_id', $row->device_id)->where('active', true)
                ->whereIn('role', ['cashier', 'manager', 'admin'])->exists();
    }

    private function payload(object $row): array
    {
        $payload = $this->store->decrypt($row->payload);
        if (! hash_equals($row->payload_hash, Contract::hash($payload))) {
            throw new PosException('handoff_integrity_error', 503);
        }

        return $payload;
    }

    private function linkedSale(object $row): ?object
    {
        return $this->store->rows('sales', (int) $row->tenant_id)->where('register_id', $row->register_id)
            ->where('request_id', $row->request_id)->where('actor_id', $row->claimed_by)->first();
    }

    private function projection(object $row): array
    {
        $payload = $this->payload($row);
        $sale = $row->state === 'claimed' ? $this->linkedSale($row) : null;

        return ['id' => $row->id, 'request_id' => $row->request_id, 'code' => strtoupper(substr($row->id, 0, 8)),
            'state' => $sale?->state ?? ($row->state === 'ready' && strtotime($row->expires_at) <= time() ? 'expired' : $row->state),
            'amount_minor' => $payload['amount_minor'], 'lines' => $payload['lines'], 'expires_at' => $row->expires_at,
            'sale_id' => $sale?->id, 'receipt_available' => $sale?->state === 'succeeded' && $sale?->stock_state === 'committed',
            'can_leave' => $sale ? (($sale->state === 'succeeded' && $sale->stock_state === 'committed')
                || (in_array($sale->state, ['failed', 'cancelled'], true) && $sale->stock_state === 'released'))
                : (in_array($row->state, ['cancelled', 'invalid'], true) || strtotime($row->expires_at) <= time())];
    }
}
