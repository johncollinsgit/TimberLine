<x-layouts::app :title="__('Explore Everbranch')">
    <main class="eb-workspace-guide">
        @include('onboarding.partials.guide-nav', ['active' => 'explore'])
        <h1>Explore Everbranch</h1>
        <div class="eb-builder-branches">
            @foreach(($catalog['modules'] ?? []) as $module)
                @php($state = (array) ($module['module_state'] ?? []))
                <article>
                    <h2>{{ $module['display_name'] }}</h2>
                    <p>{{ $module['short_description'] }}</p>
                    <p>{{ $state['reason_label'] ?? 'Setup required' }}</p>
                    @if(filled($state['cta_href'] ?? null))
                        <a href="{{ $state['cta_href'] }}">{{ $state['cta_label'] ?? 'View branch' }}</a>
                    @else
                        <a href="{{ route('app.start', ['tenant' => $tenant->slug]) }}">View setup</a>
                    @endif
                </article>
            @endforeach
        </div>
    </main>
</x-layouts::app>
