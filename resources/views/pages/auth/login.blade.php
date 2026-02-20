<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <div class="space-y-2">
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Sign in</div>
            <h1 class="text-3xl font-['Fraunces'] font-semibold text-white">Welcome back to Backstage</h1>
            <p class="text-sm text-emerald-50/70">Track production, wholesale, and shipping with a single source of truth.</p>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

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
