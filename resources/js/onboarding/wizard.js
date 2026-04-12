function safeJsonParse(raw, fallback) {
  try {
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
}

function normalizeString(value) {
  const cast = typeof value === "string" ? value : `${value ?? ""}`;
  const trimmed = cast.trim();
  return trimmed === "" ? null : trimmed;
}

function getByPath(obj, path) {
  if (!obj || typeof obj !== "object") return undefined;
  if (!path) return undefined;
  return path.split(".").reduce((acc, key) => (acc && typeof acc === "object" ? acc[key] : undefined), obj);
}

function hasValue(value) {
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === "boolean") return true;
  if (value === null || value === undefined) return false;
  if (typeof value === "number") return true;
  return `${value}`.trim() !== "";
}

function uniqStrings(values) {
  const out = [];
  (values || []).forEach((value) => {
    const key = normalizeString(value);
    if (key && !out.includes(key)) out.push(key);
  });
  return out;
}

export function mountOnboardingWizardNow() {
  const root = document.querySelector("[data-onboarding-wizard-root]");
  if (!root) return;
  if (root.__mfOnboardingWizardMounted) return;
  root.__mfOnboardingWizardMounted = true;

  const contractUrl = root.dataset.contractUrl;
  const autosaveUrl = root.dataset.autosaveUrl;
  const finalizeUrl = root.dataset.finalizeUrl;
  const summaryUrl = root.dataset.postProvisioningSummaryUrl;
  const provisionUrl = root.dataset.provisionUrl || null;
  const canProvision = root.dataset.canProvision === "1";

  const autosaveStatusEl = root.querySelector("[data-autosave-status]");
  const ctxRailEl = root.querySelector("[data-ctx-rail]");
  const ctxAccountModeEl = root.querySelector("[data-ctx-account-mode]");
  const statusEl = root.querySelector("[data-onboarding-status]");
  const errorsEl = root.querySelector("[data-onboarding-errors]");

  const stepperEl = root.querySelector("[data-onboarding-stepper]");
  const stepTitleEl = root.querySelector("[data-step-title]");
  const stepDescriptionEl = root.querySelector("[data-step-description]");

  const saveBtn = root.querySelector("[data-action-save]");
  const backBtn = root.querySelector("[data-action-back]");
  const nextBtn = root.querySelector("[data-action-next]");
  const finalizeBtn = root.querySelector("[data-action-finalize]");
  const finalizeStatusEl = root.querySelector("[data-finalize-status]");

  const templateListEl = root.querySelector("[data-template-list]");
  const dataSourceSelectEl = root.querySelector("[data-data-source-select]");

  const moduleGridEl = root.querySelector("[data-module-grid]");
  const moduleCards = Array.from(root.querySelectorAll("[data-module-card]"));
  const moduleCheckboxes = Array.from(root.querySelectorAll("[data-module-checkbox]"));

  const mobileDetailsEl = root.querySelector("[data-mobile-details]");
  const mobileRolesEl = root.querySelector("[data-mobile-roles]");
  const mobileJobsEl = root.querySelector("[data-mobile-jobs]");

  const reviewTemplateEl = root.querySelector("[data-review-template]");
  const reviewOutcomeEl = root.querySelector("[data-review-outcome]");
  const reviewModulesEl = root.querySelector("[data-review-modules]");
  const reviewDataSourceEl = root.querySelector("[data-review-data-source]");
  const reviewMobileEl = root.querySelector("[data-review-mobile]");

  const nbaActiveEl = root.querySelector("[data-nba-active]");
  const nbaSetupEl = root.querySelector("[data-nba-setup]");
  const nbaUnlockEl = root.querySelector("[data-nba-unlock]");

  const refreshSummaryBtn = root.querySelector("[data-action-refresh-summary]");
  const provisionBtn = root.querySelector("[data-action-provision]");
  const summaryStatusEl = root.querySelector("[data-summary-status]");
  const summaryTenantEl = root.querySelector("[data-summary-tenant]");
  const summaryReadyEl = root.querySelector("[data-summary-ready]");
  const summaryFirstScreenEl = root.querySelector("[data-summary-first-screen]");
  const summaryNotesEl = root.querySelector("[data-summary-notes]");
  const summaryOpenLinkEl = root.querySelector("[data-summary-open-link]");
  const summaryRawEl = root.querySelector("[data-summary-raw]");

  const panels = new Map(
    Array.from(root.querySelectorAll("[data-step-panel]")).map((el) => [
      el.getAttribute("data-step-panel"),
      el,
    ]),
  );

  const state = {
    contract: null,
    draft: null,
    final: null,
    summary: null,
    currentStepIndex: 0,
    saving: false,
    dirty: false,
    autosaveTimer: null,
    blueprint: {
      rail: root.dataset.requestedRail || null,
      template_key: null,
      desired_outcome_first: null,
      selected_modules: [],
      data_source: null,
      setup_preferences: {},
      mobile_intent: {
        needs_mobile_access: false,
        mobile_roles_needed: [],
        mobile_jobs_requested: [],
        mobile_priority: null,
      },
    },
  };

  function setStatus(message) {
    if (statusEl) statusEl.textContent = message || "—";
  }

  function clearErrors() {
    if (!errorsEl) return;
    errorsEl.classList.add("hidden");
    errorsEl.textContent = "";
  }

  function showErrors(payload) {
    if (!errorsEl) return;
    errorsEl.classList.remove("hidden");
    if (typeof payload === "string") {
      errorsEl.textContent = payload;
      return;
    }
    if (payload && typeof payload === "object" && payload.errors) {
      const lines = [];
      Object.entries(payload.errors).forEach(([key, values]) => {
        (Array.isArray(values) ? values : [values]).forEach((msg) => {
          lines.push(`${key}: ${msg}`);
        });
      });
      errorsEl.textContent = lines.join("\n");
      return;
    }
    errorsEl.textContent = "Request failed.";
  }

  function setAutosaveStatus(message) {
    if (autosaveStatusEl) autosaveStatusEl.textContent = message || "—";
  }

  function currentStep() {
    const steps = state.contract?.steps || [];
    return steps[state.currentStepIndex] || null;
  }

  function stepComplete(step) {
    const required = Array.isArray(step?.required_inputs) ? step.required_inputs : [];
    return required.every((path) => hasValue(getByPath(state.blueprint, path)));
  }

  function buildStepper() {
    if (!stepperEl) return;
    stepperEl.innerHTML = "";

    const steps = state.contract?.steps || [];
    steps.forEach((step, idx) => {
      const li = document.createElement("li");
      const done = stepComplete(step);
      const isCurrent = idx === state.currentStepIndex;

      li.className = "fb-stepper-item";
      li.setAttribute("aria-current", isCurrent ? "step" : "false");
      if (done) {
        li.classList.add("is-complete");
      }

      const badge = document.createElement("div");
      badge.className = "fb-stepper-badge";
      badge.textContent = `${idx + 1}`;

      const copy = document.createElement("div");
      const title = document.createElement("div");
      title.className = "fb-stepper-title";
      title.textContent = step?.title || step?.step_key || `Step ${idx + 1}`;

      const desc = document.createElement("div");
      desc.className = "fb-stepper-desc";
      desc.textContent = step?.description || "";

      copy.appendChild(title);
      if (desc.textContent) copy.appendChild(desc);

      li.appendChild(badge);
      li.appendChild(copy);

      li.addEventListener("click", async () => {
        await maybeAutosave();
        state.currentStepIndex = idx;
        render();
      });

      stepperEl.appendChild(li);
    });
  }

  function showPanel(stepKey) {
    const exact = panels.get(stepKey);
    const fallback = panels.get("__unknown__");
    panels.forEach((panel, key) => {
      if (!panel) return;
      if (key === "__unknown__") {
        panel.classList.toggle("hidden", !!exact);
        return;
      }
      panel.classList.toggle("hidden", key !== stepKey);
    });
    if (!exact && fallback) {
      fallback.classList.remove("hidden");
    }

    const shown = exact || fallback;
    if (shown) {
      shown.classList.remove("fb-motion-enter");
      // Force reflow so the animation can replay on each step switch.
      void shown.offsetWidth; // eslint-disable-line no-unused-expressions
      shown.classList.add("fb-motion-enter");
    }
  }

  function renderTemplates() {
    if (!templateListEl) return;
    templateListEl.innerHTML = "";

    const templates = Array.isArray(state.contract?.options?.templates)
      ? state.contract.options.templates
      : [];

    if (templates.length === 0) {
      const empty = document.createElement("div");
      empty.className =
        "rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600";
      empty.textContent = "No templates configured.";
      templateListEl.appendChild(empty);
      return;
    }

    templates.forEach((tpl) => {
      const key = normalizeString(tpl?.key);
      if (!key) return;
      const name = normalizeString(tpl?.name) || key;

      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "fb-state text-left";
      if (state.blueprint.template_key === key) {
        btn.style.borderColor = "rgba(18, 60, 67, 0.28)";
        btn.style.background = "rgba(30, 90, 99, 0.08)";
      }
      btn.dataset.templateKey = key;

      const title = document.createElement("div");
      title.className = "text-sm font-semibold text-[var(--fb-text-primary)]";
      title.textContent = name;

      const meta = document.createElement("div");
      meta.className = "mt-1 text-xs text-[var(--fb-text-secondary)]";
      meta.textContent =
        "Templates are defaults (not separate products). You can change module choices next.";

      btn.appendChild(title);
      btn.appendChild(meta);

      btn.addEventListener("click", () => {
        state.blueprint.template_key = key;
        markDirty();
        renderTemplates();
        renderReview();
        scheduleAutosave();
      });

      templateListEl.appendChild(btn);
    });
  }

  function renderDataSources() {
    if (!dataSourceSelectEl) return;
    const sources = Array.isArray(state.contract?.options?.data_sources)
      ? state.contract.options.data_sources
      : ["shopify", "csv", "manual", "connector"];

    dataSourceSelectEl.innerHTML = "";
    sources.forEach((source) => {
      const key = normalizeString(source);
      if (!key) return;
      const opt = document.createElement("option");
      opt.value = key;
      opt.textContent = key;
      dataSourceSelectEl.appendChild(opt);
    });

    if (state.blueprint.data_source) {
      dataSourceSelectEl.value = state.blueprint.data_source;
    }
  }

  function renderMobileOptions() {
    if (!mobileRolesEl || !mobileJobsEl) return;
    const roles = Array.isArray(state.contract?.options?.mobile_roles)
      ? state.contract.options.mobile_roles
      : [];
    const jobs = Array.isArray(state.contract?.options?.mobile_jobs)
      ? state.contract.options.mobile_jobs
      : [];

    mobileRolesEl.innerHTML = "";
    roles.forEach((role) => {
      const key = normalizeString(role);
      if (!key) return;
      const label = document.createElement("label");
      label.className = "flex items-center gap-2 text-sm text-zinc-800";
      const input = document.createElement("input");
      input.type = "checkbox";
      input.className = "size-4 rounded border-zinc-300";
      input.value = key;
      input.checked = state.blueprint.mobile_intent.mobile_roles_needed.includes(key);
      input.addEventListener("change", () => {
        if (input.checked) {
          state.blueprint.mobile_intent.mobile_roles_needed = uniqStrings([
            ...state.blueprint.mobile_intent.mobile_roles_needed,
            key,
          ]);
        } else {
          state.blueprint.mobile_intent.mobile_roles_needed =
            state.blueprint.mobile_intent.mobile_roles_needed.filter((v) => v !== key);
        }
        markDirty();
        renderReview();
        scheduleAutosave();
      });
      const text = document.createElement("span");
      text.textContent = key;
      label.appendChild(input);
      label.appendChild(text);
      mobileRolesEl.appendChild(label);
    });

    mobileJobsEl.innerHTML = "";
    jobs.forEach((job) => {
      const key = normalizeString(job);
      if (!key) return;
      const label = document.createElement("label");
      label.className = "flex items-center gap-2 text-sm text-zinc-800";
      const input = document.createElement("input");
      input.type = "checkbox";
      input.className = "size-4 rounded border-zinc-300";
      input.value = key;
      input.checked = state.blueprint.mobile_intent.mobile_jobs_requested.includes(key);
      input.addEventListener("change", () => {
        if (input.checked) {
          state.blueprint.mobile_intent.mobile_jobs_requested = uniqStrings([
            ...state.blueprint.mobile_intent.mobile_jobs_requested,
            key,
          ]);
        } else {
          state.blueprint.mobile_intent.mobile_jobs_requested =
            state.blueprint.mobile_intent.mobile_jobs_requested.filter((v) => v !== key);
        }
        markDirty();
        renderReview();
        scheduleAutosave();
      });
      const text = document.createElement("span");
      text.textContent = key;
      label.appendChild(input);
      label.appendChild(text);
      mobileJobsEl.appendChild(label);
    });
  }

  function updateModuleCheckboxInterlocks() {
    const selected = new Set(state.blueprint.selected_modules);
    moduleCards.forEach((card) => {
      const key = card.dataset.moduleKey;
      const locked = card.dataset.moduleLocked === "1";
      const isSelected = selected.has(key);
      const checkbox = card.querySelector("[data-module-checkbox]");
      if (checkbox) {
        checkbox.checked = isSelected;
        checkbox.disabled = locked && !isSelected;
      }

      card.classList.toggle("is-selected", isSelected);
    });
  }

  function renderReview() {
    if (reviewTemplateEl) reviewTemplateEl.textContent = state.blueprint.template_key || "—";
    if (reviewOutcomeEl) reviewOutcomeEl.textContent = state.blueprint.desired_outcome_first || "—";
    if (reviewDataSourceEl) reviewDataSourceEl.textContent = state.blueprint.data_source || "—";

    const selected = state.blueprint.selected_modules || [];
    if (reviewModulesEl) {
      reviewModulesEl.textContent = selected.length ? selected.join(", ") : "—";
    }

    if (reviewMobileEl) {
      reviewMobileEl.textContent = state.blueprint.mobile_intent.needs_mobile_access ? "Yes" : "No";
    }

    if (nbaActiveEl) nbaActiveEl.textContent = JSON.stringify(state.contract?.next_best_actions?.available_now || [], null, 2);
    if (nbaSetupEl) nbaSetupEl.textContent = JSON.stringify(state.contract?.next_best_actions?.setup_next || [], null, 2);
    if (nbaUnlockEl) nbaUnlockEl.textContent = JSON.stringify(state.contract?.next_best_actions?.unlock_next || [], null, 2);
  }

  function recommendedModuleKeys() {
    const list = state.contract?.recommendations?.recommended_modules;
    return Array.isArray(list) ? uniqStrings(list) : [];
  }

  function renderRecommendedModules() {
    const recommended = new Set(recommendedModuleKeys());
    moduleCards.forEach((card) => {
      const key = card.dataset.moduleKey;
      const pill = card.querySelector("[data-module-recommended-pill]");
      const isRecommended = recommended.has(key);
      card.classList.toggle("is-recommended", isRecommended);
      if (pill) {
        pill.classList.toggle("hidden", !isRecommended);
      }
    });
  }

  function markDirty() {
    state.dirty = true;
    if (!state.saving) setAutosaveStatus("Unsaved changes…");
  }

  function scheduleAutosave() {
    if (root.dataset.autosaveEnabled !== "1") return;
    if (state.autosaveTimer) window.clearTimeout(state.autosaveTimer);
    const wait = Math.max(200, parseInt(root.dataset.autosaveDebounceMs || "900", 10) || 900);
    state.autosaveTimer = window.setTimeout(() => {
      autosaveNow().catch(() => {});
    }, wait);
  }

  function buildAutosavePayload() {
    return {
      rail: normalizeString(state.blueprint.rail) || normalizeString(state.contract?.context?.rail) || "direct",
      template_key: normalizeString(state.blueprint.template_key),
      desired_outcome_first: normalizeString(state.blueprint.desired_outcome_first),
      selected_modules: Array.isArray(state.blueprint.selected_modules) ? state.blueprint.selected_modules : [],
      data_source: normalizeString(state.blueprint.data_source),
      setup_preferences: state.blueprint.setup_preferences && typeof state.blueprint.setup_preferences === "object"
        ? state.blueprint.setup_preferences
        : {},
      mobile_intent: {
        needs_mobile_access: !!state.blueprint.mobile_intent.needs_mobile_access,
        mobile_roles_needed: Array.isArray(state.blueprint.mobile_intent.mobile_roles_needed)
          ? state.blueprint.mobile_intent.mobile_roles_needed
          : [],
        mobile_jobs_requested: Array.isArray(state.blueprint.mobile_intent.mobile_jobs_requested)
          ? state.blueprint.mobile_intent.mobile_jobs_requested
          : [],
        mobile_priority: normalizeString(state.blueprint.mobile_intent.mobile_priority),
      },
    };
  }

  async function autosaveNow() {
    clearErrors();
    if (!autosaveUrl) return;
    state.saving = true;
    setAutosaveStatus("Saving…");

    try {
      const payload = buildAutosavePayload();
      const response = await window.axios.post(autosaveUrl, payload);
      const data = response?.data || {};
      state.draft = data.draft || null;
      state.dirty = false;
      const savedAt = normalizeString(state.draft?.saved_at);
      setAutosaveStatus(savedAt ? `Saved ${savedAt}` : "Saved");
    } catch (error) {
      const payload = error?.response?.data || null;
      showErrors(payload || "Autosave failed.");
      setAutosaveStatus("Autosave failed");
      throw error;
    } finally {
      state.saving = false;
      buildStepper();
    }
  }

  async function maybeAutosave() {
    if (!state.dirty) return;
    await autosaveNow();
  }

  async function finalizeBlueprint() {
    clearErrors();
    if (!finalizeUrl) return;
    if (finalizeBtn) finalizeBtn.disabled = true;
    if (finalizeStatusEl) finalizeStatusEl.textContent = "Finalizing…";

    try {
      await autosaveNow();
      const response = await window.axios.post(finalizeUrl, {});
      const data = response?.data || {};
      state.final = data.final || null;
      if (!state.final?.id) {
        throw new Error("Finalize returned no final id.");
      }
      if (finalizeStatusEl) {
        finalizeStatusEl.textContent = `Finalized blueprint #${state.final.id} (blueprint-only).`;
      }
      await loadSummary();
      window.dispatchEvent(new CustomEvent("toast", { detail: { message: "Blueprint finalized.", style: "success" } }));
    } catch (error) {
      const payload = error?.response?.data || null;
      showErrors(payload || "Finalize failed.");
      if (finalizeStatusEl) finalizeStatusEl.textContent = "Finalize failed.";
      throw error;
    } finally {
      if (finalizeBtn) finalizeBtn.disabled = false;
    }
  }

  async function loadSummary() {
    if (!summaryUrl) return;
    if (!state.final?.id) return;

    try {
      const response = await window.axios.get(summaryUrl, {
        params: {
          final_blueprint_id: state.final.id,
        },
      });
      state.summary = response?.data || null;
      renderSummary();
    } catch (error) {
      const status = error?.response?.status;
      if (status === 403 || status === 404) {
        state.summary = {
          status: "unavailable",
          meta: { read_only: true },
          policy: {},
          summary: { ready_for_open: false, recommended_first_screen: null, payload_anchor: "merchant_journey" },
          provisioned_tenant_id: null,
        };
        renderSummary();
        return;
      }

      const payload = error?.response?.data || null;
      showErrors(payload || "Failed to load post-provisioning summary.");
      throw error;
    }
  }

  async function provisionProductionTenant() {
    if (!provisionUrl || !canProvision) return;
    if (!state.final?.id) return;
    clearErrors();

    try {
      await window.axios.post(provisionUrl, { final_blueprint_id: state.final.id });
      await loadSummary();
    } catch (error) {
      const payload = error?.response?.data || null;
      showErrors(payload || "Provisioning failed.");
      throw error;
    }
  }

  function renderSummary() {
    const summary = state.summary;
    if (!summary) return;

    if (summaryStatusEl) summaryStatusEl.textContent = summary.status || "—";
    if (summaryTenantEl) {
      const id = summary.provisioned_tenant_id;
      summaryTenantEl.textContent = id ? `${id}` : "—";
    }
    if (summaryReadyEl) summaryReadyEl.textContent = summary?.summary?.ready_for_open ? "Yes" : "No";

    const routeName = summary?.summary?.recommended_first_screen?.route_name || null;
    const path = summary?.summary?.recommended_first_screen?.path || null;
    const reason = summary?.summary?.recommended_first_screen?.reason || "handoff";
    const anchor = summary?.summary?.payload_anchor || "merchant_journey";

    if (summaryFirstScreenEl) {
      summaryFirstScreenEl.textContent = path
        ? `${path} (${routeName || "route"}) · anchor=${anchor} · reason=${reason}`
        : "—";
    }

    if (summaryNotesEl) {
      summaryNotesEl.textContent = summary.status === "provisioned"
        ? "Provisioned tenant is fresh; source/demo tenant is not converted."
        : (summary.status === "unavailable"
          ? "Post-provisioning summary is gated (admin + feature flag). You can still finalize a blueprint and use next-best-actions."
          : "Not provisioned yet. This summary is read-only and deterministic.");
    }

    if (summaryOpenLinkEl) {
      if (path) {
        summaryOpenLinkEl.classList.remove("hidden");
        summaryOpenLinkEl.setAttribute("href", path);
      } else {
        summaryOpenLinkEl.classList.add("hidden");
        summaryOpenLinkEl.removeAttribute("href");
      }
    }

    if (summaryRawEl) {
      summaryRawEl.textContent = JSON.stringify(summary, null, 2);
    }
  }

  function hydrateInputsFromState() {
    // Simple inputs (data-input bindings)
    Array.from(root.querySelectorAll("[data-input]")).forEach((el) => {
      const path = el.getAttribute("data-input");
      if (!path) return;
      const value = getByPath(state.blueprint, path);
      if (el.type === "checkbox") {
        el.checked = !!value;
        return;
      }
      if (path === "setup_preferences_json") {
        el.value = state.blueprint.setup_preferences && Object.keys(state.blueprint.setup_preferences).length
          ? JSON.stringify(state.blueprint.setup_preferences, null, 2)
          : "";
        return;
      }
      el.value = value ?? "";
    });

    // Modules
    updateModuleCheckboxInterlocks();

    // Data sources
    renderDataSources();

    // Templates
    renderTemplates();

    // Mobile options
    renderMobileOptions();

    // Mobile details visibility
    if (mobileDetailsEl) {
      mobileDetailsEl.classList.toggle("hidden", !state.blueprint.mobile_intent.needs_mobile_access);
    }

    renderReview();
  }

  function bindInputEvents() {
    // Generic inputs
    Array.from(root.querySelectorAll("[data-input]")).forEach((el) => {
      const path = el.getAttribute("data-input");
      if (!path) return;

      const handler = () => {
        if (path === "setup_preferences_json") {
          const raw = `${el.value || ""}`.trim();
          if (raw === "") {
            state.blueprint.setup_preferences = {};
          } else {
            const parsed = safeJsonParse(raw, null);
            if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
              state.blueprint.setup_preferences = parsed;
              clearErrors();
            } else {
              showErrors("setup_preferences must be a JSON object.");
            }
          }
          markDirty();
          scheduleAutosave();
          return;
        }

        if (el.type === "checkbox") {
          const parts = path.split(".");
          if (parts.length === 1) {
            state.blueprint[parts[0]] = !!el.checked;
          } else {
            let cursor = state.blueprint;
            for (let i = 0; i < parts.length - 1; i++) {
              if (!cursor[parts[i]] || typeof cursor[parts[i]] !== "object") cursor[parts[i]] = {};
              cursor = cursor[parts[i]];
            }
            cursor[parts[parts.length - 1]] = !!el.checked;
          }

          if (path === "mobile_intent.needs_mobile_access") {
            if (mobileDetailsEl) {
              mobileDetailsEl.classList.toggle("hidden", !state.blueprint.mobile_intent.needs_mobile_access);
            }
            if (!state.blueprint.mobile_intent.needs_mobile_access) {
              state.blueprint.mobile_intent.mobile_roles_needed = [];
              state.blueprint.mobile_intent.mobile_jobs_requested = [];
            }
            renderMobileOptions();
          }

          markDirty();
          renderReview();
          scheduleAutosave();
          return;
        }

        // text/select
        const val = normalizeString(el.value);
        if (path.includes(".")) {
          const parts = path.split(".");
          let cursor = state.blueprint;
          for (let i = 0; i < parts.length - 1; i++) {
            if (!cursor[parts[i]] || typeof cursor[parts[i]] !== "object") cursor[parts[i]] = {};
            cursor = cursor[parts[i]];
          }
          cursor[parts[parts.length - 1]] = val;
        } else {
          state.blueprint[path] = val;
        }

        markDirty();
        renderReview();
        scheduleAutosave();
      };

      el.addEventListener("change", handler);
      el.addEventListener("input", handler);
    });

    // Data source select is already in data-input binding, but ensure value is synced after contract loads
    if (dataSourceSelectEl) {
      dataSourceSelectEl.addEventListener("change", () => {
        state.blueprint.data_source = normalizeString(dataSourceSelectEl.value);
        markDirty();
        renderReview();
        scheduleAutosave();
      });
    }

    // Module checkboxes
    moduleCheckboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", () => {
        const key = normalizeString(checkbox.value);
        if (!key) return;

        const selected = new Set(state.blueprint.selected_modules || []);
        if (checkbox.checked) selected.add(key);
        else selected.delete(key);

        state.blueprint.selected_modules = Array.from(selected);
        markDirty();
        updateModuleCheckboxInterlocks();
        renderReview();
        scheduleAutosave();
      });
    });

    // Save / navigation
    if (saveBtn) {
      saveBtn.addEventListener("click", () => autosaveNow().catch(() => {}));
    }

    const applyRecommendedBtn = root.querySelector("[data-action-apply-recommended]");
    if (applyRecommendedBtn) {
      applyRecommendedBtn.addEventListener("click", () => {
        const recommended = recommendedModuleKeys();
        if (recommended.length === 0) {
          window.dispatchEvent(new CustomEvent("toast", { detail: { message: "No recommendations available yet.", style: "warning" } }));
          return;
        }

        const selected = new Set(state.blueprint.selected_modules || []);
        recommended.forEach((key) => {
          const card = moduleCards.find((node) => node.dataset.moduleKey === key);
          const locked = card?.dataset?.moduleLocked === "1";
          if (!locked) {
            selected.add(key);
          }
        });

        state.blueprint.selected_modules = Array.from(selected);
        markDirty();
        updateModuleCheckboxInterlocks();
        renderReview();
        scheduleAutosave();
        window.dispatchEvent(new CustomEvent("toast", { detail: { message: "Applied recommended modules.", style: "success" } }));
      });
    }

    if (backBtn) {
      backBtn.addEventListener("click", async () => {
        await maybeAutosave();
        state.currentStepIndex = Math.max(0, state.currentStepIndex - 1);
        render();
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener("click", async () => {
        const step = currentStep();
        if (!step) return;
        clearErrors();

        if (!stepComplete(step)) {
          showErrors("This step is incomplete. Fill the required fields before continuing.");
          return;
        }

        await maybeAutosave();
        state.currentStepIndex = Math.min(
          (state.contract?.steps || []).length - 1,
          state.currentStepIndex + 1,
        );
        render();
      });
    }

    if (finalizeBtn) {
      finalizeBtn.addEventListener("click", () => finalizeBlueprint().catch(() => {}));
    }

    if (refreshSummaryBtn) {
      refreshSummaryBtn.addEventListener("click", () => loadSummary().catch(() => {}));
    }

    if (provisionBtn && canProvision) {
      provisionBtn.addEventListener("click", () => provisionProductionTenant().catch(() => {}));
    }
  }

  function render() {
    const step = currentStep();
    if (!step) return;

    if (stepTitleEl) stepTitleEl.textContent = step.title || step.step_key || "—";
    if (stepDescriptionEl) stepDescriptionEl.textContent = step.description || "—";

    showPanel(step.step_key);
    buildStepper();

    // Disable nav buttons at edges
    if (backBtn) backBtn.disabled = state.currentStepIndex === 0;
    if (nextBtn) nextBtn.disabled = state.currentStepIndex >= (state.contract?.steps || []).length - 1;

    hydrateInputsFromState();
  }

  async function loadContract() {
    clearErrors();
    setStatus("Loading wizard contract…");

    try {
      const response = await window.axios.get(contractUrl);
      const data = response?.data || {};
      state.contract = data.contract || null;
      state.draft = data.draft || null;

      const context = state.contract?.context || {};
      if (ctxRailEl) ctxRailEl.textContent = context.rail || "—";
      if (ctxAccountModeEl) ctxAccountModeEl.textContent = context.account_mode || "—";

      const defaults = state.contract?.defaults && typeof state.contract.defaults === "object"
        ? state.contract.defaults
        : {};
      const draftPayload = state.draft?.payload && typeof state.draft.payload === "object"
        ? state.draft.payload
        : {};

      state.blueprint = {
        ...state.blueprint,
        ...defaults,
        ...draftPayload,
        mobile_intent: {
          needs_mobile_access: !!getByPath({ mobile_intent: draftPayload.mobile_intent || defaults.mobile_intent || {} }, "mobile_intent.needs_mobile_access"),
          mobile_roles_needed: uniqStrings(getByPath({ mobile_intent: draftPayload.mobile_intent || defaults.mobile_intent || {} }, "mobile_intent.mobile_roles_needed") || []),
          mobile_jobs_requested: uniqStrings(getByPath({ mobile_intent: draftPayload.mobile_intent || defaults.mobile_intent || {} }, "mobile_intent.mobile_jobs_requested") || []),
          mobile_priority: normalizeString(getByPath({ mobile_intent: draftPayload.mobile_intent || defaults.mobile_intent || {} }, "mobile_intent.mobile_priority")),
        },
      };

      state.blueprint.rail = context.rail || state.blueprint.rail || "direct";

      // Data source defaults are derived server-side; ensure we reflect them.
      state.blueprint.data_source = normalizeString(state.blueprint.data_source) || null;

      // Normalize selected modules
      state.blueprint.selected_modules = uniqStrings(state.blueprint.selected_modules || []);

      // Initial step: first incomplete, else first step
      const steps = state.contract?.steps || [];
      const firstIncomplete = steps.findIndex((step) => !stepComplete(step));
      state.currentStepIndex = firstIncomplete >= 0 ? firstIncomplete : 0;

      bindInputEvents();
      renderTemplates();
      renderDataSources();
      renderMobileOptions();
      renderRecommendedModules();
      updateModuleCheckboxInterlocks();
      renderReview();

      render();
      setStatus("Ready.");
      setAutosaveStatus(state.draft?.saved_at ? `Saved ${state.draft.saved_at}` : "Not saved yet");
    } catch (error) {
      const payload = error?.response?.data || null;
      showErrors(payload || "Failed to load contract.");
      setStatus("Failed to load contract.");
      throw error;
    }
  }

  // Boot
  if (!window.axios) {
    // bootstrap.js sets axios; if unavailable, fail loud.
    setStatus("Missing axios bootstrap.");
    return;
  }

  loadContract().catch(() => {});
}
