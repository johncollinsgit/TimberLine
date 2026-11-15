<link rel="stylesheet" href="{{ asset('css/website-retail.css') }}">
<form method="GET" action="{{ ($shopUrl ?? route('managed-website.store.index')) }}" role="search" aria-label="Search products" class="retail-browse">
    @if($storePreview ?? false)<input type="hidden" name="tenant" value="{{ $site->tenant->slug }}">@endif
    @if($category !== 'all')<input type="hidden" name="category" value="{{ $category }}">@endif
    <div><label for="retail-search">Search products</label><input id="retail-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Name, details or SKU"></div>
    @if($collections->isNotEmpty())<div><label for="retail-collection">Collection</label><select id="retail-collection" name="collection"><option value="">All collections</option>@foreach($collections as $item)<option value="{{ $item->handle }}" @selected($collection === $item->handle)>{{ $item->title }}</option>@endforeach</select></div>@endif
    <button type="submit">Search</button>
    @if($search !== '' || $collection !== '' || $category !== 'all')<a class="retail-clear" href="{{ ($shopUrl ?? route('managed-website.store.index')) }}">Clear filters</a>@endif
</form>
<p class="retail-browse-status">{{ $products->total() }} {{ str('product')->plural($products->total()) }}@if($selectedCollection) in {{ $selectedCollection->title }}@endif</p>
