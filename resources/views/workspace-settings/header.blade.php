<header class="ws-header">
    <div><p class="ws-eyebrow">Your workspace</p><h1>{{ $heading }}</h1><p class="ws-muted">{{ $description }}</p></div>
    <span class="ws-context">{{ $tenant->name }}</span>
</header>
@if(session('status'))<div class="ws-notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())
    <div class="ws-notice ws-error" role="alert" tabindex="-1" data-ws-errors><p><strong>Your changes haven’t been saved.</strong></p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
