<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
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

        $emailSent = false;
        if ($wasInactive && $payload['is_active']) {
            $fresh = User::query()->find($this->editingId);
            if ($fresh) {
                try {
                    $fresh->notify(new ApprovalPasswordSetupNotification($fresh));
                    $emailSent = true;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->showEdit = false;
        $this->editingId = null;
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
        $user = User::query()->findOrFail($id);
        $wasInactive = !$user->is_active;

        $user->forceFill([
            'is_active' => true,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ])->save();

        $emailSent = false;
        if ($wasInactive) {
            try {
                $user->notify(new ApprovalPasswordSetupNotification($user));
                $emailSent = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->dispatch('toast', [
            'message' => $emailSent
                ? 'User approved. Password setup email sent.'
                : 'User approved. Password setup email could not be sent.',
            'style' => $emailSent ? 'success' : 'warning',
        ]);
    }

    public function render()
    {
        $pendingUsers = User::query()
            ->where('is_active', false)
            ->orderBy('approval_requested_at', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get();

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
        ])->layout('layouts.app');
    }
}
