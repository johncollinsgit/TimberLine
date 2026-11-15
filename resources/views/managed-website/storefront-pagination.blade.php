@if($products->hasPages())
<nav class="retail-pagination" aria-label="Product pages">
    @if($products->onFirstPage())<span aria-disabled="true">Previous</span>@else<a href="{{ $products->previousPageUrl() }}">Previous</a>@endif
    <span aria-current="page">Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>
    @if($products->hasMorePages())<a href="{{ $products->nextPageUrl() }}">Next</a>@else<span aria-disabled="true">Next</span>@endif
</nav>
@endif
