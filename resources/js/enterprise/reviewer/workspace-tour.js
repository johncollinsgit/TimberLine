/* Private demonstration navigation. No adapter installation, requests or record writes. */
(() => {
    "use strict";
    function create(boot) {
        if (
            boot.runtime !== "private" ||
            boot.enabled !== true ||
            boot.sample_data !== true ||
            !boot.identity?.viewer ||
            !boot.identity?.workspace
        )
            throw new Error("The signed-in demonstration tour is unavailable.");
        let context = null;
        const feature = (name) => context?.data?.features?.[name] === true;
        const area = () =>
            context?.data?.areas?.find(
                (row) => String(row.id) === String(context.area),
            );
        const project = () =>
            context?.data?.projects?.find(
                (row) => String(row.id) === String(context.selected),
            ) || context?.data?.projects?.[0];
        const request = () =>
            context?.data?.requests?.find(
                (row) => String(row.id) === String(context.selected),
            ) || context?.data?.requests?.[0];
        const ready = () => Boolean(context && area());
        function links(index) {
            const p = project(),
                r = request(),
                items = [];
            const add = (id, label, section, allowed, selected) =>
                items.push({
                    id,
                    label,
                    section,
                    available: ready() && Boolean(allowed),
                    ...(selected ? { selected } : {}),
                    reason: "Unavailable in the current authorized workspace or feature configuration.",
                });
            if (index === 0) {
                add("overview", "Campus overview", "home", feature("briefing"));
                add(
                    "buildings",
                    "Buildings",
                    "buildings",
                    feature("resources"),
                );
                add("calendar", "Calendar", "calendar", feature("resources"));
            }
            if (index === 1) {
                add(
                    "requests",
                    "Requests",
                    "requests",
                    context?.data?.can_submit || Boolean(r),
                    r?.id,
                );
                add(
                    "requester",
                    "My service requests",
                    "requester",
                    feature("requester"),
                );
            }
            if (index === 2) {
                add(
                    "workflow",
                    "Open complete workflow workspace",
                    "workflow",
                    feature("workflow") && Boolean(p),
                    p?.id,
                );
                add("projects", "Projects", "projects", Boolean(p), p?.id);
            }
            if (index === 3) {
                add(
                    "projects",
                    "Projects and retained work",
                    "projects",
                    Boolean(p),
                    p?.id,
                );
                add(
                    "roll-call",
                    "Roll Call",
                    "roll_call",
                    feature("roll_call") || (feature("reports") && context?.data?.can_review),
                );
            }
            if (index === 4) {
                add(
                    "people",
                    "People & contractors",
                    "people",
                    feature("planning") || feature("operations") || (feature("certificates") && context?.data?.can_review),
                );
                add("assets", "Assets", "assets", feature("resources"));
                add("calendar", "Calendar", "calendar", feature("resources"));
            }
            if (index === 5) {
                add(
                    "roll-call",
                    "Roll Call and meeting notes",
                    "roll_call",
                    feature("roll_call") || (feature("reports") && context?.data?.can_review),
                );
                add("projects", "Projects", "projects", Boolean(p), p?.id);
            }
            return items;
        }
        function describe(index, base) {
            const p = project(),
                r = request(),
                workspace = area();
            let records = [
                [
                    "Current workspace",
                    workspace?.name || "No currently authorized workspace",
                ],
                [
                    "Authorized request",
                    r?.title || "No request in the current result",
                ],
                [
                    "Authorized project",
                    p?.name || p?.title || "No project in the current result",
                ],
            ];
            if (index === 1)
                records = [
                    [
                        "Current request",
                        r?.title || "No request in the current result",
                    ],
                    [
                        "Recorded status",
                        r?.status || "Unavailable until a request is loaded",
                    ],
                    [
                        "Procedure spine",
                        "Open the request/project for current owner, next action, location and closure evidence.",
                    ],
                ];
            if (index === 2)
                records = [
                    [
                        "Current project",
                        p?.name || p?.title || "No authorized project loaded",
                    ],
                    [
                        "Procedure support",
                        "Forms, assignments, conditions and explicit approval review",
                    ],
                    [
                        "Finance boundary",
                        "No accounts-payable or finance ledger. Dedicated invoice/check procedure pending discovery.",
                    ],
                ];
            if (index === 3)
                records = [
                    [
                        "Current project",
                        p?.name || p?.title || "No authorized project loaded",
                    ],
                    [
                        "Procedure support",
                        "Open recorded ownership, checkpoint, next action, status and evidence",
                    ],
                    [
                        "Interruption boundary",
                        "Review existing interrupted work and explicit resumption; emergencies are not inferred.",
                    ],
                ];
            if (index === 4)
                records = [
                    [
                        "People and evidence",
                        "Authorized staff, contractor contacts and work evidence",
                    ],
                    [
                        "Scheduling support",
                        "Recorded exclusive reservations and explicit conflict resolution",
                    ],
                    [
                        "Insurance boundary",
                        feature("certificates")
                            ? "Manager-only certificate metadata register, reviewed replacements and internal reminders; external alerts remain disconnected."
                            : "Generic due tasks/evidence only; dedicated expiry registry and alerts pending discovery.",
                    ],
                ];
            if (index === 5)
                records = [
                    [
                        "Daily Roll Call",
                        "Current authorized priorities, decisions, owners and dates",
                    ],
                    [
                        "Fresh PDF snapshot",
                        "One dated page from the current checked scope",
                    ],
                    [
                        "Reporting boundary",
                        feature("reports")
                            ? "Quarter/date activity exports distinguish current records and recorded events. Approved report templates and external submission still require review."
                            : "Quarterly history/templates and automated reporting remain pending discovery.",
                    ],
                ];
            const copy = [
                base.text,
                "Open the current authorized request or your own service-request portal. Published updates and internal work remain separate. This tour does not create a student request.",
                "Open the complete workflow workspace for the currently selected authorized project, or the first project in the current result. Drafts, activation review and run history use the existing server-backed interface.",
                "Open current project work and Roll Call to inspect recorded ownership, checkpoints and resumption. The tour does not infer an emergency or claim an interruption that the records do not show.",
                "Open the current people, contractor, asset and calendar screens when enabled. Contacts, custody and reservations remain governed by existing permissions.",
                "Open current Roll Call and meeting notes. Any later task changes still require their own exact preview and confirmation; a downloaded PDF is a dated snapshot.",
            ];
            return {
                text: copy[index],
                role: `Signed in: ${boot.identity.userName || "current user"} · Current workspace: ${workspace?.name || "unavailable"}`,
                cards: records,
                detail: ready()
                    ? "These links use the current permission-filtered result. Data may have changed since it loaded; destination routes recheck access. Unavailable links never substitute sample records."
                    : "Waiting for the current authorized workspace. No sample record or alternate account will be substituted.",
            };
        }
        return Object.freeze({
            update(value) {
                context =
                    value?.transport?.mode === "private" &&
                    value.data &&
                    Array.isArray(value.data.areas)
                        ? value
                        : null;
            },
            links,
            describe,
            open(index, id) {
                const link = links(index).find((row) => row.id === id);
                if (!link?.available)
                    return {
                        available: false,
                        message:
                            "That screen or source record is unavailable in the current authorized workspace.",
                    };
                if (link.section === "workflow") {
                    if (
                        typeof boot.routes?.workflowWorkspace !== "string" ||
                        !boot.routes.workflowWorkspace
                    )
                        return {
                            available: false,
                            message:
                                "The authorized workflow destination is unavailable.",
                        };
                    const target = new URL(
                        boot.routes.workflowWorkspace,
                        globalThis.location.href,
                    );
                    if (
                        target.origin !== globalThis.location.origin ||
                        target.username ||
                        target.password
                    )
                        return {
                            available: false,
                            message:
                                "The authorized workflow destination is unavailable.",
                        };
                    target.search = new URLSearchParams({
                        tenant: boot.identity.tenantSlug,
                        area_id: context.area,
                        project_id: link.selected,
                    }).toString();
                    context.transport.navigate(target.href);
                } else {
                    const selected =
                        link.selected ||
                        (link.section === context.section
                            ? context.selected
                            : undefined);
                    globalThis.document.dispatchEvent(
                        new CustomEvent("enterprise:navigate", {
                            detail: {
                                section: link.section,
                                ...(selected ? { selected } : {}),
                            },
                        }),
                    );
                }
                return { available: true };
            },
        });
    }
    function mount(root, boot) {
        if (!root) return null;
        const navigation = create(boot),
            status = root.querySelector("[data-tour-status]");
        root.dataset.workspaceTour = "private";
        const links = document.createElement("nav");
        links.className = "ert-actions";
        links.setAttribute("aria-label", "Current workspace scene links");
        links.dataset.workspaceTourLinks = "";
        root.querySelector(".ert-stage").append(links);
        let currentIndex = 0,
            signature = "";
        function paintLinks(index) {
            currentIndex = index;
            const items = navigation.links(index),
                key = JSON.stringify(items);
            if (key === signature) return;
            signature = key;
            links.replaceChildren(
                ...items.map((item) => {
                    const button = document.createElement("button");
                    button.type = "button";
                    button.className = "ert-button";
                    button.textContent = item.label;
                    button.dataset.workspaceTourLink = item.id;
                    button.disabled = !item.available;
                    button.title = item.available
                        ? "Open current authorized screen"
                        : item.reason;
                    button.onclick = () => {
                        const result = navigation.open(index, item.id);
                        if (result.available) tour.close();
                        else status.textContent = result.message;
                    };
                    return button;
                }),
            );
            if (items.length && !items.some((row) => row.available)) {
                const note = document.createElement("p");
                note.className = "ert-caption";
                note.textContent =
                    "No source screen is available for this scene in the current workspace.";
                links.append(note);
            }
        }
        const tour = EverbranchReviewerTour.mount(root, {
            emailPreview: false,
            hideTrial: true,
            describeScene: (index, base) => navigation.describe(index, base),
            onScene: paintLinks,
            canNavigate: (index) =>
                navigation.links(index).some((row) => row.available),
            onNavigate(_section, index) {
                const link = navigation
                    .links(index)
                    .find((row) => row.available);
                const result = navigation.open(index, link?.id);
                if (!result.available) status.textContent = result.message;
            },
        });
        const update = (event) => {
            navigation.update(event.detail);
            signature = "";
            tour.refresh();
        };
        document.addEventListener("enterprise:render", update);
        document.addEventListener("enterprise:tour-context", update);
        document.addEventListener("enterprise:reset", () => {
            navigation.update(null);
            signature = "";
            tour.refresh();
        });
        document.querySelector(".content")?.prepend(root);
        return Object.freeze({
            tour,
            navigation,
            currentIndex: () => currentIndex,
        });
    }
    globalThis.EverbranchWorkspaceTour = Object.freeze({ create, mount });
})();
