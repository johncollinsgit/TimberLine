<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDirectInvoice;
use App\Services\Billing\DirectInvoiceManagementService;
use App\Services\Billing\DirectInvoiceSmsReminderService;
use App\Services\Billing\DirectStripeInvoiceService;
use App\Services\Billing\TenantInvoiceBillingProfileService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class LandlordDirectInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-landlord-commercial');
        $tenantId = $request->integer('tenant_id');
        $status = strtolower(trim((string) $request->query('status')));
        $baseQuery = TenantDirectInvoice::query()->with('tenant')
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId));
        $summary = [
            'sent' => (clone $baseQuery)->whereNotIn('status', ['draft', 'sending'])->count(),
            'awaiting' => (clone $baseQuery)->whereIn('status', ['open', 'payment_failed'])->count(),
            'paid' => (clone $baseQuery)->where('status', 'paid')->count(),
        ];
        $invoices = $baseQuery
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('landlord.invoices.index', [
            'invoices' => $invoices,
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'tenantId' => $tenantId,
            'status' => $status,
            'summary' => $summary,
        ]);
    }

    public function create(Tenant $tenant, TenantInvoiceBillingProfileService $billingProfiles): View
    {
        Gate::authorize('manage-landlord-commercial');

        $defaults = $billingProfiles->defaultsFor($tenant);

        return view('landlord.invoices.form', [
            'tenant' => $tenant,
            'invoice' => new TenantDirectInvoice($defaults),
            'billingProfileComplete' => (bool) $defaults['has_saved_profile'],
        ]);
    }

    public function store(Request $request, Tenant $tenant, DirectInvoiceManagementService $management, TenantInvoiceBillingProfileService $billingProfiles, DirectStripeInvoiceService $stripe): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $invoice = $management->createDraft($tenant, $this->validated($request), $request->user()?->id);
        $billingProfiles->remember($tenant, $invoice);

        if ($request->boolean('send_now')) {
            $result = $stripe->send($invoice, $request->user()?->id);

            return redirect()->route('landlord.invoices.show', [$tenant, $invoice])->with((bool) $result['ok'] ? 'status' : 'status_error', (bool) $result['ok'] ? 'Invoice emailed to '.$invoice->customer_email.'.' : (string) $result['message']);
        }

        return redirect()->route('landlord.invoices.show', [$tenant, $invoice])->with('status', 'Invoice draft saved.');
    }

    public function show(Tenant $tenant, TenantDirectInvoice $invoice, DirectStripeInvoiceService $stripe): View
    {
        Gate::authorize('manage-landlord-commercial');
        $invoice = $this->scoped($tenant, $invoice)->load('receipts');

        return view('landlord.invoices.show', [
            'tenant' => $tenant,
            'invoice' => $invoice,
            'stripeAvailable' => $stripe->availableFor($invoice),
            'history' => $this->history($invoice),
        ]);
    }

    public function edit(Tenant $tenant, TenantDirectInvoice $invoice): View
    {
        Gate::authorize('manage-landlord-commercial');
        $invoice = $this->scoped($tenant, $invoice);
        abort_unless($invoice->isEditable(), 409, 'Only an unsent draft can be edited.');

        return view('landlord.invoices.form', ['tenant' => $tenant, 'invoice' => $invoice, 'billingProfileComplete' => true]);
    }

    public function update(Request $request, Tenant $tenant, TenantDirectInvoice $invoice, DirectInvoiceManagementService $management, TenantInvoiceBillingProfileService $billingProfiles, DirectStripeInvoiceService $stripe): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $invoice = $management->updateDraft($this->scoped($tenant, $invoice), $this->validated($request), $request->user()?->id);
        $billingProfiles->remember($tenant, $invoice);

        if ($request->boolean('send_now')) {
            $result = $stripe->send($invoice, $request->user()?->id);

            return redirect()->route('landlord.invoices.show', [$tenant, $invoice])->with((bool) $result['ok'] ? 'status' : 'status_error', (bool) $result['ok'] ? 'Invoice emailed to '.$invoice->customer_email.'.' : (string) $result['message']);
        }

        return redirect()->route('landlord.invoices.show', [$tenant, $invoice])->with('status', 'Invoice draft saved.');
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

    /** @return array<int,array{at:Carbon,label:string,detail:?string,kind:string}> */
    protected function history(TenantDirectInvoice $invoice): array
    {
        $events = [
            $this->historyEvent($invoice->created_at, 'Draft created', 'Invoice prepared for '.$invoice->customer_email, 'draft'),
            $this->historyEvent($invoice->finalized_at, 'Invoice finalized', 'Stripe prepared the invoice for delivery.', 'email'),
            $this->historyEvent($invoice->sent_at, 'Invoice email sent', 'Sent to '.$invoice->customer_email, 'email'),
            $this->historyEvent($invoice->paid_at, 'Payment received', 'Stripe confirmed payment.', 'payment'),
            $this->historyEvent($invoice->voided_at, 'Invoice voided', 'The invoice was voided; its history remains available.', 'void'),
            $this->historyEvent($invoice->refunded_at, 'Payment refunded', 'Stripe recorded a refund.', 'refund'),
        ];
        if ($invoice->failed_at) {
            $events[] = $this->historyEvent($invoice->failed_at, $invoice->status === 'payment_failed' ? 'Payment issue reported' : 'Invoice send failed', null, 'issue');
        }
        foreach ((array) data_get($invoice->metadata, 'sms_invoice_reminders', []) as $reminder) {
            if (! is_array($reminder)) {
                continue;
            }
            $sent = ($reminder['status'] ?? null) === 'sent';
            $phoneLastFour = trim((string) ($reminder['phone_last_four'] ?? ''));
            $events[] = $this->historyEvent(
                $reminder['completed_at'] ?? $reminder['requested_at'] ?? null,
                $sent ? 'Payment reminder text sent' : 'Payment reminder text not sent',
                $phoneLastFour !== '' ? 'Phone ending in '.$phoneLastFour : null,
                $sent ? 'sms' : 'issue',
            );
        }
        foreach ($invoice->receipts as $receipt) {
            $events[] = $this->historyEvent($receipt->paid_at, 'Receipt recorded', 'Stripe confirmed payment receipt'.($receipt->invoice_number ? ' '.$receipt->invoice_number : '').'.', 'payment');
        }

        return collect($events)
            ->filter()
            ->sortByDesc(fn (array $event): int => $event['at']->getTimestamp())
            ->values()
            ->all();
    }

    /** @return array{at:Carbon,label:string,detail:?string,kind:string}|null */
    protected function historyEvent(mixed $at, string $label, ?string $detail, string $kind): ?array
    {
        $date = $this->historyDate($at);

        return $date ? ['at' => $date, 'label' => $label, 'detail' => $detail, 'kind' => $kind] : null;
    }

    protected function historyDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
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
