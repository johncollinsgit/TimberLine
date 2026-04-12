<x-layouts::app :title="$currentSection['label']">
 @php
 $payload = is_array($moduleStorePayload ?? null) ? $moduleStorePayload : [];
 $currentPlan = is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : ['label' => 'Unknown', 'operating_mode' => 'direct'];
 $sectionsPayload = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
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
 <h2 class="mt-2 text-xl font-semibold text-[var(--fb-text-primary)] sm:text-2xl">Tenant-aware module catalog</h2>
 <p class="mt-2 max-w-4xl text-sm text-[var(--fb-text-secondary)]">This surface is driven by the canonical module catalog and tenant entitlement resolver, not scattered plan checks.</p>
 </div>
 <div class="fb-chip fb-chip--quiet">
     Plan {{ $currentPlan['label'] ?? 'Unknown' }} · mode {{ strtoupper((string) ($currentPlan['operating_mode'] ?? 'direct')) }}
 </div>
 </div>
 </section>

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
 <article class="fb-state {{ $isFocused ? 'is-focused' : '' }}">
 <div class="flex items-start justify-between gap-3">
 <div>
 <h3 class="text-base font-semibold text-[var(--fb-text-primary)]">{{ $module['display_name'] ?? $moduleKey }}</h3>
 <p class="mt-2 text-sm leading-6 text-[var(--fb-text-secondary)]">{{ $module['description'] ?? '' }}</p>
 </div>
 <x-tenancy.module-state-badge :module-state="$moduleState" size="sm" />
 </div>

 <div class="mt-3 flex flex-wrap gap-2 text-xs">
 <span class="fb-module-pill">{{ strtoupper(str_replace('_', ' ', (string) ($module['billing_mode'] ?? 'unavailable'))) }}</span>
 @foreach((array) ($module['included_in_plans'] ?? []) as $planKey)
 <span class="fb-module-pill">{{ strtoupper((string) $planKey) }}</span>
 @endforeach
 </div>

 <p class="mt-3 text-sm leading-6 text-[var(--fb-text-secondary)]">{{ $moduleState['reason_description'] ?? $moduleState['description'] ?? '' }}</p>

 <div class="mt-4 flex flex-wrap gap-2">
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
 </div>
 </article>
 @endforeach
 </div>
 </div>
 </section>
 @endforeach
 </div>
</x-layouts::app>
