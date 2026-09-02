<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceAddressSuggestionService;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileInvoiceController extends Controller
{
    public function index(Request $request, TenantFinancialAccess $financialAccess): JsonResponse
    {
        [$tenant, $user] = $this->authorized($request, $financialAccess);
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'in:all,draft,active,open,paid,overdue,unlinked'],
            'cursor' => ['nullable', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:50'],
        ]);
        $status = (string) ($validated['status'] ?? 'all');
        $search = trim((string) ($validated['q'] ?? ''));
        $query = FieldServiceFinancialDocument::query()
            ->forTenantId((int) $tenant->id)
            ->where('document_type', 'invoice')
            ->with(['customer:id,first_name,last_name,email,phone,address_line_1,address_line_2,city,state,postal_code,country', 'job:id,title'])
            ->when($search !== '', function (Builder $documents) use ($search): void {
                $like = '%'.$search.'%';
                $documents->where(fn (Builder $match) => $match
                    ->where('document_number', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $customers) => $customers
                        ->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)));
            });

        match ($status) {
            'draft' => $query->where('source', 'quickbooks')->where('balance', '>', 0)
                ->where(function (Builder $documents): void {
                    $documents->whereNull('field_service_job_id')
                        ->orWhereHas('job', fn (Builder $jobs) => $jobs
                            ->where('external_source', 'quickbooks')
                            ->where('external_id', 'like', 'quickbooks:invoice:%'));
                }),
            'active' => $query->where('balance', '>', 0),
            'paid' => $query->whereRaw('lower(coalesce(status, \'\')) = ?', ['paid']),
            'open' => $query->where('balance', '>', 0)->where(function (Builder $documents): void {
                $documents->whereNull('due_date')->orWhereDate('due_date', '>=', today());
            }),
            'overdue' => $query->where('balance', '>', 0)->whereDate('due_date', '<', today()),
            'unlinked' => $query->whereNull('field_service_job_id'),
            default => null,
        };
        $page = $query->orderByDesc('transaction_date')->orderByDesc('id')
            ->cursorPaginate((int) ($validated['limit'] ?? 30), ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json([
            'invoices' => $page->getCollection()->map(fn (FieldServiceFinancialDocument $invoice): array => $this->payload($invoice))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function attach(Request $request, string $tenant, FieldServiceFinancialDocument $invoice, TenantFinancialAccess $financialAccess, FieldServiceAccessService $access): JsonResponse
    {
        [$tenantModel, $user] = $this->authorized($request, $financialAccess);
        abort_unless((int) $invoice->tenant_id === (int) $tenantModel->id && $invoice->document_type === 'invoice', 404);
        $validated = $request->validate(['field_service_job_id' => ['nullable', 'integer']]);
        $jobId = $validated['field_service_job_id'] ?? null;
        if ($jobId !== null) {
            $job = FieldServiceJob::query()->forTenantId((int) $tenantModel->id)->findOrFail((int) $jobId);
            abort_unless($access->canManageJobs($user, $tenantModel), 403);
            $invoice->field_service_job_id = $job->id;
        } else {
            $invoice->field_service_job_id = null;
        }
        $invoice->save();
        $invoice->load(['customer:id,first_name,last_name,email,phone,address_line_1,address_line_2,city,state,postal_code,country', 'job:id,title']);

        return response()->json(['ok' => true, 'invoice' => $this->payload($invoice)]);
    }

    public function addressSuggestions(Request $request, TenantFinancialAccess $financialAccess, FieldServiceAddressSuggestionService $suggestions): JsonResponse
    {
        $this->authorized($request, $financialAccess);
        $validated = $request->validate(['q' => ['required', 'string', 'min:3', 'max:180']]);

        return response()->json(['suggestions' => $suggestions->suggest((string) $validated['q'])]);
    }

    /** @return array{0:Tenant,1:User} */
    protected function authorized(Request $request, TenantFinancialAccess $financialAccess): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof User && $financialAccess->allows($user, $tenant), 403);

        return [$tenant, $user];
    }

    /** @return array<string,mixed> */
    protected function payload(FieldServiceFinancialDocument $invoice): array
    {
        $customer = $invoice->customer;

        return [
            'id' => (int) $invoice->id,
            'number' => $invoice->document_number ?: $invoice->external_id,
            'status' => strtolower((string) ($invoice->status ?: 'unknown')),
            'transaction_date' => $invoice->transaction_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total' => (float) ($invoice->total_amount ?? 0),
            'balance' => (float) ($invoice->balance ?? 0),
            'customer' => $customer ? [
                'id' => (int) $customer->id,
                'name' => trim($customer->first_name.' '.$customer->last_name) ?: ($customer->email ?: 'Customer'),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => array_filter([$customer->address_line_1, $customer->address_line_2, $customer->city, $customer->state, $customer->postal_code, $customer->country]),
            ] : null,
            'job' => $invoice->job ? ['id' => (int) $invoice->job->id, 'title' => $invoice->job->title] : null,
        ];
    }
}
