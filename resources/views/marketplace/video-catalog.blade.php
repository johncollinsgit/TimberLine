@extends('marketplace.layout')
@section('title', 'Video shopping catalog')
@section('content')
<div class="page-shell">
    <a href="{{ route('marketplace.public.maker.dashboard', $maker->id) }}">← Maker dashboard</a>
    <h1>Video shopping catalog</h1>
    <p>Choose the products viewers can shop from {{ $maker->name }}’s videos. Orders placed in your own store carry a 0% referral fee during the pilot. Your store handles payment, fulfillment, and refunds.</p>
    @if($connectionMessage)<p role="alert">{{ $connectionMessage }}</p>@endif
    @if($invited)
    <section class="panel">
        <h2>Connect Shopify</h2>
        @if($shopifyReady)
            <form method="post" action="{{ route('marketplace.public.maker.video-catalog.start', $maker->id) }}" class="form-grid">
                @csrf
                <label>Shopify store domain<input name="shop" autocomplete="off" placeholder="your-store.myshopify.com" required></label>
                <label><input type="checkbox" name="consent" value="1" required> Share published products with {{ $maker->name }}. I’ll choose the variants after Shopify approves access.</label>
                <button class="button">Continue to Shopify</button>
            </form>
        @else
            <p>Shopify connections will open when the marketplace operator finishes app setup.</p>
        @endif
    </section>
    <section class="panel">
        <h2>Connect Everbranch</h2>
        @if($maker->tenant_id)
            <p>A workspace owner or administrator can select individual variants in the connected catalog.</p>
            <a class="button secondary" href="{{ rtrim(config('app.url'), '/') }}/marketplace/workspaces/{{ $maker->id }}">Open workspace catalog</a>
        @else
            <form method="post" action="{{ route('marketplace.public.maker.connect', $maker->id) }}">@csrf<button class="button secondary">Connect a workspace</button></form>
        @endif
    </section>
    @else
        <p>New store connections are open to invited pilot makers.</p>
    @endif
    @foreach($sources as $source)
        <section class="panel">
            <h2>{{ $source->provider === 'shopify' ? $source->reference : 'Everbranch store' }}</h2>
            @if($source->provider === 'shopify')
                <a href="{{ route('marketplace.public.maker.video-catalog', ['id' => $maker->id, 'source' => $source->id]) }}">Browse published variants</a>
            @endif
            <ul>
                @forelse($source->selections ?? [] as $id => $selection)
                    <li><strong>{{ $selection['label'] }}</strong> · {{ $selection['variant'] }}
                        <form method="post" action="{{ route('marketplace.public.maker.video-catalog.select', [$maker->id, $source->id]) }}">@csrf
                            <input type="hidden" name="variant" value="{{ $id }}"><button class="button compact secondary" name="action" value="remove">Remove variant</button>
                        </form>
                    </li>
                @empty
                    <li>No variants selected.</li>
                @endforelse
            </ul>
            <form method="post" action="{{ route('marketplace.public.maker.video-catalog.disconnect', [$maker->id, $source->id]) }}">
                @csrf @method('DELETE') <button class="button compact secondary">Disconnect store</button>
            </form>
        </section>
    @endforeach
    @if($browse)
        <section class="panel">
            <h2>Published variants from {{ $browse['source']->reference }}</h2>
            <ul>
                @forelse($browse['variants'] as $variant)
                    <li>{{ $variant['label'] }} · {{ $variant['variant'] }}{{ $variant['available'] ? '' : ' · Sold out' }}
                        @unless(isset($browse['source']->selections[$variant['id']]))
                            <form method="post" action="{{ route('marketplace.public.maker.video-catalog.select', [$maker->id, $browse['source']->id]) }}">
                                @csrf <input type="hidden" name="variant" value="{{ $variant['id'] }}"><button class="button compact" name="action" value="add">Add to video catalog</button>
                            </form>
                        @endunless
                    </li>
                @empty
                    <li>No published variants on this page.</li>
                @endforelse
            </ul>
            @if($browse['next'])<a href="{{ route('marketplace.public.maker.video-catalog', ['id' => $maker->id, 'source' => $browse['source']->id, 'after' => $browse['next']]) }}">Next variants →</a>@endif
        </section>
    @endif
</div>
@endsection
