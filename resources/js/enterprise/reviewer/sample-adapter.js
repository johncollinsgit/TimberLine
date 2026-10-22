/* Browser-only fictional transport for the SAME Enterprise renderer. No HTTP/provider fallback. */
(() => {
    "use strict";
    const KEY = "everbranch-enterprise-reviewer-v1";
    const instances = new Map();
    const IDS = Object.freeze({
        area: "10000000-0000-4000-8000-000000000001",
        it: "10000000-0000-4000-8000-000000000002",
        events: "10000000-0000-4000-8000-000000000003",
        projects: "10000000-0000-4000-8000-000000000004",
        project: "20000000-0000-4000-8000-000000000001",
        request: "30000000-0000-4000-8000-000000000001",
        task: "40000000-0000-4000-8000-000000000001",
        emergency: "40000000-0000-4000-8000-000000000002",
        contractor: "50000000-0000-4000-8000-000000000001",
        building: "60000000-0000-4000-8000-000000000052",
        asset: "70000000-0000-4000-8000-000000000001",
    });
    const clone = (value) => JSON.parse(JSON.stringify(value));
    const error = (message, status = 422) => {
        const value = new Error(message);
        value.status = status;
        throw value;
    };
    const now = () => new Date().toISOString();
    const uuid = () => crypto.randomUUID();
    function template(assignee = "1") {
        const screen = (title, fields = []) => ({
            title,
            description:
                "Fictional sample execution. No external messages or server workflow runs.",
            fields,
        });
        return {
            schema_version: 1,
            nodes: [
                {
                    id: "start",
                    type: "start",
                    label: "Start maintenance request",
                    position: { x: 0, y: 0 },
                },
                {
                    id: "intake",
                    type: "form",
                    label: "Describe the work",
                    position: { x: 0, y: 150 },
                    screen: screen("Work intake", [
                        {
                            key: "summary",
                            label: "Work description",
                            type: "textarea",
                            required: true,
                        },
                        {
                            key: "priority",
                            label: "Priority",
                            type: "select",
                            required: true,
                            options: ["Routine", "Urgent"],
                        },
                    ]),
                },
                {
                    id: "priority",
                    type: "condition",
                    label: "Urgent work?",
                    position: { x: 0, y: 300 },
                    condition: {
                        field: "priority",
                        operator: "equals",
                        value: "Urgent",
                    },
                },
                {
                    id: "approval",
                    type: "approval",
                    label: "Coordinator approval",
                    position: { x: 240, y: 450 },
                    assignee_id: assignee,
                    screen: screen("Review urgent work"),
                },
                {
                    id: "work",
                    type: "assignment",
                    label: "Complete the assigned work",
                    position: { x: 0, y: 600 },
                    assignee_id: assignee,
                    screen: screen("Work completion", [
                        {
                            key: "outcome",
                            label: "Completion note",
                            type: "textarea",
                            required: true,
                        },
                    ]),
                },
                {
                    id: "complete",
                    type: "complete",
                    label: "Work completed",
                    position: { x: 0, y: 750 },
                },
            ],
            edges: [
                {
                    id: "e1",
                    source: "start",
                    target: "intake",
                    branch: "always",
                },
                {
                    id: "e2",
                    source: "intake",
                    target: "priority",
                    branch: "always",
                },
                {
                    id: "e3",
                    source: "priority",
                    target: "approval",
                    branch: "yes",
                },
                { id: "e4", source: "priority", target: "work", branch: "no" },
                {
                    id: "e5",
                    source: "approval",
                    target: "work",
                    branch: "always",
                },
                {
                    id: "e6",
                    source: "work",
                    target: "complete",
                    branch: "always",
                },
            ],
        };
    }
    function seed() {
        const timestamp = now();
        return {
            schema: 1,
            revision: 1,
            persona: "manager",
            receipts: {},
            histories: {},
            workflows: [],
            runs: [],
            discussions: {},
            uploads: [],
            plans: [
                {
                    id: IDS.contractor,
                    kind: "contractor",
                    area_id: IDS.area,
                    title: "Sample campus contractor",
                    status: "active",
                    version: 1,
                    data: {},
                    updated_at: timestamp,
                },
            ],
            areas: [
                {
                    id: IDS.area,
                    name: "Operations · sample",
                    visibility: "shared",
                },
                { id: IDS.it, name: "IT · sample", visibility: "shared" },
                {
                    id: IDS.events,
                    name: "Events · sample",
                    visibility: "shared",
                },
                {
                    id: IDS.projects,
                    name: "Projects · sample",
                    visibility: "department_only",
                },
            ],
            projects: [
                {
                    id: IDS.project,
                    name: "Campus event readiness · sample",
                    description:
                        "Fictional coordinated event preparation. No live college schedule or inventory is connected.",
                    enterprise_area_id: IDS.area,
                    version: 1,
                    archived: false,
                    tasks: [IDS.task, IDS.emergency],
                },
            ],
            requests: [
                {
                    id: IDS.request,
                    area_id: IDS.area,
                    title: "Event audio needs a check · sample",
                    version: 1,
                    status: "assigned",
                    due_on: "2026-10-23",
                    data: {
                        description:
                            "Fictional student request: check audio before a gym event.",
                        location: "Bryan Gym",
                        latitude: null,
                        longitude: null,
                        created_by: "3",
                        task_id: IDS.task,
                        project_id: IDS.project,
                        progress_note:
                            "Sample worker is checking the PA system. Internal sample notes remain separate from requester updates.",
                    },
                    task_id: IDS.task,
                },
            ],
            tasks: [
                {
                    id: IDS.task,
                    title: "Check event audio · sample",
                    version: 1,
                    assignee_id: "2",
                    completed_at: null,
                    due_on: "2026-10-23",
                    enterprise_area_id: IDS.area,
                    project_id: IDS.project,
                    status: "active",
                    accountable_owner_id: "1",
                    ball_user_id: "2",
                    next_action: "Check audio equipment before event",
                    blockers: [],
                    checkpoint: "PA system located; sound check remains",
                    checklist: [
                        {
                            key: "locate",
                            label: "Locate equipment",
                            weight: 1,
                            completed: true,
                        },
                        {
                            key: "sound",
                            label: "Complete sound check",
                            weight: 1,
                            completed: false,
                        },
                    ],
                    approved: false,
                },
                {
                    id: IDS.emergency,
                    title: "Urgent water response · sample",
                    version: 1,
                    assignee_id: "2",
                    completed_at: null,
                    due_on: "2026-10-23",
                    enterprise_area_id: IDS.area,
                    project_id: IDS.project,
                    status: "active",
                    accountable_owner_id: "1",
                    ball_user_id: "2",
                    next_action: "Make the area safe and report the checkpoint",
                    blockers: [],
                    checkpoint: "",
                    checklist: [],
                    approved: false,
                },
            ],
            handoffs: [],
            reviews: [],
            resources: [
                {
                    id: IDS.building,
                    kind: "location",
                    area_id: IDS.area,
                    title: "Bryan Gym",
                    version: 1,
                    status: "active",
                    data: {
                        level: "building",
                        catalog_map_number: "52",
                        latitude: null,
                        longitude: null,
                        placement_verified: false,
                        source: "https://www.lmc.edu/campus-life/campus-safety/campus-operations/files/campus-parking-map.pdf",
                        source_page: 2,
                    },
                },
                {
                    id: IDS.asset,
                    kind: "asset",
                    area_id: IDS.area,
                    title: "PA system · fictional sample inventory",
                    version: 1,
                    status: "checked_out",
                    data: {
                        location_id: IDS.building,
                        holder_id: "2",
                        checked_out_at: timestamp,
                        expected_return_at: "2026-10-23T22:00:00Z",
                        condition:
                            "Sample condition: working; audio check pending",
                        custody_history: [
                            {
                                at: timestamp,
                                action: "sample_checkout",
                                holder_id: "2",
                                location_id: IDS.building,
                            },
                        ],
                    },
                },
            ],
            reservations: [],
            meetings: [],
        };
    }
    async function digest(value) {
        const bytes = new TextEncoder().encode(JSON.stringify(value));
        return [...new Uint8Array(await crypto.subtle.digest("SHA-256", bytes))]
            .map((value) => value.toString(16).padStart(2, "0"))
            .join("");
    }
    function create(boot) {
        if (boot.runtime !== "sample")
            error(
                "Sample transport requires trusted sample presentation.",
                403,
            );
        const storage = boot.sampleStorage ?? globalThis.sessionStorage;
        const key = KEY + ":" + String(boot.identity?.workspace ?? "campus");
        if (instances.get(key)?.storage === storage)
            return instances.get(key).adapter;
        let state;
        try {
            state = JSON.parse(storage?.getItem(key) || "null");
        } catch {}
        if (!state || state.schema !== 1) state = seed();
        const members = [
            {
                id: "1",
                name: "Operations coordinator · sample",
                can_approve: true,
            },
            { id: "2", name: "Campus worker · sample", can_approve: false },
            { id: "3", name: "Student requester · sample", can_approve: false },
        ];
        const currentId = () =>
            state.persona === "requester"
                ? "3"
                : state.persona === "worker"
                  ? "2"
                  : "1";
        const manager = () => state.persona === "manager";
        const canWrite = () => state.persona !== "requester";
        function save() {
            state.revision++;
            try {
                storage?.setItem(key, JSON.stringify(state));
            } catch {
                error(
                    "This browser could not retain the sample. Export your notes or free space before continuing.",
                    507,
                );
            }
        }
        const get = (list, id) =>
            list.find((row) => row.id === id) ??
            error("This fictional record is unavailable in the sample.", 404);
        const checkVersion = (row, value) => {
            if (row.version !== Number(value))
                error(
                    "This sample record changed. Refresh before editing.",
                    409,
                );
        };
        const coordinate = () => {
            if (!manager())
                error(
                    "Switch to the sample manager perspective for this action.",
                    403,
                );
        };
        const write = () => {
            if (!canWrite())
                error(
                    "This sample perspective is read only for operational work.",
                    403,
                );
        };
        function project(id) {
            return get(state.projects, id);
        }
        function area(id) {
            return get(state.areas, id || IDS.area);
        }
        function audit(id, action, details = {}) {
            (state.histories[id] ??= []).push({
                version: state.histories[id]?.length + 1 || 1,
                actor_id: currentId(),
                at: now(),
                event: { action, ...details },
            });
        }
        function navigation(url) {
            const parsed = new URL(
                String(url),
                globalThis.location?.href ?? "https://sample.invalid/",
            );
            const origin = new URL(
                globalThis.location?.href ?? "https://sample.invalid/",
            ).origin;
            if (parsed.origin !== origin || parsed.username || parsed.password)
                error(
                    "Sample navigation stays within this demonstration.",
                    403,
                );
            const query = new URLSearchParams(parsed.hash.slice(1));
            for (const [name, value] of new URLSearchParams(parsed.search))
                query.set(name, value);
            const result = {};
            for (const name of [
                "area_id",
                "project_id",
                "selected",
                "section",
                "tab",
                "workflow",
                "run",
                "scope",
                "team_user_id",
                "meeting",
            ])
                if (query.has(name)) result[name] = query.get(name);
            return { parsed, values: result };
        }
        function href(url) {
            if (String(url).startsWith("blob:")) return String(url);
            const { parsed, values } = navigation(url);
            if (
                boot.routes.trial &&
                parsed.pathname ===
                    new URL(
                        boot.routes.trial,
                        globalThis.location?.href ?? "https://sample.invalid/",
                    ).pathname
            )
                return parsed.pathname;
            if (
                parsed.pathname.endsWith(".pdf") ||
                /\/meetings\/[^/]+\/pdf$/.test(parsed.pathname)
            )
                return pdfHref(
                    parsed.pathname.match(/\/meetings\/([^/]+)\/pdf$/)?.[1],
                );
            const workflow =
                parsed.pathname.includes("/projects/workspace") ||
                (parsed.pathname.endsWith("/workflows") &&
                    parsed.pathname.startsWith("/platform/demos/"));
            const current = new URL(
                globalThis.location?.href ?? "https://sample.invalid/",
            );
            if (
                !workflow &&
                current.pathname.endsWith("/workflows") &&
                parsed.pathname ===
                    new URL(boot.routes.back, current).pathname &&
                !Object.keys(values).length
            ) {
                const scope = new URLSearchParams(current.hash.slice(1));
                values.area_id =
                    scope.get("area_id") || boot.scope?.area_id || IDS.area;
                values.section = "projects";
                values.selected =
                    scope.get("project_id") ||
                    boot.scope?.project_id ||
                    IDS.project;
            }
            const target = new URL(
                workflow ? boot.routes.workflowWorkspace : boot.routes.back,
                globalThis.location?.href ?? "https://sample.invalid/",
            );
            target.search = "";
            target.hash = new URLSearchParams(values).toString();
            return target.pathname + target.hash;
        }
        function replace(url) {
            const target = href(url);
            globalThis.history?.replaceState(null, "", target);
        }
        function navigate(url) {
            globalThis.location?.assign(href(url));
        }
        function requestView(row) {
            const task = row.task_id ? get(state.tasks, row.task_id) : null;
            if (state.persona === "requester")
                return {
                    id: row.id,
                    area_id: row.area_id,
                    title: row.title,
                    version: row.version,
                    status: row.status,
                    due_on: row.due_on,
                    data: {
                        description: row.data.description,
                        location: row.data.location,
                        created_by: row.data.created_by,
                    },
                    task: null,
                    can_coordinate: false,
                    can_progress: false,
                };
            return {
                ...clone(row),
                task: task ? clone(task) : null,
                can_coordinate: manager(),
                can_progress:
                    canWrite() &&
                    (manager() || task?.assignee_id === currentId()),
            };
        }
        function data(query) {
            const selected = area(query.get("area_id") || IDS.area).id;
            return {
                workspace: {
                    id: "sample-campus",
                    name: "Lees-McRae · fictional reviewer demonstration",
                },
                selected_area_id: selected,
                viewer_id: currentId(),
                areas: clone(state.areas),
                requests: state.requests
                    .filter(
                        (row) =>
                            row.area_id === selected &&
                            (state.persona !== "requester" ||
                                row.data.created_by === currentId()),
                    )
                    .map(requestView),
                projects: clone(
                    state.projects
                        .filter((row) => row.enterprise_area_id === selected)
                        .filter(() => state.persona !== "requester"),
                ),
                members:
                    state.persona === "requester"
                        ? []
                        : clone(members.filter((row) => row.id !== "3")),
                features: {
                    collaboration: state.persona !== "requester",
                    planning: state.persona !== "requester",
                    ask: true,
                    email_drafts: false,
                    workflow: state.persona !== "requester",
                    briefing: state.persona !== "requester",
                    operations: state.persona !== "requester",
                    resources: true,
                    roll_call: state.persona !== "requester",
                    requester: true,
                },
                coverage:
                    "Fictional browser sample only. Perspective filtering is illustrative, not server authorization.",
                has_more: false,
                scan_partial: false,
                can_manage: manager(),
                can_submit: true,
                can_coordinate: manager(),
                sample_data: true,
            };
        }
        const recordUrl = (section, id, areaId = IDS.area) =>
            "/?area_id=" +
            encodeURIComponent(areaId) +
            "&section=" +
            section +
            "&selected=" +
            encodeURIComponent(id);
        function briefing(areaId) {
            const selected = area(areaId),
                projects = state.projects
                    .filter(
                        (row) =>
                            state.persona !== "requester" &&
                            row.enterprise_area_id === selected.id,
                    )
                    .map((row) => {
                        const tasks = state.tasks.filter(
                            (task) => task.project_id === row.id,
                        );
                        return {
                            id: row.id,
                            title: row.name,
                            version: row.version,
                            status: row.archived ? "archived" : "active",
                            tasks: tasks.length,
                            completed_tasks: tasks.filter(
                                (task) => task.completed_at,
                            ).length,
                            overdue_tasks: 0,
                            url: recordUrl(
                                "projects",
                                row.id,
                                row.enterprise_area_id,
                            ),
                        };
                    });
            const requests = state.requests
                .filter(
                    (row) =>
                        row.area_id === selected.id &&
                        row.status !== "completed" &&
                        (state.persona !== "requester" ||
                            row.data.created_by === currentId()),
                )
                .map((row) => ({
                    id: row.id,
                    title: row.title,
                    version: row.version,
                    status: row.status,
                    due_on: row.due_on,
                    url: recordUrl("requests", row.id, row.area_id),
                }));
            const taskCount = projects.reduce((sum, row) => sum + row.tasks, 0),
                completed = projects.reduce(
                    (sum, row) => sum + row.completed_tasks,
                    0,
                );
            return {
                as_of: now(),
                scope: {
                    workspace: "Fictional campus sample",
                    department: selected.name,
                    area_id: selected.id,
                },
                sample_data: true,
                label: "SAMPLE DATA · browser simulation",
                counts: {
                    projects: projects.length,
                    tasks: taskCount,
                    completed_tasks: completed,
                    overdue_tasks: 0,
                    open_requests: requests.length,
                },
                summary: {
                    mode: "sample_record_backed",
                    headline: "A clear next step for sample campus work.",
                    text: `${projects.length} sample projects, ${taskCount} linked tasks and ${requests.length} open requests in this browser.`,
                    model_status:
                        "Fictional record-derived summary. No AI call, live integration or provider charge.",
                    priority_items: [],
                },
                projects,
                requests,
                sources: [
                    ...projects.map((row) => ({
                        ref: "project:" + row.id,
                        title: row.title,
                        url: row.url,
                        facts: { status: row.status },
                    })),
                    ...requests.map((row) => ({
                        ref: "request:" + row.id,
                        title: row.title,
                        url: row.url,
                        facts: { status: row.status },
                    })),
                ],
                coverage:
                    "This tab’s fictional sample only. No college systems, live schedules, inboxes, security cases or real student records are connected.",
                snapshot_notice:
                    "Refresh or download to regenerate a sample snapshot. Saved PDFs do not update.",
            };
        }
        function pdfHref(meetingId) {
            if (state.persona === "requester")
                error(
                    "Requester exports exclude internal sample operations and meetings.",
                    403,
                );
            const meeting = meetingId ? get(state.meetings, meetingId) : null;
            const query = new URLSearchParams(
                meeting?.scope || { type: "workspace", area_id: IDS.area },
            );
            const snapshot = rollCall(query);
            const priorities = snapshot.items.map(
                (row) =>
                    `${row.kind.toUpperCase()}: ${row.title} | Owner: ${row.owner?.name || "Unassigned"} | Next: ${row.next_action} | Due: ${row.due_on || "Unknown"}`,
            );
            const notes = meeting
                ? [
                      ...meeting.decisions.map(
                          (row) => "DECISION: " + row.text,
                      ),
                      ...meeting.blockers.map((row) => "BLOCKER: " + row.text),
                      ...meeting.commitments.map(
                          (row) =>
                              "COMMITMENT: " +
                              row.text +
                              " | Owner " +
                              row.owner_id +
                              " | Due " +
                              row.due_on,
                      ),
                  ]
                : [];
            const wrap = (line) =>
                String(line).match(/.{1,92}(?:\s|$)|.{1,92}/g) || [""];
            const header = [
                "EVERBRANCH ENTERPRISE - FICTIONAL MEETING ROLL CALL",
                "SAMPLE DATA - Not live college operations",
                "As of " + now(),
                "Scope: " +
                    (meeting?.scope.type || "sample Operations workspace"),
                meeting?.title || "",
            ].flatMap(wrap);
            const footer = [
                "See the complete sample view for full history and current details.",
                "Completion 4.0 requires explicit manager approval.",
                "Source: " +
                    new URL(
                        href(boot.routes.back),
                        globalThis.location?.href ?? "https://sample.invalid/",
                    ).href,
            ].flatMap(wrap);
            // Reserve the as-of/scope header and overflow/source footer even for
            // maximum-length meeting notes. Omitted records remain in the HTML.
            const body = [],
                counts = [0, 0];
            const bodyLimit = Math.max(
                0,
                43 - header.length - footer.length - 3,
            );
            for (const [kind, entries] of [priorities, notes].entries()) {
                for (const entry of entries) {
                    const lines = wrap(entry);
                    if (body.length + lines.length > bodyLimit) continue;
                    body.push(...lines);
                    counts[kind]++;
                }
                if (body.length < bodyLimit) body.push("");
            }
            const wrapped = [
                ...header,
                ...body,
                "",
                `${priorities.length - counts[0]} additional priorities; ${notes.length - counts[1]} additional meeting items.`,
                ...footer,
            ];
            const clean = (text) =>
                String(text)
                    .normalize("NFKD")
                    .replace(/[\u2010-\u2015]/g, "-")
                    .replace(/\u00b7/g, "-")
                    .replace(/[\u2018\u2019]/g, "'")
                    .replace(/[\u201c\u201d]/g, '"')
                    .replace(/[^\x20-\x7e]/g, "?")
                    .replace(/([\\()])/g, "\\$1");
            const content =
                "BT /F1 10 Tf 42 748 Td 15 TL " +
                wrapped
                    .map(
                        (line, index) =>
                            (index ? "T* " : "") +
                            "(" +
                            clean(line.trim()) +
                            ") Tj",
                    )
                    .join("\n") +
                " ET";
            const objects = [
                "<< /Type /Catalog /Pages 2 0 R >>",
                "<< /Type /Pages /Count 1 /Kids [3 0 R] >>",
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
                "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
                `<< /Length ${content.length} >>\nstream\n${content}\nendstream`,
            ];
            let pdf = "%PDF-1.4\n",
                positions = [0];
            objects.forEach((object, index) => {
                positions.push(pdf.length);
                pdf += `${index + 1} 0 obj\n${object}\nendobj\n`;
            });
            const xref = pdf.length;
            pdf +=
                `xref\n0 6\n0000000000 65535 f \n` +
                positions
                    .slice(1)
                    .map(
                        (position) =>
                            String(position).padStart(10, "0") + " 00000 n \n",
                    )
                    .join("") +
                `trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
            return URL.createObjectURL(
                new Blob([pdf], { type: "application/pdf" }),
            );
        }
        function command(body) {
            const action = body.action;
            if (action === "workspace.create") {
                coordinate();
                if (state.areas.some((row) => row.id === body.id))
                    error("Sample workspace already exists.", 409);
                state.areas.push({
                    id: body.id,
                    name: body.title,
                    visibility: body.visibility,
                });
                return { id: body.id };
            }
            area(body.area_id);
            if (action === "project.create") {
                coordinate();
                if (state.projects.some((row) => row.id === body.id))
                    error("Sample project already exists.", 409);
                state.projects.push({
                    id: body.id,
                    name: body.title,
                    description: "Reviewer-created fictional project.",
                    enterprise_area_id: body.area_id,
                    version: 1,
                    archived: false,
                    tasks: [],
                });
                return { id: body.id };
            }
            if (action === "request.create") {
                if (state.requests.some((row) => row.id === body.id))
                    error("Sample request already exists.", 409);
                const row = {
                    id: body.id,
                    area_id: body.area_id,
                    title: String(body.title).slice(0, 100),
                    version: 1,
                    status: "submitted",
                    due_on: body.due_on ?? null,
                    data: {
                        description: String(body.description ?? "").slice(
                            0,
                            5000,
                        ),
                        location: body.location ?? "",
                        latitude: null,
                        longitude: null,
                        created_by: currentId(),
                        progress_note: "",
                    },
                    task_id: null,
                };
                state.requests.unshift(row);
                audit(row.id, action);
                return requestView(row);
            }
            const row = get(state.requests, body.id);
            checkVersion(row, body.expected_version);
            if (row.area_id !== body.area_id)
                error("Choose the request’s owning sample workspace.", 404);
            if (action === "request.triage") {
                coordinate();
                if (row.status !== "submitted")
                    error(
                        "Only submitted sample requests can be triaged.",
                        409,
                    );
                row.status = "triaged";
                row.data.triage_note = body.note ?? "";
            } else if (action === "request.assign") {
                coordinate();
                if (row.status !== "triaged")
                    error(
                        "Triage the sample request before assigning it.",
                        409,
                    );
                const selectedProject = project(body.project_id);
                if (
                    selectedProject.enterprise_area_id !== row.area_id ||
                    !members
                        .filter((member) => member.id !== "3")
                        .some(
                            (member) => member.id === String(body.assignee_id),
                        )
                )
                    error(
                        "Choose a sample project/editor in the owning workspace.",
                        422,
                    );
                const id = uuid();
                state.tasks.push({
                    id,
                    title: row.title,
                    version: 1,
                    assignee_id: String(body.assignee_id),
                    completed_at: null,
                    due_on: row.due_on,
                    enterprise_area_id: row.area_id,
                    project_id: selectedProject.id,
                    status: "active",
                    accountable_owner_id: "1",
                    ball_user_id: String(body.assignee_id),
                    next_action: "Complete the triaged sample request",
                    blockers: [],
                    checkpoint: "",
                    checklist: [],
                    approved: false,
                });
                row.task_id = id;
                row.data.task_id = id;
                row.data.project_id = selectedProject.id;
                row.status = "assigned";
                selectedProject.tasks.push(id);
                selectedProject.version++;
            } else if (
                ["request.progress", "request.complete"].includes(action)
            ) {
                write();
                const task = get(state.tasks, row.task_id);
                if (!manager() && task.assignee_id !== currentId())
                    error(
                        "Only the sample assignee or manager can update this work.",
                        403,
                    );
                if (task.status === "interrupted")
                    error(
                        "Explicitly resume the interrupted sample work first.",
                        409,
                    );
                row.status = "in_progress";
                row.data.progress_note = body.note ?? "";
                task.checkpoint = body.note ?? task.checkpoint;
                task.version++;
                if (action === "request.complete") {
                    task.status = "awaiting_verification";
                    row.data.progress_note +=
                        " Sample work performed; manager verification is required for 4.0.";
                }
            } else
                error("That command is not available in the isolated sample.");
            row.version++;
            audit(row.id, action);
            return requestView(row);
        }
        function workflowScope(areaId, projectId) {
            const selected = project(projectId || IDS.project);
            area(areaId || selected.enterprise_area_id);
            const sampleDefinition = template("2");
            sampleDefinition.nodes.find((node) => node.id === "work").task_id =
                IDS.task;
            Object.assign(
                sampleDefinition.nodes.find((node) => node.id === "approval"),
                {
                    task_id: IDS.task,
                    assignee_id: "1",
                    label: "Manager final verification",
                },
            );
            sampleDefinition.edges = [
                {
                    id: "e1",
                    source: "start",
                    target: "intake",
                    branch: "always",
                },
                {
                    id: "e2",
                    source: "intake",
                    target: "priority",
                    branch: "always",
                },
                { id: "e3", source: "priority", target: "work", branch: "yes" },
                { id: "e4", source: "priority", target: "work", branch: "no" },
                {
                    id: "e5",
                    source: "work",
                    target: "approval",
                    branch: "always",
                },
                {
                    id: "e6",
                    source: "approval",
                    target: "complete",
                    branch: "always",
                },
            ];
            return {
                project: { id: selected.id, name: selected.name },
                members: clone(members.filter((member) => member.id !== "3")),
                operations_enabled: true,
                tasks: state.tasks
                    .filter((task) => task.project_id === selected.id)
                    .map(taskView),
                can_design: manager(),
                can_run: canWrite(),
                items: [],
                templates: [
                    {
                        key: "maintenance",
                        name: "Illustrative maintenance workflow · SAMPLE",
                        sample: true,
                        definition: sampleDefinition,
                    },
                ],
                coverage:
                    "Browser sample execution only; no server-backed workflow or provider calls.",
                as_of: now(),
            };
        }
        function compile(definition) {
            const value = clone(definition);
            if (
                !Array.isArray(value.nodes) ||
                !Array.isArray(value.edges) ||
                value.nodes.length < 2 ||
                value.nodes.length > 24 ||
                value.edges.length > 48
            )
                error("Choose a bounded connected sample workflow.");
            const nodeIds = new Set(value.nodes.map((node) => node.id));
            if (
                nodeIds.size !== value.nodes.length ||
                value.nodes.some(
                    (node) =>
                        ![
                            "start",
                            "form",
                            "condition",
                            "assignment",
                            "approval",
                            "complete",
                        ].includes(node.type),
                ) ||
                value.nodes.filter((node) => node.type === "start").length !==
                    1 ||
                !value.nodes.some((node) => node.type === "complete")
            )
                error(
                    "A sample workflow needs one Start, a completion and supported step types.",
                );
            if (
                value.edges.some(
                    (edge) =>
                        !nodeIds.has(edge.source) || !nodeIds.has(edge.target),
                )
            )
                error("Every connection must use a sample step.");
            value.schema_version = 1;
            return value;
        }
        function walk(run) {
            const seen = new Set();
            while (true) {
                const node = run.definition.nodes.find(
                    (node) => node.id === run.current_node_id,
                );
                if (!node || seen.has(node.id))
                    error(
                        "The sample graph has a missing connection or cycle.",
                    );
                seen.add(node.id);
                if (["form", "assignment", "approval"].includes(node.type)) {
                    run.status = "waiting";
                    return;
                }
                if (node.type === "complete") {
                    run.history.push({
                        node_id: node.id,
                        type: node.type,
                        label: node.label,
                        user_id: currentId(),
                        at: now(),
                        decision: "completed",
                    });
                    run.status = "completed";
                    return;
                }
                let branch = "always";
                if (node.type === "condition") {
                    const condition = node.condition;
                    if (!(condition.field in run.values))
                        error("Collect the condition field before this step.");
                    const matches =
                        run.values[condition.field] === condition.value;
                    branch = (
                        condition.operator === "equals" ? matches : !matches
                    )
                        ? "yes"
                        : "no";
                }
                run.history.push({
                    node_id: node.id,
                    type: node.type,
                    label: node.label,
                    user_id: currentId(),
                    at: now(),
                    decision: branch,
                });
                const edge = run.definition.edges.find(
                    (edge) =>
                        edge.source === node.id &&
                        (edge.branch || "always") === branch,
                );
                if (!edge) error("Connect the sample step before running it.");
                run.current_node_id = edge.target;
            }
        }
        function runView(run) {
            const node = run.definition.nodes.find(
                (node) => node.id === run.current_node_id,
            );
            const can =
                run.status === "waiting" &&
                canWrite() &&
                (node.type === "form" ||
                    String(node.assignee_id) === currentId()) &&
                (node.type !== "approval" || manager());
            return {
                ...clone(run),
                current_node: clone(node),
                linked_task: node.task_id
                    ? taskView(get(state.tasks, node.task_id))
                    : null,
                can_advance: can,
                allowed_decisions: can
                    ? node.type === "approval"
                        ? ["approve", "reject"]
                        : ["complete"]
                    : [],
                collected_fields: run.definition.nodes.flatMap((node) =>
                    (node.screen?.fields || [])
                        .filter((field) => field.key in run.values)
                        .map((field) => ({
                            ...field,
                            value: run.values[field.key],
                        })),
                ),
                updated_at: now(),
                sample_execution: true,
            };
        }
        async function workflowRequest(path, query, body) {
            const parts = path
                .slice("/enterprise/workflows".length)
                .split("/")
                .filter(Boolean);
            if (!parts.length && !body) {
                const scope = workflowScope(
                    query.get("area_id"),
                    query.get("project_id"),
                );
                return {
                    ...scope,
                    items: clone(
                        state.workflows.filter(
                            (row) =>
                                row.area_id ===
                                    (query.get("area_id") || IDS.area) &&
                                row.project_id === scope.project.id,
                        ),
                    ),
                };
            }
            if (parts[0] === "propose") {
                coordinate();
                return {
                    definition: workflowScope(body.area_id, body.project_id)
                        .templates[0].definition,
                    proposal: true,
                    replayed: false,
                    sample_execution: true,
                    notice: "Illustrative sample proposal, not a real AI response. No provider charge.",
                };
            }
            if (parts[0] === "preview") {
                const definition = compile(body.definition);
                const run = {
                    definition,
                    values: body.values || {},
                    current_node_id: definition.nodes.find(
                        (node) => node.type === "start",
                    ).id,
                    history: [],
                };
                walk(run);
                return {
                    ...run,
                    digest: await digest(definition),
                    steps: definition.nodes,
                    preview: true,
                    notice: "Browser sample preview only. No run, task or external action created.",
                };
            }
            if (!parts.length && body) {
                coordinate();
                workflowScope(body.area_id, body.project_id);
                const definition = compile(body.definition);
                let row = state.workflows.find((row) => row.id === body.id);
                if (row) checkVersion(row, body.expected_version);
                else {
                    if (Number(body.expected_version) !== 0)
                        error("This sample draft no longer exists.", 409);
                    row = {
                        id: body.id,
                        area_id: body.area_id,
                        project_id: body.project_id,
                        version: 0,
                        status: "draft",
                    };
                    state.workflows.push(row);
                }
                Object.assign(row, {
                    name: String(body.name).slice(0, 100),
                    definition,
                    digest: await digest(definition),
                    version: row.version + 1,
                    updated_at: now(),
                });
                audit(row.id, "sample.workflow.save");
                return clone(row);
            }
            const row = get(state.workflows, parts[0]);
            if (parts[1] === "data")
                return {
                    ...workflowScope(row.area_id, row.project_id),
                    workflow: clone(row),
                    runs: state.runs
                        .filter((run) => run.workflow_id === row.id)
                        .map(runView),
                };
            if (["activate", "pause"].includes(parts[1])) {
                coordinate();
                checkVersion(row, body.expected_version);
                if (
                    parts[1] === "activate" &&
                    (body.confirmed !== true || body.digest !== row.digest)
                )
                    error(
                        "Review and confirm the exact sample draft before activation.",
                        409,
                    );
                if (parts[1] === "activate")
                    for (const node of row.definition.nodes.filter((node) =>
                        ["assignment", "approval"].includes(node.type),
                    )) {
                        if (!node.task_id)
                            error(
                                "Map each assignment and approval to a configured sample project task.",
                            );
                        const task = get(state.tasks, node.task_id);
                        if (
                            task.project_id !== row.project_id ||
                            !task.checklist.length ||
                            (node.type === "assignment" &&
                                String(node.assignee_id) !==
                                    task.assignee_id) ||
                            (node.type === "approval" &&
                                String(node.assignee_id) !== "1")
                        )
                            error(
                                "Review the sample task/checklist and current assignee before activation.",
                            );
                    }
                row.status = parts[1] === "activate" ? "active" : "paused";
                row.version++;
                if (row.status === "active") {
                    row.active_definition = clone(row.definition);
                    row.active_version = row.version;
                    row.active_digest = row.digest;
                    row.activated_at = now();
                }
                audit(row.id, "sample.workflow." + parts[1]);
                return clone(row);
            }
            if (parts[1] === "runs" && !parts[2]) {
                write();
                checkVersion(row, body.expected_version);
                if (row.status !== "active")
                    error("Activate the reviewed sample workflow first.", 409);
                if (state.runs.some((run) => run.id === body.id))
                    error("This sample run already exists.", 409);
                const run = {
                    id: body.id,
                    workflow_id: row.id,
                    project_id: row.project_id,
                    version: 1,
                    active_version: row.active_version,
                    definition_digest: row.active_digest,
                    definition: clone(row.active_definition),
                    values: {},
                    history: [],
                    current_node_id: row.active_definition.nodes.find(
                        (node) => node.type === "start",
                    ).id,
                    status: "waiting",
                };
                walk(run);
                state.runs.push(run);
                audit(run.id, "sample.workflow.start");
                return runView(run);
            }
            if (parts[1] === "runs" && parts[3] === "advance") {
                write();
                const run = get(state.runs, parts[2]);
                if (run.workflow_id !== row.id || !runView(run).can_advance)
                    error(
                        "Switch to the assigned sample operator to complete this screen.",
                        403,
                    );
                checkVersion(run, body.expected_version);
                const node = run.definition.nodes.find(
                        (node) => node.id === run.current_node_id,
                    ),
                    fields = node.screen?.fields || [],
                    values = body.values || {};
                if (node.task_id) {
                    const task = get(state.tasks, node.task_id),
                        linked = taskView(task);
                    if (
                        linked.task_version !==
                            Number(body.expected_task_version) ||
                        linked.version !==
                            Number(body.expected_operations_version)
                    )
                        error(
                            "The linked sample task changed. Reload before advancing.",
                            409,
                        );
                    if (task.status === "interrupted")
                        error(
                            "Explicitly resume the interrupted sample task first.",
                            409,
                        );
                    if (
                        node.type === "assignment" &&
                        linked.progress_percent !== 100
                    )
                        error(
                            "Perform the linked sample checklist before completing the assignment.",
                        );
                }
                if (
                    Object.keys(values).some(
                        (key) => !fields.some((field) => field.key === key),
                    )
                )
                    error(
                        "Only current sample-screen fields can be submitted.",
                    );
                for (const field of fields) {
                    const value = values[field.key];
                    if (
                        field.required &&
                        (value === undefined || value === null || value === "")
                    )
                        error("Complete the required sample fields.");
                    if (
                        value !== undefined &&
                        (field.type === "checkbox"
                            ? typeof value !== "boolean"
                            : typeof value !== "string" || value.length > 4000)
                    )
                        error("Use the supported sample field type.");
                    if (
                        field.type === "select" &&
                        value !== undefined &&
                        !field.options.includes(value)
                    )
                        error("Choose an available sample option.");
                }
                const decision = body.decision || "complete";
                if (
                    !(
                        node.type === "approval"
                            ? ["approve", "reject"]
                            : ["complete"]
                    ).includes(decision)
                )
                    error("Choose a valid sample step decision.");
                if (
                    node.type === "approval" &&
                    node.task_id &&
                    decision === "approve"
                )
                    operationCommand({
                        action: "task.approve",
                        task_id: node.task_id,
                        expected_version: body.expected_operations_version,
                        expected_task_version: body.expected_task_version,
                        note: "Approved through fictional sample workflow.",
                    });
                run.values = { ...run.values, ...values };
                run.history.push({
                    node_id: node.id,
                    type: node.type,
                    label: node.label,
                    user_id: currentId(),
                    at: now(),
                    decision,
                    fields: Object.keys(values),
                });
                if (decision === "reject") run.status = "rejected";
                else {
                    const edge = run.definition.edges.find(
                        (edge) =>
                            edge.source === node.id &&
                            (edge.branch || "always") === "always",
                    );
                    if (!edge) error("Connect the next sample step.");
                    run.current_node_id = edge.target;
                    walk(run);
                }
                run.version++;
                audit(run.id, "sample.workflow.advance");
                return runView(run);
            }
            error("That sample workflow operation is unavailable.", 404);
        }
        function discussionKey(kind, id) {
            if (kind === "request") {
                const row = get(state.requests, id);
                return row.task_id ? "task:" + row.task_id : "request:" + id;
            }
            return kind + ":" + id;
        }
        function discussionSource(kind, id) {
            if (kind === "request") {
                const row = get(state.requests, id);
                return { section: "requests", id, area: row.area_id };
            }
            if (kind === "task") {
                const row = get(state.tasks, id);
                return {
                    section: "projects",
                    id: row.project_id,
                    area: row.enterprise_area_id,
                };
            }
            return {
                section: "projects",
                id,
                area: project(id).enterprise_area_id,
            };
        }
        function discussion(kind, id) {
            if (state.persona === "requester")
                error(
                    "Requester views exclude internal discussions and file metadata.",
                    404,
                );
            if (kind === "request") {
                const row = get(state.requests, id);
                if (!row.task_id)
                    return {
                        task_id: null,
                        can_write: false,
                        comments: [],
                        files: [],
                        coverage:
                            "Assign the sample request before adding internal notes/files.",
                    };
            } else if (kind === "task") get(state.tasks, id);
            else project(id);
            const key = discussionKey(kind, id);
            const task = key.startsWith("task:")
                ? get(state.tasks, key.slice(5))
                : null;
            const allowed =
                canWrite() &&
                (!task || manager() || task.assignee_id === currentId());
            const thread = (state.discussions[key] ??= {
                task_id: task?.id || uuid(),
                comments: [],
                files: [],
            });
            return {
                ...clone(thread),
                files: state.uploads
                    .filter((row) => row.task_id === thread.task_id)
                    .map((row) => ({
                        ...clone(row),
                        downloadable: false,
                        can_manage: allowed && row.uploaded_by === currentId(),
                    })),
                can_write: allowed,
                coverage:
                    "Fictional browser discussion only. Uploaded sample metadata stays quarantined; no scan or server storage is claimed.",
            };
        }
        function taskView(task) {
            const checklist = (task.checklist || []).map((item) => ({
                ...item,
                id: item.id || item.key,
                weight: Number(item.weight || 1),
            }));
            const weight = checklist.reduce(
                    (sum, item) => sum + item.weight,
                    0,
                ),
                complete = checklist.reduce(
                    (sum, item) => sum + (item.completed ? item.weight : 0),
                    0,
                );
            const percent = weight ? Math.floor((complete / weight) * 100) : 0,
                performed =
                    weight > 0 && checklist.every((item) => item.completed);
            return {
                ...clone(task),
                task_id: task.id,
                area_id: task.enterprise_area_id,
                version: task.ops_version || 0,
                task_version: task.version,
                owner_user_id:
                    task.owner_user_id ||
                    task.accountable_owner_id ||
                    task.assignee_id,
                ball_user_id: task.assignee_id,
                ball_team: !task.assignee_id,
                priority: task.id === IDS.emergency ? "urgent" : "normal",
                emergency: task.id === IDS.emergency,
                checklist,
                progress_percent: percent,
                completion_score: performed
                    ? task.approved
                        ? 4
                        : 3.9
                    : Math.round(Math.min(3.8, (percent * 4) / 100) * 10) / 10,
                approval_required: performed && !task.approved,
                approval: task.approved
                    ? { by: "1", at: task.completed_at }
                    : null,
                closure: task.closure || null,
                status:
                    task.status === "active" && performed && !task.approved
                        ? "awaiting_verification"
                        : task.status,
                source_request_id:
                    task.source_request_id ||
                    state.requests.find((row) => row.task_id === task.id)?.id ||
                    null,
                owning_area_id: task.owning_area_id || task.enterprise_area_id,
                can_manage: manager(),
                can_update:
                    canWrite() &&
                    (manager() || task.assignee_id === currentId()),
                url: recordUrl(
                    "projects",
                    task.project_id,
                    task.enterprise_area_id,
                ),
                updated_at: task.updated_at || now(),
            };
        }
        function operations(query) {
            const chosen = area(query.get("area_id") || IDS.area),
                projectId = query.get("project_id");
            const items = state.tasks
                .filter(
                    (task) =>
                        task.enterprise_area_id === chosen.id &&
                        (!projectId || task.project_id === projectId),
                )
                .map(taskView);
            return {
                items: state.persona === "requester" ? [] : items,
                resumption_queue:
                    state.persona === "requester"
                        ? []
                        : items.filter((task) => task.status === "interrupted"),
                pending_handoffs: clone(
                    state.handoffs.filter(
                        (row) =>
                            state.persona !== "requester" &&
                            row.status === "pending" &&
                            (row.target_area_id === chosen.id ||
                                row.owning_area_id === chosen.id),
                    ),
                ),
                members: clone(members.filter((row) => row.id !== "3")),
                can_manage: manager(),
                coverage:
                    "Fictional sample operations only; private server authorization is not simulated as a security boundary.",
                as_of: now(),
            };
        }
        function operationCommand(body) {
            if (body.action.startsWith("handoff.")) {
                coordinate();
                if (body.confirmed !== true && body.confirmed !== 1)
                    error("Explicitly confirm the sample handoff.");
                if (body.action === "handoff.propose") {
                    const request = get(state.requests, body.source_request_id);
                    checkVersion(request, body.expected_request_version);
                    const destination = project(body.target_project_id);
                    if (destination.enterprise_area_id !== body.target_area_id)
                        error(
                            "Choose a project in the destination sample workspace.",
                        );
                    const handoff = {
                        id: body.id,
                        version: 1,
                        status: "pending",
                        source_request_id: request.id,
                        owning_area_id: request.area_id,
                        target_area_id: body.target_area_id,
                        target_project_id: destination.id,
                        title: body.title,
                        next_action: body.next_action,
                        assignee_id: String(body.assignee_id),
                        task_id: null,
                        can_accept: true,
                    };
                    state.handoffs.push(handoff);
                    request.version++;
                    audit(request.id, "sample.handoff.proposed");
                    return clone(handoff);
                }
                const handoff = get(state.handoffs, body.id);
                checkVersion(handoff, body.expected_version);
                if (handoff.status !== "pending")
                    error("This sample handoff was already resolved.", 409);
                if (!String(body.reason || "").trim())
                    error("Record the reason for the sample handoff decision.");
                handoff.status =
                    body.action === "handoff.accept" ? "accepted" : "declined";
                handoff.version++;
                if (handoff.status === "accepted") {
                    const id = uuid();
                    handoff.task_id = id;
                    state.tasks.push({
                        id,
                        title: handoff.title,
                        version: 1,
                        ops_version: 1,
                        assignee_id: handoff.assignee_id,
                        accountable_owner_id: handoff.assignee_id,
                        completed_at: null,
                        due_on: null,
                        enterprise_area_id: handoff.target_area_id,
                        project_id: handoff.target_project_id,
                        status: "active",
                        next_action: handoff.next_action,
                        blockers: [],
                        checkpoint: "",
                        checklist: [],
                        approved: false,
                        source_request_id: handoff.source_request_id,
                        owning_area_id: handoff.owning_area_id,
                        handoff_id: handoff.id,
                    });
                    project(handoff.target_project_id).tasks.push(id);
                }
                audit(handoff.source_request_id, "sample." + body.action, {
                    reason: body.reason,
                });
                return clone(handoff);
            }
            if (body.action === "contractor.review") {
                coordinate();
                const task = get(state.tasks, body.task_id),
                    contractor = get(state.plans, body.contractor_id);
                if (contractor.kind !== "contractor")
                    error("Choose a sample contractor.");
                const dimensions = [
                    "workmanship",
                    "timeliness",
                    "communication",
                    "documentation",
                ];
                if (
                    dimensions.some(
                        (name) =>
                            !Number.isInteger(body[name]) ||
                            body[name] < 0 ||
                            body[name] > 4,
                    )
                )
                    error(
                        "Score each separate quality dimension from 0 through 4.",
                    );
                const previous = state.reviews.find(
                    (review) =>
                        review.task_id === task.id &&
                        review.contractor_id === contractor.id,
                );
                if ((previous?.version || 0) !== Number(body.expected_version))
                    error(
                        "This sample evaluation changed. Review the current version.",
                        409,
                    );
                const review = {
                    id: previous?.id || uuid(),
                    version: (previous?.version || 0) + 1,
                    task_id: task.id,
                    contractor_id: contractor.id,
                    ...Object.fromEntries(
                        dimensions.map((name) => [name, body[name]]),
                    ),
                    note: body.note || "",
                    reviewed_at: now(),
                    reviewed_by: currentId(),
                };
                if (previous) Object.assign(previous, review);
                else state.reviews.push(review);
                audit(task.id, "sample.contractor.review");
                return quality(contractor.id);
            }
            const task = get(state.tasks, body.task_id);
            if (!taskView(task).can_update)
                error(
                    "Use the current sample assignee or manager perspective.",
                    403,
                );
            if (
                (task.ops_version || 0) !== Number(body.expected_version) ||
                task.version !== Number(body.expected_task_version)
            )
                error(
                    "The sample task changed. Reload before applying an operation.",
                    409,
                );
            const action = body.action;
            if (["closed", "completed"].includes(task.status))
                error("This sample task is closed; history is retained.", 409);
            if (action === "task.configure") {
                coordinate();
                if (
                    !Array.isArray(body.checklist) ||
                    !body.checklist.length ||
                    body.checklist.length > 50
                )
                    error("Provide a bounded sample checklist.");
                task.checklist = body.checklist.map((item) => ({
                    ...clone(item),
                    weight: Number(item.weight || 1),
                    completed: false,
                }));
                task.owner_user_id = String(body.owner_user_id);
                task.assignee_id = body.ball_team
                    ? null
                    : String(body.ball_user_id);
                task.next_action = body.next_action;
                task.due_on = body.due_on ?? null;
                task.blockers = clone(body.blockers || []);
                task.approved = false;
            } else if (action === "task.check") {
                if (task.status === "interrupted")
                    error(
                        "Explicitly resume the interrupted sample task before changing progress.",
                        409,
                    );
                const item = (task.checklist || []).find(
                    (item) => (item.id || item.key) === body.item_id,
                );
                if (!item) error("Choose a sample checklist item.");
                item.completed = Boolean(body.completed);
                task.approved = false;
            } else if (action === "task.interrupt") {
                if (
                    !String(body.checkpoint || "").trim() ||
                    !String(body.reason || "").trim()
                )
                    error(
                        "Record the sample checkpoint and interruption reason.",
                    );
                if (body.emergency_task_id === task.id)
                    error("An emergency must be a separate sample task.");
                if (body.emergency_task_id)
                    get(state.tasks, body.emergency_task_id);
                task.checkpoint = {
                    text: body.checkpoint,
                    reason: body.reason,
                    at: now(),
                    emergency_task_id: body.emergency_task_id || null,
                };
                task.status = "interrupted";
            } else if (action === "task.resume") {
                if (!body.reason || task.status !== "interrupted")
                    error("Resume only interrupted sample work with a reason.");
                task.status = "active";
            } else if (action === "task.reassign") {
                coordinate();
                if (
                    !body.reason ||
                    !members.some(
                        (member) =>
                            member.id === String(body.ball_user_id) &&
                            member.id !== "3",
                    )
                )
                    error("Choose a sample editor and record the reason.");
                task.assignee_id = String(body.ball_user_id);
                task.status = "active";
            } else if (action === "task.reschedule") {
                coordinate();
                if (!body.reason || !/^\d{4}-\d{2}-\d{2}$/.test(body.due_on))
                    error("Choose a sample date and record a reason.");
                task.due_on = body.due_on;
                task.status = "active";
            } else if (action === "task.close") {
                coordinate();
                if (!body.reason)
                    error("Record why the sample work is closed.");
                task.status = "closed";
                task.closure = {
                    reason: body.reason,
                    at: now(),
                    by: currentId(),
                };
            } else if (action === "task.approve") {
                coordinate();
                if (
                    !task.checklist.length ||
                    !task.checklist.every((item) => item.completed)
                )
                    error(
                        "Complete all required sample checklist work before final approval.",
                    );
                task.approved = true;
                task.status = "completed";
                task.completed_at = now();
                for (const request of state.requests.filter(
                    (row) => row.task_id === task.id,
                )) {
                    request.status = "completed";
                    request.version++;
                    audit(request.id, "sample.completion.approved");
                }
            } else error("That sample operations action is unavailable.");
            task.version++;
            task.ops_version = (task.ops_version || 0) + 1;
            task.updated_at = now();
            audit(task.id, "sample." + action);
            return taskView(task);
        }
        function quality(id) {
            coordinate();
            get(state.plans, id);
            const reviews = state.reviews.filter(
                    (row) => row.contractor_id === id,
                ),
                dimensions = [
                    "workmanship",
                    "timeliness",
                    "communication",
                    "documentation",
                ];
            const score = reviews.length
                ? reviews.reduce(
                      (sum, review) =>
                          sum +
                          dimensions.reduce(
                              (total, name) => total + review[name],
                              0,
                          ) /
                              4,
                      0,
                  ) / reviews.length
                : null;
            return {
                contractor_id: id,
                quality_score: score,
                quality_label:
                    score === null ? "Unrated" : score.toFixed(2) + " / 4",
                review_count: reviews.length,
                reviews: clone(reviews),
                coverage:
                    "Separate manager-reviewed fictional quality, not the checklist completion grade.",
            };
        }
        function resourceView(row) {
            return {
                ...clone(row),
                data: {
                    ...clone(row.data),
                    ...(row.kind === "asset"
                        ? {
                              holder_name:
                                  members.find(
                                      (member) =>
                                          member.id ===
                                          String(row.data.holder_id),
                                  )?.name || "Not recorded",
                          }
                        : {}),
                },
                can_coordinate: manager(),
                can_checkout:
                    canWrite() &&
                    row.kind === "asset" &&
                    row.status === "available",
                can_return:
                    canWrite() &&
                    row.kind === "asset" &&
                    row.status === "checked_out" &&
                    (manager() || String(row.data.holder_id) === currentId()),
                updated_at: row.updated_at || now(),
                url: recordUrl(
                    row.kind === "location"
                        ? "buildings"
                        : row.kind === "asset"
                          ? "assets"
                          : "calendar",
                    row.id,
                    row.area_id,
                ),
                qr_url: recordUrl(
                    row.kind === "location" ? "buildings" : "assets",
                    row.id,
                    row.area_id,
                ),
            };
        }
        function resourceCommand(body) {
            const action = body.action;
            if (action === "fixture.seed") {
                coordinate();
                const source = boot.locations || { entries: [] },
                    entries = Array.isArray(source)
                        ? source
                        : source.entries || [];
                for (const entry of entries) {
                    const id =
                        "60000000-0000-4000-8000-" +
                        String(entry.map_number).padStart(12, "0");
                    if (!state.resources.some((row) => row.id === id))
                        state.resources.push({
                            id,
                            kind: "location",
                            area_id: body.area_id,
                            title: entry.name,
                            version: 1,
                            status: "active",
                            data: {
                                ...clone(entry),
                                level: "building",
                                placement_verified: false,
                                latitude: null,
                                longitude: null,
                                fixture: true,
                            },
                        });
                }
                return {
                    seeded: true,
                    count: entries.length,
                    sample_data: true,
                };
            }
            let row = state.resources.find((row) => row.id === body.id);
            if (action.endsWith(".save")) {
                coordinate();
                const kind = action.split(".")[0];
                if (row) checkVersion(row, body.expected_version);
                else {
                    if (Number(body.expected_version) !== 0)
                        error("The sample resource changed.", 409);
                    row = {
                        id: body.id,
                        kind,
                        area_id: body.area_id,
                        version: 0,
                        data: {},
                    };
                    state.resources.push(row);
                }
                row.title = String(body.title).slice(0, 150);
                row.status =
                    kind === "asset"
                        ? "available"
                        : kind === "reservation"
                          ? "scheduled"
                          : "active";
                row.data = {
                    ...row.data,
                    ...Object.fromEntries(
                        Object.entries(body).filter(([name]) =>
                            [
                                "level",
                                "parent_id",
                                "catalog_map_number",
                                "description",
                                "location_id",
                                "condition",
                                "resource_id",
                                "starts_at",
                                "ends_at",
                                "purpose",
                                "category",
                            ].includes(name),
                        ),
                    ),
                };
                if (kind === "reservation") {
                    get(state.resources, body.resource_id);
                    if (
                        !Number.isFinite(Date.parse(body.starts_at)) ||
                        !Number.isFinite(Date.parse(body.ends_at)) ||
                        body.ends_at <= body.starts_at
                    )
                        error(
                            "Choose a valid sample UTC reservation interval.",
                        );
                    const overlaps = state.resources.filter(
                        (other) =>
                            other.id !== row.id &&
                            other.kind === "reservation" &&
                            ["scheduled", "conflicted"].includes(
                                other.status,
                            ) &&
                            other.data.resource_id === body.resource_id &&
                            other.data.starts_at < body.ends_at &&
                            other.data.ends_at > body.starts_at,
                    );
                    for (const other of overlaps) {
                        row.status = other.status = "conflicted";
                        state.resources.push({
                            id: uuid(),
                            kind: "conflict",
                            area_id: body.area_id,
                            title: "Sample exclusive reservation overlap",
                            version: 1,
                            status: "open",
                            data: {
                                reason: "Overlapping exclusive room/asset reservations require manager resolution.",
                                reservation_ids: [row.id, other.id],
                                starts_at: body.starts_at,
                                resource_id: body.resource_id,
                            },
                        });
                    }
                }
            } else {
                if (!row) error("The sample resource is unavailable.", 404);
                checkVersion(row, body.expected_version);
                if (action === "asset.checkout") {
                    write();
                    if (row.status !== "available")
                        error("This sample asset is already checked out.", 409);
                    get(state.resources, body.location_id);
                    if (
                        !members.some(
                            (member) => member.id === String(body.holder_id),
                        )
                    )
                        error("Choose a sample holder.");
                    row.status = "checked_out";
                    Object.assign(row.data, {
                        holder_id: String(body.holder_id),
                        location_id: body.location_id,
                        checked_out_at: now(),
                        expected_return_at: body.expected_return_at,
                        condition: body.condition || "",
                    });
                } else if (action === "asset.return") {
                    write();
                    if (!resourceView(row).can_return)
                        error(
                            "Only the current sample holder or manager can return this checked-out asset.",
                            403,
                        );
                    row.status = "available";
                    Object.assign(row.data, {
                        holder_id: null,
                        location_id: body.location_id,
                        condition: body.condition || "",
                        returned_at: now(),
                    });
                } else if (action === "reservation.cancel") {
                    coordinate();
                    if (!body.note)
                        error(
                            "Record a reason to cancel the sample reservation.",
                        );
                    row.status = "cancelled";
                    row.data.note = body.note;
                } else if (action === "conflict.resolve") {
                    coordinate();
                    if (!body.note)
                        error("Record the sample conflict resolution.");
                    row.status = "resolved";
                    row.data.resolution = body.note;
                } else if (action === "fixture.withdraw") {
                    coordinate();
                    row.status = "withdrawn";
                } else error("That sample resource action is unavailable.");
            }
            row.version++;
            row.updated_at = now();
            audit(row.id, "sample." + action, {
                holder_id: row.data.holder_id || null,
                location_id: row.data.location_id || null,
            });
            return resourceView(row);
        }
        function rollCall(query) {
            const type = query.get("type") || query.get("scope") || "workspace",
                selected = query.get("area_id") || IDS.area,
                projectId = query.get("project_id"),
                personId = query.get("team_user_id");
            const tasks =
                state.persona === "requester"
                    ? []
                    : state.tasks.filter(
                          (task) =>
                              (type === "campus" ||
                                  task.enterprise_area_id === selected) &&
                              (type !== "project" ||
                                  task.project_id === projectId) &&
                              (type !== "team" ||
                                  task.assignee_id === personId),
                      );
            const items = tasks
                .filter(
                    (task) => !["closed", "completed"].includes(task.status),
                )
                .map((task) => ({
                    ref: "task:" + task.id,
                    kind:
                        task.status === "interrupted"
                            ? "interrupted_work"
                            : task.id === IDS.emergency
                              ? "emergency"
                              : "active_work",
                    title: task.title,
                    reason:
                        task.status === "interrupted"
                            ? "Interrupted sample work retains its checkpoint and needs explicit resumption."
                            : task.id === IDS.emergency
                              ? "Urgent sample work needs immediate coordination."
                              : "Recorded sample work has a next action.",
                    owner: {
                        id: taskView(task).owner_user_id,
                        name:
                            members.find(
                                (member) =>
                                    member.id === taskView(task).owner_user_id,
                            )?.name || "Not recorded",
                    },
                    ball: {
                        id: task.assignee_id,
                        name:
                            members.find(
                                (member) => member.id === task.assignee_id,
                            )?.name || "Unassigned",
                    },
                    next_action: task.next_action,
                    due_on: task.due_on,
                    source_url: recordUrl(
                        "projects",
                        task.project_id,
                        task.enterprise_area_id,
                    ),
                    source_updated_at: task.updated_at || now(),
                    rank:
                        task.status === "interrupted"
                            ? 20
                            : task.id === IDS.emergency
                              ? 10
                              : 90,
                    version: task.version,
                }));
            for (const row of state.handoffs.filter(
                (row) =>
                    state.persona !== "requester" &&
                    row.status === "pending" &&
                    (type === "campus" || row.target_area_id === selected),
            ))
                items.push({
                    ref: "handoff:" + row.id,
                    kind: "pending_handoff",
                    title: row.title,
                    reason: "Explicit destination acceptance is pending.",
                    owner: null,
                    ball: null,
                    next_action: row.next_action,
                    due_on: null,
                    source_url: recordUrl(
                        "projects",
                        row.target_project_id,
                        row.target_area_id,
                    ),
                    source_updated_at: now(),
                    rank: 40,
                    version: row.version,
                });
            for (const row of state.resources.filter(
                (row) =>
                    state.persona !== "requester" &&
                    row.kind === "conflict" &&
                    row.status === "open" &&
                    (type === "campus" || row.area_id === selected),
            ))
                items.push({
                    ref: "resource:" + row.id,
                    kind: "schedule_conflict",
                    title: row.title,
                    reason: row.data.reason,
                    owner: null,
                    ball: null,
                    next_action:
                        "Review the recorded overlap; do not silently reschedule.",
                    due_on: row.data.starts_at || null,
                    source_url: resourceView(row).url,
                    source_updated_at: row.updated_at || now(),
                    rank: 65,
                    version: row.version,
                });
            items.sort(
                (left, right) =>
                    left.rank - right.rank ||
                    left.title.localeCompare(right.title),
            );
            return {
                scope: {
                    type,
                    area_id: type === "campus" ? null : selected,
                    project_id: type === "project" ? projectId : null,
                    team_user_id: type === "team" ? personId : null,
                    label:
                        type === "campus"
                            ? "Campus · fictional sample"
                            : area(selected).name,
                },
                as_of: now(),
                method: "sample_record_rules",
                method_label:
                    "Current fictional browser-record ordering; no AI or external feed was called.",
                sample_data: true,
                label: "SAMPLE DATA · illustrative campus operations",
                items,
                total_visible: items.length,
                overflow_count: 0,
                can_lead: manager(),
                complete_url: recordUrl("roll_call", "", selected),
                coverage:
                    "Fictional sample records only. No college schedules, private evaluations, internal notes or real student records.",
            };
        }
        async function meetingRequest(parts, query, body) {
            coordinate();
            if (!parts.length && !body)
                return {
                    items: clone(state.meetings),
                    coverage: "Meetings led in this browser sample only.",
                };
            if (!parts.length && body) {
                area(body.area_id);
                let meeting = state.meetings.find((row) => row.id === body.id);
                if (meeting) checkVersion(meeting, body.expected_version);
                else {
                    if (Number(body.expected_version) !== 0)
                        error("This sample meeting changed.", 409);
                    meeting = { id: body.id, version: 0, applied: {} };
                    state.meetings.push(meeting);
                }
                if (
                    ["decisions", "blockers", "commitments"].some(
                        (name) =>
                            !Array.isArray(body[name]) ||
                            body[name].length > 20,
                    )
                )
                    error(
                        "Keep the sample meeting within its bounded note limits.",
                    );
                for (const commitment of body.commitments) {
                    project(commitment.project_id);
                    if (
                        !members.some(
                            (member) =>
                                member.id === String(commitment.owner_id) &&
                                member.id !== "3",
                        )
                    )
                        error("Choose a sample editor for the commitment.");
                }
                for (const id of Object.keys(meeting.applied)) {
                    const old = meeting.commitments.find(
                            (row) => row.id === id,
                        ),
                        submitted = body.commitments.find(
                            (row) => row.id === id,
                        );
                    if (JSON.stringify(old) !== JSON.stringify(submitted))
                        error(
                            "A confirmed sample commitment is immutable. Add another commitment for a further change.",
                            409,
                        );
                }
                Object.assign(meeting, {
                    area_id: body.area_id,
                    title: body.title,
                    scope: clone(body.scope),
                    decisions: clone(body.decisions),
                    blockers: clone(body.blockers),
                    commitments: clone(body.commitments),
                    created_by: currentId(),
                    version: meeting.version + 1,
                    updated_at: now(),
                    privacy:
                        "Sample meeting leader view; no server or provider action.",
                });
                audit(meeting.id, "sample.meeting.save");
                return clone(meeting);
            }
            const meeting = get(state.meetings, parts[0]);
            if (parts.length === 1) return clone(meeting);
            const actions = meeting.commitments
                .filter((commitment) => !meeting.applied[commitment.id])
                .map((commitment) => {
                    const task = commitment.task_id
                        ? get(state.tasks, commitment.task_id)
                        : null;
                    if (task)
                        checkVersion(task, commitment.expected_task_version);
                    return {
                        commitment_id: commitment.id,
                        owner_id: String(commitment.owner_id),
                        owner_name: members.find(
                            (member) =>
                                member.id === String(commitment.owner_id),
                        )?.name,
                        project_id: commitment.project_id,
                        project_title: project(commitment.project_id).name,
                        command: task
                            ? {
                                  kind: "task.update",
                                  id: task.id,
                                  expected_version: task.version,
                                  changes: {
                                      title: commitment.text,
                                      due_on: commitment.due_on,
                                  },
                              }
                            : {
                                  kind: "task.create",
                                  id: commitment.id,
                                  title: commitment.text,
                                  due_on: commitment.due_on,
                                  assignee_id: String(commitment.owner_id),
                              },
                    };
                });
            const value = await digest([
                meeting.id,
                meeting.version,
                currentId(),
                actions,
            ]);
            if (parts[1] === "preview") {
                if (!actions.length)
                    error("No unconfirmed sample commitments remain.");
                meeting.preview = {
                    preview_id: uuid(),
                    meeting_id: meeting.id,
                    version: meeting.version,
                    digest: value,
                    actions,
                    expires_at: new Date(Date.now() + 900000).toISOString(),
                    effect: "Preview only. No sample task changed; confirm this exact preview to apply.",
                };
                return clone(meeting.preview);
            }
            if (parts[1] === "confirm") {
                checkVersion(meeting, body.expected_version);
                const preview = meeting.preview;
                if (
                    !preview ||
                    preview.preview_id !== body.preview_id ||
                    preview.digest !== value ||
                    body.digest !== value ||
                    body.confirmed !== true ||
                    Date.parse(preview.expires_at) < Date.now()
                )
                    error(
                        "Review and explicitly confirm the current exact sample commitments.",
                        409,
                    );
                for (const action of actions) {
                    const command = action.command;
                    if (command.kind === "task.update") {
                        const task = get(state.tasks, command.id);
                        Object.assign(task, command.changes);
                        task.version++;
                    } else {
                        state.tasks.push({
                            id: command.id,
                            title: command.title,
                            version: 1,
                            assignee_id: command.assignee_id,
                            accountable_owner_id: command.assignee_id,
                            completed_at: null,
                            due_on: command.due_on,
                            enterprise_area_id: meeting.area_id,
                            project_id: action.project_id,
                            status: "active",
                            next_action:
                                "Complete the reviewed sample meeting commitment",
                            blockers: [],
                            checklist: [],
                            approved: false,
                        });
                        project(action.project_id).tasks.push(command.id);
                    }
                    meeting.applied[action.commitment_id] = {
                        task_id: command.id,
                        at: now(),
                        by: currentId(),
                    };
                }
                meeting.version++;
                delete meeting.preview;
                audit(meeting.id, "sample.meeting.confirm");
                return clone(meeting);
            }
            error("That sample meeting operation is unavailable.", 404);
        }
        async function handle(path, query, body, options) {
            if (path === "/enterprise/data") return data(query);
            if (path === "/enterprise/commands") return command(body);
            if (path.startsWith("/enterprise/workflows"))
                return workflowRequest(path, query, body);
            if (path === "/enterprise/operations") return operations(query);
            if (path === "/enterprise/operations/commands")
                return operationCommand(body);
            const operationalTask = path.match(
                /^\/enterprise\/operations\/tasks\/([^/]+)$/,
            );
            if (operationalTask) {
                if (state.persona === "requester")
                    error(
                        "Requester perspective excludes internal operational tasks.",
                        404,
                    );
                return taskView(get(state.tasks, operationalTask[1]));
            }
            const contractorQuality = path.match(
                /^\/enterprise\/operations\/contractors\/([^/]+)\/quality$/,
            );
            if (contractorQuality) return quality(contractorQuality[1]);
            if (path === "/enterprise/roll-call") return rollCall(query);
            if (
                path === "/enterprise/meetings" ||
                path.startsWith("/enterprise/meetings/")
            )
                return meetingRequest(
                    path
                        .slice("/enterprise/meetings".length)
                        .split("/")
                        .filter(Boolean),
                    query,
                    body,
                );
            if (
                path === "/enterprise/briefing" ||
                path === "/enterprise/briefing/refresh"
            )
                return briefing(body?.area_id || query.get("area_id"));
            if (path === "/enterprise/integrations")
                return {
                    ai: {
                        ready: false,
                        status: "Sample record-derived answers; no live AI or charge.",
                    },
                    mailbox: {
                        configured: false,
                        status: "Tour preview only. No reviewer mailbox or live send is connected.",
                    },
                    scanner: {
                        configured: false,
                        status: "Sample uploads are held. Real scanner acceptance is separate.",
                    },
                    as_of: now(),
                };
            if (path === "/enterprise/gmail")
                return {
                    configured: false,
                    can_connect: false,
                    connected: false,
                    connection_id: null,
                    address: null,
                    send_enabled: false,
                    scopes: [],
                    status: "The open demonstration never connects a reviewer mailbox.",
                };
            if (path === "/enterprise/mailboxes")
                return {
                    mailboxes: [],
                    delivery_ready: false,
                    status: "Preview only. No messages are read or sent in the sample.",
                };
            if (
                path.startsWith("/enterprise/gmail/") ||
                path.startsWith("/enterprise/email-drafts")
            )
                error(
                    "The open demonstration cannot connect mailboxes or send real messages.",
                    403,
                );
            if (path === "/enterprise/ask") {
                const result = briefing(body.area_id);
                return {
                    reply: "Here are the fictional sample records in this workspace. No AI provider was called.",
                    sources: result.sources,
                    as_of: result.as_of,
                    model_status:
                        "SAMPLE record-derived response, not a generated model answer.",
                    coverage: result.coverage,
                };
            }
            const history = path.match(
                /^\/enterprise\/requests\/([^/]+)\/history$/,
            );
            if (history) {
                get(state.requests, history[1]);
                return {
                    items: clone(state.histories[history[1]] || []),
                    coverage: "This tab’s sample request history only.",
                };
            }
            const thread = path.match(
                /^\/enterprise\/discussion\/(project|request|task)\/([^/]+)(?:\/(open|comments))?$/,
            );
            if (thread) {
                const result = discussion(thread[1], thread[2]);
                if (!thread[3]) return result;
                write();
                if (!result.can_write)
                    error("Assign the sample request before posting.", 409);
                if (thread[3] === "open") return { task_id: result.task_id };
                const row =
                    state.discussions[discussionKey(thread[1], thread[2])];
                if (
                    !String(body.body || "").trim() ||
                    String(body.body).length > 5000
                )
                    error("Write a bounded sample note.");
                row.comments.push({
                    id: body.request_id,
                    author_id: currentId(),
                    body: body.body,
                    created_at: now(),
                });
                return { saved: true };
            }
            const caption = path.match(
                /^\/enterprise\/files\/([^/]+)\/caption$/,
            );
            if (caption) {
                write();
                const row = get(state.uploads, caption[1]);
                if (row.uploaded_by !== currentId())
                    error(
                        "Only this sample file uploader can edit its caption.",
                        403,
                    );
                if (!["quarantined", "ready"].includes(row.status))
                    error("Finish the sample upload before captioning.", 409);
                if (body.expected_version !== (row.caption_version || 0))
                    error(
                        "The sample caption changed; review its current version.",
                        409,
                    );
                if (
                    typeof body.caption !== "string" ||
                    body.caption.length > 300
                )
                    error("Use a caption of up to 300 characters.");
                row.caption = body.caption;
                row.caption_version = (row.caption_version || 0) + 1;
                audit(row.id, "sample.file.caption");
                return {
                    id: row.id,
                    caption: row.caption,
                    version: row.caption_version,
                };
            }
            const beginUpload = path.match(/^\/team\/tasks\/([^/]+)\/files$/);
            if (beginUpload) {
                write();
                const task = state.tasks.find(
                    (row) => row.id === beginUpload[1],
                );
                if (task && !manager() && task.assignee_id !== currentId())
                    error(
                        "This sample task is not assigned to the current worker.",
                        403,
                    );
                if (
                    !Object.values(state.discussions).some(
                        (row) => row.task_id === beginUpload[1],
                    )
                )
                    error("This sample discussion is unavailable.", 404);
                const previous = state.uploads.find(
                    (row) =>
                        row.task_id === beginUpload[1] &&
                        row.client_key === body.client_key,
                );
                if (previous) {
                    if (
                        previous.sha256 !== body.sha256 ||
                        previous.filename !== body.filename ||
                        previous.size !== body.size ||
                        previous.mime_type !== body.mime_type
                    )
                        error(
                            "The retained sample upload key describes different bytes.",
                            409,
                        );
                    return clone(previous);
                }
                if (
                    ![
                        "image/jpeg",
                        "image/png",
                        "application/pdf",
                        "text/plain",
                    ].includes(body.mime_type) ||
                    !Number.isInteger(body.size) ||
                    body.size < 1 ||
                    body.size > 10485760 ||
                    !/^[a-f0-9]{64}$/.test(body.sha256)
                )
                    error(
                        "Choose a supported photo, PDF or text file up to 10 MB.",
                    );
                const row = {
                    ...clone(body),
                    id: uuid(),
                    uploaded_by: currentId(),
                    caption: "",
                    caption_version: 0,
                    task_id: beginUpload[1],
                    status: "uploading",
                    chunk_bytes: 262144,
                    received_chunks: [],
                    chunk_sizes: {},
                    downloadable: false,
                    sample_data: true,
                };
                state.uploads.push(row);
                return clone(row);
            }
            const upload = path.match(
                /^\/team\/file-uploads\/([^/]+)\/(chunks\/(\d+)|complete|cancel)$/,
            );
            if (upload) {
                write();
                const row = get(state.uploads, upload[1]);
                if (row.uploaded_by !== currentId())
                    error(
                        "Only this sample uploader can change its upload metadata.",
                        403,
                    );
                if (upload[2] === "cancel") {
                    row.status = "cancelled";
                    return clone(row);
                }
                if (upload[2] === "complete") {
                    if (row.status === "quarantined") return clone(row);
                    if (
                        row.status !== "uploading" ||
                        Object.values(row.chunk_sizes).reduce(
                            (sum, size) => sum + size,
                            0,
                        ) !== row.size
                    )
                        error(
                            "All exact sample chunks must be present before completion.",
                            409,
                        );
                    row.status = "quarantined";
                    return clone(row);
                }
                if (
                    row.status !== "uploading" ||
                    options.method !== "PUT" ||
                    !options.binary
                )
                    error("This sample upload is not accepting chunks.", 409);
                const index = Number(upload[3]),
                    bytes =
                        body instanceof Blob ? await body.arrayBuffer() : body;
                const expected = Math.min(
                    row.chunk_bytes,
                    row.size - index * row.chunk_bytes,
                );
                if (
                    !Number.isInteger(index) ||
                    index < 0 ||
                    expected <= 0 ||
                    bytes.byteLength !== expected
                )
                    error("The sample chunk size or position does not match.");
                const chunkHash = await digest([...new Uint8Array(bytes)]);
                row.chunk_hashes ??= {};
                if (
                    row.chunk_hashes[index] &&
                    row.chunk_hashes[index] !== chunkHash
                )
                    error(
                        "A repeated sample chunk contained different bytes.",
                        409,
                    );
                row.chunk_hashes[index] = chunkHash;
                row.chunk_sizes[index] = bytes.byteLength;
                if (!row.received_chunks.includes(index))
                    row.received_chunks.push(index);
                return clone(row);
            }
            if (path === "/enterprise/planning") {
                const selected =
                    body?.area_id || query.get("area_id") || IDS.area;
                area(selected);
                if (!body)
                    return {
                        items: clone(
                            state.plans.filter(
                                (row) =>
                                    row.area_id === selected &&
                                    (row.kind !== "cost" || manager()),
                            ),
                        ),
                        can_coordinate: manager(),
                        can_finance: manager(),
                        basis: "Fictional sample planning, not actual purchasing or schedules.",
                        partial: false,
                    };
                coordinate();
                let row = state.plans.find((row) => row.id === body.id);
                if (row) checkVersion(row, body.expected_version);
                else {
                    if (Number(body.expected_version) !== 0)
                        error("Sample planning version changed.", 409);
                    row = { id: body.id, area_id: selected, version: 0 };
                    state.plans.push(row);
                }
                if (!["contractor", "schedule", "cost"].includes(body.kind))
                    error("Choose a supported sample plan.");
                if (
                    body.kind === "schedule" &&
                    state.plans.some(
                        (item) =>
                            item.id !== body.id &&
                            item.kind === "schedule" &&
                            item.status === "planned" &&
                            item.data.contractor_id === body.contractor_id &&
                            item.starts_on <= body.ends_on &&
                            item.ends_on >= body.starts_on,
                    )
                )
                    error(
                        "A sample contractor date block overlaps. Resolve it explicitly.",
                        409,
                    );
                Object.assign(row, {
                    kind: body.kind,
                    title: body.title,
                    status: body.status,
                    starts_on: body.starts_on ?? null,
                    ends_on: body.ends_on ?? null,
                    version: row.version + 1,
                    data: Object.fromEntries(
                        Object.entries(body).filter(([name]) =>
                            [
                                "project_id",
                                "contractor_id",
                                "amount_minor",
                                "currency",
                                "basis",
                            ].includes(name),
                        ),
                    ),
                    updated_at: now(),
                });
                return clone(row);
            }
            if (path === "/enterprise/resources/catalog") {
                const source = boot.locations || { entries: [] },
                    entries = Array.isArray(source)
                        ? source
                        : source.entries || [];
                const q = (query.get("q") || "").toLowerCase(),
                    matching = entries.filter((row) =>
                        JSON.stringify(row).toLowerCase().includes(q),
                    );
                return {
                    ...(Array.isArray(source) ? {} : clone(source)),
                    entries: clone(matching),
                    count: matching.length,
                };
            }
            if (path === "/enterprise/resources/commands")
                return resourceCommand(body);
            if (path === "/enterprise/resources") {
                const items =
                    state.persona === "requester"
                        ? []
                        : state.resources.filter(
                              (row) =>
                                  row.area_id ===
                                      (query.get("area_id") || IDS.area) &&
                                  JSON.stringify(row)
                                      .toLowerCase()
                                      .includes(
                                          (query.get("q") || "").toLowerCase(),
                                      ),
                          );
                return {
                    items: items.map(resourceView),
                    counts: {
                        location: items.filter((row) => row.kind === "location")
                            .length,
                        asset: items.filter((row) => row.kind === "asset")
                            .length,
                        reservation: items.filter(
                            (row) => row.kind === "reservation",
                        ).length,
                        conflict: items.filter((row) => row.kind === "conflict")
                            .length,
                        checked_out: items.filter(
                            (row) => row.status === "checked_out",
                        ).length,
                        open_conflicts: items.filter(
                            (row) =>
                                row.kind === "conflict" &&
                                row.status === "open",
                        ).length,
                    },
                    can_coordinate: manager(),
                    partial: false,
                    coverage:
                        "Fictional browser resources only. Location names are source-backed; sample inventory and custody are illustrative.",
                };
            }
            const resource = path.match(
                /^\/enterprise\/resources\/([^/]+)(?:\/(history))?$/,
            );
            if (resource) {
                if (state.persona === "requester")
                    error(
                        "Sample requester access excludes internal resource records.",
                        404,
                    );
                const row = get(state.resources, resource[1]);
                return resource[2]
                    ? {
                          items: clone(state.histories[row.id] || []),
                          coverage: "Sample custody/resource history only.",
                      }
                    : resourceView(row);
            }
            if (
                path === "/enterprise/requester" ||
                path.startsWith("/enterprise/requester/")
            ) {
                const parts = path
                    .slice("/enterprise/requester".length)
                    .split("/")
                    .filter(Boolean);
                const projection = (row) => ({
                    id: row.id,
                    area_id: row.area_id,
                    title: row.title,
                    status: row.status,
                    category: row.data.requester_category || "Maintenance",
                    description: row.data.description,
                    version: row.version,
                    created_at: row.created_at || now(),
                    updated_at: row.updated_at || now(),
                    updates: clone(row.public_updates || []),
                    coverage:
                        "Your fictional sample fields and explicitly published updates only.",
                });
                if (!parts.length && !body)
                    return {
                        departments: state.areas
                            .filter(
                                (row) =>
                                    state.preferences?.[row.id + ":shared"]
                                        ?.intake_published !== false,
                            )
                            .map((row) => ({
                                id: row.id,
                                name: row.name,
                            })),
                        requests: state.requests
                            .filter(
                                (row) => row.data.created_by === currentId(),
                            )
                            .map(projection),
                        as_of: now(),
                        partial: false,
                        coverage:
                            "Your fictional sample requests only; internal notes and other requester details are excluded.",
                        notifications:
                            "In-app sample updates only. No email or SMS sent.",
                    };
                if (!parts.length && body) {
                    if (
                        state.preferences?.[body.area_id + ":shared"]
                            ?.intake_published === false
                    )
                        error(
                            "This sample department is not accepting new requester submissions.",
                            404,
                        );
                    const row = command({
                        ...body,
                        action: "request.create",
                        expected_version: 0,
                    });
                    const original = get(state.requests, row.id);
                    original.data.requester_category = body.category;
                    return projection(original);
                }
                const row = get(state.requests, parts[0]);
                if (parts[1] === "updates") {
                    coordinate();
                    checkVersion(row, body.expected_version);
                    if (
                        body.confirmed_public !== true ||
                        !String(body.message || "").trim()
                    )
                        error("Explicitly confirm the public sample update.");
                    const update = {
                        id: uuid(),
                        message: String(body.message).slice(0, 2000),
                        at: now(),
                    };
                    (row.public_updates ??= []).push(update);
                    audit(row.id, "sample.requester.update");
                    return { id: update.id };
                }
                if (row.data.created_by !== currentId())
                    error("This sample requester view is not yours.", 404);
                return projection(row);
            }
            if (path === "/enterprise/preferences") {
                const chosen = area(
                    body?.area_id || query.get("area_id") || IDS.area,
                ).id;
                state.preferences ??= {};
                const sharedKey = chosen + ":shared",
                    personalKey = chosen + ":" + currentId();
                if (body) {
                    if (body.scope === "workspace") coordinate();
                    const key =
                            body.scope === "workspace"
                                ? sharedKey
                                : personalKey,
                        previous = state.preferences[key];
                    if (
                        (previous?.version || 0) !==
                        Number(body.expected_version)
                    )
                        error("The sample preferences changed.", 409);
                    state.preferences[key] = {
                        settings: clone(body.settings),
                        roles: clone(body.roles || {}),
                        intake_published:
                            body.intake_published ??
                            previous?.intake_published ??
                            true,
                        version: (previous?.version || 0) + 1,
                    };
                }
                const shared = state.preferences[sharedKey],
                    personal = state.preferences[personalKey];
                return {
                    area_id: chosen,
                    effective: {
                        density: "comfortable",
                        view: "list",
                        favorites: [],
                        ...shared?.settings,
                        ...shared?.roles?.[manager() ? "manager" : "employee"],
                        ...personal?.settings,
                    },
                    personal_version: personal?.version || 0,
                    shared_version: shared?.version || 0,
                    layout_defaults: clone(shared?.settings || {}),
                    role_defaults: clone(shared?.roles || {}),
                    role: manager() ? "manager" : "employee",
                    intake_published: shared?.intake_published ?? true,
                    can_manage: manager(),
                    licensing: {
                        unit: "department_workspace",
                        employees: "all_included",
                        per_seat_charge: false,
                        additional_units_for: [],
                        pricing_committed: false,
                        billing_changed: false,
                        description:
                            "Department workspace licensing includes employees. No subscriptions or billing changed by this sample.",
                    },
                };
            }
            if (path === "/enterprise/search") {
                const q = (query.get("q") || "").toLowerCase(),
                    chosen = query.get("area_id"),
                    items = [];
                const add = (kind, id, title, content, areaId, extra = {}) => {
                    if (
                        (!chosen || !areaId || chosen === areaId) &&
                        (title + " " + content).toLowerCase().includes(q) &&
                        items.length < 100
                    )
                        items.push({
                            kind,
                            id,
                            title,
                            summary: content,
                            excerpt: String(content).slice(0, 180),
                            area_id: areaId,
                            url: recordUrl(
                                kind === "building"
                                    ? "buildings"
                                    : kind === "request"
                                      ? "requests"
                                      : kind === "asset"
                                        ? "assets"
                                        : "projects",
                                id,
                                areaId || IDS.area,
                            ),
                            source_url: recordUrl(
                                kind === "building"
                                    ? "buildings"
                                    : kind === "request"
                                      ? "requests"
                                      : kind === "asset"
                                        ? "assets"
                                        : ["person", "contractor"].includes(
                                                kind,
                                            )
                                          ? "people"
                                          : "projects",
                                extra.project_id || id,
                                areaId || IDS.area,
                            ),
                            ...extra,
                        });
                };
                for (const row of state.requests.filter(
                    (row) =>
                        state.persona !== "requester" ||
                        row.data.created_by === currentId(),
                ))
                    add(
                        "request",
                        row.id,
                        row.title,
                        row.data.description,
                        row.area_id,
                        { status: row.status },
                    );
                if (state.persona !== "requester") {
                    for (const row of state.projects)
                        add(
                            "project",
                            row.id,
                            row.name,
                            row.description,
                            row.enterprise_area_id,
                        );
                    for (const row of state.tasks)
                        add(
                            "task",
                            row.id,
                            row.title,
                            row.next_action,
                            row.enterprise_area_id,
                            {
                                project_id: row.project_id,
                                task_id: row.id,
                                owner_id: row.assignee_id,
                                status: row.status,
                            },
                        );
                    for (const row of state.plans.filter(
                        (row) => row.kind === "contractor",
                    ))
                        add("contractor", row.id, row.title, "", row.area_id);
                    for (const [key, thread] of Object.entries(
                        state.discussions,
                    )) {
                        const [kind, target] = key.split(":");
                        const context = discussionSource(kind, target);
                        const source = recordUrl(
                            context.section,
                            context.id,
                            context.area,
                        );
                        for (const note of thread.comments)
                            add(
                                "comment",
                                note.id,
                                "Sample discussion note",
                                note.body,
                                context.area,
                                { source_url: source },
                            );
                    }
                    for (const file of state.uploads) {
                        const target = Object.entries(state.discussions).find(
                            ([, thread]) => thread.task_id === file.task_id,
                        )?.[0];
                        const [kind, id] = target?.split(":") || [];
                        const context = discussionSource(
                            kind || "project",
                            id || IDS.project,
                        );
                        add(
                            "attachment",
                            file.id,
                            file.filename,
                            "Quarantined sample metadata",
                            context.area,
                            {
                                source_url: recordUrl(
                                    context.section,
                                    context.id,
                                    context.area,
                                ),
                            },
                        );
                    }
                    for (const person of members.filter(
                        (row) => row.id !== "3",
                    ))
                        add(
                            "person",
                            person.id,
                            person.name,
                            "Fictional sample employee",
                            IDS.area,
                        );
                }
                const catalog =
                    boot.locations?.entries ||
                    (Array.isArray(boot.locations) ? boot.locations : []);
                for (const row of catalog)
                    add(
                        "building",
                        "catalog:" + row.map_number,
                        "#" + row.map_number + " " + row.name,
                        [
                            row.official_function_details,
                            row.aliases,
                            row.related_spaces,
                        ]
                            .filter(Boolean)
                            .join(" "),
                        null,
                        {
                            source: "official_public_catalog",
                            coordinates_verified: false,
                            map_number: row.map_number,
                        },
                    );
                return {
                    items,
                    visible_count: items.length,
                    partial: true,
                    as_of: now(),
                    coverage:
                        "Up to 100 matches from this fictional browser sample and the official public location catalog. No private ratings, file contents, real college records or mailbox content.",
                };
            }
            error(
                "This operation is not yet available in the isolated sample. It was not sent to the server.",
                404,
            );
        }
        let queue = Promise.resolve();
        function request(url, body, options = {}) {
            const work = async () => {
                const { parsed } = navigation(url),
                    path = parsed.pathname.replace(/\/$/, ""),
                    query = parsed.searchParams;
                if (
                    !path.startsWith("/enterprise/") &&
                    !path.startsWith("/team/")
                )
                    error(
                        "Only explicit virtual sample routes are allowed.",
                        403,
                    );
                const before = clone(state);
                try {
                    const receiptKey =
                        body && !options.binary ? body.request_id : null;
                    const input = receiptKey
                        ? JSON.stringify([path, body])
                        : null;
                    if (receiptKey && state.receipts[receiptKey]) {
                        const receipt = state.receipts[receiptKey];
                        if (
                            receipt.input !== input ||
                            receipt.persona !== state.persona
                        )
                            error(
                                "This sample operation key has different input or perspective.",
                                409,
                            );
                        return { ...clone(receipt.result), replayed: true };
                    }
                    if (receiptKey && Object.keys(state.receipts).length >= 500)
                        error(
                            "This sample reached its bounded operation history. Reset explicitly before more changes.",
                            409,
                        );
                    const result = await handle(path, query, body, options);
                    if (body !== undefined) {
                        if (receiptKey)
                            state.receipts[receiptKey] = {
                                input,
                                persona: state.persona,
                                result: clone(result),
                            };
                        save();
                    }
                    return {
                        ...clone(result),
                        ...(receiptKey ? { replayed: false } : {}),
                    };
                } catch (failure) {
                    state = before;
                    throw failure;
                }
            };
            const result = queue.then(work);
            queue = result.catch(() => {});
            return result;
        }
        const adapter = Object.freeze({
            mode: "sample",
            request,
            href,
            navigate,
            replace,
            snapshot: () => clone(state),
            setPersona(value) {
                if (!["manager", "worker", "requester"].includes(value))
                    error("Choose a sample perspective.");
                state.persona = value;
                save();
            },
            reset() {
                // Clear only this sample workspace's retained UI drafts. Private
                // runtime drafts and the separate controlled trial are untouched.
                const workspace = String(boot.identity?.workspace ?? "campus");
                const prefixes = [
                    `everbranch-enterprise-draft:sample:${workspace}:`,
                    `enterprise-workflow:sample:${workspace}:`,
                    `everbranch-resource:sample:${workspace}:`,
                ];
                try {
                    const names = Array.from(
                        { length: storage.length },
                        (_, index) => storage.key(index),
                    );
                    for (const name of names)
                        if (
                            name &&
                            prefixes.some((prefix) => name.startsWith(prefix))
                        )
                            storage.removeItem(name);
                } catch {
                    /* Browser storage can be unavailable without blocking reset. */
                }
                state = seed();
                save();
            },
            initialScope: () => ({
                area_id: IDS.area,
                project_id: IDS.project,
            }),
        });
        instances.set(key, { storage, adapter });
        return adapter;
    }
    globalThis.EverbranchSampleTransport = Object.freeze({
        create,
        template,
        ids: IDS,
        storageKey: KEY,
    });
})();
