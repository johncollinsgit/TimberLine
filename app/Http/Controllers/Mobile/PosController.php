<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Pos\PosException;
use App\Services\Pos\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PosController extends Controller
{
    public function __construct(private PosService $pos) {}

    public function registers(Request $r): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->registers());
    }

    public function catalog(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->catalog($register));
    }

    public function countStock(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'], 'item_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
            'quantity' => ['required', 'integer', 'min:0', 'max:10000000'], 'expected_version' => ['required', 'integer', 'min:0'],
            'physically_confirmed' => ['required', 'boolean']], function ($input) use ($register): array {
                $scope = app(\App\Services\Pos\PosAccess::class)->register($register, 'quote');
                if (! in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true)) {
                    throw new PosException('inventory_owner_required', 403);
                }
                if (! is_int($input['quantity']) || ! is_int($input['expected_version']) || $input['physically_confirmed'] !== true) {
                    throw new PosException('physical_count_confirmation_required', 422);
                }
                $settings = app(\App\Services\Pos\StockPosCommerce::class)->settings($scope);
                app(\App\Services\Inventory\StockLedger::class)->count($scope['tenant_id'], 'mobile:'.$input['request_id'], $scope['actor_id'],
                    $settings['inventory_pool_id'], [$input['item_id'] => $input['quantity']], 'physical_count', [$input['item_id'] => $input['expected_version']]);
                app(\App\Services\Pos\StockPosCommerce::class)->queuePublications($scope['tenant_id']);

                return $this->pos->catalog($register);
            });
    }

    public function customers(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['query' => ['required', 'string', 'min:2', 'max:120', 'regex:/\A[^\p{C}]+\z/u']],
            fn ($input) => app(\App\Services\Pos\PosCustomerDirectory::class)->search($register, $input['query']));
    }

    public function createCustomer(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'],
            'first_name' => ['required', 'string', 'max:120', 'regex:/\A[^\p{C}]+\z/u'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/\A[^\p{C}]*\z/u'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/\A[^\p{C}]*\z/u']],
            fn ($input) => app(\App\Services\Pos\PosCustomerDirectory::class)->create($register, $input));
    }

    public function customerRequest(Request $r, string $tenant, string $register, string $requestId): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosCustomerDirectory::class)->request($register, $requestId));
    }

    public function approvedQuotes(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->approvedQuotes($register));
    }

    public function authorizeDevice(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->authorizeDevice($register));
    }

    public function requestStatus(Request $r, string $tenant, string $register, string $requestId): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->requestStatus($register, $requestId));
    }

    public function quote(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [
            'request_id' => ['required', 'uuid'], 'source_order_id' => ['sometimes', 'integer', 'min:1', 'prohibits:items,custom_charge'],
            'items' => ['required_without_all:source_order_id,custom_charge', 'array', 'min:1', 'max:50'],
            'custom_charge' => ['sometimes', 'array:description,amount_minor'],
            'custom_charge.description' => ['required_with:custom_charge', 'string', 'min:1', 'max:150', 'regex:/\A[^\p{C}]+\z/u'],
            'custom_charge.amount_minor' => ['required_with:custom_charge', 'integer', 'min:1', 'max:100000000'],
            'items.*' => ['array:item_id,quantity,revision,components'], 'items.*.item_id' => ['required', 'string', 'max:100', 'distinct'],
            'items.*.components' => ['sometimes', 'array', 'min:1', 'max:100'], 'items.*.components.*' => ['array:item_id,quantity,revision'],
            'items.*.components.*.item_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
            'items.*.components.*.quantity' => ['required', 'integer', 'min:1', 'max:9900'], 'items.*.components.*.revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'], 'items.*.revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'customer_profile_id' => ['sometimes', 'integer', 'min:1'],
            'customer' => ['sometimes', 'nullable', 'array:name,email'], 'customer.name' => ['sometimes', 'string', 'max:150'],
            'customer.email' => ['sometimes', 'email:rfc', 'max:254'],
        ], fn ($input) => $this->pos->quote($register, $input));
    }

    public function discard(Request $r, string $tenant, string $register, string $requestId): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->discard($register, $requestId));
    }

    public function attachCustomer(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'], 'customer_profile_id' => ['sometimes', 'integer', 'min:1'],
            'name' => ['sometimes', 'nullable', 'string', 'max:150', 'regex:/\A[^\p{C}]*\z/u'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/\A[^\p{C}]*\z/u'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'reward_evidence' => ['sometimes', 'array:member_at_purchase,prior_confirmed_orders,reason'],
            'reward_evidence.member_at_purchase' => ['required_with:reward_evidence', 'boolean'],
            'reward_evidence.prior_confirmed_orders' => ['required_with:reward_evidence', 'integer', 'min:0', 'max:1000000'],
            'reward_evidence.reason' => ['required_with:reward_evidence', 'string', 'min:5', 'max:500']], function ($input) use ($register, $sale): array {
                $scope = app(\App\Services\Pos\PosAccess::class)->register($register, 'quote');
                $row = $this->pos->sale($scope, $sale);
                $result = app(\App\Services\Pos\PosReceiptCustomers::class)->attach($scope, $row, $this->pos->snapshot($row), $input);

                return [...$result, 'receipt' => $this->pos->receipt($register, $sale)];
            });
    }

    public function start(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->start($register, $sale));
    }

    public function show(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->inspect($register, $sale));
    }

    public function receipt(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->receipt($register, $sale));
    }

    public function reconcile(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, ['payment_id' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{1,100}$/D']],
            fn ($input) => $this->pos->reconcile($register, $sale, $input['payment_id'] ?? null));
    }

    public function refund(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'in:requested_by_customer,duplicate,other']], fn ($input) => $this->pos->refund($register, $sale, $input));
    }

    private function respond(Request $request, array $rules, callable $action): JsonResponse
    {
        try {
            $input = $request->all();
            $top = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
            if (array_diff(array_keys($input), $top) || Validator::make($input, $rules)->fails()) {
                throw new PosException('invalid_request', 422);
            }
            foreach (['amount_minor', 'source_order_id', 'customer_profile_id'] as $key) {
                if (isset($input[$key]) && ! is_int($input[$key])) {
                    throw new PosException('invalid_request', 422);
                }
            }
            foreach ($input['items'] ?? [] as $item) {
                if (! is_int($item['quantity'])) {
                    throw new PosException('invalid_request', 422);
                }
                foreach ($item['components'] ?? [] as $part) {
                    if (! is_int($part['quantity'])) {
                        throw new PosException('invalid_request', 422);
                    }
                }
            }
            if (isset($input['custom_charge']) && ! is_int($input['custom_charge']['amount_minor'])) {
                throw new PosException('invalid_request', 422);
            }
            if (isset($input['reward_evidence']) && (! is_bool($input['reward_evidence']['member_at_purchase']) || ! is_int($input['reward_evidence']['prior_confirmed_orders']))) {
                throw new PosException('invalid_request', 422);
            }
            $body = ['contract_version' => 1, ...$action($input)];
            $status = 200;
        } catch (PosException $error) {
            $body = ['contract_version' => 1, 'error' => ['code' => $error->blocker]];
            $status = $error->status;
        } catch (\App\Services\Inventory\StockException $error) {
            $body = ['contract_version' => 1, 'error' => ['code' => $error->reason]];
            $status = $error->status;
        } catch (\DomainException) {
            $body = ['contract_version' => 1, 'error' => ['code' => 'operation_requires_review']];
            $status = 409;
        }

        return response()->json($body, $status, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
