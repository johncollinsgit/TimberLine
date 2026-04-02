<div class="inline-flex items-center gap-4 bg-white text-black rounded-lg px-4 py-3 shadow">
    <div class="text-lg font-semibold">
        Count: {{ $count }}
    </div>

    <button
        wire:click="increment"
        class="px-4 py-2 bg-red-600 text-zinc-950 rounded-md hover:bg-red-700"
    >
        Increment
    </button>
</div>
