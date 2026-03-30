<x-layouts::auth>
    @php
        $authTenantPresentation = $authTenantPresentation ?? [];
        $loginEyebrow = $authTenantPresentation['login_eyebrow'] ?? 'Sign in';
        $loginTitle = $authTenantPresentation['login_title'] ?? 'Welcome back';
        $loginSubtitle = $authTenantPresentation['login_subtitle'] ?? 'Sign in to open your workspace and continue where you left off.';
    @endphp

    <div class="flex flex-col gap-6">
        <div class="space-y-2">
            <p class="fb-auth-eyebrow">{{ $loginEyebrow }}</p>
            <h1 class="fb-auth-title">{{ $loginTitle }}</h1>
            <p class="fb-auth-subtitle">{{ $loginSubtitle }}</p>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (session('status'))
            <div class="rounded-2xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-3 text-xs text-[var(--fb-muted)]">
                <div class="font-semibold text-[var(--fb-brand)]">You're almost in</div>
                <div class="mt-1">Check your email for next steps if you just requested access. When you're ready, return here and sign in.</div>
                <div class="mt-2 flex flex-wrap gap-3">
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="underline underline-offset-2" wire:navigate>
                            Reset password
                        </a>
                    @endif
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="underline underline-offset-2" wire:navigate>
                            Request access
                        </a>
                    @endif
                </div>
            </div>
        @endif

        @php
            $googleLoginEnabled = (bool) config('services.google.enabled')
                && filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret'))
                && filled(config('services.google.redirect'));
        @endphp

        @if ($googleLoginEnabled)
            <div class="space-y-3">
                <a href="{{ route('auth.google.redirect') }}" class="fb-auth-google-btn">
                    <span aria-hidden="true" class="fb-auth-google-mark">G</span>
                    <span>Continue with Google</span>
                </a>
                <div class="flex items-center gap-3 text-xs text-[var(--fb-muted)]">
                    <span class="h-px flex-1 bg-[var(--fb-border)]"></span>
                    <span>or</span>
                    <span class="h-px flex-1 bg-[var(--fb-border)]"></span>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full fb-auth-submit" data-test="login-button">
                    {{ __('Sign in') }}
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center text-[var(--fb-muted)]">
                <span>{{ __('Need an account?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('Request access') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
