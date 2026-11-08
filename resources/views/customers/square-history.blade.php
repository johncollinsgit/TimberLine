<x-layouts::app :title="'Square purchase history'">
    <main class="mx-auto max-w-5xl space-y-5 p-5">
        <a class="inline-flex min-h-11 items-center py-2" href="{{ route('customers.profile', ['tenant' => $tenant->slug, 'profile' => $profile->id]) }}">Back to customer profile</a>
        <h1 class="text-2xl font-semibold">{{ trim($profile->first_name.' '.$profile->last_name) ?: 'Customer' }} · Square history</h1>
        @if($historyError)
            <p class="rounded border p-4" role="status">{{ $historyError }}</p>
        @else
            @include('customers.square-source', ['squareHistoryOnly' => true])
        @endif
        <nav class="flex flex-wrap gap-5" aria-label="Cached source history pages">
            <a class="inline-flex min-h-11 items-center rounded border px-3 py-2" href="{{ $restartUrl }}">Restart {{ $historyKind === 'square_order' ? 'orders' : 'payments' }} history</a>
            <a class="inline-flex min-h-11 items-center rounded border px-3 py-2" href="{{ route('customers.square.history', ['tenant' => $tenant->slug, 'profile' => $profile->id, 'kind' => $historyKind === 'square_order' ? 'square_payment' : 'square_order']) }}">View cached {{ $historyKind === 'square_order' ? 'payments' : 'orders' }}</a>
        </nav>
    </main>
</x-layouts::app>
