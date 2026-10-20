<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleFollowUp;
use App\Models\WholesaleProspect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OperationsWholesale
{
    public function read(Tenant $tenant, User $user, TeamArea $area, string $view, Request $request): array
    {
        app(OperationsAccess::class)->area($tenant, $user, $area->id);
        abort_unless(app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'wholesale_operations'), 503, 'The existing Wholesale commerce source is not enabled. Teams account notes and pipeline remain available.');
        abort_unless(in_array($view, ['overview', 'accounts', 'orders', 'prospects', 'followups', 'suggestions']), 404);
        if (in_array($view, ['overview', 'orders', 'suggestions'])) {
            app(OperationsAccess::class)->financial($tenant, $user);
        }
        $service = app(\App\Services\Wholesale\WholesaleOperationsService::class);
        $required = in_array($view, ['prospects', 'followups']) ? 'wholesale_'.$view : 'orders';
        abort_unless(Schema::hasTable($required), 503, 'The existing Wholesale data service needs its schema and source configuration.');
        try {
            if ($view === 'overview') {
                return ['data' => $service->overview($tenant->id)['metrics'], 'scope' => 'Qualified wholesale orders only', 'as_of' => now()->toIso8601String()];
            }
            if ($view === 'accounts') {
                $admin = app(OperationsAccess::class)->admin(app(OperationsAccess::class)->actor($tenant, $user));
                $all = $service->customers($tenant->id)->map(fn ($c) => ['id' => $c['public_key'], 'title' => $c['company'], 'contact' => $c['primary_buyer'], 'email' => $c['email'], 'phone' => $c['phone'] ?? null, 'order_count' => $c['order_count'], 'status' => $c['timing_state'], 'last_order_at' => $c['last_order_at']?->toIso8601String(), ...($admin ? ['revenue' => $c['lifetime_revenue']] : [])])->values();
                $page = max(1, (int) $request->input('page', 1));

                return ['items' => $all->slice(($page - 1) * 30, 30)->values()->all(), 'page' => $page, 'has_more' => $all->count() > $page * 30, 'total' => $all->count()];
            }
            if ($view === 'orders') {
                $p = app(\App\Services\Wholesale\WholesaleQualifiedOrderScope::class)->query($tenant->id)->latest('ordered_at')->orderBy('id')->paginate(30);

                return ['items' => collect($p->items())->map(fn ($o) => ['id' => $o->id, 'title' => $o->name ?: $o->display_name ?: 'Wholesale order', 'customer' => $o->customer_name, 'ordered_at' => $o->ordered_at?->toIso8601String(), 'amount' => $o->total_price, 'status' => $o->financial_status])->all(), 'page' => $p->currentPage(), 'has_more' => $p->hasMorePages(), 'total' => $p->total()];
            }
            if ($view === 'suggestions') {
                return ['items' => \App\Models\WholesaleSuggestion::query()->forAllTenants()->where('tenant_id', $tenant->id)->where('status', 'pending')->latest()->limit(30)->get(['public_id', 'title', 'recommended_action', 'priority', 'confidence', 'supporting_evidence'])->toArray()];
            }
            $q = $view === 'prospects' ? WholesaleProspect::query()->forAllTenants()->where('tenant_id', $tenant->id)->orderByDesc('fit_score') : WholesaleFollowUp::query()->forAllTenants()->where('tenant_id', $tenant->id)->orderBy('due_at');
            $p = $q->paginate(30);

            return ['items' => collect($p->items())->map(fn ($item) => $view === 'prospects' ? ['id' => $item->public_id, 'title' => $item->business_name, 'status' => $item->status, 'fit_score' => $item->fit_score, 'city' => $item->city, 'state' => $item->state, 'do_not_contact' => (bool) $item->do_not_contact] : ['id' => $item->public_id, 'title' => $item->title, 'status' => $item->status, 'due_at' => $item->due_at?->toIso8601String()])->all(), 'page' => $p->currentPage(), 'has_more' => $p->hasMorePages(), 'total' => $p->total()];
        } catch (\Illuminate\Database\QueryException) {
            abort(503, 'Wholesale source data is unavailable. This is not an empty result.');
        }
    }

    public function act(Tenant $tenant, User $user, TeamArea $area, string $action, Request $request): array
    {
        app(OperationsAccess::class)->area($tenant, $user, $area->id, true);
        abort_unless(app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'wholesale_operations'), 403, 'The existing Wholesale source is not enabled.');
        app(OperationsAccess::class)->financial($tenant, $user);
        if ($action === 'suggestion') {
            $input = $request->validate(['id' => 'required|uuid', 'action' => 'required|in:accept,create_follow_up,snooze,dismiss,already_handled,mark_incorrect,request_review', 'note' => 'nullable|string|max:4000', 'snoozed_until' => 'nullable|date']);
            $decision = app(\App\Services\Wholesale\WholesaleSuggestionDecisionService::class)->decide($tenant->id, $input['id'], $user, $input);

            return ['id' => $decision->id, 'status' => 'recorded'];
        }
        if ($action === 'prospect') {
            $input = $request->validate(['id' => 'required|uuid', 'action' => 'required|in:qualify,reject,mark_duplicate,set_priority,mark_do_not_contact,clear_do_not_contact,add_note,request_research,record_call,record_contact_attempt,schedule_follow_up,convert', 'note' => 'nullable|string|max:4000', 'rejection_reason' => 'nullable|string|max:1000', 'priority' => 'nullable|in:low,normal,high', 'due_at' => 'nullable|date']);
            $prospect = WholesaleProspect::query()->forAllTenants()->where('tenant_id', $tenant->id)->where('public_id', $input['id'])->firstOrFail();
            $result = app(\App\Services\Wholesale\WholesaleProspectWorkflowService::class)->apply($prospect, $user, $input);

            return ['id' => $result->public_id, 'status' => $result->status];
        }
        abort(404);
    }

    public function statistics(Tenant $tenant, User $user, TeamArea $area): array
    {
        $access = app(OperationsAccess::class);
        $access->financial($tenant, $user);
        $overview = null;
        $notice = null;
        try {
            $overview = $this->read($tenant, $user, $area, 'overview', new Request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 503) {
                throw $e;
            } $notice = 'Qualified order actuals are unavailable. Pipeline estimates remain separate.';
        }
        $pipeline = [];
        $weighted = 0;
        $unknown = 0;
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'opportunity'])->whereNotIn('status', ['converted', 'lost', 'withdrawn'])->get() as $item) {
            $access->record($tenant, $user, $item);
            $amount = $item->data['amount_cents'] ?? null;
            $probability = $item->data['probability'] ?? null;
            $value = $amount !== null && $probability !== null ? intdiv((int) $amount * max(0, min(100, (int) $probability)), 100) : null;
            if ($value === null) {
                $unknown++;
            } else {
                $weighted += $value;
            }
            $pipeline[] = ['id' => $item->id, 'title' => $item->title, 'stage' => $item->status, 'amount_cents' => $amount, 'probability' => $probability, 'weighted_cents' => $value];
        }

        return ['qualified_metrics' => $overview['data'] ?? null, 'actuals_available' => $overview !== null, 'notice' => $notice, 'unknown_opportunities' => $unknown, 'pipeline' => $pipeline, 'weighted_pipeline_cents' => $weighted, 'basis' => 'Qualified order actuals and open opportunities shown separately. Pipeline is an estimate, not additional booked revenue. Predicted reorders are not added.', 'as_of' => now()->toIso8601String()];
    }
}
