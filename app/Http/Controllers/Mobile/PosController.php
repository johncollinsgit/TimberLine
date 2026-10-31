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
            'request_id' => ['required', 'uuid'], 'source_order_id' => ['sometimes', 'integer', 'min:1', 'prohibits:items'],
            'items' => ['required_without:source_order_id', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:item_id,quantity,revision'], 'items.*.item_id' => ['required', 'string', 'max:100', 'distinct'],
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
            }
            $body = ['contract_version' => 1, ...$action($input)];
            $status = 200;
        } catch (PosException $error) {
            $body = ['contract_version' => 1, 'error' => ['code' => $error->blocker]];
            $status = $error->status;
        } catch (\DomainException) {
            $body = ['contract_version' => 1, 'error' => ['code' => 'operation_requires_review']];
            $status = 409;
        }

        return response()->json($body, $status, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
