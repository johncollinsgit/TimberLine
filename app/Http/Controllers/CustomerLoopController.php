<?php

namespace App\Http\Controllers;

use App\Models\CustomerLoopAction;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\CustomerLoop\CustomerLoopService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerLoopController extends Controller
{
    public function index(Request $request, CustomerLoopService $loop): View
    {
        $tenant = $this->tenant($request);
        $actions = CustomerLoopAction::query()->forTenantId($tenant->id)->with(['profile:id,first_name,last_name,email', 'assignee:id,name'])
            ->whereNotIn('status', [CustomerLoopAction::STATUS_COMPLETED, CustomerLoopAction::STATUS_DISMISSED])
            ->where(fn ($query) => $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->orderByRaw("case when status='suggested' then 0 when status='prepared' then 1 else 2 end")
            ->orderBy('due_at')->paginate(30);

        return view('customer-loop.index', [
            'actions' => $actions, 'templates' => $loop->templates(),
            'profiles' => MarketingProfile::query()->forTenantId($tenant->id)->orderBy('first_name')->limit(150)->get(['id', 'first_name', 'last_name', 'email']),
        ]);
    }

    public function store(Request $request, CustomerLoopService $loop): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['template' => ['required', 'string'], 'title' => ['required', 'string', 'max:190'], 'marketing_profile_id' => ['nullable', 'integer']]);
        abort_unless(array_key_exists($data['template'], $loop->templates()), 422);
        $profile = isset($data['marketing_profile_id']) ? MarketingProfile::query()->forTenantId($tenant->id)->findOrFail($data['marketing_profile_id']) : null;
        $activity = $loop->record($tenant->id, 'manual', null, $data['title'], 'Created in Customer Loop.', $profile, $request->user());
        $loop->suggest($tenant->id, $data['template'], $data['title'], $profile, $activity, $request->user());

        return back()->with('success', 'Customer Loop action created. It is a draft only until a person reviews it.');
    }

    public function prepare(Request $request, CustomerLoopAction $action, CustomerLoopService $loop): RedirectResponse
    {
        $this->assertAction($request, $action);
        $loop->prepare($action);

        return back()->with('success', 'Draft prepared. Review it before using any communication channel.');
    }

    public function complete(Request $request, CustomerLoopAction $action): RedirectResponse
    {
        $this->assertAction($request, $action);
        $action->forceFill(['status' => CustomerLoopAction::STATUS_COMPLETED, 'completed_at' => now(), 'snoozed_until' => null])->save();

        return back()->with('success', 'Customer Loop action completed.');
    }

    public function snooze(Request $request, CustomerLoopAction $action): RedirectResponse
    {
        $this->assertAction($request, $action);
        $action->forceFill(['status' => CustomerLoopAction::STATUS_SNOOZED, 'snoozed_until' => now()->addDays(3)])->save();

        return back()->with('success', 'Customer Loop action snoozed for three days.');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function assertAction(Request $request, CustomerLoopAction $action): void
    {
        abort_unless((int) $action->tenant_id === (int) $this->tenant($request)->id, 404);
    }
}
