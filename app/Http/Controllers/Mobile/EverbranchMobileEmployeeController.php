<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantEmployeeInvitation;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Tenancy\TenantEmployeeInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileEmployeeController extends Controller
{
    public function index(Request $request, FieldServiceAccessService $access): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->manage($access, $request, $tenant);

        return response()->json([
            'members' => $tenant->users()->orderBy('name')->get()->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->pivot->role ?: 'member', 'active' => (bool) $user->pivot->membership_active])->values(),
            'invitations' => TenantEmployeeInvitation::query()->forTenantId((int) $tenant->id)->latest()->limit(100)->get()->map(fn (TenantEmployeeInvitation $invite): array => $this->invitePayload($invite))->values(),
        ]);
    }

    public function invite(Request $request, FieldServiceAccessService $access, TenantEmployeeInvitationService $invitations): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->manage($access, $request, $tenant);
        $validated = $request->validate(['phone' => ['nullable', 'string', 'max:40', 'required_without:email'], 'email' => ['nullable', 'email', 'max:255', 'required_without:phone'], 'role' => ['nullable', 'in:member,manager']]);
        $result = $invitations->create($tenant, $this->user($request), $validated['phone'] ?? null, $validated['email'] ?? null, $validated['role'] ?? 'member');

        return response()->json(['ok' => true, 'invitation' => $this->invitePayload($result['invitation']), 'invite_url' => $result['invite_url']], 201);
    }

    public function resend(Request $request, string $tenant, TenantEmployeeInvitation $invitation, FieldServiceAccessService $access, TenantEmployeeInvitationService $invitations): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $this->manage($access, $request, $tenantModel);
        $result = $invitations->resend($tenantModel, $invitation);

        return response()->json(['ok' => true, 'invitation' => $this->invitePayload($result['invitation']), 'invite_url' => $result['invite_url']]);
    }

    public function revoke(Request $request, string $tenant, TenantEmployeeInvitation $invitation, FieldServiceAccessService $access, TenantEmployeeInvitationService $invitations): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $this->manage($access, $request, $tenantModel);
        $invitations->revoke($tenantModel, $invitation);

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, string $tenant, User $employee, FieldServiceAccessService $access): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $actor = $this->user($request);
        $this->manage($access, $request, $tenantModel);
        abort_unless($tenantModel->users()->whereKey((int) $employee->id)->exists(), 404);
        $validated = $request->validate(['role' => ['sometimes', 'in:member,manager,admin'], 'active' => ['sometimes', 'boolean']]);
        abort_if((int) $actor->id === (int) $employee->id && (($validated['active'] ?? true) === false), 422, 'You cannot deactivate your own workspace membership.');
        $changes = ['updated_at' => now()];
        if (array_key_exists('role', $validated)) {
            $changes['role'] = $validated['role'];
        }
        if (array_key_exists('active', $validated)) {
            $changes['membership_active'] = $validated['active'];
        }
        $tenantModel->users()->updateExistingPivot((int) $employee->id, $changes);

        return response()->json(['ok' => true]);
    }

    public function accept(Request $request, TenantEmployeeInvitationService $invitations): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $tenant = $invitations->accept($this->user($request), $validated['token']);

        return response()->json(['ok' => true, 'workspace' => ['id' => (int) $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug]]);
    }

    /** @return array<string,mixed> */
    protected function invitePayload(TenantEmployeeInvitation $invite): array
    {
        return ['id' => (int) $invite->id, 'phone' => $invite->phone, 'email' => $invite->email, 'role' => $invite->role, 'status' => $invite->expires_at->isPast() && $invite->status === 'pending' ? 'expired' : $invite->status, 'delivery_status' => $invite->delivery_status, 'delivery_error' => $invite->delivery_error, 'expires_at' => $invite->expires_at?->toIso8601String()];
    }

    protected function manage(FieldServiceAccessService $access, Request $request, Tenant $tenant): void
    {
        abort_unless($access->canManageJobs($this->user($request), $tenant), 403);
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        return $user;
    }
}
