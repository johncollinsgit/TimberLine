<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Mail\AgreementProposalMail;
use App\Models\Agreement;
use App\Models\Tenant;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Agreements\AgreementTerminationService;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $agreement = $data['template_key'] === Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES
            ? $management->prepareCollinsElectric($tenant, $request->user()?->id, $this->cents($data['onboarding_amount'] ?? null) ?? 29900, $this->cents($data['launch_partner_amount'] ?? null) ?? 5900, $this->cents($data['standard_amount'] ?? null) ?? 14900, $data['additional_scope'] ?? null)
            : $management->prepareFrontYardFoods($tenant, $request->user()?->id, $this->cents($data['implementation_amount'] ?? null), $this->cents($data['due_on_acceptance'] ?? null), $this->cents($data['due_before_launch'] ?? null), $data['additional_scope'] ?? null);

        return redirect()->route('landlord.agreements.show', $agreement)->with('status', 'Agreement draft prepared.');
    }

    public function show(Agreement $agreement, AgreementManagementService $management): View
    {
        return view('landlord.agreements.show', [
            'agreement' => $agreement->load(['tenant', 'currentVersion', 'versions', 'acceptance', 'events', 'termination', 'billingOrders.receipts', 'tenant.billingReceipts']),
            'proposalUrl' => filled($agreement->public_token_encrypted) ? $management->shortPublicUrl($agreement) : null,
            'ownerEmail' => $agreement->tenant->users()->wherePivot('role', 'owner')->orderBy('users.id')->value('users.email'),
        ]);
    }

    public function edit(Agreement $agreement): View
    {
        abort_if($agreement->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION, 409, 'Sandbox validation agreements are read-only.');
        abort_if(in_array($agreement->status, ['accepted', 'active', 'termination_pending', 'terminated'], true), 409, 'Accepted agreements are read-only. Create an amendment.');

        return view('landlord.agreements.edit', ['agreement' => $agreement->load('currentVersion')]);
    }

    public function version(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        abort_if($agreement->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION, 409, 'Sandbox validation agreements cannot version the client agreement.');
        abort_if(in_array($agreement->status, ['accepted', 'active', 'termination_pending', 'terminated'], true), 409);
        $data = $request->validate($this->pricingRules(false));
        if ($agreement->template_key === Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES) {
            $management->prepareCollinsElectric($agreement->tenant, $request->user()?->id, $this->cents($data['onboarding_amount'] ?? null) ?? 29900, $this->cents($data['launch_partner_amount'] ?? null) ?? 5900, $this->cents($data['standard_amount'] ?? null) ?? 14900, $data['additional_scope'] ?? null);
        } else {
            $management->prepareFrontYardFoods($agreement->tenant, $request->user()?->id, $this->cents($data['implementation_amount'] ?? null), $this->cents($data['due_on_acceptance'] ?? null), $this->cents($data['due_before_launch'] ?? null), $data['additional_scope'] ?? null);
        }

        return redirect()->route('landlord.agreements.show', $agreement)->with('status', 'A new immutable version was created when content changed.');
    }

    public function send(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:10', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'recipient_email' => ['nullable', 'email:rfc,dns', 'max:255'],
        ]);
        $access = $management->send($agreement->load('currentVersion'), $request->user()?->id, $data['password'] ?? null, (int) ($data['expires_in_days'] ?? 14));

        $recipient = strtolower(trim((string) ($data['recipient_email'] ?? '')));
        if ($recipient !== '') {
            Mail::to($recipient)->send(new AgreementProposalMail($access['agreement'], $access['short_url'], $access['password']));
            $access['agreement']->forceFill(['recipient_email' => $recipient, 'email_sent_at' => now()])->save();
        }

        return redirect()->route('landlord.agreements.show', $agreement)
            ->with('proposal_access', ['url' => $access['short_url'], 'password' => $access['password']])
            ->with('status', $recipient !== '' ? 'Agreement email sent to '.$recipient.'. Copy the access details now; the password is never shown again.' : 'Proposal access was rotated. Copy the password now; it is never shown again.');
    }

    public function revoke(Request $request, Agreement $agreement, AgreementManagementService $management): RedirectResponse
    {
        $management->revoke($agreement, $request->user()?->id);

        return back()->with('status', 'Proposal access revoked.');
    }

    public function sendText(Request $request, Agreement $agreement, AgreementManagementService $management, TwilioSmsService $sms): RedirectResponse
    {
        $data = $request->validate([
            'recipient_phone' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $recipients = collect(explode(',', (string) $data['recipient_phone']))
            ->map(fn (string $phone): string => trim($phone))
            ->filter()
            ->unique(fn (string $phone): string => (string) preg_replace('/\D+/', '', $phone))
            ->values();

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['recipient_phone' => 'Enter at least one mobile number.']);
        }

        if ($recipients->count() > 10) {
            throw ValidationException::withMessages(['recipient_phone' => 'Send to no more than 10 people at a time.']);
        }

        $invalidRecipients = $recipients->filter(fn (string $phone): bool => ! preg_match('/\d{10,}/', (string) preg_replace('/\D+/', '', $phone)));
        if ($invalidRecipients->isNotEmpty()) {
            throw ValidationException::withMessages(['recipient_phone' => 'Each recipient must have a valid mobile number. Separate multiple numbers with commas.']);
        }

        $access = $management->send($agreement->load('currentVersion'), $request->user()?->id, null, (int) ($data['expires_in_days'] ?? 14));
        $message = 'Hi! '.$agreement->tenant->name.': your Everbranch workspace is ready. Open, approve & pay: '.$access['short_url'].' Code: '.$access['password'];
        $sent = [];
        $failed = [];

        foreach ($recipients as $phone) {
            $result = $sms->sendSms($phone, $message, ['tenant_id' => $agreement->tenant_id, 'source_type' => 'agreement', 'source_id' => $agreement->id]);
            if ($result['success'] ?? false) {
                $sent[] = $phone;
            } else {
                $failed[] = ['phone' => $phone, 'error' => trim((string) ($result['error_message'] ?? 'unknown provider error'))];
            }
        }

        if ($sent !== []) {
            $access['agreement']->forceFill(['recipient_phone' => implode(', ', $sent), 'sms_sent_at' => now()])->save();
            $access['agreement']->events()->create(['tenant_id' => $agreement->tenant_id, 'agreement_version_id' => $access['agreement']->current_version_id, 'actor_user_id' => $request->user()?->id, 'event_type' => 'agreement_text_sent', 'metadata' => ['recipient_phones' => $sent, 'failed_recipients' => $failed, 'purpose' => 'agreement_delivery']]);
        }

        if ($sent === []) {
            return back()->with('status_error', 'Agreement link was created, but no texts could be sent: '.collect($failed)->pluck('error')->unique()->implode('; '));
        }

        $message = 'Agreement link and access code were texted to '.count($sent).' '.str('recipient')->plural(count($sent)).'.';
        if ($failed !== []) {
            $message .= ' '.count($failed).' '.str('recipient')->plural(count($failed)).' could not be reached.';
        }

        $response = redirect()->route('landlord.agreements.show', $agreement)->with('status', $message);
        if ($failed !== []) {
            $response->with('status_error', 'Could not text '.collect($failed)->pluck('phone')->implode(', ').'.');
        }

        return $response;
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
            'template_key' => ['nullable', Rule::in([Agreement::TEMPLATE_FRONT_YARD_CLIENT_SERVICES, Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES])],
            'implementation_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'due_on_acceptance' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'due_before_launch' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'additional_scope' => ['nullable', 'string', 'max:20000'],
            'onboarding_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'launch_partner_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'standard_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ];
        if ($withTenant) {
            $rules['template_key'] = ['required', Rule::in([Agreement::TEMPLATE_FRONT_YARD_CLIENT_SERVICES, Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES])];
            $rules['tenant_id'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }

    protected function cents(mixed $amount): ?int
    {
        return $amount === null || $amount === '' ? null : (int) round(((float) $amount) * 100);
    }
}
