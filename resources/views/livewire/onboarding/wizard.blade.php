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
    <div class="space-y-6">
        <header class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-zinc-500">Onboarding Wizard (Skeleton)</div>
            <h1 class="mt-2 text-3xl font-semibold text-zinc-950">Set up your tenant</h1>
            <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                Build a blueprint from a few answers. This UI consumes the existing onboarding API seams (contract, autosave, finalize, post-provisioning summary).
            </p>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Tenant</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-950">{{ $tenantName !== '' ? $tenantName : $tenantToken }}</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Rail</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-950" data-ctx-rail>—</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Account Mode</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-950" data-ctx-account-mode>—</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Autosave</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-950" data-autosave-status>Not saved yet</div>
                </div>
            </div>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <aside class="lg:col-span-4">
                <div class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="text-sm font-semibold text-zinc-950">Progress</div>
                    <ol class="mt-4 space-y-2" data-onboarding-stepper>
                        <li class="text-sm text-zinc-500">Loading contract…</li>
                    </ol>
                </div>

                <div class="mt-6 rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="text-sm font-semibold text-zinc-950">Status</div>
                    <div class="mt-2 text-sm text-zinc-600" data-onboarding-status>—</div>
                    <div class="mt-3 hidden rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-onboarding-errors></div>
                </div>
            </aside>

            <section class="lg:col-span-8">
                <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-zinc-950" data-step-title>—</div>
                            <div class="mt-1 text-sm text-zinc-600" data-step-description>—</div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded-full border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-50" data-action-save>
                                Save draft
                            </button>
                        </div>
                    </div>

                    <div class="mt-6 space-y-6">
                        <div class="hidden" data-step-panel="__unknown__">
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 text-sm text-zinc-800">
                                <div class="font-semibold">Unsupported step</div>
                                <div class="mt-1 text-zinc-600">
                                    This wizard client doesn’t yet have a UI renderer for the current step key.
                                    The step catalog is backend-driven; add a renderer for this step when ready.
                                </div>
                            </div>
                        </div>

                        <div class="hidden" data-step-panel="connect_shopify">
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                                <div class="font-semibold">Shopify context required</div>
                                <div class="mt-1 text-amber-800">
                                    This tenant is Shopify rail, but Shopify store context is not detected yet. Install/authorize the Shopify app for this tenant, then reload this wizard.
                                </div>
                            </div>
                        </div>

                        <div class="hidden space-y-6" data-step-panel="template_and_outcome">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Business Type / Template</div>
                                <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-2" data-template-list>
                                    <div class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
                                        Loading templates…
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-600">First desired outcome</label>
                                <input
                                    type="text"
                                    class="mt-2 w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-950"
                                    placeholder="e.g. first_sync, first_campaign, first_value"
                                    data-input="desired_outcome_first"
                                />
                                <div class="mt-2 text-xs text-zinc-500">Keep it short; this anchors the initial checklist and recommendations.</div>
                            </div>
                        </div>

                        <div class="hidden space-y-6" data-step-panel="modules_and_data">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Modules</div>
                                <div class="mt-2 text-sm text-zinc-600">Locked modules stay visible but can’t be newly selected.</div>
                                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2" data-module-grid>
                                    @foreach($moduleCards as $module)
                                        @php
                                            $locked = (bool) ($module['locked'] ?? false);
                                            $comingSoon = (bool) ($module['coming_soon'] ?? false);
                                        @endphp
                                        <label
                                            class="flex gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-sm text-zinc-900 transition hover:bg-zinc-50 {{ $locked ? 'opacity-60' : '' }}"
                                            data-module-card
                                            data-module-key="{{ $module['module_key'] }}"
                                            data-module-locked="{{ $locked ? '1' : '0' }}"
                                        >
                                            <input
                                                type="checkbox"
                                                class="mt-1 size-4 rounded border-zinc-300"
                                                value="{{ $module['module_key'] }}"
                                                data-module-checkbox
                                            />
                                            <span class="flex-1">
                                                <span class="flex items-center gap-2">
                                                    <span class="font-semibold">{{ $module['label'] }}</span>
                                                    @if($comingSoon)
                                                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-700">Coming soon</span>
                                                    @endif
                                                    @if($locked)
                                                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-700">Locked</span>
                                                    @endif
                                                </span>
                                                @if(trim((string) ($module['description'] ?? '')) !== '')
                                                    <span class="mt-1 block text-xs text-zinc-600">{{ $module['description'] }}</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Data source</label>
                                    <select
                                        class="mt-2 w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-950"
                                        data-input="data_source"
                                        data-data-source-select
                                    >
                                        <option value="">Loading…</option>
                                    </select>
                                    <div class="mt-2 text-xs text-zinc-500">This is the intake path for initial data availability.</div>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Setup preferences (optional)</label>
                                    <textarea
                                        rows="4"
                                        class="mt-2 w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3 font-mono text-xs text-zinc-950"
                                        placeholder='{"timezone":"America/New_York"}'
                                        data-input="setup_preferences_json"
                                    ></textarea>
                                    <div class="mt-2 text-xs text-zinc-500">JSON object; leave blank if unsure.</div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden space-y-6" data-step-panel="mobile_intent">
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5">
                                <label class="flex items-center gap-3 text-sm font-semibold text-zinc-900">
                                    <input type="checkbox" class="size-4 rounded border-zinc-300" data-input="mobile_intent.needs_mobile_access" />
                                    Needs phone/field access
                                </label>
                                <div class="mt-2 text-xs text-zinc-600">Planned lightweight mobile companion; capture intent now.</div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2" data-mobile-details>
                                <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Roles needed</div>
                                    <div class="mt-3 space-y-2" data-mobile-roles></div>
                                </div>
                                <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Jobs requested</div>
                                    <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2" data-mobile-jobs></div>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Priority (optional)</label>
                                    <input
                                        type="text"
                                        class="mt-2 w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-950"
                                        placeholder="low / medium / high"
                                        data-input="mobile_intent.mobile_priority"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="hidden space-y-6" data-step-panel="review_and_start">
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5">
                                <div class="text-sm font-semibold text-zinc-950">Review</div>
                                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div class="rounded-xl border border-zinc-200 bg-white p-4">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500">Template</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-950" data-review-template>—</div>
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 bg-white p-4">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500">First outcome</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-950" data-review-outcome>—</div>
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 bg-white p-4 md:col-span-2">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500">Modules</div>
                                        <div class="mt-1 text-sm text-zinc-800" data-review-modules>—</div>
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 bg-white p-4">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500">Data source</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-950" data-review-data-source>—</div>
                                    </div>
                                    <div class="rounded-xl border border-zinc-200 bg-white p-4">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500">Mobile intent</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-950" data-review-mobile>—</div>
                                    </div>
                                </div>

                                <div class="mt-5 flex flex-wrap items-center gap-2">
                                    <button type="button" class="rounded-full bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800" data-action-finalize>
                                        Finalize blueprint
                                    </button>
                                    <div class="text-xs text-zinc-600" data-finalize-status>Finalization is blueprint-only (no redirects, no session mutation).</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Available now</div>
                                    <pre class="mt-3 whitespace-pre-wrap text-xs text-zinc-700" data-nba-active>—</pre>
                                </div>
                                <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Setup next</div>
                                    <pre class="mt-3 whitespace-pre-wrap text-xs text-zinc-700" data-nba-setup>—</pre>
                                </div>
                                <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Unlock next</div>
                                    <pre class="mt-3 whitespace-pre-wrap text-xs text-zinc-700" data-nba-unlock>—</pre>
                                </div>
                            </div>

                            <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-zinc-950">What happens next</div>
                                        <div class="mt-1 text-sm text-zinc-600">Derived from the post-provisioning summary seam (single read).</div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" class="rounded-full border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-50" data-action-refresh-summary>
                                            Refresh summary
                                        </button>
                                        @if($canProvision)
                                            <button type="button" class="rounded-full border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-50" data-action-provision>
                                                Provision production tenant (internal)
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500">Provisioning status</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-950" data-summary-status>—</div>
                                    </div>
                                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500">Provisioned tenant</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-950" data-summary-tenant>—</div>
                                    </div>
                                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500">Ready for open</div>
                                        <div class="mt-1 text-sm font-semibold text-zinc-950" data-summary-ready>—</div>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-2xl border border-zinc-200 bg-white p-5">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-600">Recommended first screen</div>
                                    <div class="mt-2 text-sm text-zinc-800" data-summary-first-screen>—</div>
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <a class="hidden rounded-full bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800" data-summary-open-link target="_blank" rel="noopener noreferrer">
                                            Open recommended screen
                                        </a>
                                        <span class="text-xs text-zinc-500" data-summary-notes>—</span>
                                    </div>
                                </div>

                                <details class="mt-4 rounded-2xl border border-zinc-200 bg-white p-5" data-summary-debug>
                                    <summary class="cursor-pointer text-sm font-semibold text-zinc-950">Debug payloads</summary>
                                    <pre class="mt-3 max-h-[420px] overflow-auto whitespace-pre-wrap rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-xs text-zinc-700" data-summary-raw>—</pre>
                                </details>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 flex items-center justify-between">
                        <button type="button" class="rounded-full border border-zinc-200 bg-white px-5 py-2.5 text-sm font-semibold text-zinc-900 hover:bg-zinc-50" data-action-back>
                            Back
                        </button>
                        <button type="button" class="rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800" data-action-next>
                            Next
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
