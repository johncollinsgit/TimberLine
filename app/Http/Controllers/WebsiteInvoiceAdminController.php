<?php

namespace App\Http\Controllers;

use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteDiscount;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\WebsiteDiscountService;
use App\Services\ManagedWebsite\WebsiteQuoteOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsiteInvoiceAdminController extends Controller
{
    public function index(Request $request, WebsiteQuoteOrderService $quotes)
    {
        $site = $this->site($request, $quotes);
        $orders = WebsiteOrder::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->whereIn('source', ['staff_quote', 'staff_draft_invoice'])->latest()->paginate(25);

        $summary = app(\App\Services\ManagedWebsite\WebsiteInvoicePaymentService::class)->summary($site);

        return view('managed-website.invoices.index', compact('site', 'orders', 'summary'));
    }

    public function create(Request $request, WebsiteQuoteOrderService $quotes)
    {
        $site = $this->site($request, $quotes);
        $submission = $request->filled('quote_request_id') ? FormSubmission::query()->forTenantId($site->tenant_id)->where('source', 'managed_website_quote')->findOrFail($request->integer('quote_request_id')) : null;
        $order = null;

        return view('managed-website.invoices.edit', ['site' => $site, 'order' => $order, 'submission' => $submission, 'variants' => $this->variants($site), 'requestId' => (string) Str::uuid()]);
    }

    public function store(Request $request, WebsiteQuoteOrderService $quotes)
    {
        $site = $this->site($request, $quotes);
        $order = $quotes->create($site, $this->pricingInput($request), $request->user());

        return redirect()->route('managed-website.invoices.show', $order)->with('status', 'Priced draft saved. Review the final totals before issuing.');
    }

    public function show(Request $request, WebsiteOrder $order, WebsiteQuoteOrderService $quotes)
    {
        $site = $this->site($request, $quotes);
        $this->assertOrder($order, $site);
        $order->load(['lines', 'events', 'payments']);

        $publicUrl = rtrim(app(\App\Services\ManagedWebsite\ManagedWebsiteDomainService::class)->publicUrl($site), '/').'/invoices/'.$order->lookup_token;

        return view('managed-website.invoices.show', compact('site', 'order', 'publicUrl'));
    }

    public function edit(Request $request, WebsiteOrder $order, WebsiteQuoteOrderService $quotes)
    {
        $site = $this->site($request, $quotes);
        $this->assertOrder($order, $site);
        abort_if(data_get($order->service_request, 'invoice.issued_at'), 409, 'Issued terms are frozen. Create a new draft to change them.');

        return view('managed-website.invoices.edit', ['site' => $site, 'order' => $order->load('lines'), 'submission' => null, 'variants' => $this->variants($site), 'requestId' => (string) Str::uuid()]);
    }

    public function update(Request $request, WebsiteOrder $order, WebsiteQuoteOrderService $quotes)
    {
        $site = $this->site($request, $quotes);
        $this->assertOrder($order, $site);
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];
        $quotes->revise($order, $this->pricingInput($request), $request->user(), (int) $version);

        return redirect()->route('managed-website.invoices.show', $order)->with('status', 'Draft revised. The previous version remains in its private history.');
    }

    public function issue(Request $request, WebsiteOrder $order, WebsiteQuoteOrderService $quotes)
    {
        $site = $this->site($request, $quotes);
        $this->assertOrder($order, $site);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $quotes->issue($order, $request->user(), (int) $data['version']);

        return back()->with('status', 'Final terms issued. Share the secure customer link below.');
    }

    public function discounts(Request $request, WebsiteQuoteOrderService $quotes)
    {
        $site = $this->site($request, $quotes);

        return view('managed-website.invoices.discounts', ['site' => $site, 'discounts' => WebsiteDiscount::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->latest()->get(), 'variants' => $this->variants($site)]);
    }

    public function saveDiscount(Request $request, WebsiteQuoteOrderService $quotes, WebsiteDiscountService $discounts)
    {
        $site = $this->site($request, $quotes);
        $data = $request->all();
        $data['value'] = $request->input('type') === 'fixed' ? $this->cents($request->input('value'), 'value') : $request->input('value');
        $data['minimum_subtotal_cents'] = $this->cents($request->input('minimum_subtotal', '0'), 'minimum_subtotal');
        $data['enabled'] = true;
        $discounts->save($site, $data, $request->user());

        return back()->with('status', 'Discount code saved. Apply it to a draft before issuing.');
    }

    public function disableDiscount(Request $request, int $discount, WebsiteQuoteOrderService $quotes, WebsiteDiscountService $discounts)
    {
        $site = $this->site($request, $quotes);
        $discounts->disable($site, $discount, $request->user());

        return back()->with('status', 'Code disabled for new invoices. Issued prices remain unchanged.');
    }

    private function site(Request $request, WebsiteQuoteOrderService $quotes): TenantSite
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $site = TenantSite::query()->forTenantId($tenant->id)->firstOrFail();
        $quotes->assertEditor($site, $request->user());

        return $site;
    }

    private function assertOrder(WebsiteOrder $order, TenantSite $site): void
    {
        abort_unless((int) $order->tenant_id === (int) $site->tenant_id && (int) $order->tenant_site_id === (int) $site->id && in_array($order->source, ['staff_quote', 'staff_draft_invoice'], true), 404);
    }

    private function variants(TenantSite $site)
    {
        return WebsiteProductVariant::query()->forTenantId($site->tenant_id)->where('is_available', true)->whereHas('product', fn ($q) => $q->where('tenant_site_id', $site->id)->where('status', 'active'))->with('product')->get();
    }

    private function pricingInput(Request $request): array
    {
        $data = $request->all();
        $data['lines'] = collect((array) $request->input('lines'))->filter(fn ($line) => is_array($line) && (filled($line['website_product_variant_id'] ?? null) || filled($line['title'] ?? null)))->map(function ($line) {
            if (filled($line['unit_price'] ?? null)) {
                $line['unit_price_cents'] = $this->cents($line['unit_price'], 'lines');
            }

            return $line;
        })->values()->all();
        $data['tax_cents'] = $this->cents($request->input('tax', '0'), 'tax');
        $data['shipping_cents'] = $this->cents($request->input('delivery_charge', '0'), 'delivery_charge');
        if (in_array($request->input('manual_discount_type'), ['fixed', 'percent'], true)) {
            $data['manual_discount'] = ['type' => $request->input('manual_discount_type'), 'value' => $request->input('manual_discount_type') === 'fixed' ? $this->cents($request->input('manual_discount_value'), 'manual_discount_value') : $request->input('manual_discount_value'), 'reason' => $request->input('manual_discount_reason')];
        } else {
            unset($data['manual_discount']);
        }
        if ($request->input('deposit_type') === 'fixed') {
            $data['deposit_value'] = $this->cents($request->input('deposit_value'), 'deposit_value');
        } elseif ($request->input('deposit_type') === 'none') {
            $data['deposit_value'] = null;
        }

        return $data;
    }

    private function cents(mixed $value, string $field): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Enter a dollar amount with at most two decimal places.']);
        }
        [$dollars, $cents] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $dollars * 100) + (int) str_pad($cents, 2, '0');
    }
}
