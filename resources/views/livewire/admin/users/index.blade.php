<section class="rounded-3xl border border-emerald-200/10 bg-[#0f1412]/70 p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <div class="text-lg font-semibold text-white">Users</div>
      <div class="text-sm text-emerald-50/70">Manage access roles for Production-OS.</div>
    </div>
    <div class="flex items-center gap-2">
      <button wire:click="openCreate" class="rounded-full border border-emerald-400/40 bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-white">
        Add new user
      </button>
    </div>
  </div>

  @if($showCreate)
    <div class="mt-4 grid gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 md:grid-cols-6">
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.name" label="Name" />
        @error('create.name') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.email" label="Email" type="email" />
        @error('create.email') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div class="md:col-span-2">
        <flux:input wire:model.defer="create.password" label="Password" type="password" />
        @error('create.password') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
      </div>
      <div>
        <label class="text-xs text-white/70">Role</label>
        <select wire:model.defer="create.role" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/90">
          <option value="admin">Admin</option>
          <option value="manager">Manager</option>
          <option value="pouring">Pouring</option>
        </select>
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="create.is_active" class="rounded border-white/20 bg-white/10" />
        <span class="text-sm text-white/80">Active</span>
      </div>
      <div class="md:col-span-6 flex items-center gap-2">
        <button wire:click="create" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white">
          Save
        </button>
        <button wire:click="openCreate" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">
          Cancel
        </button>
      </div>
    </div>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search users..." />
    <div class="flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-white/50">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-white/80 focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 overflow-hidden rounded-2xl border border-white/10">
    <table class="min-w-full text-sm">
      <thead class="bg-white/5 text-white/70">
        <tr>
          <th class="px-4 py-3 text-left cursor-pointer" wire:click="setSort('name')">Name</th>
          <th class="px-4 py-3 text-left">Email</th>
          <th class="px-4 py-3 text-left">Role</th>
          <th class="px-4 py-3 text-left">Active</th>
          <th class="px-4 py-3 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-white/5">
        @foreach($users as $user)
          <tr class="hover:bg-white/5">
            <td class="px-4 py-3 text-white">{{ $user->name }}</td>
            <td class="px-4 py-3 text-white/80">{{ $user->email }}</td>
            <td class="px-4 py-3 text-white/80 capitalize">{{ $user->role ?? 'admin' }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-1 text-xs {{ $user->is_active ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                {{ $user->is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button type="button" wire:click="openEdit({{ $user->id }})" class="rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 py-1 text-[11px] text-emerald-100">Edit</button>
              <button type="button" wire:click="openDelete({{ $user->id }})" class="rounded-full border border-red-400/30 bg-red-500/10 px-3 py-1 text-[11px] text-red-100">Delete</button>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-4">{{ $users->links() }}</div>

</section>

  @if($showEdit)
    <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
      <div class="w-full max-w-2xl rounded-2xl border border-white/10 bg-zinc-950 p-6">
        <div class="text-lg font-semibold text-white">Edit User</div>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
          <flux:input wire:model.defer="edit.name" label="Name" />
          <flux:input wire:model.defer="edit.email" label="Email" type="email" />
          <div>
            <label class="text-xs text-white/70">Role</label>
            <select wire:model.defer="edit.role" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/90">
              <option value="admin">Admin</option>
              <option value="manager">Manager</option>
              <option value="pouring">Pouring</option>
            </select>
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.defer="edit.is_active" class="rounded border-white/20 bg-white/10" />
            <span class="text-sm text-white/80">Active</span>
          </div>
          <flux:input wire:model.defer="newPassword" label="Reset Password" type="password" />
          @error('newPassword') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>
        <div class="mt-4 flex items-center gap-2">
          <button type="button" wire:click="save" class="rounded-full border border-emerald-400/40 bg-emerald-500/30 px-4 py-2 text-xs font-semibold text-white">Save</button>
          <button type="button" wire:click="$set('showEdit', false)" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
        </div>
      </div>
    </div>
  @endif

  @if($showDelete)
    <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
      <div class="w-full max-w-md rounded-2xl border border-white/10 bg-zinc-950 p-6">
        <div class="text-lg font-semibold text-white">Delete User</div>
        <div class="mt-2 text-sm text-white/70">Are you sure? This cannot be undone.</div>
        <div class="mt-4 flex items-center gap-2">
          <button type="button" wire:click="destroy" class="rounded-full border border-red-400/40 bg-red-500/30 px-4 py-2 text-xs font-semibold text-white">Delete</button>
          <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/70">Cancel</button>
        </div>
      </div>
    </div>
  @endif
