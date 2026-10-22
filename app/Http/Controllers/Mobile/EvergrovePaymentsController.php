<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\PaymentsCore\Mobile\MobilePaymentException;
use App\Services\PaymentsCore\Mobile\MobilePaymentService;
use App\Services\PaymentsCore\Quotes\QuoteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class EvergrovePaymentsController extends Controller
{
    public function __construct(private MobilePaymentService $payments) {}

    public function registers(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->payments->registers(), $request, []);
    }

    public function catalog(Request $request, string $tenant, string $register): JsonResponse
    {
        return $this->respond(fn ($input) => $this->payments->catalog($register, $input), $request,
            ['cursor' => ['sometimes', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:50'], 'sku' => ['sometimes', 'string', 'min:1', 'max:100']]);
    }

    public function quote(Request $request, string $tenant, string $register): JsonResponse
    {
        return $this->respond(fn ($input) => $this->payments->quote($register, $input), $request,
            ['request_id' => ['required', 'string', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:50'],
                'items.*' => ['required', 'array:variant_id,quantity,expected_revision'],
                'items.*.variant_id' => ['required', 'integer', 'min:1'],
                'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
                'items.*.expected_revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
    }

    public function start(Request $request, string $tenant, string $register): JsonResponse
    {
        return $this->respond(fn ($input) => $this->payments->start($register, $input), $request,
            ['request_id' => ['required', 'string', 'uuid'], 'quote_id' => ['required', 'string', 'uuid'],
                'quote_version' => ['required', 'integer', 'min:1', 'max:2147483647']]);
    }

    public function inspect(Request $request, string $tenant, string $register, string $attempt): JsonResponse
    {
        return $this->respond(fn () => $this->payments->inspect($register, $attempt), $request, []);
    }

    public function reconcile(Request $request, string $tenant, string $register, string $attempt): JsonResponse
    {
        return $this->respond(fn () => $this->payments->reconcile($register, $attempt), $request, []);
    }

    public function refund(Request $request, string $tenant, string $register, string $attempt): JsonResponse
    {
        return $this->respond(fn ($input) => $this->payments->refund($register, $attempt, $input), $request,
            ['request_id' => ['required', 'string', 'uuid'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
                'reason' => ['required', 'string', 'in:requested_by_customer,duplicate,other']]);
    }

    private function respond(callable $action, Request $request, array $rules): JsonResponse
    {
        try {
            $input = $request->all();
            $keys = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
            if (array_diff(array_keys($input), $keys) || Validator::make($input, $rules)->fails()) {
                throw new MobilePaymentException('invalid_request', 422);
            }
            if ($request->isMethod('POST')) {
                foreach (['amount_minor', 'quote_version'] as $key) {
                    if (isset($input[$key]) && ! is_int($input[$key])) {
                        throw new MobilePaymentException('invalid_request', 422);
                    }
                }
                foreach ($input['items'] ?? [] as $item) {
                    if (! is_int($item['variant_id'] ?? null) || ! is_int($item['quantity'] ?? null)) {
                        throw new MobilePaymentException('invalid_request', 422);
                    }
                }
            }
            foreach (['request_id', 'quote_id'] as $key) {
                if (isset($input[$key])) {
                    $input[$key] = strtolower($input[$key]);
                }
            }
            $result = $action($input);
            $status = 200;
        } catch (MobilePaymentException|QuoteException $error) {
            $result = $this->payments->envelope(['error' => ['code' => $error->blockerCode]]);
            $status = $error->httpStatus;
        } catch (\DomainException) {
            $result = $this->payments->envelope(['error' => ['code' => 'invalid_request']]);
            $status = 422;
        }

        return response()->json($result, $status, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
