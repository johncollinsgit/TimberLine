<x-layouts::auth>
    <div class="flex flex-col gap-6">
        @if ($invitation && $tenant)
            <div class="space-y-2">
                <p class="fb-auth-eyebrow">Team invitation</p>
                <h1 class="fb-auth-title">Join {{ $tenant->name }}</h1>
                <p class="fb-auth-subtitle">You will receive {{ $invitation->role === 'manager' ? 'manager' : 'employee' }} access to this Everbranch workspace.</p>
            </div>

            @auth
                <form method="POST" action="{{ route('employee-invitations.accept') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <flux:button variant="primary" type="submit" class="w-full fb-auth-submit">Join workspace</flux:button>
                </form>
                <p class="text-center text-sm text-[var(--fb-muted)]">Signed in as {{ auth()->user()->email }}</p>
                @if (strcasecmp((string) auth()->user()->email, (string) $invitation->email) !== 0)
                    <p class="text-center text-sm text-[var(--fb-muted)]">This invitation is for {{ $invitation->email }}. Sign out and use that account to join.</p>
                @endif
            @else
                @if ($invitation->email && ! $accountExists)
                    <p class="fb-auth-subtitle">Create your account for {{ $invitation->email }}. Your email will be your username in Everbranch Field.</p>
                    <form method="POST" action="{{ route('employee-invitations.register') }}" class="flex flex-col gap-4">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <label class="flex flex-col gap-1">Your name<input name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" class="rounded border p-3"></label>
                        <label class="flex flex-col gap-1">Password<input type="password" name="password" required autocomplete="new-password" class="rounded border p-3"></label>
                        <label class="flex flex-col gap-1">Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" class="rounded border p-3"></label>
                        @if ($errors->any())
                            <p role="alert" class="text-sm text-red-600">{{ $errors->first() }}</p>
                        @endif
                        <flux:button variant="primary" type="submit" class="w-full fb-auth-submit">Create account and join</flux:button>
                    </form>
                @else
                    <p class="fb-auth-subtitle">Sign in with {{ $invitation->email ?: 'your existing Everbranch account' }} to accept this invitation.</p>
                @endif
                <p class="text-center text-sm"><a href="{{ route('login') }}" class="underline">Sign in to your account</a></p>
            @endauth
        @else
            <div class="space-y-2">
                <p class="fb-auth-eyebrow">Team invitation</p>
                <h1 class="fb-auth-title">This invitation is no longer available</h1>
                <p class="fb-auth-subtitle">It may have expired, been revoked, or already been used. Ask your manager to send a new invitation.</p>
            </div>
        @endif
    </div>
</x-layouts::auth>
