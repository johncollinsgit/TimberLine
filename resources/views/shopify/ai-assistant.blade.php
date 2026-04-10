<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions ?? []"
>
    @php
        $assistantState = is_array($assistantState ?? null)
            ? (array) $assistantState
            : \App\Support\Tenancy\TenantModuleUi::present(null, 'AI Assistant');
        $assistantEnabled = (bool) ($assistantEnabled ?? false);
        $assistantMessage = trim((string) ($assistantMessage ?? ''));
        $assistantTierMessage = trim((string) ($assistantTierMessage ?? ''));
        $assistantLockedCta = is_array($assistantLockedCta ?? null) ? (array) $assistantLockedCta : [
            'label' => 'Review plans and module access',
            'href' => route('shopify.app.plans', [], false),
        ];

        $activeSurfaceKey = strtolower(trim((string) ($activeSurfaceKey ?? 'start')));
        $isStartSurface = $activeSurfaceKey === 'start';

        $surfaceLabel = trim((string) ($surfaceLabel ?? 'Start Here'));
        $surfaceSummary = trim((string) ($surfaceSummary ?? 'Stage 1 foundation route.'));
        $surfaceStubItems = array_values((array) ($surfaceStubItems ?? []));

        $startHere = is_array($startHere ?? null) ? (array) $startHere : [];
        $welcome = is_array($startHere['welcome'] ?? null) ? (array) $startHere['welcome'] : [
            'title' => 'Welcome to AI Assistant',
            'copy' => 'See what is ready now, what needs setup, and the next best click in one place.',
        ];
        $statusStrip = array_values((array) ($startHere['status_strip'] ?? []));
        $actions = array_values((array) ($startHere['actions'] ?? []));
        $helpsWith = array_values((array) ($startHere['helps_with'] ?? []));

        $topOpportunitiesPayload = is_array($topOpportunities ?? null) ? (array) $topOpportunities : [];
        $opportunitiesIntro = is_array($topOpportunitiesPayload['intro'] ?? null) ? (array) $topOpportunitiesPayload['intro'] : [
            'title' => 'Best Opportunities Right Now',
            'copy' => 'These are the highest-value next steps for this tenant today.',
        ];
        $opportunityItems = array_values((array) ($topOpportunitiesPayload['opportunities'] ?? []));
        $opportunitiesPagination = is_array($topOpportunitiesPayload['pagination'] ?? null) ? (array) $topOpportunitiesPayload['pagination'] : [];
        $opportunitiesEmpty = is_array($topOpportunitiesPayload['empty_state'] ?? null) ? (array) $topOpportunitiesPayload['empty_state'] : [
            'title' => 'No top opportunities yet',
            'copy' => 'Nothing needs immediate follow-up right now.',
            'label' => 'Open Setup',
            'href' => route('shopify.app.assistant.setup', [], false),
        ];
        $opportunitiesLockedCta = is_array($topOpportunitiesPayload['locked_cta'] ?? null) ? (array) $topOpportunitiesPayload['locked_cta'] : [
            'label' => 'Review plans and module access',
            'href' => route('shopify.app.plans', [], false),
        ];

        $draftPayload = is_array($draftCampaigns ?? null) ? (array) $draftCampaigns : [];
        $draftIntro = is_array($draftPayload['intro'] ?? null) ? (array) $draftPayload['intro'] : [
            'title' => 'Draft Campaigns',
            'copy' => 'Review or create AI-assisted drafts in plain English. Nothing sends automatically.',
        ];
        $draftItems = array_values((array) ($draftPayload['drafts'] ?? []));
        $selectedDraft = is_array($draftPayload['selected_draft'] ?? null) ? (array) $draftPayload['selected_draft'] : null;
        $draftRecommendations = array_values((array) ($draftPayload['recommendations'] ?? []));
        $draftEmpty = is_array($draftPayload['empty_state'] ?? null) ? (array) $draftPayload['empty_state'] : [
            'title' => 'No draft campaigns yet',
            'copy' => 'Start from a top opportunity to create your first draft for human review.',
            'label' => 'Open Top Opportunities',
            'href' => route('shopify.app.assistant.opportunities', [], false),
        ];
        $draftLockedCta = is_array($draftPayload['locked_cta'] ?? null) ? (array) $draftPayload['locked_cta'] : [
            'label' => 'Review plans and module access',
            'href' => route('shopify.app.plans', [], false),
        ];

        $setupPayload = is_array($setupChecklist ?? null) ? (array) $setupChecklist : [];
        $setupIntro = is_array($setupPayload['intro'] ?? null) ? (array) $setupPayload['intro'] : [
            'title' => 'Setup',
            'copy' => 'See what is connected, what is missing, and the one next step to take.',
        ];
        $setupStatusStrip = array_values((array) ($setupPayload['status_strip'] ?? []));
        $setupItems = array_values((array) ($setupPayload['checklist'] ?? []));
        $setupNextStep = is_array($setupPayload['next_step'] ?? null) ? (array) $setupPayload['next_step'] : [
            'title' => 'Next Best Step',
            'copy' => 'Open setup details and complete one item to move forward.',
            'label' => 'Open Setup',
            'href' => route('shopify.app.assistant.setup', [], false),
        ];

        $activityPayload = is_array($activityFeed ?? null) ? (array) $activityFeed : [];
        $activityIntro = is_array($activityPayload['intro'] ?? null) ? (array) $activityPayload['intro'] : [
            'title' => 'Activity',
            'copy' => 'Recent AI Assistant suggestions and draft history. Human review stays in control.',
        ];
        $activityItems = array_values((array) ($activityPayload['items'] ?? []));
        $activityPagination = is_array($activityPayload['pagination'] ?? null) ? (array) $activityPayload['pagination'] : [];
        $activityEmpty = is_array($activityPayload['empty_state'] ?? null) ? (array) $activityPayload['empty_state'] : [
            'title' => 'No recent activity yet',
            'copy' => 'You will see recent opportunities, drafts, and review decisions here after your team starts using AI Assistant.',
            'label' => 'Open Top Opportunities',
            'href' => route('shopify.app.assistant.opportunities', [], false),
        ];
        $activityLockedCta = is_array($activityPayload['locked_cta'] ?? null) ? (array) $activityPayload['locked_cta'] : [
            'label' => 'Review plans and module access',
            'href' => route('shopify.app.plans', [], false),
        ];

        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
        $contextToken = trim((string) ($contextToken ?? ''));
    @endphp

    @if($isStartSurface)
        <section class="assistant-shell assistant-start-grid" data-ai-assistant-start="true">
            <article class="assistant-panel assistant-panel--hero" aria-labelledby="assistant-start-welcome-heading">
                <h2 id="assistant-start-welcome-heading" class="assistant-title">{{ $welcome['title'] ?? 'Welcome to AI Assistant' }}</h2>
                <p class="assistant-copy">{{ $welcome['copy'] ?? '' }}</p>

                <div class="assistant-meta" aria-label="AI assistant state">
                    <x-tenancy.module-state-badge :module-state="$assistantState" />
                </div>
                @if($assistantTierMessage !== '')
                    <p class="assistant-note">Plan status: {{ $assistantTierMessage }}</p>
                @endif

                @if($assistantEnabled)
                    <p class="assistant-policy">AI Assistant is unlocked for this tenant. Start with the next best click below.</p>
                @elseif($assistantMessage !== '')
                    <div class="assistant-lock" role="status" aria-live="polite">
                        <p>{{ $assistantMessage }}</p>
                        <a class="assistant-link" href="{{ $embeddedUrl((string) ($assistantLockedCta['href'] ?? route('shopify.app.plans', [], false))) }}">{{ $assistantLockedCta['label'] ?? 'Review plans and module access' }}</a>
                    </div>
                @endif
            </article>

            <article class="assistant-panel" aria-labelledby="assistant-status-strip-heading">
                <h3 id="assistant-status-strip-heading" class="assistant-title">Current Status</h3>
                <div class="assistant-status-strip" role="list" aria-label="AI assistant status strip">
                    @foreach(array_slice($statusStrip, 0, 4) as $status)
                        @php($label = trim((string) ($status['label'] ?? 'Status')))
                        @php($count = (int) ($status['count'] ?? 0))
                        <section class="assistant-status-card" role="listitem" aria-label="{{ $label }} status">
                            <h4 class="assistant-status-label">{{ $label }}</h4>
                            <p class="assistant-status-value" aria-label="{{ $label }} count">{{ $count }}</p>
                        </section>
                    @endforeach
                </div>
            </article>

            <article class="assistant-panel" aria-labelledby="assistant-next-click-heading">
                <h3 id="assistant-next-click-heading" class="assistant-title">Next Best Click</h3>
                <div class="assistant-action-list" role="list">
                    @forelse(array_slice($actions, 0, 3) as $action)
                        @php($href = trim((string) ($action['href'] ?? '')))
                        <section class="assistant-action-item" role="listitem">
                            <h4 class="assistant-item-title">{{ $action['label'] ?? 'Action' }}</h4>
                            <p class="assistant-copy">{{ $action['description'] ?? '' }}</p>
                            @if($href !== '')
                                <a
                                    class="assistant-action-link"
                                    href="{{ $embeddedUrl($href) }}"
                                    aria-label="{{ ($action['label'] ?? 'Open action') }}"
                                >
                                    Open
                                </a>
                            @endif
                        </section>
                    @empty
                        <p class="assistant-copy">No actions are available yet.</p>
                    @endforelse
                </div>
            </article>

            <article class="assistant-panel" aria-labelledby="assistant-helps-heading">
                <h3 id="assistant-helps-heading" class="assistant-title">What This Helps With</h3>
                <ul class="assistant-help-list">
                    @foreach(array_slice($helpsWith, 0, 3) as $item)
                        <li class="assistant-copy">{{ $item }}</li>
                    @endforeach
                </ul>
            </article>
        </section>
    @elseif($activeSurfaceKey === 'opportunities')
        <section class="assistant-shell" data-ai-assistant-opportunities="true">
            <article class="assistant-panel assistant-panel--hero" aria-labelledby="assistant-opportunities-heading">
                <h2 id="assistant-opportunities-heading" class="assistant-title">{{ $opportunitiesIntro['title'] ?? 'Best Opportunities Right Now' }}</h2>
                <p class="assistant-copy">{{ $opportunitiesIntro['copy'] ?? '' }}</p>

                <div class="assistant-meta" aria-label="AI assistant state">
                    <x-tenancy.module-state-badge :module-state="$assistantState" />
                </div>
                @if($assistantTierMessage !== '')
                    <p class="assistant-note">Plan status: {{ $assistantTierMessage }}</p>
                @endif

                @if(!$assistantEnabled && $assistantMessage !== '')
                    <div class="assistant-lock" role="status" aria-live="polite">
                        <p>{{ $assistantMessage }}</p>
                        @php($lockedHref = trim((string) ($opportunitiesLockedCta['href'] ?? route('shopify.app.plans', [], false))))
                        <a class="assistant-link" href="{{ $embeddedUrl($lockedHref) }}">{{ $opportunitiesLockedCta['label'] ?? 'Review plans and module access' }}</a>
                    </div>
                @endif
            </article>

            @if($assistantEnabled)
                <article class="assistant-panel" aria-labelledby="assistant-opportunities-list-heading">
                    <h3 id="assistant-opportunities-list-heading" class="assistant-title">Top Opportunities</h3>
                    <div class="assistant-action-list" role="list">
                        @forelse($opportunityItems as $item)
                            <section class="assistant-action-item" role="listitem">
                                <h4 class="assistant-item-title">{{ $item['title'] ?? 'Opportunity' }}</h4>
                                <p class="assistant-copy">{{ $item['why_this_matters'] ?? '' }}</p>
                                <p class="assistant-note">Priority: {{ $item['priority'] ?? 'Needs review' }}</p>
                                @if(filled($item['explainability'] ?? null))
                                    <p class="assistant-note">{{ $item['explainability'] }}</p>
                                @endif
                                @php($actionHref = trim((string) ($item['action_href'] ?? '')))
                                @if($actionHref !== '')
                                    <a
                                        class="assistant-action-link"
                                        href="{{ $embeddedUrl($actionHref) }}"
                                        aria-label="{{ ($item['action_label'] ?? 'Open') }}"
                                    >
                                        {{ $item['action_label'] ?? 'Open' }}
                                    </a>
                                @endif
                            </section>
                        @empty
                            <section class="assistant-action-item" role="status" aria-live="polite">
                                <h4 class="assistant-item-title">{{ $opportunitiesEmpty['title'] ?? 'No top opportunities yet' }}</h4>
                                <p class="assistant-copy">{{ $opportunitiesEmpty['copy'] ?? '' }}</p>
                                @if(filled($opportunitiesEmpty['href'] ?? null))
                                    <a
                                        class="assistant-action-link"
                                        href="{{ $embeddedUrl((string) $opportunitiesEmpty['href']) }}"
                                        aria-label="{{ ($opportunitiesEmpty['label'] ?? 'Open Setup') }}"
                                    >
                                        {{ $opportunitiesEmpty['label'] ?? 'Open Setup' }}
                                    </a>
                                @endif
                            </section>
                        @endforelse
                    </div>
                </article>

                @if((bool) ($opportunitiesPagination['has_pages'] ?? false))
                    <article class="assistant-panel" aria-label="Top opportunities pagination">
                        <div class="assistant-list-item">
                            <p class="assistant-copy">
                                Showing {{ (int) ($opportunitiesPagination['from'] ?? 0) }}-{{ (int) ($opportunitiesPagination['to'] ?? 0) }}
                                of {{ (int) ($opportunitiesPagination['total'] ?? 0) }}
                            </p>
                            <div class="assistant-meta">
                                @if(filled($opportunitiesPagination['prev_url'] ?? null))
                                    <a
                                        class="assistant-link"
                                        href="{{ $embeddedUrl((string) $opportunitiesPagination['prev_url']) }}"
                                        aria-label="Open previous opportunities page"
                                    >
                                        Previous
                                    </a>
                                @endif
                                @if(filled($opportunitiesPagination['next_url'] ?? null))
                                    <a
                                        class="assistant-link"
                                        href="{{ $embeddedUrl((string) $opportunitiesPagination['next_url']) }}"
                                        aria-label="Open next opportunities page"
                                    >
                                        Next
                                    </a>
                                @endif
                            </div>
                        </div>
                    </article>
                @endif
            @endif
        </section>
    @elseif($activeSurfaceKey === 'drafts')
        <section class="assistant-shell assistant-start-grid" data-ai-assistant-drafts="true">
            <article class="assistant-panel assistant-panel--hero" aria-labelledby="assistant-drafts-heading">
                <h2 id="assistant-drafts-heading" class="assistant-title">{{ $draftIntro['title'] ?? 'Draft Campaigns' }}</h2>
                <p class="assistant-copy">{{ $draftIntro['copy'] ?? '' }}</p>

                <div class="assistant-meta" aria-label="AI assistant state">
                    <x-tenancy.module-state-badge :module-state="$assistantState" />
                </div>
                @if($assistantTierMessage !== '')
                    <p class="assistant-note">Plan status: {{ $assistantTierMessage }}</p>
                @endif

                @if($assistantEnabled)
                    <p class="assistant-policy">Drafts stay human-reviewed. AI suggestions help you prepare, not send.</p>
                @elseif($assistantMessage !== '')
                    <div class="assistant-lock" role="status" aria-live="polite">
                        <p>{{ $assistantMessage }}</p>
                        @php($lockedHref = trim((string) ($draftLockedCta['href'] ?? route('shopify.app.plans', [], false))))
                        <a class="assistant-link" href="{{ $embeddedUrl($lockedHref) }}">{{ $draftLockedCta['label'] ?? 'Review plans and module access' }}</a>
                    </div>
                @endif
            </article>

            @if($assistantEnabled)
                <article class="assistant-panel" aria-labelledby="assistant-draft-list-heading">
                    <h3 id="assistant-draft-list-heading" class="assistant-title">Draft Campaigns</h3>
                    <div class="assistant-action-list" role="list">
                        @forelse($draftItems as $item)
                            @php($selectHref = trim((string) ($item['select_href'] ?? '')))
                            <section class="assistant-action-item" role="listitem">
                                <div class="assistant-card-head">
                                    <h4 class="assistant-item-title">{{ $item['title'] ?? 'Draft campaign' }}</h4>
                                    <p class="assistant-note">{{ $item['status_label'] ?? 'Draft Ready' }}</p>
                                </div>
                                <p class="assistant-note">Why this was suggested: {{ $item['why_this_was_suggested'] ?? 'AI found a useful follow-up based on your recent activity.' }}</p>
                                <p class="assistant-note">Audience: {{ $item['audience'] ?? 'All eligible customers' }}</p>
                                <p class="assistant-note">Message: {{ \Illuminate\Support\Str::limit((string) ($item['message'] ?? ''), 120) }}</p>
                                <p class="assistant-note">Next Step: {{ $item['next_step'] ?? 'Review Draft before any send action.' }}</p>
                                @if($selectHref !== '')
                                    <a
                                        class="assistant-action-link"
                                        href="{{ $embeddedUrl($selectHref) }}"
                                        aria-label="Review Draft {{ $item['title'] ?? 'campaign' }}"
                                    >
                                        Review Draft
                                    </a>
                                @endif
                            </section>
                        @empty
                            <section class="assistant-action-item" role="status" aria-live="polite">
                                <h4 class="assistant-item-title">{{ $draftEmpty['title'] ?? 'No draft campaigns yet' }}</h4>
                                <p class="assistant-copy">{{ $draftEmpty['copy'] ?? '' }}</p>
                                @if(filled($draftEmpty['href'] ?? null))
                                    <a
                                        class="assistant-action-link"
                                        href="{{ $embeddedUrl((string) $draftEmpty['href']) }}"
                                        aria-label="{{ $draftEmpty['label'] ?? 'Open Top Opportunities' }}"
                                    >
                                        {{ $draftEmpty['label'] ?? 'Open Top Opportunities' }}
                                    </a>
                                @endif
                            </section>
                        @endforelse
                    </div>
                </article>

                <article class="assistant-panel" aria-labelledby="assistant-draft-editor-heading">
                    <h3 id="assistant-draft-editor-heading" class="assistant-title">Review Draft</h3>

                    @if(is_array($selectedDraft ?? null))
                        @php($updateHref = trim((string) ($selectedDraft['update_href'] ?? '')))
                        <form
                            method="POST"
                            action="{{ $embeddedUrl($updateHref !== '' ? $updateHref : route('shopify.app.assistant.drafts', [], false)) }}"
                            class="assistant-action-list"
                            aria-label="Draft campaign editor"
                        >
                            @csrf
                            @if($contextToken !== '')
                                <input type="hidden" name="context_token" value="{{ $contextToken }}">
                            @endif

                            <section class="assistant-action-item">
                                <label for="draft-name" class="assistant-item-title">Campaign Name</label>
                                <input
                                    id="draft-name"
                                    name="name"
                                    type="text"
                                    value="{{ $selectedDraft['title'] ?? '' }}"
                                    maxlength="120"
                                    required
                                    class="assistant-field-input"
                                >
                            </section>

                            <section class="assistant-action-item">
                                <label for="draft-audience" class="assistant-item-title">Audience</label>
                                <input
                                    id="draft-audience"
                                    name="audience"
                                    type="text"
                                    value="{{ $selectedDraft['audience'] ?? '' }}"
                                    maxlength="200"
                                    class="assistant-field-input"
                                >
                            </section>

                            <section class="assistant-action-item">
                                <label for="draft-message" class="assistant-item-title">Message</label>
                                <textarea
                                    id="draft-message"
                                    name="message"
                                    rows="4"
                                    maxlength="5000"
                                    required
                                    class="assistant-field-input"
                                >{{ $selectedDraft['message'] ?? '' }}</textarea>
                            </section>

                            <p class="assistant-note">Why this was suggested: {{ $selectedDraft['why_this_was_suggested'] ?? 'AI found this from current campaign and customer activity.' }}</p>
                            <p class="assistant-note">Next Step: {{ $selectedDraft['next_step'] ?? 'Review Draft and confirm before any send action.' }}</p>

                            <div class="assistant-meta">
                                <button type="submit" class="assistant-action-link" aria-label="Save Draft Changes">
                                    Save Draft Changes
                                </button>
                                @if(filled($selectedDraft['review_href'] ?? null))
                                    <a
                                        class="assistant-link"
                                        href="{{ $embeddedUrl((string) $selectedDraft['review_href']) }}"
                                        aria-label="Open Messaging to continue manual review"
                                    >
                                        Open Messaging
                                    </a>
                                @endif
                            </div>
                        </form>
                    @else
                        <p class="assistant-copy">Select a draft to edit or create one from a recommendation below.</p>
                    @endif

                    <div class="assistant-action-list" role="list" aria-label="Recommendation to draft actions">
                        @forelse($draftRecommendations as $recommendation)
                            <section class="assistant-action-item" role="listitem">
                                <h4 class="assistant-item-title">{{ $recommendation['title'] ?? 'Opportunity' }}</h4>
                                <p class="assistant-note">Why this was suggested: {{ $recommendation['why_this_was_suggested'] ?? '' }}</p>
                                <p class="assistant-note">Audience: {{ $recommendation['audience'] ?? 'All eligible customers' }}</p>
                                <p class="assistant-note">Message: {{ \Illuminate\Support\Str::limit((string) ($recommendation['message'] ?? ''), 130) }}</p>
                                <p class="assistant-note">Next Step: {{ $recommendation['next_step'] ?? 'Create a draft and review it with your team.' }}</p>
                                <p class="assistant-note">Priority: {{ $recommendation['priority'] ?? 'Needs review' }}</p>

                                <form method="POST" action="{{ $embeddedUrl(route('shopify.app.assistant.drafts.create', [], false)) }}">
                                    @csrf
                                    @if($contextToken !== '')
                                        <input type="hidden" name="context_token" value="{{ $contextToken }}">
                                    @endif
                                    <input type="hidden" name="recommendation_id" value="{{ (int) ($recommendation['id'] ?? 0) }}">
                                    <button type="submit" class="assistant-action-link" aria-label="Create Draft from {{ $recommendation['title'] ?? 'opportunity' }}">
                                        Create Draft
                                    </button>
                                </form>
                            </section>
                        @empty
                            <p class="assistant-copy">No pending recommendations are available right now.</p>
                        @endforelse
                    </div>
                </article>
            @endif
        </section>
    @elseif($activeSurfaceKey === 'setup')
        <section class="assistant-shell assistant-start-grid" data-ai-assistant-setup="true">
            <article class="assistant-panel assistant-panel--hero" aria-labelledby="assistant-setup-heading">
                <h2 id="assistant-setup-heading" class="assistant-title">{{ $setupIntro['title'] ?? 'Setup' }}</h2>
                <p class="assistant-copy">{{ $setupIntro['copy'] ?? '' }}</p>

                <div class="assistant-meta" aria-label="AI assistant state">
                    <x-tenancy.module-state-badge :module-state="$assistantState" />
                </div>
                @if($assistantTierMessage !== '')
                    <p class="assistant-note">Plan status: {{ $assistantTierMessage }}</p>
                @endif

                @if($assistantEnabled)
                    <p class="assistant-policy">{{ $setupNextStep['copy'] ?? 'Complete one checklist item to move setup forward.' }}</p>
                    @if(filled($setupNextStep['href'] ?? null))
                        <a
                            class="assistant-link"
                            href="{{ $embeddedUrl((string) $setupNextStep['href']) }}"
                            aria-label="{{ ($setupNextStep['label'] ?? 'Open next step') }}"
                        >
                            {{ $setupNextStep['label'] ?? 'Open next step' }}
                        </a>
                    @endif
                @elseif($assistantMessage !== '')
                    <div class="assistant-lock" role="status" aria-live="polite">
                        <p>{{ $assistantMessage }}</p>
                        <a class="assistant-link" href="{{ $embeddedUrl((string) ($assistantLockedCta['href'] ?? route('shopify.app.plans', [], false))) }}">{{ $assistantLockedCta['label'] ?? 'Review plans and module access' }}</a>
                    </div>
                @endif
            </article>

            @if($assistantEnabled)
                <article class="assistant-panel" aria-labelledby="assistant-setup-status-heading">
                    <h3 id="assistant-setup-status-heading" class="assistant-title">Current Status</h3>
                    <div class="assistant-status-strip" role="list" aria-label="AI setup status strip">
                        @foreach(array_slice($setupStatusStrip, 0, 4) as $status)
                            @php($label = trim((string) ($status['label'] ?? 'Status')))
                            @php($count = (int) ($status['count'] ?? 0))
                            <section class="assistant-status-card" role="listitem" aria-label="{{ $label }} status">
                                <h4 class="assistant-status-label">{{ $label }}</h4>
                                <p class="assistant-status-value" aria-label="{{ $label }} count">{{ $count }}</p>
                            </section>
                        @endforeach
                    </div>
                </article>

                <article class="assistant-panel" aria-labelledby="assistant-setup-checklist-heading">
                    <h3 id="assistant-setup-checklist-heading" class="assistant-title">Setup Checklist</h3>
                    <div class="assistant-action-list" role="list">
                        @forelse(array_slice($setupItems, 0, 6) as $item)
                            @php($itemState = is_array($item['state'] ?? null) ? (array) $item['state'] : null)
                            @php($itemHref = trim((string) ($item['action_href'] ?? '')))
                            <section class="assistant-action-item assistant-setup-item" role="listitem">
                                <div class="assistant-card-head">
                                    <h4 class="assistant-item-title">{{ $item['title'] ?? 'Checklist Item' }}</h4>
                                    <x-tenancy.module-state-badge :module-state="$itemState" />
                                </div>
                                <p class="assistant-copy">{{ $item['description'] ?? '' }}</p>
                                <p class="assistant-note">Current state: {{ $itemState['state_label'] ?? 'Locked' }}</p>
                                @if($itemHref !== '')
                                    <a
                                        class="assistant-action-link"
                                        href="{{ $embeddedUrl($itemHref) }}"
                                        aria-label="{{ ($item['action_label'] ?? 'Open checklist item') }}"
                                    >
                                        {{ $item['action_label'] ?? 'Open' }}
                                    </a>
                                @endif
                            </section>
                        @empty
                            <p class="assistant-copy">Setup details are not available yet.</p>
                        @endforelse
                    </div>
                </article>

                <article class="assistant-panel" aria-labelledby="assistant-setup-next-heading">
                    <h3 id="assistant-setup-next-heading" class="assistant-title">{{ $setupNextStep['title'] ?? 'Next Best Step' }}</h3>
                    <p class="assistant-copy">{{ $setupNextStep['copy'] ?? '' }}</p>
                    @if(filled($setupNextStep['href'] ?? null))
                        <a
                            class="assistant-action-link"
                            href="{{ $embeddedUrl((string) $setupNextStep['href']) }}"
                            aria-label="{{ ($setupNextStep['label'] ?? 'Open next step') }}"
                        >
                            {{ $setupNextStep['label'] ?? 'Open next step' }}
                        </a>
                    @endif
                </article>
            @endif
        </section>
    @elseif($activeSurfaceKey === 'activity')
        <section class="assistant-shell" data-ai-assistant-activity="true">
            <article class="assistant-panel assistant-panel--hero" aria-labelledby="assistant-activity-heading">
                <h2 id="assistant-activity-heading" class="assistant-title">{{ $activityIntro['title'] ?? 'Activity' }}</h2>
                <p class="assistant-copy">{{ $activityIntro['copy'] ?? '' }}</p>

                <div class="assistant-meta" aria-label="AI assistant state">
                    <x-tenancy.module-state-badge :module-state="$assistantState" />
                </div>
                @if($assistantTierMessage !== '')
                    <p class="assistant-note">Plan status: {{ $assistantTierMessage }}</p>
                @endif

                @if($assistantEnabled)
                    <p class="assistant-policy">This is recent assistant history only. People review all decisions.</p>
                @elseif($assistantMessage !== '')
                    <div class="assistant-lock" role="status" aria-live="polite">
                        <p>{{ $assistantMessage }}</p>
                        @php($lockedHref = trim((string) ($activityLockedCta['href'] ?? route('shopify.app.plans', [], false))))
                        <a class="assistant-link" href="{{ $embeddedUrl($lockedHref) }}">{{ $activityLockedCta['label'] ?? 'Review plans and module access' }}</a>
                    </div>
                @endif
            </article>

            @if($assistantEnabled)
                <article class="assistant-panel" aria-labelledby="assistant-activity-list-heading">
                    <h3 id="assistant-activity-list-heading" class="assistant-title">Recent Activity</h3>
                    <div class="assistant-action-list" role="list" aria-label="Recent AI Assistant activity">
                        @forelse($activityItems as $item)
                            @php($itemActionHref = trim((string) ($item['action_href'] ?? '')))
                            @php($itemActionLabel = trim((string) ($item['action_label'] ?? '')))
                            <section class="assistant-action-item" role="listitem">
                                <div class="assistant-card-head">
                                    <h4 class="assistant-item-title">{{ $item['title'] ?? 'Activity item' }}</h4>
                                    <p class="assistant-note">{{ $item['event_label'] ?? 'Activity' }}</p>
                                </div>
                                <p class="assistant-copy">{{ $item['summary'] ?? '' }}</p>
                                <p class="assistant-note">
                                    <time
                                        datetime="{{ $item['occurred_at_iso'] ?? '' }}"
                                        aria-label="Occurred {{ $item['occurred_at_accessible'] ?? ($item['occurred_at_label'] ?? 'recently') }}"
                                    >
                                        {{ $item['occurred_at_label'] ?? 'Time unavailable' }}
                                    </time>
                                </p>
                                @if($itemActionHref !== '' && $itemActionLabel !== '')
                                    <a
                                        class="assistant-action-link"
                                        href="{{ $embeddedUrl($itemActionHref) }}"
                                        aria-label="{{ $itemActionLabel }}"
                                    >
                                        {{ $itemActionLabel }}
                                    </a>
                                @endif
                            </section>
                        @empty
                            <section class="assistant-action-item" role="status" aria-live="polite">
                                <h4 class="assistant-item-title">{{ $activityEmpty['title'] ?? 'No recent activity yet' }}</h4>
                                <p class="assistant-copy">{{ $activityEmpty['copy'] ?? '' }}</p>
                                @if(filled($activityEmpty['href'] ?? null))
                                    <a
                                        class="assistant-action-link"
                                        href="{{ $embeddedUrl((string) $activityEmpty['href']) }}"
                                        aria-label="{{ ($activityEmpty['label'] ?? 'Open Top Opportunities') }}"
                                    >
                                        {{ $activityEmpty['label'] ?? 'Open Top Opportunities' }}
                                    </a>
                                @endif
                            </section>
                        @endforelse
                    </div>
                </article>

                @if((bool) ($activityPagination['has_pages'] ?? false))
                    <article class="assistant-panel" aria-label="Activity pagination">
                        <div class="assistant-list-item">
                            <p class="assistant-copy">
                                Showing {{ (int) ($activityPagination['from'] ?? 0) }}-{{ (int) ($activityPagination['to'] ?? 0) }}
                                of {{ (int) ($activityPagination['total'] ?? 0) }}
                            </p>
                            <div class="assistant-meta">
                                @if(filled($activityPagination['prev_url'] ?? null))
                                    <a
                                        class="assistant-link"
                                        href="{{ $embeddedUrl((string) $activityPagination['prev_url']) }}"
                                        aria-label="Open previous activity page"
                                    >
                                        Previous
                                    </a>
                                @endif
                                @if(filled($activityPagination['next_url'] ?? null))
                                    <a
                                        class="assistant-link"
                                        href="{{ $embeddedUrl((string) $activityPagination['next_url']) }}"
                                        aria-label="Open next activity page"
                                    >
                                        Next
                                    </a>
                                @endif
                            </div>
                        </div>
                    </article>
                @endif
            @endif
        </section>
    @else
        <section class="assistant-shell" data-ai-assistant-foundation="true">
            <article class="assistant-panel assistant-panel--hero" aria-label="AI assistant foundation status">
                <h2 class="assistant-title">AI Assistant</h2>
                <p class="assistant-copy">{{ $surfaceSummary }}</p>

                <div class="assistant-meta" aria-label="AI assistant state">
                    <x-tenancy.module-state-badge :module-state="$assistantState" />
                    <span class="assistant-pill">Surface · {{ $surfaceLabel }}</span>
                </div>
                @if($assistantTierMessage !== '')
                    <p class="assistant-note">Plan status: {{ $assistantTierMessage }}</p>
                @endif

                @if($assistantEnabled)
                    <p class="assistant-policy">This page is a routing stub for stage 1. Full workflows are intentionally deferred.</p>
                    <p class="assistant-note">Human review stays in control for any future send action.</p>
                @elseif($assistantMessage !== '')
                    <div class="assistant-lock" role="status" aria-live="polite">
                        <p>{{ $assistantMessage }}</p>
                        <a class="assistant-link" href="{{ $embeddedUrl((string) ($assistantLockedCta['href'] ?? route('shopify.app.plans', [], false))) }}">{{ $assistantLockedCta['label'] ?? 'Review plans and module access' }}</a>
                    </div>
                @endif
            </article>

            @if($assistantEnabled)
                <article class="assistant-panel" aria-label="Surface stub details">
                    <h3 class="assistant-title">{{ $surfaceLabel }}</h3>
                    <div class="assistant-list" role="list">
                        @foreach(array_slice($surfaceStubItems, 0, 4) as $item)
                            <div class="assistant-list-item assistant-list-item--stack" role="listitem">
                                <p class="assistant-copy">{{ $item }}</p>
                            </div>
                        @endforeach
                    </div>
                </article>
            @endif
        </section>
    @endif
</x-shopify-embedded-shell>
