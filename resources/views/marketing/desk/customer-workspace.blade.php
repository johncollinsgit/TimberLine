@if($customerWorkspace)
<section class="md-snapshot" aria-label="Connected customer workspace">
    <div class="md-section-heading"><div><span class="md-eyebrow">Purchases, contacts and permissions</span><h2>Customers &amp; audiences</h2><p class="md-muted">Pay, Teams and Marketing use the same customer identities. Post-sale attribution updates dynamic event audiences.</p></div></div>
    <div class="md-summary">
        <a href="{{ route('customers.directory', ['tenant'=>$tenant->slug]) }}"><span>Customers</span><strong>{{ number_format($customerWorkspace['customers']) }}</strong><small>Canonical active customer records</small></a>
        <a href="{{ route('marketing.groups', ['tenant'=>$tenant->id]) }}"><span>Saved groups</span><strong>{{ $customerWorkspace['saved_groups'] }}</strong><small>Existing memberships retained</small></a>
        <a href="{{ route('marketing.segments', ['tenant'=>$tenant->id]) }}"><span>Saved audiences &amp; rules</span><strong>{{ $customerWorkspace['saved_segments'] }}</strong><small>Historical rules and new dynamic audiences</small></a>
    </div>
    <div class="md-header-actions">
        @if($customerWorkspace['can_manage'])<a class="eb-primary-button" href="{{ route('marketing.event-audiences', ['tenant'=>$tenant->slug]) }}">Build an event audience</a><a class="eb-secondary-button" href="{{ route('customers.reconciliation', ['tenant'=>$tenant->slug]) }}">Review older sources &amp; audiences</a><a class="eb-secondary-button" href="{{ route('customers.square.index', ['tenant'=>$tenant->slug]) }}">Refresh Square &amp; recent customers</a>@endif
        <a class="eb-secondary-button" href="{{ route('customers.directory', ['tenant'=>$tenant->slug]) }}">Find a customer</a>
    </div>
    <p class="md-muted">Square directory: {{ number_format($customerWorkspace['square_imported']) }} source records; {{ number_format($customerWorkspace['square_linked']) }} customer source links. Counts use different layers; unmatched or conflicting identities stay in reconciliation.</p>
    <p class="md-muted">Shopify, Growave, Square, manual sources and saved groups retain their original provenance and permissions. Customer capture and receipts do not grant marketing consent. Automated pre-event invitations remain off.</p>
</section>
@endif
