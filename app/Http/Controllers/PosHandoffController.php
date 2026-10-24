<?php

namespace App\Http\Controllers;

use App\Services\Pos\PosAccess;
use App\Services\Pos\PosException;
use App\Services\Pos\PosHandoffs;
use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PosHandoffController extends Controller
{
    public function __construct(private PosHandoffs $handoffs, private PosService $pos, private PosAccess $access) {}

    public function index(Request $r, string $tenant)
    {
        $actor = $this->access->actor();

        return response()->view('pos.staff', ['bootstrap' => ['registers' => $this->pos->registers()['registers'],
            'device' => $actor['device_id'], 'base' => $r->url(), 'tenant' => $tenant]]);
    }

    public function catalog(Request $r, string $tenant, string $register)
    {
        return $this->json([...$this->pos->catalog($register), ...$this->pos->approvedQuotes($register)]);
    }

    public function script()
    {
        $this->access->actor();

        return response()->file(resource_path('js/pos-staff-register.js'), [
            'Content-Type' => 'application/javascript', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function prepare(Request $r, string $tenant, string $register)
    {
        $input = $this->input($r, [
            'request_id' => ['required', 'uuid'], 'source_order_id' => ['sometimes', 'integer', 'min:1', 'prohibits:items'],
            'items' => ['required_without:source_order_id', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:item_id,quantity,revision'], 'items.*.item_id' => ['required', 'string', 'max:100', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'], 'items.*.revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'customer' => ['sometimes', 'array:name,email'], 'customer.name' => ['sometimes', 'string', 'max:150'],
            'customer.email' => ['sometimes', 'email:rfc', 'max:254'],
        ]);
        if ((isset($input['source_order_id']) && ! is_int($input['source_order_id']))
            || collect($input['items'] ?? [])->contains(fn ($item) => ! is_int($item['quantity']))) {
            throw new PosException('invalid_request', 422);
        }

        return $this->json($this->handoffs->prepare($register, $input));
    }

    public function byRequest(Request $r, string $tenant, string $register, string $requestId)
    {
        $scope = $this->access->register($register);
        $row = app(PosStore::class)->rows('handoffs', $scope['tenant_id'])->where('register_id', $register)->where('request_id', $requestId)->first();
        if (! $row) {
            throw new PosException('handoff_unavailable', 404);
        }

        return $this->json($this->handoffs->status($register, $row->id));
    }

    public function show(Request $r, string $tenant, string $register, string $handoff)
    {
        return $this->json($this->handoffs->status($register, $handoff));
    }

    public function cancelRequest(Request $r, string $tenant, string $register, string $requestId)
    {
        $this->input($r, []);

        return $this->json($this->handoffs->cancelRequest($register, $requestId));
    }

    public function cancel(Request $r, string $tenant, string $register, string $handoff)
    {
        $this->input($r, []);

        return $this->json($this->handoffs->cancel($register, $handoff));
    }

    public function receipt(Request $r, string $tenant, string $register, string $handoff)
    {
        return $this->json($this->handoffs->receipt($register, $handoff));
    }

    public function inbox(Request $r, string $tenant, string $register)
    {
        return $this->json($this->handoffs->inbox($register));
    }

    public function claim(Request $r, string $tenant, string $register, string $handoff)
    {
        $this->input($r, []);

        return $this->json($this->handoffs->claim($register, $handoff));
    }

    private function input(Request $r, array $rules): array
    {
        $input = $r->all();
        $top = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($input), $top) || Validator::make($input, $rules)->fails()) {
            throw new PosException('invalid_request', 422);
        }

        return $input;
    }

    private function json(array $body)
    {
        return response()->json(['contract_version' => 1, ...$body], 200, ['Cache-Control' => 'private, no-store']);
    }
}
