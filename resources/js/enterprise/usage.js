/* Read-only presentation of a supplied, permission-filtered usage DTO. No IO or authority. */
(() => {
    "use strict";
    const countKeys = [
        "requests",
        "completed",
        "reserved",
        "uncertain",
        "cancelled",
        "unreconciled",
        "input_tokens",
        "output_tokens",
        "reported_token_records",
        "token_unknown_records",
        "exposure_unknown_records",
    ];
    const moneyKeys = [
        "original_reserved_microusd",
        "settled_usage_derived_microusd",
        "reserved_exposure_microusd",
        "uncertain_reserved_microusd",
        "estimated_total_exposure_microusd",
    ];
    const basisLabels = Object.freeze({
        source: "Ledger source",
        settled_cost: "Usage-derived estimate",
        reserved_exposure: "Reservation and uncertainty",
        tokens: "Token coverage",
        zero_records: "Empty ledger scope",
        commercial: "Budget and commercial boundary",
        snapshot: "Snapshot coverage",
    });
    const words = (value, limit = 600) =>
        typeof value === "string" ? value.slice(0, limit) : "";
    const integer = (value) =>
        Number.isSafeInteger(value) && value >= 0 ? value : null;
    const count = (value) =>
        value === null ? "Unavailable" : value.toLocaleString("en-US");
    const formatMicroUsd = (value) => {
        const safe = integer(value);
        if (safe === null) return "Unavailable";
        return (
            "$" +
            Math.floor(safe / 1000000).toLocaleString("en-US") +
            "." +
            String(safe % 1000000).padStart(6, "0")
        );
    };
    const totals = (input, moneySupported) =>
        Object.fromEntries(
            [...countKeys, ...moneyKeys].map((key) => [
                key,
                moneyKeys.includes(key) && !moneySupported
                    ? null
                    : integer(input?.[key]),
            ]),
        );
    function explanations(input) {
        if (typeof input === "string") return [words(input, 1200)];
        if (Array.isArray(input))
            return input
                .filter((value) => typeof value === "string")
                .slice(0, 8)
                .map((value) => words(value, 600));
        if (!input || typeof input !== "object") return [];
        // Only the aggregate DTO's documented own explanation fields belong here.
        // Do not serialize provider payloads, identities, inherited keys or nested objects.
        return Object.keys(basisLabels)
            .filter(
                (key) =>
                    Object.prototype.hasOwnProperty.call(input, key) &&
                    typeof input[key] === "string" &&
                    input[key].trim() !== "",
            )
            .map((key) => basisLabels[key] + ": " + words(input[key], 1200));
    }
    function model(usage) {
        if (!usage || typeof usage !== "object" || Array.isArray(usage))
            return null;
        const moneySupported =
            usage.currency === "USD" && usage.money_unit === "microusd";
        const period = usage.coverage?.period;
        let periodLabel = words(period, 250);
        if (!periodLabel && period && typeof period === "object") {
            periodLabel = words(period.label, 250);
            const from = words(
                    period.start || period.starts_at || period.from,
                    100,
                ),
                to = words(period.end || period.ends_at || period.to, 100);
            if (!periodLabel && (from || to))
                periodLabel =
                    (from || "Start not supplied") +
                    " through " +
                    (to || "End not supplied");
        }
        return {
            sample: usage.sample_data === true,
            asOf:
                words(usage.as_of, 100) ||
                "Not supplied — freshness unverified",
            scope: {
                type: words(usage.scope?.type, 60) || "Unspecified",
                area_id: words(usage.scope?.area_id, 200),
            },
            ledgerState: words(usage.ledger_state, 120) || "Not supplied",
            moneySupported,
            displayLimited:
                Array.isArray(usage.departments) &&
                (usage.departments.length > 100 ||
                    usage.departments.some(
                        (row) =>
                            Array.isArray(row?.purposes) &&
                            row.purposes.length > 30,
                    )),
            totals: totals(usage.totals, moneySupported),
            departments: (Array.isArray(usage.departments)
                ? usage.departments
                : []
            )
                .slice(0, 100)
                .filter((row) => row && typeof row === "object")
                .map((row) => ({
                    id: words(row.id, 200),
                    name: words(row.name, 180) || "Department name unavailable",
                    totals: totals(row.totals, moneySupported),
                    purposes: (Array.isArray(row.purposes) ? row.purposes : [])
                        .slice(0, 30)
                        .filter(
                            (purpose) => purpose && typeof purpose === "object",
                        )
                        .map((purpose) => ({
                            purpose: words(purpose.purpose, 100),
                            label:
                                words(purpose.label, 180) ||
                                "Feature label unavailable",
                            totals: totals(purpose.totals, moneySupported),
                        })),
                })),
            coverage: {
                partial: usage.coverage?.partial !== false,
                scanned: integer(usage.coverage?.scanned_records),
                limit: integer(usage.coverage?.record_scan_limit),
                period: periodLabel || "Period not supplied",
            },
            budget: {
                ready: usage.technical_budget?.ready === true,
                status:
                    words(usage.technical_budget?.status, 350) ||
                    "Technical budget readiness unavailable",
            },
            basis: explanations(usage.basis),
        };
    }
    function createUsageOverview(host, options = {}) {
        if (!host) return null;
        const document = host.ownerDocument || globalThis.document,
            data = model(options.usage);
        let active = true;
        const element = (tag, className, content) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (content !== undefined) node.textContent = content;
            return node;
        };
        host.replaceChildren();
        host.hidden = false;
        host.className = "eusage";
        if (!data) {
            const unavailable = element(
                "p",
                "eusage-notice",
                "Usage data is unavailable. No amount or activity is inferred.",
            );
            unavailable.setAttribute("role", "status");
            host.append(unavailable);
            return Object.freeze({
                destroy() {
                    active = false;
                    host.replaceChildren();
                    host.hidden = true;
                },
            });
        }
        const fact = (list, label, value) => {
            list.append(element("dt", "", label), element("dd", "", value));
        };
        const status = element("p", "eusage-status");
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        const header = element("header", "eusage-hero"),
            intro = element("div"),
            badge = element(
                "span",
                "eusage-badge",
                data.sample
                    ? "FICTIONAL SAMPLE USAGE"
                    : "CURRENT AUTHORIZED USAGE LEDGER",
            );
        intro.append(
            badge,
            element("h2", "", "AI usage, with its evidence."),
            element(
                "p",
                "",
                "See recorded requests, token reporting and estimated exposure by department and feature. Manual core workflows remain usable when AI is unavailable.",
            ),
        );
        const provenance = element("aside", "eusage-meta");
        provenance.append(
            element(
                "strong",
                "",
                data.scope.type === "tenant" ||
                    data.scope.type === "organization"
                    ? "Authorized organization overview"
                    : data.scope.type === "department"
                      ? "Authorized department scope"
                      : "Authorized usage scope",
            ),
            element("p", "", "As of " + data.asOf),
            element("p", "", "Ledger state: " + data.ledgerState),
        );
        header.append(intro, provenance);
        host.append(header);
        host.append(
            element(
                "p",
                "eusage-notice",
                data.sample
                    ? "Browser simulation with fictional departmental and feature usage. No real college usage, live provider call or provider invoice is verified here."
                    : "Read-only ledger estimates. Reservations are exposure, not charges. Settled usage-derived costs depend on recorded usage and rates; they are not verified provider invoices.",
            ),
        );
        if (!data.moneySupported)
            host.append(
                element(
                    "p",
                    "eusage-notice",
                    "The expected USD / microusd denomination was not supplied. Monetary figures remain unavailable rather than being converted or relabeled.",
                ),
            );
        const summary = element("section", "");
        summary.id = "enterprise-usage-summary";
        summary.setAttribute(
            "aria-labelledby",
            "enterprise-usage-summary-title",
        );
        const title = element("h3", "", "Recorded activity");
        title.id = "enterprise-usage-summary-title";
        summary.append(title);
        const counters = element("div", "eusage-metrics");
        for (const [label, key, description] of [
            ["Requests", "requests", "Recorded request count"],
            [
                "Completed",
                "completed",
                "Requests marked completed by the ledger",
            ],
            [
                "Uncertain",
                "uncertain",
                "Provider outcomes still needing reconciliation",
            ],
            ["Unreconciled", "unreconciled", "Records not yet reconciled"],
        ]) {
            const card = element("article", "eusage-card");
            card.append(
                element("h4", "", label),
                element("strong", "eusage-value", count(data.totals[key])),
                element("p", "", description),
            );
            counters.append(card);
        }
        summary.append(counters);
        const lifecycles = element("dl", "eusage-facts");
        fact(lifecycles, "Reserved requests", count(data.totals.reserved));
        fact(lifecycles, "Cancelled requests", count(data.totals.cancelled));
        summary.append(lifecycles);
        host.append(summary);
        const estimates = element("section", "");
        estimates.id = "enterprise-usage-estimates";
        estimates.setAttribute(
            "aria-labelledby",
            "enterprise-usage-estimates-title",
        );
        const estimatesTitle = element(
            "h3",
            "",
            "Estimated costs and exposure",
        );
        estimatesTitle.id = "enterprise-usage-estimates-title";
        estimates.append(
            estimatesTitle,
            element(
                "p",
                "",
                "These categories follow the supplied ledger and may overlap. This view does not add them together, verify payment or establish a commercial allowance.",
            ),
        );
        const amounts = element("div", "eusage-money-grid");
        for (const [label, key, description] of [
            [
                "Settled usage-derived estimate",
                "settled_usage_derived_microusd",
                "Derived from settled usage and recorded rates; not a provider invoice.",
            ],
            [
                "Reserved exposure",
                "reserved_exposure_microusd",
                "Outstanding exposure classified by the ledger; a reservation is not a charge.",
            ],
            [
                "Uncertain reserved exposure",
                "uncertain_reserved_microusd",
                "Unconfirmed outcomes still held for reconciliation; not proof of zero cost.",
            ],
            [
                "Estimated total exposure",
                "estimated_total_exposure_microusd",
                "Ledger estimate only; incomplete wherever exposure remains unknown.",
            ],
        ]) {
            const card = element("article", "eusage-card");
            card.append(
                element("h4", "", label),
                element(
                    "strong",
                    "eusage-money",
                    formatMicroUsd(data.totals[key]),
                ),
                element("p", "", description),
            );
            amounts.append(card);
        }
        estimates.append(amounts);
        const original = element("dl", "eusage-facts");
        fact(
            original,
            "Original reserved amount",
            formatMicroUsd(data.totals.original_reserved_microusd),
        );
        fact(
            original,
            "Records with unknown exposure",
            count(data.totals.exposure_unknown_records),
        );
        estimates.append(original);
        if (
            data.totals.exposure_unknown_records === null ||
            data.totals.exposure_unknown_records > 0
        )
            estimates.append(
                element(
                    "p",
                    "eusage-notice",
                    "Exposure is not fully known. Displayed amounts must not be treated as a complete spending total or remaining allowance.",
                ),
            );
        host.append(estimates);
        const tokens = element("section", "eusage-card");
        tokens.id = "enterprise-usage-tokens";
        tokens.append(
            element("h3", "", "Token reporting"),
            element(
                "p",
                "",
                "Only reported token values are included. Missing usage is unknown, not zero; this contains no questions, answers or user identities.",
            ),
        );
        const tokenFacts = element("dl", "eusage-facts");
        for (const [label, key] of [
            ["Reported input tokens", "input_tokens"],
            ["Reported output tokens", "output_tokens"],
            ["Records with reported tokens", "reported_token_records"],
            ["Records with unknown tokens", "token_unknown_records"],
        ])
            fact(tokenFacts, label, count(data.totals[key]));
        tokens.append(tokenFacts);
        host.append(tokens);
        const departments = element("section", "");
        departments.id = "enterprise-usage-departments";
        departments.setAttribute(
            "aria-labelledby",
            "enterprise-usage-departments-title",
        );
        const departmentTitle = element("h3", "", "Departments and features");
        departmentTitle.id = "enterprise-usage-departments-title";
        departments.append(
            departmentTitle,
            element(
                "p",
                "",
                "Only departments and features supplied in the currently authorized scope appear. These are ledger aggregates, not employee activity profiles.",
            ),
        );
        const departmentList = element("div", "eusage-departments");
        for (const department of data.departments) {
            const card = element("article", "eusage-card eusage-department");
            card.dataset.departmentId = department.id;
            const heading = element("div", "eusage-department-head");
            heading.append(element("h4", "", department.name));
            if (
                typeof options.onDepartment === "function" &&
                /^[A-Za-z0-9_-]{1,200}$/.test(department.id)
            ) {
                const button = element(
                    "button",
                    "eusage-button",
                    "View department usage",
                );
                button.type = "button";
                button.setAttribute(
                    "aria-label",
                    "View usage for " + department.name,
                );
                button.addEventListener("click", async () => {
                    if (!active || button.disabled) return;
                    button.disabled = true;
                    try {
                        const result = await options.onDepartment(
                            department.id,
                        );
                        if (active)
                            status.textContent =
                                result?.available === false
                                    ? "That department is unavailable in the current authorized scope."
                                    : "Department selection opened. The destination rechecks current access.";
                    } catch {
                        if (active)
                            status.textContent =
                                "The department could not be opened. The current usage view remains available.";
                    } finally {
                        if (active) button.disabled = false;
                    }
                });
                heading.append(button);
            }
            card.append(heading);
            const facts = element("dl", "eusage-facts");
            for (const [label, value] of [
                ["Requests", count(department.totals.requests)],
                [
                    "Settled usage-derived estimate",
                    formatMicroUsd(
                        department.totals.settled_usage_derived_microusd,
                    ),
                ],
                [
                    "Reserved exposure",
                    formatMicroUsd(
                        department.totals.reserved_exposure_microusd,
                    ),
                ],
                [
                    "Uncertain reserved exposure",
                    formatMicroUsd(
                        department.totals.uncertain_reserved_microusd,
                    ),
                ],
                [
                    "Unknown token records",
                    count(department.totals.token_unknown_records),
                ],
                [
                    "Unknown exposure records",
                    count(department.totals.exposure_unknown_records),
                ],
            ])
                fact(facts, label, value);
            card.append(facts);
            const purposes = element("div", "eusage-purpose-list");
            for (const purpose of department.purposes) {
                const item = element("article", "eusage-purpose");
                item.dataset.purpose = purpose.purpose;
                item.append(element("h5", "", purpose.label));
                const values = element("dl", "eusage-facts");
                for (const [label, value] of [
                    ["Requests", count(purpose.totals.requests)],
                    [
                        "Completed / uncertain",
                        count(purpose.totals.completed) +
                            " / " +
                            count(purpose.totals.uncertain),
                    ],
                    [
                        "Settled usage-derived estimate",
                        formatMicroUsd(
                            purpose.totals.settled_usage_derived_microusd,
                        ),
                    ],
                    [
                        "Reserved exposure",
                        formatMicroUsd(
                            purpose.totals.reserved_exposure_microusd,
                        ),
                    ],
                    [
                        "Reported input / output tokens",
                        count(purpose.totals.input_tokens) +
                            " / " +
                            count(purpose.totals.output_tokens),
                    ],
                    [
                        "Unknown token records",
                        count(purpose.totals.token_unknown_records),
                    ],
                ])
                    fact(values, label, value);
                item.append(values);
                purposes.append(item);
            }
            if (!department.purposes.length)
                purposes.append(
                    element(
                        "p",
                        "",
                        "No feature breakdown was supplied for this department.",
                    ),
                );
            card.append(purposes);
            departmentList.append(card);
        }
        if (!data.departments.length)
            departmentList.append(
                element(
                    "p",
                    "eusage-empty",
                    "No departments were returned for this scope. This is not proof of zero usage.",
                ),
            );
        departments.append(departmentList);
        host.append(departments);
        const coverage = element("section", "eusage-card");
        coverage.id = "enterprise-usage-coverage";
        coverage.append(
            element("h3", "", "Coverage and freshness"),
            element(
                "p",
                "",
                data.coverage.partial
                    ? "Partial coverage: totals describe the returned bounded ledger scan, not necessarily all usage."
                    : "Coverage is limited to the supplied ledger scope and period; it does not verify provider completeness.",
            ),
        );
        const coverageFacts = element("dl", "eusage-facts");
        if (data.displayLimited)
            coverage.append(
                element(
                    "p",
                    "eusage-notice",
                    "Display limit reached: at most 100 departments and 30 features per department are shown. Supplied ledger totals have not been recalculated from the visible cards.",
                ),
            );
        fact(coverageFacts, "Records scanned", count(data.coverage.scanned));
        fact(coverageFacts, "Record scan limit", count(data.coverage.limit));
        fact(coverageFacts, "Period", data.coverage.period);
        fact(coverageFacts, "Generated as of", data.asOf);
        coverage.append(coverageFacts);
        for (const basis of data.basis)
            coverage.append(element("p", "", basis));
        host.append(coverage);
        const budget = element("section", "eusage-card");
        budget.id = "enterprise-usage-technical-budget";
        budget.append(
            element("h3", "", "Technical budget readiness"),
            element(
                "span",
                "eusage-badge",
                data.budget.ready
                    ? "Technical guard ready"
                    : "Readiness not established",
            ),
            element("p", "", data.budget.status),
            element(
                "p",
                "",
                "This is a technical readiness status, not a price, included entitlement, remaining commercial balance or approved spending cap. This overview does not verify provider calls or invoices. Feature preference discovery does not enable AI or change access.",
            ),
        );
        host.append(budget, status);
        return Object.freeze({
            destroy() {
                active = false;
                host.replaceChildren();
                host.hidden = true;
            },
        });
    }
    globalThis.createUsageOverview = createUsageOverview;
    globalThis.EverbranchUsage = Object.freeze({
        createUsageOverview,
        model,
        formatMicroUsd,
    });
})();
