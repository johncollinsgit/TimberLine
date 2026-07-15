<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Onboarding\CustomerAccessApprovalService;
use App\Support\Wholesale\WholesaleApplicationInboxUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class WholesaleApplicationInboxController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = strtolower(trim((string) $request->query('status', 'pending')));
        $tenantSlug = $this->wholesaleTenantSlug();
        $tenant = $this->wholesaleTenant();
        $tenantAliases = $this->wholesaleApplicationTenantAliases();

        $applications = CustomerAccessRequest::query()
            ->with([
                'formSubmission.form:id,name,slug',
                'user:id,name,email,is_active',
                'tenant:id,name,slug',
            ])
            ->where(function ($query) use ($tenant, $tenantAliases): void {
                if ($tenant instanceof Tenant) {
                    $query->where('tenant_id', (int) $tenant->id)
                        ->orWhere(function ($legacy) use ($tenantAliases): void {
                            $legacy->whereNull('tenant_id')
                                ->whereIn('requested_tenant_slug', $tenantAliases);
                        });

                    return;
                }

                $query->whereNull('tenant_id')->whereIn('requested_tenant_slug', $tenantAliases);
            })
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), function ($query) use ($status): void {
                $query->where('status', $status);
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('company', 'like', '%'.$search.'%');
                });
            })
            ->orderByRaw("case when status = 'pending' then 0 when status = 'approved' then 1 when status = 'rejected' then 2 else 3 end")
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $summary = [
            'pending' => $this->countByStatus('pending', $tenant),
            'approved' => $this->countByStatus('approved', $tenant),
            'rejected' => $this->countByStatus('rejected', $tenant),
        ];

        return view('admin.wholesale-applications.index', [
            'applications' => $applications,
            'search' => $search,
            'status' => $status,
            'summary' => $summary,
            'tenant' => $tenant,
            'tenantSlug' => $tenantSlug,
            'inboxUrl' => app(WholesaleApplicationInboxUrl::class)->inboxUrl($tenantSlug),
        ]);
    }

    public function show(CustomerAccessRequest $accessRequest): View
    {
        $this->assertWholesaleRequest($accessRequest);

        $accessRequest->load([
            'formSubmission.form.template',
            'user:id,name,email,is_active,approved_at',
            'tenant:id,name,slug',
        ]);

        return view('admin.wholesale-applications.show', [
            'accessRequest' => $accessRequest,
            'detailRows' => $this->detailRows($accessRequest),
            'canManageApproval' => $this->canManageApproval(Auth::user()),
            'inboxUrl' => app(WholesaleApplicationInboxUrl::class)->inboxUrl($this->wholesaleTenantSlug()),
            'applicationUrl' => app(WholesaleApplicationInboxUrl::class)->detailUrl($accessRequest),
        ]);
    }

    public function approve(
        Request $request,
        CustomerAccessRequest $accessRequest,
        CustomerAccessApprovalService $approvalService
    ): RedirectResponse {
        $this->assertWholesaleRequest($accessRequest);

        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $approvalService->approve(
                (int) $accessRequest->id,
                (int) $request->user()->id,
                $validated['decision_note'] ?? null
            );

            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('status', 'Wholesale application approved and activation email sent.');
        } catch (\DomainException $e) {
            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('error', (string) $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('error', 'Approval failed. Check logs if this keeps happening.');
        }
    }

    public function reject(
        Request $request,
        CustomerAccessRequest $accessRequest,
        CustomerAccessApprovalService $approvalService
    ): RedirectResponse {
        $this->assertWholesaleRequest($accessRequest);

        $validated = $request->validate([
            'rejection_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $approvalService->reject(
                (int) $accessRequest->id,
                (int) $request->user()->id,
                $validated['rejection_note'] ?? null
            );

            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('status', 'Wholesale application rejected.');
        } catch (\DomainException $e) {
            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('error', (string) $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('error', 'Rejection failed. Check logs if this keeps happening.');
        }
    }

    public function resendActivation(
        Request $request,
        CustomerAccessRequest $accessRequest,
        CustomerAccessApprovalService $approvalService
    ): RedirectResponse {
        $this->assertWholesaleRequest($accessRequest);

        $validated = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $approvalService->resendActivation(
                (int) $accessRequest->id,
                (int) $request->user()->id,
                $validated['decision_note'] ?? null
            );

            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('status', 'Activation email resend processed.');
        } catch (\DomainException $e) {
            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('error', (string) $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.wholesale.applications.show', $accessRequest)
                ->with('error', 'Activation resend failed. Check logs if this keeps happening.');
        }
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    protected function detailRows(CustomerAccessRequest $accessRequest): array
    {
        $payload = (array) optional($accessRequest->formSubmission)->payload;
        $metadata = (array) ($accessRequest->metadata ?? []);

        $read = function (string ...$keys) use ($payload, $metadata, $accessRequest): string {
            foreach ($keys as $key) {
                $value = match ($key) {
                    'name' => $accessRequest->name,
                    'email' => $accessRequest->email,
                    'company' => $accessRequest->company,
                    'message' => $accessRequest->message,
                    default => $payload[$key] ?? $metadata[$key] ?? null,
                };

                $string = trim((string) $value);
                if ($string !== '') {
                    return $string;
                }
            }

            return '—';
        };

        return [
            ['label' => 'Name', 'value' => $read('name')],
            ['label' => 'Email', 'value' => $read('email')],
            ['label' => 'Phone', 'value' => $read('phone')],
            ['label' => 'Company', 'value' => $read('company')],
            ['label' => 'Store type', 'value' => $read('store_type', 'business_type')],
            ['label' => 'Website', 'value' => $read('website')],
            ['label' => 'Position', 'value' => $read('position')],
            ['label' => 'Referral source', 'value' => $read('referral')],
            ['label' => 'Address', 'value' => $read('address')],
            ['label' => 'Address line 2', 'value' => $read('address2')],
            ['label' => 'City', 'value' => $read('city')],
            ['label' => 'State', 'value' => $read('state')],
            ['label' => 'Postal / ZIP', 'value' => $read('zip')],
            ['label' => 'Country', 'value' => $read('country')],
            ['label' => 'Retail license / resale #', 'value' => $read('retail_license_number')],
            ['label' => 'Contact preference', 'value' => $read('contact_preference')],
            ['label' => 'Current suppliers', 'value' => $read('current_suppliers')],
            ['label' => 'Business info', 'value' => $read('business_info', 'message')],
            ['label' => 'Agreement accepted', 'value' => $this->agreementValue($payload, $metadata)],
        ];
    }

    protected function agreementValue(array $payload, array $metadata): string
    {
        $value = $payload['agreement'] ?? $metadata['agreement'] ?? null;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    protected function countByStatus(string $status, ?Tenant $tenant): int
    {
        $tenantAliases = $this->wholesaleApplicationTenantAliases();

        return CustomerAccessRequest::query()
            ->where('status', $status)
            ->where(function ($query) use ($tenant, $tenantAliases): void {
                if ($tenant instanceof Tenant) {
                    $query->where('tenant_id', (int) $tenant->id)
                        ->orWhere(function ($legacy) use ($tenantAliases): void {
                            $legacy->whereNull('tenant_id')
                                ->whereIn('requested_tenant_slug', $tenantAliases);
                        });

                    return;
                }

                $query->whereNull('tenant_id')->whereIn('requested_tenant_slug', $tenantAliases);
            })
            ->count();
    }

    protected function assertWholesaleRequest(CustomerAccessRequest $accessRequest): void
    {
        $tenant = $this->wholesaleTenant();

        abort_unless(
            ($tenant instanceof Tenant && (int) $accessRequest->tenant_id === (int) $tenant->id)
                || ($accessRequest->tenant_id === null && in_array(
                    (string) $accessRequest->requested_tenant_slug,
                    $this->wholesaleApplicationTenantAliases(),
                    true
                )),
            404
        );
    }

    protected function canManageApproval(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $allowedRoles = array_values(array_filter(
            array_map(static fn (mixed $role): string => strtolower(trim((string) $role)), (array) config('tenancy.landlord.operator_roles', ['admin'])),
            static fn (string $role): bool => $role !== ''
        ));

        return in_array(strtolower(trim((string) $user->role)), $allowedRoles, true);
    }

    protected function wholesaleTenantSlug(): string
    {
        return (string) config(
            'product_surfaces.access_request.wholesale_storefront_tenant_slug',
            'modern-forestry'
        );
    }

    protected function wholesaleTenant(): ?Tenant
    {
        return Tenant::query()->where('slug', $this->wholesaleTenantSlug())->first();
    }

    /** @return array<int,string> */
    protected function wholesaleApplicationTenantAliases(): array
    {
        return array_values(array_unique([
            $this->wholesaleTenantSlug(),
            'modern-forestry-wholesale',
        ]));
    }
}
