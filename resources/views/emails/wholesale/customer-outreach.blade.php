<table role="presentation" width="100%" style="width:100%;border-collapse:collapse;background:#F5F2EB;font-family:Arial,Helvetica,sans-serif;color:#263C2E"><tr><td align="center" style="padding:26px 14px">
<table role="presentation" width="600" style="width:100%;max-width:600px;border-collapse:collapse;background:#FFFFFF"><tr><td align="center" bgcolor="{{ $draft['brand']['logo_url'] ? $draft['brand']['primary_color'] : '#FFFFFF' }}" style="padding:28px;border-bottom:1px solid #E6E1D7;background:{{ $draft['brand']['logo_url'] ? $draft['brand']['primary_color'] : '#FFFFFF' }}">
@if($draft['brand']['logo_url'])
<img src="{{ $draft['brand']['logo_url'] }}" alt="{{ $draft['brand']['display_name'] }}" width="220" style="display:block;width:220px;max-width:100%;height:auto;border:0">
@else
<div style="font-family:Georgia,serif;font-size:29px;color:{{ $draft['brand']['primary_color'] }}">{{ $draft['brand']['display_name'] }}</div>
@endif
</td></tr>
<tr><td style="padding:34px 30px 12px">
<p style="margin:0 0 14px;font-size:10px;font-weight:bold;letter-spacing:2.3px;color:{{ $draft['brand']['accent_color'] }}">A THOUGHTFUL RESTOCK</p>
<h1 style="margin:0 0 26px;font-family:Georgia,'Times New Roman',serif;font-size:34px;line-height:1.16;font-weight:normal;color:{{ $draft['brand']['primary_color'] }}">Something good<br>for your shelves.</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7">{{ $draft['greeting'] }}</p>
<div data-outreach-message style="font-size:15px;line-height:1.7;color:#3E4C40">
@foreach(explode("\n\n", $draft['message']) as $paragraph)
<p style="margin:0 0 18px;white-space:pre-wrap">{{ $paragraph }}</p>
@endforeach
</div>
</td></tr>
<tr><td style="padding:10px 30px 0">
<table role="presentation" width="100%" style="width:100%;border-collapse:collapse"><tr><td style="padding:17px 0;border-top:1px solid #E6E1D7;border-bottom:1px solid #E6E1D7">
<p style="margin:0 0 6px;font-size:11px;font-weight:bold;letter-spacing:1px;color:{{ $draft['brand']['accent_color'] }}">FROM YOUR LAST ORDER</p>
<p style="margin:0;font-size:13px;line-height:1.6;color:#687267">{{ $draft['order_number'] }} · {{ $draft['order_date'] }}</p>
</td></tr></table>
</td></tr>
@forelse(array_chunk($draft['products'], 2) as $pair)
<tr><td style="padding:22px 22px 0"><table role="presentation" width="100%" style="width:100%;table-layout:fixed;border-collapse:collapse"><tr>
@foreach($pair as $product)
<td width="50%" valign="top" style="width:50%;padding:0 8px 18px;vertical-align:top">
@if($product['image_url'])
<a href="{{ $product['url'] }}" target="_blank" rel="noopener"><img src="{{ $product['image_url'] }}" alt="{{ $product['image_alt'] }}" width="254" style="display:block;width:100%;max-width:254px;height:auto;border:0;background:#F7F6F1"></a>
@endif
<h2 style="margin:15px 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:21px;line-height:1.3;font-weight:normal;color:{{ $draft['brand']['primary_color'] }}">{{ $product['title'] }}</h2>
<p style="margin:0 0 13px;font-size:11px;line-height:1.6;color:#687267">Last time: {{ $product['previous_order'] }}</p>
<a href="{{ $product['url'] }}" target="_blank" rel="noopener" style="font-size:12px;font-weight:bold;color:{{ $draft['brand']['primary_color'] }};text-decoration:underline">View this favorite →</a>
</td>
@endforeach
@if(count($pair) === 1)<td width="50%" style="width:50%"></td>@endif
</tr></table></td></tr>
@empty
<tr><td style="padding:22px 30px"><p style="margin:0;font-size:13px;line-height:1.7;color:#687267">{{ implode(' · ', $draft['items']) }}</p></td></tr>
@endforelse
@if($draft['storefront_url'])
<tr><td align="center" style="padding:12px 30px 28px"><table role="presentation" style="border-collapse:collapse"><tr><td bgcolor="{{ $draft['brand']['primary_color'] }}" style="background:{{ $draft['brand']['primary_color'] }};border-radius:3px"><a href="{{ $draft['storefront_url'] }}" target="_blank" rel="noopener" style="display:inline-block;padding:16px 27px;font-size:13px;font-weight:bold;letter-spacing:.3px;color:#FFFFFF;text-decoration:none">Explore wholesale favorites</a></td></tr></table></td></tr>
@endif
<tr><td style="padding:8px 30px 34px"><p style="margin:0 0 16px;font-size:14px;line-height:1.7;color:#3E4C40">Want a different scent or size mix? Just reply—I’m happy to help.</p><p style="margin:0;font-size:14px;line-height:1.7;color:#3E4C40">Warmly,<br><strong>{{ $draft['brand']['display_name'] }}</strong></p></td></tr>
<tr><td align="center" style="padding:24px 26px;background:#EFEBE2;border-top:1px solid #E6E1D7;font-size:11px;line-height:1.8;color:#6A7366">
<strong style="font-size:11px;letter-spacing:1px;color:{{ $draft['brand']['primary_color'] }}">{{ $draft['brand']['display_name'] }}</strong><br>
@if($draft['contact_email'])<a href="mailto:{{ $draft['contact_email'] }}" style="color:#6A7366;text-decoration:none">{{ $draft['contact_email'] }}</a><br>@endif
Prefer a different contact for your shop? Reply and let us know.
</td></tr></table>
</td></tr></table>
