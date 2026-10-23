@if(is_string($details['implementation_timeline_heading'] ?? null) && is_string($details['implementation_timeline'] ?? null) && $details['implementation_timeline_heading'] !== '' && $details['implementation_timeline'] !== '')
<section class="card"><h2>{{ $details['implementation_timeline_heading'] }}</h2><p class="body-copy">{{ $details['implementation_timeline'] }}</p></section>
@endif
