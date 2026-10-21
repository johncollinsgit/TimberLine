<?php

namespace App\Services\PointOfSale;

use App\Models\PosPaymentAttempt;
use App\Models\PosRefund;
use App\Models\PosRegister;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Native customers and shown-register practice history; never marketing enrollment. */
class PosCustomerDirectory
{
    public function __construct(private PosAccess $access, private PosService $sales) {}

    public function search(Tenant $tenant, User $actor, int $registerId, mixed $query): array
    {
        $this->access->register($tenant, $actor, $registerId);
        $input = Validator::make(['query' => $query], ['query' => ['required', 'string', 'min:2', 'max:120', 'regex:/\A[^\p{C}]+\z/u']])->validate();
        $query = trim($input['query']);
        abort_if(mb_strlen($query) < 2, 422, 'Enter at least two characters to find a native customer.');
        $customers = WebsiteCustomer::query()->forTenantId($tenant->id)->where('status', 'active');
        foreach (preg_split('/\s+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            // Explicit escaping treats barcode-like punctuation and SQL wildcard
            // characters literally on both SQLite and MySQL.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $part).'%';
            $customers->where(function ($fields) use ($pattern): void {
                foreach (['first_name', 'last_name', 'email', 'phone'] as $column) {
                    $fields->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        $matches = $customers->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(21)->get();
        $this->access->register($tenant, $actor, $registerId);

        return ['simulation' => true, 'customers' => $matches->take(20)->map(fn ($customer) => $this->summary($customer))->values()->all(),
            'has_more' => $matches->count() > 20];
    }

    public function get(Tenant $tenant, User $actor, int $registerId, int $customerId): array
    {
        $this->access->register($tenant, $actor, $registerId);
        $customer = WebsiteCustomer::query()->forTenantId($tenant->id)->where('status', 'active')->findOrFail($customerId);

        return $this->summary($customer);
    }

    /** Recover a lost create response by its opaque request, without saving contacts in the browser. */
    public function findCreation(Tenant $tenant, User $actor, int $registerId, mixed $requestId): ?array
    {
        $this->access->register($tenant, $actor, $registerId);
        $data = Validator::make(['request_id' => $requestId], ['request_id' => ['required', 'uuid']])->validate();
        $data['request_id'] = strtolower($data['request_id']);
        $customer = WebsiteCustomer::query()->forTenantId($tenant->id)->where('status', 'active')
            ->where('notes->pos_simulation_creation->request_id', $data['request_id'])
            ->where('notes->pos_simulation_creation->register_id', $registerId)->first();

        return $customer ? $this->summary($customer) : null;
    }

    /** Explicit contact creation only. Matching email instructs selection, never a merge. */
    public function create(Tenant $tenant, User $actor, int $registerId, array $input): array
    {
        if (DB::transactionLevel() > 0) {
            $this->admissionLock($tenant);
        }
        $this->access->register($tenant, $actor, $registerId);
        foreach (['first_name', 'last_name', 'email', 'phone'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, [
            'request_id' => ['required', 'uuid'],
            'first_name' => ['required', 'string', 'max:120', 'regex:/\A[^\p{C}]+\z/u'],
            'last_name' => ['nullable', 'string', 'max:120', 'regex:/\A[^\p{C}]*\z/u'],
            'email' => ['nullable', 'string', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:80', 'regex:/\A[^\p{C}]*\z/u'],
        ])->validate();
        $data['request_id'] = strtolower($data['request_id']);
        $terms = ['register_id' => $registerId, 'first_name' => $data['first_name'],
            'last_name' => ($data['last_name'] ?? '') !== '' ? $data['last_name'] : null,
            'email' => ($data['email'] ?? '') !== '' ? mb_strtolower($data['email']) : null,
            'phone' => ($data['phone'] ?? '') !== '' ? $data['phone'] : null];
        $hash = hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
        try {
            $customer = DB::transaction(function () use ($tenant, $actor, $registerId, $data, $terms, $hash): WebsiteCustomer {
                // Share POS admission ordering. This makes no-contact creations
                // duplicate-safe even though native customer email is nullable.
                $this->admissionLock($tenant);
                $this->access->register($tenant, $actor, $registerId);
                $existing = WebsiteCustomer::query()->forTenantId($tenant->id)
                    ->where('notes->pos_simulation_creation->request_id', $data['request_id'])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless($existing->status === 'active'
                        && hash_equals((string) data_get($existing->notes, 'pos_simulation_creation.request_hash'), $hash),
                        409, 'This native customer request already has different terms or needs review.');

                    return $existing;
                }
                if ($terms['email'] !== null && $this->hasEmail($tenant, $terms['email'])) {
                    throw ValidationException::withMessages(['email' => 'Select the existing native customer with this email instead of creating another record.']);
                }

                return WebsiteCustomer::query()->create(['tenant_id' => $tenant->id, 'status' => 'active',
                    'first_name' => $terms['first_name'], 'last_name' => $terms['last_name'],
                    'email' => $terms['email'], 'phone' => $terms['phone'],
                    'notes' => ['pos_simulation_creation' => ['request_id' => $data['request_id'], 'request_hash' => $hash,
                        'register_id' => $registerId, 'simulation' => true]]]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($terms['email'] !== null && $this->hasEmail($tenant, $terms['email'])) {
                throw ValidationException::withMessages(['email' => 'Select the existing native customer with this email instead of creating another record.']);
            }
            throw $exception;
        }

        return ['simulation' => true, 'customer' => $this->summary($customer)];
    }

    /** Totals cover only the latest 100 confirmed practice sales at this register. */
    public function history(Tenant $tenant, User $actor, int $registerId, int $customerId): array
    {
        $register = $this->access->register($tenant, $actor, $registerId);
        // Archived profiles retain their issued history. Only new selection and
        // search require active status; no historical contact gets rewritten.
        $customer = WebsiteCustomer::query()->forTenantId($tenant->id)->findOrFail($customerId);
        $attempts = PosPaymentAttempt::query()->forTenantId($tenant->id)->where('pos_register_id', $registerId)
            ->where('status', 'succeeded')->whereNotNull('stock_committed_at')
            ->whereHas('order', fn ($orders) => $orders->where('tenant_id', $tenant->id)->where('tenant_site_id', $register->tenant_site_id)
                ->where('source', 'pos_simulation')->where('website_customer_id', $customerId))
            ->orderByDesc('stock_committed_at')->orderByDesc('id')->limit(101)->get();
        $sales = [];
        foreach ($attempts->take(100) as $candidate) {
            $attempt = $this->sales->inspectAttempt($tenant, $actor, $candidate->id);
            $snapshot = $attempt->snapshot;
            $order = WebsiteOrder::query()->forTenantId($tenant->id)->where('tenant_site_id', $register->tenant_site_id)
                ->where('source', 'pos_simulation')->findOrFail($attempt->website_order_id);
            abort_unless($attempt->status === 'succeeded' && $attempt->stock_committed_at && $order->paid_at
                && in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)
                && $attempt->provider_requested_at && str_starts_with((string) $attempt->provider_id, 'pi_sim_')
                && ($snapshot['website_customer_id'] ?? null) === $customerId && $order->website_customer_id === $customerId
                && ($snapshot['customer_snapshot'] ?? null) === $order->customer_snapshot
                && is_int($snapshot['amount_cents'] ?? null) && $snapshot['amount_cents'] > 0,
                409, 'A frozen customer practice sale needs review before displaying history.');
            $refunds = PosRefund::query()->forTenantId($tenant->id)->where('pos_payment_attempt_id', $attempt->id)->orderBy('id')->get();
            $confirmed = $pending = 0;
            $refundItems = [];
            foreach ($refunds as $refund) {
                $this->assertRefundBinding($refund, $attempt);
                abort_unless(in_array($refund->status, ['creating', 'unknown', 'pending', 'review', 'succeeded', 'failed'], true),
                    409, 'A practice refund status needs review.');
                if ($refund->status === 'succeeded') {
                    abort_unless($refund->provider_requested_at && str_starts_with((string) $refund->provider_id, 're_sim_'),
                        409, 'A confirmed practice refund needs original provider evidence.');
                    $confirmed += $refund->amount_cents;
                } elseif (in_array($refund->status, ['creating', 'unknown', 'pending', 'review'], true)) {
                    $pending += $refund->amount_cents;
                }
                $refundItems[] = ['id' => $refund->id, 'status' => $refund->status, 'amount_cents' => $refund->amount_cents];
            }
            abort_if($confirmed + $pending > $snapshot['amount_cents'], 409, 'The practice refund totals need review.');
            $sales[] = ['attempt_id' => $attempt->id, 'order_id' => $order->id, 'number' => $order->number,
                'paid_at' => $order->paid_at?->toIso8601String(), 'currency' => $snapshot['currency'],
                'tender' => $snapshot['tender'] ?? 'card', 'subtotal_cents' => $snapshot['subtotal_cents'], 'tax_cents' => $snapshot['tax_cents'],
                'lines' => array_map(fn ($line) => array_intersect_key($line, array_flip([
                    'variant_id', 'title', 'variant_title', 'sku', 'quantity', 'unit_price_cents', 'line_total_cents',
                ])), $snapshot['lines']),
                'customer_snapshot' => $snapshot['customer_snapshot'], 'gross_cents' => $snapshot['amount_cents'],
                'confirmed_refunds_cents' => $confirmed, 'net_cents' => $snapshot['amount_cents'] - $confirmed,
                'pending_refunds_cents' => $pending, 'refunds' => $refundItems];
        }
        $this->access->register($tenant, $actor, $registerId);

        return ['simulation' => true, 'scope' => 'register', 'coverage' => 'latest_100_confirmed_practice_sales',
            'register_id' => $registerId, 'customer' => $this->summary($customer), 'sales' => $sales,
            'paid_sale_count' => count($sales), 'gross_cents' => array_sum(array_column($sales, 'gross_cents')),
            'confirmed_refunds_cents' => array_sum(array_column($sales, 'confirmed_refunds_cents')),
            'net_cents' => array_sum(array_column($sales, 'net_cents')),
            'pending_refunds_cents' => array_sum(array_column($sales, 'pending_refunds_cents')),
            'has_more' => $attempts->count() > 100, 'history_complete' => $attempts->count() <= 100];
    }

    private function summary(WebsiteCustomer $customer): array
    {
        return ['id' => $customer->id, 'name' => trim((string) $customer->first_name.' '.(string) $customer->last_name),
            'email' => $customer->email, 'phone' => $customer->phone];
    }

    private function admissionLock(Tenant $tenant): void
    {
        PosRegister::query()->where('tenant_id', $tenant->id)->orderBy('id')->lockForUpdate()->firstOrFail();
    }

    private function hasEmail(Tenant $tenant, string $email): bool
    {
        // A retained MySQL repeatable-read snapshot may predate a concurrent
        // native customer create. Current reads preserve the select-existing
        // response even after a unique-key collision in an outer transaction.
        return WebsiteCustomer::query()->forTenantId($tenant->id)->whereRaw('LOWER(email) = ?', [$email])
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first(['id']) !== null;
    }

    private function assertRefundBinding(PosRefund $refund, PosPaymentAttempt $attempt): void
    {
        $snapshot = $refund->snapshot;
        $payment = $attempt->snapshot;
        abort_unless($refund->tenant_id === $attempt->tenant_id && $refund->pos_payment_attempt_id === $attempt->id
            && ($snapshot['provider_key'] ?? null) === ($payment['provider_key'] ?? null)
            && ($snapshot['environment'] ?? null) === 'simulation'
            && ($snapshot['tenant_id'] ?? null) === $attempt->tenant_id
            && ($snapshot['register_id'] ?? null) === $attempt->pos_register_id
            && ($snapshot['order_id'] ?? null) === $attempt->website_order_id
            && ($snapshot['merchant_id'] ?? null) === ($payment['merchant_id'] ?? null)
            && ($snapshot['payment_id'] ?? null) === $attempt->provider_id
            && ($snapshot['tender'] ?? 'card') === ($payment['tender'] ?? 'card')
            && ($snapshot['currency'] ?? null) === ($payment['currency'] ?? null)
            && ($snapshot['amount_cents'] ?? null) === $refund->amount_cents && $refund->amount_cents > 0,
            409, 'A customer practice refund no longer matches its original sale.');
    }
}
