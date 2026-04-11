<x-layouts::auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Request access')" :description="__('Submit your details. After approval, we will email you a link to set your password.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Submit access request') }}
                </flux:button>
            </div>
        </form>

        <div class="fb-surface-inset p-3 text-xs text-zinc-600">
            <div class="font-semibold text-zinc-950">What happens next?</div>
            <ol class="mt-2 list-decimal space-y-1 pl-4">
                <li>An administrator reviews your request.</li>
                <li>You receive an approval email with a password setup link.</li>
                <li>Set your password, then return to sign in.</li>
            </ol>
        </div>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
