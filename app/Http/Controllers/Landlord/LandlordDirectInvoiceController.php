<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDirectInvoice;
use App\Services\Billing\DirectInvoiceManagementService;
use App\Services\Billing\DirectInvoiceSmsReminderService;
use App\Services\Billing\DirectStripeInvoiceService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LandlordDirectInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-landlord-commercial');
        $tenantId = $request->integer('tenant_id');
        $status = strtolower(trim((string) $request->query('status')));
        $invoices = TenantDirectInvoice::query()->with('tenant')
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('landlord.invoices.index', [
            'invoices' => $invoices,
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'tenantId' => $tenantId,
            'status' => $status,
        ]);
    }

    public function create(Tenant $tenant): View
    {
        Gate::authorize('manage-landlord-commercial');

        return view('landlord.invoices.form', ['tenant' => $tenant, 'invoice' => new TenantDirectInvoice]);
    }

    public function store(Request $request, Tenant $tenant, DirectInvoiceManagementService $management): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $invoice = $management->createDraft($tenant, $this->validated($request), $request->user()?->id);

        return redirect()->route('landlord.invoices.show', [$tenant, $invoice])->with('status', 'Invoice draft created. Review it before sending.');
    }

    public function show(Tenant $tenant, TenantDirectInvoice $invoice, DirectStripeInvoiceService $stripe): View
    {
        Gate::authorize('manage-landlord-commercial');
        $invoice = $this->scoped($tenant, $invoice)->load('receipts');

        return view('landlord.invoices.show', ['tenant' => $tenant, 'invoice' => $invoice, 'stripeAvailable' => $stripe->availableFor($invoice)]);
    }

    public function edit(Tenant $tenant, TenantDirectInvoice $invoice): View
    {
        Gate::authorize('manage-landlord-commercial');
        $invoice = $this->scoped($tenant, $invoice);
        abort_unless($invoice->isEditable(), 409, 'Only an unsent draft can be edited.');

        return view('landlord.invoices.form', ['tenant' => $tenant, 'invoice' => $invoice]);
    }

    public function update(Request $request, Tenant $tenant, TenantDirectInvoice $invoice, DirectInvoiceManagementService $management): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $invoice = $management->updateDraft($this->scoped($tenant, $invoice), $this->validated($request), $request->user()?->id);

        return redirect()->route('landlord.invoices.show', [$tenant, $invoice])->with('status', 'Invoice draft updated.');
    }

    public function send(Request $request, Tenant $tenant, TenantDirectInvoice $invoice, DirectStripeInvoiceService $stripe): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $result = $stripe->send($this->scoped($tenant, $invoice), $request->user()?->id);

        return back()->with((bool) $result['ok'] ? 'status' : 'status_error', (bool) $result['ok'] ? 'Stripe emailed the invoice to the customer.' : (string) $result['message']);
    }

    public function void(Request $request, Tenant $tenant, TenantDirectInvoice $invoice, DirectStripeInvoiceService $stripe): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $result = $stripe->void($this->scoped($tenant, $invoice), $request->user()?->id);

        return back()->with((bool) $result['ok'] ? 'status' : 'status_error', (bool) $result['ok'] ? 'Stripe invoice voided. The audit trail remains available.' : (string) $result['message']);
    }

    public function remindBySms(Request $request, Tenant $tenant, TenantDirectInvoice $invoice, DirectInvoiceSmsReminderService $reminders): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $data = $request->validate([
            'customer_phone' => ['required', 'string', 'max:40'],
            'consent_confirmed' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ], [
            'consent_confirmed.accepted' => 'Confirm that the customer agreed to receive this billing text.',
        ]);
        $result = $reminders->send(
            $this->scoped($tenant, $invoice),
            (string) $data['customer_phone'],
            (string) $data['idempotency_key'],
            true,
            $request->user()?->id,
        );

        return back()->with((bool) $result['ok'] ? 'status' : 'status_error', (string) $result['message']);
    }

    protected function scoped(Tenant $tenant, TenantDirectInvoice $invoice): TenantDirectInvoice
    {
        abort_unless((int) $invoice->tenant_id === (int) $tenant->id, 404);

        return $invoice;
    }

    /** @return array<string,mixed> */
    protected function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:190'],
            'customer_email' => ['required', 'email:rfc', 'max:254'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'billing_address.line1' => ['required', 'string', 'max:190'],
            'billing_address.line2' => ['nullable', 'string', 'max:190'],
            'billing_address.city' => ['required', 'string', 'max:100'],
            'billing_address.state' => ['required', 'string', 'max:100'],
            'billing_address.postal_code' => ['required', 'string', 'max:20'],
            'billing_address.country' => ['required', 'string', 'size:2'],
            'days_until_due' => ['required', 'integer', 'min:1', 'max:90'],
            'authorization_reference' => ['required', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'footer' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.category' => ['required', Rule::in(TenantDirectInvoice::LINE_CATEGORIES)],
            'lines.*.description' => ['required', 'string', 'max:250'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'lines.*.unit_amount' => ['required', 'numeric', 'decimal:0,2', 'min:-999999.99', 'max:999999.99'],
        ];
    }

    /** @return array<string,mixed> */
    protected function validated(Request $request): array
    {
        $validator = Validator::make($request->all(), $this->rules());
        $validator->after(function ($validator) use ($request): void {
            if (filled($request->input('customer_phone')) && app(MarketingIdentityNormalizer::class)->toE164((string) $request->input('customer_phone')) === null) {
                $validator->errors()->add('customer_phone', 'Enter a valid 10-digit US phone number.');
            }
            foreach ((array) $request->input('lines', []) as $index => $line) {
                if (! is_array($line) || ! is_numeric($line['unit_amount'] ?? null)) {
                    continue;
                }

                $category = strtolower(trim((string) ($line['category'] ?? '')));
                $unitAmount = (float) $line['unit_amount'];
                if ($category === 'discount' && $unitAmount >= 0) {
                    $validator->errors()->add("lines.{$index}.unit_amount", 'Discount amounts must be negative.');
                }
                if ($category !== 'discount' && $unitAmount <= 0) {
                    $validator->errors()->add("lines.{$index}.unit_amount", 'Charge amounts must be greater than zero.');
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
