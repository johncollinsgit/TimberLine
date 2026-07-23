import "../bootstrap";
import axios from "axios";
import "@glideapps/glide-data-grid/dist/index.css";
import { CustomCell, CustomRenderer, DataEditor, GridCell, GridCellKind, GridColumn, Item, roundedRect } from "@glideapps/glide-data-grid";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";

type Bucket = "current" | "potential" | "past";
type Row = {
    id: number; kind: "job" | "candidate"; url?: string; title: string; customer?: string; status: string;
    priority?: string; scheduled_for?: string; lead_id?: number; lead?: string; crew?: string[];
    vehicles?: { id: number; name: string }[]; hours?: number; source?: string; amount?: number | null;
    balance?: number | null; updated_at?: string; customer_email?: string; customer_phone?: string;
    description?: string; service_address?: string; blocked_reason?: string;
    project_manager_name?: string; project_manager_company?: string; project_manager_phone?: string; project_manager_email?: string;
};
type Options = { team: { id: number; name: string }[]; vehicles: { id: number; name: string; identifier?: string }[]; statuses: string[] };
type Meta = { bucket: Bucket; page: number; last_page: number; total: number };
type Props = { endpoint: string; updateTemplate: string; candidateTemplate: string; canManage: boolean; canManageDrafts: boolean };
type View = { name: string; bucket: Bucket; sort: string; dir: "asc" | "desc"; q: string; columns: string[] };
type OpenCell = CustomCell<{ kind: "open-job" }>;

const openColumn: GridColumn & { id: string } = { id: "open", title: "", width: 92 };
const openCellRenderer: CustomRenderer<OpenCell> = {
    kind: GridCellKind.Custom,
    isMatch: (cell): cell is OpenCell => cell.data.kind === "open-job",
    needsHover: true,
    draw: ({ ctx, rect, theme, hoverAmount, overrideCursor }) => {
        const x = rect.x + 10;
        const y = rect.y + 8;
        const width = rect.width - 20;
        const height = rect.height - 16;
        overrideCursor?.("pointer");
        ctx.save();
        ctx.beginPath();
        roundedRect(ctx, x, y, width, height, 7);
        ctx.fillStyle = hoverAmount > 0 ? "#dcecff" : "#eef6ff";
        ctx.fill();
        ctx.strokeStyle = hoverAmount > 0 ? "#5b9cff" : "#8bb9ff";
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.fillStyle = "#0b1b36";
        ctx.font = `600 ${theme.baseFontFull}`;
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";
        ctx.fillText("Open", rect.x + rect.width / 2, rect.y + rect.height / 2 + 0.5);
        ctx.restore();
        return true;
    },
};

const allColumns: (GridColumn & { id: string })[] = [
    { id: "status", title: "Status", width: 125 }, { id: "title", title: "Job", width: 250 },
    { id: "customer", title: "Customer", width: 180 }, { id: "scheduled_for", title: "Schedule", width: 170 },
    { id: "lead", title: "Lead", width: 150 }, { id: "crew", title: "Crew", width: 190 },
    { id: "vehicles", title: "Vehicles", width: 175 }, { id: "hours", title: "Hours", width: 95 },
    { id: "priority", title: "Priority", width: 110 }, { id: "source", title: "Source", width: 110 },
    { id: "amount", title: "Amount", width: 120 }, { id: "balance", title: "Balance", width: 120 },
    { id: "updated_at", title: "Updated", width: 140 },
];
const editable = new Set(["status", "scheduled_for", "lead", "priority", "vehicles"]);
const defaultVisible = allColumns.map(column => column.id);
const financialColumns = new Set(["source", "amount", "balance"]);

function useSize() {
    const ref = useRef<HTMLDivElement | null>(null);
    const [size, setSize] = useState({ width: 0, height: 570 });
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const observer = new ResizeObserver(() => setSize({ width: el.clientWidth, height: Math.max(570, el.clientHeight) }));
        observer.observe(el);
        setSize({ width: el.clientWidth, height: Math.max(570, el.clientHeight) });
        return () => observer.disconnect();
    }, []);
    return [ref, size] as const;
}

function display(key: string, row: Row): string {
    const value = row[key as keyof Row];
    if (key === "scheduled_for") return row.scheduled_for ? new Date(row.scheduled_for).toLocaleString([], { dateStyle: "medium", timeStyle: "short" }) : "Unscheduled";
    if (key === "updated_at") return row.updated_at ? new Date(row.updated_at).toLocaleDateString() : "—";
    if (key === "vehicles") return row.vehicles?.map(vehicle => vehicle.name).join(", ") || "—";
    if (key === "crew") return row.crew?.join(", ") || "—";
    if (key === "hours") return `${Number(row.hours || 0).toFixed(2)}h`;
    if (key === "amount" || key === "balance") return value == null ? "—" : Number(value).toLocaleString(undefined, { style: "currency", currency: "USD" });
    return value == null || value === "" ? "—" : String(value).replaceAll("_", " ");
}

function dateTimeLocalValue(value?: string): string {
    if (!value) return "";
    const date = new Date(value);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
    return local.toISOString().slice(0, 16);
}

function FieldServiceGrid({ endpoint, updateTemplate, candidateTemplate, canManage, canManageDrafts }: Props) {
    const [bucket, setBucket] = useState<Bucket>("current");
    const [rows, setRows] = useState<Row[]>([]);
    const [options, setOptions] = useState<Options>({ team: [], vehicles: [], statuses: [] });
    const [meta, setMeta] = useState<Meta>({ bucket: "current", page: 1, last_page: 1, total: 0 });
    const [q, setQ] = useState("");
    const [sort, setSort] = useState("status");
    const [dir, setDir] = useState<"asc" | "desc">("asc");
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [saveState, setSaveState] = useState("");
    const [reload, setReload] = useState(0);
    const [visible, setVisible] = useState<string[]>(defaultVisible);
    const [columnsOpen, setColumnsOpen] = useState(false);
    const [candidate, setCandidate] = useState<Row | null>(null);
    const [openedJobId, setOpenedJobId] = useState<number | null>(null);
    const [views, setViews] = useState<View[]>(() => JSON.parse(localStorage.getItem("everbranch-field-views") || "[]"));
    const [gridRef, size] = useSize();
    const availableColumns = useMemo(() => allColumns.filter(column => canManageDrafts && bucket !== "potential" || !financialColumns.has(column.id)), [bucket, canManageDrafts]);
    const columns = useMemo(() => [openColumn, ...availableColumns.filter(column => visible.includes(column.id))], [availableColumns, visible]);
    const openedJob = openedJobId === null ? null : rows.find(row => row.kind === "job" && row.id === openedJobId) || null;

    useEffect(() => { setPage(1); }, [bucket, q, sort, dir]);
    useEffect(() => {
        if (openedJobId === null) return;
        const previousOverflow = document.body.style.overflow;
        const closeOnEscape = (event: KeyboardEvent) => { if (event.key === "Escape") setOpenedJobId(null); };
        document.body.style.overflow = "hidden";
        window.addEventListener("keydown", closeOnEscape);
        return () => { document.body.style.overflow = previousOverflow; window.removeEventListener("keydown", closeOnEscape); };
    }, [openedJobId]);
    useEffect(() => {
        if (openedJobId !== null && !rows.some(row => row.kind === "job" && row.id === openedJobId)) setOpenedJobId(null);
    }, [openedJobId, rows]);
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true); setError("");
            try {
                const response = await axios.get(endpoint, { signal: controller.signal, params: { bucket, q: q || undefined, sort, dir, page, per_page: 50 } });
                setRows(Array.isArray(response.data.rows) ? response.data.rows : []);
                setMeta(response.data.meta); setOptions(response.data.options || { team: [], vehicles: [], statuses: [] });
            } catch (failure) {
                if (!axios.isCancel(failure)) setError(axios.isAxiosError(failure) ? failure.response?.data?.message || "Could not load work." : "Could not load work.");
            } finally { if (!controller.signal.aborted) setLoading(false); }
        }, 220);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [bucket, dir, endpoint, page, q, reload, sort]);

    const persist = useCallback(async (row: Row, patch: Record<string, unknown>) => {
        if (!canManage || row.kind !== "job") return;
        const previous = rows;
        const optimisticPatch = { ...patch };
        if (typeof patch.operational_status === "string") optimisticPatch.status = patch.operational_status;
        setSaveState("Saving…");
        setRows(current => current.map(item => item.id === row.id ? { ...item, ...optimisticPatch } : item));
        try {
            await axios.patch(updateTemplate.replace(/\/0$/, `/${row.id}`), patch);
            setSaveState("Saved"); window.setTimeout(() => setSaveState(""), 1600);
        } catch (failure) {
            setRows(previous); setSaveState("Save failed");
            setError(axios.isAxiosError(failure) ? failure.response?.data?.message || "The edit could not be saved." : "The edit could not be saved.");
        }
    }, [canManage, rows, updateTemplate]);

    const getCellContent = useCallback(([col, rowIndex]: Item): GridCell => {
        const row = rows[rowIndex]; const column = columns[col];
        if (!row || !column) return { kind: GridCellKind.Text, data: "", displayData: "", readonly: true, allowOverlay: false };
        if (column.id === "open") return { kind: GridCellKind.Custom, data: { kind: "open-job" }, copyData: "Open", readonly: true, allowOverlay: false, cursor: "pointer" };
        const value = display(column.id, row);
        return { kind: GridCellKind.Text, data: value === "—" ? "" : value, displayData: value, readonly: !canManage || row.kind !== "job" || !editable.has(column.id), allowOverlay: canManage && row.kind === "job" && editable.has(column.id) };
    }, [canManage, columns, rows]);

    const editCell = useCallback((cell: Item, next: GridCell) => {
        if (next.kind !== GridCellKind.Text) return;
        const [col, rowIndex] = cell; const row = rows[rowIndex]; const key = columns[col]?.id; const value = next.data.trim();
        if (!row || !key) return;
        if (key === "status") void persist(row, { operational_status: value.toLowerCase().replaceAll(" ", "_") });
        if (key === "priority") void persist(row, { priority: value.toLowerCase() });
        if (key === "scheduled_for") void persist(row, { scheduled_for: value === "" ? null : value });
        if (key === "lead") {
            const person = options.team.find(member => member.name.toLowerCase() === value.toLowerCase());
            if (!person && value !== "") { setError("Type an employee name exactly as shown in Team."); return; }
            void persist(row, { assigned_user_id: person?.id || null, lead: person?.name || "" });
        }
        if (key === "vehicles") {
            const names = value.split(",").map(name => name.trim().toLowerCase()).filter(Boolean);
            const matches = options.vehicles.filter(vehicle => names.includes(vehicle.name.toLowerCase()));
            if (matches.length !== names.length) { setError("Enter vehicle names exactly, separated by commas."); return; }
            void persist(row, { vehicle_ids: matches.map(vehicle => vehicle.id), vehicles: matches });
        }
    }, [columns, options.team, options.vehicles, persist, rows]);

    const clickCell = useCallback(([col, rowIndex]: Item) => {
        const row = rows[rowIndex]; const key = columns[col]?.id;
        if (!row || key !== "open") return;
        if (row.kind === "candidate") setCandidate(row); else setOpenedJobId(row.id);
    }, [columns, rows]);

    function saveView() {
        const name = window.prompt("Name this view"); if (!name?.trim()) return;
        const next = [...views.filter(view => view.name !== name.trim()), { name: name.trim(), bucket, sort, dir, q, columns: visible }];
        setViews(next); localStorage.setItem("everbranch-field-views", JSON.stringify(next));
    }

    function applyView(name: string) {
        const view = views.find(item => item.name === name); if (!view) return;
        setBucket(view.bucket); setSort(view.sort); setDir(view.dir); setQ(view.q); setVisible(view.columns);
    }

    async function reviewCandidate(action: "create_job" | "link" | "dismiss") {
        if (!candidate) return;
        const jobId = action === "link" ? window.prompt("Enter the existing job ID") : null;
        if (action === "link" && !jobId) return;
        try {
            const response = await axios.post(candidateTemplate.replace(/\/0\/review$/, `/${candidate.id}/review`), { action, job_id: jobId ? Number(jobId) : undefined });
            setCandidate(null); setReload(value => value + 1);
            if (response.data.url && action === "create_job") window.location.assign(response.data.url);
        } catch (failure) { setError(axios.isAxiosError(failure) ? failure.response?.data?.message || "Candidate review failed." : "Candidate review failed."); }
    }

    return <div className="space-y-4">
        <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div className="inline-flex w-fit rounded-xl border border-zinc-200 bg-white p-1">
                {(["current", ...(canManageDrafts ? ["potential"] : []), "past"] as Bucket[]).map(item => <button key={item} onClick={() => setBucket(item)} className={`min-h-11 rounded-lg px-4 text-sm font-semibold ${bucket === item ? "bg-zinc-950 text-white" : "text-zinc-600 hover:bg-zinc-100"}`}>{item === "potential" ? "Job Drafts" : item[0].toUpperCase() + item.slice(1)}</button>)}
            </div>
            <div className="flex flex-1 flex-wrap justify-end gap-2">
                <input type="search" value={q} onChange={event => setQ(event.target.value)} placeholder="Search jobs, customers, addresses" className="min-h-11 min-w-[260px] flex-1 rounded-xl border border-zinc-300 bg-white px-4 text-sm xl:max-w-md" />
                <select value={sort} onChange={event => setSort(event.target.value)} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-3 text-sm"><option value="status">Sort: active now</option><option value="scheduled_for">Schedule</option><option value="priority">Priority</option><option value="customer">Customer</option><option value="title">Job</option><option value="hours">Hours</option><option value="updated_at">Last update</option></select>
                <button onClick={() => setDir(value => value === "asc" ? "desc" : "asc")} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold">{dir === "asc" ? "Ascending" : "Descending"}</button>
                <button onClick={() => setColumnsOpen(value => !value)} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold">Columns</button>
            </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-zinc-200 bg-white p-3">
            <select defaultValue="" onChange={event => applyView(event.target.value)} className="min-h-11 rounded-xl border border-zinc-300 px-3 text-sm"><option value="" disabled>Saved views</option>{views.map(view => <option key={view.name}>{view.name}</option>)}</select>
            <button onClick={saveView} className="min-h-11 rounded-xl border border-zinc-300 px-4 text-sm font-semibold">Save this view</button>
            <span className={`ml-auto text-sm font-semibold ${saveState.includes("failed") ? "text-rose-700" : "text-emerald-700"}`}>{saveState || (loading ? "Loading…" : `${meta.total} ${bucket === "potential" ? "job draft" : `${bucket} job`}${meta.total === 1 ? "" : "s"}`)}</span>
        </div>

        {columnsOpen ? <div className="flex flex-wrap gap-2 rounded-2xl border border-zinc-200 bg-white p-3">{availableColumns.map(column => <label key={column.id} className="flex min-h-11 items-center gap-2 rounded-xl border border-zinc-200 px-3 text-sm"><input type="checkbox" checked={visible.includes(column.id)} onChange={() => setVisible(current => current.includes(column.id) ? current.filter(key => key !== column.id) : [...current, column.id])} />{column.title}</label>)}</div> : null}
        {error ? <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{error}<button onClick={() => setError("")} className="ml-3 font-semibold underline">Dismiss</button></div> : null}

        <div ref={gridRef} className="min-h-[570px] overflow-hidden rounded-2xl border border-zinc-200 bg-white">
            {size.width > 0 ? <DataEditor columns={columns} rows={rows.length} getCellContent={getCellContent} onCellEdited={editCell} onCellClicked={clickCell} onCellActivated={clickCell} cellActivationBehavior="single-click" customRenderers={[openCellRenderer]} freezeColumns={1} rowMarkers="none" width={size.width} height={size.height} rowHeight={46} headerHeight={46} smoothScrollX smoothScrollY /> : null}
        </div>

        <div className="flex items-center justify-between"><button disabled={page <= 1} onClick={() => setPage(value => value - 1)} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold disabled:opacity-40">Previous</button><span className="text-sm text-zinc-600">Page {meta.page} of {Math.max(1, meta.last_page)}</span><button disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold disabled:opacity-40">Next</button></div>

        {openedJob ? <div className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={() => setOpenedJobId(null)}>
            <section role="dialog" aria-modal="true" aria-labelledby="work-job-dialog-title" onMouseDown={event => event.stopPropagation()} className="flex max-h-[calc(100vh-1.5rem)] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl sm:max-h-[calc(100vh-3rem)]">
                <header className="flex flex-wrap items-center gap-3 border-b border-zinc-200 bg-zinc-50 px-5 py-3 sm:px-7">
                    <span className="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-blue-950 ring-1 ring-inset ring-blue-200">{openedJob.status.replaceAll("_", " ")}</span>
                    <span className="text-sm text-zinc-500">{openedJob.source || "Everbranch"}</span>
                    <div className="ml-auto flex items-center gap-2">
                        {openedJob.url ? <a href={openedJob.url} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-950 hover:bg-blue-100">Full job page ↗</a> : null}
                        <button type="button" onClick={() => setOpenedJobId(null)} className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-zinc-300 bg-white text-xl text-zinc-700 hover:bg-zinc-100" aria-label="Close job">×</button>
                    </div>
                </header>

                <div className="overflow-y-auto px-5 py-6 sm:px-7 sm:py-7">
                    <div className="border-b border-zinc-200 pb-6">
                        <div className="text-xs font-semibold uppercase tracking-[0.2em] text-blue-800">Work item #{openedJob.id}</div>
                        <h2 id="work-job-dialog-title" className="mt-2 text-2xl font-semibold tracking-tight text-zinc-950 sm:text-3xl">{openedJob.title}</h2>
                        <p className="mt-2 text-base text-zinc-600">{openedJob.customer || "Customer not named"}</p>
                    </div>

                    <div className="grid gap-x-8 gap-y-5 border-b border-zinc-200 py-6 md:grid-cols-2">
                        <label className="grid gap-2 sm:grid-cols-[7rem_1fr] sm:items-center">
                            <span className="text-sm font-semibold text-zinc-700">Status</span>
                            <select value={openedJob.status} disabled={!canManage} onChange={event => void persist(openedJob, { operational_status: event.target.value })} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-950 disabled:bg-zinc-100">
                                {options.statuses.map(status => <option key={status} value={status}>{status.replaceAll("_", " ")}</option>)}
                            </select>
                        </label>
                        <label className="grid gap-2 sm:grid-cols-[7rem_1fr] sm:items-center">
                            <span className="text-sm font-semibold text-zinc-700">Assignee</span>
                            <select value={openedJob.lead_id || ""} disabled={!canManage} onChange={event => { const person = options.team.find(member => member.id === Number(event.target.value)); void persist(openedJob, { assigned_user_id: person?.id || null, lead: person?.name || "" }); }} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-950 disabled:bg-zinc-100">
                                <option value="">Unassigned</option>
                                {options.team.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}
                            </select>
                        </label>
                        <label className="grid gap-2 sm:grid-cols-[7rem_1fr] sm:items-center">
                            <span className="text-sm font-semibold text-zinc-700">Schedule</span>
                            <input type="datetime-local" value={dateTimeLocalValue(openedJob.scheduled_for)} disabled={!canManage} onChange={event => void persist(openedJob, { scheduled_for: event.target.value || null })} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-950 disabled:bg-zinc-100" />
                        </label>
                        <label className="grid gap-2 sm:grid-cols-[7rem_1fr] sm:items-center">
                            <span className="text-sm font-semibold text-zinc-700">Priority</span>
                            <select value={openedJob.priority || "normal"} disabled={!canManage} onChange={event => void persist(openedJob, { priority: event.target.value })} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-medium capitalize text-zinc-950 disabled:bg-zinc-100">
                                {['low', 'normal', 'high', 'urgent'].map(priority => <option key={priority}>{priority}</option>)}
                            </select>
                        </label>
                    </div>

                    {openedJob.blocked_reason ? <div className="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"><span className="font-semibold">Blocked:</span> {openedJob.blocked_reason}</div> : null}

                    <div className="grid gap-6 py-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(18rem,0.75fr)]">
                        <div className="space-y-6">
                            <section>
                                <h3 className="text-sm font-semibold text-zinc-950">Description</h3>
                                <div className="mt-2 min-h-28 whitespace-pre-wrap rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm leading-6 text-zinc-700">{openedJob.description || "No description has been added."}</div>
                            </section>
                            <section>
                                <h3 className="text-sm font-semibold text-zinc-950">Crew and equipment</h3>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {(openedJob.crew || []).map(person => <span key={person} className="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-900 ring-1 ring-inset ring-emerald-200">{person}</span>)}
                                    {(openedJob.vehicles || []).map(vehicle => <span key={vehicle.id} className="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-950 ring-1 ring-inset ring-blue-200">{vehicle.name}</span>)}
                                    {(openedJob.crew || []).length === 0 && (openedJob.vehicles || []).length === 0 ? <span className="text-sm text-zinc-500">No crew or vehicles assigned.</span> : null}
                                </div>
                            </section>
                        </div>

                        <aside className="space-y-4">
                            <section className="rounded-xl border border-zinc-200 p-4">
                                <h3 className="text-sm font-semibold text-zinc-950">Customer and site</h3>
                                <div className="mt-3 space-y-2 text-sm text-zinc-700">
                                    <div className="font-medium text-zinc-950">{openedJob.customer || "Customer not named"}</div>
                                    {openedJob.customer_phone ? <div>{openedJob.customer_phone}</div> : null}
                                    {openedJob.customer_email ? <div className="break-all">{openedJob.customer_email}</div> : null}
                                    <div>{openedJob.service_address || "Service address not added"}</div>
                                </div>
                            </section>
                            {openedJob.project_manager_name || openedJob.project_manager_phone || openedJob.project_manager_email ? <section className="rounded-xl border border-zinc-200 p-4"><h3 className="text-sm font-semibold text-zinc-950">Project Manager</h3><div className="mt-3 space-y-2 text-sm text-zinc-700"><div className="font-medium text-zinc-950">{openedJob.project_manager_name || "Not named"}</div>{openedJob.project_manager_company ? <div>{openedJob.project_manager_company}</div> : null}{openedJob.project_manager_phone ? <div><a className="font-semibold text-emerald-800" href={`tel:${openedJob.project_manager_phone}`}>Call</a> · <a className="font-semibold text-emerald-800" href={`sms:${openedJob.project_manager_phone}`}>Text</a> · {openedJob.project_manager_phone}</div> : null}{openedJob.project_manager_email ? <a className="break-all text-emerald-800" href={`mailto:${openedJob.project_manager_email}`}>{openedJob.project_manager_email}</a> : null}</div></section> : null}
                            <section className="grid grid-cols-2 gap-3">
                                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><div className="text-xs font-semibold uppercase text-zinc-500">Hours</div><div className="mt-1 text-lg font-semibold text-zinc-950">{display("hours", openedJob)}</div></div>
                                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><div className="text-xs font-semibold uppercase text-zinc-500">Updated</div><div className="mt-1 text-lg font-semibold text-zinc-950">{display("updated_at", openedJob)}</div></div>
                                {openedJob.amount != null ? <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><div className="text-xs font-semibold uppercase text-zinc-500">Amount</div><div className="mt-1 text-lg font-semibold text-zinc-950">{display("amount", openedJob)}</div></div> : null}
                                {openedJob.balance != null ? <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><div className="text-xs font-semibold uppercase text-zinc-500">Balance</div><div className="mt-1 text-lg font-semibold text-zinc-950">{display("balance", openedJob)}</div></div> : null}
                            </section>
                        </aside>
                    </div>
                </div>
            </section>
        </div> : null}

        {candidate ? <div className="fixed inset-0 z-50 flex justify-end bg-black/30" onClick={() => setCandidate(null)}><aside onClick={event => event.stopPropagation()} className="h-full w-full max-w-lg overflow-y-auto bg-white p-6 shadow-2xl"><button onClick={() => setCandidate(null)} className="min-h-11 text-sm font-semibold text-zinc-600">← Back to Job Drafts</button><div className="mt-6 text-xs font-semibold uppercase tracking-widest text-emerald-700">Job Draft</div><h2 className="mt-2 text-2xl font-semibold text-zinc-950">{candidate.title}</h2><p className="mt-2 text-zinc-600">{candidate.customer || "Customer not linked"}</p><div className="mt-6 space-y-4 rounded-xl bg-zinc-50 p-4 text-sm text-zinc-700">{candidate.service_address ? <div><strong className="block text-zinc-950">Service address</strong>{candidate.service_address}</div> : null}{candidate.description ? <div><strong className="block text-zinc-950">Work description</strong><span className="whitespace-pre-wrap">{candidate.description}</span></div> : null}{candidate.project_manager_name || candidate.project_manager_phone ? <div><strong className="block text-zinc-950">Project Manager</strong>{[candidate.project_manager_name, candidate.project_manager_company, candidate.project_manager_phone].filter(Boolean).join(" · ")}</div> : null}</div><p className="mt-4 text-sm text-zinc-500">Review this operational draft, then publish it for the field team. Accounting stays in the office system.</p><div className="mt-8 grid gap-3"><button onClick={() => void reviewCandidate("create_job")} className="min-h-12 rounded-xl bg-zinc-950 px-4 font-semibold text-white">Publish Job</button><button onClick={() => void reviewCandidate("link")} className="min-h-12 rounded-xl border border-zinc-300 px-4 font-semibold">Link to Existing Job</button><button onClick={() => void reviewCandidate("dismiss")} className="min-h-12 rounded-xl border border-zinc-300 px-4 font-semibold text-zinc-600">Archive Draft</button></div></aside></div> : null}
    </div>;
}

const root = document.getElementById("field-service-jobs-grid");
if (root) {
    createRoot(root).render(<FieldServiceGrid endpoint={root.dataset.endpoint || ""} updateTemplate={root.dataset.updateTemplate || ""} candidateTemplate={root.dataset.candidateTemplate || ""} canManage={root.dataset.canManage === "1"} canManageDrafts={root.dataset.canManageDrafts === "1"} />);
}
