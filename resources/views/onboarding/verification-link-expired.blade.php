<x-layouts::auth :title="__('Get a new link')">
    <h1 class="fb-auth-title">Get a new link</h1>
    <p>This verification link has expired or changed.</p>
    @if($canResend)
        <p>Send a new link for the account signed in to this browser.</p>
        <form method="post" action="{{ route('self-service.verify.resend') }}">
            @csrf
            <flux:button type="submit" variant="primary">Send verification email</flux:button>
        </form>
    @elseif(auth()->check())
        <p>To verify another account, sign out and sign in to that account.</p>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="primary">Use another account</flux:button>
        </form>
        <a class="underline" href="{{ route('home') }}">Continue to Everbranch</a>
    @else
        <p>Sign in to request another verification email.</p>
        <flux:button :href="route('self-service.verify.notice')" variant="primary">Sign in</flux:button>
    @endif
</x-layouts::auth>
