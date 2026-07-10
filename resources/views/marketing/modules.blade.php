<x-layouts::app :title="$currentSection['label']">
 @php
 $payload = is_array($moduleStorePayload ?? null) ? $moduleStorePayload : [];
 $currentPlan = is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : ['label' => 'Unknown', 'operating_mode' => 'direct'];
 $sectionsPayload = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
 $blueprintRecommendations = is_array($payload['blueprint_recommendations'] ?? null) ? $payload['blueprint_recommendations'] : [];
 $blueprintContext = is_array($blueprintRecommendations['context'] ?? null) ? $blueprintRecommendations['context'] : [];
 $blueprintRows = array_values((array) ($blueprintRecommendations['rows'] ?? []));
 $blueprintSummary = is_array($blueprintRecommendations['summary'] ?? null) ? $blueprintRecommendations['summary'] : [];
 $storeSections = [
 'active' => 'Active now',
 'available' => 'Available to add',
 'upgrade' => 'Upgrade path',
 'request' => 'Request or sales assist',
 ];
 $focusModule = strtolower(trim((string) request('module', '')));
 @endphp

 <div class="fb-workflow-shell">
 <x-marketing.partials.section-shell
 :section="$currentSection"
 :sections="$sections"
 />

 <section class="fb-workflow-header">
 <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
 <div>
 <div class="fb-eyebrow">Module Store</div>
 <h2 class="mt-2 text-xl font-semibold text-[var(--fb-text-primary)] sm:text-2xl">Workspace feature catalog</h2>
 <p class="mt-2 max-w-4xl text-sm text-[var(--fb-text-secondary)]">Pick the next useful module, see what it does in plain language, and follow the setup steps when you are ready. Viewing a card does not change billing or feature access.</p>
 <div class="mt-4 flex flex-wrap gap-2">
 <a href="{{ route('custom-module-requests.create') }}" class="fb-btn-soft fb-link-soft">Request something custom</a>
 <a href="{{ route('custom-module-requests.index') }}" class="fb-btn-soft fb-link-soft">View custom requests</a>
 </div>
 </div>
 <div class="fb-chip fb-chip--quiet">
     Plan {{ $currentPlan['label'] ?? 'Unknown' }} · setup {{ strtoupper((string) ($currentPlan['operating_mode'] ?? 'direct')) }}
 </div>
 </div>
 </section>

 @if($blueprintRows !== [])
 <section class="fb-panel" data-blueprint-module-recommendations="true">
 <div class="fb-panel-head">
 <div>
 <div class="fb-eyebrow">Setup guidance</div>
 <h2 class="mt-2 text-lg font-semibold text-[var(--fb-text-primary)]">Recommended for your setup</h2>
 <p class="mt-2 max-w-3xl text-sm text-[var(--fb-text-secondary)]">
 {{ $blueprintContext['business_template_label'] ?? 'Workspace' }} profile · {{ $blueprintContext['operating_mode_label'] ?? 'Not sure yet' }} setup. Recommendations are planning guidance only and do not install features, change access, start billing, run imports, or activate future workflows.
 </p>
 </div>
 <div class="flex flex-wrap gap-2">
 <span class="fb-chip fb-chip--quiet">{{ (int) ($blueprintSummary['recommended'] ?? 0) }} recommended</span>
 <span class="fb-chip fb-chip--quiet">{{ (int) ($blueprintSummary['requested'] ?? 0) }} requested</span>
 <span class="fb-chip fb-chip--quiet">{{ (int) ($blueprintSummary['planned_or_future'] ?? 0) }} planned/future</span>
 </div>
 </div>

 @if((bool) ($blueprintContext['is_demo'] ?? false) || (bool) ($blueprintContext['is_sandbox'] ?? false))
 <div class="mx-5 mt-4 fb-state text-sm">
 {{ (bool) ($blueprintContext['is_demo'] ?? false) ? 'Demo tenant context' : 'Sandbox tenant context' }}: module recommendations are for review/testing only.
 </div>
 @endif

 <div class="fb-panel-body">
 <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
 @foreach(array_slice($blueprintRows, 0, 12) as $row)
 <article class="rounded-lg border border-zinc-200 bg-white p-4">
 <div class="flex items-start justify-between gap-3">
 <div>
 <h3 class="text-sm font-semibold text-[var(--fb-text-primary)]">{{ $row['label'] ?? Str::headline((string) ($row['key'] ?? 'module')) }}</h3>
 <p class="mt-1 text-xs leading-5 text-[var(--fb-text-secondary)]">{{ $row['reason'] ?? 'Setup recommendation only.' }}</p>
 </div>
 <span class="fb-module-pill">{{ $row['display_state_label'] ?? 'Planned' }}</span>
 </div>
 @if(! empty($row['requires_future_implementation']))
 <p class="mt-3 text-xs text-[var(--fb-text-secondary)]">Future module family. Not active yet.</p>
 @endif
 </article>
 @endforeach
 </div>
 </div>
 </section>
 @endif

 @foreach($storeSections as $sectionKey => $sectionLabel)
 @php
 $modules = is_array($sectionsPayload[$sectionKey] ?? null) ? $sectionsPayload[$sectionKey] : [];
 @endphp

 @if($modules === [])
 @continue
 @endif

 <section class="fb-panel">
 <div class="fb-panel-head">
 <div>
 <div class="fb-eyebrow">Catalog Section</div>
 <h2 class="mt-2 text-lg font-semibold text-[var(--fb-text-primary)]">{{ $sectionLabel }}</h2>
 </div>
 <span class="fb-chip fb-chip--quiet">{{ count($modules) }} modules</span>
 </div>

 <div class="fb-panel-body">
 <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
 @foreach($modules as $module)
 @php
 $moduleState = is_array($module['module_state'] ?? null) ? $module['module_state'] : [];
 $cta = (string) ($moduleState['cta'] ?? 'none');
 $moduleKey = (string) ($module['module_key'] ?? '');
 $isFocused = $focusModule !== '' && $focusModule === $moduleKey;
 $ctaHref = trim((string) ($moduleState['cta_href'] ?? ''));
 @endphp
 <x-tenancy.module-next-step-card
 :module="$module"
 :module-state="$moduleState"
 :focused="$isFocused"
 >
 @if($cta === 'add')
 <form method="POST" action="{{ route('marketing.modules.activate', ['moduleKey' => $moduleKey]) }}">
 @csrf
 <button type="submit" class="fb-btn-soft fb-btn-accent fb-link-soft">{{ $moduleState['cta_label'] ?? 'Add module' }}</button>
 </form>
 @elseif($cta === 'request')
 <form method="POST" action="{{ route('marketing.modules.request', ['moduleKey' => $moduleKey]) }}">
 @csrf
 <button type="submit" class="fb-btn-soft fb-link-soft">{{ $moduleState['cta_label'] ?? 'Request access' }}</button>
 </form>
 @elseif($cta === 'upgrade' && $ctaHref !== '')
 <a href="{{ $ctaHref }}" class="fb-btn-soft fb-link-soft">{{ $moduleState['cta_label'] ?? 'Upgrade plan' }}</a>
 @elseif($ctaHref !== '')
 <a href="{{ $ctaHref }}" class="fb-btn-soft fb-link-soft">{{ $moduleState['cta_label'] ?? 'Learn more' }}</a>
 @endif
 <a href="{{ route('custom-module-requests.create', ['related_module_key' => $moduleKey]) }}" class="fb-btn-soft fb-link-soft">Request customization</a>
 </x-tenancy.module-next-step-card>
 @endforeach
 </div>
 </div>
 </section>
 @endforeach
 </div>
</x-layouts::app>
