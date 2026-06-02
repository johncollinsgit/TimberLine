@props([
    'consoleSwitches' => [],
    'name' => null,
    'email' => null,
    'position' => 'bottom',
    'align' => 'start',
])

@php
    $user = auth()->user();
    $displayName = trim((string) ($name ?: $user?->name ?: $user?->email ?: 'User'));
    $displayEmail = trim((string) ($email ?: $user?->email ?? ''));
    $initials = trim((string) ($user?->initials() ?? ''));
    if ($initials === '') {
        $initials = strtoupper(substr($displayName, 0, 1));
    }
    $activeConsole = collect($consoleSwitches)->first(
        fn (mixed $switch): bool => is_array($switch) && ! empty($switch['active'])
    );
    $secondaryLine = $displayEmail !== ''
        ? $displayEmail
        : trim((string) ($activeConsole['descriptor'] ?? 'Account'));
@endphp

<flux:dropdown :position="$position" :align="$align" {{ $attributes->class('mf-user-menu') }}>
    <button type="button" class="mf-user-menu-trigger" data-test="sidebar-menu-button">
        <span class="mf-user-menu-avatar" aria-hidden="true">{{ $initials }}</span>
        <span class="mf-user-menu-body">
            <span class="mf-user-menu-name" title="{{ $displayName }}">{{ $displayName }}</span>
            <span class="mf-user-menu-email" title="{{ $secondaryLine }}">{{ $secondaryLine }}</span>
        </span>
        <span class="mf-user-menu-caret" aria-hidden="true">
            <flux:icon.chevrons-up-down class="size-4" />
        </span>
    </button>

    <flux:menu class="mf-user-menu-panel">
        <div class="mf-user-menu-panel-head">
            <span class="mf-user-menu-avatar mf-user-menu-avatar-lg" aria-hidden="true">{{ $initials }}</span>
            <div class="mf-user-menu-panel-copy">
                <div class="mf-user-menu-name" title="{{ $displayName }}">{{ $displayName }}</div>
                <div class="mf-user-menu-email" title="{{ $secondaryLine }}">{{ $secondaryLine }}</div>
                @if(is_array($activeConsole))
                    <div class="mf-user-menu-panel-caption">
                        {{ $activeConsole['label'] ?? 'Console' }}{{ ! empty($activeConsole['descriptor']) ? ' | '.$activeConsole['descriptor'] : '' }}
                    </div>
                @endif
            </div>
        </div>

        @if(is_array($consoleSwitches) && count($consoleSwitches) > 1)
            <flux:menu.separator />
            <div class="mf-user-menu-panel-section">Switch Console</div>
            @foreach($consoleSwitches as $switch)
                @continue(! is_array($switch) || trim((string) ($switch['href'] ?? '')) === '')
                <flux:menu.item
                    href="{{ $switch['href'] }}"
                    icon="{{ ! empty($switch['active']) ? 'check-circle' : 'arrow-path' }}"
                    class="{{ ! empty($switch['active']) ? 'mf-user-menu-item-active' : '' }}"
                >
                    {{ $switch['label'] ?? 'Console' }}
                </flux:menu.item>
            @endforeach
        @endif

        <flux:menu.separator />
        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
            {{ __('Settings') }}
        </flux:menu.item>
        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                {{ __('Log Out') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>


