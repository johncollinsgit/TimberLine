<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Administration</h1>
    </x-slot>

    <div class="space-y-6">
        <section class="fb-page-surface fb-page-surface--subtle p-6">
            <div class="fb-kpi-label">Administration</div>
            <h2 class="mt-2 text-3xl font-semibold text-zinc-950">System Controls</h2>
            <p class="mt-2 text-sm text-zinc-600">Configuration and catalog tools for Production-OS.</p>
        </section>

        <section class="fb-page-surface p-6">
            <div class="text-sm font-semibold text-zinc-950">Locked Dropdown Lists</div>
            <div class="mt-1 text-sm text-zinc-600">
                Manage the allowed Scent + Size options used by Shipping orders.
            </div>

            <div class="mt-4">
                <livewire:admin.catalog />
            </div>
        </section>

        <section class="fb-page-surface p-6">
            <div class="text-sm font-semibold text-zinc-950">Scent Mapping</div>
            <div class="mt-1 text-sm text-zinc-600">
                Resolve unmapped Shopify lines and create new scents.
            </div>
            <div class="mt-4">
                <a href="{{ route('admin.mapping-exceptions') }}"
                   class="fb-btn-soft fb-link-soft rounded-full">
                    Open Mapping Queue
                </a>
                <a href="{{ route('admin.import-runs') }}"
                   class="ml-3 fb-btn-soft fb-link-soft rounded-full">
                    Import Runs
                </a>
            </div>
        </section>
    </div>
</x-app-layout>
