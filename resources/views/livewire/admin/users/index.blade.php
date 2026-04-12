<section class="mf-app-card rounded-3xl border border-[var(--fb-border)] p-5">
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <div class="text-lg font-semibold text-[var(--fb-text)]">Users</div>
        <div class="text-sm text-[var(--fb-muted)]">Manage workspace access and roles.</div>
      </div>
    <div class="flex items-center gap-2">
      <button wire:click="openCreate" class="rounded-full border border-[var(--fb-brand)] bg-[var(--fb-brand)] px-4 py-2 text-xs font-semibold text-zinc-950 hover:bg-[var(--fb-brand-2)] hover:border-[var(--fb-brand-2)]">
        Add user
      </button>
    </div>
  </div>

  @if($showCreate)
    <div class="mt-4 grid gap-3 rounded-2xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 md:grid-cols-6">
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
        <label class="text-xs text-[var(--fb-muted)]">Role</label>
        <select wire:model.defer="create.role" class="mt-1 w-full rounded-xl border border-[var(--fb-border)] bg-white px-3 py-2 text-sm text-[var(--fb-text)]">
          <option value="admin">Admin</option>
          <option value="manager">Manager</option>
          <option value="marketing_manager">Marketing Manager</option>
          <option value="pouring">Pouring</option>
        </select>
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" wire:model.defer="create.is_active" class="rounded border-[var(--fb-border)] bg-white" />
        <span class="text-sm text-[var(--fb-muted)]">Active</span>
      </div>
      <div class="md:col-span-6 flex items-center gap-2">
        <button wire:click="create" class="rounded-full border border-[var(--fb-brand)] bg-[var(--fb-brand)] px-4 py-2 text-xs font-semibold text-zinc-950 hover:bg-[var(--fb-brand-2)] hover:border-[var(--fb-brand-2)]">
          Save
        </button>
        <button wire:click="openCreate" class="rounded-full border border-[var(--fb-border)] bg-white px-4 py-2 text-xs font-semibold text-[var(--fb-muted)]">
          Cancel
        </button>
      </div>
    </div>
  @endif

  <div class="mt-4 flex flex-wrap items-center gap-2">
    <flux:input wire:model.live="search" placeholder="Search users..." />
    <div class="flex items-center gap-2 rounded-full border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-3 py-2">
      <span class="text-[11px] uppercase tracking-wide text-[var(--fb-muted)]">Rows</span>
      <select wire:model.live="perPage" class="bg-transparent text-xs text-[var(--fb-text)] focus:outline-none">
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  </div>

  <div class="mt-4 rounded-2xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4">
    <div class="flex items-center justify-between gap-2">
      <div>
        <div class="text-sm font-semibold text-[var(--fb-text)]">Pending Approvals</div>
        <div class="text-xs text-[var(--fb-muted)]">Requests from Google sign-in or the Request Access form.</div>
      </div>
      <div class="rounded-full border border-[var(--fb-border)] bg-white px-3 py-1 text-xs text-[var(--fb-muted)]">
        {{ $pendingUsers->count() }} pending
      </div>
    </div>

    <div class="mt-3 space-y-2">
      @forelse($pendingUsers as $pending)
        @php
          $source = $pending->requested_via ?: ($pending->google_id ? 'google' : 'manual');
          $pendingEmail = strtolower(trim((string) $pending->email));
          $pendingAccess = is_array($pendingAccessRequests[$pendingEmail] ?? null) ? (array) $pendingAccessRequests[$pendingEmail] : [];
          $pendingIntent = strtolower(trim((string) ($pendingAccess['intent'] ?? '')));
        @endphp
        <div class="flex flex-col gap-3 rounded-xl border border-[var(--fb-border)] bg-white p-3 md:flex-row md:items-center md:justify-between">
          <div>
            <div class="text-sm text-[var(--fb-text)]">{{ $pending->name }}</div>
            <div class="text-xs text-[var(--fb-muted)]">{{ $pending->email }}</div>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-[var(--fb-muted)]">
              <span class="rounded-full border border-[var(--fb-border)] px-2 py-0.5 capitalize">{{ str_replace('_', ' ', $source) }}</span>
              <span>Requested {{ optional($pending->approval_requested_at ?? $pending->created_at)->diffForHumans() }}</span>
              <span>Role: {{ $pending->role ?? 'pouring' }}</span>
              @if($pendingIntent !== '')
                <span class="rounded-full border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-2 py-0.5 capitalize">
                  {{ $pendingIntent === 'demo' ? 'Demo request' : 'Production request' }}
                </span>
              @endif
            </div>
            @if($pendingAccess !== [])
              <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-[var(--fb-muted)]">
                @if(filled($pendingAccess['company'] ?? null))
                  <span class="rounded-full border border-[var(--fb-border)] px-2 py-0.5">{{ $pendingAccess['company'] }}</span>
                @endif
                @if(filled($pendingAccess['requested_tenant_slug'] ?? null))
                  <span class="rounded-full border border-[var(--fb-border)] px-2 py-0.5">Tenant: {{ $pendingAccess['requested_tenant_slug'] }}</span>
                @endif
              </div>
            @endif
          </div>
          <div class="flex items-center gap-2">
            <button type="button" wire:click="openEdit({{ $pending->id }})" class="rounded-full border border-[var(--fb-border)] bg-white px-3 py-1 text-[11px] text-[var(--fb-muted)]">
              Review
            </button>
            <button type="button" wire:click="approve({{ $pending->id }})" class="rounded-full border border-[var(--fb-brand)] bg-[var(--fb-brand)] px-3 py-1 text-[11px] text-zinc-950 hover:bg-[var(--fb-brand-2)] hover:border-[var(--fb-brand-2)]">
              Approve
            </button>
          </div>
        </div>
      @empty
        <div class="rounded-xl border border-[var(--fb-border)] bg-white p-3 text-sm text-[var(--fb-muted)]">
          No pending account approvals.
        </div>
      @endforelse
    </div>
  </div>

  <div class="mt-4 overflow-hidden rounded-2xl border border-[var(--fb-border)]">
    <table class="min-w-full text-sm">
      <thead class="bg-[var(--fb-surface-strong)] text-[var(--fb-muted)]">
        <tr>
          <th class="px-4 py-3 text-left cursor-pointer" wire:click="setSort('name')">Name</th>
          <th class="px-4 py-3 text-left">Email</th>
          <th class="px-4 py-3 text-left">Role</th>
          <th class="px-4 py-3 text-left">Active</th>
          <th class="px-4 py-3 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-[var(--fb-border)]">
        @foreach($users as $user)
          <tr class="hover:bg-[var(--fb-surface-muted)]">
            <td class="px-4 py-3 text-[var(--fb-text)]">{{ $user->name }}</td>
            <td class="px-4 py-3 text-[var(--fb-muted)]">{{ $user->email }}</td>
            <td class="px-4 py-3 text-[var(--fb-muted)] capitalize">{{ $user->role ?? 'admin' }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-1 text-xs {{ $user->is_active ? 'border border-[var(--fb-brand)] bg-[var(--fb-brand)] text-zinc-950' : 'border border-[var(--fb-border)] bg-white text-[var(--fb-muted)]' }}">
                {{ $user->is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3 text-right space-x-2">
              <button type="button" wire:click="openEdit({{ $user->id }})" class="rounded-full border border-[var(--fb-border)] bg-white px-3 py-1 text-[11px] text-[var(--fb-muted)]">Edit</button>
              <button type="button" wire:click="openDelete({{ $user->id }})" class="rounded-full border border-red-300/40 bg-red-50 px-3 py-1 text-[11px] text-red-700">Delete</button>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-4">{{ $users->links() }}</div>

</section>

  @if($showEdit)
    <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
      <div class="mf-app-card w-full max-w-2xl rounded-2xl border border-[var(--fb-border)] p-6">
        <div class="text-lg font-semibold text-[var(--fb-text)]">Edit User</div>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
          <flux:input wire:model.defer="edit.name" label="Name" />
          <flux:input wire:model.defer="edit.email" label="Email" type="email" />
          <div>
            <label class="text-xs text-[var(--fb-muted)]">Role</label>
            <select wire:model.defer="edit.role" class="mt-1 w-full rounded-xl border border-[var(--fb-border)] bg-white px-3 py-2 text-sm text-[var(--fb-text)]">
              <option value="admin">Admin</option>
              <option value="manager">Manager</option>
              <option value="marketing_manager">Marketing Manager</option>
              <option value="pouring">Pouring</option>
            </select>
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.defer="edit.is_active" class="rounded border-[var(--fb-border)] bg-white" />
            <span class="text-sm text-[var(--fb-muted)]">Active</span>
          </div>
          <flux:input wire:model.defer="newPassword" label="Reset Password" type="password" />
          @error('newPassword') <div class="mt-1 text-xs text-red-300">{{ $message }}</div> @enderror
        </div>

        @if(! empty($editAccessRequest))
          <div class="mt-6 rounded-2xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4">
            <div class="flex items-center justify-between gap-2">
              <div>
                <div class="text-sm font-semibold text-[var(--fb-text)]">Access request</div>
                <div class="text-xs text-[var(--fb-muted)]">Adjust routing details before sending the approval email.</div>
              </div>
              <div class="rounded-full border border-[var(--fb-border)] bg-white px-3 py-1 text-xs text-[var(--fb-muted)] capitalize">
                {{ $editAccessRequest['intent'] ?? 'unknown' }}
              </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
              <flux:input wire:model.defer="editAccessRequest.company" label="Company" />
              <flux:input wire:model.defer="editAccessRequest.requested_tenant_slug" label="Requested tenant slug" />
              <div class="md:col-span-2">
                <label class="text-xs text-[var(--fb-muted)]">Notes</label>
                <textarea wire:model.defer="editAccessRequest.message" rows="4" class="mt-1 w-full rounded-xl border border-[var(--fb-border)] bg-white px-3 py-2 text-sm text-[var(--fb-text)]"></textarea>
              </div>
            </div>
          </div>
        @endif

        <div class="mt-4 flex items-center gap-2">
          <button type="button" wire:click="save" class="rounded-full border border-[var(--fb-brand)] bg-[var(--fb-brand)] px-4 py-2 text-xs font-semibold text-zinc-950 hover:bg-[var(--fb-brand-2)] hover:border-[var(--fb-brand-2)]">Save</button>
          <button type="button" wire:click="$set('showEdit', false)" class="rounded-full border border-[var(--fb-border)] bg-white px-4 py-2 text-xs font-semibold text-[var(--fb-muted)]">Cancel</button>
        </div>
      </div>
    </div>
  @endif

  @if($showDelete)
    <div class="fixed inset-0 z-[9999] flex items-center justify-center fb-overlay-soft p-4" style="position: fixed; inset: 0; z-index: 99999;" data-admin-modal>
      <div class="mf-app-card w-full max-w-md rounded-2xl border border-[var(--fb-border)] p-6">
        <div class="text-lg font-semibold text-[var(--fb-text)]">Delete User</div>
        <div class="mt-2 text-sm text-[var(--fb-muted)]">Are you sure? This cannot be undone.</div>
        <div class="mt-4 flex items-center gap-2">
          <button type="button" wire:click="destroy" class="rounded-full border border-red-300/40 bg-red-600 px-4 py-2 text-xs font-semibold text-zinc-950">Delete</button>
          <button type="button" wire:click="$set('showDelete', false)" class="rounded-full border border-[var(--fb-border)] bg-white px-4 py-2 text-xs font-semibold text-[var(--fb-muted)]">Cancel</button>
        </div>
      </div>
    </div>
  @endif
