<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Event customers · Everbranch</title>
<style>body{font:16px system-ui;background:#f4f7f5;color:#193b2b;margin:0}main{max-width:1100px;margin:auto;padding:24px}section{background:white;border:1px solid #d8e3dc;border-radius:16px;padding:24px;margin:20px 0}.scroll{overflow:auto}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px;border-bottom:1px solid #e5ebe6}form{display:flex;gap:12px;flex-wrap:wrap}input,button{padding:10px}small{color:#526c5d}</style></head><body><main>
<h1>Event customers</h1><p>Follow customers first captured at an event into later online purchases and verified memberships.</p>
<form><input type="hidden" name="tenant" value="{{ $tenantSlug }}"><label>Captured from <input type="date" name="from" value="{{ $report['from'] }}" required></label><label>Through <input type="date" name="to" value="{{ $report['to'] }}" required></label><button>Update report</button></form>
<p><small>{{ $report['basis'] }}</small></p>
@if($report['issues'])<p>Some linked history is incomplete. These counts reflect the records available.</p>@endif
@forelse($report['events'] as $event)<section><h2>{{ $event['event_name'] }}</h2>
<p>{{ $event['new_customer_records'] }} new customer records · {{ $event['online_customers_after_event'] }} later online customers · {{ $event['online_orders_after_event'] }} online purchases · ${{ number_format($event['online_net_minor']/100,2) }} after recorded refunds</p>
<p>{{ $event['memberships_after_event'] }} verified membership joins after capture. @if($event['membership_timing_unknown']) Membership timing is unavailable for {{ $event['membership_timing_unknown'] }} customers. @endif</p>
@if($event['existing_before_event'])<p>{{ $event['existing_before_event'] }} captured records have an earlier linked purchase and are excluded from the new-customer cohort.</p>@endif
<div class="scroll"><table><thead><tr><th>Customer</th><th>Captured</th><th>Later online orders</th><th>Online net</th><th>Membership after event</th></tr></thead><tbody>
@foreach($event['customers'] as $customer)<tr><td>{{ $customer['name'] }}</td><td>{{ substr($customer['captured_at'],0,10) }}</td><td>{{ $customer['online_orders_after_event'] }}</td><td>${{ number_format($customer['online_net_minor']/100,2) }}</td><td>{{ $customer['membership_after_event'] === null ? 'Timing unavailable' : ($customer['membership_after_event'] ? 'Yes' : 'No recorded join') }}</td></tr>@endforeach
</tbody></table></div></section>@empty<section>No event acquisition records are available for this date range. Historical event attribution has not been inferred.</section>@endforelse
</main></body></html>
