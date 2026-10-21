<?php

namespace App\Services\PointOfSale;

use App\Models\PosPaymentAttempt;
use App\Models\PosRefund;
use App\Models\PosRegister;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsiteOrderLine;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Local/test counter-sale rehearsal. No live provider, customer data or dispatch. */
class PosService
{
    private const OPEN = ['creating', 'awaiting_reader', 'processing', 'unknown', 'review'];

    public function __construct(private PosAccess $access, private SimulatedReader $reader) {}

    /** Authorize a saved snapshot for a GET/receipt without contacting the reader. */
    public function inspectAttempt(Tenant $tenant, User $actor, int $attemptId): PosPaymentAttempt
    {
        return $this->authorizedAttempt($tenant, $actor, $attemptId);
    }

    public function catalog(Tenant $tenant, User $actor, int $registerId): Collection
    {
        $register = $this->access->register($tenant, $actor, $registerId);

        return WebsiteProductVariant::query()->forTenantId($tenant->id)->where('is_available', true)
            ->whereHas('product', fn ($query) => $query->where('tenant_id', $tenant->id)->where('tenant_site_id', $register->tenant_site_id)
                ->where('status', 'active')->where('product_type', 'physical'))
            ->with('product')->orderBy('id')->limit(100)->get()->map(function ($variant) use ($tenant): array {
                $held = (int) WebsiteInventoryReservation::query()->forTenantId($tenant->id)
                    ->where('website_product_variant_id', $variant->id)->where('status', 'active')->sum('quantity');

                return ['variant_id' => $variant->id, 'title' => $variant->product->title, 'variant_title' => $variant->title,
                    'sku' => $variant->sku, 'price_cents' => $variant->price_cents,
                    'available_quantity' => $variant->product->track_inventory
                        ? max(0, (int) $variant->inventory_quantity - $held) : null];
            });
    }

    public function start(Tenant $tenant, User $actor, int $registerId, array $input): PosPaymentAttempt
    {
        if (DB::transactionLevel() > 0) {
            // An outer transaction must take the exclusive admission mutex
            // before authorization takes a shared current-read register lock.
            $this->admissionLock($tenant);
        }
        $this->access->register($tenant, $actor, $registerId);
        $providerKey = $this->reader->key();
        $this->assertProvider(['provider_key' => $providerKey]);
        $request = Validator::make($input, ['request_id' => ['required', 'uuid'], 'customer_id' => ['prohibited'], 'website_customer_id' => ['prohibited'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99']])->validate();
        $lines = collect($request['lines'])->map(fn ($line) => ['variant_id' => (int) $line['variant_id'], 'quantity' => (int) $line['quantity']])
            ->sortBy('variant_id')->values()->all();
        $hash = $this->hash(['register_id' => $registerId, 'lines' => $lines]);
        $attempt = DB::transaction(function () use ($tenant, $actor, $registerId, $request, $lines, $hash, $providerKey): PosPaymentAttempt {
            // Serialize immutable request admission before locking a missing
            // unique key. Different registers must not form an RR gap-lock cycle.
            $this->admissionLock($tenant);
            $register = PosRegister::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($registerId);
            $this->access->register($tenant, $actor, $registerId);
            $existing = PosPaymentAttempt::query()->where('tenant_id', $tenant->id)->where('request_id', $request['request_id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This checkout request already has different terms.');

                return $existing;
            }
            abort_if(PosPaymentAttempt::query()->where('tenant_id', $tenant->id)->where('pos_register_id', $registerId)
                ->whereIn('status', self::OPEN)->lockForUpdate()->first() !== null,
                409, 'Resolve the current reader payment before starting another sale.');
            $subtotal = 0;
            $frozen = [];
            foreach ($lines as $line) {
                $variant = WebsiteProductVariant::query()->forTenantId($tenant->id)->lockForUpdate()->with('product')->findOrFail($line['variant_id']);
                abort_unless($variant->product?->tenant_id === $tenant->id && $variant->product?->tenant_site_id === $register->tenant_site_id
                    && $variant->product?->status === 'active' && $variant->product?->product_type === 'physical' && $variant->is_available,
                    422, 'This catalog item is unavailable for this register.');
                abort_unless($variant->price_cents >= 1 && $variant->price_cents <= 100000000, 422, 'Review the catalog price before checkout.');
                $tracked = $variant->product->track_inventory;
                if ($tracked) {
                    abort_if($variant->inventory_quantity === null, 422, 'Tracked stock is unknown. Review inventory before this sale.');
                    $held = (int) WebsiteInventoryReservation::query()->forTenantId($tenant->id)->where('website_product_variant_id', $variant->id)
                        ->where('status', 'active')->lockForUpdate()->get()->sum('quantity');
                    abort_if($variant->inventory_quantity - $held < $line['quantity'], 409, 'There is not enough unreserved stock for this sale.');
                }
                $total = $variant->price_cents * $line['quantity'];
                $subtotal += $total;
                abort_if($subtotal > 100000000, 422, 'The simulated sale exceeds the supported amount.');
                $frozen[] = ['variant_id' => $variant->id, 'title' => $variant->product->title, 'variant_title' => $variant->title,
                    'sku' => $variant->sku, 'quantity' => $line['quantity'], 'unit_price_cents' => $variant->price_cents,
                    'line_total_cents' => $total, 'track_inventory' => $tracked];
            }
            $tax = intdiv($subtotal * $register->tax_rate_bps + 5000, 10000);
            $order = WebsiteOrder::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $register->tenant_site_id,
                'number' => 'SIM-POS-'.strtoupper(Str::random(12)), 'lookup_token' => Str::random(56), 'currency' => 'usd',
                'source' => 'pos_simulation', 'payment_status' => 'pending', 'fulfillment_status' => 'unfulfilled', 'fulfillment_method' => 'pickup',
                'order_status' => 'open', 'subtotal_cents' => $subtotal, 'discount_cents' => 0, 'shipping_cents' => 0,
                'tax_cents' => $tax, 'total_cents' => $subtotal + $tax,
                'customer_snapshot' => ['name' => 'Simulated counter sale'], 'service_request' => ['simulation' => true, 'register_id' => $registerId]]);
            foreach ($frozen as $line) {
                WebsiteOrderLine::query()->create(['tenant_id' => $tenant->id, 'website_order_id' => $order->id,
                    'website_product_variant_id' => $line['variant_id'], 'title' => $line['title'], 'product_type' => 'physical',
                    'quantity' => $line['quantity'], 'unit_price_cents' => $line['unit_price_cents'], 'line_total_cents' => $line['line_total_cents'],
                    'snapshot' => ['track_inventory' => $line['track_inventory'], 'sku' => $line['sku'], 'variant_title' => $line['variant_title'], 'simulation' => true]]);
                if ($line['track_inventory']) {
                    WebsiteInventoryReservation::query()->create(['tenant_id' => $tenant->id, 'website_order_id' => $order->id,
                        'website_product_variant_id' => $line['variant_id'], 'quantity' => $line['quantity'], 'status' => 'active',
                        // Expiry is informational: unresolved payments never automatically release stock.
                        'expires_at' => now()->addHours(23)]);
                }
            }
            $snapshot = ['provider_key' => $providerKey, 'environment' => 'simulation', 'tenant_id' => $tenant->id, 'register_id' => $registerId, 'order_id' => $order->id, 'site_id' => $register->tenant_site_id,
                'merchant_id' => $register->merchant_id, 'reader_id' => $register->reader_id, 'location_id' => $register->website_fulfillment_location_id,
                'currency' => 'usd', 'amount_cents' => $order->total_cents, 'subtotal_cents' => $subtotal, 'tax_cents' => $tax,
                'tax_rate_bps' => $register->tax_rate_bps, 'tax_basis' => $register->tax_basis, 'nonce' => Str::random(40), 'lines' => $frozen];
            $attempt = PosPaymentAttempt::query()->create(['tenant_id' => $tenant->id, 'pos_register_id' => $registerId,
                'website_order_id' => $order->id, 'user_id' => $actor->id, 'request_id' => $request['request_id'],
                'request_hash' => $hash, 'snapshot' => $snapshot, 'status' => 'creating']);
            $this->event($order, $actor, 'pos_simulation_started', 'Fictional reader payment reserved. No real payment is requested.');

            return $attempt;
        }, 3);

        // Dispatch is claimed durably once. Repeated clicks only reconcile the original request.
        $claimed = PosPaymentAttempt::query()->where('tenant_id', $tenant->id)->whereKey($attempt->id)->whereNull('provider_requested_at')
            ->where('status', 'creating')->update(['provider_requested_at' => now()]);
        if ($claimed) {
            try {
                $this->authorizedAttempt($tenant, $actor, $attempt->id);
                $evidence = $this->reader->createPayment($attempt->snapshot, $attempt->request_id);
                $this->applyPayment($tenant, $actor, $attempt->id, $evidence);
            } catch (\Throwable $exception) {
                $this->unknownPayment($tenant, $attempt->id);

                return $this->reloadAttempt($tenant, $attempt->id);
            }
        }

        return $this->reconcile($tenant, $actor, $attempt->id);
    }

    public function reconcile(Tenant $tenant, User $actor, int $attemptId): PosPaymentAttempt
    {
        $attempt = $this->authorizedAttempt($tenant, $actor, $attemptId);
        if (in_array($attempt->status, ['succeeded', 'failed', 'cancelled', 'review'], true)) {
            return $attempt;
        }
        try {
            $evidence = $attempt->provider_id ? $this->reader->payment($tenant->id, $attempt->provider_id)
                : $this->reader->findPayment($tenant->id, $attempt->request_id);
        } catch (\Throwable $exception) {
            $this->unknownPayment($tenant, $attemptId);

            return $this->reloadAttempt($tenant, $attempt->id);
        }
        if ($evidence) {
            return $this->applyPayment($tenant, $actor, $attemptId, $evidence);
        }
        $this->unknownPayment($tenant, $attemptId);

        return $this->reloadAttempt($tenant, $attempt->id);
    }

    public function simulate(Tenant $tenant, User $actor, int $attemptId, string $outcome): PosPaymentAttempt
    {
        $attempt = $this->authorizedAttempt($tenant, $actor, $attemptId);
        abort_unless(in_array($outcome, ['approved', 'declined', 'unknown', 'cancelled'], true), 422);
        if (in_array($attempt->status, ['succeeded', 'failed', 'cancelled', 'review'], true)) {
            return $attempt;
        }
        abort_unless($attempt->provider_id, 409, 'Reconcile the original request before controlling the simulated reader.');
        $this->reader->simulatePayment($tenant->id, $attempt->provider_id, $outcome);

        return $this->reconcile($tenant, $actor, $attemptId);
    }

    public function cancel(Tenant $tenant, User $actor, int $attemptId): PosPaymentAttempt
    {
        $attempt = $this->authorizedAttempt($tenant, $actor, $attemptId);
        if (in_array($attempt->status, ['succeeded', 'failed', 'cancelled', 'review'], true)) {
            return $attempt;
        }
        abort_unless($attempt->provider_id, 409, 'The original payment has an unknown outcome. Reconcile it before cancellation.');
        try {
            $this->reader->cancelPayment($tenant->id, $attempt->provider_id);
        } catch (\Throwable $exception) {
            $this->unknownPayment($tenant, $attemptId);
        }

        return $this->reconcile($tenant, $actor, $attemptId);
    }

    public function refund(Tenant $tenant, User $actor, int $attemptId, array $input): PosRefund
    {
        $attempt = $this->authorizedAttempt($tenant, $actor, $attemptId, true);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['request_id' => ['required', 'uuid'], 'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:1000']])->validate();
        $hash = $this->hash(['attempt_id' => $attemptId, 'amount_cents' => (int) $data['amount_cents'], 'reason' => trim($data['reason'])]);
        $refund = DB::transaction(function () use ($tenant, $actor, $attemptId, $data, $hash): PosRefund {
            // Keep admission lock ordering consistent with new sales; no
            // provider work occurs inside this retryable reservation transaction.
            $this->admissionLock($tenant);
            $attempt = $this->lockAttempt($tenant, $actor, $attemptId, true);
            $existing = PosRefund::query()->where('tenant_id', $tenant->id)->where('request_id', $data['request_id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->pos_payment_attempt_id === $attemptId && hash_equals($existing->request_hash, $hash), 409, 'This refund request already has different terms.');

                return $existing;
            }
            abort_unless($attempt->status === 'succeeded' && $attempt->stock_committed_at, 409, 'Only a verified simulated sale can be refunded.');
            abort_if((int) $data['amount_cents'] < $attempt->snapshot['amount_cents']
                && ($this->reader->capabilities()['partial_refunds'] ?? false) !== true,
                422, 'The original payment provider does not support partial refunds.');
            $reserved = (int) PosRefund::query()->where('tenant_id', $tenant->id)->where('pos_payment_attempt_id', $attemptId)
                ->whereIn('status', ['creating', 'unknown', 'pending', 'review', 'succeeded'])->lockForUpdate()->get()->sum('amount_cents');
            abort_if($reserved + (int) $data['amount_cents'] > $attempt->snapshot['amount_cents'], 409, 'This exceeds the remaining refundable amount, including unresolved refunds.');
            $snapshot = ['provider_key' => $attempt->snapshot['provider_key'], 'environment' => 'simulation', 'tenant_id' => $tenant->id, 'register_id' => $attempt->snapshot['register_id'],
                'order_id' => $attempt->website_order_id, 'merchant_id' => $attempt->snapshot['merchant_id'], 'payment_id' => $attempt->provider_id,
                'amount_cents' => (int) $data['amount_cents'], 'currency' => $attempt->snapshot['currency'], 'nonce' => Str::random(40)];
            $refund = PosRefund::query()->create(['tenant_id' => $tenant->id, 'pos_payment_attempt_id' => $attemptId, 'user_id' => $actor->id,
                'request_id' => $data['request_id'], 'request_hash' => $hash, 'snapshot' => $snapshot, 'amount_cents' => (int) $data['amount_cents'],
                'reason' => trim($data['reason']), 'status' => 'creating']);
            $this->event($this->order($tenant, $attempt), $actor, 'pos_simulation_refund_requested', 'Manager reserved a fictional original-payment refund.', ['refund_id' => $refund->id]);

            return $refund;
        }, 3);
        $claimed = PosRefund::query()->where('tenant_id', $tenant->id)->whereKey($refund->id)->whereNull('provider_requested_at')
            ->where('status', 'creating')->update(['provider_requested_at' => now()]);
        if ($claimed) {
            try {
                $original = $this->authorizedAttempt($tenant, $actor, $attemptId, true);
                $this->assertRefundBinding($refund, $original);
                $this->reader->createRefund($refund->snapshot, $refund->request_id);
            } catch (\Throwable $exception) {
                PosRefund::query()->where('tenant_id', $tenant->id)->whereKey($refund->id)->where('status', 'creating')
                    ->update(['status' => 'unknown', 'last_error' => 'simulator_outcome_unknown']);

                return $this->reloadRefund($tenant, $refund->id);
            }
        }

        return $this->reconcileRefund($tenant, $actor, $refund->id);
    }

    public function reconcileRefund(Tenant $tenant, User $actor, int $refundId): PosRefund
    {
        $this->access->authorize($tenant, $actor, true);
        $refund = $this->reloadRefund($tenant, $refundId);
        $original = $this->authorizedAttempt($tenant, $actor, $refund->pos_payment_attempt_id, true);
        $this->assertRefundBinding($refund, $original);
        if (in_array($refund->status, ['succeeded', 'failed', 'review'], true)) {
            return $refund;
        }
        try {
            $evidence = $refund->provider_id ? $this->reader->refund($tenant->id, $refund->provider_id)
                : $this->reader->findRefund($tenant->id, $refund->request_id);
        } catch (\Throwable $exception) {
            $evidence = null;
        }

        return DB::transaction(function () use ($tenant, $actor, $refund, $evidence): PosRefund {
            $attempt = $this->lockAttempt($tenant, $actor, $refund->pos_payment_attempt_id, true);
            $row = PosRefund::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($refund->id);
            $this->assertRefundBinding($row, $attempt);
            if (in_array($row->status, ['succeeded', 'failed', 'review'], true)) {
                return $row;
            }
            if (! $evidence) {
                $row->update(['status' => 'unknown', 'last_error' => 'simulator_outcome_unknown']);
            } elseif (! $this->matches($row->snapshot, $evidence) || ! str_starts_with((string) ($evidence['id'] ?? ''), 're_sim_')
                || ($row->provider_id && $row->provider_id !== $evidence['id'])) {
                $row->update(['status' => 'review', 'last_error' => 'canonical_refund_mismatch']);
            } else {
                $status = in_array($evidence['status'] ?? null, ['succeeded', 'failed'], true) ? $evidence['status'] : 'pending';
                $row->update(['provider_id' => $evidence['id'], 'status' => $status, 'last_error' => null]);
                if ($status === 'succeeded') {
                    $order = $this->order($tenant, $attempt);
                    $refunded = (int) PosRefund::query()->where('tenant_id', $tenant->id)->where('pos_payment_attempt_id', $attempt->id)
                        ->where('status', 'succeeded')->lockForUpdate()->get()->sum('amount_cents');
                    $order->update(['refunded_cents' => $refunded, 'payment_status' => $refunded === $order->total_cents ? 'refunded' : 'partially_refunded']);
                    $this->event($order, $actor, 'pos_simulation_refund_confirmed', 'Fictional refund confirmed. Inventory was not restocked.', ['refund_id' => $row->id]);
                }
            }

            return $this->reloadRefund($tenant, $row->id);
        });
    }

    public function simulateRefund(Tenant $tenant, User $actor, int $refundId, string $outcome): PosRefund
    {
        $this->access->authorize($tenant, $actor, true);
        $refund = $this->reloadRefund($tenant, $refundId);
        $original = $this->authorizedAttempt($tenant, $actor, $refund->pos_payment_attempt_id, true);
        $this->assertRefundBinding($refund, $original);
        if (in_array($refund->status, ['succeeded', 'failed', 'review'], true)) {
            return $refund;
        }
        abort_unless($refund->provider_id, 409, 'Reconcile the original refund request first.');
        $this->reader->simulateRefund($tenant->id, $refund->provider_id, $outcome);

        return $this->reconcileRefund($tenant, $actor, $refundId);
    }

    private function applyPayment(Tenant $tenant, User $actor, int $attemptId, array $evidence): PosPaymentAttempt
    {
        return DB::transaction(function () use ($tenant, $actor, $attemptId, $evidence): PosPaymentAttempt {
            $attempt = $this->lockAttempt($tenant, $actor, $attemptId);
            if (in_array($attempt->status, ['succeeded', 'failed', 'cancelled', 'review'], true)) {
                return $attempt;
            }
            $order = $this->order($tenant, $attempt);
            if (! $this->matches($attempt->snapshot, $evidence) || ! str_starts_with((string) ($evidence['id'] ?? ''), 'pi_sim_')
                || ($attempt->provider_id && $attempt->provider_id !== $evidence['id'])) {
                $attempt->update(['status' => 'review', 'last_error' => 'canonical_payment_mismatch']);
                $order->update(['payment_status' => 'payment_review', 'order_status' => 'payment_review']);

                return $this->reloadAttempt($tenant, $attempt->id);
            }
            $attempt->provider_id = $evidence['id'];
            $status = $evidence['status'] ?? null;
            if ($status === 'succeeded') {
                if (! $attempt->stock_committed_at && ! $this->commitInventory($tenant, $actor, $order)) {
                    $attempt->status = 'review';
                    $attempt->last_error = 'paid_inventory_conflict';
                    $order->update(['payment_status' => 'paid_review', 'order_status' => 'payment_review']);
                } else {
                    $attempt->status = 'succeeded';
                    $attempt->stock_committed_at ??= now();
                    $attempt->last_error = null;
                    $order->update(['payment_status' => 'paid', 'order_status' => 'confirmed', 'paid_at' => $order->paid_at ?: now()]);
                    $this->event($order, $actor, 'pos_simulation_paid', 'Fictional reader approval verified. No real money was collected.');
                }
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                $attempt->status = $status;
                $attempt->last_error = null;
                WebsiteInventoryReservation::query()->forTenantId($tenant->id)->where('website_order_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
                $order->update(['payment_status' => $status, 'order_status' => 'cancelled', 'fulfillment_status' => 'cancelled', 'cancelled_at' => now()]);
                $this->event($order, $actor, 'pos_simulation_unpaid', 'Fictional reader payment ended unpaid and stock holds were released.');
            } else {
                $attempt->status = $status === 'awaiting_reader' ? 'awaiting_reader' : 'unknown';
                $attempt->last_error = $attempt->status === 'unknown' ? 'simulator_outcome_unknown' : null;
            }
            $attempt->save();

            return $this->reloadAttempt($tenant, $attempt->id);
        });
    }

    private function authorizedAttempt(Tenant $tenant, User $actor, int $attemptId, bool $manager = false): PosPaymentAttempt
    {
        if (DB::transactionLevel() > 0) {
            $this->admissionLock($tenant);
        }
        $this->access->authorize($tenant, $actor, $manager);
        if (DB::transactionLevel() > 0) {
            // Discover the register through a current joined read: a retained
            // RR snapshot may predate the original same-key request entirely.
            PosRegister::query()->join('pos_payment_attempts as pos_attempt', 'pos_attempt.pos_register_id', '=', 'pos_registers.id')
                ->where('pos_registers.tenant_id', $tenant->id)->where('pos_attempt.tenant_id', $tenant->id)
                ->where('pos_attempt.id', $attemptId)->select('pos_registers.*')->lockForUpdate()->firstOrFail();
        }
        $attempt = $this->reloadAttempt($tenant, $attemptId);
        $register = $this->access->register($tenant, $actor, $attempt->pos_register_id, $manager);
        $snapshot = $attempt->snapshot;
        $this->assertProvider($snapshot);
        abort_unless(($snapshot['environment'] ?? null) === 'simulation' && ($snapshot['tenant_id'] ?? null) === $tenant->id
            && ($snapshot['register_id'] ?? null) === $register->id && ($snapshot['site_id'] ?? null) === $register->tenant_site_id && ($snapshot['merchant_id'] ?? null) === $register->merchant_id
            && ($snapshot['reader_id'] ?? null) === $register->reader_id && ($snapshot['location_id'] ?? null) === $register->website_fulfillment_location_id
            && ($snapshot['currency'] ?? null) === $register->currency, 409, 'The original register binding changed. Restore it for reconciliation.');
        $this->order($tenant, $attempt);

        return $attempt;
    }

    private function lockAttempt(Tenant $tenant, User $actor, int $attemptId, bool $manager = false): PosPaymentAttempt
    {
        return $this->authorizedAttempt($tenant, $actor, $attemptId, $manager);
    }

    private function order(Tenant $tenant, PosPaymentAttempt $attempt): WebsiteOrder
    {
        $order = WebsiteOrder::query()->forTenantId($tenant->id)->where('tenant_site_id', $attempt->snapshot['site_id'])
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->where('source', 'pos_simulation')->findOrFail($attempt->website_order_id);
        abort_unless($order->id === ($attempt->snapshot['order_id'] ?? null) && $order->total_cents === ($attempt->snapshot['amount_cents'] ?? null)
            && $order->currency === ($attempt->snapshot['currency'] ?? null)
            && $order->subtotal_cents === $attempt->snapshot['subtotal_cents'] && $order->tax_cents === $attempt->snapshot['tax_cents'],
            409, 'The frozen simulated sale no longer matches.');
        $lines = $order->lines()->orderBy('website_product_variant_id')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->get()->map(fn ($line) => [
                'variant_id' => $line->website_product_variant_id, 'title' => $line->title, 'variant_title' => data_get($line->snapshot, 'variant_title'),
                'sku' => data_get($line->snapshot, 'sku'), 'quantity' => $line->quantity, 'unit_price_cents' => $line->unit_price_cents,
                'line_total_cents' => $line->line_total_cents, 'track_inventory' => data_get($line->snapshot, 'track_inventory'),
            ])->all();
        abort_unless($lines === $attempt->snapshot['lines'], 409, 'The frozen simulated sale lines no longer match.');

        return $order;
    }

    private function commitInventory(Tenant $tenant, User $actor, WebsiteOrder $order): bool
    {
        $locked = [];
        foreach ($order->lines()->orderBy('website_product_variant_id')->lockForUpdate()->get()->filter(fn ($line) => data_get($line->snapshot, 'track_inventory')) as $line) {
            $variant = WebsiteProductVariant::query()->forTenantId($tenant->id)->lockForUpdate()->find($line->website_product_variant_id);
            $hold = WebsiteInventoryReservation::query()->forTenantId($tenant->id)->where('website_order_id', $order->id)->where('website_product_variant_id', $line->website_product_variant_id)->lockForUpdate()->first();
            $others = (int) WebsiteInventoryReservation::query()->forTenantId($tenant->id)->where('website_product_variant_id', $line->website_product_variant_id)
                ->where('website_order_id', '!=', $order->id)->where('status', 'active')->lockForUpdate()->get()->sum('quantity');
            if (! $variant || $variant->inventory_quantity === null || ! $hold || $hold->status !== 'active'
                || $hold->quantity !== $line->quantity || $variant->inventory_quantity - $others < $line->quantity) {
                return false;
            }
            $locked[] = [$variant, $hold, $line];
        }
        foreach ($locked as [$variant, $hold, $line]) {
            $variant->decrement('inventory_quantity', $line->quantity);
            $hold->update(['status' => 'confirmed']);
            WebsiteInventoryMovement::query()->create(['tenant_id' => $tenant->id, 'website_product_variant_id' => $variant->id,
                'quantity_delta' => -$line->quantity, 'reason' => 'pos_simulation_sale', 'reference_type' => 'website_order', 'reference_id' => $order->id, 'actor_user_id' => $actor->id]);
        }

        return true;
    }

    private function unknownPayment(Tenant $tenant, int $id): void
    {
        PosPaymentAttempt::query()->where('tenant_id', $tenant->id)->whereKey($id)->whereIn('status', ['creating', 'awaiting_reader', 'processing', 'unknown'])
            ->update(['status' => 'unknown', 'last_error' => 'simulator_outcome_unknown']);
    }

    private function reloadAttempt(Tenant $tenant, int $id): PosPaymentAttempt
    {
        return PosPaymentAttempt::query()->where('tenant_id', $tenant->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    private function reloadRefund(Tenant $tenant, int $id): PosRefund
    {
        if (DB::transactionLevel() > 0) {
            $this->admissionLock($tenant);
            PosRegister::query()->join('pos_payment_attempts as pos_attempt', 'pos_attempt.pos_register_id', '=', 'pos_registers.id')
                ->join('pos_refunds as pos_refund', 'pos_refund.pos_payment_attempt_id', '=', 'pos_attempt.id')
                ->where('pos_registers.tenant_id', $tenant->id)->where('pos_attempt.tenant_id', $tenant->id)
                ->where('pos_refund.tenant_id', $tenant->id)->where('pos_refund.id', $id)
                ->select('pos_registers.*')->lockForUpdate()->firstOrFail();
        }

        return PosRefund::query()->where('tenant_id', $tenant->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    private function assertProvider(array $snapshot): void
    {
        $key = $this->reader->key();
        abort_unless(is_string($snapshot['provider_key'] ?? null) && $snapshot['provider_key'] !== ''
            && hash_equals($snapshot['provider_key'], $key), 409, 'The original payment provider is unavailable. No replacement provider will be used.');
        $capabilities = $this->reader->capabilities();
        abort_unless(($capabilities['environment'] ?? null) === 'simulation'
            && ($capabilities['physical_readers'] ?? null) === false && ($capabilities['offline'] ?? null) === false,
            409, 'This register only supports the declared local simulated provider.');
    }

    private function assertRefundBinding(PosRefund $refund, PosPaymentAttempt $attempt): void
    {
        $snapshot = $refund->snapshot;
        $this->assertProvider($snapshot);
        abort_unless(($snapshot['provider_key'] ?? null) === $attempt->snapshot['provider_key']
            && ($snapshot['merchant_id'] ?? null) === $attempt->snapshot['merchant_id']
            && ($snapshot['payment_id'] ?? null) === $attempt->provider_id
            && ($snapshot['tenant_id'] ?? null) === $attempt->tenant_id
            && ($snapshot['register_id'] ?? null) === $attempt->pos_register_id
            && ($snapshot['order_id'] ?? null) === $attempt->website_order_id
            && ($snapshot['currency'] ?? null) === $attempt->snapshot['currency']
            && ($snapshot['amount_cents'] ?? null) === $refund->amount_cents
            && ($snapshot['environment'] ?? null) === 'simulation',
            409, 'This refund no longer matches its original provider, merchant and payment.');
    }

    private function admissionLock(Tenant $tenant): void
    {
        // The first existing register is a stable per-tenant admission mutex.
        // Do not lock Tenant: native checkout locks variants before inserting
        // orders whose Tenant foreign key needs a compatible parent-row lock.
        // No register deletion endpoint exists; include inactive rows so pausing
        // a register cannot change the mutex while another request is waiting.
        PosRegister::query()->where('tenant_id', $tenant->id)->orderBy('id')->lockForUpdate()->firstOrFail();
    }

    private function matches(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function event(WebsiteOrder $order, User $actor, string $kind, string $message, array $data = []): void
    {
        WebsiteOrderEvent::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id,
            'user_id' => $actor->id, 'event_type' => $kind, 'visibility' => 'staff', 'message' => $message, 'data' => ['simulation' => true, ...$data]]);
    }
}
