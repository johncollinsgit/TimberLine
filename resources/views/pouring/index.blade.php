<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Pouring Room</h1>
    </x-slot>

    <div class="p-6 space-y-4">
        <p class="text-zinc-600 dark:text-zinc-300">
            This is where batches, scents, and production runs will live.
        </p>

        <div class="rounded-lg border border-dashed border-zinc-300 dark:border-zinc-600 p-6 text-sm">
            🚧 Planned features:
            <ul class="list-disc ml-5 mt-2 space-y-1">
                <li>Batch creation</li>
                <li>Pouring schedules</li>
                <li>Yield tracking</li>
            </ul>
        </div>
    </div>
</x-app-layout>
