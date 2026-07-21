<?php

namespace App\Livewire\Admin\Users;

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use App\Services\Onboarding\CustomerAccessApprovalService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class UsersIndex extends Component
{
    use WithPagination;

    public ?int $tenantId = null;

    public string $search = '';

    public string $sort = 'name';

    public string $dir = 'asc';

    public int $perPage = 25;

    public bool $showInvite = false;

    public array $invite = [
        'name' => '',
        'email' => '',
        'role' => 'member',
    ];

    protected $queryString = [
        'search' => ['except' => ''],
        'sort' => ['except' => 'name'],
        'dir' => ['except' => 'asc'],
        'perPage' => ['except' => 25],
    ];

    public function mount(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $candidateIds = [
            app(TenantContext::class)->id(),
            request()->attributes->get('current_tenant_id'),
            request()->attributes->get('host_tenant_id'),
            request()->hasSession() ? request()->session()->get('tenant_id') : null,
        ];

        foreach ($candidateIds as $candidateId) {
            if (! is_numeric($candidateId) || (int) $candidateId <= 0) {
                continue;
            }

            if ($actor->tenants()->whereKey((int) $candidateId)->exists()) {
                $this->tenantId = (int) $candidateId;
                break;
            }
        }

        if ($this->tenantId === null) {
            $this->tenantId = $actor->tenants()->orderBy('tenants.name')->value('tenants.id');
        }

        abort_unless($this->tenantId !== null, 403, 'A workspace is required to manage team access.');
        app(TenantContext::class)->set($this->tenantId);
        $this->assertTenantAdmin();
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setSort(string $field): void
    {
        if (! in_array($field, ['name', 'email'], true)) {
            return;
        }

        if ($this->sort === $field) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->dir = 'asc';
        }
    }

    public function toggleInvite(): void
    {
        $this->showInvite = ! $this->showInvite;
        $this->resetValidation();
    }

    public function inviteMember(): void
    {
        $this->assertTenantAdmin();
        $tenant = $this->currentTenant();

        $data = validator($this->invite, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in($this->assignableRoles())],
        ])->validate();

        $email = strtolower(trim((string) $data['email']));
        $user = User::query()->where('email', $email)->first();
        $wasCreated = $user === null;
        $wasInactive = $user !== null && ! (bool) $user->is_active;

        if ($user === null) {
            $user = User::query()->create([
                'name' => trim((string) $data['name']),
                'email' => $email,
                'password' => Hash::make(Str::password(32)),
                'role' => 'member',
                'is_active' => true,
                'requested_via' => 'workspace_invite',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);
        } elseif ($user->tenants()->whereKey((int) $tenant->id)->exists()) {
            $this->addError('invite.email', 'This person already has access to this workspace.');

            return;
        } else {
            $user->forceFill([
                'name' => trim((string) $data['name']),
                'is_active' => true,
                'approved_at' => $user->approved_at ?? now(),
                'approved_by' => $user->approved_by ?? auth()->id(),
            ])->save();
        }

        $user->tenants()->syncWithoutDetaching([
            (int) $tenant->id => ['role' => (string) $data['role']],
        ]);

        $activationSent = false;
        if ($wasCreated || $wasInactive) {
            try {
                Notification::send($user, new ApprovalPasswordSetupNotification(
                    $user,
                    app(TenantHostBuilder::class)->hostForSlug((string) $tenant->slug)
                ));
                $activationSent = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->invite = ['name' => '', 'email' => '', 'role' => 'member'];
        $this->showInvite = false;
        $this->dispatch('toast', [
            'message' => ($wasCreated || $wasInactive)
                ? ($activationSent ? 'Team member added and activation email sent.' : 'Team member added. The activation email could not be sent.')
                : 'Existing Everbranch user added to this workspace.',
            'style' => ($wasCreated || $wasInactive) && ! $activationSent ? 'warning' : 'success',
        ]);
    }

    public function approveRequest(int $requestId): void
    {
        $this->assertTenantAdmin();
        $request = $this->platformRequestForTenant($requestId, 'pending');

        try {
            $approved = app(CustomerAccessApprovalService::class)->approve(
                (int) $request->id,
                (int) auth()->id()
            );

            if ($approved->user_id) {
                $this->currentTenant()->users()->syncWithoutDetaching([
                    (int) $approved->user_id => ['role' => 'member'],
                ]);
            }

            $this->dispatch('toast', ['message' => 'Access approved. The person can now use this workspace.', 'style' => 'success']);
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', ['message' => 'Approval failed. Please try again.', 'style' => 'warning']);
        }
    }

    public function rejectRequest(int $requestId): void
    {
        $this->assertTenantAdmin();
        $request = $this->platformRequestForTenant($requestId, 'pending');

        try {
            app(CustomerAccessApprovalService::class)->reject(
                (int) $request->id,
                (int) auth()->id()
            );

            $this->dispatch('toast', ['message' => 'Access request rejected and removed from the queue.', 'style' => 'success']);
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', ['message' => 'Rejection failed. Please try again.', 'style' => 'warning']);
        }
    }

    public function updateMemberRole(int $userId, string $role): void
    {
        $this->assertTenantAdmin();
        validator(['role' => $role], ['role' => ['required', Rule::in($this->assignableRoles())]])->validate();

        $tenant = $this->currentTenant();
        $member = $this->tenantMember($userId);
        $currentRole = strtolower(trim((string) $member->pivot->role));

        if (in_array($currentRole, ['owner', 'admin'], true)
            && ! in_array($role, ['owner', 'admin'], true)
            && $this->administratorCount() <= 1) {
            $this->dispatch('toast', ['message' => 'Assign another administrator before changing the last administrator.', 'style' => 'warning']);

            return;
        }

        $tenant->users()->updateExistingPivot($userId, ['role' => $role]);
        $this->dispatch('toast', ['message' => 'Workspace role updated.', 'style' => 'success']);
    }

    public function removeAccess(int $userId): void
    {
        $this->assertTenantAdmin();
        $member = $this->tenantMember($userId);

        if ((int) auth()->id() === $userId) {
            $this->dispatch('toast', ['message' => 'You cannot remove your own workspace access.', 'style' => 'warning']);

            return;
        }

        $role = strtolower(trim((string) $member->pivot->role));
        if (in_array($role, ['owner', 'admin'], true) && $this->administratorCount() <= 1) {
            $this->dispatch('toast', ['message' => 'The workspace must keep at least one administrator.', 'style' => 'warning']);

            return;
        }

        $this->currentTenant()->users()->detach($userId);
        $this->dispatch('toast', ['message' => 'Workspace access removed. The user account was not deleted.', 'style' => 'success']);
    }

    public function render()
    {
        $tenant = $this->currentTenant();
        $pendingRequests = collect();

        if (Schema::hasTable('customer_access_requests')) {
            $pendingRequests = $this->platformRequestsForTenant()
                ->where('status', 'pending')
                ->with('user:id,name,email,is_active')
                ->orderBy('created_at')
                ->limit(50)
                ->get();
        }

        $members = User::query()
            ->whereHas('tenants', fn (Builder $query): Builder => $query->where('tenants.id', (int) $tenant->id))
            ->with(['tenants' => fn ($query) => $query->where('tenants.id', (int) $tenant->id)])
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $search): void {
                    $search->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy($this->sort, $this->dir)
            ->paginate($this->perPage);

        return view('livewire.admin.users.index', [
            'tenant' => $tenant,
            'members' => $members,
            'pendingRequests' => $pendingRequests,
            'memberCount' => $tenant->users()->count(),
            'administratorCount' => $this->administratorCount(),
            'assignableRoles' => $this->assignableRoles(),
        ])->layout('layouts.app');
    }

    protected function currentTenant(): Tenant
    {
        abort_unless($this->tenantId !== null, 403);

        $tenant = Tenant::query()->find($this->tenantId);
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    protected function assertTenantAdmin(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $this->tenantId !== null, 403);

        $membership = $actor->tenants()->whereKey($this->tenantId)->first();
        abort_unless($membership instanceof Tenant, 403);

        $role = strtolower(trim((string) ($membership->pivot->role ?? '')));
        abort_unless($actor->isAdmin() || in_array($role, ['owner', 'admin'], true), 403);
    }

    protected function tenantMember(int $userId): User
    {
        $member = $this->currentTenant()->users()->whereKey($userId)->first();
        abort_unless($member instanceof User, 404);

        return $member;
    }

    protected function administratorCount(): int
    {
        return $this->currentTenant()->users()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->count();
    }

    protected function platformRequestForTenant(int $requestId, ?string $status = null): CustomerAccessRequest
    {
        abort_unless(Schema::hasTable('customer_access_requests'), 404);

        $request = $this->platformRequestsForTenant()
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->whereKey($requestId)
            ->first();

        abort_unless($request instanceof CustomerAccessRequest, 404);

        return $request;
    }

    protected function platformRequestsForTenant(): Builder
    {
        $tenant = $this->currentTenant();

        return CustomerAccessRequest::query()
            ->platformAccess()
            ->where(function (Builder $query) use ($tenant): void {
                $query->where('tenant_id', (int) $tenant->id)
                    ->orWhere(function (Builder $legacy) use ($tenant): void {
                        $legacy->whereNull('tenant_id')
                            ->where('requested_tenant_slug', (string) $tenant->slug);
                    });
            });
    }

    /** @return array<int,string> */
    protected function assignableRoles(): array
    {
        return ['admin', 'manager', 'member'];
    }
}
