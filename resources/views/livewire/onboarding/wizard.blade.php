<div
    data-onboarding-wizard-root
    data-tenant-token="{{ $tenantToken }}"
    data-tenant-id="{{ (int) $tenantId }}"
    data-contract-url="{{ $contractUrl }}"
    data-autosave-url="{{ $autosaveUrl }}"
    data-finalize-url="{{ $finalizeUrl }}"
    data-post-provisioning-summary-url="{{ $postProvisioningSummaryUrl }}"
    @if($provisionUrl)
        data-provision-url="{{ $provisionUrl }}"
    @endif
    data-can-provision="{{ $canProvision ? '1' : '0' }}"
    data-autosave-enabled="1"
    data-autosave-debounce-ms="900"
    @if($requestedRail)
        data-requested-rail="{{ $requestedRail }}"
    @endif
>
    <div class="fb-workflow-shell">
        <header class="fb-workflow-header">
            <div class="fb-eyebrow">Onboarding Wizard</div>
            <h1 class="fb-title-xl">Set up your tenant</h1>
            <p class="fb-subtitle">
                Build a blueprint from a few answers. This uses the existing onboarding seams (contract, autosave, finalize, post-provisioning summary).
            </p>

            <div class="fb-metric-grid">
                <div class="fb-metric">
                    <div class="fb-metric-label">Tenant</div>
                    <div class="fb-metric-value">{{ $tenantName !== '' ? $tenantName : $tenantToken }}</div>
                </div>
                <div class="fb-metric">
                    <div class="fb-metric-label">Rail</div>
                    <div class="fb-metric-value" data-ctx-rail>—</div>
                </div>
                <div class="fb-metric">
                    <div class="fb-metric-label">Account Mode</div>
                    <div class="fb-metric-value" data-ctx-account-mode>—</div>
                </div>
                <div class="fb-metric">
                    <div class="fb-metric-label">Autosave</div>
                    <div class="fb-metric-value">
                        <span class="fb-chip fb-chip--quiet" data-autosave-status>Not saved yet</span>
                    </div>
                </div>
            </div>
        </header>

        <div class="fb-workflow-grid">
            <aside class="min-w-0">
                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Progress</div>
                            <div class="fb-panel-copy">Steps come from the backend contract.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body">
                        <ol class="fb-stepper" data-onboarding-stepper>
                            <li class="fb-stepper-item" aria-current="step">
                                <span class="fb-stepper-badge">•</span>
                                <div>
                                    <div class="fb-stepper-title">
                                        <span class="fb-skeleton fb-skeleton--lg" style="max-width: 12rem; display:block;"></span>
                                    </div>
                                    <div class="fb-stepper-desc">
                                        <span class="fb-skeleton fb-skeleton--sm" style="max-width: 16rem; display:block;"></span>
                                    </div>
                                </div>
                            </li>
                            <li class="fb-stepper-item">
                                <span class="fb-stepper-badge">•</span>
                                <div>
                                    <div class="fb-stepper-title">
                                        <span class="fb-skeleton fb-skeleton--lg" style="max-width: 10rem; display:block;"></span>
                                    </div>
                                    <div class="fb-stepper-desc">
                                        <span class="fb-skeleton fb-skeleton--sm" style="max-width: 14rem; display:block;"></span>
                                    </div>
                                </div>
                            </li>
                        </ol>
                    </div>
                </section>

                <section class="fb-panel" style="margin-top: 1.1rem;">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Status</div>
                            <div class="fb-panel-copy">Read-only wizard client; no redirects.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body">
                        <div class="text-sm text-[var(--fb-text-secondary)]" data-onboarding-status>—</div>
                        <div class="mt-3 hidden fb-state fb-state--danger text-sm" data-onboarding-errors></div>
                    </div>
                </section>
            </aside>

            <section class="min-w-0">
                <div class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title" data-step-title>—</div>
                            <div class="fb-panel-copy" data-step-description>—</div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" class="fb-btn-soft fb-link-soft" data-action-save>Save draft</button>
                        </div>
                    </div>

                    <div class="fb-panel-body space-y-6">
                        <div class="hidden" data-step-panel="__unknown__">
                            <div class="fb-state text-sm">
                                <div class="font-semibold text-[var(--fb-text-primary)]">Unsupported step</div>
                                <div class="mt-1">
                                    This client doesn’t yet have a renderer for the current step key. The step catalog is backend-driven; add a renderer when ready.
                                </div>
                            </div>
                        </div>

                        <div class="hidden" data-step-panel="connect_shopify">
                            <div class="fb-state fb-state--warning text-sm">
                                <div class="font-semibold text-[var(--fb-text-primary)]">Shopify context required</div>
                                <div class="mt-1">
                                    This tenant is Shopify rail, but Shopify store context is not detected yet. Install/authorize the Shopify app for this tenant, then reload this wizard.
                                </div>
                            </div>
                        </div>

                        <div class="hidden space-y-6" data-step-panel="template_and_outcome">
                            <div>
                                <div class="fb-form-label">Business Type / Template</div>
                                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2" data-template-list>
                                    <div class="fb-state text-sm">
                                        <span class="fb-skeleton fb-skeleton--lg" style="max-width: 12rem; display:block;"></span>
                                        <span class="fb-skeleton fb-skeleton--sm" style="max-width: 18rem; display:block; margin-top: 0.55rem;"></span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="fb-form-label">First desired outcome</label>
                                <input
                                    type="text"
                                    class="fb-input mt-2"
                                    placeholder="e.g. first_sync, first_campaign, first_value"
                                    data-input="desired_outcome_first"
                                />
                                <div class="fb-help">Keep it short; this anchors initial checklist and recommendations.</div>
                            </div>
                        </div>

                        <div class="hidden space-y-6" data-step-panel="modules_and_data">
                            <div>
                                <div class="flex flex-wrap items-end justify-between gap-3">
                                    <div>
                                        <div class="fb-form-label">Modules</div>
                                        <div class="fb-help">Locked modules stay visible but can’t be newly selected.</div>
                                    </div>
                                    <button type="button" class="fb-btn-soft fb-link-soft" data-action-apply-recommended>
                                        Apply recommended
                                    </button>
                                </div>

                                <div class="mt-4 fb-module-grid" data-module-grid>
                                    @foreach($moduleCards as $module)
                                        @php
                                            $locked = (bool) ($module['locked'] ?? false);
                                            $comingSoon = (bool) ($module['coming_soon'] ?? false);
                                        @endphp
                                        <label
                                            class="fb-module-card {{ $locked ? 'is-locked' : '' }}"
                                            data-module-card
                                            data-module-key="{{ $module['module_key'] }}"
                                            data-module-locked="{{ $locked ? '1' : '0' }}"
                                        >
                                            <input
                                                type="checkbox"
                                                class="fb-module-card-checkbox size-4 rounded border-zinc-300"
                                                value="{{ $module['module_key'] }}"
                                                data-module-checkbox
                                            />
                                            <span class="flex-1">
                                                <span class="fb-module-card-title">{{ $module['label'] }}</span>
                                                @if(trim((string) ($module['description'] ?? '')) !== '')
                                                    <span class="fb-module-card-desc">{{ $module['description'] }}</span>
                                                @endif
                                                <span class="fb-module-card-badges">
                                                    <span class="fb-module-pill fb-module-pill--accent hidden" data-module-recommended-pill>Recommended</span>
                                                    @if($comingSoon)
                                                        <span class="fb-module-pill">Coming soon</span>
                                                    @endif
                                                    @if($locked)
                                                        <span class="fb-module-pill">Locked</span>
                                                    @endif
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="fb-form-label">Data source</label>
                                    <select
                                        class="fb-select mt-2"
                                        data-input="data_source"
                                        data-data-source-select
                                    >
                                        <option value="">Loading…</option>
                                    </select>
                                    <div class="fb-help">This is the intake path for initial data availability.</div>
                                </div>
                                <div>
                                    <label class="fb-form-label">Setup preferences (optional)</label>
                                    <textarea
                                        rows="4"
                                        class="fb-textarea mt-2"
                                        placeholder='{"timezone":"America/New_York"}'
                                        data-input="setup_preferences_json"
                                    ></textarea>
                                    <div class="fb-help">JSON object; leave blank if unsure.</div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden space-y-6" data-step-panel="mobile_intent">
                            <div class="fb-state">
                                <label class="flex items-center gap-3 text-sm font-semibold text-[var(--fb-text-primary)]">
                                    <input type="checkbox" class="size-4 rounded border-zinc-300" data-input="mobile_intent.needs_mobile_access" />
                                    Needs phone/field access
                                </label>
                                <div class="fb-help">Planned lightweight mobile companion; capture intent now.</div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2" data-mobile-details>
                                <div class="fb-state">
                                    <div class="fb-form-label">Roles needed</div>
                                    <div class="mt-3 space-y-2" data-mobile-roles></div>
                                </div>
                                <div class="fb-state">
                                    <div class="fb-form-label">Jobs requested</div>
                                    <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2" data-mobile-jobs></div>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="fb-form-label">Priority (optional)</label>
                                    <input
                                        type="text"
                                        class="fb-input mt-2"
                                        placeholder="low / medium / high"
                                        data-input="mobile_intent.mobile_priority"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="hidden space-y-6" data-step-panel="review_and_start">
                            <div class="fb-state">
                                <div class="text-sm font-semibold text-[var(--fb-text-primary)]">Review</div>
                                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div class="fb-surface-inset p-4">
                                        <div class="fb-form-label">Template</div>
                                        <div class="mt-1 text-sm font-semibold text-[var(--fb-text-primary)]" data-review-template>—</div>
                                    </div>
                                    <div class="fb-surface-inset p-4">
                                        <div class="fb-form-label">First outcome</div>
                                        <div class="mt-1 text-sm font-semibold text-[var(--fb-text-primary)]" data-review-outcome>—</div>
                                    </div>
                                    <div class="fb-surface-inset p-4 md:col-span-2">
                                        <div class="fb-form-label">Modules</div>
                                        <div class="mt-1 text-sm text-[var(--fb-text-secondary)]" data-review-modules>—</div>
                                    </div>
                                    <div class="fb-surface-inset p-4">
                                        <div class="fb-form-label">Data source</div>
                                        <div class="mt-1 text-sm font-semibold text-[var(--fb-text-primary)]" data-review-data-source>—</div>
                                    </div>
                                    <div class="fb-surface-inset p-4">
                                        <div class="fb-form-label">Mobile intent</div>
                                        <div class="mt-1 text-sm font-semibold text-[var(--fb-text-primary)]" data-review-mobile>—</div>
                                    </div>
                                </div>

                                <div class="mt-5 flex flex-wrap items-center gap-2">
                                    <button type="button" class="fb-btn-soft fb-btn-accent fb-link-soft" data-action-finalize>Finalize blueprint</button>
                                    <div class="text-xs text-[var(--fb-text-secondary)]" data-finalize-status>Finalization is blueprint-only (no redirects, no session mutation).</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div class="fb-panel">
                                    <div class="fb-panel-body">
                                        <div class="fb-form-label">Available now</div>
                                        <pre class="mt-3 whitespace-pre-wrap text-xs text-[var(--fb-text-secondary)]" data-nba-active>—</pre>
                                    </div>
                                </div>
                                <div class="fb-panel">
                                    <div class="fb-panel-body">
                                        <div class="fb-form-label">Setup next</div>
                                        <pre class="mt-3 whitespace-pre-wrap text-xs text-[var(--fb-text-secondary)]" data-nba-setup>—</pre>
                                    </div>
                                </div>
                                <div class="fb-panel">
                                    <div class="fb-panel-body">
                                        <div class="fb-form-label">Unlock next</div>
                                        <pre class="mt-3 whitespace-pre-wrap text-xs text-[var(--fb-text-secondary)]" data-nba-unlock>—</pre>
                                    </div>
                                </div>
                            </div>

                            <div class="fb-panel">
                                <div class="fb-panel-head">
                                    <div>
                                        <div class="fb-panel-title">What happens next</div>
                                        <div class="fb-panel-copy">Derived from the post-provisioning summary seam (single read).</div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" class="fb-btn-soft fb-link-soft" data-action-refresh-summary>Refresh summary</button>
                                        @if($canProvision)
                                            <button type="button" class="fb-btn-soft fb-link-soft" data-action-provision>Provision production tenant (internal)</button>
                                        @endif
                                    </div>
                                </div>

                                <div class="fb-panel-body">
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                        <div class="fb-state">
                                            <div class="fb-form-label">Provisioning status</div>
                                            <div class="mt-2 text-sm font-semibold text-[var(--fb-text-primary)]" data-summary-status>—</div>
                                        </div>
                                        <div class="fb-state">
                                            <div class="fb-form-label">Provisioned tenant</div>
                                            <div class="mt-2 text-sm font-semibold text-[var(--fb-text-primary)]" data-summary-tenant>—</div>
                                        </div>
                                        <div class="fb-state">
                                            <div class="fb-form-label">Ready for open</div>
                                            <div class="mt-2 text-sm font-semibold text-[var(--fb-text-primary)]" data-summary-ready>—</div>
                                        </div>
                                    </div>

                                    <div class="mt-4 fb-state">
                                        <div class="fb-form-label">Recommended first screen</div>
                                        <div class="mt-2 text-sm text-[var(--fb-text-secondary)]" data-summary-first-screen>—</div>
                                        <div class="mt-3 flex flex-wrap items-center gap-2">
                                            <a class="hidden fb-btn-soft fb-btn-accent fb-link-soft" data-summary-open-link target="_blank" rel="noopener noreferrer">
                                                Open recommended screen
                                            </a>
                                            <span class="text-xs text-[var(--fb-text-muted)]" data-summary-notes>—</span>
                                        </div>
                                    </div>

                                    <details class="mt-4 fb-state" data-summary-debug>
                                        <summary class="cursor-pointer text-sm font-semibold text-[var(--fb-text-primary)]">Debug payloads</summary>
                                        <pre class="mt-3 max-h-[420px] overflow-auto whitespace-pre-wrap fb-code-block" data-summary-raw>—</pre>
                                    </details>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="fb-action-row">
                        <button type="button" class="fb-btn-soft fb-link-soft" data-action-back>Back</button>
                        <button type="button" class="fb-btn-soft fb-btn-accent fb-link-soft" data-action-next>Next</button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

