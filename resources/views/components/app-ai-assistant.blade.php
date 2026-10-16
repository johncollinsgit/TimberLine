@props(['tenant'])
<div data-ai-assistant data-base-url="{{ route('ai-branch.index', ['tenant' => $tenant->slug]) }}" data-workspace="{{ $tenant->name }}" data-origin="{{ json_encode(app(\App\Services\AiBranch\AiWorkspaceContext::class)->enabled($tenant) ? app(\App\Services\AiBranch\AiWorkspacePage::class)->descriptor(request()) : []) }}"></div>
