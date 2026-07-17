<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Tenant;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Agreements\AgreementTerminationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LandlordAgreementController extends Controller
{
    public function index(Request $request): View
    {
        $status = strtolower(trim((string) $request->query('status')));
        $tenantId = $request->integer('tenant_id');
        $agreements = Agreement::query()->with(['tenant', 'currentVersion', 'acceptance', 'termination'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest('id')->paginate(25)->withQueryString();

        return view('landlord.agreements.index', ['agreements' => $agreements, 'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']), 'status' => $status, 'tenantId' => $tenantId]);
    }

    public function forTenant(Tenant $tenant): View
    {
        $agreements = Agreement::query()->forTenant($tenant)->with(['currentVersion', 'acceptance', 'termination'])->latest('id')->paginate(25);

        return view('landlord.agreements.index', ['agreements' => $agreements, 'tenants' => collect([$tenant]), 'status' => '', 'tenantId' => (int) $tenant->id]);
    }

    public function create(Request $request): View
    {
        return view('landlord.agreements.create', ['tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']), 'selectedTenantId' => $request->integer('tenant_id')]);
    }

    public function store(Request $request, AgreementManagementService $management): RedirectResponse
    {
        $data = $request->validate($this->pricingRules());
        $tenant = Tenant::query()->findOrFail((int) $data['tenant_id']);
        $agreement = $management->prepareFrontYardFoods($tenant, $request->user()?->id, $this->cents($data['implementation_amount'] ?? null), $this->cents($data['due_on_acceptance'] ?? null), $this->cents($data['due_before_launch'] ?? null), $data['additional_scope'] ?? null);

        return redirect()->route('landlord.agreements.show', $agreement)->with('status', 'Agreement draft prepared.');
    }

    public function show(Agreement $agreement, AgreementManagementService $management): View
    {
        return view('landlord.agreements.show', [
            'agreement' => $agreement->load(['tenant', 'currentVersion', 'versions', 'acceptance', 'events', 'termination', 'billingOrders.receipts', 'tenant.billingReceipts']),
            'proposalUrl' => filled($agreement->public_token_encrypted) ? $management->publicUrl((string) $agreement->public_token_encrypted) : null,
        ]);
    }

    public function edit(Agreement $agreement): View
    {
        abort_if(in_array($agreement->status, ['accepted', 'active', 'termination_pending', 'terminated'], true), 409, 'Accepted agreements are read-only. Create an amendment.');

        return view('landlord.agreements.edit', ['agreement' => $agreement->load('currentVersion')]);
    }

    public function version(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        abort_if(in_array($agreement->status, ['accepted', 'active', 'termination_pending', 'terminated'], true), 409);
        $data = $request->validate($this->pricingRules(false));
        $management->prepareFrontYardFoods($agreement->tenant, $request->user()?->id, $this->cents($data['implementation_amount'] ?? null), $this->cents($data['due_on_acceptance'] ?? null), $this->cents($data['due_before_launch'] ?? null), $data['additional_scope'] ?? null);

        return redirect()->route('landlord.agreements.show', $agreement)->with('status', 'A new immutable version was created when content changed.');
    }

    public function send(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        $data = $request->validate(['password' => ['nullable', 'string', 'min:10', 'max:255'], 'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90']]);
        $access = $management->send($agreement->load('currentVersion'), $request->user()?->id, $data['password'] ?? null, (int) ($data['expires_in_days'] ?? 14));

        return redirect()->route('landlord.agreements.show', $agreement)->with('proposal_access', ['url' => $access['url'], 'password' => $access['password']])->with('status', 'Proposal access was rotated. Copy the password now; it is never shown again.');
    }

    public function revoke(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        $management->revoke($agreement, $request->user()?->id);

        return back()->with('status', 'Proposal access revoked.');
    }

    public function notes(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        $data = $request->validate(['internal_notes' => ['nullable', 'string', 'max:10000']]);
        $management->updateInternalNotes($agreement, $request->user()?->id, $data['internal_notes'] ?? null);

        return back()->with('status', 'Internal notes updated.');
    }

    public function amendment(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        $data = $request->validate(['implementation_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'], 'additional_scope' => ['nullable', 'string', 'max:20000']]);
        $amendment = $management->createAmendment($agreement, $request->user()?->id, $this->cents($data['implementation_amount'] ?? null), $data['additional_scope'] ?? null);

        return redirect()->route('landlord.agreements.show', $amendment)->with('status', 'Read-only amendment draft created.');
    }

    public function supplementalWork(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:20000'],
            'pricing_type' => ['required', Rule::in(['fixed', 'hourly'])],
            'fixed_amount' => ['nullable', 'required_if:pricing_type,fixed', 'numeric', 'min:0.01', 'max:999999.99'],
            'approved_hours' => ['nullable', 'required_if:pricing_type,hourly', 'numeric', 'min:0.01', 'max:9999'],
        ]);
        $hours = $data['pricing_type'] === 'hourly' ? (float) $data['approved_hours'] : null;
        $amount = $hours !== null ? (int) round($hours * 5000) : $this->cents($data['fixed_amount']);
        $work = $management->createSupplementalWork($agreement, $request->user()?->id, (string) $data['description'], (int) $amount, $hours);

        return redirect()->route('landlord.agreements.show', $work)->with('status', 'Supplemental work order prepared for separate acceptance and payment.');
    }

    public function milestone(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        $milestone = $management->createImplementationMilestone($agreement, $request->user()?->id);

        return redirect()->route('landlord.agreements.show', $milestone)->with('status', 'The accepted due-before-launch milestone was prepared for separate signature and payment.');
    }

    public function termination(Request $request, Agreement $agreement, AgreementTerminationService $terminations): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:5000'], 'effective_at' => ['nullable', 'date', 'after_or_equal:today']]);
        $terminations->request($agreement, $request->user()?->id, $data['reason'] ?? null, isset($data['effective_at']) ? new \DateTimeImmutable($data['effective_at']) : null);

        return back()->with('status', 'Termination scheduled with a 30-day export window.');
    }

    public function export(Request $request, Agreement $agreement, AgreementTerminationService $terminations): RedirectResponse
    {
        $data = $request->validate(['export_status' => ['required', Rule::in(['requested', 'completed'])], 'export_reference' => ['nullable', 'string', 'max:255']]);
        $terminations->markExport($agreement, $request->user()?->id, $data['export_status'], $data['export_reference'] ?? null);

        return back()->with('status', 'Export tracking updated.');
    }

    public function download(Agreement $agreement): BinaryFileResponse
    {
        $acceptance = $agreement->acceptance()->firstOrFail();

        return Storage::disk('local')->download((string) $acceptance->snapshot_path, 'agreement-'.$agreement->id.'-accepted.html', ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @return array<string,array<int,mixed>> */
    protected function pricingRules(bool $withTenant = true): array
    {
        $rules = [
            'implementation_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'due_on_acceptance' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'due_before_launch' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'additional_scope' => ['nullable', 'string', 'max:20000'],
        ];
        if ($withTenant) {
            $rules['tenant_id'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }

    protected function cents(mixed $amount): ?int
    {
        return $amount === null || $amount === '' ? null : (int) round(((float) $amount) * 100);
    }
}
