@if($visualization)
    @once
        <link rel="stylesheet" href="{{ asset('product-visualization/viewer.css') }}">
        <script type="module" src="{{ asset('product-visualization/viewer.js') }}"></script>
    @endonce
    <everbranch-product-viewer data-config="{{ json_encode($visualization, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}"></everbranch-product-viewer>
@endif
