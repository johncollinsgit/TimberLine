<x-layouts::auth :title="__('Create account')">
    <h1 class="fb-auth-title">Create account</h1>
    @if(session('status_error'))<p role="alert">{{ session('status_error') }}</p>@endif
    <form method="post" action="{{ route('self-service.signup.store') }}" class="flex flex-col gap-5">
        @csrf
        <flux:input name="name" label="Your name" :value="old('name')" required autocomplete="name" />
        <flux:input name="email" type="email" label="Email" :value="old('email')" required autocomplete="email" />
        <flux:input name="password" type="password" label="Password" required autocomplete="new-password" />
        <flux:input name="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password" />
        <label class="flex gap-3 text-sm"><input name="terms" type="checkbox" value="1" required> <span>I agree to the <a class="underline" href="{{ route('legal.terms') }}" target="_blank" rel="noopener">terms</a> and <a class="underline" href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">privacy policy</a>.</span></label>
        @error('terms')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <flux:button type="submit" variant="primary">Create account</flux:button>
    </form>
    <p class="text-sm">Already have an account? <a class="underline" href="{{ route('login') }}">Log in</a></p>
</x-layouts::auth>
