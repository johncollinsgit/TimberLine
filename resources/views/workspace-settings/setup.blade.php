@php
    $labels = ['complete' => 'Complete', 'in_progress' => 'In progress', 'not_started' => 'Not started', 'unknown' => 'Unable to verify', 'verification_pending' => 'Verification pending', 'optional' => 'Optional'];
    $required = collect($guide['steps'])->where('required', true);
    $optional = collect($guide['steps'])->where('required', false);
@endphp
<x-layouts::app title="Your setup guide">
<div class="ws-settings">
    @include('workspace-settings.header', ['heading' => 'A good place to start', 'description' => 'A few useful steps for your organization. Your existing work counts, and you can return anytime.'])
    <p><a href="{{ route('workspace-settings.index', ['tenant' => $tenant->slug]) }}">← Settings</a></p>
    <div class="ws-card">
        @if($guide['required_count'])
            <h2>{{ $guide['required_complete'] }} of {{ $guide['required_count'] }} starting steps complete</h2>
            <progress class="ws-progress" value="{{ $guide['required_complete'] }}" max="{{ $guide['required_count'] }}" aria-label="Starting steps complete"></progress>
        @else
            <h2>Your work, at your pace</h2><p class="ws-muted">Your owner manages organization setup. Your account settings and available branches are ready to browse.</p>
        @endif
        @if($guide['context']['confirmed_at'] ?? null)<p class="ws-muted">{{ config('workspace_setup.categories.'.$guide['context']['category'], 'Your organization') }}@if(filled($guide['context']['specialty'] ?? null)) · {{ $guide['context']['specialty'] }}@endif · {{ config('workspace_setup.models.'.$guide['context']['model'], 'Your business') }}</p>@endif
        @if($guide['next'])<a class="ws-button" href="{{ $guide['next']['url'] }}">{{ $guide['next']['label'] }} →</a>@else<p class="ws-muted">Keep working in your available branches. There’s no need to restart onboarding.</p>@endif
    </div>
    @foreach(['Starting steps' => $required, 'Optional improvements' => $optional] as $heading => $steps)
        @if($steps->isNotEmpty())<h2 class="my-5">{{ $heading }}</h2>@endif
        @foreach($steps as $step)
            <section class="ws-card ws-guide-row">
                <div><span class="ws-state @if(in_array($step['state'], ['unknown', 'verification_pending'])) attention @endif">{{ $step['dismissed'] ? 'Dismissed for you · not completed' : $labels[$step['state']] }}</span><h2>{{ $step['label'] }}</h2><p class="ws-muted">{{ $step['description'] }}</p>@if($step['note'])<p class="ws-muted">{{ $step['note'] }}</p>@endif</div>
                @if($step['url'])<a class="ws-button secondary" href="{{ $step['url'] }}">{{ $step['state'] === 'complete' ? 'View your work' : 'Open' }}</a>@endif
            </section>
        @endforeach
    @endforeach
    <details><summary>Control your setup guidance</summary>
        <p class="ws-muted">These choices affect only your reminders in {{ $tenant->name }}. They never mark organization work complete or affect a teammate’s progress.</p>
        <form method="post" action="{{ route('workspace-settings.preferences', ['tenant' => $tenant->slug]) }}">@csrf @method('PUT')
            <input type="hidden" name="hidden" value="0"><label class="ws-choice"><input type="checkbox" name="hidden" value="1" @checked($guide['guidance_hidden'])>Hide the setup reminder on my home screen</label>
            @foreach($optional as $step)<label class="ws-choice"><input type="checkbox" name="dismissed_steps[]" value="{{ $step['id'] }}" @checked($step['dismissed'])>Dismiss {{ $step['label'] }} for me</label>@endforeach
            @if($optional->isNotEmpty())<input type="hidden" name="dismissed_steps_present" value="1">@endif
            <div class="ws-actions"><button class="ws-button" type="submit">Save my guidance preferences</button></div>
        </form>
    </details>
    <div class="ws-actions"><a class="ws-button secondary" href="{{ route('marketing.modules', ['tenant' => $tenant->slug, 'tab' => 'branches']) }}">Your branches</a><a href="{{ route('profile.edit', ['tenant' => $tenant->slug]) }}">My account</a></div>
</div>
@vite('resources/js/workspace-settings.js')
</x-layouts::app>
