/* Explicit sample presentation only. Never reads a transport, storage, account or provider. */
(() => {
    "use strict";
    const sections = new Set([
        "home",
        "projects",
        "requests",
        "roll_call",
        "people",
        "finance",
        "buildings",
        "calendar",
        "assets",
        "contractors",
        "workflows",
        "requester",
    ]);
    const text = (value, limit = 600) =>
        typeof value === "string" ? value.slice(0, limit) : "";
    const target = (value) => {
        if (!value || !sections.has(value.section)) return null;
        const result = { section: value.section };
        for (const name of ["area", "selected"]) {
            if (value[name] !== undefined) {
                if (
                    typeof value[name] !== "string" ||
                    !/^[A-Za-z0-9_-]{1,200}$/.test(value[name])
                )
                    return null;
                result[name] = value[name];
            }
        }
        return result;
    };
    function sourceUrl(value, origins) {
        try {
            const url = new URL(value);
            return url.protocol === "https:" &&
                !url.username &&
                !url.password &&
                origins.includes(url.origin)
                ? url.href
                : null;
        } catch {
            return null;
        }
    }
    function model(input = {}) {
        if (input.runtime !== "sample" || input.persona !== "executive")
            return { supported: false };
        const origins = (
            Array.isArray(input.allowedSourceOrigins)
                ? input.allowedSourceOrigins
                : []
        ).filter((value) => {
            try {
                const url = new URL(value);
                return url.protocol === "https:" && url.origin === value;
            } catch {
                return false;
            }
        });
        const row = (value) => ({
            id: text(value.id, 100),
            label: text(value.label, 160),
            detail: text(value.detail || value.summary),
            available: value.available === true,
            target: target(value.target),
        });
        return {
            supported: true,
            demoManager: input.demoManager === true,
            asOf:
                text(input.asOf, 100) || "Not supplied — freshness unverified",
            scope:
                text(input.scope, 250) ||
                "Configured demonstration workspaces only",
            network: (Array.isArray(input.network) ? input.network : [])
                .slice(0, 40)
                .filter((value) => value && value.visible !== false)
                .map((value) => ({
                    ...row(value),
                    lifecycle: ["Live", "Onboarding", "Planned"].includes(
                        value.lifecycle,
                    )
                        ? value.lifecycle
                        : "Unconfirmed",
                })),
            metrics: (Array.isArray(input.metrics) ? input.metrics : [])
                .slice(0, 12)
                .filter((value) => value && value.visible !== false)
                .map((value) => ({
                    ...row(value),
                    value:
                        typeof value.value === "number" &&
                        Number.isFinite(value.value)
                            ? String(value.value)
                            : typeof value.value === "string" &&
                                value.value.trim()
                              ? text(value.value, 100)
                              : null,
                })),
            sources: (Array.isArray(input.sources) ? input.sources : [])
                .slice(0, 12)
                .filter((value) => value && value.label)
                .map((value) => ({
                    label: text(value.label, 160),
                    detail: text(value.detail, 350),
                    url: sourceUrl(value.url, origins),
                })),
            currency:
                typeof input.proposal?.currency === "string" &&
                /^[A-Z]{3}$/.test(input.proposal.currency)
                    ? input.proposal.currency
                    : null,
            plans: (Array.isArray(input.proposal?.stages)
                ? input.proposal.stages
                : []
            )
                .slice(0, 8)
                .filter(
                    (value) =>
                        value &&
                        Number.isSafeInteger(value.amount) &&
                        value.amount >= 0,
                )
                .map((value, index) => ({
                    label:
                        text(value.label, 100) ||
                        "Proposed stage " + (index + 1),
                    amount: value.amount,
                    detail: text(value.detail),
                })),
        };
    }
    function mount(root, initial = {}) {
        if (!root) return null;
        let generation = 0,
            config = initial;
        const document = root.ownerDocument || globalThis.document;
        const node = (tag, className, content) => {
            const element = document.createElement(tag);
            if (className) element.className = className;
            if (content !== undefined) element.textContent = content;
            return element;
        };
        const icon = (kind = "grid") => {
            const svg = document.createElementNS(
                "http://www.w3.org/2000/svg",
                "svg",
            );
            svg.setAttribute("viewBox", "0 0 24 24");
            svg.setAttribute("aria-hidden", "true");
            const path = document.createElementNS(
                "http://www.w3.org/2000/svg",
                "path",
            );
            path.setAttribute(
                "d",
                kind === "arrow"
                    ? "M5 12h14m-5-5 5 5-5 5"
                    : kind === "building"
                      ? "M4 21V7l8-4 8 4v14M8 8h1m6 0h1M8 12h1m6 0h1M8 16h1m6 0h1M10 21v-3h4v3M2 21h20"
                      : "M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z",
            );
            svg.append(path);
            return svg;
        };
        function paint() {
            const data = model(config),
                token = ++generation;
            root.replaceChildren();
            root.hidden = !data.supported;
            if (!data.supported) return;
            root.className = "eed";
            const status = node("p", "eed-status");
            status.setAttribute("role", "status");
            status.setAttribute("aria-live", "polite");
            const navigate = async (item) => {
                if (
                    !item.available ||
                    !item.target ||
                    typeof config.onNavigate !== "function"
                )
                    return;
                const callback = config.onNavigate;
                try {
                    const result = await callback(
                        { ...item.target },
                        { id: item.id, label: item.label },
                    );
                    if (token !== generation) return;
                    status.textContent =
                        result?.available === false
                            ? text(result.message) ||
                              "This record is unavailable in the current perspective."
                            : "Opened the selected sample. Destination permissions and sample boundaries still apply.";
                } catch {
                    if (token === generation)
                        status.textContent =
                            "This record could not be opened. Your current work remains available.";
                }
            };
            const actionable = (item, label) => {
                const button = node("button", "eed-card eed-card-button");
                button.type = "button";
                button.disabled =
                    !item.available ||
                    !item.target ||
                    typeof config.onNavigate !== "function";
                button.setAttribute("aria-label", label);
                button.addEventListener("click", () => navigate(item));
                return button;
            };
            const hero = node("header", "eed-hero"),
                intro = node("div"),
                identity = node("aside", "eed-meta");
            intro.append(node("strong", "", "Campus overview"));
            identity.append(node("span", "eed-tag", "Demo"),node("strong", "", data.demoManager ? "Blaine · demo manager" : "Executive · read-only"),node("small", "", "Demo role · no real account or grants"));
            hero.append(intro, identity);
            root.append(hero);
            root.append(
                node(
                    "div",
                    "eed-note",
                    "Simulation status: this overview uses fictional browser records. “Live”, “Onboarding” and “Planned” describe configured demo stages, not hosted customer deployments, connected providers or approved institutional adoption.",
                ),
            );
            root.append(
                node("p", "", `As of ${data.asOf}. Scope: ${data.scope}.`),
            );
            const metricSection = node("section"),
                metricHeading = node("h3", "", "Current work, with its source"),
                metrics = node("div", "eed-metrics");
            metricSection.setAttribute(
                "aria-label",
                "Executive sample metrics",
            );
            metricSection.id = "executive-metrics";
            metricSection.append(
                metricHeading,
                node(
                    "p",
                    "",
                    "A missing measure stays unavailable. Counts cover only the configured visible sample records.",
                ),
            );
            for (const item of data.metrics) {
                const card = actionable(
                    item,
                    "Open supporting records for " + item.label,
                );
                card.append(
                    node("span", "eed-metric-label", item.label),
                    node(
                        "strong",
                        "eed-value" +
                            (item.value === null ? " eed-empty-value" : ""),
                        item.value ?? "Unavailable",
                    ),
                    node(
                        "p",
                        "",
                        item.detail || "No supporting detail supplied.",
                    ),
                );
                const action = node(
                    "span",
                    "eed-action",
                    card.disabled
                        ? "Supporting records unavailable"
                        : "Open supporting records",
                );
                if (!card.disabled) action.append(icon("arrow"));
                card.append(action);
                metrics.append(card);
            }
            if (!data.metrics.length)
                metrics.append(
                    node(
                        "p",
                        "eed-unavailable",
                        "No metrics were supplied for this perspective. Nothing is estimated.",
                    ),
                );
            metricSection.append(metrics);
            root.append(metricSection);
            const networkSection = node("section"),
                network = node("div", "eed-network");
            networkSection.setAttribute(
                "aria-label",
                "Configured workspace network",
            );
            networkSection.id = "executive-network";
            networkSection.append(
                node("h3", "", "Your workspace network"),
                node(
                    "p",
                    "",
                    "Configured workspaces within this demonstration. Department-only work, private records and financial information keep their own access boundaries.",
                ),
            );
            for (const item of data.network) {
                const card = actionable(item, "Open " + item.label),
                    head = node("div", "eed-workspace-head"),
                    mark = node("span", "eed-icon"),
                    title = node("div"),
                    state = node("span", "eed-state");
                mark.append(icon("building"));
                state.dataset.state = item.lifecycle;
                state.append(
                    node("span", "eed-dot"),
                    node("span", "", item.lifecycle + " · simulated"),
                );
                title.append(node("h4", "", item.label), state);
                head.append(mark, title);
                card.append(
                    head,
                    node(
                        "p",
                        "",
                        item.detail || "No readiness detail supplied.",
                    ),
                );
                const action = node(
                    "span",
                    "eed-action",
                    card.disabled
                        ? "Record drilldown unavailable"
                        : "Open workspace records",
                );
                if (!card.disabled) action.append(icon("arrow"));
                card.append(action);
                network.append(card);
            }
            if (!data.network.length)
                network.append(
                    node(
                        "p",
                        "eed-unavailable",
                        "No configured workspaces were supplied. No additional campuses or departments are inferred.",
                    ),
                );
            networkSection.append(network);
            root.append(networkSection);
            const pricing = node("section"),
                plans = node("div", "eed-plans");
            pricing.setAttribute("aria-label", "Uncommitted software proposal");
            pricing.id = "executive-software";
            pricing.append(
                node("h3", "", "Proposed software rollout"),
                node(
                    "p",
                    "",
                    "Planning figures only. Terms, cadence and timing remain uncommitted. These are software proposal amounts, separate from project costs, invoice packets and work budgets. Billing is inactive.",
                ),
            );
            for (const plan of data.plans) {
                const card = node("article", "eed-card eed-plan");
                let amount = plan.amount.toLocaleString("en-US");
                if (data.currency) {
                    try {
                        amount = new Intl.NumberFormat("en-US", {
                            style: "currency",
                            currency: data.currency,
                            maximumFractionDigits: 0,
                        }).format(plan.amount);
                    } catch {
                        amount = data.currency + " " + amount;
                    }
                } else amount += " · currency unconfirmed";
                card.append(
                    node("h4", "", plan.label),
                    node("span", "eed-plan-amount", amount),
                    node("small", "", "Proposed · not billed"),
                );
                if (plan.detail) card.append(node("p", "", plan.detail));
                plans.append(card);
            }
            if (!data.plans.length)
                plans.append(
                    node(
                        "p",
                        "eed-unavailable",
                        "No proposed software amounts supplied.",
                    ),
                );
            pricing.append(plans);
            root.append(pricing);
            const provenance = node("footer"),
                sources = node("div", "eed-sources");
            provenance.id = "executive-sources";
            provenance.append(
                node("h3", "", "Sources and limits"),
                node(
                    "p",
                    "",
                    "Source labels describe supplied evidence. They do not verify a live connection, approved price or deployment.",
                ),
            );
            for (const source of data.sources) {
                const badge = node(
                    source.url ? "a" : "span",
                    "eed-source",
                    source.label,
                );
                if (source.url) {
                    badge.href = source.url;
                    badge.target = "_blank";
                    badge.rel = "noopener noreferrer";
                }
                if (source.detail) badge.title = source.detail;
                sources.append(badge);
            }
            if (!data.sources.length)
                sources.append(
                    node("span", "eed-source", "No source references supplied"),
                );
            provenance.append(sources);
            root.append(provenance, status);
        }
        paint();
        return Object.freeze({
            update(next) {
                config = next || {};
                paint();
            },
            destroy() {
                generation++;
                root.replaceChildren();
                root.hidden = true;
            },
        });
    }
    globalThis.EverbranchExecutiveDemo = Object.freeze({ model, mount });
})();
