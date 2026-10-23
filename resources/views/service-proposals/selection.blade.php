<div class="table-wrap"><table><thead><tr><th>Selected service</th><th>Contract duration</th><th class="money">Price · USD</th></tr></thead><tbody>@foreach($selection['lines'] as $line)<tr><td><strong>{{ $line['service_name'] }}</strong><br>{{ $line['label'] }}</td><td>{{ $line['term_months'] ? ($line['term_months']/12).' year(s)' : 'To be agreed' }}</td><td class="money">${{ number_format($line['amount_cents']/100, 2) }}<br><small>{{ $line['cadence'] === 'one_time' ? 'One-time' : 'Per '.$line['cadence'] }}</small></td></tr>@endforeach</tbody></table></div>
@foreach($selection['totals_cents'] as $cadence=>$total)<div class="row"><strong>{{ ['one_time'=>'One-time total','month'=>'Monthly total','year'=>'Yearly total'][$cadence] }}</strong><strong>${{ number_format($total/100, 2) }} USD</strong></div>@endforeach
<p class="notice">{{ $selection['legal_notice'] }}</p>
@foreach($selection['unresolved'] as $unknown)<p class="muted">{{ $unknown }}</p>@endforeach
@if(!empty($selection['scope_notes']))<h3>Included and separately scoped work</h3><p class="body-copy">{{ $selection['scope_notes'] }}</p>@endif
@if(!empty($selection['journey']))@include('service-proposals.journey', ['journey' => $selection['journey'], 'rolloutNote' => \App\Proposals\CommercialSections::from($selection['client_details'] ?? []) !== null ? ($selection['client_details']['rollout'] ?? null) : null])@endif
@if($selection['template'])<h3>Draft master language · unreviewed</h3><p class="body-copy">{{ $selection['template']['text'] }}</p>@endif

@if(!empty($selection['client_details']))
@if(\App\Proposals\CommercialSections::from($selection['client_details']) !== null)
@include('service-proposals.commercial-terms', ['details' => $selection['client_details']])
@else
@include('service-proposals.client-details', ['details' => $selection['client_details']])
@endif
@endif
