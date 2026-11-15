<x-layouts::app :title="__('Explore Everbranch')">
    <main class="eb-workspace-guide">
        @include('onboarding.partials.guide-nav', ['active' => 'explore'])
        @include('onboarding.partials.trial-status', ['showTrialPreview' => true])
        <h1>Explore Everbranch</h1>
        @php
            $privateTrialGuide = ($trial['registered_trial'] ?? false) && ($trial['state'] ?? '') !== 'paid';
        @endphp
        <div class="eb-builder-branches">
            @foreach(($catalog['modules'] ?? []) as $module)
                @php
                    $state = (array) ($module['module_state'] ?? []);
                    $moduleHref = $privateTrialGuide
                        ? app(\App\Services\Navigation\UnifiedAppNavigationService::class)->trialReadHref((string) ($state['cta_href'] ?? ''), $tenant, request())
                        : ($state['cta_href'] ?? null);
                @endphp
                <article>
                    <h2>{{ $module['display_name'] }}</h2>
                    <p>{{ $module['short_description'] }}</p>
                    <p>{{ $state['reason_label'] ?? 'Setup required' }}</p>
                    @if(filled($moduleHref))
                        <a href="{{ $moduleHref }}">{{ $state['cta_label'] ?? 'View branch' }}</a>
                    @elseif($privateTrialGuide)
                        <span>After activation</span>
                    @else
                        <a href="{{ route('app.start', ['tenant' => $tenant->slug]) }}">View setup</a>
                    @endif
                </article>
            @endforeach
        </div>
    </main>
</x-layouts::app>
