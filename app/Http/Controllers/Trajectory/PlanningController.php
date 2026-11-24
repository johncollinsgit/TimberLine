<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Services\Trajectory\AnomalyService;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\WorkspaceService;
use Illuminate\Http\Request;

class PlanningController extends Controller
{
    private function bankOwner(Request $request, Space $space, \App\Models\Trajectory\Connection $connection): void
    {
        app(WorkspaceService::class)->manage($request->user(), $space);
        abort_unless($connection->space_id === $space->id, 404);
        abort_unless(($connection->coverage['owner_user_id'] ?? $space->owner_user_id) === $request->user()->id, 403);
        abort_if($connection->status === 'disconnected', 422, 'Reconnect this bank first.');
    }

    public function bankAccounts(Request $request, Space $space, \App\Models\Trajectory\Connection $connection)
    {
        $this->bankOwner($request, $space, $connection);
        $provider = app(\App\Services\Trajectory\BankProviderService::class);
        $remote = $provider->accounts($connection);
        $local = Account::where('connection_id', $connection->id)->get()->keyBy('source_key');
        $allowed = app(FinanceAccess::class)->spaces($request->user())->pluck('id');
        $rows = collect($remote)->map(function ($a) use ($local, $connection, $allowed, $provider) {
            $existing = $local->get($provider->sourceKey($connection, $a['id']));
            // A connection owner cannot inspect another space after losing membership.
            if ($existing && ! $allowed->contains($existing->space_id)) {
                return null;
            }

            return [...$a, 'space_id' => $existing?->space_id, 'existing' => (bool) $existing];
        })->filter()->values();

        $manual = $connection->provider === 'simplefin' ? Account::whereIn('space_id', $allowed)->whereNull('connection_id')->where('source_key', 'like', 'manual:%')
            ->whereNotIn('id', Transaction::select('account_id'))->get()->map->only(['id', 'space_id', 'name', 'kind', 'currency'])->all() : [];

        return response()->json(['accounts' => $rows, 'provider' => $connection->provider, 'manual_accounts' => $manual])->header('Cache-Control', 'private, no-store');
    }

    public function mapBankAccounts(Request $request, Space $space, \App\Models\Trajectory\Connection $connection)
    {
        $this->bankOwner($request, $space, $connection);
        $data = $request->validate(['assignments' => 'required|array|min:1|max:100', 'assignments.*.id' => 'required|string|max:200|distinct', 'assignments.*.space_id' => 'required|integer',
            'assignments.*.kind' => 'nullable|in:cash,credit,loan', 'assignments.*.balance_multiplier' => 'nullable|integer|in:1,-1', 'assignments.*.reuse_account_id' => 'nullable|integer|distinct']);
        $provider = app(\App\Services\Trajectory\BankProviderService::class);
        $remote = $provider->accounts($connection);
        $remoteIds = collect($remote)->pluck('id');
        $mapping = [];
        foreach ($data['assignments'] as $assignment) {
            abort_unless($remoteIds->contains($assignment['id']), 422, 'Choose an account returned by this bank.');
            app(WorkspaceService::class)->manage($request->user(), Space::findOrFail($assignment['space_id']));
            if ($connection->provider === 'simplefin') {
                abort_unless(isset($assignment['kind'], $assignment['balance_multiplier'])
                    && ($assignment['kind'] !== 'cash' || (int) $assignment['balance_multiplier'] === 1), 422, 'Review the account type and bank balance meaning. Cash retains its reported sign.');
            }
            $mapping[$assignment['id']] = $assignment['space_id'];
        }
        \Illuminate\Support\Facades\Cache::lock('trajectory:sync:'.$connection->id, 300)->block(5, function () use ($connection, $mapping, $request, $space, $data, $provider, $remote) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($connection, $mapping, $request, $space, $data, $provider, $remote) {
                $connection = \App\Models\Trajectory\Connection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                $this->bankOwner($request, $space, $connection);
                foreach ($mapping as $id => $target) {
                    app(WorkspaceService::class)->manage($request->user(), Space::whereKey($target)->lockForUpdate()->firstOrFail());
                    $account = Account::where('source_key', $provider->sourceKey($connection, $id))->first();
                    abort_if($account && $account->space_id !== $target, 422, 'Use Assign owner on an imported account so its history and linked plans can be checked.');
                }
                $before = $connection->coverage['account_spaces'] ?? [];
                $coverage = $connection->coverage ?? [];
                $resetHistory = $connection->provider !== 'simplefin';
                if ($connection->provider === 'simplefin') {
                    foreach ($data['assignments'] as $assignment) {
                        $id = $assignment['id'];
                        $resetHistory = $resetHistory || ! isset($before[$id]);
                        $key = $provider->sourceKey($connection, $id);
                        $existing = Account::where('source_key', $key)->lockForUpdate()->first();
                        abort_if($existing && ($existing->kind !== $assignment['kind'] || (($coverage['balance_multipliers'][$id] ?? null) !== (int) $assignment['balance_multiplier'] && ! ($coverage['balance_review_pending'][$id] ?? false))), 422, 'An imported account cannot change its type or balance convention during mapping.');
                        if (! empty($assignment['reuse_account_id'])) {
                            abort_if($existing, 422, 'This bank account already has a saved account.');
                            $reuse = Account::where('space_id', $assignment['space_id'])->lockForUpdate()->findOrFail($assignment['reuse_account_id']);
                            abort_unless(! $reuse->connection_id && str_starts_with($reuse->source_key, 'manual:') && $reuse->kind === $assignment['kind'] && $reuse->currency === 'USD'
                                && ! Transaction::where('account_id', $reuse->id)->exists(), 422, 'Choose a matching manual balance account without transaction history.');
                            app(\App\Services\Trajectory\CashBalanceSourceService::class)->assertUnlinked(Space::findOrFail($reuse->space_id), $reuse);
                            $prior = $reuse->only(['source_key', 'connection_id']);
                            $reuse->update(['source_key' => $key, 'connection_id' => $connection->id]);
                            \App\Models\Trajectory\Event::create(['space_id' => $reuse->space_id, 'actor_id' => $request->user()->id, 'action' => 'manual_account_connected', 'record_id' => $reuse->id, 'before' => $prior, 'after' => $reuse->only(['source_key', 'connection_id'])]);
                        }
                        $coverage['account_kinds'][$id] = $assignment['kind'];
                        $coverage['balance_multipliers'][$id] = (int) $assignment['balance_multiplier'];
                        $coverage['zero_balance_review'][$id] = $assignment['kind'] !== 'cash' && collect($remote)->firstWhere('id', $id)['reported_balance_cents'] === 0;
                        unset($coverage['balance_review_pending'][$id]);
                    }
                }
                $connection->update(['coverage' => [...$coverage, 'require_account_mapping' => true, 'account_spaces' => [...$before, ...$mapping]], 'cursor' => $resetHistory ? null : $connection->cursor, 'status' => 'sync_pending']);
                \App\Models\Trajectory\Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'action' => 'bank_account_mapping', 'record_id' => $connection->id, 'before' => $before, 'after' => $mapping]);
            });
        });
        \App\Jobs\Trajectory\SyncBank::dispatch($connection->id);

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request, Space $space, WorkspaceService $workspaces)
    {
        $workspaces->manage($request->user(), $space);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:160',
            'income_categories' => 'sometimes|array|max:60', 'income_categories.*' => 'required|string|max:80',
            'income_expectations' => 'sometimes|array|max:60', 'income_expectations.*' => 'required|integer|min:0|max:100000000000',
            'flexible_monthly_cents' => 'sometimes|nullable|integer|min:0|max:100000000000',
            'debt_extra_cents' => 'sometimes|integer|min:0|max:100000000000', 'debt_target_months' => 'sometimes|integer|min:1|max:600',
            'tax_profile' => 'sometimes|array:state,federal_treatment,tax_year,industry',
            'tax_profile.state' => 'required_with:tax_profile|string|size:2|regex:/^[A-Z]{2}$/',
            'tax_profile.federal_treatment' => 'required_with:tax_profile|in:unknown,sole_proprietor,single_member_llc,partnership,s_corporation,c_corporation',
            'tax_profile.tax_year' => 'required_with:tax_profile|integer|min:2000|max:2100',
            'tax_profile.industry' => 'nullable|string|max:160',
        ]);
        foreach (array_keys($data['income_categories'] ?? []) as $key) {
            abort_unless(preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key) && ! in_array($key, config('trajectory.categories'), true), 422, 'Use a unique category key of at most 40 lowercase letters, numbers, and underscores.');
        }
        foreach (array_keys($data['income_expectations'] ?? []) as $key) {
            abort_unless(in_array($key, [...$workspaces->categories($space), ...array_keys($data['income_categories'] ?? [])], true), 422, 'Choose an income category from this space.');
        }
        $workspaces->save($request->user(), $space, $data);

        return response()->json(['ok' => true]);
    }

    public function assign(Request $request, Space $space, Account $account, WorkspaceService $workspaces)
    {
        $data = $request->validate(['target_space_id' => 'required|integer']);
        $workspaces->assign($request->user(), $space, $account, Space::findOrFail($data['target_space_id']));

        return response()->json(['ok' => true]);
    }

    public function anomaly(Request $request, Space $space, Transaction $transaction, AnomalyService $anomalies)
    {
        $data = $request->validate(['version' => 'required|integer', 'decision' => 'required|in:normal,change', 'category' => 'sometimes|nullable|string|max:40']);
        $anomalies->resolve($request->user(), $space, $transaction, $data['version'], $data['decision'], $data['category'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function bud(Request $request, Space $space, FinanceAccess $access, DashboardService $dashboard)
    {
        $access->authorize($request->user(), $space);
        abort_unless(config('bud.core_enabled', true), 503, 'Bud is currently unavailable.');
        $data = $request->validate(['question' => 'required|string|max:2000', 'range' => 'sometimes|in:day,week,month,quarter,year,all,custom', 'account_id' => 'sometimes|nullable|integer|min:1', 'from' => 'required_if:range,custom|date_format:Y-m-d|before_or_equal:today', 'through' => 'required_if:range,custom|date_format:Y-m-d|after_or_equal:from|before_or_equal:today', 'accounting_basis' => 'sometimes|in:cash,accrual', 'comparison' => 'sometimes|in:previous_period,previous_year', 'use_ai' => 'sometimes|boolean', 'request_id' => 'required_if:use_ai,true|nullable|uuid']);
        $context = $dashboard->build($space, $data['range'] ?? 'month', null, $data['from'] ?? null, $data['through'] ?? null, false, $request->user(), isset($data['account_id']) ? (int) $data['account_id'] : null, $data['accounting_basis'] ?? 'accrual');
        $context['profit_loss_comparison'] = $data['comparison'] ?? 'previous_period';
        $reply = ! empty($data['use_ai'])
            ? app(\App\Services\Trajectory\TrajectoryAiService::class)->respond($request->user(), $space, $data['question'], $context, $data['request_id'] ?? null)
            : app(\App\Services\Bud\TrajectoryBudService::class)->respond($data['question'], $context);

        return response()->json($reply)->header('Cache-Control', 'private, no-store');
    }

    public function debtScenarios(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['extra_cents' => 'required|integer|min:0|max:100000000000', 'account_id' => 'sometimes|nullable|integer|min:1']);

        return response()->json(app(\App\Services\Trajectory\DebtScenarioSourceService::class)->build($space, (int) $data['extra_cents'], isset($data['account_id']) ? (int) $data['account_id'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function businesses(Request $request, FinanceAccess $access)
    {
        abort_unless(config('trajectory.enabled') && $request->user()->is_active && $request->user()->email_verified_at, 403);
        $tenants = $request->user()->tenants()->get()->filter(fn ($tenant) => $tenant->pivot->membership_active && app(\App\Services\Tenancy\TenantFinancialAccess::class)->allows($request->user(), $tenant) && app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'trajectory'));

        return response()->json($tenants->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'space_id' => Space::where('tenant_id', $t->id)->where('kind', 'business')->value('id')])->values())->header('Cache-Control', 'private, no-store');
    }

    public function addBusiness(Request $request)
    {
        $data = $request->validate(['tenant_id' => 'required|integer']);
        abort_unless(config('trajectory.enabled') && $request->user()->is_active && $request->user()->email_verified_at, 403);
        $tenant = $request->user()->tenants()->whereKey($data['tenant_id'])->firstOrFail();
        abort_unless($tenant->pivot->membership_active && app(\App\Services\Tenancy\TenantFinancialAccess::class)->allows($request->user(), $tenant) && app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'trajectory'), 403);
        $space = Space::firstOrCreate(['tenant_id' => $tenant->id, 'kind' => 'business'], ['owner_user_id' => $request->user()->id, 'name' => $tenant->name, 'enabled' => true]);

        if ($space->wasRecentlyCreated) {
            \App\Models\Trajectory\Event::create(['space_id' => $space->id, 'actor_id' => $request->user()->id, 'action' => 'business_space_created', 'after' => ['tenant_id' => $tenant->id, 'billing_change' => false]]);
        }

        return response()->json(['space' => $space->only(['id', 'name', 'kind'])]);
    }
}
