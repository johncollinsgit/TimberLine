<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantBudSetting;
use App\Services\Bud\TenantBudService;
use App\Services\Mobile\TenantMobileSupportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantSupportTicketController extends Controller
{
    public function __construct(private TenantMobileSupportService $support, private TenantBudService $bud) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        return view('account-help.index', ['tickets' => $this->support->index($tenant->id)['tickets'], 'budSetting' => TenantBudSetting::query()->firstOrCreate(['tenant_id' => $tenant->id], ['status' => 'disabled'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:180'], 'category' => ['required', 'in:help,bug,billing,feature,account'], 'priority' => ['required', 'in:low,normal,high,urgent'], 'body' => ['required', 'string', 'max:8000']]);
        $this->support->create($this->tenant($request), $request->user(), $data);
        return back()->with('success', 'Your ticket is in the Everbranch support queue.');
    }

    public function show(Request $request, int $ticket): View
    {
        return view('account-help.show', ['payload' => $this->support->show($this->tenant($request)->id, $ticket)]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:8000']]);
        $this->support->reply($this->tenant($request)->id, $ticket, $request->user(), $data['body'], 'tenant');
        return back()->with('success', 'Reply sent.');
    }

    public function requestBud(Request $request): RedirectResponse
    {
        $this->bud->request($this->tenant($request), $request->user());
        return back()->with('success', 'Bud activation was requested from Everbranch.');
    }

    public function askBud(Request $request): RedirectResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:3000']]);
        $answer = $this->bud->respond($this->tenant($request), $request->user(), $data['question']);
        return back()->with('bud_answer', $answer);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        return $tenant;
    }
}
