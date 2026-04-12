<?php

namespace App\Livewire\Admin\Users;

use App\Models\CustomerAccessRequest;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use App\Services\Onboarding\CustomerAccessApprovalService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class UsersIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sort = 'name';
    public string $dir = 'asc';
    public int $perPage = 25;
    public bool $showCreate = false;

    public array $create = [
        'name' => '',
        'email' => '',
        'password' => '',
        'role' => 'admin',
        'is_active' => true,
    ];

    public bool $showEdit = false;
    public ?int $editingId = null;
    public array $edit = [];
    public string $newPassword = '';
    public array $editAccessRequest = [];
    public string $accessDecisionNote = '';
    public string $accessRejectionNote = '';
    public ?int $approvedAccessRequestId = null;

    public bool $showDelete = false;
    public ?int $deletingId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'sort' => ['except' => 'name'],
        'dir' => ['except' => 'asc'],
        'perPage' => ['except' => 25],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setSort(string $field): void
    {
        if ($this->sort === $field) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->dir = 'asc';
        }
    }

    public function openCreate(): void
    {
        $this->showCreate = !$this->showCreate;
    }

    public function create(): void
    {
        $data = validator($this->create, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:admin,manager,pouring,marketing_manager'],
            'is_active' => ['boolean'],
        ])->validate();

        User::query()->create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'requested_via' => 'admin',
            'approval_requested_at' => (bool) ($data['is_active'] ?? true) ? null : now(),
            'approved_at' => (bool) ($data['is_active'] ?? true) ? now() : null,
            'approved_by' => (bool) ($data['is_active'] ?? true) ? auth()->id() : null,
        ]);

        $this->reset('create');
        $this->create['role'] = 'admin';
        $this->create['is_active'] = true;
        $this->dispatch('toast', ['message' => 'User created.', 'style' => 'success']);
    }

    public function openEdit(int $id): void
    {
        \Log::info('Admin Users openEdit', ['id' => $id]);
        $user = User::query()->findOrFail($id);
        $this->editingId = $id;
        $this->edit = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'admin',
            'is_active' => (bool) $user->is_active,
        ];
        $this->editAccessRequest = $this->accessRequestPayload($user);
        $this->accessDecisionNote = (string) ($this->editAccessRequest['decision_note'] ?? '');
        $this->accessRejectionNote = '';
        $this->approvedAccessRequestId = $this->latestApprovedAccessRequest($user)?->id;
        $this->newPassword = '';
        $this->showEdit = true;
    }

    public function save(): void
    {
        if (!$this->editingId) {
            return;
        }

        $user = User::query()->findOrFail($this->editingId);

        $data = validator($this->edit, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $this->editingId],
            'role' => ['required', 'in:admin,manager,pouring,marketing_manager'],
            'is_active' => ['boolean'],
        ])->validate();

        $payload = [
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'role' => $data['role'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if ($payload['is_active']) {
            $payload['approved_at'] = now();
            $payload['approved_by'] = auth()->id();
            if (empty($this->edit['requested_via'] ?? null)) {
                $payload['requested_via'] = 'admin';
            }
        }

        if ($this->newPassword !== '') {
            if (strlen($this->newPassword) < 8) {
                throw ValidationException::withMessages([
                    'newPassword' => 'Password must be at least 8 characters.',
                ]);
            }
            $payload['password'] = Hash::make($this->newPassword);
        }

        $wasInactive = !$user->is_active;

        User::query()->whereKey($this->editingId)->update($payload);

        $this->persistAccessRequestEdits($user);

        $emailSent = false;
        if ($wasInactive && $payload['is_active']) {
            $fresh = User::query()->find($this->editingId);
            if ($fresh) {
                try {
                    $fresh->notify(new ApprovalPasswordSetupNotification($fresh, $this->preferredHostForUser($fresh)));
                    $emailSent = true;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->showEdit = false;
        $this->editingId = null;
        $this->editAccessRequest = [];
        $this->accessDecisionNote = '';
        $this->accessRejectionNote = '';
        $this->approvedAccessRequestId = null;
        $this->dispatch('toast', [
            'message' => ($wasInactive && $payload['is_active'])
                ? ($emailSent ? 'User updated and approval email sent.' : 'User updated. Approval email could not be sent.')
                : 'User updated.',
            'style' => ($wasInactive && $payload['is_active'] && !$emailSent) ? 'warning' : 'success',
        ]);
    }

    public function openDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDelete = true;
    }

    public function destroy(): void
    {
        if (!$this->deletingId) {
            return;
        }
        if (auth()->id() === $this->deletingId) {
            $this->dispatch('toast', ['message' => 'You cannot delete your own account.', 'style' => 'warning']);
            $this->showDelete = false;
            return;
        }

        User::query()->whereKey($this->deletingId)->delete();
        $this->showDelete = false;
        $this->dispatch('toast', ['message' => 'User deleted.', 'style' => 'success']);
    }

    public function approve(int $id): void
    {
        $this->assertApprovalActor();

        $user = User::query()->findOrFail($id);
        $success = false;
        $request = $this->latestPendingAccessRequest($user);

        try {
            if ($request) {
                app(CustomerAccessApprovalService::class)->approve((int) $request->id, (int) auth()->id(), $this->accessDecisionNote);
                $success = true;
            } else {
                $wasInactive = !$user->is_active;
                if ($wasInactive) {
                    $user->forceFill([
                        'is_active' => true,
                        'approved_at' => now(),
                        'approved_by' => auth()->id(),
                    ])->save();

                    $user->notify(new ApprovalPasswordSetupNotification($user, $this->preferredHostForUser($user)));
                    $success = true;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        $this->dispatch('toast', [
            'message' => $success
                ? 'Approval processed.'
                : 'Approval failed. See logs for details.',
            'style' => $success ? 'success' : 'warning',
        ]);
    }

    public function rejectAccessRequest(int $userId): void
    {
        $this->assertApprovalActor();

        $user = User::query()->findOrFail($userId);
        $request = $this->latestPendingAccessRequest($user);
        if (! $request) {
            $this->dispatch('toast', ['message' => 'No pending access request found for this user.', 'style' => 'warning']);

            return;
        }

        try {
            app(CustomerAccessApprovalService::class)->reject((int) $request->id, (int) auth()->id(), $this->accessRejectionNote);
            $this->dispatch('toast', ['message' => 'Access request rejected.', 'style' => 'success']);
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', ['message' => 'Reject failed. See logs for details.', 'style' => 'warning']);
        }
    }

    public function resendAccessActivation(int $userId): void
    {
        $this->assertApprovalActor();

        $user = User::query()->findOrFail($userId);
        $request = $this->latestApprovedAccessRequest($user);
        if (! $request) {
            $this->dispatch('toast', ['message' => 'No approved access request found for this user.', 'style' => 'warning']);

            return;
        }

        try {
            app(CustomerAccessApprovalService::class)->resendActivation((int) $request->id, (int) auth()->id(), $this->accessDecisionNote);
            $this->dispatch('toast', ['message' => 'Activation email resent.', 'style' => 'success']);
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', ['message' => 'Resend failed. See logs for details.', 'style' => 'warning']);
        }
    }

    protected function latestPendingAccessRequest(User $user): ?CustomerAccessRequest
    {
        if (!\Schema::hasTable('customer_access_requests')) {
            return null;
        }

        return CustomerAccessRequest::query()
            ->where('email', strtolower(trim((string) $user->email)))
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();
    }

    protected function accessRequestPayload(User $user): array
    {
        $request = $this->latestPendingAccessRequest($user);
        if (! $request) {
            return [];
        }

        return [
            'id' => (int) $request->id,
            'intent' => (string) $request->intent,
            'company' => (string) ($request->company ?? ''),
            'requested_tenant_slug' => (string) ($request->requested_tenant_slug ?? ''),
            'message' => (string) ($request->message ?? ''),
            'decision_note' => (string) ($request->decision_note ?? ''),
        ];
    }

    protected function persistAccessRequestEdits(User $user): void
    {
        if (!\Schema::hasTable('customer_access_requests')) {
            return;
        }

        $id = $this->editAccessRequest['id'] ?? null;
        if (! is_numeric($id) || (int) $id <= 0) {
            return;
        }

        $payload = [
            'company' => trim((string) ($this->editAccessRequest['company'] ?? '')),
            'requested_tenant_slug' => trim((string) ($this->editAccessRequest['requested_tenant_slug'] ?? '')),
            'message' => trim((string) ($this->editAccessRequest['message'] ?? '')),
        ];

        CustomerAccessRequest::query()
            ->whereKey((int) $id)
            ->where('status', 'pending')
            ->update([
                'company' => $payload['company'] !== '' ? $payload['company'] : null,
                'requested_tenant_slug' => $payload['requested_tenant_slug'] !== '' ? strtolower($payload['requested_tenant_slug']) : null,
                'message' => $payload['message'] !== '' ? $payload['message'] : null,
                'decision_note' => $this->accessDecisionNote !== '' ? $this->accessDecisionNote : null,
            ]);
    }

    protected function preferredHostForUser(User $user): ?string
    {
        $request = $this->latestPendingAccessRequest($user);
        if (! $request) {
            return null;
        }

        $slug = strtolower(trim((string) ($request->requested_tenant_slug ?? '')));
        if ($slug === '') {
            return null;
        }

        return app(\App\Support\Tenancy\TenantHostBuilder::class)->hostForSlug($slug);
    }

    protected function latestApprovedAccessRequest(User $user): ?CustomerAccessRequest
    {
        if (! \Schema::hasTable('customer_access_requests')) {
            return null;
        }

        return CustomerAccessRequest::query()
            ->where('email', strtolower(trim((string) $user->email)))
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->first();
    }

    protected function assertApprovalActor(): void
    {
        $actor = auth()->user();
        abort_unless($actor && ($actor->role ?? 'admin') === 'admin', 403);
    }

    public function render()
    {
        $pendingUsers = User::query()
            ->where('is_active', false)
            ->orderBy('approval_requested_at', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get();

        $pendingAccessRequests = [];
        if (\Schema::hasTable('customer_access_requests') && $pendingUsers->isNotEmpty()) {
            $emails = $pendingUsers
                ->pluck('email')
                ->map(static fn ($value): string => strtolower(trim((string) $value)))
                ->filter()
                ->values()
                ->all();

            $rows = CustomerAccessRequest::query()
                ->whereIn('email', $emails)
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->get();

            foreach ($rows as $row) {
                $email = strtolower(trim((string) $row->email));
                if ($email === '' || array_key_exists($email, $pendingAccessRequests)) {
                    continue;
                }

                $pendingAccessRequests[$email] = [
                    'id' => (int) $row->id,
                    'intent' => (string) $row->intent,
                    'company' => (string) ($row->company ?? ''),
                    'requested_tenant_slug' => (string) ($row->requested_tenant_slug ?? ''),
                ];
            }
        }

        $users = User::query()
            ->when($this->search !== '', function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%')
                    ->orWhere('role', 'like', '%' . $this->search . '%');
            })
            ->orderBy($this->sort, $this->dir)
            ->paginate($this->perPage);

        return view('livewire.admin.users.index', [
            'users' => $users,
            'pendingUsers' => $pendingUsers,
            'pendingAccessRequests' => $pendingAccessRequests,
        ])->layout('layouts.app');
    }
}
