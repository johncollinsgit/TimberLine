@php
    $demoGroups = [
        'property' => ['label' => 'Properties', 'title' => 'From request to repair.', 'description' => 'Choose a property, assign a repair, and follow the work.', 'image' => 'everbranch-interactive-demo.png', 'alt' => 'Everbranch property management demo showing fictional properties and a portfolio overview.'],
        'trades' => ['label' => 'Service crews', 'title' => 'Keep the crew connected.', 'description' => 'Follow a service job from the office to the field.', 'image' => 'everbranch-industry-field-service-exterior.jpg', 'alt' => 'A service crew at a residential property.'],
        'makers' => ['label' => 'Retail & makers', 'title' => 'Make room for the craft.', 'description' => 'Explore customers, retail orders, and the next follow-up.', 'image' => 'everbranch-industry-retail.jpg', 'alt' => 'An independent retail business.'],
        'campus' => ['label' => 'Campus teams', 'title' => 'A clearer campus day.', 'description' => 'Bring maintenance requests and team handoffs together.', 'image' => 'everbranch-industry-projects.jpg', 'alt' => 'A team planning project work.'],
    ];
@endphp
<section id="interactive-demo" class="eb-studio-demo" aria-labelledby="interactive-demo-title" data-demo-guide>
    <div class="eb-studio-container">
        <div class="eb-guide__heading"><h2 id="interactive-demo-title">Take a look around.</h2><span class="eb-demo-label">Interactive demo</span></div>
        <nav class="eb-guide__tabs" aria-label="Choose an interactive demo">
            @foreach($demoGroups as $key => $demo)
                <a id="demo-tab-{{ $key }}" href="#demo-panel-{{ $key }}" data-demo-tab="{{ $key }}">{{ $demo['label'] }}</a>
            @endforeach
        </nav>
        @foreach($demoGroups as $key => $demo)
            <section id="demo-panel-{{ $key }}" class="eb-studio-demo__grid" aria-labelledby="demo-tab-{{ $key }}" data-demo-panel="{{ $key }}">
                <div class="eb-studio-section-heading">
                    <h3>{{ $demo['title'] }}</h3>
                    <p>{{ $demo['description'] }}</p>
                    <a class="eb-studio-button eb-studio-button--dark" href="https://teams-demo.theeverbranch.com/#{{ $key }}"><x-everbranch.action-leaf />Open the interactive demo</a>
                    <p class="eb-studio-demo__note">No sign-up. Fictional data and changes saved in your browser. No live bookings, payments or messages.</p>
                </div>
                <a class="eb-studio-demo__preview" href="https://teams-demo.theeverbranch.com/#{{ $key }}" aria-label="Open the {{ $demo['label'] }} demo">
                    <img src="{{ asset('images/public-site/'.$demo['image']) }}" width="1440" height="900" alt="{{ $demo['alt'] }}" loading="lazy" decoding="async">
                </a>
            </section>
        @endforeach
    </div>
</section>
