<x-layouts::app :title="'Customers'">
    <main class="mx-auto max-w-6xl space-y-5 p-5">
        <h1 class="text-2xl font-semibold">{{ $tenant->name }} · Customers</h1>
        <p>Find a customer across online and in-person purchases, rewards and events.</p>
        @if($canManageSquare)<a class="inline-block rounded border p-2" href="{{ route('customers.square.index', ['tenant' => $tenant->slug]) }}">Square connection and import</a>@endif
        <form method="GET" action="{{ route('customers.directory') }}" class="grid gap-3 sm:grid-cols-3">
            <input type="hidden" name="tenant" value="{{ $tenant->slug }}">
            <label>Name, phone or email<input class="block w-full rounded border p-2" name="query" value="{{ $query['query'] ?? '' }}" minlength="2"></label>
            <label>Event<select class="block w-full rounded border p-2" name="event_id"><option value="">All events and channels</option>@foreach($events as $event)<option value="{{ $event['id'] }}" @selected((int)($query['event_id'] ?? 0) === $event['id'])>{{ $event['name'] }} · {{ $event['starts_on'] }}</option>@endforeach</select></label>
            <button type="submit" class="rounded border p-2">Find customers</button>
        </form>
        @if($results)
            <div class="grid gap-3 sm:grid-cols-2">@forelse($results['customers'] as $customer)
                <a class="rounded border p-4" href="{{ route('customers.profile', ['profile' => $customer['id'], 'tenant' => $tenant->slug]) }}"><strong>{{ $customer['name'] }}</strong><p>{{ $customer['phone'] }} · {{ $customer['email'] }}</p>
                @if($context['relations'][$customer['id']]['captured'] ?? false)<small>Contact captured at event</small>@endif
                @if($context['relations'][$customer['id']]['purchased'] ?? false)<small>Confirmed linked event purchase</small>@endif</a>
            @empty<p>No matching customers.</p>@endforelse</div>
            @if(($results['page'] ?? 1) > 1)<a href="{{ route('customers.directory', array_merge($query, ['tenant' => $tenant->slug, 'page' => $results['page'] - 1])) }}">Previous customers</a>@endif
            @if($results['has_more'])<a href="{{ route('customers.directory', array_merge($query, ['tenant' => $tenant->slug, 'page' => $results['page'] + 1])) }}">Next customers</a>@endif
        @endif
        @foreach($context['review_items'] ?? [] as $issue)<p>{{ $issue }}</p>@endforeach
    </main>
</x-layouts::app>
