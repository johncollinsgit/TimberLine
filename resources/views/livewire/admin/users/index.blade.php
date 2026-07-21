<div class="space-y-5">
  <section class="overflow-hidden rounded-3xl border border-[var(--fb-border)] bg-white shadow-sm">
    <div class="flex flex-col gap-5 border-b border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-5 py-6 sm:px-7 lg:flex-row lg:items-center lg:justify-between">
      <div class="flex items-start gap-4">
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[var(--fb-brand)] text-white shadow-sm" aria-hidden="true">
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
          </svg>
        </div>
        <div>
          <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[var(--fb-muted)]">{{ $tenant->name }}</div>
          <h2 class="mt-1 text-2xl font-semibold text-[var(--fb-text)]">Team access</h2>
          <p class="mt-1 max-w-2xl text-sm leading-6 text-[var(--fb-muted)]">Invite teammates, approve workspace requests, and assign the right level of access. This page only shows people connected to this workspace.</p>
        </div>
      </div>

      <button
        type="button"
        wire:click="toggleInvite"
        class="team-access-primary-action inline-flex h-11 items-center justify-center gap-2 rounded-xl px-5 text-sm font-semibold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-[var(--fb-accent)] focus:ring-offset-2"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" d="M12 5v14M5 12h14" />
        </svg>
        {{ $showInvite ? 'Close invite' : 'Invite teammate' }}
      </button>
    </div>

    <div class="grid grid-cols-2 divide-x divide-[var(--fb-border)] border-b border-[var(--fb-border)] sm:w-fit sm:grid-cols-none sm:auto-cols-fr sm:grid-flow-col sm:divide-x-0">
      <div class="px-5 py-4 sm:min-w-36 sm:border-r sm:border-[var(--fb-border)] sm:px-7">
        <div class="text-2xl font-semibold text-[var(--fb-text)]">{{ $memberCount }}</div>
        <div class="text-xs text-[var(--fb-muted)]">Team members</div>
      </div>
      <div class="px-5 py-4 sm:min-w-36 sm:border-r sm:border-[var(--fb-border)] sm:px-7">
        <div class="text-2xl font-semibold text-[var(--fb-text)]">{{ $pendingRequests->count() }}</div>
        <div class="text-xs text-[var(--fb-muted)]">Pending requests</div>
      </div>
      <div class="hidden px-5 py-4 sm:block sm:min-w-36 sm:px-7">
        <div class="text-2xl font-semibold text-[var(--fb-text)]">{{ $administratorCount }}</div>
        <div class="text-xs text-[var(--fb-muted)]">Administrators</div>
      </div>
    </div>

    @if($showInvite)
      <form wire:submit="inviteMember" class="border-b border-[var(--fb-border)] bg-white px-5 py-6 sm:px-7">
        <div class="mb-4">
          <h3 class="text-base font-semibold text-[var(--fb-text)]">Invite a teammate</h3>
          <p class="mt-1 text-sm text-[var(--fb-muted)]">They will receive an activation email if they do not already have an Everbranch account.</p>
        </div>
        <div class="grid gap-4 lg:grid-cols-[1fr_1.25fr_0.8fr_auto] lg:items-end">
          <flux:input wire:model="invite.name" label="Name" autocomplete="name" />
          <flux:input wire:model="invite.email" label="Email" type="email" autocomplete="email" />
          <div>
            <label for="invite-role" class="mb-1.5 block text-sm font-medium text-[var(--fb-text)]">Workspace role</label>
            <select id="invite-role" wire:model="invite.role" class="h-10 w-full rounded-lg border border-[var(--fb-border)] bg-white px-3 text-sm text-[var(--fb-text)] shadow-sm focus:border-[var(--fb-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--fb-brand)]/20">
              <option value="member">Team member</option>
              <option value="manager">Manager</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
          <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="inviteMember"
            class="team-access-primary-action inline-flex h-10 items-center justify-center rounded-lg px-5 text-sm font-semibold transition disabled:cursor-wait disabled:opacity-60"
          >
            <span wire:loading.remove wire:target="inviteMember">Send invite</span>
            <span wire:loading wire:target="inviteMember">Adding…</span>
          </button>
        </div>
        @error('invite.name') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        @error('invite.email') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        @error('invite.role') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
      </form>
    @endif
  </section>

  @if($pendingRequests->isNotEmpty())
    <section class="rounded-3xl border border-[var(--fb-border)] bg-white p-5 shadow-sm sm:p-7" aria-labelledby="pending-access-heading">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 rounded-full bg-amber-500" aria-hidden="true"></span>
            <h3 id="pending-access-heading" class="text-lg font-semibold text-[var(--fb-text)]">Needs your review</h3>
          </div>
          <p class="mt-1 text-sm text-[var(--fb-muted)]">Workspace access requests only. Wholesale applications are kept in their separate wholesale inbox.</p>
        </div>
        <span class="w-fit rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">{{ $pendingRequests->count() }} pending</span>
      </div>

      <div class="mt-5 divide-y divide-[var(--fb-border)] overflow-hidden rounded-2xl border border-[var(--fb-border)]">
        @foreach($pendingRequests as $accessRequest)
          @php
            $displayName = trim((string) ($accessRequest->name ?: $accessRequest->user?->name ?: 'New teammate'));
            $displayEmail = strtolower(trim((string) ($accessRequest->email ?: $accessRequest->user?->email)));
            $initials = collect(preg_split('/\s+/', $displayName) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
          @endphp
          <div class="grid gap-4 bg-white p-4 sm:grid-cols-[auto_1fr_auto] sm:items-center sm:p-5" wire:key="access-request-{{ $accessRequest->id }}">
            <div class="hidden h-10 w-10 items-center justify-center rounded-full bg-[var(--fb-surface-strong)] text-xs font-bold text-[var(--fb-text)] sm:flex" aria-hidden="true">{{ $initials ?: '?' }}</div>
            <div class="min-w-0">
              <div class="truncate font-medium text-[var(--fb-text)]">{{ $displayName }}</div>
              <div class="truncate text-sm text-[var(--fb-muted)]">{{ $displayEmail }}</div>
              <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[var(--fb-muted)]">
                <span>Requested {{ $accessRequest->created_at?->diffForHumans() }}</span>
                @if(filled($accessRequest->company))
                  <span>{{ $accessRequest->company }}</span>
                @endif
              </div>
            </div>
            <div class="flex items-center gap-2 sm:justify-end">
              <button
                type="button"
                wire:click="rejectRequest({{ $accessRequest->id }})"
                wire:loading.attr="disabled"
                wire:target="rejectRequest({{ $accessRequest->id }})"
                class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-red-200 bg-white px-4 text-sm font-semibold text-red-700 transition hover:bg-red-50 disabled:cursor-wait disabled:opacity-60 sm:flex-none"
              >
                <span wire:loading.remove wire:target="rejectRequest({{ $accessRequest->id }})">Reject</span>
                <span wire:loading wire:target="rejectRequest({{ $accessRequest->id }})">Rejecting…</span>
              </button>
              <button
                type="button"
                wire:click="approveRequest({{ $accessRequest->id }})"
                wire:loading.attr="disabled"
                wire:target="approveRequest({{ $accessRequest->id }})"
                class="team-access-primary-action inline-flex h-10 flex-1 items-center justify-center rounded-lg px-4 text-sm font-semibold transition disabled:cursor-wait disabled:opacity-60 sm:flex-none"
              >
                <span wire:loading.remove wire:target="approveRequest({{ $accessRequest->id }})">Approve</span>
                <span wire:loading wire:target="approveRequest({{ $accessRequest->id }})">Approving…</span>
              </button>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  <section class="overflow-hidden rounded-3xl border border-[var(--fb-border)] bg-white shadow-sm" aria-labelledby="workspace-team-heading">
    <div class="flex flex-col gap-4 border-b border-[var(--fb-border)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
      <div>
        <h3 id="workspace-team-heading" class="text-lg font-semibold text-[var(--fb-text)]">Workspace team</h3>
        <p class="mt-1 text-sm text-[var(--fb-muted)]">Role changes save immediately.</p>
      </div>
      <div class="w-full sm:w-80">
        <flux:input wire:model.live.debounce.250ms="search" placeholder="Search name or email…" aria-label="Search team members" />
      </div>
    </div>

    <div class="divide-y divide-[var(--fb-border)]">
      @forelse($members as $member)
        @php
          $membershipRole = strtolower(trim((string) ($member->tenants->first()?->pivot?->role ?? 'member')));
          $membershipRole = $membershipRole === 'owner' ? 'admin' : $membershipRole;
          $membershipRole = in_array($membershipRole, $assignableRoles, true) ? $membershipRole : 'member';
          $memberInitials = collect(preg_split('/\s+/', trim((string) $member->name)) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
        @endphp
        <div class="grid gap-4 px-5 py-4 transition hover:bg-[var(--fb-surface-muted)] sm:px-7 lg:grid-cols-[minmax(0,1fr)_minmax(15rem,0.5fr)_9rem_10rem] lg:items-center" wire:key="team-member-{{ $member->id }}">
          <div class="flex min-w-0 items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--fb-brand)] text-xs font-bold text-white" aria-hidden="true">{{ $memberInitials ?: '?' }}</div>
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="truncate font-medium text-[var(--fb-text)]">{{ $member->name }}</span>
                @if((int) $member->id === (int) auth()->id())
                  <span class="rounded-full bg-[var(--fb-surface-strong)] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[var(--fb-muted)]">You</span>
                @endif
              </div>
              <div class="truncate text-sm text-[var(--fb-muted)]">{{ $member->email }}</div>
            </div>
          </div>

          <div>
            <label for="member-role-{{ $member->id }}" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[var(--fb-muted)] lg:sr-only">Role</label>
            <select
              id="member-role-{{ $member->id }}"
              wire:change="updateMemberRole({{ $member->id }}, $event.target.value)"
              wire:loading.attr="disabled"
              wire:target="updateMemberRole"
              class="h-10 w-full rounded-lg border border-[var(--fb-border)] bg-white px-3 text-sm font-medium text-[var(--fb-text)] shadow-sm focus:border-[var(--fb-brand)] focus:outline-none focus:ring-2 focus:ring-[var(--fb-brand)]/20"
            >
              <option value="member" @selected($membershipRole === 'member')>Team member</option>
              <option value="manager" @selected($membershipRole === 'manager')>Manager</option>
              <option value="admin" @selected($membershipRole === 'admin')>Administrator</option>
            </select>
          </div>

          <div class="flex min-h-9 items-center lg:justify-start">
            <span class="inline-flex items-center gap-1.5 text-xs font-medium {{ $member->is_active ? 'text-emerald-700' : 'text-amber-700' }}">
              <span class="h-2 w-2 rounded-full {{ $member->is_active ? 'bg-emerald-500' : 'bg-amber-500' }}" aria-hidden="true"></span>
              {{ $member->is_active ? 'Active' : 'Activation pending' }}
            </span>
          </div>

          <div @class([
            'min-h-9 items-center lg:justify-start',
            'flex' => (int) $member->id !== (int) auth()->id(),
            'hidden lg:flex' => (int) $member->id === (int) auth()->id(),
          ])>
            @if((int) $member->id !== (int) auth()->id())
              <button
                type="button"
                wire:click="removeAccess({{ $member->id }})"
                wire:confirm="Remove this person from the workspace? Their Everbranch account will not be deleted."
                wire:loading.attr="disabled"
                wire:target="removeAccess({{ $member->id }})"
                class="team-access-remove-action inline-flex h-9 w-full items-center justify-center rounded-lg px-3 text-xs font-semibold transition disabled:opacity-60"
              >
                Remove access
              </button>
            @endif
          </div>
        </div>
      @empty
        <div class="px-5 py-12 text-center sm:px-7">
          <div class="text-sm font-medium text-[var(--fb-text)]">No matching teammates</div>
          <div class="mt-1 text-sm text-[var(--fb-muted)]">Try a different name or email.</div>
        </div>
      @endforelse
    </div>

    @if($members->hasPages())
      <div class="border-t border-[var(--fb-border)] px-5 py-4 sm:px-7">{{ $members->links() }}</div>
    @endif
  </section>

  <p class="px-1 text-xs leading-5 text-[var(--fb-muted)]">Removing workspace access does not delete a person’s Everbranch account or affect access they may have to another workspace.</p>
</div>
