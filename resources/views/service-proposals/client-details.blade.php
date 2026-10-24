<section class="card working-boundaries"><h2>Working together, with clear boundaries.</h2>
<div class="working-boundaries-grid">@foreach(['rollout'=>'A department at a time','activation'=>'Setup and acceptance','support'=>'Human support','travel'=>'Travel','usage'=>'Usage without markup'] as $key=>$label)<article><h3>{{ $label }}</h3><p>{{ $details[$key] }}</p></article>@endforeach</div>
</section>
