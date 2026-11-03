<?php

namespace App\Http\Controllers;

use App\Services\Inventory\StockCatalogSync;
use App\Services\Inventory\StockEventProduction;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\PosAdministration;
use App\Services\Pos\PosStore;
use App\Services\Pos\StockPosCommerce;
use Illuminate\Http\Request;

final class StockAdministrationController extends Controller
{
    public function __construct(private PosAdministration $admin, private StockLedger $stock, private StockEventProduction $production, private PosStore $pos) {}

    private function scope(): array
    {
        $scope = $this->admin->scope();
        abort_unless($scope['admin'], 403);

        return $scope;
    }

    public function index(Request $request)
    {
        $request->validate(['pool' => ['sometimes', 'regex:/^[a-f0-9]{32}$/D'], 'line' => 'sometimes|integer|min:1']);
        $scope = $this->scope();
        $tenant = $scope['tenant_id'];
        $pools = $this->stock->rows('pools', $tenant)->where('active', true)->orderBy('name')->get();
        $poolId = $request->query('pool', $pools->first()?->id);
        $pool = $poolId ? $this->stock->pool($tenant, $poolId) : null;
        $demand = $this->production->demand($tenant);
        $line = collect($demand)->firstWhere('line_id', (int) $request->query('line'));
        $legacy = $this->stock->db->table('orders as o')->join('retail_plan_items as i', 'i.order_id', '=', 'o.id')
            ->join('retail_plans as p', 'p.id', '=', 'i.retail_plan_id')->join('tenant_user as m', 'm.user_id', '=', 'p.created_by')
            ->where('m.tenant_id', $tenant)->where('m.membership_active', true)->whereNull('o.tenant_id')->whereNotNull('o.event_id')
            ->where('o.source', 'internal')->where('o.order_type', 'event')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('commerce_stock_production_sources as cs')->whereColumn('cs.order_id', 'o.id'))
            ->select(['o.id', 'o.order_label'])->distinct()->limit(50)->get();

        return response()->view('pos.stock', ['scope' => $scope, 'pools' => $pools, 'pool' => $pool,
            'items' => $pool ? $this->stock->listing($tenant, $pool->id) : [],
            'allItems' => $this->stock->rows('items', $tenant)->where('active', true)->orderBy('title')->get(),
            'demand' => $demand, 'line' => $line, 'legacySources' => $legacy, 'tenantSlug' => $request->query('tenant'),
            'events' => app(\App\Services\Inventory\StockEvents::class)->listing($tenant),
            'bindings' => $this->stock->rows('bindings', $tenant)->where('active', true)->get()], 200,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function update(Request $request)
    {
        $scope = $this->scope();
        $tenant = $scope['tenant_id'];
        $actor = $scope['actor_id'];
        $input = $request->validate(['request_id' => 'required|uuid', 'action' => 'required|in:event,pool,count,transfer,production_map,production_ready,adopt,catalog,register_pool,bundle',
            'required_quantity' => 'sometimes|integer|min:1|max:100', 'eligible_items' => 'sometimes|array|min:1|max:500', 'eligible_items.*' => ['distinct:strict', 'regex:/^[a-f0-9]{32}$/D'],
            'provider' => 'sometimes|in:manual,asana,teams', 'external_id' => 'sometimes|nullable|regex:/^[A-Za-z0-9_-]{1,100}$/D',
            'start_on' => 'sometimes|date_format:Y-m-d', 'end_on' => 'sometimes|date_format:Y-m-d|after_or_equal:start_on',
            'venue' => 'sometimes|nullable|string|max:250', 'address' => 'sometimes|nullable|string|max:250',
            'city' => 'sometimes|nullable|string|max:150', 'state' => 'sometimes|nullable|string|max:50', 'postal_code' => 'sometimes|nullable|string|max:20',
            'name' => 'sometimes|string|max:150', 'kind' => 'sometimes|in:warehouse,shared,show', 'event_id' => 'sometimes|nullable|integer|min:1',
            'pool_id' => 'sometimes|regex:/^[a-f0-9]{32}$/D', 'from_pool_id' => 'sometimes|regex:/^[a-f0-9]{32}$/D',
            'item_id' => 'sometimes|regex:/^[a-f0-9]{32}$/D', 'quantity' => 'sometimes|integer|min:0|max:10000000',
            'reason' => 'sometimes|in:opening_count,physical_count,damage,return,transfer,packed,returned',
            'line_id' => 'sometimes|integer|min:1', 'order_id' => 'sometimes|integer|min:1', 'revision' => 'sometimes|regex:/^[a-f0-9]{64}$/D', 'expected_version' => 'sometimes|integer|min:0',
            'physically_confirmed' => 'sometimes|accepted', 'register_id' => 'sometimes|regex:/^[a-f0-9]{32}$/D']);
        $require = function (array $keys) use ($input): void {
            foreach ($keys as $key) {
                if (! isset($input[$key])) {
                    throw new StockException('stock_form_incomplete', 422);
                }
            }
        };
        try {
            $key = 'admin:'.$input['request_id'];
            switch ($input['action']) {
                case 'bundle':
                    $require(['item_id', 'revision', 'required_quantity', 'eligible_items', 'physically_confirmed']);
                    app(\App\Services\Inventory\StockBundles::class)->review($tenant, $key, $actor, $input['item_id'], (int) $input['required_quantity'], $input['eligible_items'], $input['revision']);
                    break;
                case 'event':
                    $require(['name', 'provider', 'start_on', 'end_on', 'physically_confirmed']);
                    $eventInput = array_intersect_key($input, array_flip(['name', 'provider', 'external_id', 'event_id', 'start_on', 'end_on', 'venue', 'address', 'city', 'state', 'postal_code']));
                    if ($input['provider'] === 'manual') {
                        $eventInput['external_id'] = $input['request_id'];
                    } else {
                        $require(['external_id']);
                    }
                    app(\App\Services\Inventory\StockEvents::class)->review($tenant, $key, $actor, $eventInput);
                    break;
                case 'pool':
                    $require(['name', 'kind']);
                    $event = null;
                    if ($input['kind'] === 'show') {
                        $require(['event_id']);
                        if (! app(\App\Services\Inventory\StockEvents::class)->owns($tenant, (int) $input['event_id'])) {
                            throw new StockException('production_source_ownership_review_required');
                        }
                        $event = 'event:'.$input['event_id'];
                    }
                    $result = $this->stock->createPool($tenant, $key, $actor, $input['name'], $input['kind'], $event);

                    return redirect()->route($this->routeName(), ['tenant' => $request->query('tenant'), 'pool' => $result['pool_id']])->with('status', 'Stock pool created. Planned production has not been added to stock.');
                case 'count':
                    $require(['pool_id', 'item_id', 'quantity', 'reason', 'physically_confirmed', 'expected_version']);
                    $this->stock->count($tenant, $key, $actor, $input['pool_id'], [$input['item_id'] => (int) $input['quantity']], $input['reason'], [$input['item_id'] => (int) $input['expected_version']]);
                    break;
                case 'transfer':
                    $require(['from_pool_id', 'pool_id', 'item_id', 'quantity', 'reason', 'physically_confirmed']);
                    $this->stock->transfer($tenant, $key, $actor, $input['from_pool_id'], $input['pool_id'], [$input['item_id'] => (int) $input['quantity']], $input['reason']);
                    break;
                case 'adopt':
                    $require(['order_id', 'physically_confirmed']);
                    $this->production->adopt($tenant, $key, $actor, (int) $input['order_id']);
                    break;
                case 'production_map':
                    $require(['line_id', 'item_id', 'revision', 'physically_confirmed']);
                    $this->production->map($tenant, $key, $actor, (int) $input['line_id'], $input['item_id'], $input['revision']);
                    break;
                case 'production_ready':
                    $require(['line_id', 'pool_id', 'quantity', 'revision', 'physically_confirmed']);
                    $this->production->confirm($tenant, $key, $actor, (int) $input['line_id'], $input['pool_id'], (int) $input['quantity'], $input['revision'], true);
                    break;
                case 'catalog':
                    $require(['register_id']);
                    $register = $scope['registers']->firstWhere('id', $input['register_id']);
                    if (! $register || $register->catalog_source !== 'shopify') {
                        throw new StockException('stock_register_unavailable', 404);
                    }
                    $settings = $this->pos->decrypt($register->configuration)['shopify'] ?? [];
                    if (! isset($settings['store_id'], $settings['location_id'], $settings['shop_id'])) {
                        throw new StockException('stock_catalog_setup_required');
                    }
                    app(StockCatalogSync::class)->import($tenant, (int) $settings['store_id'], $settings['location_id'], $settings['shop_id'], $actor);
                    break;
                case 'register_pool':
                    $require(['register_id', 'pool_id', 'physically_confirmed']);
                    $this->stock->operation($tenant, $key, 'register_pool', $actor, [$input['register_id'], $input['pool_id']], function () use ($tenant, $actor, $input, $key): array {
                        $register = $this->pos->rows('registers', $tenant)->where('id', $input['register_id'])->lockForUpdate()->first();
                        if (! $register) {
                            throw new StockException('stock_register_unavailable', 404);
                        }
                        if ($this->pos->rows('sales', $tenant)->where('register_id', $register->id)->whereIn('state', ['quoted', 'processing', 'unknown', 'review'])->exists()) {
                            throw new StockException('stock_register_has_unresolved_sale');
                        }
                        $this->stock->bind($tenant, $key.':binding', $actor, 'pos', $this->pos->runtime().':'.$register->id, $input['pool_id']);
                        $settings = $this->pos->decrypt($register->configuration);
                        $settings['commerce_adapter'] = StockPosCommerce::VERSION;
                        $settings['inventory_pool_id'] = $input['pool_id'];
                        $this->pos->rows('registers', $tenant)->where('id', $register->id)->update(['configuration' => $this->pos->encrypt($settings), 'collection_enabled' => false]);
                        $this->pos->event($tenant, $register->id, 'owned_stock_configured', ['actor_id' => $actor, 'pool_id' => $input['pool_id']]);

                        return ['register_id' => $register->id];
                    });
                    break;
            }
        } catch (StockException $error) {
            return back()->withErrors(['stock' => $this->message($error->reason)]);
        }

        return back()->with('status', 'Saved.');
    }

    private function routeName(): string
    {
        return config('enterprise.runtime') ? 'enterprise.pos.stock.index' : 'pos.admin.stock.index';
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            'stock_unavailable' => 'There are not enough available units in the source pool.',
            'count_below_reserved_stock' => 'This count is below units held for payments or online orders. Review those reservations first.',
            'production_plan_changed' => 'This production plan changed. Refresh and review its current quantities.',
            'production_exceeds_plan_review_required' => 'This would exceed the plan. Review the plan and already confirmed quantities first.',
            'production_mapping_required', 'production_mapping_has_receipts' => 'Review this production line’s exact product mapping before receiving more stock.',
            'production_event_pool_mismatch' => 'Choose the stock pool assigned to this production plan’s event.',
            'stock_register_has_unresolved_sale' => 'Resolve this register’s open sale before changing its stock pool.',
            'show_stock_pool_exists' => 'This event already has a stock pool. Select that pool above.',
            default => 'This change needs review. Refresh the page and check the selected event, product and quantities.',
        };
    }
}
