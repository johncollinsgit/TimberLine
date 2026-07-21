<x-layouts::auth>
    <div class="flex flex-col gap-6">
        @if ($invitation && $tenant)
            <div class="space-y-2">
                <p class="fb-auth-eyebrow">Team invitation</p>
                <h1 class="fb-auth-title">Join {{ $tenant->name }}</h1>
                <p class="fb-auth-subtitle">You will receive {{ $invitation->role === 'manager' ? 'manager' : 'employee' }} access to this Everbranch workspace.</p>
            </div>

            <form method="POST" action="{{ route('employee-invitations.accept') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <flux:button variant="primary" type="submit" class="w-full fb-auth-submit">
                    Join workspace
                </flux:button>
            </form>

            <p class="text-center text-sm text-[var(--fb-muted)]">
                Signed in as {{ auth()->user()->email }}
            </p>
        @else
            <div class="space-y-2">
                <p class="fb-auth-eyebrow">Team invitation</p>
                <h1 class="fb-auth-title">This invitation is no longer available</h1>
                <p class="fb-auth-subtitle">It may have expired, been revoked, or already been used. Ask your manager to send a new invitation.</p>
            </div>
        @endif
    </div>
</x-layouts::auth>
