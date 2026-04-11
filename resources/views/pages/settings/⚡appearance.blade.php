<?php

use Livewire\Component;

new class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Appearance Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>

        @php
            $prefs = is_array(auth()->user()?->ui_preferences ?? null) ? auth()->user()->ui_preferences : [];
        @endphp

        <form method="POST" action="{{ route('ui.preferences.update') }}" class="mt-6 space-y-4">
            @csrf

            <div class="rounded-2xl border border-zinc-200 bg-emerald-500/5 p-4">
                <div class="text-sm font-semibold text-zinc-950">Layout Preferences</div>
                <div class="mt-3 space-y-3">
                    <label class="flex items-center justify-between text-sm text-zinc-700">
                        <span>Wide layout (wider dashboards)</span>
                        <input type="checkbox" name="wide_layout" value="1" @checked(!empty($prefs['wide_layout'])) class="rounded border-zinc-300 bg-white text-emerald-600" />
                    </label>
                    <label class="flex items-center justify-between text-sm text-zinc-700">
                        <span>Compact tables</span>
                        <input type="checkbox" name="compact_tables" value="1" @checked(!empty($prefs['compact_tables'])) class="rounded border-zinc-300 bg-white text-emerald-600" />
                    </label>
                </div>
            </div>

            <flux:button type="submit" variant="primary">
                {{ __('Save Preferences') }}
            </flux:button>
        </form>
    </x-pages::settings.layout>
</section>
