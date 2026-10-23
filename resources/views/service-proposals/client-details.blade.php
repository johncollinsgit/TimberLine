<section class="card"><h2>Working together, with clear boundaries.</h2>
@foreach(['rollout'=>'A department at a time','activation'=>'Setup and acceptance','support'=>'Human support','travel'=>'Travel','usage'=>'Usage without markup'] as $key=>$label)<h3>{{ $label }}</h3><p>{{ $details[$key] }}</p>@endforeach
</section>
