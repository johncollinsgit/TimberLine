<x-layouts::app title="Marketing Results">
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
        <header><div class="text-xs font-semibold uppercase text-zinc-500">Reporting</div><h1 class="mt-1 text-2xl font-semibold text-zinc-950">Marketing Results</h1><p class="mt-2 max-w-3xl text-sm text-zinc-600">See which Everbranch messages helped produce orders, with refunds and messaging costs kept visible.</p></header>
        <x-marketing-results-dashboard :results="$marketingResults" />
    </div>
</x-layouts::app>
