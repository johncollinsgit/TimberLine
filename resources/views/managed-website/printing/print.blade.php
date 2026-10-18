@php
    $profile = $attempt['profile'];
    [$paperWidth, $paperHeight] = \App\Services\ManagedWebsite\WebsiteLabelPdf::LAYOUTS[$profile['layout']];
    if ($profile['orientation'] === 'landscape') { [$paperWidth, $paperHeight] = [$paperHeight, $paperWidth]; }
    $thermal = $profile['layout'] === 'thermal_4x6';
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Print shipping label</title>
<style>
    *{box-sizing:border-box}body{margin:0;background:#f4f5f2;color:#17251e;font-family:Arial,sans-serif}.container{max-width:880px;margin:0 auto;padding:28px 18px}.controls{margin-bottom:22px}.controls h1{margin:0;font-size:27px}.controls p{max-width:720px;font-size:14px;line-height:1.6;color:#4b5a52}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}button,a.button{border:1px solid #afbbb2;border-radius:6px;background:white;padding:11px 15px;font-size:14px;color:#143e2b;text-decoration:none;cursor:pointer}button.primary{color:white;background:#184d36;border-color:#184d36}form{display:contents}.label-page{background:white;border:1px solid #d5ddd7;max-width:420px;padding:18px}.label-image{display:block;width:288px;height:auto;max-width:100%;margin:auto}.notice{padding:12px 14px;background:#e6f0e8;border-radius:6px;font-size:14px;line-height:1.6}.result-buttons{margin-top:18px}.result-buttons h2{font-size:18px}.hint{font-size:12px!important}.controls a{color:#184d36}
    @page{size:{{ number_format($paperWidth / 72, 6, '.', '') }}in {{ number_format($paperHeight / 72, 6, '.', '') }}in;margin:0}
    @media print{html,body{margin:0;padding:0;background:white}.container{margin:0;padding:0;max-width:none}.controls{display:none!important}.label-page{position:relative;width:{{ number_format($paperWidth / 72, 6, '.', '') }}in;height:{{ number_format($paperHeight / 72, 6, '.', '') }}in;max-width:none;padding:0;border:0;overflow:hidden;break-after:avoid}.label-image{position:absolute;width:4in;height:6in;max-width:none;margin:0;left:{{ $thermal ? '0' : '0.5in' }};top:{{ $thermal ? '0' : '0.5in' }};@if($thermal && $profile['orientation'] === 'landscape')transform-origin:0 0;transform:translateX(6in) rotate(90deg);@endif}}
</style></head><body><main class="container">
    <section class="controls"><h1>Print the purchased label</h1><p><strong>{{ $profile['printer_name'] }}</strong> · {{ $profile['layout'] === 'thermal_4x6' ? '4 x 6 inch thermal' : strtoupper($profile['layout']) }} · {{ ucfirst($profile['orientation']) }} · {{ $profile['os'] === 'mac' ? 'Mac' : 'Windows' }}</p><p>Choose this printer and matching paper in your print dialog. Use <strong>Actual Size / 100%</strong>, turn off Fit or Shrink, and turn off browser headers/footers. Download the PDF if your browser does not preserve the layout.</p><p class="hint">This is the same purchased tracking number for the same package. Printing again does not buy postage. A browser cannot confirm printer output.</p>
        @if($result)<p class="notice">Result already recorded: {{ str($result->status)->headline() }}. Return to the label to create a new reprint request.</p>@else<div class="actions"><button id="open-print-dialog" type="button" class="primary">Open print dialog</button><a class="button" href="{{ route('managed-website.labels.download', ['shipment' => $shipment, 'layout' => $profile['layout'], 'orientation' => $profile['orientation']]) }}">Download PDF</a></div><div class="result-buttons"><h2>Did the label reach paper correctly?</h2><p id="print-dialog-status">Confirm only after checking the full address, barcode, and label size on paper.</p><div class="actions">@foreach(['operator_confirmed' => 'Yes, printed correctly', 'failed' => 'Printer or output problem', 'cancelled' => 'Cancel this request'] as $status => $label)<form method="POST" action="{{ route('managed-website.labels.print-result', ['shipment' => $shipment, 'requestId' => $requestId]) }}">@csrf<input type="hidden" name="result" value="{{ $status }}"><button type="submit">{{ $label }}</button></form>@endforeach</div></div>@endif
        <p><a href="{{ route('managed-website.labels.show', $shipment) }}">Return to label and print history</a></p>
    </section>
    <div class="label-page"><img class="label-image" src="{{ route('managed-website.labels.image', ['shipment' => $shipment, 'requestId' => $requestId]) }}" alt="Purchased shipping label"></div>
</main><script>
    document.getElementById('open-print-dialog')?.addEventListener('click', () => {
        const image = document.querySelector('.label-image');
        if (!image.complete || !image.naturalWidth) { document.getElementById('print-dialog-status').textContent = 'Wait for the label image to finish loading, or download the PDF.'; return; }
        window.print();
    });
    window.addEventListener('afterprint', () => {
        const status = document.getElementById('print-dialog-status');
        if (status) status.textContent = 'The print dialog closed. Check the paper, then record the result below. No result has been recorded automatically.';
    });
</script></body></html>
