<x-layouts::auth :title="__('Verify email')">
    <h1 class="fb-auth-title">Verify your email</h1>
    <p>Use a verification link to finish creating your account.</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if(session('status_error'))<p role="alert">{{ session('status_error') }}</p>@endif
    <form method="post" action="{{ route('self-service.verify.resend') }}">@csrf<flux:button type="submit" variant="primary">Send verification email</flux:button></form>
    <form method="post" action="{{ route('logout') }}">@csrf<flux:button type="submit">Use another account</flux:button></form>
</x-layouts::auth>
