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
        facultyProject: "20000000-0000-4000-8000-000000000002",
        facultyRequest: "30000000-0000-4000-8000-000000000002",
        facultyTask: "40000000-0000-4000-8000-000000000003",
        contractor: "50000000-0000-4000-8000-000000000001",
        building: "60000000-0000-4000-8000-000000000052",
        asset: "70000000-0000-4000-8000-000000000001",
        certificate: "80000000-0000-4000-8000-000000000001",
        finance: "90000000-0000-4000-8000-000000000001",
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
                {
                    id: IDS.facultyProject,
                    name: "Teaching-space readiness - sample",
                    description: "Fictional teaching-space preparation, not an actual class schedule or faculty assignment.",
                    enterprise_area_id: IDS.area,
                    version: 1,
                    archived: false,
                    tasks: [IDS.facultyTask],
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
                {
                    id: IDS.facultyRequest,
                    area_id: IDS.area,
                    title: "Faculty classroom AV readiness - sample",
                    version: 1,
                    status: "assigned",
                    due_on: "2026-10-23",
                    data: {
                        description: "Fictional faculty request to check classroom AV. No actual faculty person, room, timetable or equipment inventory is asserted.",
                        requester_label: "Faculty requester - fictional example",
                        location: "May School of Natural and Health Sciences",
                        location_source: "https://www.lmc.edu/campus-life/campus-safety/campus-operations/files/campus-parking-map.pdf",
                        catalog_map_number: "2",
                        latitude: null,
                        longitude: null,
                        created_by: "fictional-faculty",
                        task_id: IDS.facultyTask,
                        project_id: IDS.facultyProject,
                        progress_note: "The sample worker will confirm the fictional teaching-space AV checklist. No message was sent.",
                    },
                    task_id: IDS.facultyTask,
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
                    id: IDS.facultyTask,
                    title: "Faculty classroom AV readiness - sample",
                    version: 1,
                    assignee_id: "2",
                    completed_at: null,
                    due_on: "2026-10-23",
                    enterprise_area_id: IDS.area,
                    project_id: IDS.facultyProject,
                    status: "active",
                    accountable_owner_id: "1",
                    ball_user_id: "2",
                    next_action: "Confirm the fictional teaching-space AV checklist with the sample faculty requester.",
                    blockers: ["Sample teaching-space access needs confirmation."],
                    checkpoint: "",
                    checklist: [{key: "av", label: "Check the fictional classroom AV setup", weight: 1, completed: false}],
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
            certificates: [
                {
                    id: IDS.certificate,
                    area_id: IDS.area,
                    title: "Sample contractor certificate",
                    contractor_id: IDS.contractor,
                    contractor_title: "Sample campus contractor",
                    contractor_status: "active",
                    status: "active",
                    version: 1,
                    renewal_contact:
                        "Sample renewal contact — no messages sent",
                    warning_lead_days: 30,
                    review_due_on: "2026-10-22",
                    accepted: {
                        document_reference:
                            "FICTIONAL-COI-2026 — reference only",
                        expires_on: "2026-10-25",
                        reviewed_by: "1",
                        reviewed_at: timestamp,
                        review_reason: "Illustrative metadata review only",
                    },
                    pending: null,
                    last_review: null,
                    updated_at: timestamp,
                },
            ],
            finance: [
                {
                    id: IDS.finance,
                    area_id: IDS.area,
                    title: "Event audio invoice — fictional packet",
                    status: "draft",
                    version: 1,
                    project_id: IDS.project,
                    contractor_id: IDS.contractor,
                    amount_minor: 12500,
                    currency: "USD",
                    document_reference:
                        "FICTIONAL-INVOICE-104 — no document fetched",
                    fund_code: "SAMPLE-EVENT-01",
                    restriction_reference:
                        "Fictional source restriction: event audio only; Finance verification required",
                    source_reference:
                        "Fictional existing Finance packet AP-104",
                    next_owner_id: "1",
                    review: null,
                    submission: null,
                    external_completion: null,
                    updated_at: timestamp,
                },
            ],
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
        if (!state || state.schema !== 1) {
            state = seed();
            if (["executive", "demo_manager"].includes(boot.defaultPersona))
                state.persona = boot.defaultPersona;
        }
        if (state.persona === "demo_manager" && boot.defaultPersona !== "demo_manager") state.persona = "executive";
        // Add new illustrative records to older sample sessions without replacing user work.
        const supplemental = seed();
        state.certificates ??= supplemental.certificates;
        state.finance ??= supplemental.finance;
        // Add the clearly fictional faculty example to older browser samples without replacing their edits.
        if (!state.projects.some(row => row.id === IDS.facultyProject)) state.projects.push(clone(supplemental.projects.find(row => row.id === IDS.facultyProject)));
        if (!state.tasks.some(row => row.id === IDS.facultyTask)) state.tasks.push(clone(supplemental.tasks.find(row => row.id === IDS.facultyTask)));
        if (!state.requests.some(row => row.id === IDS.facultyRequest)) state.requests.push(clone(supplemental.requests.find(row => row.id === IDS.facultyRequest)));
        const sampleUpgradeChanged=globalThis.EverbranchJobSample?.upgrade(state,boot,IDS)||false;
        const members=globalThis.EverbranchJobSample?.members()||[{id:'1',name:'Operations coordinator',can_approve:true},{id:'2',name:'Campus worker',can_approve:false},{id:'3',name:'Student requester',can_approve:false}];
        // The former default Executive option represented Blaine. Upgrade that
        // retained sample once, only when the trusted presentation selects the
        // explicit demo manager. Later deliberate perspective choices stay put.
        const managerMigration = "blaine-demo-manager-v1";
        let personaUpgradeChanged = false;
        if (boot.defaultPersona === "demo_manager" && !state.sample_migrations?.[managerMigration]) {
            if (state.persona === "executive") state.persona = "demo_manager";
            state.sample_migrations ??= {};
            state.sample_migrations[managerMigration] = { applied_at: now() };
            personaUpgradeChanged = true;
        }
        if(sampleUpgradeChanged || personaUpgradeChanged){try{storage?.setItem(key,JSON.stringify(state));}catch{state.demo_storage_warning=true;}}
        const currentId = () =>
            state.persona === "executive"
                ? "demo-executive"
                : state.persona === "requester"
                  ? "3"
                  : state.persona === "worker"
                    ? "2"
                    : "1";
        const readOnlyExecutive = () => state.persona === "executive";
        const demoManager = () => state.persona === "demo_manager";
        // Both share the overview and its ancillary read-only capabilities.
        const executive = () => readOnlyExecutive() || demoManager();
        const manager = () => ["manager", "executive", "demo_manager"].includes(state.persona);
        const workCoordinator = () => manager() && !readOnlyExecutive();
        const canWrite = () =>
            !["requester", "executive"].includes(state.persona);
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
            const result = get(state.projects, id);
            area(result.enterprise_area_id);
            return result;
        }
        function area(id) {
            const result = get(state.areas, id || IDS.area);
            if (
                (!manager() && result.visibility === "department_only") ||
                (state.persona === "requester" && result.id !== IDS.area)
            )
                error(
                    "This workspace is unavailable in this sample perspective.",
                    404,
                );
            return result;
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
                "view",
                "map",
                "search",
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
                parsed.pathname ===
                    new URL(
                        boot.routes.workflowWorkspace,
                        globalThis.location?.href ?? "https://sample.invalid/",
                    ).pathname ||
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
            area(row.area_id);
            if (
                state.persona === "requester" &&
                row.data.created_by !== currentId()
            )
                error("This sample request is unavailable.", 404);
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
                request_type:row.data.request_type||null,requested_priority:row.data.priority||null,
                priority:task?.priority||row.data.priority||null,priority_source:task?.priority?'task':row.data.priority?'request':'not_recorded',
                task: task ? clone(task) : null,
                can_coordinate: workCoordinator(),
                can_progress:
                    canWrite() &&
                    (manager() || task?.assignee_id === currentId()),
            };
        }
        function executiveModel() {
            if (!executive())
                error("The executive sample overview is unavailable.", 403);
            const configured = state.areas.filter(
                (row) => manager() || row.visibility === "shared",
            );
            const ids = new Set(configured.map((row) => row.id));
            const tasks = state.tasks.filter((row) =>
                ids.has(row.enterprise_area_id),
            );
            const target = (section, selected) => ({
                section,
                area: IDS.area,
                ...(selected ? { selected } : {}),
            });
            return {
                runtime: "sample",
                persona: "executive",
                demoManager: demoManager(),
                asOf: now(),
                scope: "Configured fictional workspace records. No current college operating data or direct-report hierarchy is asserted.",
                network: configured.map((row) => ({
                    id: row.id,
                    label: row.name,
                    lifecycle:
                        row.id === IDS.area
                            ? "Live"
                            : row.id === IDS.it
                              ? "Onboarding"
                              : "Planned",
                    summary:
                        "Illustrative rollout stage only; not provisioned or connected. Open this configured sample workspace to inspect its records.",
                    available: true,
                    target: { section: "projects", area: row.id },
                })),
                metrics: [
                    {
                        id: "active",
                        label: "Active work",
                        value: tasks.filter((row) => !row.completed_at).length,
                        detail: "Current visible sample tasks; inspect ownership and next action.",
                        available: true,
                        target: target("projects", IDS.project),
                    },
                    {
                        id: "blocked",
                        label: "Interrupted or blocked work",
                        value: tasks.filter(
                            (row) =>
                                row.status === "interrupted" ||
                                row.blockers?.length,
                        ).length,
                        detail: "Recorded interruptions and blockers, not inferred urgency.",
                        available: true,
                        target: target("roll_call"),
                    },
                    {
                        id: "schedule",
                        label: "Schedule and capacity",
                        value: null,
                        detail: "Recorded reservations and conflicts are available. Staff capacity, live class schedules and utilization are not connected.",
                        available: true,
                        target: target("calendar"),
                    },
                    {
                        id: "insurance",
                        label: "Contractor renewal attention",
                        value: state.certificates.filter(
                            (row) =>
                                ids.has(row.area_id) &&
                                sampleCertificate(row).reminder_reasons?.length,
                        ).length,
                        detail: "Derived fictional certificate attention queue; no external alerts or coverage-policy decision.",
                        available: true,
                        target: target("people"),
                    },
                    {
                        id: "approvals",
                        label: "Invoice review packets",
                        value: state.finance.filter(
                            (row) =>
                                ids.has(row.area_id) &&
                                row.status !== "externally_completed",
                        ).length,
                        detail: "Current sample packets needing a next owner. Internal routing is not payment authorization.",
                        available: true,
                        target: target("finance", IDS.finance),
                    },
                    {
                        id: "variance",
                        label: "Restricted funds / budget variance",
                        value: null,
                        detail: "Source fund references can be inspected. Approved budgets, restrictions and ledger balances remain unverified; no variance is calculated.",
                        available: true,
                        target: target("finance", IDS.finance),
                    },
                    {
                        id: "quarterly",
                        label: "Quarterly readiness",
                        value: "Review required",
                        detail: "Open factual period exports and reconcile to the agreed report template. No approved college template or submission is connected.",
                        available: true,
                        target: target("roll_call"),
                    },
                ],
                proposal: {
                    currency: null,
                    cadence: null,
                    stages: [
                        { amount: 1000, label: "Proposed stage 1" },
                        { amount: 2000, label: "Proposed stage 2" },
                        { amount: 2500, label: "Proposed stage 3" },
                    ],
                },
                allowedSourceOrigins: ["https://www.lmc.edu"],
                sources: [
                    {
                        label: "Current public leadership",
                        url: "https://www.lmc.edu/about/leadership.htm",
                        detail: "Parent research checked Oct22,2026: Hansen is Executive Vice President / COO. No account or purchasing authority is inferred.",
                    },
                    {
                        label: "Hansen directory",
                        url: "https://www.lmc.edu/directory/employee/hansen-blaine.htm",
                        detail: "Current role in Strategic Planning & Effectiveness; demonstration persona only.",
                    },
                    {
                        label: "Historical org chart — confirm current scope",
                        url: "https://www.lmc.edu/about/strategic-plan-2030/files/factbook.pdf",
                        detail: "2022–23 Factbook published Jan2024, PDF page index4. Historical division included operations/risk, facilities, IR/planning, technology, dining/retail/Exchange and events. It is not a current direct-report chart.",
                    },
                    {
                        label: "Current department directory",
                        url: "https://www.lmc.edu/directory/strategicplanning-effectiveness.htm",
                        detail: "Directory grouping includes operations, institutional research, project management and motor pool. Grouping is not direct-report proof. Other portfolio areas are unconnected; admissions and marketing are outside this initial scope.",
                    },
                ],
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
                areas: clone(
                    state.areas.filter(
                        (row) =>
                            manager() ||
                            (row.visibility === "shared" &&
                                (state.persona !== "requester" ||
                                    row.id === IDS.area)),
                    ),
                ),
                demo_persona: state.persona,
                ...(executive() ? { executive: executiveModel() } : {}),
                requests: state.requests
                    .filter(
                        (row) =>
                            row.status !== "removed" && row.area_id === selected &&
                            (state.persona !== "requester" ||
                                row.data.created_by === currentId()),
                    )
                    .map(requestView),
                projects: state.projects.filter(row=>row.enterprise_area_id===selected&&state.persona!=='requester').map(row=>globalThis.EverbranchJobSample?EverbranchJobSample.projectProjection(state,row,members,workCoordinator()):clone(row)),
                tasks: state.persona==='requester'?[]:state.tasks.filter(row=>row.enterprise_area_id===selected&&!row.deleted).map(clone),
                request_fields:{version:1,types:{general:'General request',it_support:'IT support',event_setup:'Event setup',plumbing:'Plumbing',lighting:'Lighting',maintenance:'Maintenance'},priorities:['low','normal','high','urgent']},
                ...(state.demo_storage_warning?{demo_storage_warning:true}:{}),
                tasks_has_more:false,tasks_coverage:'Returned fictional tasks in this browser.',
                buildings:{items:state.persona==='requester'?[]:state.resources.filter(row=>row.kind==='location'&&row.area_id===selected&&row.status==='active'&&row.data.level==='facility').map(row=>({id:row.id,title:row.title,version:row.version,map_number:row.data.map_number||row.data.catalog_map_number,source_url:row.data.source_url||row.data.source})),has_more:false,coverage:'Saved fictional building records in this department.'},
                routing_departments:manager()?clone(state.areas):[],
                members:
                    state.persona === "requester"
                        ? []
                        : clone(members.filter((row) => row.id !== "3")),
                features: {
                    collaboration: state.persona !== "requester",
                    planning: state.persona !== "requester",
                    ask: true,
                    voice_jobs: true,
                    ai_usage: manager(),
                    email_drafts: false,
                    workflow: state.persona !== "requester",
                    briefing: state.persona !== "requester",
                    operations: state.persona !== "requester",
                    resources: true,
                    roll_call: state.persona !== "requester",
                    requester: true,
                    reports: manager(),
                    certificates: manager(),
                    finance: manager(),
                },
                coverage:
                    "Fictional browser sample only. Perspective filtering is illustrative, not server authorization.",
                has_more: false,
                scan_partial: false,
                can_manage: manager() && !executive(),
                can_submit: !readOnlyExecutive() && (state.persona!=="requester" || selected===IDS.area),
                can_coordinate: workCoordinator(),
                can_review: manager(),
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
        function sampleJobCommand(body) {
            const actions=['job.create','project.update','project.archive','task.create','task.update','task.delete','request.plan'];
            if(!actions.includes(body.action))return null;
            coordinate();area(body.area_id);
            const member=id=>id===null||id===undefined||id===''?null:members.find(row=>row.id===String(id)&&row.id!=='3')||error('Choose a current sample worker.',422);
            const savedBuilding=(areaId)=>{
                if(!body.location_id)return null;
                const row=get(state.resources,body.location_id);
                if(row.kind!=='location'||row.area_id!==areaId||row.status!=='active'||row.data.level!=='facility')error('Choose a saved building in the destination department.',422);
                checkVersion(row,body.location_version);return row;
            };
            const link=(row,building)=>{for(const key of ['location_id','location_version','location_reference','catalog_map_number','latitude','longitude'])delete row.data[key];row.data.location=building?.title||body.location||'';if(building){row.data.location_id=building.id;row.data.location_version=building.version;row.data.catalog_map_number=String(building.data.map_number||building.data.catalog_map_number||'');row.data.location_reference={id:building.id,version:building.version,map_number:row.data.catalog_map_number,source_url:building.data.source_url||building.data.source};}};
            const result=(projectId,taskId,ticketId,areaId=body.area_id)=>({area_id:areaId,project_id:projectId,...(taskId?{task_id:taskId}:{}),...(ticketId?{ticket_id:ticketId}:{})});
            if(body.action==='job.create'){
                const assignee=member(body.assignee_id);if(!assignee)error('Choose an existing sample worker.');
                const building=savedBuilding(body.area_id);command({action:'project.create',id:body.id,area_id:body.area_id,title:body.title});
                const target=project(body.id);target.description=body.description||'';target.owner_id=currentId();target.due_on=body.due_on||null;target.updated_at=now();
                let ticket=command({action:'request.create',id:uuid(),area_id:body.area_id,title:body.task_title||body.title,description:body.description||'',due_on:body.due_on,priority:body.priority||'normal',request_type:body.request_type||'general'});
                const row=get(state.requests,ticket.id);link(row,building);
                ticket=command({action:'request.triage',id:row.id,area_id:row.area_id,expected_version:row.version,note:'Reviewed through the assignment wizard.'});
                ticket=command({action:'request.assign',id:row.id,area_id:row.area_id,expected_version:ticket.version,project_id:target.id,assignee_id:assignee.id});
                return result(target.id,ticket.data.task_id,row.id);
            }
            if(body.action==='request.plan'){
                const row=get(state.requests,body.id);if(row.area_id!==body.area_id||row.task_id||!['submitted','triaged'].includes(row.status))error('Only a current incoming ticket can be routed.',409);
                checkVersion(row,body.expected_version);if(body.acknowledge_routing!==true&&body.acknowledge_routing!==1)error('Review and confirm the destination.',422);
                const destination=area(body.target_area_id),target=project(body.project_id),assignee=member(body.assignee_id),building=savedBuilding(destination.id);
                if(!assignee||target.enterprise_area_id!==destination.id||target.archived)error('Choose an active project and worker in the destination department.',422);
                row.area_id=destination.id;link(row,building);row.version++;audit(row.id,'request.routed');
                if(row.status==='submitted')command({action:'request.triage',id:row.id,area_id:row.area_id,expected_version:row.version,note:'Reviewed through the assignment wizard.'});
                const assigned=command({action:'request.assign',id:row.id,area_id:row.area_id,expected_version:row.version,project_id:target.id,assignee_id:assignee.id});
                return result(target.id,assigned.data.task_id,row.id,destination.id);
            }
            if(body.action.startsWith('project.')){
                const row=project(body.id);if(row.enterprise_area_id!==body.area_id)error('Choose this project’s department.',404);checkVersion(row,body.expected_version);
                if(body.action==='project.archive')row.archived=Boolean(body.archived);else{row.name=String(body.title).slice(0,100);row.description=String(body.description||'').slice(0,5000);row.due_on=body.due_on||null;}
                row.version++;row.updated_at=now();audit(row.id,body.action);return result(row.id);
            }
            if(body.action==='task.create'){
                const target=project(body.project_id);if(target.enterprise_area_id!==body.area_id||target.archived)error('Choose an active project in this department.',422);
                if(state.tasks.some(row=>row.id===body.id))error('This sample task already exists.',409);
                const assignee=member(body.assignee_id);const row={id:body.id,title:String(body.title).slice(0,100),description:body.description||'',version:1,assignee_id:assignee?.id||null,completed_at:null,due_on:body.due_on||null,enterprise_area_id:body.area_id,project_id:target.id,status:'active',priority:body.priority||'normal',owner_user_id:'1',accountable_owner_id:'1',ball_user_id:assignee?.id||null,next_action:'Review the assigned work.',blockers:[],checklist:[],approved:false,created_at:now(),updated_at:now()};state.tasks.push(row);target.tasks.push(row.id);audit(row.id,body.action);return result(target.id,row.id);
            }
            const task=get(state.tasks,body.id);if(task.deleted||task.enterprise_area_id!==body.area_id)error('This task is unavailable.',404);checkVersion(task,body.expected_version);
            const row=state.requests.find(item=>item.data?.task_id===task.id);
            if(row&&(row.id!==body.related_request_id||row.version!==Number(body.request_version)))error('The linked ticket changed. Reload before editing.',409);
            if(body.action==='task.delete'){
                if(body.acknowledge_removal!==true&&body.acknowledge_removal!==1)error('Confirm removal without deleting history.',422);
                task.deleted=true;if(row)row.status='removed';
            }else{
                if(task.completed_at)error('Review completed work through its operational controls.',409);
                const assignee=member(body.assignee_id);Object.assign(task,{title:String(body.title).slice(0,100),description:body.description||'',priority:body.priority||task.priority||'normal',assignee_id:assignee?.id||null,ball_user_id:assignee?.id||null,due_on:body.due_on||null});
                if(row){row.title=task.title;row.due_on=task.due_on;row.data.description=task.description;}
            }
            task.version++;task.updated_at=now();if(row){row.version++;audit(row.id,body.action);}audit(task.id,body.action);return result(task.project_id,task.id);
        }
        function command(body) {
            const managed=sampleJobCommand(body);if(managed)return managed;
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
                    description: body.description || "Reviewer-created fictional project.",
                    owner_id: currentId(),
                    updated_at: now(),
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
                        request_type:body.request_type||null,priority:body.priority||null,
                        latitude: null,
                        longitude: null,
                        created_by: currentId(),
                        progress_note: "",
                    },
                    task_id: null,
                };
                if(body.location_id){const building=get(state.resources,body.location_id);if(building.area_id!==body.area_id||building.kind!=='location'||building.status!=='active'||building.data.level!=='facility')error('Choose a current saved building in this department.',422);checkVersion(building,body.location_version);Object.assign(row.data,{location:building.title,location_id:building.id,location_version:building.version,catalog_map_number:String(building.data.map_number||building.data.catalog_map_number||''),location_reference:{id:building.id,version:building.version,map_number:String(building.data.map_number||building.data.catalog_map_number||''),source_url:building.data.source_url||building.data.source}});}
                state.requests.unshift(row);
                audit(row.id, action);
                return requestView(row);
            }
            const row = get(state.requests, body.id);
            checkVersion(row, body.expected_version);
            if (row.area_id !== body.area_id)
                error("Choose the request’s owning sample workspace.", 404);
            if(action === "request.classify"){coordinate();if(!["submitted","triaged"].includes(row.status)||row.task_id)error("Only an incoming ticket can be classified.",409);for(const key of ["request_type","priority"])if(body[key]!==undefined)row.data[key]=body[key];
            } else if (action === "request.triage") {
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
                    description:row.data.description||"",priority:row.data.priority||"normal",updated_at:now(),
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
                can_design: manager() && !executive(),
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
                area(row.area_id);
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
            } else if (kind === "task")
                area(get(state.tasks, id).enterprise_area_id);
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
            area(task.enterprise_area_id);
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
                can_manage: manager() && !executive(),
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
                can_manage: manager() && !executive(),
                coverage:
                    "Fictional sample operations only; private server authorization is not simulated as a security boundary.",
                as_of: now(),
            };
        }
        // Pure lookup: never enters sample receipts, persistence, discussion creation or provider transport.
        function voiceJobs(body) {
            const uuidPattern = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i;
            if (!body || typeof body !== "object" || Array.isArray(body) || typeof body.area_id !== "string" || !uuidPattern.test(body.area_id)
                || typeof body.question !== "string" || !body.question.trim() || body.question.length > 1500
                || (body.focus != null && (typeof body.focus !== "string" || !uuidPattern.test(body.focus))))
                error("Choose a current sample department and a job question up to 1,500 characters.");
            const chosen = area(body.area_id), question = body.question.trim();
            const base = {
                mode: "record_backed", provider_dispatch: false, sample_data: true, as_of: now(),
                scope: {tenant_id: "sample-campus", user_id: currentId(), area_id: chosen.id, department: chosen.name},
                coverage: "At most 200 currently permitted fictional sample tasks in this department. Linked request location only; no live tracking, mailbox, student records or accounting. Missing evidence is not proof that nothing exists.",
                candidates: [], job: null,
            };
            if (/\b(send|email|e-mail|text|sms|delete|assign|approve|pay|complete|reschedule|cancel)\b/iu.test(question))
                return {...base, outcome: "review_required", reply: "Voice lookup cannot change work or send messages. Open the authorized action or composer, review the exact recipient and body, and confirm separately. Nothing was sent or changed."};
            const permitted = task => {
                if (state.persona === "requester" || task.enterprise_area_id !== chosen.id || task.deleted) return false;
                const parent = state.projects.find(row => row.id === task.project_id);
                return Boolean(parent && !parent.archived && parent.enterprise_area_id === chosen.id);
            };
            const read = id => {
                const task = get(state.tasks, id);
                if (!permitted(task)) error("This sample operational task is unavailable in the current department and perspective.", 404);
                project(task.project_id); // Recheck the same current department/project policy on every focused follow-up.
                return {raw: task, view: taskView(task)};
            };
            let selected;
            if (body.focus != null) selected = read(body.focus);
            else {
                const stop = new Set("a an the please can could would you me my our show open find bring up tell about everything what whats is are where wheres who whos when how status job jobs task tasks work on at in of for to and with it its that this".split(" "));
                const terms = [...new Set(question.toLowerCase().split(/[^\p{L}\p{N}]+/u).filter(term => term && !stop.has(term)))];
                const checked = state.tasks.filter(permitted).slice(0, 200);
                const matches = terms.length ? checked.filter(task => terms.every(term => task.title.toLowerCase().includes(term))) : [];
                if (matches.length !== 1) return {...base, candidates: matches.slice(0, 10).map(task => ({id: task.id, title: task.title})),
                    outcome: matches.length ? "choose" : "no_match", reply: matches.length
                        ? "More than one permitted job matches. Choose the exact job before I open or describe it."
                        : "No matching job was found in the checked department sample. Say a few words from its title, or use search."};
                selected = read(matches[0].id);
            }
            const {raw, view} = selected;
            let location = null;
            for (const row of state.requests) {
                if (row.area_id !== chosen.id || (row.task_id || row.data?.task_id) !== view.id) continue;
                try {
                    const linked = requestView(row);
                    location = {label: linked.data.location ?? null, latitude: linked.data.latitude ?? null, longitude: linked.data.longitude ?? null,
                        provenance: "Recorded fictional request location; not verified positioning or live GPS.", source_url: recordUrl("requests", row.id, chosen.id)};
                    break;
                } catch (failure) {
                    if (![403, 404].includes(failure.status)) throw failure;
                }
            }
            const job = Object.fromEntries(["id", "project_id", "area_id", "title", "status", "priority", "next_action", "due_on", "blockers", "version", "task_version"]
                .map(key => [key, view[key] ?? null]));
            job.priority = ['low', 'normal', 'high', 'urgent'].includes(raw.priority) ? raw.priority : null;
            job.updated_at = raw.updated_at ?? null; // An absent source timestamp stays unknown.
            job.owner = members.find(member => member.id === String(view.owner_user_id))?.name ?? null;
            job.handler = view.ball_team ? "Department team" : (members.find(member => member.id === String(view.ball_user_id))?.name ?? null);
            job.location = location;
            job.source_url = recordUrl("projects", view.project_id, chosen.id) + "&task=" + encodeURIComponent(view.id);
            job.cost_coverage = "Costs are outside this voice projection. Use currently authorized Planning for recorded project costs; no job total is inferred.";
            const reply = job.title + ". Status: " + String(job.status).replaceAll("_", " ") + ". Location: " + (location?.label ?? "not recorded in the checked sources")
                + ". Priority: " + (job.priority ?? "not recorded") + ". Current handler: " + (job.handler ?? "not available") + ". Due date: " + (job.due_on ?? "not recorded")
                + ". Next action: " + (job.next_action || "not recorded") + ". Blockers: " + (job.blockers?.length ? job.blockers.join("; ") : "none recorded in this task") + ".";
            return {...base, outcome: "matched", job: clone(job), reply};
        }
        // Demo-only drafts and outbox stay in RAM. Never mailbox, graph or receipt storage.
        let demoEpoch = 0;
        const demoPreviews = new Map(), demoSent = new Map(), demoListeners = new Set();
        function retireDemo() {
            demoEpoch++; demoPreviews.clear(); demoSent.clear();
            for (const fn of demoListeners) fn();
        }
        function demoContext(areaId) {
            if (boot.reviewerSimulation !== true || Object.hasOwn(boot, "reviewerWorkspace")) error("Simulated conversation is unavailable in this presentation.", 403);
            area(areaId);
            const departments = state.areas.filter(row => {
                try { area(row.id); return true; } catch (failure) { if (failure.status === 404) return false; throw failure; }
            }).map(row => ({id: row.id, name: row.name}));
            return clone({stamp: JSON.stringify([demoEpoch, state.persona, currentId(), areaId]), user_id: currentId(), departments});
        }
        function demoEmail(input) {
            if (!input || Object.keys(input).sort().join(',') !== 'area_id,body,department_id,job_id,subject'
                || typeof input.subject !== 'string' || !input.subject.trim() || input.subject.length > 200
                || typeof input.body !== 'string' || !input.body.trim() || input.body.length > 3000
                || /[\u0000-\u0008\u000b\u000c\u000e-\u001f]/u.test(input.subject + input.body))
                error('Review a subject up to 200 characters and a message up to 3,000 characters.', 422);
            const context = demoContext(input.area_id), destination = area(input.department_id);
            const result = voiceJobs({area_id: input.area_id, question: 'Show this job', focus: input.job_id});
            if (result.outcome !== 'matched') error('Choose a currently permitted job before preparing this demo message.', 404);
            if (demoPreviews.size >= 32) error('This page has reached its demo preview limit. Nothing was sent.', 409);
            const id = crypto.randomUUID();
            const preview = {id, simulated: true, status: 'preview', area_id: input.area_id, department_id: destination.id,
                department: destination.name, subject: input.subject, body: input.body};
            demoPreviews.set(id, {preview, stamp: context.stamp, job: JSON.stringify(result.job), job_id: input.job_id, expires: Date.now() + 600000});
            return clone(preview);
        }
        function demoDeliver(input) {
            if (!input || Object.keys(input).sort().join(',') !== 'confirmed,preview_id' || input.confirmed !== true || typeof input.preview_id !== 'string')
                error('Confirm the exact simulated preview.', 422);
            const saved = demoPreviews.get(input.preview_id);
            if (!saved || saved.expires <= Date.now() || demoContext(saved.preview.area_id).stamp !== saved.stamp)
                error('This demo preview expired or its perspective changed. Review it again.', 409);
            area(saved.preview.department_id);
            const result = voiceJobs({area_id: saved.preview.area_id, question: 'Show this job', focus: saved.job_id});
            if (result.outcome !== 'matched' || JSON.stringify(result.job) !== saved.job)
                error('The source job changed. Keep your draft and review current facts.', 409);
            if (demoSent.has(input.preview_id)) return clone({...demoSent.get(input.preview_id), replayed: true});
            if (demoSent.size >= 20) error('This page has reached its demo outbox limit. Nothing was sent.', 409);
            const receipt = {...saved.preview, status: 'simulated_delivery', delivered_to_provider: false, replayed: false};
            demoSent.set(input.preview_id, receipt);
            return clone(receipt);
        }
        function sampleUsage(query) {
            if (!manager()) error("Sample usage is available only to the manager or executive perspective.", 404);
            if (query.has("area_id") && !/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(query.get("area_id")))
                error("Choose an exact configured sample department for usage.");
            const selected = query.has("area_id") ? area(query.get("area_id")).id : null;
            const empty = () => Object.fromEntries(["requests", "completed", "reserved", "uncertain", "cancelled", "unreconciled", "input_tokens", "output_tokens", "reported_token_records", "token_unknown_records", "original_reserved_microusd", "original_reservation_unknown_records", "settled_usage_derived_microusd", "reserved_exposure_microusd", "uncertain_reserved_microusd", "estimated_total_exposure_microusd", "exposure_unknown_records"].map(key => [key, 0]));
            // Fixed illustrative examples, never calculated from browser activity or represented as provider usage.
            const examples = [
                {area: IDS.area, purpose: "ask", label: "Ask Everbranch", totals: {...empty(), requests: 2, completed: 1, uncertain: 1, input_tokens: 900, output_tokens: 150, reported_token_records: 1, token_unknown_records: 1, original_reserved_microusd: 7000, settled_usage_derived_microusd: 1500, reserved_exposure_microusd: 4000, uncertain_reserved_microusd: 4000, estimated_total_exposure_microusd: 5500}},
                {area: IDS.area, purpose: "briefing", label: "Project briefing", totals: {...empty(), requests: 1, completed: 1, input_tokens: 1200, output_tokens: 200, reported_token_records: 1, original_reserved_microusd: 3000, settled_usage_derived_microusd: 1800, estimated_total_exposure_microusd: 1800}},
                {area: IDS.it, purpose: "workflow_draft", label: "Workflow draft", totals: {...empty(), requests: 1, completed: 1, input_tokens: 1500, output_tokens: 300, reported_token_records: 1, original_reserved_microusd: 5000, settled_usage_derived_microusd: 2400, estimated_total_exposure_microusd: 2400}},
            ];
            const totals = empty(), departments = state.areas.filter(row => (!selected && [IDS.area, IDS.it].includes(row.id)) || row.id === selected).map(row => {
                area(row.id);
                const department = {id: row.id, name: row.name, totals: empty(), purposes: examples.filter(example => example.area === row.id).map(({purpose, label, totals}) => ({purpose, label, totals}))};
                for (const purpose of department.purposes) for (const key of Object.keys(totals)) {department.totals[key] += purpose.totals[key]; totals[key] += purpose.totals[key];}
                return department;
            });
            return {sample_data: true, read_only: true, provider_invoice_verified: false, as_of: now(), currency: "USD", money_unit: "microusd",
                scope: {type: selected ? "department" : "authorized_departments", area_id: selected}, ledger_state: "fictional_illustrative_records", totals, departments,
                coverage: {record_scan_limit: 1000, scanned_records: totals.requests, department_limit: 200, returned_departments: departments.length, has_more_records: false, has_more_departments: false, partial: false, period: "fixed_fictional_example_only"},
                technical_budget: {ready: false, provider_calls_verified: false, status: "Budget discovery pending; unactivated. These fixed fictional examples do not establish a live allowance or commercial cap."},
                basis: {source: "FICTIONAL SAMPLE USAGE: explicit fixed examples, not browser counts, real ledger records or provider-account activity.",
                    settled_cost: "Illustrative token-derived cost only; not an invoice or actual spend.", reserved_exposure: "The uncertain example illustrates held exposure; no real reservation is created.",
                    tokens: "All token figures are fictional. Browser voice lookup is a separate record lookup and makes no model call or charge.",
                    zero_records: "No illustrative row is not proof of zero provider-account spend.", commercial: "Budget discovery pending and unactivated. No price, subscription, quota, allowance or cap is established.", snapshot: "Generated from fixed fictional constants during this read; no transcript or usage is persisted."}};
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
                can_coordinate: manager() && !executive(),
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
                can_lead: manager() && !executive(),
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
        function procedureArea(query, body) {
            coordinate();
            return area(body?.area_id || query.get("area_id") || IDS.area).id;
        }
        function sampleCertificate(row) {
            const today = new Date().toISOString().slice(0, 10),
                accepted = row.accepted;
            const reasons = [],
                expiry = accepted?.expires_on;
            let expiryState = accepted ? "unknown" : "unreviewed";
            if (!accepted) reasons.push("unreviewed");
            if (accepted && row.pending)
                reasons.push("awaiting_replacement_review");
            if (!accepted?.document_reference)
                reasons.push("document_reference_missing");
            if (!row.renewal_contact) reasons.push("renewal_contact_missing");
            if (row.warning_lead_days === null)
                reasons.push("warning_not_configured");
            if (!row.review_due_on) reasons.push("review_not_scheduled");
            else if (row.review_due_on <= today) reasons.push("review_due");
            if (!expiry) reasons.push("expiry_unknown");
            else if (expiry < today) reasons.push((expiryState = "expired"));
            else if (expiry === today)
                reasons.push((expiryState = "expires_today"));
            else if (row.warning_lead_days === null)
                expiryState = "not_configured";
            else if (
                (Date.parse(expiry) - Date.parse(today)) / 86400000 <=
                row.warning_lead_days
            ) {
                reasons.push("expiry_warning");
                expiryState = "warning";
            } else expiryState = "outside_warning";
            return {
                ...clone(row),
                reminder_reasons: row.status === "archived" ? [] : reasons,
                expiry_state:
                    row.status === "archived" ? "archived" : expiryState,
                can_manage:
                    manager() && !executive() && row.status !== "archived",
                as_of_on: today,
                timezone: "UTC",
                sample_data: true,
            };
        }
        function certificateRequest(path, query, body) {
            const selected = procedureArea(query, body),
                match = path.match(
                    /^\/enterprise\/certificates\/([^/]+)(\/history)?$/,
                );
            if (path === "/enterprise/certificates/commands") {
                const action = body.action,
                    existing = state.certificates.find((r) => r.id === body.id);
                let row = existing;
                if (row) {
                    if (row.area_id !== selected)
                        error(
                            "Sample certificate is outside this department.",
                            404,
                        );
                    checkVersion(row, body.expected_version);
                    if (row.status === "archived")
                        error(
                            "Archived sample certificate cannot be changed.",
                            409,
                        );
                } else {
                    if (
                        action !== "certificate.propose" ||
                        Number(body.expected_version) !== 0
                    )
                        error("Sample certificate is unavailable.", 404);
                    const contractor = get(state.plans, body.contractor_id);
                    if (
                        contractor.kind !== "contractor" ||
                        contractor.area_id !== selected ||
                        contractor.status !== "active"
                    )
                        error(
                            "Choose an active same-department sample contractor.",
                            422,
                        );
                    row = {
                        id: body.id,
                        area_id: selected,
                        title: body.title,
                        contractor_id: contractor.id,
                        contractor_title: contractor.title,
                        contractor_status: contractor.status,
                        status: "active",
                        version: 0,
                        accepted: null,
                        pending: null,
                        last_review: null,
                        renewal_contact: body.renewal_contact,
                        warning_lead_days: body.warning_lead_days,
                        review_due_on: body.review_due_on,
                    };
                    state.certificates.push(row);
                }
                if (action === "certificate.propose") {
                    if (row.pending)
                        error(
                            "Review the current sample replacement first.",
                            409,
                        );
                    row.pending = {
                        replacement_id: body.replacement_id,
                        document_reference: body.document_reference,
                        expires_on: body.expires_on,
                        proposed_at: now(),
                        proposed_by: currentId(),
                    };
                } else if (action === "certificate.metadata")
                    Object.assign(row, {
                        title: body.title,
                        renewal_contact: body.renewal_contact,
                        warning_lead_days: body.warning_lead_days,
                        review_due_on: body.review_due_on,
                    });
                else if (action === "certificate.review") {
                    if (
                        body.confirmed !== true ||
                        !String(body.reason || "").trim() ||
                        row.pending?.replacement_id !== body.replacement_id ||
                        !["accept", "reject"].includes(body.decision)
                    )
                        error(
                            "Confirm this exact sample replacement and review reason.",
                            422,
                        );
                    if (body.decision === "accept") {
                        if (
                            !row.pending.document_reference ||
                            !row.pending.expires_on
                        )
                            error(
                                "Sample acceptance requires a reference and expiry.",
                                422,
                            );
                        row.accepted = {
                            document_reference: row.pending.document_reference,
                            expires_on: row.pending.expires_on,
                            reviewed_by: currentId(),
                            reviewed_at: now(),
                            review_reason: body.reason,
                        };
                    }
                    row.last_review = {
                        decision: body.decision,
                        reason: body.reason,
                        by: currentId(),
                        at: now(),
                    };
                    row.pending = null;
                } else if (action === "certificate.archive") {
                    if (
                        body.confirmed !== true ||
                        !String(body.reason || "").trim()
                    )
                        error("Confirm the sample archive and reason.", 422);
                    row.status = "archived";
                } else error("Unsupported sample certificate action.", 422);
                row.version++;
                row.updated_at = now();
                audit(row.id, "sample." + action, { status: row.status });
                return sampleCertificate(row);
            }
            if (match && match[1] !== "reminders") {
                const row = get(state.certificates, match[1]);
                if (row.area_id !== selected)
                    error(
                        "Sample certificate is outside this department.",
                        404,
                    );
                return match[2]
                    ? {
                          items: clone(state.histories[row.id] || []),
                          coverage: "Fictional browser-only revision history.",
                      }
                    : sampleCertificate(row);
            }
            const rows = state.certificates
                .filter((r) => r.area_id === selected)
                .map(sampleCertificate);
            return {
                items: path.endsWith("/reminders")
                    ? rows.filter((r) => r.reminder_reasons.length)
                    : rows,
                contractors: clone(
                    state.plans.filter(
                        (r) =>
                            r.kind === "contractor" && r.area_id === selected,
                    ),
                ),
                can_manage: !executive(),
                as_of_on: new Date().toISOString().slice(0, 10),
                timezone: "UTC",
                partial: false,
                has_more: false,
                coverage:
                    "Fictional browser-only records; no insurance provider or notification is connected.",
            };
        }
        function sampleFinance(row) {
            return {
                ...clone(row),
                next_owner_eligible: row.next_owner_id === "1",
                can_manage:
                    !executive() && row.status !== "externally_completed",
                can_decide:
                    row.status === "submitted" &&
                    row.next_owner_id === currentId(),
                authority:
                    "Fictional internal routing only. No fund verification, college records or payment execution.",
            };
        }
        function financeRequest(path, query, body) {
            const selected = procedureArea(query, body),
                match = path.match(
                    /^\/enterprise\/finance\/([^/]+)(\/history)?$/,
                );
            if (path === "/enterprise/finance/commands") {
                let row = state.finance.find((r) => r.id === body.id);
                const action = body.action;
                if (row) {
                    if (row.area_id !== selected)
                        error("Sample packet is outside this department.", 404);
                    checkVersion(row, body.expected_version);
                    if (row.status === "externally_completed")
                        error(
                            "Completed sample packets preserve their references.",
                            409,
                        );
                } else {
                    if (
                        action !== "packet.save" ||
                        Number(body.expected_version) !== 0
                    )
                        error("Sample packet is unavailable.", 404);
                    row = {
                        id: body.id,
                        area_id: selected,
                        status: "draft",
                        version: 0,
                        review: null,
                        submission: null,
                        external_completion: null,
                    };
                    state.finance.push(row);
                }
                if (
                    action !== "packet.save" &&
                    (body.confirmed !== true ||
                        !String(body.reason || "").trim())
                )
                    error(
                        "Review and confirm this exact sample packet with a reason.",
                        422,
                    );
                if (action === "packet.save") {
                    if (!["draft", "needs_changes"].includes(row.status))
                        error("Reopen this packet before editing.", 409);
                    const p = project(body.project_id);
                    if (p.enterprise_area_id !== selected || p.archived)
                        error(
                            "Choose a project in this sample department.",
                            404,
                        );
                    if (body.contractor_id) {
                        const c = get(state.plans, body.contractor_id);
                        if (
                            c.kind !== "contractor" ||
                            c.area_id !== selected ||
                            c.status !== "active"
                        )
                            error(
                                "Choose a same-department sample contractor.",
                                422,
                            );
                    }
                    if (String(body.next_owner_id) !== "1")
                        error(
                            "Choose the sample manager as the financial reviewer.",
                            422,
                        );
                    if (
                        !Number.isSafeInteger(body.amount_minor) ||
                        body.amount_minor < 0 ||
                        body.amount_minor > 1000000000 ||
                        !/^[A-Z]{3}$/.test(body.currency) ||
                        !String(body.title || "").trim()
                    )
                        error(
                            "Enter a bounded amount, currency and title.",
                            422,
                        );
                    for (const field of [
                        "title",
                        "project_id",
                        "contractor_id",
                        "amount_minor",
                        "currency",
                        "document_reference",
                        "fund_code",
                        "restriction_reference",
                        "source_reference",
                    ])
                        row[field] = body[field];
                    row.next_owner_id = String(body.next_owner_id);
                    row.status = "draft";
                    row.review = null;
                    row.submission = null;
                } else if (action === "packet.submit") {
                    if (
                        !["draft", "needs_changes"].includes(row.status) ||
                        !row.document_reference ||
                        !row.fund_code ||
                        !row.source_reference
                    )
                        error(
                            "Provide document, fund and existing source references before submitting.",
                            422,
                        );
                    row.status = "submitted";
                    row.submission = {
                        by: currentId(),
                        at: now(),
                        reason: body.reason,
                    };
                } else if (action === "packet.review") {
                    if (
                        row.status !== "submitted" ||
                        row.next_owner_id !== currentId()
                    )
                        error(
                            "Only the assigned sample reviewer may decide the submitted packet.",
                            409,
                        );
                    if (!["approved", "needs_changes"].includes(body.decision))
                        error("Choose a sample review decision.", 422);
                    row.status = body.decision;
                    row.review = {
                        by: currentId(),
                        at: now(),
                        reason: body.reason,
                        decision: body.decision,
                        version_reviewed: row.version,
                    };
                } else if (action === "packet.handoff") {
                    if (String(body.next_owner_id) !== "1")
                        error(
                            "Choose an eligible sample financial reviewer.",
                            422,
                        );
                    row.next_owner_id = "1";
                } else if (action === "packet.reopen") {
                    if (
                        !["submitted", "approved", "needs_changes"].includes(
                            row.status,
                        )
                    )
                        error("This sample packet is already a draft.", 409);
                    row.status = "draft";
                    row.review = null;
                    row.submission = null;
                } else if (action === "packet.external_complete") {
                    if (
                        row.status !== "approved" ||
                        row.next_owner_id !== currentId() ||
                        !String(body.external_reference || "").trim()
                    )
                        error(
                            "Record an external reference only after assigned-owner internal review.",
                            409,
                        );
                    row.status = "externally_completed";
                    row.external_completion = {
                        by: currentId(),
                        at: now(),
                        reference: body.external_reference,
                        reason: body.reason,
                        verification:
                            "Manual reference only; no payment verified",
                    };
                } else error("Unsupported sample packet action.", 422);
                row.version++;
                row.updated_at = now();
                audit(row.id, "sample." + action, { status: row.status });
                return sampleFinance(row);
            }
            if (match) {
                const row = get(state.finance, match[1]);
                if (row.area_id !== selected)
                    error("Sample packet is outside this department.", 404);
                return match[2]
                    ? { items: clone(state.histories[row.id] || []) }
                    : sampleFinance(row);
            }
            return {
                items: state.finance
                    .filter((r) => r.area_id === selected)
                    .map(sampleFinance),
                projects: clone(
                    state.projects.filter(
                        (p) => p.enterprise_area_id === selected && !p.archived,
                    ),
                ),
                contractors: clone(
                    state.plans.filter(
                        (p) =>
                            p.area_id === selected &&
                            p.kind === "contractor" &&
                            p.status === "active",
                    ),
                ),
                approvers: [clone(members[0])],
                can_manage: !executive(),
                partial: false,
                has_more: false,
                as_of: now(),
                coverage:
                    "Fictional browser-only packets. No authoritative accounting, real invoices or payment connection.",
            };
        }
        function samplePeriod(query) {
            const selected = procedureArea(query),
                tz = query.get("timezone"),
                mode = query.get("mode");
            let start, end;
            try {
                new Intl.DateTimeFormat("en-US", { timeZone: tz }).format();
            } catch {
                error("Choose a valid reporting timezone.", 422);
            }
            const date = (value) => {
                if (
                    !/^\d{4}-\d{2}-\d{2}$/.test(value || "") ||
                    new Date(value + "T00:00:00Z")
                        .toISOString()
                        .slice(0, 10) !== value
                )
                    error("Choose valid sample dates.", 422);
                return new Date(value + "T00:00:00Z");
            };
            if (mode === "calendar_quarter") {
                const year = Number(query.get("year")),
                    q = Number(query.get("quarter"));
                if (
                    !Number.isInteger(year) ||
                    year < 2000 ||
                    year > 2100 ||
                    ![1, 2, 3, 4].includes(q)
                )
                    error("Choose a valid calendar quarter.", 422);
                start = new Date(Date.UTC(year, (q - 1) * 3, 1));
                end = new Date(Date.UTC(year, q * 3, 1));
            } else if (mode === "date_range") {
                start = date(query.get("start_date"));
                end = date(query.get("end_date"));
                end.setUTCDate(end.getUTCDate() + 1);
                if (end <= start || (end - start) / 86400000 > 366)
                    error(
                        "Choose an ordered sample window of at most 366 days.",
                        422,
                    );
            } else error("Choose a reporting window.", 422);
            // Convert the selected local midnight by Intl timezone offsets. This remains sample data, not a campus feed.
            const localMidnight = (value) => {
                let target = value.getTime();
                const parts = new Intl.DateTimeFormat("en-CA", {
                    timeZone: tz,
                    year: "numeric",
                    month: "2-digit",
                    day: "2-digit",
                    hour: "2-digit",
                    minute: "2-digit",
                    second: "2-digit",
                    hourCycle: "h23",
                });
                for (let i = 0; i < 3; i++) {
                    const p = Object.fromEntries(
                        parts
                            .formatToParts(new Date(target))
                            .filter((p) => p.type !== "literal")
                            .map((p) => [p.type, Number(p.value)]),
                    );
                    const projected = Date.UTC(
                        p.year,
                        p.month - 1,
                        p.day,
                        p.hour,
                        p.minute,
                        p.second,
                    );
                    target += value.getTime() - projected;
                }
                return new Date(target).toISOString();
            };
            const startUtc = localMidnight(start),
                endUtc = localMidnight(end),
                stamp = now(),
                projectFilter = query.get("project_id");
            if (
                projectFilter &&
                project(projectFilter).enterprise_area_id !== selected
            )
                error("Sample project is outside this department.", 404);
            const current = [],
                add = (type, row, title, status, owner, due) =>
                    current.push({
                        entity_type: type,
                        id: row.id,
                        project_id:
                            type === "project"
                                ? row.id
                                : row.project_id || row.data?.project_id,
                        title,
                        status,
                        owner_id: owner,
                        due_on: due,
                        updated_at: row.updated_at || null,
                        version: row.version,
                        source_url: recordUrl(
                            type === "request" ? "requests" : "projects",
                            type === "task" ? row.project_id : row.id,
                            selected,
                        ),
                    });
            for (const p of state.projects.filter(
                (p) =>
                    p.enterprise_area_id === selected &&
                    (!projectFilter || p.id === projectFilter),
            ))
                add(
                    "project",
                    p,
                    p.name,
                    p.archived ? "archived" : "active",
                    null,
                    null,
                );
            for (const t of state.tasks.filter(
                (t) =>
                    t.enterprise_area_id === selected &&
                    (!projectFilter || t.project_id === projectFilter),
            ))
                add(
                    "task",
                    t,
                    t.title,
                    t.completed_at ? "completed" : "active",
                    t.assignee_id,
                    t.due_on,
                );
            for (const r of state.requests.filter(
                (r) =>
                    r.area_id === selected &&
                    (!projectFilter || r.data.project_id === projectFilter),
            ))
                add(
                    "request",
                    r,
                    r.title,
                    r.status,
                    r.data.created_by,
                    r.due_on,
                );
            const recorded = [];
            for (const r of current)
                for (const event of state.histories[r.id] || [])
                    if (
                        event.at >= startUtc &&
                        event.at < endUtc &&
                        event.at <= stamp
                    )
                        recorded.push({
                            entity_type: r.entity_type,
                            id: r.id,
                            current_title: r.title,
                            action: event.event.action,
                            recorded_at: event.at,
                            version: event.version,
                            source_url: r.source_url,
                        });
            return {
                schema_version: 1,
                report_type: "period_activity",
                generated_at_utc: stamp,
                sample_data: true,
                label: "FICTIONAL BROWSER SAMPLE — operational period export",
                selection: {
                    area_id: selected,
                    project_id: projectFilter || null,
                },
                window: {
                    timezone: tz,
                    start_local: start.toISOString().slice(0, 10),
                    end_local_inclusive: new Date(end - 86400000)
                        .toISOString()
                        .slice(0, 10),
                    start_utc: startUtc,
                    end_utc_exclusive: endUtc,
                    observed_through_utc: endUtc < stamp ? endUtc : stamp,
                    period_in_progress: startUtc <= stamp && endUtc > stamp,
                    period_state:
                        startUtc > stamp
                            ? "future"
                            : endUtc > stamp
                              ? "in_progress"
                              : "closed",
                },
                current_records: current,
                recorded_events: recorded,
                coverage: {
                    current_records: {
                        returned: current.length,
                        omitted_visible_candidates: 0,
                        limits: { project: 100, task: 500, request: 200 },
                    },
                    recorded_events: {
                        returned: recorded.length,
                        omitted_matching_events: 0,
                        limit: 1000,
                    },
                },
                exclusions: [
                    "All real college records and live feeds",
                    "Invoice, fund, insurance and document contents",
                    "Private notes and mailbox data",
                ],
                snapshot_notice:
                    "Fictional browser-only snapshot; download again to refresh. Not an approved quarterly template or historical reconstruction.",
            };
        }
        async function handle(path, query, body, options) {
            if (path.startsWith("/enterprise/certificates"))
                return certificateRequest(path, query, body);
            if (path.startsWith("/enterprise/finance"))
                return financeRequest(path, query, body);
            if (path.startsWith("/enterprise/reports/quarterly")) {
                const report = samplePeriod(query);
                if (path.endsWith(".json"))
                    return {
                        sample_export: true,
                        mime: "application/json",
                        content: JSON.stringify(report, null, 2),
                    };
                if (path.endsWith(".csv")) {
                    const csv = (value) =>
                        '"' + String(value ?? "").replace(/"/g, '""') + '"';
                    const rows = [
                        [
                            "sample_data",
                            "generated_at",
                            "window_start",
                            "window_end",
                            "type",
                            "title",
                            "status",
                        ],
                        ...report.current_records.map((r) => [
                            "FICTIONAL BROWSER SAMPLE",
                            report.generated_at_utc,
                            report.window.start_local,
                            report.window.end_local_inclusive,
                            r.entity_type,
                            /^[\s\u0000-\u001f]*[=+@-]/u.test(r.title)
                                ? "'" + r.title
                                : r.title,
                            r.status,
                        ]),
                    ];
                    return {
                        sample_export: true,
                        mime: "text/csv",
                        content: rows
                            .map((r) => r.map(csv).join(","))
                            .join("\r\n"),
                    };
                }
                return report;
            }
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
                requestView(get(state.requests, history[1]));
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
                        can_coordinate: manager() && !executive(),
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
                    can_coordinate: manager() && !executive(),
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
                    can_manage: manager() && !executive(),
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
                if (path === "/enterprise/voice/jobs") {
                    if (String(options.method || (body !== undefined ? "POST" : "GET")).toUpperCase() !== "POST" || options.binary)
                        error("Use the explicit read-only sample voice lookup POST.", 405);
                    return clone(voiceJobs(body));
                }
                if (path === "/enterprise/ai-usage") {
                    if (body !== undefined || String(options.method || "GET").toUpperCase() !== "GET")
                        error("Sample usage accepts read-only GET only.", 405);
                    return clone(sampleUsage(query));
                }
                const mutating = body !== undefined || (options.method && !["GET", "HEAD"].includes(options.method.toUpperCase()));
                if (readOnlyExecutive() && mutating)
                    error("The Executive sample perspective is read-only. Nothing was changed.", 403);
                if (
                    demoManager() &&
                    !((path==='/enterprise/commands'&&['job.create','project.create','project.update','project.archive','task.create','task.update','task.delete','request.create','request.classify','request.triage','request.assign','request.progress','request.complete','request.plan'].includes(body?.action))||path==='/enterprise/preferences'||/^\/enterprise\/discussion\/(project|request|task)\/[^/]+(?:\/(open|comments))?$/.test(path)||/^\/team\/tasks\/[^/]+\/files$/.test(path)||/^\/team\/file-uploads\/[^/]+\/(chunks\/\d+|complete|cancel)$/.test(path)||/^\/enterprise\/files\/[^/]+\/caption$/.test(path)) &&
                    mutating
                )
                    error(
                        "This action is outside the demo manager’s permitted work controls. No real administrator access is granted.",
                        403,
                    );
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
            demoContext, demoEmail, demoDeliver,
            demoOutbox() { demoContext(IDS.area); return clone([...demoSent.values()]); },
            subscribeDemo(fn) { if (typeof fn !== "function") error("Invalid demo observer."); demoListeners.add(fn); return () => demoListeners.delete(fn); },
            href,
            navigate,
            replace,
            snapshot: () => clone(state),
            setPersona(value) {
                if (
                    !["manager", "worker", "requester", "executive", ...(boot.defaultPersona === "demo_manager" ? ["demo_manager"] : [])].includes(
                        value,
                    )
                )
                    error("Choose a sample perspective.");
                retireDemo();
                state.persona = value;
                save();
            },
            reset() {
                retireDemo();
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
                if (["executive", "demo_manager"].includes(boot.defaultPersona))
                    state.persona = boot.defaultPersona;
                if (boot.defaultPersona === "demo_manager") {
                    state.sample_migrations ??= {};
                    state.sample_migrations["blaine-demo-manager-v1"] = { applied_at: now() };
                }
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
