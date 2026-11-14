@php
    $guideModules = collect((array) data_get($catalog ?? [], 'modules', []))->keyBy('key');
    $guideGroups = [
        'customers' => ['label' => 'Customers', 'photo' => 'retail', 'title' => 'Know your people.', 'summary' => 'Keep conversations, purchases, and follow-ups together.', 'keys' => ['customers', 'lead_capture', 'messaging', 'rewards']],
        'work' => ['label' => 'Work', 'photo' => 'projects', 'title' => 'Keep work moving.', 'summary' => 'Plan the day and give every next step a home.', 'keys' => ['field_service', 'class_scheduling', 'documents', 'everbranch_billing']],
        'growth' => ['label' => 'Growth', 'photo' => 'studios', 'title' => 'Give people a reason.', 'summary' => 'Build thoughtful follow-ups around the customers you know.', 'keys' => ['campaigns', 'reviews', 'birthdays', 'workflow_automations']],
        'websites' => ['label' => 'Websites', 'photo' => 'field-service-exterior', 'title' => 'A home for your business.', 'summary' => 'Create your pages, preview your design, and publish when ready.', 'keys' => ['managed_website', 'lead_capture', 'everbranch_billing', 'integrations']],
    ];
@endphp
<section id="explore" class="eb-guide" aria-labelledby="guide-title" data-capability-guide>
    <div class="eb-studio-container">
        <div class="eb-guide__heading">
            <h2 id="guide-title">Find your next branch.</h2>
            <a href="{{ route('platform.modules.explore') }}">All branches <span aria-hidden="true">↗</span></a>
        </div>
        <nav class="eb-guide__tabs" aria-label="Explore Everbranch">
            @foreach($guideGroups as $key => $group)
                <a id="guide-tab-{{ $key }}" href="#guide-panel-{{ $key }}" data-guide-tab="{{ $key }}">{{ $group['label'] }}</a>
            @endforeach
        </nav>
        <details class="eb-motion-preview"><summary>See how it connects <span aria-hidden="true">↗</span></summary><video controls playsinline preload="none" width="960" height="540" poster="{{ asset('media/everbranch-branches-motion-poster.png') }}" aria-label="An animation showing Customers, Work, Growth, and Website branches connected to Everbranch"><source src="{{ asset('media/everbranch-branches-motion.mp4') }}" type="video/mp4">Your browser does not support this video.</video></details>
        @foreach($guideGroups as $key => $group)
            <section id="guide-panel-{{ $key }}" class="eb-guide__panel" aria-labelledby="guide-tab-{{ $key }}" data-guide-panel="{{ $key }}">
                <div class="eb-guide__photo">
                    <img src="{{ asset('images/public-site/guide-'.$group['photo'].'.webp') }}" width="960" height="800" alt="" loading="lazy" decoding="async">
                    <div><h3>{{ $group['title'] }}</h3><p>{{ $group['summary'] }}</p></div>
                </div>
                <div class="eb-guide__cards">
                    @foreach($group['keys'] as $moduleKey)
                        @if($module = $guideModules->get($moduleKey))
                            <a class="eb-guide__card" href="{{ route('platform.modules.show', ['module' => $moduleKey]) }}">
                                <span class="eb-guide__included">Included</span>
                                <h3>{{ $module['display_name'] }}</h3>
                                <p>{{ $module['short_description'] }}</p>
                                <span class="eb-guide__action">Explore <span aria-hidden="true">↗</span></span>
                            </a>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</section>
