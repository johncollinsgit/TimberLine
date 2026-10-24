<?php

namespace App\Http\Controllers;

use App\Services\Pos\PosException;
use App\Services\Pos\PosPilotAccess;
use App\Services\Pos\PosPilotOperations;
use App\Services\Pos\PosPilotReports;
use App\Services\Pos\PosReadiness;
use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use App\Services\Pos\PosStripeSetup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PosPilotController extends Controller
{
    public function __construct(private PosStore $store, private PosPilotAccess $access, private PosPilotOperations $ops, private PosService $pos) {}

    public function callAction($method, $parameters)
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (PosException $e) {
            return $e->render();
        } catch (\DomainException) {
            return response()->json(['error' => ['code' => 'operation_requires_review']], 409);
        }
    }

    public function index(Request $r, string $tenant)
    {
        $this->access->tenant();

        return response()->view('pos.pilot', ['base' => $r->url(), 'tenantName' => $r->attributes->get('current_tenant')->name]);
    }

    public function asset(Request $r, string $tenant, string $asset)
    {
        $this->access->tenant();
        $files = ['register.js' => ['js/pos-pilot-register.js', 'application/javascript'], 'register.css' => ['css/pos-pilot.css', 'text/css'], 'trees.png' => ['images/pos-trees.png', 'image/png']];
        abort_unless(isset($files[$asset]), 404);

        return response()->file(resource_path($files[$asset][0]), ['Content-Type' => $files[$asset][1], 'X-Content-Type-Options' => 'nosniff']);
    }

    public function state()
    {
        $tenant = $this->access->tenant();
        $owner = null;
        try {
            $owner = $this->access->owner();
        } catch (PosException) {
        }
        try {
            $ctx = $this->access->context(true);
        } catch (PosException $e) {
            if (! $owner) {
                throw $e;
            } $ctx = null;
        }
        $operator = $ctx['operator'] ?? null;
        $events = $this->store->rows('trade_events', $tenant);
        if (! $owner) {
            $events->whereIn('id', $this->store->rows('event_registers', $tenant)->where('register_id', $ctx['device']->register_id)->where('active', true)->select('event_id'));
            if ($operator) {
                $events->whereIn('id', $this->store->rows('event_staff', $tenant)->where('operator_id', $operator->id)->where('active', true)->select('event_id'));
            }
        }
        $staffIds = $this->store->rows('event_staff', $tenant)->whereIn('event_id', (clone $events)->select('id'))->where('active', true)->pluck('operator_id');
        $staff = $this->store->rows('operators', $tenant)->when(! $owner, fn ($q) => $q->whereIn('id', $staffIds)->where('active', true))->get(['id', 'name', 'role', 'user_id', 'active']);
        $registers = $this->store->rows('registers', $tenant)->when(! $owner, fn ($q) => $q->where('id', $ctx['device']->register_id))->get();
        $safeRegisters = [];
        foreach ($registers as $register) {
            $connection = $this->store->rows('connections', $tenant)->where('id', $register->connection_id)->first();
            $safeRegisters[] = ['id' => $register->id, 'name' => $register->name, 'location' => $register->location_name, 'provider' => $register->provider,
                'blockers' => $connection ? app(PosReadiness::class)->blockers($register, $connection) : ['merchant_unavailable']];
        }
        $safeEvents = $events->orderBy('starts_at', 'desc')->limit(100)->get(['id', 'name', 'state', 'timezone', 'starts_at', 'ends_at'])->map(function ($event) use ($tenant) {
            $start = \Carbon\CarbonImmutable::now($event->timezone)->startOfDay();
            $event->today_total_minor = (int) $this->store->rows('sales', $tenant)->where('event_id', $event->id)->where('state', 'succeeded')->where('confirmed_at', '>=', $start->utc())->where('confirmed_at', '<', $start->addDay()->utc())->sum('amount_minor');

            return $event;
        });

        return ['company' => request()->attributes->get('current_tenant')->name, 'owner_setup' => (bool) $owner,
            'operator' => $operator ? ['id' => $operator->id, 'name' => $operator->name, 'role' => $ctx['role']] : null,
            'device' => $ctx ? ['id' => $ctx['device']->id, 'name' => $ctx['device']->name, 'register_id' => $ctx['device']->register_id] : null,
            'shared' => $ctx['shared'] ?? false, 'shift_id' => $ctx['session']->shift_id ?? null, 'staff' => $staff,
            'current_shift' => $operator ? $this->store->rows('shifts', $tenant)->where('id', $ctx['session']->shift_id)->where('operator_id', $operator->id)->first(['id', 'event_id', 'state']) : null,
            'events' => $safeEvents, 'registers' => $safeRegisters,
            'reporting_timezone' => $this->store->rows('company_settings', $tenant)->value('reporting_timezone'),
            'baskets' => $operator ? $this->store->rows('baskets', $tenant)->where('operator_id', $operator->id)->orderByDesc('updated_at')->get(['id', 'event_id', 'register_id', 'state', 'updated_at']) : [],
            'favorites' => $operator ? $this->store->rows('favorites', $tenant)->where('operator_id', $operator->id)->pluck('item_id') : [],
            'devices' => $owner ? $this->store->rows('devices', $tenant)->get(['id', 'name', 'register_id', 'active']) : [],
            'build' => config('pos_live.web_build'), 'collection_enabled' => config('pos_live.collection_enabled') === true];
    }

    private function input(Request $r, array $rules): array
    {
        $data = $r->all();
        $top = array_filter(array_keys($rules), fn ($k) => ! str_contains($k, '.'));
        if (array_diff(array_keys($data), $top) || Validator::make($data, $rules)->fails()) {
            throw new PosException('invalid_request', 422);
        }

        return $data;
    }

    public function ownerAction(Request $r, string $tenant, string $action)
    {
        $ctx = $this->access->owner();
        if ($action === 'staff') {
            return $this->ops->operator($this->input($r, ['id' => ['sometimes', 'regex:/^[a-f0-9]{32}$/D'], 'name' => 'required|string|max:100', 'role' => 'required|in:cashier,manager,admin',
                'active' => 'required|boolean', 'user_id' => 'sometimes|nullable|integer|min:1', 'pin' => ['sometimes', 'string', 'regex:/^\d{6}$/D']]));
        }
        if ($action === 'timezone') {
            $i = $this->input($r, ['timezone' => 'required|timezone']);

            return $this->ops->company($i['timezone']);
        }
        if ($action === 'enroll') {
            $i = $this->input($r, ['register_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'], 'name' => 'required|string|max:100', 'shared' => 'required|boolean']);
            if (! is_bool($i['shared'])) {
                throw new PosException('invalid_request', 422);
            }

            return $this->access->enroll($i['register_id'], $i['name'], $i['shared']);
        }
        if ($action === 'reader') {
            $i = $this->input($r, ['register_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'], 'location_id' => ['required', 'regex:/^tml_[A-Za-z0-9]+$/D'], 'reader_id' => ['required', 'regex:/^tmr_[A-Za-z0-9]+$/D']]);
            app(PosStripeSetup::class)->bind([...$ctx, 'admin' => true], $i['register_id'], $i['location_id'], $i['reader_id']);

            return ['bound' => true, 'collection_enabled' => false];
        }
        if ($action === 'revoke-device') {
            $i = $this->input($r, ['device_id' => ['required', 'regex:/^[a-f0-9]{32}$/D']]);
            $this->store->rows('devices', $ctx['tenant_id'])->where('id', $i['device_id'])->update(['active' => false]);

            return ['revoked' => true];
        }
        if ($action === 'assignment') {
            $i = $this->input($r, ['event_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'], 'operator_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'], 'active' => 'required|boolean']);
            $this->store->rows('trade_events', $ctx['tenant_id'])->where('id', $i['event_id'])->firstOrFail();
            $this->store->rows('operators', $ctx['tenant_id'])->where('id', $i['operator_id'])->firstOrFail();
            $this->store->rows('event_staff', $ctx['tenant_id'])->updateOrInsert(['event_id' => $i['event_id'], 'operator_id' => $i['operator_id']], $this->store->values($ctx['tenant_id'], ['id' => substr(hash('sha256', $i['event_id'].$i['operator_id']), 0, 32), 'active' => $i['active'], 'created_at' => now()]));

            return ['assigned' => $i['active']];
        }
        if ($action === 'pause') {
            $i = $this->input($r, ['register_id' => ['required', 'regex:/^[a-f0-9]{32}$/D']]);
            $this->store->rows('registers', $ctx['tenant_id'])->where('id', $i['register_id'])->update(['collection_enabled' => false]);

            return ['collection_enabled' => false];
        }
        throw new PosException('action_unavailable', 404);
    }

    public function createEvent(Request $r)
    {
        $i = $this->input($r, ['name' => 'required|string|max:100', 'timezone' => 'required|timezone', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'address' => 'required|array:address1,city,region,postal_code,country', 'address.address1' => 'required|string|max:150', 'address.city' => 'required|string|max:100', 'address.region' => 'required|string|max:100', 'address.postal_code' => 'required|string|max:20', 'address.country' => 'required|in:US',
            'register_ids' => 'required|array|min:1|max:30', 'register_ids.*' => ['required', 'distinct', 'regex:/^[a-f0-9]{32}$/D'],
            'operator_ids' => 'required|array|min:1|max:100', 'operator_ids.*' => ['required', 'distinct', 'regex:/^[a-f0-9]{32}$/D'], 'shopify_routing_verified' => 'required|boolean']);
        foreach (['starts_at', 'ends_at'] as $key) {
            $i[$key] = \Carbon\CarbonImmutable::parse($i[$key], $i['timezone'])->utc()->format('Y-m-d H:i:s');
        }

        return $this->ops->createEvent($i);
    }

    public function sessionAction(Request $r, string $tenant, string $action)
    {
        if ($action === 'unlock') {
            $i = $this->input($r, ['operator_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'], 'pin' => 'required|string|max:6']);

            return $this->access->unlock($i['operator_id'], $i['pin']);
        }
        if ($action === 'shift') {
            $i = $this->input($r, ['event_id' => ['required', 'regex:/^[a-f0-9]{32}$/D']]);

            return $this->ops->shift($i['event_id']);
        }
        if (in_array($action, ['lock', 'end-shift', 'touch'], true)) {
            $this->input($r, []);

            return $action === 'touch' ? $this->ops->touch() : $this->ops->lock($action === 'end-shift');
        }
        throw new PosException('action_unavailable', 404);
    }

    public function catalog(Request $r, string $tenant, string $register)
    {
        $this->input($r, []);

        return [...$this->pos->catalog($register), ...$this->pos->approvedQuotes($register)];
    }

    private function selectionRules(): array
    {
        return ['basket_id' => ['sometimes', 'regex:/^[a-f0-9]{32}$/D'], 'request_id' => 'required|uuid', 'source_order_id' => 'sometimes|integer|min:1|prohibits:items', 'items' => 'required_without:source_order_id|array|min:1|max:50',
            'items.*' => 'array:item_id,quantity,revision', 'items.*.item_id' => 'required|string|max:100|distinct', 'items.*.quantity' => 'required|integer|min:1|max:99', 'items.*.revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'customer' => 'sometimes|nullable|array:name,email', 'customer.name' => 'sometimes|string|max:150', 'customer.email' => 'sometimes|email:rfc|max:254'];
    }

    public function quote(Request $r, string $tenant, string $register)
    {
        $i = $this->input($r, $this->selectionRules());
        foreach ($i['items'] ?? [] as $line) {
            if (! is_int($line['quantity'])) {
                throw new PosException('invalid_request', 422);
            }
        }

        return $this->pos->quote($register, $i);
    }

    public function saleAction(Request $r, string $tenant, string $register, string $sale, string $action)
    {
        if (in_array($action, ['refund-status', 'refund-resume'], true)) {
            $i = $this->input($r, ['request_id' => 'required|uuid']);

            return $this->pos->refundStatus($register, $sale, $i['request_id'], $action === 'refund-resume');
        }
        if ($action === 'refund') {
            $i = $this->input($r, ['request_id' => 'required|uuid', 'amount_minor' => 'required|integer|min:1|max:100000000', 'reason' => 'required|in:requested_by_customer,duplicate,other',
                'restock' => 'required|boolean', 'restock_lines' => 'present|array|max:50', 'restock_lines.*' => 'array:item_id,quantity', 'restock_lines.*.item_id' => 'required|string|max:100|distinct', 'restock_lines.*.quantity' => 'required|integer|min:1|max:99']);
            if (! is_int($i['amount_minor'])) {
                throw new PosException('invalid_request', 422);
            }

            return $this->pos->refund($register, $sale, $i);
        }
        $this->input($r, []);

        return match ($action) {
            'collect' => $this->pos->start($register, $sale), 'status' => $this->pos->inspect($register, $sale),
            'receipt' => $this->pos->receipt($register, $sale), 'reconcile' => $this->pos->reconcile($register, $sale),
            'cancel', 'retry', 'resume' => $this->pos->readerControl($register, $sale, $action), default => throw new PosException('action_unavailable', 404),
        };
    }

    public function discardRequest(Request $r, string $tenant, string $register, string $requestId)
    {
        $this->input($r, []);

        return $this->pos->discard($register, $requestId);
    }

    public function requestStatus(Request $r, string $tenant, string $register, string $requestId)
    {
        $this->input($r, []);

        return $this->pos->requestStatus($register, $requestId);
    }

    public function basket(Request $r)
    {
        $rules = $this->selectionRules();
        unset($rules['request_id'], $rules['source_order_id'], $rules['basket_id']);
        $rules['items'] = 'required|array|min:1|max:50';
        $i = $this->input($r, [...$rules, 'register_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'], 'id' => ['sometimes', 'regex:/^[a-f0-9]{32}$/D']]);

        return $this->ops->basket($i);
    }

    public function resumeBasket(Request $r, string $tenant, string $basket)
    {
        return $this->ops->resumeBasket($basket);
    }

    public function favorite(Request $r, string $tenant, string $register)
    {
        $i = $this->input($r, ['item_id' => 'required|string|max:100', 'favorite' => 'required|boolean']);
        if (! is_bool($i['favorite'])) {
            throw new PosException('invalid_request', 422);
        }

        return $this->ops->favorite($register, $i['item_id'], $i['favorite']);
    }

    public function report(Request $r, string $tenant, ?string $format = null)
    {
        $i = $this->input($r, ['scope' => 'sometimes|in:device,user,company', 'period' => 'sometimes|in:day,month,year', 'date' => ['sometimes', 'regex:/^\d{4}(?:-\d{2}){0,2}$/D'], 'event_id' => ['sometimes', 'regex:/^[a-f0-9]{32}$/D'], 'offset' => 'sometimes|integer|min:0|max:50000', 'bucket' => 'sometimes|integer|min:0|max:30']);
        $result = app(PosPilotReports::class)->activity($i, $format === 'csv');
        if ($format !== 'csv') {
            return $result;
        }

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Time (UTC)', 'Kind', 'Sale', 'Event', 'Cashier', 'Amount (USD)', 'Sync pending'], ',', '"', '');
            foreach ($result['transactions'] as $row) {
                $values = [$row['at'], $row['kind'], $row['id'], $row['event'], $row['staff'], number_format($row['amount_minor'] / 100, 2, '.', ''), $row['sync_pending'] ? 'yes' : 'no'];
                foreach ($values as $key => $value) {
                    if ($key !== 5 && preg_match('/^[=+@\-\t\r]/', $value)) {
                        $values[$key] = "'".$value;
                    }
                }
                fputcsv($out, $values, ',', '"', '');
            }
            fclose($out);
        }, 'everbranch-pos-activity.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function eventAction(Request $r, string $tenant, string $event, string $action)
    {
        if ($action === 'count') {
            $i = $this->input($r, ['register_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'], 'stage' => 'required|in:opening,closing', 'counts' => 'present|array|max:200', 'counts.*' => 'array:item_id,quantity', 'counts.*.item_id' => 'required|string|max:100|distinct', 'counts.*.quantity' => 'required|integer|min:0']);

            return app(PosPilotReports::class)->countStock($event, $i['register_id'], $i['stage'], $i['counts']);
        }
        $this->input($r, []);

        return app(PosPilotReports::class)->eventState($event, $action);
    }
}
