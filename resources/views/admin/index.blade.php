<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Administration</h1>
    </x-slot>

    <div class="p-6 space-y-6">
        <p class="text-zinc-600 dark:text-zinc-300">
            Configuration, users, and system-level controls.
        </p>

        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950/40 p-6">
            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Locked Dropdown Lists</div>
            <div class="text-sm text-zinc-600 dark:text-zinc-300 mt-1">
                Manage the allowed Scent + Size options used by Shipping orders.
            </div>

            <div class="mt-4">
                <livewire:admin.catalog />
            </div>
        </div>

        <div class="rounded-lg border border-dashed border-zinc-300 dark:border-zinc-600 p-6 text-sm">
            🚧 Planned:
            <ul class="list-disc ml-5 mt-2 space-y-1">
                <li>User roles</li>
                <li>Shopify store connections</li>
                <li>System settings</li>
            </ul>
        </div>
    </div>
</x-app-layout>
