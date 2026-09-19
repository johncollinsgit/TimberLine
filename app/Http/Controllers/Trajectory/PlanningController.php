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
        $remote = app(\App\Services\Trajectory\PlaidService::class)->call('/accounts/get', ['access_token' => $connection->access_token]);
        $local = Account::where('connection_id', $connection->id)->get()->keyBy('source_key');
        $allowed = app(FinanceAccess::class)->spaces($request->user())->pluck('id');
        $rows = collect($remote['accounts'] ?? [])->filter(fn ($a) => ($a['balances']['iso_currency_code'] ?? null) === 'USD')->map(function ($a) use ($local, $connection, $allowed) {
            $existing = $local->get('plaid:'.$connection->id.':'.$a['account_id']);
            // A connection owner cannot inspect another space after losing membership.
            if ($existing && ! $allowed->contains($existing->space_id)) {
                return null;
            }

            return ['id' => $a['account_id'], 'name' => $a['name'], 'mask' => $a['mask'] ?? null, 'space_id' => $existing?->space_id, 'existing' => (bool) $existing];
        })->filter()->values();

        return response()->json(['accounts' => $rows])->header('Cache-Control', 'private, no-store');
    }

    public function mapBankAccounts(Request $request, Space $space, \App\Models\Trajectory\Connection $connection)
    {
        $this->bankOwner($request, $space, $connection);
        $data = $request->validate(['assignments' => 'required|array|min:1|max:100', 'assignments.*.id' => 'required|string|max:200|distinct', 'assignments.*.space_id' => 'required|integer']);
        $remote = app(\App\Services\Trajectory\PlaidService::class)->call('/accounts/get', ['access_token' => $connection->access_token]);
        $remoteIds = collect($remote['accounts'] ?? [])->filter(fn ($a) => ($a['balances']['iso_currency_code'] ?? null) === 'USD')->pluck('account_id');
        $mapping = [];
        foreach ($data['assignments'] as $assignment) {
            abort_unless($remoteIds->contains($assignment['id']), 422, 'Choose an account returned by this bank.');
            app(WorkspaceService::class)->manage($request->user(), Space::findOrFail($assignment['space_id']));
            $mapping[$assignment['id']] = $assignment['space_id'];
        }
        \Illuminate\Support\Facades\Cache::lock('trajectory:sync:'.$connection->id, 300)->block(5, function () use ($connection, $mapping, $request, $space) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($connection, $mapping, $request, $space) {
                $connection = \App\Models\Trajectory\Connection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                $this->bankOwner($request, $space, $connection);
                foreach ($mapping as $id => $target) {
                    $account = Account::where('source_key', 'plaid:'.$connection->id.':'.$id)->first();
                    abort_if($account && $account->space_id !== $target, 422, 'Use Assign owner on an imported account so its history and linked plans can be checked.');
                }
                $before = $connection->coverage['account_spaces'] ?? [];
                $connection->update(['coverage' => [...($connection->coverage ?? []), 'require_account_mapping' => true, 'account_spaces' => [...$before, ...$mapping]], 'cursor' => null, 'status' => 'sync_pending']);
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
        $data = $request->validate(['question' => 'required|string|max:2000', 'range' => 'sometimes|in:day,week,month,year,all,custom', 'from' => 'required_if:range,custom|date_format:Y-m-d', 'through' => 'required_if:range,custom|date_format:Y-m-d|after_or_equal:from']);

        return response()->json(app(\App\Services\Bud\TrajectoryBudService::class)->respond($data['question'], $dashboard->build($space, $data['range'] ?? 'month', null, $data['from'] ?? null, $data['through'] ?? null)))->header('Cache-Control', 'private, no-store');
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
