import React, { useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import {
    ReactFlow,
    Background,
    Controls,
    MiniMap,
    applyNodeChanges,
    type Node,
    type Edge,
} from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import "./workspace.css";
import "./transport.js";

type Field = {
    key: string;
    label: string;
    type: string;
    required: boolean;
    options?: string[];
};
type Step = {
    id: string;
    type: string;
    label: string;
    position: { x: number; y: number };
    screen?: { title: string; description: string; fields: Field[] };
    assignee_id?: string;
    task_id?: string;
    condition?: { field: string; operator: string; value: string | boolean };
};
type Definition = {
    schema_version?: 1;
    nodes: Step[];
    edges: { id: string; source: string; target: string; branch?: string }[];
};
const mount = document.getElementById("enterprise-workflow");
if (mount)
    createRoot(mount).render(
        <Workspace boot={JSON.parse(mount.dataset.bootstrap!)} />,
    );

function Workspace({ boot }: { boot: any }) {
    const [transport] = useState(() =>
        (globalThis as any).EverbranchTransport.create({
            ...boot,
            identity: boot.identity ?? {tenantSlug: boot.tenant, viewer: boot.user_id, workspace: boot.tenant_id},
        }),
    );
    const incoming = new URL(location.href);
    if (transport.mode === 'sample') {
        incoming.search = incoming.hash.slice(1);
        incoming.hash = '';
    }
    for (const key of ['tab', 'workflow', 'run']) {
        if (!incoming.searchParams.has(key) && boot.navigation?.[key]) incoming.searchParams.set(key, boot.navigation[key]);
    }
    const tabs = ["canvas", "screens", "runs", "settings"];
    const [tab, setTab] = useState(
        tabs.includes(incoming.searchParams.get("tab") || "")
            ? incoming.searchParams.get("tab")!
            : "canvas",
    );
    const [list, setList] = useState<any>(boot.initial),
        [record, setRecord] = useState<any>(null);
    const [id, setId] = useState<string | null>(
        incoming.searchParams.get("workflow"),
    );
    const [name, setName] = useState("New campus workflow"),
        [def, setDef] = useState<Definition>({ nodes: [], edges: [] });
    const [selected, setSelected] = useState<string | null>(null),
        [preview, setPreview] = useState<any>(null);
    const [run, setRun] = useState<any>(null),
        [values, setValues] = useState<Record<string, any>>({});
    const [prompt, setPrompt] = useState(""),
        [notice, setNotice] = useState(""),
        [busy, setBusy] = useState(false),
        [dirty, setDirty] = useState(false);
    const [listMode, setListMode] = useState(false),
        [restored, setRestored] = useState(false);
    const pending = useRef<{ path: string; body: any } | null>(null),
        generation = useRef(0),
        liveDef = useRef(def);
    const scope = boot.scope;
    const base = boot.routes?.workflows ?? "/enterprise/workflows";
    const key = `enterprise-workflow:${transport.mode === 'sample' ? 'sample:' : ''}${boot.tenant_id}:${boot.user_id}:${scope.area_id}:${scope.project_id}`;
    const canDesign = record?.can_design ?? list.can_design;
    const members = record?.members ?? list.members ?? [];
    const tasks = record?.tasks ?? list.tasks ?? [];
    const operationsEnabled = record?.operations_enabled ?? list.operations_enabled ?? false;
    const operationsBase = boot.routes?.operations ?? "/enterprise/operations";
    const chosen = def.nodes.find((n) => n.id === selected);
    const chosenTask = tasks.find((task: any) => task.id === chosen?.task_id);
    const memberName = (userId: string | null) => userId ? members.find((member: any) => member.id === userId)?.name ?? `Account ${userId}` : "Owning department";
    liveDef.current = def;
    async function api(path: string, body?: any) {
        const url = new URL(path, location.origin);
        url.searchParams.set("tenant", boot.tenant);
        try {
            return await transport.request(url.href, body);
        } catch (e: any) {
            if ([403, 404].includes(e.status)) {
                ++generation.current;
                setRecord(null);
                setRun(null);
                setValues({});
                setDef({ nodes: [], edges: [] });
                setList({
                    ...boot.initial,
                    items: [],
                    can_design: false,
                    can_run: false,
                    members: [],
                });
            }
            throw e;
        }
    }
    const query = `?area_id=${encodeURIComponent(scope.area_id)}&project_id=${encodeURIComponent(scope.project_id)}`;
    async function refresh(nextId: string | null = id, restore = false) {
        const token = ++generation.current;
        try {
            const index = await api(base + query);
            if (token !== generation.current) return;
            setList(index);
            let row: any = null;
            if (nextId) {
                row = await api(`${base}/${encodeURIComponent(nextId)}/data`);
                if (token !== generation.current) return;
                if (
                    row.workflow.area_id !== scope.area_id ||
                    row.workflow.project_id !== scope.project_id
                )
                    throw new Error(
                        "This workflow belongs to a different project. Return to its authorized project.",
                    );
                setRecord(row);
                setId(nextId);
                setName(row.workflow.name);
                setDef(row.workflow.definition);
                setDirty(false);
                if (restore && incoming.searchParams.get("run"))
                    setRun(
                        row.runs.find(
                            (r: any) =>
                                r.id === incoming.searchParams.get("run"),
                        ) || null,
                    );
            }
            if (restore && index.can_design) {
                try {
                    setPrompt(
                        sessionStorage.getItem(key + ":prompt") ||
                            JSON.parse(
                                sessionStorage.getItem(key + ":proposal") ||
                                    "null",
                            )?.prompt ||
                            "",
                    );
                } catch {}
                let saved: any;
                try {
                    saved = JSON.parse(sessionStorage.getItem(key) || "null");
                } catch {}
                if (
                    saved &&
                    saved.id === nextId &&
                    saved.definition?.nodes &&
                    (!row || saved.version === row.workflow.version)
                ) {
                    setName(saved.name);
                    setDef(saved.definition);
                    setDirty(true);
                    setRestored(true);
                    pending.current = saved.pending || null;
                }
            }
            if (restore && index.can_run) {
                let operation: any;
                try {
                    operation = JSON.parse(
                        sessionStorage.getItem(key + ":operation") || "null",
                    );
                } catch {}
                if (
                    operation &&
                    (operation.path === base || operation.path === operationsBase + "/commands" ||
                        new RegExp(
                            "^" +
                                base +
                                "/[a-f0-9-]{36}/(activate|pause|runs(/[a-f0-9-]{36}/advance)?)$",
                        ).test(operation.path))
                ) {
                    pending.current = operation;
                    setNotice(
                        "An interrupted operation is retained. Retry it once to check its recorded result.",
                    );
                }
            }
        } catch (e: any) {
            if (token !== generation.current) return;
            setRecord(null);
            setDef({ nodes: [], edges: [] });
            setList({
                ...boot.initial,
                items: [],
                can_design: false,
                can_run: false,
            });
            setNotice(e.message);
        }
    }
    useEffect(() => {
        refresh(id, true);
    }, []);
    useEffect(() => {
        const url = new URL(location.href);
        url.searchParams.set("tab", tab);
        if (id) url.searchParams.set("workflow", id);
        else url.searchParams.delete("workflow");
        if (run) url.searchParams.set("run", run.id);
        else url.searchParams.delete("run");
        transport.replace(url);
    }, [tab, id, run?.id]);
    useEffect(() => {
        if (!run) return;
        let draft: any;
        try {
            draft = JSON.parse(
                sessionStorage.getItem(key + ":run:" + run.id) || "null",
            );
        } catch {}
        setValues(
            run.can_advance && draft?.version === run.version
                ? draft.values
                : {},
        );
    }, [run?.id, run?.version]);
    useEffect(() => {
        const resize = () => {
            if (document.activeElement?.matches("textarea,input,select"))
                document.activeElement.scrollIntoView({ block: "nearest" });
        };
        window.visualViewport?.addEventListener("resize", resize);
        return () =>
            window.visualViewport?.removeEventListener("resize", resize);
    }, []);
    useEffect(() => {
        if (canDesign && dirty)
            try {
                sessionStorage.setItem(
                    key,
                    JSON.stringify({
                        id,
                        name,
                        definition: def,
                        version: record?.workflow.version,
                        pending: pending.current,
                    }),
                );
            } catch {
                setNotice(
                    "This browser cannot retain local drafts. Save before leaving.",
                );
            }
    }, [def, name, dirty, id, record, canDesign]);
    useEffect(() => {
        if (canDesign && prompt)
            try {
                sessionStorage.setItem(key + ":prompt", prompt);
            } catch {}
    }, [prompt, canDesign]);
    useEffect(() => {
        const warn = (e: BeforeUnloadEvent) => {
            if (dirty) {
                e.preventDefault();
                e.returnValue = "";
            }
        };
        window.addEventListener("beforeunload", warn);
        return () => window.removeEventListener("beforeunload", warn);
    }, [dirty]);
    function edit(next: Definition) {
        if (!canDesign || busy || pending.current) return;
        setDef(next);
        setDirty(true);
        setPreview(null);
    }
    function patchStep(patch: Partial<Step>) {
        if (chosen)
            edit({
                ...def,
                nodes: def.nodes.map((n) =>
                    n.id === chosen.id ? { ...n, ...patch } : n,
                ),
            });
    }
    async function mutate(
        path: string,
        body: any,
        done?: (x: any) => Promise<void> | void,
    ) {
        if (busy) return;
        if (
            pending.current &&
            JSON.stringify(pending.current) !== JSON.stringify({ path, body })
        ) {
            setNotice(
                "Resolve the retained operation before making another change.",
            );
            return;
        }
        pending.current = { path, body };
        setBusy(true);
        setNotice("Saving…");
        try {
            sessionStorage.setItem(
                key + ":operation",
                JSON.stringify(pending.current),
            );
            if (dirty)
                sessionStorage.setItem(
                    key,
                    JSON.stringify({
                        id,
                        name,
                        definition: def,
                        version: record?.workflow.version,
                        pending: pending.current,
                    }),
                );
        } catch {}
        try {
            const x = await api(path, body);
            pending.current = null;
            setRestored(false);
            try {
                sessionStorage.removeItem(key);
                sessionStorage.removeItem(key + ":operation");
            } catch {}
            setNotice("Saved.");
            await done?.(x);
        } catch (e: any) {
            if ([403, 404, 409, 422].includes(e.status)) {
                pending.current = null;
                try {
                    sessionStorage.removeItem(key + ":operation");
                } catch {}
            }
            setNotice(
                e.message +
                    (pending.current
                        ? " The exact operation is retained. Retry it to confirm the result."
                        : ""),
            );
        } finally {
            setBusy(false);
        }
    }
    async function refreshSelectedRun(workflowId: string | null = id, runId: string | null = run?.id ?? null) {
        if (!workflowId) return;
        const token = ++generation.current;
        const latest = await api(`${base}/${encodeURIComponent(workflowId)}/data`);
        if (token !== generation.current) return;
        if (latest.workflow.area_id !== scope.area_id || latest.workflow.project_id !== scope.project_id) throw new Error("This workflow belongs to a different project.");
        setRecord(latest);
        setRun(latest.runs.find((item: any) => item.id === runId) ?? null);
    }
    async function save() {
        const nextId = id || crypto.randomUUID();
        await mutate(
            base,
            {
                id: nextId,
                request_id: crypto.randomUUID(),
                ...scope,
                expected_version: record?.workflow.version || 0,
                name,
                definition: { ...def, schema_version: 1 },
            },
            async () => {
                setDirty(false);
                setRestored(false);
                await refresh(nextId);
            },
        );
    }
    function newDraft(template?: any) {
        if (dirty || pending.current) {
            setNotice("Save or explicitly discard the current draft first.");
            return;
        }
        setId(null);
        setRecord(null);
        setName(template?.name || "New campus workflow");
        setDef(
            template?.definition || {
                nodes: [
                    {
                        id: "start",
                        type: "start",
                        label: "Start",
                        position: { x: 120, y: 40 },
                    },
                    {
                        id: "complete",
                        type: "complete",
                        label: "Complete",
                        position: { x: 120, y: 220 },
                    },
                ],
                edges: [
                    {
                        id: "start-complete",
                        source: "start",
                        target: "complete",
                        branch: "always",
                    },
                ],
            },
        );
        setSelected(null);
        setPreview(null);
        setRun(null);
        setDirty(true);
        setTab("canvas");
        setNotice(
            template
                ? "Illustrative template loaded. Review every step before saving."
                : "Draft created locally.",
        );
    }
    async function propose() {
        if (dirty || pending.current) {
            setNotice(
                "Save or discard the current draft before requesting a replacement proposal.",
            );
            return;
        }
        let retained: any;
        try {
            retained = JSON.parse(
                sessionStorage.getItem(key + ":proposal") || "null",
            );
        } catch {}
        if (retained && retained.prompt !== prompt) {
            setNotice(
                "Retry the retained proposal text before requesting a different draft. An unknown provider outcome needs reconciliation.",
            );
            return;
        }
        const body = retained || {
            ...scope,
            prompt,
            request_id: crypto.randomUUID(),
        };
        try {
            sessionStorage.setItem(key + ":proposal", JSON.stringify(body));
        } catch {}
        setBusy(true);
        setNotice("Requesting a validated proposal…");
        try {
            const out = await api(base + "/propose", body);
            try {
                sessionStorage.removeItem(key + ":proposal");
            } catch {}
            setDef(out.definition);
            setName(out.name || "Proposed campus workflow");
            setId(null);
            setRecord(null);
            setDirty(true);
            setPreview(null);
            setNotice(
                out.model_status ||
                    "AI proposal loaded as an unsaved draft. Review screens, conditions and assignments before saving.",
            );
        } catch (e: any) {
            setNotice(e.message);
        } finally {
            setBusy(false);
        }
    }
    async function checkPreview() {
        setBusy(true);
        try {
            const p = await api(base + "/preview", {
                ...scope,
                definition: { ...def, schema_version: 1 },
                values: {},
            });
            setPreview(p);
            setNotice("Preview checked. No run or task was created.");
        } catch (e: any) {
            setNotice(e.message);
        } finally {
            setBusy(false);
        }
    }
    function addStep(type: string) {
        const node: Step = {
            id: "n" + crypto.randomUUID(),
            type,
            label: type.charAt(0).toUpperCase() + type.slice(1),
            position: {
                x: 120 + (def.nodes.length % 2) * 300,
                y: 80 + def.nodes.length * 130,
            },
        };
        if (["form", "assignment", "approval"].includes(type))
            node.screen = { title: node.label, description: "", fields: [] };
        if (type === "condition")
            node.condition = {
                field: "priority",
                operator: "equals",
                value: "urgent",
            };
        edit({ ...def, nodes: [...def.nodes, node] });
        setSelected(node.id);
    }
    function updateField(index: number, patch: Partial<Field>) {
        if (chosen?.screen)
            patchStep({
                screen: {
                    ...chosen.screen,
                    fields: chosen.screen.fields.map((f, i) => {
                        if (i !== index) return f;
                        const next = { ...f, ...patch };
                        if (next.type !== "select") delete next.options;
                        return next;
                    }),
                },
            });
    }
    const runNode = run?.current_node;
    const status = record?.workflow.status || "unsaved";
    return (
        <div className="ew-shell">
            <aside className="ew-sidebar">
                <a className="ew-brand" href={transport.href(boot.back)}>
                    <span aria-hidden="true">♧</span>
                    <span>
                        Everbranch<small>Enterprise · Workflows</small>
                    </span>
                </a>
                <p className="ew-eyebrow">OPERATING WORKSPACE</p>
                <nav aria-label="Workflow workspace">
                    {tabs.map((t) => (
                        <button
                            key={t}
                            aria-current={tab === t ? "page" : undefined}
                            onClick={() => setTab(t)}
                        >
                            {
                                {
                                    canvas: "◇ Canvas",
                                    screens: "▤ Screens & forms",
                                    runs: "▷ Runs & history",
                                    settings: "⚙ Settings",
                                }[t]
                            }
                        </button>
                    ))}
                </nav>
                <div className="ew-library">
                    <p className="ew-eyebrow">PROJECT WORKFLOWS</p>
                    {(list.items || []).map((w: any) => (
                        <button
                            key={w.id}
                            aria-current={id === w.id ? "page" : undefined}
                            onClick={() => {
                                if (dirty || pending.current)
                                    return setNotice(
                                        "Save or discard your current draft before switching workflows.",
                                    );
                                setRun(null);
                                setPreview(null);
                                refresh(w.id);
                            }}
                        >
                            {w.name}
                            <small>{w.status}</small>
                        </button>
                    ))}
                    {canDesign && (
                        <button onClick={() => newDraft()}>
                            + New workflow
                        </button>
                    )}
                </div>
                <a className="ew-back" href={transport.href(boot.back)}>
                    ← Back to Projects
                </a>
            </aside>
            <main className="ew-main">
                <header className="ew-header">
                    <div>
                        <p className="ew-eyebrow">
                            {list.project?.name || "Project"} / {tab}
                        </p>
                        <h1>{name}</h1>
                        <p className="ew-subtle">
                            Design the handoff. See every decision.
                        </p>
                        {transport.mode === "sample" && (
                            <p className="ew-subtle" role="note">
                                Sample execution — browser records, not the server-backed workflow engine
                            </p>
                        )}
                    </div>
                    <div className="ew-actions">
                        <span className="ew-badge">
                            {status}
                            {dirty ? " · unsaved" : ""}
                        </span>
                        {canDesign && (
                            <>
                                <button
                                    disabled={
                                        busy ||
                                        !def.nodes.length ||
                                        !!pending.current
                                    }
                                    onClick={checkPreview}
                                >
                                    Preview
                                </button>
                                <button
                                    className="ew-primary"
                                    disabled={
                                        busy || !dirty || !!pending.current
                                    }
                                    onClick={save}
                                >
                                    Save draft
                                </button>
                            </>
                        )}
                    </div>
                </header>
                <div className="ew-notice" role="status" aria-live="polite">
                    {notice ||
                        "Internal workflow steps only. External delivery remains subject to its own connection and confirmation."}
                    {restored && (
                        <span>
                            {" "}
                            Your unsaved draft was restored for this project.
                        </span>
                    )}
                    {pending.current && (
                        <button
                            disabled={busy}
                            onClick={() => {
                                const p = pending.current!;
                                mutate(p.path, p.body, async () => {
                                    if (p.path === operationsBase + "/commands") {
                                        await refreshSelectedRun(id, run?.id ?? incoming.searchParams.get("run"));
                                        return;
                                    }
                                    setDirty(false);
                                    const workflowId =
                                        p.path === base
                                            ? p.body.id
                                            : p.path
                                                  .slice(base.length + 1)
                                                  .split("/")[0];
                                    await refresh(workflowId);
                                    if (
                                        p.path.endsWith("/advance") ||
                                        p.path.endsWith("/runs")
                                    ) {
                                        const latest = await api(
                                            `${base}/${workflowId}/data`,
                                        );
                                        setRun(
                                            latest.runs.find(
                                                (r: any) =>
                                                    r.id ===
                                                    (p.body.id ||
                                                        p.path
                                                            .split("/")
                                                            .at(-2)),
                                            ) || null,
                                        );
                                    }
                                });
                            }}
                        >
                            Retry exact operation
                        </button>
                    )}
                </div>
                {!def.nodes.length ? (
                    <section className="ew-welcome">
                        <p className="ew-eyebrow">CONNECTED CAMPUS WORK</p>
                        <h2>From a request to a clear next step.</h2>
                        <p>
                            Create a workflow, build the screens staff will use,
                            and review each handoff before activation.
                        </p>
                        {canDesign && (
                            <>
                                <button
                                    className="ew-primary"
                                    onClick={() => newDraft()}
                                >
                                    Create workflow
                                </button>
                                {(list.templates || []).map((t: any) => (
                                    <button
                                        key={t.key || t.name}
                                        onClick={() => newDraft(t)}
                                    >
                                        Use {t.name}
                                    </button>
                                ))}
                                <label>
                                    Describe the workflow
                                    <textarea
                                        value={prompt}
                                        onChange={(e) =>
                                            setPrompt(e.target.value)
                                        }
                                        placeholder="Collect a maintenance request, review urgency, assign an existing team member, then request approval."
                                        maxLength={2000}
                                    />
                                </label>
                                <button
                                    disabled={busy || !prompt.trim()}
                                    onClick={propose}
                                >
                                    Ask Everbranch for a draft
                                </button>
                            </>
                        )}
                    </section>
                ) : (
                    <>
                        {tab === "canvas" && (
                            <div className="ew-designer">
                                <section className="ew-canvas-section">
                                    <div className="ew-toolbar">
                                        <div className="ew-actions">
                                            <button
                                                aria-pressed={!listMode}
                                                onClick={() =>
                                                    setListMode(false)
                                                }
                                            >
                                                Canvas
                                            </button>
                                            <button
                                                aria-pressed={listMode}
                                                onClick={() =>
                                                    setListMode(true)
                                                }
                                            >
                                                Accessible list
                                            </button>
                                        </div>
                                        {canDesign && (
                                            <label>
                                                Add step
                                                <select
                                                    aria-label="Add step"
                                                    value=""
                                                    disabled={
                                                        busy ||
                                                        !!pending.current
                                                    }
                                                    onChange={(e) =>
                                                        addStep(e.target.value)
                                                    }
                                                >
                                                    <option value="">
                                                        Choose type…
                                                    </option>
                                                    {[
                                                        "form",
                                                        "condition",
                                                        "assignment",
                                                        "approval",
                                                        "complete",
                                                    ].map((t) => (
                                                        <option key={t}>
                                                            {t}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                        )}
                                    </div>
                                    {listMode ? (
                                        <ol className="ew-step-list">
                                            {def.nodes.map((n) => (
                                                <li key={n.id}>
                                                    <button
                                                        onClick={() =>
                                                            setSelected(n.id)
                                                        }
                                                        aria-pressed={
                                                            selected === n.id
                                                        }
                                                    >
                                                        {n.label}{" "}
                                                        <small>{n.type}</small>
                                                    </button>
                                                    <p>
                                                        {def.edges
                                                            .filter(
                                                                (e) =>
                                                                    e.source ===
                                                                    n.id,
                                                            )
                                                            .map(
                                                                (e) =>
                                                                    `${e.branch || "always"} → ${def.nodes.find((n) => n.id === e.target)?.label || e.target}`,
                                                            )
                                                            .join(" · ") ||
                                                            "No next step"}
                                                    </p>
                                                </li>
                                            ))}
                                        </ol>
                                    ) : (
                                        <div className="ew-canvas">
                                            <ReactFlow
                                                nodes={
                                                    def.nodes.map((n) => ({
                                                        id: n.id,
                                                        position: n.position,
                                                        data: {
                                                            label: (
                                                                <>
                                                                    <small>
                                                                        {n.type}
                                                                    </small>
                                                                    <strong>
                                                                        {
                                                                            n.label
                                                                        }
                                                                    </strong>
                                                                </>
                                                            ),
                                                        },
                                                        selected:
                                                            selected === n.id,
                                                        type:
                                                            n.type === "start"
                                                                ? "input"
                                                                : n.type ===
                                                                    "complete"
                                                                  ? "output"
                                                                  : "default",
                                                    })) as Node[]
                                                }
                                                edges={
                                                    def.edges.map((e) => ({
                                                        ...e,
                                                        label:
                                                            e.branch ||
                                                            "always",
                                                        type: "smoothstep",
                                                    })) as Edge[]
                                                }
                                                fitView
                                                minZoom={0.25}
                                                maxZoom={1.5}
                                                nodesDraggable={
                                                    !!canDesign &&
                                                    !busy &&
                                                    !pending.current
                                                }
                                                nodesConnectable={
                                                    !!canDesign &&
                                                    !busy &&
                                                    !pending.current
                                                }
                                                elementsSelectable
                                                onNodeClick={(_, n) =>
                                                    setSelected(n.id)
                                                }
                                                onPaneClick={() =>
                                                    setSelected(null)
                                                }
                                                onNodesChange={(changes) => {
                                                    const positioned =
                                                        applyNodeChanges(
                                                            changes,
                                                            def.nodes.map(
                                                                (n) => ({
                                                                    id: n.id,
                                                                    position:
                                                                        n.position,
                                                                    data: {},
                                                                }),
                                                            ),
                                                        );
                                                    if (
                                                        changes.some(
                                                            (c) =>
                                                                c.type ===
                                                                    "position" &&
                                                                c.position,
                                                        )
                                                    )
                                                        edit({
                                                            ...liveDef.current,
                                                            nodes: liveDef.current.nodes.map(
                                                                (n) => ({
                                                                    ...n,
                                                                    position:
                                                                        positioned.find(
                                                                            (
                                                                                p,
                                                                            ) =>
                                                                                p.id ===
                                                                                n.id,
                                                                        )
                                                                            ?.position ||
                                                                        n.position,
                                                                }),
                                                            ),
                                                        });
                                                }}
                                                onConnect={(c) => {
                                                    if (c.source && c.target)
                                                        edit({
                                                            ...def,
                                                            edges: [
                                                                ...def.edges,
                                                                {
                                                                    id:
                                                                        "e" +
                                                                        crypto.randomUUID(),
                                                                    source: c.source,
                                                                    target: c.target,
                                                                    branch: "always",
                                                                },
                                                            ],
                                                        });
                                                }}
                                                deleteKeyCode={null}
                                            >
                                                <Background
                                                    color="#d8dfd1"
                                                    gap={24}
                                                />
                                                <Controls
                                                    showInteractive={false}
                                                />
                                                <MiniMap
                                                    pannable
                                                    zoomable
                                                    nodeColor="#315e49"
                                                />
                                            </ReactFlow>
                                        </div>
                                    )}
                                </section>
                                <aside className="ew-inspector">
                                    <p className="ew-eyebrow">STEP INSPECTOR</p>
                                    {chosen ? (
                                        <>
                                            <h2>{chosen.label}</h2>
                                            <label>
                                                Step name
                                                <input
                                                    value={chosen.label}
                                                    disabled={
                                                        !canDesign || busy
                                                    }
                                                    maxLength={100}
                                                    onChange={(e) =>
                                                        patchStep({
                                                            label: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <p className="ew-subtle">
                                                {chosen.type} · {chosen.id}
                                            </p>
                                            {[
                                                "assignment",
                                                "approval",
                                            ].includes(chosen.type) && (
                                                <>
                                                <label>
                                                    {chosen.type === "approval"
                                                        ? "Existing approver"
                                                        : "Existing assignee"}
                                                    <select
                                                        value={
                                                            chosen.assignee_id ||
                                                            ""
                                                        }
                                                        disabled={
                                                            !canDesign || busy
                                                        }
                                                        onChange={(e) =>
                                                            patchStep({
                                                                assignee_id:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                    >
                                                        <option value="">
                                                            Choose an authorized
                                                            member
                                                        </option>
                                                        {members
                                                            .filter(
                                                                (m: any) =>
                                                                    chosen.type !==
                                                                        "approval" ||
                                                                    m.can_approve,
                                                            )
                                                            .filter((m: any) => chosen.type !== "assignment" || !chosenTask || m.id === chosenTask.ball_user_id)
                                                            .map((m: any) => (
                                                                <option
                                                                    key={m.id}
                                                                    value={m.id}
                                                                >
                                                                    {m.name}
                                                                </option>
                                                            ))}
                                                    </select>
                                                </label>
                                                {(operationsEnabled || chosen.task_id) && <>
                                                    <label>Canonical project task
                                                        <select aria-label="Canonical project task" value={chosen.task_id ?? ""} disabled={!canDesign || busy || !!pending.current || !operationsEnabled} onChange={event => {
                                                            const task = tasks.find((item: any) => item.id === event.target.value);
                                                            patchStep({task_id: task?.id, ...(chosen.type === "assignment" && task?.ball_user_id ? {assignee_id: task.ball_user_id} : {})});
                                                        }}>
                                                            <option value="">Choose an existing task</option>
                                                            {tasks.map((task: any) => <option key={task.id} value={task.id}>{task.title}{task.version === 0 ? " (setup required)" : ""}</option>)}
                                                        </select>
                                                    </label>
                                                    <p className="ew-subtle">{chosenTask ? `Current ball: ${memberName(chosenTask.ball_user_id)}. Native version ${chosenTask.task_version}; operations version ${chosenTask.version}.` : "Map this step to an existing configured task before activation. Selecting a task grants no access and creates no task."}</p>
                                                </>}
                                                </>
                                            )}
                                            {chosen.type === "condition" && (
                                                <>
                                                    <label>
                                                        Field key
                                                        <input
                                                            value={
                                                                chosen.condition
                                                                    ?.field ||
                                                                ""
                                                            }
                                                            disabled={
                                                                !canDesign
                                                            }
                                                            onChange={(e) =>
                                                                patchStep({
                                                                    condition: {
                                                                        field: e
                                                                            .target
                                                                            .value,
                                                                        operator:
                                                                            chosen
                                                                                .condition
                                                                                ?.operator ||
                                                                            "equals",
                                                                        value:
                                                                            chosen
                                                                                .condition
                                                                                ?.value ||
                                                                            "",
                                                                    },
                                                                })
                                                            }
                                                        />
                                                    </label>
                                                    <label>
                                                        Operator
                                                        <select
                                                            value={
                                                                chosen.condition
                                                                    ?.operator ||
                                                                "equals"
                                                            }
                                                            disabled={
                                                                !canDesign
                                                            }
                                                            onChange={(e) =>
                                                                patchStep({
                                                                    condition: {
                                                                        ...chosen.condition!,
                                                                        operator:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                })
                                                            }
                                                        >
                                                            <option value="equals">
                                                                equals
                                                            </option>
                                                            <option value="not_equals">
                                                                does not equal
                                                            </option>
                                                        </select>
                                                    </label>
                                                    <label>
                                                        Value
                                                        <input
                                                            value={String(
                                                                chosen.condition
                                                                    ?.value ??
                                                                    "",
                                                            )}
                                                            disabled={
                                                                !canDesign
                                                            }
                                                            onChange={(e) =>
                                                                patchStep({
                                                                    condition: {
                                                                        ...chosen.condition!,
                                                                        value: e
                                                                            .target
                                                                            .value,
                                                                    },
                                                                })
                                                            }
                                                        />
                                                    </label>
                                                </>
                                            )}
                                            {chosen.screen && (
                                                <button
                                                    onClick={() =>
                                                        setTab("screens")
                                                    }
                                                >
                                                    Build this step’s screen →
                                                </button>
                                            )}
                                            <h3>Connections</h3>
                                            {def.edges
                                                .filter(
                                                    (e) =>
                                                        e.source === chosen.id,
                                                )
                                                .map((edge) => (
                                                    <div
                                                        className="ew-edge"
                                                        key={edge.id}
                                                    >
                                                        <span>
                                                            →{" "}
                                                            {
                                                                def.nodes.find(
                                                                    (n) =>
                                                                        n.id ===
                                                                        edge.target,
                                                                )?.label
                                                            }
                                                        </span>
                                                        <select
                                                            aria-label="Connection branch"
                                                            disabled={
                                                                !canDesign
                                                            }
                                                            value={
                                                                edge.branch ||
                                                                "always"
                                                            }
                                                            onChange={(e) =>
                                                                edit({
                                                                    ...def,
                                                                    edges: def.edges.map(
                                                                        (x) =>
                                                                            x.id ===
                                                                            edge.id
                                                                                ? {
                                                                                      ...x,
                                                                                      branch: e
                                                                                          .target
                                                                                          .value,
                                                                                  }
                                                                                : x,
                                                                    ),
                                                                })
                                                            }
                                                        >
                                                            {(chosen.type ===
                                                            "condition"
                                                                ? ["yes", "no"]
                                                                : ["always"]
                                                            ).map((b) => (
                                                                <option key={b}>
                                                                    {b}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {canDesign && (
                                                            <button
                                                                aria-label="Remove connection"
                                                                onClick={() =>
                                                                    edit({
                                                                        ...def,
                                                                        edges: def.edges.filter(
                                                                            (
                                                                                e,
                                                                            ) =>
                                                                                e.id !==
                                                                                edge.id,
                                                                        ),
                                                                    })
                                                                }
                                                            >
                                                                ×
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}
                                            {canDesign &&
                                                chosen.type !== "complete" && (
                                                    <label>
                                                        Connect next step
                                                        <select
                                                            value=""
                                                            onChange={(e) => {
                                                                if (
                                                                    e.target
                                                                        .value
                                                                )
                                                                    edit({
                                                                        ...def,
                                                                        edges: [
                                                                            ...def.edges,
                                                                            {
                                                                                id:
                                                                                    "e" +
                                                                                    crypto.randomUUID(),
                                                                                source: chosen.id,
                                                                                target: e
                                                                                    .target
                                                                                    .value,
                                                                                branch:
                                                                                    chosen.type ===
                                                                                    "condition"
                                                                                        ? "yes"
                                                                                        : "always",
                                                                            },
                                                                        ],
                                                                    });
                                                            }}
                                                        >
                                                            <option value="">
                                                                Select step…
                                                            </option>
                                                            {def.nodes
                                                                .filter(
                                                                    (n) =>
                                                                        n.id !==
                                                                            chosen.id &&
                                                                        n.type !==
                                                                            "start",
                                                                )
                                                                .map((n) => (
                                                                    <option
                                                                        key={
                                                                            n.id
                                                                        }
                                                                        value={
                                                                            n.id
                                                                        }
                                                                    >
                                                                        {
                                                                            n.label
                                                                        }
                                                                    </option>
                                                                ))}
                                                        </select>
                                                    </label>
                                                )}
                                            {canDesign &&
                                                chosen.type !== "start" && (
                                                    <button
                                                        className="ew-danger"
                                                        onClick={() => {
                                                            edit({
                                                                nodes: def.nodes.filter(
                                                                    (n) =>
                                                                        n.id !==
                                                                        chosen.id,
                                                                ),
                                                                edges: def.edges.filter(
                                                                    (e) =>
                                                                        e.source !==
                                                                            chosen.id &&
                                                                        e.target !==
                                                                            chosen.id,
                                                                ),
                                                            });
                                                            setSelected(null);
                                                        }}
                                                    >
                                                        Remove step
                                                    </button>
                                                )}
                                        </>
                                    ) : (
                                        <>
                                            <h2>Make each handoff clear.</h2>
                                            <p>
                                                Select a step to edit its
                                                screen, responsible person and
                                                connections. Use the list on
                                                touch devices if preferred.
                                            </p>
                                        </>
                                    )}
                                </aside>
                            </div>
                        )}
                        {tab === "screens" && (
                            <div className="ew-screen-builder">
                                <section className="ew-panel">
                                    <h2>Screens & forms</h2>
                                    <label>
                                        Connected step
                                        <select
                                            aria-label="Connected step"
                                            value={selected || ""}
                                            onChange={(e) =>
                                                setSelected(e.target.value)
                                            }
                                        >
                                            <option value="">
                                                Select a screen
                                            </option>
                                            {def.nodes
                                                .filter((n) => n.screen)
                                                .map((n) => (
                                                    <option
                                                        key={n.id}
                                                        value={n.id}
                                                    >
                                                        {n.label}
                                                    </option>
                                                ))}
                                        </select>
                                    </label>
                                    {chosen?.screen && (
                                        <>
                                            <label>
                                                Screen title
                                                <input
                                                    disabled={!canDesign}
                                                    value={chosen.screen.title}
                                                    maxLength={100}
                                                    onChange={(e) =>
                                                        patchStep({
                                                            screen: {
                                                                ...chosen.screen!,
                                                                title: e.target
                                                                    .value,
                                                            },
                                                        })
                                                    }
                                                />
                                            </label>
                                            <label>
                                                Instructions
                                                <textarea
                                                    disabled={!canDesign}
                                                    value={
                                                        chosen.screen
                                                            .description
                                                    }
                                                    maxLength={1000}
                                                    onChange={(e) =>
                                                        patchStep({
                                                            screen: {
                                                                ...chosen.screen!,
                                                                description:
                                                                    e.target
                                                                        .value,
                                                            },
                                                        })
                                                    }
                                                />
                                            </label>
                                            {chosen.screen.fields.map(
                                                (f, i) => (
                                                    <fieldset key={i}>
                                                        <legend>
                                                            Field {i + 1}
                                                        </legend>
                                                        <label>
                                                            Key
                                                            <input
                                                                value={f.key}
                                                                disabled={
                                                                    !canDesign
                                                                }
                                                                maxLength={40}
                                                                onChange={(e) =>
                                                                    updateField(
                                                                        i,
                                                                        {
                                                                            key: e
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </label>
                                                        <label>
                                                            Label
                                                            <input
                                                                value={f.label}
                                                                disabled={
                                                                    !canDesign
                                                                }
                                                                maxLength={100}
                                                                onChange={(e) =>
                                                                    updateField(
                                                                        i,
                                                                        {
                                                                            label: e
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </label>
                                                        <label>
                                                            Type
                                                            <select
                                                                value={f.type}
                                                                disabled={
                                                                    !canDesign
                                                                }
                                                                onChange={(e) =>
                                                                    updateField(
                                                                        i,
                                                                        {
                                                                            type: e
                                                                                .target
                                                                                .value,
                                                                            ...(e
                                                                                .target
                                                                                .value ===
                                                                            "select"
                                                                                ? {
                                                                                      options:
                                                                                          [
                                                                                              "Routine",
                                                                                              "Urgent",
                                                                                          ],
                                                                                  }
                                                                                : {}),
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                {[
                                                                    "text",
                                                                    "textarea",
                                                                    "select",
                                                                    "date",
                                                                    "checkbox",
                                                                ].map((t) => (
                                                                    <option
                                                                        key={t}
                                                                    >
                                                                        {t}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </label>
                                                        {f.type ===
                                                            "select" && (
                                                            <label>
                                                                Options, one per
                                                                line
                                                                <textarea
                                                                    value={(
                                                                        f.options ||
                                                                        []
                                                                    ).join(
                                                                        "\n",
                                                                    )}
                                                                    disabled={
                                                                        !canDesign
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        updateField(
                                                                            i,
                                                                            {
                                                                                options:
                                                                                    e.target.value.split(
                                                                                        "\n",
                                                                                    ),
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </label>
                                                        )}
                                                        <label className="ew-check">
                                                            <input
                                                                type="checkbox"
                                                                checked={
                                                                    f.required
                                                                }
                                                                disabled={
                                                                    !canDesign
                                                                }
                                                                onChange={(e) =>
                                                                    updateField(
                                                                        i,
                                                                        {
                                                                            required:
                                                                                e
                                                                                    .target
                                                                                    .checked,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                            Required
                                                        </label>
                                                        {canDesign && (
                                                            <button
                                                                onClick={() =>
                                                                    patchStep({
                                                                        screen: {
                                                                            ...chosen.screen!,
                                                                            fields: chosen.screen!.fields.filter(
                                                                                (
                                                                                    _,
                                                                                    j,
                                                                                ) =>
                                                                                    i !==
                                                                                    j,
                                                                            ),
                                                                        },
                                                                    })
                                                                }
                                                            >
                                                                Remove field
                                                            </button>
                                                        )}
                                                    </fieldset>
                                                ),
                                            )}
                                            {canDesign && (
                                                <button
                                                    onClick={() =>
                                                        patchStep({
                                                            screen: {
                                                                ...chosen.screen!,
                                                                fields: [
                                                                    ...chosen
                                                                        .screen!
                                                                        .fields,
                                                                    {
                                                                        key:
                                                                            "f" +
                                                                            crypto.randomUUID(),
                                                                        label: "New field",
                                                                        type: "text",
                                                                        required: false,
                                                                    },
                                                                ],
                                                            },
                                                        })
                                                    }
                                                >
                                                    + Add field
                                                </button>
                                            )}
                                        </>
                                    )}
                                </section>
                                <section className="ew-panel ew-form-preview">
                                    <p className="ew-eyebrow">
                                        STAFF SCREEN PREVIEW · NO SUBMISSION
                                    </p>
                                    {chosen?.screen ? (
                                        <>
                                            <h2>{chosen.screen.title}</h2>
                                            <p>{chosen.screen.description}</p>
                                            <Fields
                                                fields={chosen.screen.fields}
                                                values={{}}
                                                onChange={() => {}}
                                                disabled
                                            />
                                            <button disabled>Continue</button>
                                        </>
                                    ) : (
                                        <p>
                                            Select a form, assignment or
                                            approval step.
                                        </p>
                                    )}
                                    <p className="ew-subtle">
                                        Fields bind only to this run’s validated
                                        values. Forms cannot change permissions
                                        or call providers.
                                    </p>
                                </section>
                            </div>
                        )}
                        {tab === "runs" && (
                            <div className="ew-run-layout">
                                <section className="ew-panel">
                                    <h2>Runs & history</h2>
                                    {id &&
                                        !dirty &&
                                        (record?.can_run ?? list.can_run) &&
                                        status === "active" && (
                                            <button
                                                className="ew-primary"
                                                disabled={
                                                    busy || !!pending.current
                                                }
                                                onClick={() =>
                                                    mutate(
                                                        `${base}/${id}/runs`,
                                                        {
                                                            id: crypto.randomUUID(),
                                                            request_id:
                                                                crypto.randomUUID(),
                                                            expected_version:
                                                                record.workflow
                                                                    .version,
                                                        },
                                                        async (out) => {
                                                            setRun(
                                                                out.run || out,
                                                            );
                                                            setValues({});
                                                            await refresh();
                                                        },
                                                    )
                                                }
                                            >
                                                Start a run
                                            </button>
                                        )}
                                    {(record?.runs || []).map((r: any) => (
                                        <button
                                            className="ew-run-row"
                                            key={r.id}
                                            onClick={() => {
                                                setRun(r);
                                                setValues({});
                                            }}
                                        >
                                            {r.title || r.id}
                                            <small>
                                                {r.status} · version {r.version}
                                            </small>
                                        </button>
                                    ))}
                                    <p className="ew-subtle">
                                        Each run uses its activated definition
                                        snapshot. Editing the draft does not
                                        rewrite running work.
                                    </p>
                                </section>
                                <section className="ew-panel">
                                    {run ? (
                                        <>
                                            <span className="ew-badge">
                                                {run.status}
                                            </span>
                                            <h2>
                                                {runNode?.screen?.title ||
                                                    runNode?.label ||
                                                    "Run history"}
                                            </h2>
                                            <p>
                                                {runNode?.screen?.description}
                                            </p>
                                            {run.linked_task && <section className="ew-collected" aria-label="Linked native task">
                                                <h3>Canonical task</h3>
                                                <a href={transport.href(run.linked_task.url)}>{run.linked_task.title}</a>
                                                <dl><dt>Accountable owner</dt><dd>{memberName(run.linked_task.owner_user_id)}</dd><dt>Current ball</dt><dd>{memberName(run.linked_task.ball_user_id)}</dd><dt>Next action</dt><dd>{run.linked_task.next_action || "Not recorded"}</dd><dt>Due</dt><dd>{run.linked_task.due_on || "Not recorded"}</dd></dl>
                                                <progress max={100} value={run.linked_task.progress_percent} aria-label="Native task checklist progress"/>
                                                <p>{run.linked_task.progress_percent}% performed · {Number(run.linked_task.completion_score).toFixed(1)} / 4.0{run.linked_task.approval_required ? " · awaiting verification" : ""}</p>
                                                <p className="ew-subtle">Native version {run.linked_task.task_version}; operations version {run.linked_task.version}. Current task: {run.linked_task.status}.</p>
                                                {(run.linked_task.checklist || []).map((item: any) => <label className="ew-check" key={item.id}>
                                                    <input aria-label={`${item.label} (native task)`} type="checkbox" checked={item.completed} disabled={busy || dirty || !!pending.current || !run.linked_task.can_update || ["interrupted", "closed", "completed"].includes(run.linked_task.status)} onChange={() => mutate(operationsBase + "/commands", {
                                                        action: "task.check", task_id: run.linked_task.id, expected_version: run.linked_task.version, expected_task_version: run.linked_task.task_version,
                                                        request_id: crypto.randomUUID(), item_id: item.id, completed: !item.completed,
                                                    }, async () => {await refreshSelectedRun();})}/><span>{item.label} (weight {item.weight})</span>
                                                </label>)}
                                                <button disabled={busy || !!pending.current} onClick={async () => {setBusy(true);try {await refreshSelectedRun();setNotice("Current native task and run refreshed.");} catch (error: any) {setNotice(error.message);} finally {setBusy(false);}}}>Refresh native task &amp; run</button>
                                                <p className="ew-subtle">Complete the native checklist before continuing an assignment. Only an existing authorized manager can verify 4.0. Interrupted work must be explicitly resumed in Operations.</p>
                                            </section>}
                                            {run.collected_fields?.length >
                                                0 && (
                                                <section className="ew-collected">
                                                    <h3>
                                                        Recorded in this run
                                                    </h3>
                                                    <dl>
                                                        {run.collected_fields.map(
                                                            (f: any) => (
                                                                <React.Fragment
                                                                    key={f.key}
                                                                >
                                                                    <dt>
                                                                        {
                                                                            f.label
                                                                        }
                                                                    </dt>
                                                                    <dd>
                                                                        {typeof f.value ===
                                                                        "boolean"
                                                                            ? f.value
                                                                                ? "Yes"
                                                                                : "No"
                                                                            : String(
                                                                                  f.value ??
                                                                                      "",
                                                                              )}
                                                                    </dd>
                                                                </React.Fragment>
                                                            ),
                                                        )}
                                                    </dl>
                                                </section>
                                            )}
                                            {runNode?.screen && (
                                                <Fields
                                                    fields={
                                                        runNode.screen.fields
                                                    }
                                                    values={values}
                                                    onChange={(k, v) => {
                                                        const next = {
                                                            ...values,
                                                            [k]: v,
                                                        };
                                                        setValues(next);
                                                        try {
                                                            sessionStorage.setItem(
                                                                key +
                                                                    ":run:" +
                                                                    run.id,
                                                                JSON.stringify({
                                                                    version:
                                                                        run.version,
                                                                    values: next,
                                                                }),
                                                            );
                                                        } catch {}
                                                    }}
                                                    disabled={
                                                        busy || !run.can_advance
                                                    }
                                                />
                                            )}
                                            <p className="ew-subtle">
                                                {run.assignee_name
                                                    ? `Assigned to ${run.assignee_name}`
                                                    : ""}
                                            </p>
                                            {run.can_advance && (
                                                <div className="ew-actions">
                                                    {(
                                                        run.allowed_decisions || [
                                                            runNode?.type ===
                                                            "approval"
                                                                ? "approve"
                                                                : "complete",
                                                        ]
                                                    ).map(
                                                        (decision: string) => (
                                                            <button
                                                                key={decision}
                                                                className={
                                                                    decision ===
                                                                    "reject"
                                                                        ? ""
                                                                        : "ew-primary"
                                                                }
                                                                disabled={
                                                                    busy ||
                                                                    !!pending.current
                                                                }
                                                                onClick={() =>
                                                                    mutate(
                                                                        `${base}/${id}/runs/${run.id}/advance`,
                                                                        {
                                                                            request_id:
                                                                                crypto.randomUUID(),
                                                                            expected_version:
                                                                                run.version,
                                                                            ...(run.linked_task ? {expected_task_version: run.linked_task.task_version, expected_operations_version: run.linked_task.version} : {}),
                                                                            values,
                                                                            decision,
                                                                        },
                                                                        async (
                                                                            out,
                                                                        ) => {
                                                                            setRun(
                                                                                out.run ||
                                                                                    out,
                                                                            );
                                                                            setValues(
                                                                                {},
                                                                            );
                                                                            await refresh();
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                {decision ===
                                                                "approve"
                                                                    ? "Approve"
                                                                    : decision ===
                                                                        "reject"
                                                                      ? "Reject"
                                                                      : "Submit & continue"}
                                                            </button>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                            <h3>Audit trail</h3>
                                            <ol>
                                                {(run.history || []).map(
                                                    (h: any, i: number) => (
                                                        <li key={i}>
                                                            <strong>
                                                                {h.decision ||
                                                                    h.action ||
                                                                    h.type ||
                                                                    h.status}
                                                            </strong>{" "}
                                                            {h.label}
                                                            <small>
                                                                {h.at}{" "}
                                                                {h.user_id
                                                                    ? `· actor ${h.user_id}`
                                                                    : ""}
                                                            </small>
                                                        </li>
                                                    ),
                                                )}
                                            </ol>
                                        </>
                                    ) : (
                                        <>
                                            <h2>Every handoff, in view.</h2>
                                            <p>
                                                Select a run to complete the
                                                current screen or review its
                                                history.
                                            </p>
                                        </>
                                    )}
                                </section>
                            </div>
                        )}
                        {tab === "settings" && (
                            <section className="ew-panel ew-settings">
                                <h2>Workflow settings</h2>
                                <label>
                                    Workflow name
                                    <input
                                        value={name}
                                        disabled={!canDesign || busy}
                                        maxLength={100}
                                        onChange={(e) => {
                                            setName(e.target.value);
                                            setDirty(true);
                                            setPreview(null);
                                        }}
                                    />
                                </label>
                                <p>
                                    Version{" "}
                                    {record?.workflow.version || "not saved"} ·{" "}
                                    {status}
                                </p>
                                {canDesign && (
                                    <section className="ew-ai-draft">
                                        <h3>
                                            Ask Everbranch to propose a draft
                                        </h3>
                                        <p>
                                            Your saved workflow is preserved. A
                                            proposal is reviewed locally before
                                            a new draft is saved or activated.
                                        </p>
                                        <label>
                                            Describe the workflow
                                            <textarea
                                                value={prompt}
                                                onChange={(e) =>
                                                    setPrompt(e.target.value)
                                                }
                                                maxLength={2000}
                                            />
                                        </label>
                                        <button
                                            disabled={
                                                busy ||
                                                dirty ||
                                                !!pending.current ||
                                                !prompt.trim()
                                            }
                                            onClick={propose}
                                        >
                                            Propose a new workflow draft
                                        </button>
                                    </section>
                                )}
                                <p className="ew-subtle">
                                    Current project and department permissions
                                    are rechecked on save, preview, activation
                                    and every run transition. Assigning work
                                    grants no access. No email, payment or
                                    external action node is included.
                                </p>
                                {dirty && (
                                    <button
                                        disabled={busy || !!pending.current}
                                        onClick={() => {
                                            try {
                                                sessionStorage.removeItem(key);
                                            } catch {}
                                            setDirty(false);
                                            setRestored(false);
                                            setPreview(null);
                                            if (id) refresh();
                                            else
                                                setDef({
                                                    nodes: [],
                                                    edges: [],
                                                });
                                        }}
                                    >
                                        Discard unsaved changes
                                    </button>
                                )}
                                {canDesign && id && !dirty && (
                                    <>
                                        <button
                                            disabled={busy || !!pending.current}
                                            onClick={checkPreview}
                                        >
                                            Review activation preview
                                        </button>
                                        {preview && (
                                            <div className="ew-review">
                                                <h3>
                                                    Review before activation
                                                </h3>
                                                <p>
                                                    This enables staff to start
                                                    internal runs using this
                                                    saved graph and screens.
                                                </p>
                                                <pre>
                                                    {JSON.stringify(
                                                        preview,
                                                        null,
                                                        2,
                                                    )}
                                                </pre>
                                                <button
                                                    className="ew-primary"
                                                    disabled={
                                                        busy ||
                                                        !!pending.current
                                                    }
                                                    onClick={() =>
                                                        mutate(
                                                            `${base}/${id}/activate`,
                                                            {
                                                                request_id:
                                                                    crypto.randomUUID(),
                                                                expected_version:
                                                                    record
                                                                        .workflow
                                                                        .version,
                                                                confirmed: true,
                                                                digest: preview.digest,
                                                            },
                                                            async () => {
                                                                setPreview(
                                                                    null,
                                                                );
                                                                await refresh();
                                                            },
                                                        )
                                                    }
                                                >
                                                    Confirm activation
                                                </button>
                                            </div>
                                        )}
                                        {status === "active" && (
                                            <button
                                                disabled={
                                                    busy || !!pending.current
                                                }
                                                onClick={() =>
                                                    mutate(
                                                        `${base}/${id}/pause`,
                                                        {
                                                            request_id:
                                                                crypto.randomUUID(),
                                                            expected_version:
                                                                record.workflow
                                                                    .version,
                                                        },
                                                        async () => {
                                                            await refresh();
                                                        },
                                                    )
                                                }
                                            >
                                                Pause workflow
                                            </button>
                                        )}
                                    </>
                                )}
                            </section>
                        )}
                        {preview && tab === "canvas" && (
                            <section className="ew-panel ew-preview">
                                <h2>Safe preview</h2>
                                <p>
                                    No operational run was created. Review and
                                    activate the saved draft in Settings.
                                </p>
                                <pre>{JSON.stringify(preview, null, 2)}</pre>
                            </section>
                        )}
                    </>
                )}
                <footer className="ew-footer">
                    {list.coverage ||
                        "Only authorized work in the selected project and department is available."}
                </footer>
            </main>
        </div>
    );
}
function Fields({
    fields,
    values,
    onChange,
    disabled,
}: {
    fields: Field[];
    values: Record<string, any>;
    onChange: (k: string, v: any) => void;
    disabled: boolean;
}) {
    return (
        <>
            {fields.map((f) => (
                <label
                    key={f.key}
                    className={f.type === "checkbox" ? "ew-check" : ""}
                >
                    {f.type !== "checkbox" && (
                        <span>
                            {f.label}
                            {f.required ? " *" : ""}
                        </span>
                    )}
                    {f.type === "textarea" ? (
                        <textarea
                            aria-label={f.label + (f.required ? " *" : "")}
                            required={f.required}
                            disabled={disabled}
                            value={values[f.key] || ""}
                            maxLength={4000}
                            onChange={(e) => onChange(f.key, e.target.value)}
                        />
                    ) : f.type === "select" ? (
                        <select
                            aria-label={f.label + (f.required ? " *" : "")}
                            required={f.required}
                            disabled={disabled}
                            value={values[f.key] || ""}
                            onChange={(e) => onChange(f.key, e.target.value)}
                        >
                            <option value="">Choose…</option>
                            {f.options?.map((o) => (
                                <option key={o}>{o}</option>
                            ))}
                        </select>
                    ) : (
                        <input
                            type={
                                f.type === "checkbox"
                                    ? "checkbox"
                                    : f.type === "date"
                                      ? "date"
                                      : "text"
                            }
                            required={f.required}
                            disabled={disabled}
                            {...(f.type === "checkbox"
                                ? { checked: !!values[f.key] }
                                : {
                                      value: values[f.key] || "",
                                      maxLength: 500,
                                  })}
                            onChange={(e) =>
                                onChange(
                                    f.key,
                                    f.type === "checkbox"
                                        ? e.target.checked
                                        : e.target.value,
                                )
                            }
                        />
                    )}{" "}
                    {f.type === "checkbox" && f.label}
                </label>
            ))}
        </>
    );
}
