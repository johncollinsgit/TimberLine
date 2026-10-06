<x-layouts::auth>
    <div class="mt-4 flex flex-col gap-6 text-center">
        <h1 class="fb-auth-title">Email confirmed</h1>
        <flux:text>Your email address is verified. Return to Everbranch and sign in to continue.</flux:text>

        @if ($signedInAsDifferentAccount)
            <flux:text>You are signed in to a different account in this browser. Sign out before continuing.</flux:text>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">Sign out</flux:button>
            </form>
        @else
            <flux:button :href="route('login')" variant="primary" class="w-full">Sign in</flux:button>
        @endif
    </div>
</x-layouts::auth>
