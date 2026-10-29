<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceInvoicePreviewService;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileInvoicePreviewController extends Controller
{
    public function __invoke(
        Request $request,
        TenantFinancialAccess $financialAccess,
        FieldServiceInvoicePreviewService $previews,
    ): JsonResponse {
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof User && $financialAccess->allows($user, $tenant), 403);

        $input = $request->validate([
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_email' => ['nullable', 'email', 'max:254'],
            'line_items' => ['required', 'array', 'min:1', 'max:100'],
            'line_items.*.description' => ['required', 'string', 'max:500'],
            'line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'line_items.*.unit_amount_cents' => ['required', 'integer', 'min:0', 'max:999999999'],
            'due_date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'invoice_provider' => ['required', 'in:everbranch_pay,quickbooks'],
        ]);

        return response()->json(['preview' => $previews->preview($tenant, $input)]);
    }
}
