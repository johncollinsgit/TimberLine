@if(($missingShippingWeights ?? collect())->isNotEmpty())
    <div role="status" style="max-width:1100px;margin:24px auto;padding:16px;border:1px solid #e5ca8b;border-radius:12px;background:#fff9eb;color:#654715">
        <strong>Some items are not available for shipping yet.</strong>
        <ul style="margin:8px 0;padding-left:20px">
            @foreach($missingShippingWeights as $item)
                <li>{{ $item->variant->product->title }}@if(!in_array($item->variant->title, ['Default', 'Default Title', ''], true)) ({{ $item->variant->title }})@endif</li>
            @endforeach
        </ul>
        <p>Choose another delivery option, if offered, or remove these items to continue with shipping.</p>
    </div>
@endif
