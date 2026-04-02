<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-950">Administration</h1>
    </x-slot>

    <div class="fb-page-canvas">
        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="text-[11px] uppercase tracking-[0.35em] text-zinc-500">Administration</div>
            <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">System Controls</div>
            <div class="mt-2 text-sm text-zinc-600">Configuration and catalog tools for Production-OS.</div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="text-sm font-semibold text-zinc-900">Locked Dropdown Lists</div>
            <div class="mt-1 text-sm text-zinc-600">
                Manage the allowed Scent + Size options used by Shipping orders.
            </div>

            <div class="mt-4">
                <livewire:admin.catalog />
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="text-sm font-semibold text-zinc-900">Scent Mapping</div>
            <div class="mt-1 text-sm text-zinc-600">
                Resolve unmapped Shopify lines and create new scents.
            </div>
            <div class="mt-4">
                <a href="{{ route('admin.mapping-exceptions') }}"
                   class="inline-flex items-center rounded-full border border-zinc-300 bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">
                    Open Mapping Queue
                </a>
                <a href="{{ route('admin.import-runs') }}"
                   class="ml-3 inline-flex items-center rounded-full border border-zinc-300 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">
                    Import Runs
                </a>
            </div>
        </section>
    </div>
</x-app-layout>
