<?php

namespace App\Http\Controllers;

use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\TenantForm;
use App\Models\TenantSite;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsiteProductCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebsiteCommerceController extends Controller
{
    public function services(Request $request, ManagedWebsiteService $websites): View
    {
        $tenant = $this->tenant($request);
        $site = $this->site($tenant);
        $products = WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->where('product_type', 'quote')->with('variants')->latest()->paginate(25);

        return view('managed-website.services', compact('tenant', 'site', 'products') + ['isEditorEnabled' => $websites->editorEnabledFor($tenant)]);
    }

    public function storeService(Request $request, ManagedWebsiteService $websites, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless($websites->editorEnabledFor($tenant), 423, 'Website editing is not enabled for this workspace yet.');
        $commerce->saveProduct($this->site($tenant), $this->serviceData($request));

        return back()->with('status', 'Quote service saved.');
    }

    public function updateService(Request $request, WebsiteProduct $product, ManagedWebsiteService $websites, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $product->tenant_id === (int) $tenant->id && $product->product_type === 'quote', 404);
        abort_unless($websites->editorEnabledFor($tenant), 423, 'Website editing is not enabled for this workspace yet.');
        $commerce->saveProduct($this->site($tenant), $this->serviceData($request) + ['id' => $product->id]);

        return back()->with('status', 'Quote service updated.');
    }

    public function products(Request $request, ManagedWebsiteService $websites): View
    {
        $tenant = $this->tenant($request);
        $site = $this->site($tenant);
        $products = WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->with('variants')->latest()->paginate(25);

        return view('managed-website.commerce.index', compact('tenant', 'site', 'products') + ['screen' => 'products', 'isEditorEnabled' => $websites->editorEnabledFor($tenant)]);
    }

    public function storeProduct(Request $request, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $site = $this->site($tenant);
        $this->requireCommerce($tenant, $commerce);
        $commerce->saveProduct($site, $this->productData($request));

        return back()->with('status', 'Website product saved.');
    }

    public function updateProduct(Request $request, WebsiteProduct $product, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $product->tenant_id === (int) $tenant->id, 404);
        $this->requireCommerce($tenant, $commerce);
        $commerce->saveProduct($this->site($tenant), $this->productData($request) + ['id' => $product->id]);

        return back()->with('status', 'Website product updated.');
    }

    public function archiveProduct(Request $request, WebsiteProduct $product, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireCommerce($tenant, $commerce);
        $commerce->archiveProduct($this->site($tenant), $product);

        return back()->with('status', 'Product archived. Existing order history was preserved.');
    }

    public function exportProducts(Request $request, WebsiteProductCsvService $csv): StreamedResponse
    {
        $tenant = $this->tenant($request);

        return $csv->export($this->site($tenant));
    }

    public function importProducts(Request $request, WebsiteCommerceService $commerce, WebsiteProductCsvService $csv): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireCommerce($tenant, $commerce);
        $data = $request->validate(['catalog' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $result = $csv->import($this->site($tenant), $data['catalog']);

        return back()->with('status', "Catalog imported: {$result['created']} created, {$result['updated']} updated.");
    }

    public function customers(Request $request, ManagedWebsiteService $websites): View
    {
        $tenant = $this->tenant($request);
        $customers = WebsiteCustomer::query()->forTenant($tenant)->latest()->paginate(25);

        return view('managed-website.commerce.index', compact('tenant', 'customers') + ['site' => $this->site($tenant), 'screen' => 'customers', 'isEditorEnabled' => $websites->editorEnabledFor($tenant)]);
    }

    public function storeCustomer(Request $request, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireCommerce($tenant, $commerce);
        WebsiteCustomer::query()->create($this->customerData($request) + ['tenant_id' => $tenant->id, 'status' => 'active']);

        return back()->with('status', 'Website customer saved.');
    }

    public function updateCustomer(Request $request, WebsiteCustomer $customer, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $customer->tenant_id === (int) $tenant->id, 404);
        $this->requireCommerce($tenant, $commerce);
        $customer->update($this->customerData($request));

        return back()->with('status', 'Website customer updated.');
    }

    public function orders(Request $request, ManagedWebsiteService $websites): View
    {
        $tenant = $this->tenant($request);
        $orders = WebsiteOrder::query()->forTenant($tenant)->with('lines')->latest()->paginate(25);

        return view('managed-website.commerce.index', compact('tenant', 'orders') + ['site' => $this->site($tenant), 'screen' => 'orders', 'isEditorEnabled' => $websites->editorEnabledFor($tenant)]);
    }

    public function fulfill(Request $request, WebsiteOrder $order, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $order->tenant_id === (int) $tenant->id, 404);
        $this->requireCommerce($tenant, $commerce);
        $commerce->fulfill($order, $request->user(), (string) $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note']);

        return back()->with('status', 'Order marked fulfilled.');
    }

    public function shop(Request $request, ManagedWebsiteService $websites): View
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $products = WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->where('status', 'active')
            ->when(data_get($site->settings, 'domain_choice') === 'everbranch_subdomain', fn ($query) => $query->where('product_type', 'quote'))
            ->with('variants')->get();

        return view('managed-website.shop', compact('tenant', 'site', 'products'));
    }

    public function showProduct(Request $request, string $handle, ManagedWebsiteService $websites): View
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $product = WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->where('handle', $handle)->where('status', 'active')
            ->when(data_get($site->settings, 'domain_choice') === 'everbranch_subdomain', fn ($query) => $query->where('product_type', 'quote'))
            ->with('variants')->firstOrFail();

        return view('managed-website.product', compact('tenant', 'site', 'product'));
    }

    public function cart(Request $request, ManagedWebsiteService $websites, WebsiteCommerceService $commerce): View
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $this->requireCommerce($tenant, $commerce);
        $cart = $commerce->cartFor($site, $request->session()->get($this->cartSessionKey($site)));
        $request->session()->put($this->cartSessionKey($site), $cart->token);

        return view('managed-website.cart', compact('site', 'cart'));
    }

    public function requestQuote(Request $request, string $handle, ManagedWebsiteService $websites): RedirectResponse
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $product = WebsiteProduct::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->where('handle', $handle)->where('product_type', 'quote')->where('status', 'active')->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'], 'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'max:80'], 'service_address' => ['required', 'string', 'max:500'], 'service_needed' => ['required', 'string', 'max:500'], 'message' => ['required', 'string', 'max:4000'], 'website' => ['nullable', 'max:0'],
        ]);
        $form = TenantForm::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'managed-website-quote'],
            ['name' => 'Website quote request', 'status' => 'active', 'channel' => 'website', 'schema' => ['name', 'email', 'phone', 'service_address', 'service_needed', 'message'], 'settings' => ['managed_website_quote' => true]]
        );
        FormSubmission::query()->create([
            'tenant_id' => $tenant->id, 'tenant_form_id' => $form->id, 'status' => 'submitted', 'source' => 'managed_website_quote',
            'source_key' => 'managed-website-quote-'.Str::uuid(), 'submitted_at' => now(), 'submitter_name' => $data['name'], 'submitter_email' => $data['email'], 'submitter_phone' => $data['phone'] ?? null,
            'payload' => ['service_address' => $data['service_address'], 'service_needed' => $data['service_needed'], 'message' => $data['message']], 'normalized_payload' => ['website_product_id' => $product->id, 'tenant_site_id' => $site->id],
        ]);

        return back()->with('quote_status', 'Thanks — your quote request was received.');
    }

    public function addCartItem(Request $request, WebsiteProductVariant $variant, ManagedWebsiteService $websites, WebsiteCommerceService $commerce): RedirectResponse
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $this->requireCommerce($tenant, $commerce);
        abort_unless((int) $variant->tenant_id === (int) $tenant->id && $variant->product?->tenant_site_id === $site->id, 404);
        $cart = $commerce->cartFor($site, $request->session()->get($this->cartSessionKey($site)));
        $quantity = (int) ($request->validate(['quantity' => ['nullable', 'integer', 'min:1', 'max:20']])['quantity'] ?? 1);
        $cart = $commerce->addToCart($cart, $variant->load('product'), $quantity);
        $request->session()->put($this->cartSessionKey($site), $cart->token);

        return redirect()->route('managed-website.store.cart')->with('cart_status', 'Added to cart.');
    }

    public function checkout(Request $request, ManagedWebsiteService $websites, WebsiteCommerceService $commerce): RedirectResponse
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $this->requireCommerce($tenant, $commerce);
        $cart = $commerce->cartFor($site, $request->session()->get($this->cartSessionKey($site)));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'], 'email' => ['required', 'email:rfc,dns', 'max:190'], 'phone' => ['nullable', 'string', 'max:80'],
            'fulfillment_method' => ['required', 'in:pickup,local_delivery'], 'preferred_at' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $commerce->beginCheckout($cart, $data + ['first_name' => Str::before($data['name'], ' '), 'last_name' => Str::after($data['name'], ' ')], route('managed-website.store.success'), route('managed-website.store.cart'));

        return redirect()->away($result['url']);
    }

    public function success(Request $request, ManagedWebsiteService $websites): View
    {
        [$tenant, $site] = $this->publicSite($request, $websites);
        $order = WebsiteOrder::query()->forTenant($tenant)->where('tenant_site_id', $site->id)->where('number', (string) $request->query('order'))->where('lookup_token', (string) $request->query('token'))->with('lines')->firstOrFail();

        return view('managed-website.order', compact('site', 'order'));
    }

    public function webhook(Request $request, WebsiteCommerceService $commerce): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');
        abort_unless($this->validSignature($payload, $signature), 400, 'Invalid webhook signature.');
        $commerce->processStripeEvent((array) json_decode($payload, true, 512, JSON_THROW_ON_ERROR));

        return response()->json(['received' => true]);
    }

    private function productData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'], 'product_type' => ['required', 'in:physical,service,quote'], 'description' => ['nullable', 'string', 'max:8000'],
            'status' => ['required', 'in:draft,active,archived'], 'price' => ['required', 'numeric', 'min:0', 'max:1000000'], 'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'variant_title' => ['nullable', 'string', 'max:190'], 'sku' => ['nullable', 'string', 'max:120'], 'track_inventory' => ['nullable', 'boolean'], 'inventory_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'], 'is_available' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'service_details' => ['nullable', 'array'], 'seo_title' => ['nullable', 'string', 'max:190'], 'seo_description' => ['nullable', 'string', 'max:320'],
        ]);

        $data['media'] = filled($data['image_url'] ?? null) ? [$data['image_url']] : [];

        return $data;
    }

    /** @return array<string,mixed> */
    private function serviceData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'], 'description' => ['nullable', 'string', 'max:8000'], 'status' => ['required', 'in:draft,active'],
        ]);

        return $data + ['product_type' => 'quote', 'price' => '0', 'track_inventory' => false, 'is_available' => true];
    }

    /** @return array<string,string> */
    private function customerData(Request $request): array
    {
        return $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc,dns', 'max:190'],
            'phone' => ['nullable', 'string', 'max:80'],
        ]);
    }

    /** @return array{Tenant,TenantSite} */
    private function publicSite(Request $request, ManagedWebsiteService $websites): array
    {
        $tenant = $request->attributes->get('host_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $payload = $websites->publicPage($tenant, '');
        abort_unless($payload !== null, 404);
        abort_unless($websites->publicHostAllowed($payload['site'], $request->getHost()), 404);

        return [$tenant, $payload['site']];
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    private function site(Tenant $tenant): TenantSite
    {
        return TenantSite::query()->forTenant($tenant)->firstOrFail();
    }

    private function requireCommerce(Tenant $tenant, WebsiteCommerceService $commerce): void
    {
        abort_unless($commerce->enabledFor($tenant), 423, 'Website Commerce is not enabled for this workspace yet.');
    }

    private function cartSessionKey(TenantSite $site): string
    {
        return 'website_cart_'.$site->id;
    }

    private function validSignature(string $payload, string $header): bool
    {
        $secret = trim((string) config('managed_website.stripe_webhook_secret'));
        if ($secret === '' || ! preg_match('/t=(\d+),v1=([^,]+)/', $header, $matches) || abs(now()->timestamp - (int) $matches[1]) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $matches[1].'.'.$payload, $secret), $matches[2]);
    }
}
