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
        $payload = is_array($assistantPayload ?? null) ? $assistantPayload : [];
        $assistant = is_array($assistantAccess ?? null) ? $assistantAccess : [];
        $assistantState = is_array($assistant['state'] ?? null)
            ? (array) $assistant['state']
            : \App\Support\Tenancy\TenantModuleUi::present(is_array($assistantModuleState ?? null) ? $assistantModuleState : null, 'AI Assistant');
        $assistantEnabled = (bool) ($assistant['enabled'] ?? false);
        $assistantMessage = trim((string) ($assistant['message'] ?? ''));

        $experience = is_array($payload['experience_profile'] ?? null) ? (array) $payload['experience_profile'] : [];
        $workspace = is_array($experience['workspace'] ?? null) ? (array) $experience['workspace'] : [];
        $channelType = ucfirst(strtolower(trim((string) ($experience['channel_type'] ?? 'shopify'))));
        $useCase = ucfirst(strtolower(trim((string) ($experience['use_case_profile'] ?? 'ops'))));

        $topOpportunities = array_values((array) ($payload['top_opportunities'] ?? []));
        $draftCampaigns = array_values((array) ($payload['draft_campaigns'] ?? []));
        $setupItems = array_values((array) ($payload['setup_items'] ?? []));

        $activity = is_array($payload['activity'] ?? null) ? (array) $payload['activity'] : [];
        $activityHero = is_array($activity['hero'] ?? null) ? (array) $activity['hero'] : [];
        $activityCards = array_values((array) ($activity['summary_cards'] ?? []));
        $quickActions = array_values((array) ($activity['quick_actions'] ?? []));

        $primaryActions = array_values((array) ($payload['primary_actions'] ?? []));
        $humanReviewPolicy = trim((string) ($payload['human_review_policy'] ?? 'Every draft stays in review mode until a person confirms the final send.'));
        $surface = strtolower(trim((string) ($assistantSurface ?? 'start')));

        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
    @endphp

    <section class="assistant-shell" data-ai-assistant-surface="true" data-ai-assistant-view="{{ $surface }}">
        <article class="assistant-panel assistant-panel--hero" aria-label="AI assistant status">
            <h2 class="assistant-title">AI Assistant</h2>
            <p class="assistant-copy">{{ $workspace['subtitle'] ?? 'Use this workspace to prioritize opportunities, prepare drafts, and keep human review in control.' }}</p>

            <div class="assistant-meta" aria-label="AI assistant profile">
                <x-tenancy.module-state-badge :module-state="$assistantState" />
                <span class="assistant-pill">Channel · {{ $channelType }}</span>
                <span class="assistant-pill">Focus · {{ $useCase }}</span>
            </div>

            @if($assistantEnabled)
                <p class="assistant-policy">{{ $humanReviewPolicy }}</p>
            @elseif($assistantMessage !== '')
                <div class="assistant-lock" role="status" aria-live="polite">
                    <p>{{ $assistantMessage }}</p>
                    <a class="assistant-link" href="{{ $embeddedUrl(route('shopify.app.plans', [], false)) }}">Review plans and module access</a>
                </div>
            @endif
        </article>

        @if($surface === 'start')
            <div class="assistant-grid assistant-grid--three">
                <article class="assistant-panel" aria-label="Workspace summary">
                    <h3 class="assistant-title">Start Here</h3>
                    <p class="assistant-copy">{{ $workspace['label'] ?? 'Workspace' }}</p>
                    <p class="assistant-copy">{{ $workspace['command_placeholder'] ?? 'Search tasks and pages.' }}</p>
                </article>

                <article class="assistant-panel" aria-label="Primary actions">
                    <h3 class="assistant-title">Next Actions</h3>
                    <div class="assistant-list">
                        @forelse(array_slice($primaryActions, 0, 5) as $action)
                            @php($href = trim((string) ($action['href'] ?? '')))
                            <div class="assistant-list-item">
                                <span>{{ $action['label'] ?? 'Action' }}</span>
                                @if($href !== '')
                                    <a class="assistant-link" href="{{ $embeddedUrl($href) }}">Open</a>
                                @endif
                            </div>
                        @empty
                            <p class="assistant-copy">No actions are available yet.</p>
                        @endforelse
                    </div>
                </article>

                <article class="assistant-panel" aria-label="Human review policy">
                    <h3 class="assistant-title">Human Review</h3>
                    <p class="assistant-copy">{{ $humanReviewPolicy }}</p>
                </article>
            </div>
        @elseif($surface === 'opportunities')
            <article class="assistant-panel" aria-label="Top opportunities list">
                <h3 class="assistant-title">Top Opportunities</h3>
                <div class="assistant-list">
                    @forelse(array_slice($topOpportunities, 0, 5) as $opportunity)
                        @php($href = trim((string) ($opportunity['href'] ?? '')))
                        <div class="assistant-list-item assistant-list-item--stack">
                            <p class="assistant-item-title">{{ $opportunity['title'] ?? 'Opportunity' }}</p>
                            <p class="assistant-copy">{{ $opportunity['description'] ?? '' }}</p>
                            @if($href !== '')
                                <a class="assistant-link" href="{{ $embeddedUrl($href) }}">Open</a>
                            @endif
                        </div>
                    @empty
                        <p class="assistant-copy">No opportunities are available yet.</p>
                    @endforelse
                </div>
            </article>
        @elseif($surface === 'drafts')
            <article class="assistant-panel" aria-label="Draft campaigns">
                <h3 class="assistant-title">Draft Campaigns</h3>
                <p class="assistant-policy">{{ $humanReviewPolicy }}</p>
                <div class="assistant-grid assistant-grid--three">
                    @forelse(array_slice($draftCampaigns, 0, 6) as $draft)
                        @php($moduleState = is_array($draft['module_state'] ?? null) ? $draft['module_state'] : null)
                        @php($href = trim((string) ($draft['href'] ?? '')))
                        <article class="assistant-card" data-assistant-draft="{{ $draft['key'] ?? 'draft' }}">
                            <header class="assistant-card-head">
                                <h4 class="assistant-item-title">{{ $draft['title'] ?? 'Draft Campaign' }}</h4>
                                @if(is_array($moduleState))
                                    <x-tenancy.module-state-badge :module-state="$moduleState" size="sm" compact />
                                @endif
                            </header>
                            <p class="assistant-copy">{{ $draft['description'] ?? '' }}</p>
                            <p class="assistant-note">{{ $draft['status'] ?? 'Locked' }}</p>
                            <p class="assistant-note">{{ $draft['review_note'] ?? '' }}</p>
                            @if($href !== '')
                                <a class="assistant-link" href="{{ $embeddedUrl($href) }}">Review details</a>
                            @endif
                        </article>
                    @empty
                        <p class="assistant-copy">No drafts are available yet.</p>
                    @endforelse
                </div>
            </article>
        @elseif($surface === 'setup')
            <article class="assistant-panel" aria-label="Assistant setup states">
                <h3 class="assistant-title">Setup</h3>
                <div class="assistant-grid assistant-grid--three">
                    @forelse(array_slice($setupItems, 0, 6) as $item)
                        @php($moduleState = is_array($item['module_state'] ?? null) ? $item['module_state'] : null)
                        @php($href = trim((string) ($item['href'] ?? '')))
                        <article class="assistant-card" data-assistant-setup="{{ $item['module_key'] ?? 'module' }}">
                            <header class="assistant-card-head">
                                <h4 class="assistant-item-title">{{ $item['label'] ?? 'Module' }}</h4>
                                @if(is_array($moduleState))
                                    <x-tenancy.module-state-badge :module-state="$moduleState" size="sm" compact />
                                @endif
                            </header>
                            <p class="assistant-note">{{ $item['setup_status_label'] ?? 'Not Started' }}</p>
                            <p class="assistant-copy">{{ $item['description'] ?? '' }}</p>
                            @if($href !== '')
                                <a class="assistant-link" href="{{ $embeddedUrl($href) }}">Open</a>
                            @endif
                        </article>
                    @empty
                        <p class="assistant-copy">No setup items are available yet.</p>
                    @endforelse
                </div>
            </article>
        @else
            <div class="assistant-grid assistant-grid--three">
                <article class="assistant-panel" aria-label="Activity hero">
                    <h3 class="assistant-title">Activity</h3>
                    <p class="assistant-item-title">{{ $activityHero['label'] ?? 'Workspace readiness' }}</p>
                    <p class="assistant-value">{{ $activityHero['value'] ?? 'Ready' }}</p>
                    <p class="assistant-copy">{{ $activityHero['supporting'] ?? '' }}</p>
                </article>

                <article class="assistant-panel" aria-label="Activity summary cards">
                    <h3 class="assistant-title">Summary</h3>
                    <div class="assistant-list">
                        @forelse(array_slice($activityCards, 0, 5) as $card)
                            <div class="assistant-list-item assistant-list-item--stack">
                                <p class="assistant-item-title">{{ $card['label'] ?? 'Metric' }}</p>
                                <p class="assistant-value">{{ $card['value'] ?? '0' }}</p>
                                <p class="assistant-copy">{{ $card['detail'] ?? '' }}</p>
                            </div>
                        @empty
                            <p class="assistant-copy">No summary metrics are available yet.</p>
                        @endforelse
                    </div>
                </article>

                <article class="assistant-panel" aria-label="Quick actions">
                    <h3 class="assistant-title">Quick Actions</h3>
                    <div class="assistant-list">
                        @forelse(array_slice($quickActions, 0, 5) as $action)
                            @php($href = trim((string) ($action['href'] ?? '')))
                            <div class="assistant-list-item assistant-list-item--stack">
                                <p class="assistant-item-title">{{ $action['label'] ?? 'Action' }}</p>
                                <p class="assistant-copy">{{ $action['description'] ?? '' }}</p>
                                @if($href !== '')
                                    <a class="assistant-link" href="{{ $embeddedUrl($href) }}">Open</a>
                                @endif
                            </div>
                        @empty
                            <p class="assistant-copy">No quick actions are available yet.</p>
                        @endforelse
                    </div>
                </article>
            </div>
        @endif
    </section>
</x-shopify-embedded-shell>
