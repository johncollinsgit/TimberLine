@php
    $presentations = app(\App\Services\Tenancy\WorkspacePresentationService::class);
    $presentation = $presentations->forTenant($tenant);
    $canEditPresentation = $presentations->canEdit(auth()->user());
@endphp
<section class="eb-landlord-card" aria-labelledby="presentation-heading">
    <div class="mb-5">
        <h2 id="presentation-heading">Client workspace template</h2>
        <p class="mt-2 text-sm text-zinc-600">Choose the presentation that fits this client's work. Their features and access follow their existing setup.</p>
    </div>
    @if($canEditPresentation)
        <form method="POST" action="{{ route('landlord.tenants.presentation.update', $tenant) }}" class="grid gap-4 md:grid-cols-2">
            @csrf @method('PUT')
            <label>Client template
                <select name="template" aria-label="Client template" class="mt-2 w-full">
                    @foreach(config('workspace_presentation.templates') as $key => $template)
                        <option value="{{ $key }}" @selected(old('template', $presentation['key']) === $key)>{{ $template['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>Workspace introduction
                <input name="summary" maxlength="190" value="{{ old('summary', $presentation['summary']) }}" class="mt-2 w-full" placeholder="A short introduction for this client's workspace" />
            </label>
            <div class="flex flex-wrap items-center gap-4 md:col-span-2">
                <button class="fb-btn fb-btn-primary">Save presentation</button>
                <p class="text-sm text-zinc-600">Only the configured Everbranch owner can change these templates.</p>
            </div>
        </form>
    @else
        <p><strong>{{ $presentation['label'] }}</strong></p>
        <p class="mt-2 text-sm text-zinc-600">Template changes are restricted to the Everbranch owner.</p>
    @endif
</section>
