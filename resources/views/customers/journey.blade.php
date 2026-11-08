<section class="space-y-4 rounded border p-4" aria-label="Customer event journey">
    <h2 class="text-xl font-semibold">Event journey and repeat purchases</h2>
    <p>First recorded event contact: <strong>{{ data_get($journey, 'first_recorded_capture.event.name', 'Not recorded') }}</strong> · {{ data_get($journey, 'first_recorded_capture.captured_at', 'Date unknown') }}.</p>
    <p>Customer at acquisition: <strong>{{ ucfirst($journey['customer_classification']) }}</strong>. {{ $journey['acquisition']['first_customer_acquisition_confirmed'] ? 'New-customer classification has reviewed source evidence.' : 'First recorded contact does not prove this was the customer’s first purchase.' }}</p>
    <p>Later membership join: {{ $journey['membership_conversion']['observed_join_after_capture_at'] ?? 'Not established from original join records' }}. A later join does not establish that the event caused it.</p>
    <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Window after first contact</th><th>Observed paid value after refunds (includes tax)</th><th>Observed repeat purchases</th><th>Coverage</th></tr></thead><tbody>
        @foreach($journey['windows'] as $window)
            <tr><td class="py-3">{{ $window['days'] }} days</td><td>
                @forelse($window['currencies'] as $currency)
                    <p>{{ $currency['currency'] }} {{ $currency['net_paid_minor'] === null ? 'Unknown' : number_format($currency['net_paid_minor'] / 100, 2) }}</p>
                    @if($currency['net_paid_minor'] === null)<small>Known linked value: {{ $currency['currency'] }} {{ number_format($currency['known_net_paid_minor'] / 100, 2) }}; some refunds are unknown.</small>@endif
                @empty<p>No linked paid value observed; unconnected history is unknown.</p>@endforelse
            </td><td>@foreach($window['currencies'] as $currency)<p>{{ $currency['currency'] }}: {{ $currency['observed_repeat_purchase_count'] }} repeat purchases</p>@endforeach</td><td>{{ $window['time_matured'] ? 'Time window elapsed' : 'Time window still open' }} · Linked history incomplete</td></tr>
        @endforeach
    </tbody></table></div>
    <p>{{ $journey['basis'] }}</p><p>Customer contribution margin remains unknown until original cost, shipping, processing and reward costs are connected.</p>
    <details><summary>Purchase events and source coverage</summary>
        <ul class="list-disc space-y-2 pl-5">@foreach($journey['purchase_journey'] as $purchase)<li>{{ $purchase['occurred_at'] ?? 'Date unknown' }} · {{ str($purchase['channel'])->replace('_', ' ') }} · {{ data_get($purchase, 'purchase_event.event.name', 'Purchase event not established') }} · {{ $purchase['confirmed_purchase'] ? 'Confirmed original purchase' : 'Unverified source record' }}</li>@endforeach</ul>
        <ul class="list-disc pl-5">@foreach($journey['issues'] as $issue)<li>{{ str($issue)->replace('_', ' ')->ucfirst() }}</li>@endforeach</ul>
    </details>
    @if($canReviewAttribution && $journey['review_options']['links'])
        <details><summary>Review event attribution evidence</summary>
            <p>Record an original source reference. Reviewing a link does not change the purchase, payment, rewards or marketing permission.</p>
            @foreach($journey['review_options']['links'] as $link)
                @foreach(array_unique(array_merge($link['allowed_kinds'], $link['allowed_clear_kinds'] ?? [])) as $kind)
                    <form method="POST" action="{{ route('customers.event-attribution', ['tenant' => $tenant->slug, 'profile' => $profile->id]) }}" class="my-3 space-y-2 rounded border p-3">
                        @csrf
                        <h3 class="font-semibold">{{ str($kind)->replace('_', ' ')->title() }} · {{ $link['source_type'] }} {{ $link['source_id'] }}</h3>
                        <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="source_link_id" value="{{ $link['source_link_id'] }}"><input type="hidden" name="expected_revision" value="{{ $link['source_link_revision'] }}"><input type="hidden" name="kind" value="{{ $kind }}">
                        @if($kind === 'acquisition')
                            <label class="block">Reviewed classification<select class="block rounded border p-2" name="classification"><option value="unknown">Unknown / clear classification</option><option value="new">New customer</option><option value="returning">Returning customer</option></select></label>
                            <label class="block"><input type="checkbox" name="prehistory_reviewed" value="1"> I reviewed prior customer purchase history through this recorded capture.</label>
                        @else
                            <label class="block">Reviewed event<select class="block rounded border p-2" name="event_id"><option value="">Select event</option>@foreach($journey['review_options']['events'] as $event)<option value="{{ $event['id'] }}">{{ $event['name'] }} · {{ $event['starts_on'] }}</option>@endforeach</select></label>
                            @if($kind === 'membership_conversion')<input type="hidden" name="joined_at" value="{{ $link['original_join_at'] }}"><p>Original recorded join: {{ $link['original_join_at'] }}</p>@endif
                        @endif
                        <label class="block">Source reference<input class="block w-full rounded border p-2" name="evidence_reference" minlength="3" maxlength="500" required></label>
                        <label class="block">Review explanation<textarea class="block w-full rounded border p-2" name="reason" minlength="8" maxlength="1000" required></textarea></label>
                        @if(in_array($kind, $link['allowed_clear_kinds'] ?? [], true))<label class="block"><input type="checkbox" name="clear" value="1"> Clear the existing reviewed relationship</label>@endif
                        <button class="rounded border p-2">Save reviewed evidence</button>
                    </form>
                @endforeach
            @endforeach
        </details>
    @endif
</section>
