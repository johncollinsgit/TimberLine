<?php

namespace App\Http\Controllers;

use App\Models\CustomerServiceMembership;
use App\Models\MarketingProfile;
use App\Models\ServiceMembershipSetting;
use App\Models\ServicePlanOffer;
use App\Models\ServicePlanTemplate;
use App\Models\ServicePlanVersion;
use App\Models\Tenant;
use App\Models\WorkspaceAsset;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\ServiceMembershipService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceMembershipController extends Controller
{
    public function __construct(protected FieldServiceAccessService $access, protected ServiceMembershipService $memberships) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);

        return view('service-memberships.index', [
            'tenant' => $tenant,
            'settings' => ServiceMembershipSetting::query()->firstOrCreate(['tenant_id' => $tenant->id]),
            'templates' => ServicePlanTemplate::query()->forTenantId($tenant->id)->with(['versions' => fn ($query) => $query->latest('version')->limit(1)])->orderBy('sort_order')->get(),
            'offers' => ServicePlanOffer::query()->forTenantId($tenant->id)->with(['customer', 'version.template'])->latest()->limit(25)->get(),
            'memberships' => CustomerServiceMembership::query()->forTenantId($tenant->id)->with(['customer', 'version.template'])->latest('activated_at')->limit(25)->get(),
            'customers' => MarketingProfile::query()->forTenantId($tenant->id)->whereNull('merged_into_profile_id')->orderBy('last_name')->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name', 'email']),
            'assets' => WorkspaceAsset::query()->forTenantId($tenant->id)->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/gif', 'image/heic', 'image/heif'])->latest()->limit(100)->get(['id', 'file_name', 'caption']),
            'canManage' => true,
            'canConfigure' => $this->canConfigure($request, $tenant),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeConfigure($request, $tenant);
        $data = $request->validate([
            'terms_contact_email' => ['nullable', 'email', 'max:255'], 'terms_contact_phone' => ['nullable', 'string', 'max:40'],
            'service_area_label' => ['nullable', 'string', 'max:255'], 'terms' => ['nullable', 'string', 'max:10000'],
            'offer_expiry_days' => ['nullable', 'integer', 'min:1', 'max:90'], 'customer_email_copy' => ['nullable', 'string', 'max:3000'],
        ]);
        ServiceMembershipSetting::query()->updateOrCreate(['tenant_id' => $tenant->id], [
            'terms_contact_email' => $data['terms_contact_email'] ?? null, 'terms_contact_phone' => $data['terms_contact_phone'] ?? null,
            'service_area_label' => $data['service_area_label'] ?? null,
            'customer_experience' => ['terms' => $data['terms'] ?? '', 'offer_expiry_days' => (int) ($data['offer_expiry_days'] ?? 14), 'customer_email_copy' => $data['customer_email_copy'] ?? ''],
        ]);

        return back()->with('status', 'Service-plan customer settings saved.');
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeConfigure($request, $tenant);
        $data = $this->validatedPlan($request);
        $template = ServicePlanTemplate::query()->create([
            'tenant_id' => $tenant->id, 'created_by_user_id' => $request->user()->id,
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)), 'name' => $data['name'], 'badge' => $data['badge'] ?? null,
            'description' => $data['description'] ?? null, 'status' => 'draft', 'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        $this->memberships->createVersion($tenant, $template, $request->user(), $this->planSnapshot($data), $this->addons($data), $this->media($data));

        return back()->with('status', 'Plan template published as version 1. Future edits create a new immutable version.');
    }

    public function publishVersion(Request $request, ServicePlanTemplate $template): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeConfigure($request, $tenant);
        abort_unless((int) $template->tenant_id === (int) $tenant->id, 404);
        $data = $this->validatedPlan($request);
        $template->forceFill(['name' => $data['name'], 'badge' => $data['badge'] ?? null, 'description' => $data['description'] ?? null, 'sort_order' => (int) ($data['sort_order'] ?? 0)])->save();
        $this->memberships->createVersion($tenant, $template, $request->user(), $this->planSnapshot($data), $this->addons($data), $this->media($data));

        return back()->with('status', 'New immutable plan version published. Existing customer offers stay unchanged.');
    }

    public function storeOffer(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        $data = $request->validate(['marketing_profile_id' => ['required', 'integer'], 'service_plan_version_id' => ['required', 'integer'], 'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90']]);
        $customer = MarketingProfile::query()->forTenantId($tenant->id)->findOrFail($data['marketing_profile_id']);
        $version = ServicePlanVersion::query()->forTenantId($tenant->id)->findOrFail($data['service_plan_version_id']);
        $created = $this->memberships->createOffer($tenant, $customer, $version, $request->user(), now()->addDays((int) ($data['expires_in_days'] ?? 14)));

        return back()->with('status', 'Customer offer created. Copy this secure link: '.route('service-plan-offers.show', ['token' => $created['token']]));
    }

    public function activateOffer(Request $request, ServicePlanOffer $offer): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        abort_unless((int) $offer->tenant_id === (int) $tenant->id, 404);
        $data = $request->validate(['external_invoice_reference' => ['nullable', 'string', 'max:255'], 'external_invoice_url' => ['nullable', 'url', 'max:2048', 'starts_with:https://']]);
        $this->memberships->activateOffer($offer, $request->user(), $data['external_invoice_reference'] ?? null, $data['external_invoice_url'] ?? null);

        return back()->with('status', 'Membership activated. Everbranch did not collect or store a payment method.');
    }

    public function revokeOffer(Request $request, ServicePlanOffer $offer): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeManage($request, $tenant);
        abort_unless((int) $offer->tenant_id === (int) $tenant->id, 404);
        abort_if($offer->accepted_at !== null, 409, 'Accepted offers are immutable.');
        $offer->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        return back()->with('status', 'Customer offer revoked.');
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function authorizeManage(Request $request, Tenant $tenant): void
    {
        abort_unless($this->access->canManageJobs($request->user(), $tenant), 403);
    }

    protected function canConfigure(Request $request, Tenant $tenant): bool
    {
        return in_array($this->access->role($request->user(), $tenant), ['owner', 'tenant_owner', 'admin'], true) || $request->user()->role === 'platform_admin';
    }

    protected function authorizeConfigure(Request $request, Tenant $tenant): void
    {
        abort_unless($this->canConfigure($request, $tenant), 403);
    }

    /** @return array<string,mixed> */
    protected function validatedPlan(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'], 'badge' => ['nullable', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'], 'billing_frequency' => ['required', 'in:monthly,annual'], 'visit_interval_days' => ['required', 'integer', 'min:1', 'max:730'],
            'visit_title' => ['required', 'string', 'max:255'], 'priority' => ['required', 'in:normal,priority'], 'benefits' => ['nullable', 'string', 'max:5000'], 'terms' => ['nullable', 'string', 'max:10000'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'addons' => ['nullable', 'array', 'max:20'], 'addons.*.name' => ['nullable', 'string', 'max:255'], 'addons.*.description' => ['nullable', 'string', 'max:1000'], 'addons.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'addons.*.billing_frequency' => ['nullable', 'in:one_time,monthly,annual'], 'addons.*.max_quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'media' => ['nullable', 'array', 'max:12'], 'media.*.workspace_asset_id' => ['nullable', 'integer'], 'media.*.caption' => ['nullable', 'string', 'max:500'], 'media.*.alt_text' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    protected function planSnapshot(array $data): array
    {
        return ['name' => $data['name'], 'badge' => $data['badge'] ?? null, 'description' => $data['description'] ?? null, 'price' => $data['price'], 'billing_frequency' => $data['billing_frequency'], 'visit_interval_days' => $data['visit_interval_days'], 'visit_title' => $data['visit_title'], 'priority' => $data['priority'], 'benefits' => preg_split('/\r\n|\r|\n/', (string) ($data['benefits'] ?? '')) ?: [], 'terms' => $data['terms'] ?? ''];
    }

    /** @param array<string,mixed> $data @return array<int,array<string,mixed>> */
    protected function addons(array $data): array
    {
        return collect((array) ($data['addons'] ?? []))->filter(fn (array $addon): bool => filled($addon['name'] ?? null))->values()->all();
    }

    /** @param array<string,mixed> $data @return array<int,array<string,mixed>> */
    protected function media(array $data): array
    {
        return collect((array) ($data['media'] ?? []))->filter(fn (array $item): bool => filled($item['workspace_asset_id'] ?? null))->map(fn (array $item): array => $item + ['visibility' => 'customer_offer', 'alt_text' => $item['alt_text'] ?? 'Service plan photo'])->values()->all();
    }
}
