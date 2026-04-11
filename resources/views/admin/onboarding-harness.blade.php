<x-app-layout>
    <div
        id="onboarding-harness"
        class="space-y-6"
        data-contract-url="{{ $contractUrl }}"
        data-autosave-url="{{ $autosaveUrl }}"
        data-tenant-token="{{ $tenantToken }}"
        data-csrf="{{ csrf_token() }}"
    >
        <section class="fb-page-surface fb-page-surface--subtle p-6">
            <div class="fb-kpi-label">Internal tool</div>
            <h1 class="mt-2 text-3xl font-semibold text-zinc-950">Onboarding Harness (Internal)</h1>
            <p class="mt-2 text-sm text-zinc-600">
                Minimal debug UI for Stage 1A onboarding contracts. Calls backend endpoints; not production onboarding UX.
            </p>
        </section>

        <section class="fb-page-surface p-6">
            <div class="flex items-center gap-3">
                <button id="load-contract" type="button" class="fb-btn-soft fb-link-soft rounded-full">
                    Load contract
                </button>
                <button id="save-draft" type="button" class="fb-btn-soft fb-link-soft rounded-full">
                    Autosave now
                </button>
                <label class="ml-auto flex items-center gap-2 text-sm text-zinc-700">
                    <input id="autosave-enabled" type="checkbox" class="rounded border-zinc-300" checked>
                    Autosave on change
                </label>
            </div>

            <div id="status" class="mt-3 text-sm text-zinc-600"></div>
            <div id="errors" class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"></div>
        </section>

        <section class="fb-page-surface p-6">
            <div class="text-sm font-semibold text-zinc-950">Context</div>
            <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Rail</div>
                    <div id="ctx-rail" class="mt-1 text-sm font-semibold text-zinc-950">—</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Account Mode</div>
                    <div id="ctx-mode" class="mt-1 text-sm font-semibold text-zinc-950">—</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Tenant</div>
                    <div class="mt-1 text-sm font-semibold text-zinc-950">{{ $tenantToken }}</div>
                </div>
            </div>
        </section>

        <section class="fb-page-surface p-6">
            <div class="text-sm font-semibold text-zinc-950">Draft (Editable)</div>
            <div class="mt-1 text-sm text-zinc-600">These fields POST to <code class="text-xs">/api/onboarding/blueprint-draft</code>.</div>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="text-xs font-semibold text-zinc-700">Rail</label>
                    <select id="draft-rail" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white p-2 text-sm">
                        <option value="shopify">shopify</option>
                        <option value="direct">direct</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-zinc-700">Data Source</label>
                    <select id="draft-data-source" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white p-2 text-sm">
                        <option value="">(unset)</option>
                        <option value="shopify">shopify</option>
                        <option value="csv">csv</option>
                        <option value="manual">manual</option>
                        <option value="connector">connector</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold text-zinc-700">Template Key</label>
                    <input id="draft-template-key" type="text" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white p-2 text-sm" placeholder="e.g. candle">
                </div>
                <div>
                    <label class="text-xs font-semibold text-zinc-700">Desired Outcome First</label>
                    <input id="draft-outcome" type="text" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white p-2 text-sm" placeholder="e.g. first_sync">
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs font-semibold text-zinc-700">Selected Modules (comma-separated)</label>
                    <input id="draft-modules" type="text" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white p-2 text-sm" placeholder="customers, rewards, ...">
                    <div id="recommended-modules" class="mt-2 text-xs text-zinc-600"></div>
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs font-semibold text-zinc-700">Setup Preferences (JSON)</label>
                    <textarea id="draft-setup-preferences" rows="4" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white p-2 font-mono text-xs"></textarea>
                    <div class="mt-1 text-xs text-zinc-500">Keep this config/answers oriented; no operational/demo data.</div>
                </div>
            </div>

            <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-semibold text-zinc-950">Mobile Intent (planned)</div>
                        <div class="text-xs text-zinc-600">Capture planning-only intent; does not enable any mobile runtime.</div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-zinc-700">
                        <input id="mobile-needs" type="checkbox" class="rounded border-zinc-300">
                        Needs mobile access
                    </label>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <div class="text-xs font-semibold text-zinc-700">Mobile Roles Needed</div>
                        <div id="mobile-roles" class="mt-2 space-y-1 text-sm text-zinc-700"></div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-zinc-700">Mobile Jobs Requested</div>
                        <div id="mobile-jobs" class="mt-2 grid grid-cols-1 gap-1 text-sm text-zinc-700 md:grid-cols-2"></div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-semibold text-zinc-700">Mobile Priority</label>
                        <input id="mobile-priority" type="text" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white p-2 text-sm" placeholder="low / medium / high (optional)">
                    </div>
                </div>
            </div>
        </section>

        <section class="fb-page-surface p-6">
            <div class="text-sm font-semibold text-zinc-950">Visible Steps</div>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                    <tr class="text-xs uppercase tracking-wide text-zinc-500">
                        <th class="py-2 pr-4">Seq</th>
                        <th class="py-2 pr-4">Key</th>
                        <th class="py-2 pr-4">Title</th>
                        <th class="py-2 pr-4">Rail</th>
                        <th class="py-2 pr-4">Required Inputs</th>
                    </tr>
                    </thead>
                    <tbody id="steps-table" class="text-zinc-800"></tbody>
                </table>
            </div>
        </section>

        <section class="fb-page-surface p-6">
            <div class="text-sm font-semibold text-zinc-950">Next Best Actions</div>
            <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Available Now</div>
                    <pre id="nba-active" class="mt-2 whitespace-pre-wrap text-xs text-zinc-700"></pre>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Setup Next</div>
                    <pre id="nba-setup" class="mt-2 whitespace-pre-wrap text-xs text-zinc-700"></pre>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">Unlock Next</div>
                    <pre id="nba-unlock" class="mt-2 whitespace-pre-wrap text-xs text-zinc-700"></pre>
                </div>
            </div>
        </section>

        <section class="fb-page-surface p-6">
            <div class="text-sm font-semibold text-zinc-950">Raw Contract (Debug)</div>
            <pre id="raw-contract" class="mt-3 max-h-[520px] overflow-auto rounded-xl border border-zinc-200 bg-white p-4 text-xs text-zinc-800"></pre>
        </section>
    </div>

    @push('scripts')
        <script>
            (function () {
                const root = document.getElementById('onboarding-harness');
                const contractUrl = root.dataset.contractUrl;
                const autosaveUrl = root.dataset.autosaveUrl;
                const csrf = root.dataset.csrf;

                const statusEl = document.getElementById('status');
                const errorsEl = document.getElementById('errors');

                const ctxRail = document.getElementById('ctx-rail');
                const ctxMode = document.getElementById('ctx-mode');

                const draftRail = document.getElementById('draft-rail');
                const draftTemplateKey = document.getElementById('draft-template-key');
                const draftOutcome = document.getElementById('draft-outcome');
                const draftModules = document.getElementById('draft-modules');
                const draftDataSource = document.getElementById('draft-data-source');
                const draftSetupPreferences = document.getElementById('draft-setup-preferences');

                const mobileNeeds = document.getElementById('mobile-needs');
                const mobileRoles = document.getElementById('mobile-roles');
                const mobileJobs = document.getElementById('mobile-jobs');
                const mobilePriority = document.getElementById('mobile-priority');

                const stepsTable = document.getElementById('steps-table');
                const rawContract = document.getElementById('raw-contract');
                const recommendedModules = document.getElementById('recommended-modules');
                const autosaveEnabled = document.getElementById('autosave-enabled');

                const nbaActive = document.getElementById('nba-active');
                const nbaSetup = document.getElementById('nba-setup');
                const nbaUnlock = document.getElementById('nba-unlock');

                let contract = null;
                let autosaveTimer = null;

                function setStatus(msg) {
                    statusEl.textContent = msg || '';
                }

                function clearErrors() {
                    errorsEl.classList.add('hidden');
                    errorsEl.textContent = '';
                }

                function showErrors(payload) {
                    errorsEl.classList.remove('hidden');
                    if (payload && payload.errors) {
                        const parts = [];
                        for (const [key, messages] of Object.entries(payload.errors)) {
                            parts.push(`${key}: ${(messages || []).join(' ')}`);
                        }
                        errorsEl.textContent = parts.join('\n') || 'Validation failed.';
                        return;
                    }
                    errorsEl.textContent = (payload && payload.message) ? payload.message : 'Request failed.';
                }

                function parseJsonOrEmpty(raw) {
                    const trimmed = (raw || '').trim();
                    if (trimmed === '') return {};
                    return JSON.parse(trimmed);
                }

                function splitModules(raw) {
                    return (raw || '')
                        .split(',')
                        .map(s => s.trim())
                        .filter(Boolean);
                }

                function checkboxList(container, values, selected) {
                    container.innerHTML = '';
                    values.forEach(v => {
                        const id = `${container.id}-${v}`;
                        const label = document.createElement('label');
                        label.className = 'flex items-center gap-2';
                        const cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.id = id;
                        cb.value = v;
                        cb.className = 'rounded border-zinc-300';
                        cb.checked = selected.includes(v);
                        cb.addEventListener('change', scheduleAutosave);
                        const span = document.createElement('span');
                        span.textContent = v;
                        label.appendChild(cb);
                        label.appendChild(span);
                        container.appendChild(label);
                    });
                }

                function selectedCheckboxValues(container) {
                    const inputs = container.querySelectorAll('input[type="checkbox"]');
                    return Array.from(inputs).filter(i => i.checked).map(i => i.value);
                }

                function renderSteps(steps) {
                    stepsTable.innerHTML = '';
                    (steps || []).forEach(step => {
                        const tr = document.createElement('tr');
                        tr.className = 'border-t border-zinc-100';
                        const required = (step.required_inputs || []).join(', ');
                        tr.innerHTML = `
                          <td class="py-2 pr-4 text-zinc-500">${step.sequence ?? ''}</td>
                          <td class="py-2 pr-4 font-mono text-xs">${step.step_key ?? ''}</td>
                          <td class="py-2 pr-4">${step.title ?? ''}</td>
                          <td class="py-2 pr-4 text-zinc-600">${step.rail_visibility ?? ''}</td>
                          <td class="py-2 pr-4 text-zinc-600">${required}</td>
                        `;
                        stepsTable.appendChild(tr);
                    });
                }

                function renderRecommendations(rec) {
                    const mods = (rec && rec.recommended_modules) ? rec.recommended_modules : [];
                    if (!mods.length) {
                        recommendedModules.textContent = 'Recommended modules: (none)';
                        return;
                    }

                    const current = new Set(splitModules(draftModules.value));
                    const parts = mods.map(m => {
                        const active = current.has(m);
                        return `<button type="button" data-mod="${m}" class="underline ${active ? 'text-zinc-400' : 'text-zinc-700'}">${m}</button>`;
                    });
                    recommendedModules.innerHTML = `Recommended modules: ${parts.join(', ')}`;

                    recommendedModules.querySelectorAll('button[data-mod]').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const mod = btn.dataset.mod;
                            const set = new Set(splitModules(draftModules.value));
                            set.add(mod);
                            draftModules.value = Array.from(set).join(', ');
                            scheduleAutosave();
                        });
                    });
                }

                function renderNextBestActions(nba) {
                    nbaActive.textContent = JSON.stringify(nba?.available_now ?? [], null, 2);
                    nbaSetup.textContent = JSON.stringify(nba?.setup_next ?? [], null, 2);
                    nbaUnlock.textContent = JSON.stringify(nba?.unlock_next ?? [], null, 2);
                }

                function hydrateDraftFromResponse(response) {
                    const contractPayload = response.contract || {};
                    const draftPayload = response.draft && response.draft.payload ? response.draft.payload : null;

                    ctxRail.textContent = contractPayload.context?.rail ?? '—';
                    ctxMode.textContent = contractPayload.context?.account_mode ?? '—';

                    const defaults = contractPayload.defaults || {};
                    const effective = Object.assign({}, defaults, draftPayload || {});

                    draftRail.value = effective.rail || (contractPayload.context?.rail ?? 'direct');
                    draftTemplateKey.value = effective.template_key || '';
                    draftOutcome.value = effective.desired_outcome_first || '';
                    draftDataSource.value = effective.data_source || '';
                    draftModules.value = Array.isArray(effective.selected_modules) ? effective.selected_modules.join(', ') : '';

                    try {
                        draftSetupPreferences.value = JSON.stringify(effective.setup_preferences || {}, null, 2);
                    } catch (e) {
                        draftSetupPreferences.value = '{}';
                    }

                    const mobile = effective.mobile_intent || {};
                    mobileNeeds.checked = !!mobile.needs_mobile_access;
                    mobilePriority.value = mobile.mobile_priority || '';

                    const roles = contractPayload.blueprint_contract?.mobile_roles || [];
                    const jobs = contractPayload.blueprint_contract?.mobile_jobs || [];

                    checkboxList(mobileRoles, roles, Array.isArray(mobile.mobile_roles_needed) ? mobile.mobile_roles_needed : []);
                    checkboxList(mobileJobs, jobs, Array.isArray(mobile.mobile_jobs_requested) ? mobile.mobile_jobs_requested : []);

                    renderSteps(contractPayload.steps || []);
                    renderRecommendations(contractPayload.recommendations || {});
                    renderNextBestActions(contractPayload.next_best_actions || {});

                    rawContract.textContent = JSON.stringify(response, null, 2);
                }

                async function loadContract() {
                    clearErrors();
                    setStatus('Loading contract…');

                    const res = await fetch(contractUrl, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });

                    const payload = await res.json().catch(() => null);
                    if (!res.ok) {
                        showErrors(payload);
                        setStatus(`Load failed (${res.status})`);
                        return;
                    }

                    contract = payload;
                    hydrateDraftFromResponse(payload);
                    setStatus('Contract loaded.');
                }

                function draftPayload() {
                    let setupPreferences = {};
                    try {
                        setupPreferences = parseJsonOrEmpty(draftSetupPreferences.value);
                    } catch (e) {
                        // Let server return JSON parse errors as a 422 by sending a non-object marker.
                        setupPreferences = "__invalid_json__";
                    }

                    return {
                        rail: draftRail.value,
                        template_key: (draftTemplateKey.value || '').trim() || null,
                        desired_outcome_first: (draftOutcome.value || '').trim() || null,
                        selected_modules: splitModules(draftModules.value),
                        data_source: (draftDataSource.value || '').trim() || null,
                        setup_preferences: setupPreferences,
                        mobile_intent: {
                            needs_mobile_access: !!mobileNeeds.checked,
                            mobile_roles_needed: selectedCheckboxValues(mobileRoles),
                            mobile_jobs_requested: selectedCheckboxValues(mobileJobs),
                            mobile_priority: (mobilePriority.value || '').trim() || null,
                        },
                    };
                }

                async function autosave() {
                    clearErrors();
                    setStatus('Saving draft…');

                    const payload = draftPayload();

                    const res = await fetch(autosaveUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload),
                    });

                    const body = await res.json().catch(() => null);
                    if (!res.ok) {
                        showErrors(body);
                        setStatus(`Save failed (${res.status})`);
                        return;
                    }

                    setStatus('Draft saved.');
                    // Reload contract to rehydrate canonicalized values and recommendations.
                    await loadContract();
                }

                function scheduleAutosave() {
                    if (!autosaveEnabled.checked) {
                        return;
                    }

                    if (autosaveTimer) {
                        clearTimeout(autosaveTimer);
                    }
                    autosaveTimer = setTimeout(() => autosave().catch(() => {}), 700);
                }

                document.getElementById('load-contract').addEventListener('click', () => loadContract().catch(() => {}));
                document.getElementById('save-draft').addEventListener('click', () => autosave().catch(() => {}));

                [
                    draftRail,
                    draftTemplateKey,
                    draftOutcome,
                    draftModules,
                    draftDataSource,
                    draftSetupPreferences,
                    mobileNeeds,
                    mobilePriority,
                ].forEach(el => el.addEventListener('input', scheduleAutosave));

                // Initial load.
                loadContract().catch(() => {});
            })();
        </script>
    @endpush
</x-app-layout>
