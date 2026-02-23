<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <div class="space-y-2">
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Sign in</div>
            <h1 class="text-3xl font-['Fraunces'] font-semibold text-white">Welcome back to Backstage</h1>
            <p class="text-sm text-emerald-50/70">Track production, wholesale, and shipping with a single source of truth.</p>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-300/20 bg-emerald-500/10 p-3 text-xs text-emerald-50/85">
                <div class="font-semibold">Next steps</div>
                <div class="mt-1">Check your email for approval or password setup instructions. Then come back here to sign in.</div>
                <div class="mt-2 flex flex-wrap gap-3">
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="underline decoration-emerald-200/60 underline-offset-2" wire:navigate>
                            Reset password
                        </a>
                    @endif
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="underline decoration-emerald-200/60 underline-offset-2" wire:navigate>
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
                <a href="{{ route('auth.google.redirect') }}"
                   class="flex w-full items-center justify-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/90 hover:bg-white/10 transition">
                    <span aria-hidden="true" class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-black text-[11px] font-semibold">G</span>
                    <span>Continue with Google</span>
                </a>
                <div class="flex items-center gap-3 text-xs text-emerald-100/40">
                    <span class="h-px flex-1 bg-white/10"></span>
                    <span>or</span>
                    <span class="h-px flex-1 bg-white/10"></span>
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
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Enter Backstage') }}
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center text-emerald-50/70">
                <span>{{ __('Need an account?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('Request access') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
