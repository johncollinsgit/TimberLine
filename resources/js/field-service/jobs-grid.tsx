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
type JobUpdateAttachment = { id: number; name: string; mime_type: string; url: string; preview_url?: string | null };
type JobUpdate = { id: number; body: string; author: string; noted_at?: string | null; attachments: JobUpdateAttachment[] };
type Props = { endpoint: string; updateTemplate: string; transitionTemplate: string; updatesTemplate: string; noteTemplate: string; candidateTemplate: string; canManage: boolean; canManageDrafts: boolean };
type View = { name: string; bucket: Bucket; sort: string; dir: "asc" | "desc"; q: string; columns: string[] };
type OpenCell = CustomCell<{ kind: "open-job" }>;
type DeleteCell = CustomCell<{ kind: "delete-job" }>;
type SelectCell = CustomCell<{ kind: "select-job"; selected: boolean }>;

const selectColumn: GridColumn & { id: string } = { id: "select", title: "", width: 52 };
const openColumn: GridColumn & { id: string } = { id: "open", title: "", width: 92 };
const deleteColumn: GridColumn & { id: string } = { id: "delete", title: "Action", width: 104 };
const websitePhotoTargetBytes = 1_500_000;
const websiteUploadBatchBytes = 6_000_000;
const pendingFileIds = new WeakMap<File, string>();
let nextPendingFileId = 0;

function pendingFileId(file: File): string {
    let id = pendingFileIds.get(file);
    if (!id) {
        id = `pending-file-${++nextPendingFileId}`;
        pendingFileIds.set(file, id);
    }
    return id;
}
const selectCellRenderer: CustomRenderer<SelectCell> = {
    kind: GridCellKind.Custom,
    isMatch: (cell): cell is SelectCell => cell.data.kind === "select-job",
    needsHover: true,
    draw: ({ ctx, rect, hoverAmount, overrideCursor, cell }) => {
        const size = 18;
        const x = rect.x + (rect.width - size) / 2;
        const y = rect.y + (rect.height - size) / 2;
        overrideCursor?.("pointer");
        ctx.save();
        ctx.beginPath();
        roundedRect(ctx, x, y, size, size, 4);
        ctx.fillStyle = cell.data.selected ? "#0f766e" : (hoverAmount > 0 ? "#f0fdfa" : "#ffffff");
        ctx.fill();
        ctx.strokeStyle = cell.data.selected ? "#0f766e" : "#a1a1aa";
        ctx.lineWidth = 1.5;
        ctx.stroke();
        if (cell.data.selected) {
            ctx.strokeStyle = "#ffffff";
            ctx.lineWidth = 2;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
            ctx.beginPath();
            ctx.moveTo(x + 4, y + 9);
            ctx.lineTo(x + 7.5, y + 12.5);
            ctx.lineTo(x + 14.5, y + 5.5);
            ctx.stroke();
        }
        ctx.restore();
        return true;
    },
};
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

const deleteCellRenderer: CustomRenderer<DeleteCell> = {
    kind: GridCellKind.Custom,
    isMatch: (cell): cell is DeleteCell => cell.data.kind === "delete-job",
    needsHover: true,
    draw: ({ ctx, rect, theme, hoverAmount, overrideCursor }) => {
        const x = rect.x + 9;
        const y = rect.y + 8;
        const width = rect.width - 18;
        const height = rect.height - 16;
        overrideCursor?.("pointer");
        ctx.save();
        ctx.beginPath();
        roundedRect(ctx, x, y, width, height, 7);
        ctx.fillStyle = hoverAmount > 0 ? "#fff1f2" : "#fffafa";
        ctx.fill();
        ctx.strokeStyle = hoverAmount > 0 ? "#e11d48" : "#fda4af";
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.fillStyle = "#9f1239";
        ctx.font = `600 ${theme.baseFontFull}`;
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";
        ctx.fillText("Delete", rect.x + rect.width / 2, rect.y + rect.height / 2 + 0.5);
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

function canvasToJpeg(canvas: HTMLCanvasElement, quality: number): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error("The selected photo could not be resized.")), "image/jpeg", quality);
    });
}

async function loadWebsitePhoto(file: File): Promise<HTMLImageElement> {
    const url = URL.createObjectURL(file);
    try {
        return await new Promise<HTMLImageElement>((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error("The selected photo could not be decoded."));
            image.src = url;
        });
    } finally {
        URL.revokeObjectURL(url);
    }
}

async function prepareWebsitePhoto(file: File): Promise<File> {
    if (!file.type.startsWith("image/") || file.size <= websitePhotoTargetBytes) return file;

    let image: HTMLImageElement;
    try {
        image = await loadWebsitePhoto(file);
    } catch {
        throw new Error(`“${file.name}” is too large for a website upload and could not be resized here. Choose a JPEG or PNG copy, then try again.`);
    }

    for (const maximumDimension of [2560, 1920, 1440]) {
        const scale = Math.min(1, maximumDimension / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext("2d");
        if (!context) throw new Error("This browser could not prepare the selected photo for upload.");
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        for (const quality of [0.88, 0.78, 0.68, 0.58]) {
            const blob = await canvasToJpeg(canvas, quality);
            if (blob.size <= websitePhotoTargetBytes || (maximumDimension === 1440 && quality === 0.58)) {
                const extensionlessName = file.name.replace(/\.[^.]+$/, "") || "photo";
                return new File([blob], `${extensionlessName}.jpg`, { type: "image/jpeg", lastModified: file.lastModified });
            }
        }
    }

    throw new Error(`“${file.name}” could not be prepared for a safe website upload. Choose a smaller copy and try again.`);
}

function makeWebsiteUploadBatches(files: File[]): File[][] {
    const batches: File[][] = [];
    let batch: File[] = [];
    let batchBytes = 0;

    for (const file of files) {
        if (file.size > websiteUploadBatchBytes) {
            throw new Error(`“${file.name}” is too large for a website upload. Choose a file under 6 MB and try again.`);
        }
        if (batch.length > 0 && batchBytes + file.size > websiteUploadBatchBytes) {
            batches.push(batch);
            batch = [];
            batchBytes = 0;
        }
        batch.push(file);
        batchBytes += file.size;
    }
    if (batch.length > 0) batches.push(batch);
    return batches;
}

function usePendingImagePreviews(files: File[]): Map<string, string> {
    const previews = useRef(new Map<string, string>());
    const [version, setVersion] = useState(0);

    useEffect(() => {
        const activeIds = new Set<string>();
        files.filter(file => file.type.startsWith("image/")).forEach(file => {
            const id = pendingFileId(file);
            activeIds.add(id);
            if (!previews.current.has(id)) previews.current.set(id, URL.createObjectURL(file));
        });
        previews.current.forEach((url, id) => {
            if (!activeIds.has(id)) {
                URL.revokeObjectURL(url);
                previews.current.delete(id);
            }
        });
        setVersion(current => current + 1);
    }, [files]);

    useEffect(() => () => {
        previews.current.forEach(url => URL.revokeObjectURL(url));
        previews.current.clear();
    }, []);

    return useMemo(() => new Map(previews.current), [version]);
}

function FieldServiceGrid({ endpoint, updateTemplate, transitionTemplate, updatesTemplate, noteTemplate, candidateTemplate, canManage, canManageDrafts }: Props) {
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
    const [editAll, setEditAll] = useState(false);
    const [candidate, setCandidate] = useState<Row | null>(null);
    const [openedJobId, setOpenedJobId] = useState<number | null>(null);
    const [selectedJobIds, setSelectedJobIds] = useState<Set<number>>(new Set());
    const [openedUpdates, setOpenedUpdates] = useState<JobUpdate[]>([]);
    const [updatesLoading, setUpdatesLoading] = useState(false);
    const [updateBody, setUpdateBody] = useState("");
    const [updateFiles, setUpdateFiles] = useState<File[]>([]);
    const [updatePosting, setUpdatePosting] = useState(false);
    const [updatePostingMessage, setUpdatePostingMessage] = useState("");
    const [updateConfirmation, setUpdateConfirmation] = useState("");
    const [updateError, setUpdateError] = useState("");
    const [activePhotoIndex, setActivePhotoIndex] = useState(0);
    const [views, setViews] = useState<View[]>(() => JSON.parse(localStorage.getItem("everbranch-field-views") || "[]"));
    const [gridRef, size] = useSize();
    const archivingJobIds = useRef(new Set<number>());
    const availableColumns = useMemo(() => allColumns.filter(column => canManageDrafts && bucket !== "potential" || !financialColumns.has(column.id)), [bucket, canManageDrafts]);
    const showDeleteAction = canManage && bucket === "current";
    const columns = useMemo(() => [
        ...(showDeleteAction ? [selectColumn] : []),
        openColumn,
        ...(showDeleteAction ? [deleteColumn] : []),
        ...availableColumns.filter(column => visible.includes(column.id)),
    ], [availableColumns, showDeleteAction, visible]);
    const openedJob = openedJobId === null ? null : rows.find(row => row.kind === "job" && row.id === openedJobId) || null;
    const updatePhotos = useMemo(() => openedUpdates.flatMap(update => update.attachments.filter(attachment => attachment.mime_type.startsWith("image/")).map(attachment => ({ ...attachment, updateId: update.id, author: update.author, notedAt: update.noted_at }))), [openedUpdates]);
    const activePhoto = updatePhotos[activePhotoIndex] || null;
    const pendingImagePreviewUrls = usePendingImagePreviews(updateFiles);
    const pendingPhotoCount = useMemo(() => updateFiles.filter(file => file.type.startsWith("image/")).length, [updateFiles]);
    const updatePostLabel = updatePosting
        ? updatePostingMessage || "Preparing upload…"
        : pendingPhotoCount > 0
            ? `Post ${pendingPhotoCount === 1 ? "photo" : `${pendingPhotoCount} photos`} to job`
            : updateFiles.length > 0
                ? `Post ${updateFiles.length === 1 ? "file" : "files"} to job`
                : "Post update to job";

    useEffect(() => { setPage(1); }, [bucket, q, sort, dir]);
    useEffect(() => { if (bucket !== "current") setEditAll(false); }, [bucket]);
    useEffect(() => {
        if (openedJobId === null) return;
        const previousOverflow = document.body.style.overflow;
        const closeOnEscape = (event: KeyboardEvent) => { if (event.key === "Escape") setOpenedJobId(null); };
        document.body.style.overflow = "hidden";
        window.addEventListener("keydown", closeOnEscape);
        return () => { document.body.style.overflow = previousOverflow; window.removeEventListener("keydown", closeOnEscape); };
    }, [openedJobId]);
    useEffect(() => {
        if (!openedJob) {
            setOpenedUpdates([]); setUpdateBody(""); setUpdateFiles([]); setUpdateError(""); setActivePhotoIndex(0);
            return;
        }
        const controller = new AbortController();
        setUpdatesLoading(true);
        axios.get(updatesTemplate.replace(/\/0\/updates$/, `/${openedJob.id}/updates`), { signal: controller.signal })
            .then(response => setOpenedUpdates(Array.isArray(response.data?.updates) ? response.data.updates : []))
            .catch(failure => {
                if (!axios.isCancel(failure)) setError(axios.isAxiosError(failure) ? failure.response?.data?.message || "Could not load job updates." : "Could not load job updates.");
            })
            .finally(() => { if (!controller.signal.aborted) setUpdatesLoading(false); });
        return () => controller.abort();
    }, [openedJob?.id, updatesTemplate]);
    useEffect(() => { setActivePhotoIndex(current => Math.max(0, Math.min(current, updatePhotos.length - 1))); }, [updatePhotos.length]);
    useEffect(() => {
        if (openedJobId !== null && !rows.some(row => row.kind === "job" && row.id === openedJobId)) setOpenedJobId(null);
    }, [openedJobId, rows]);
    useEffect(() => {
        setSelectedJobIds(current => new Set([...current].filter(id => rows.some(row => row.kind === "job" && row.id === id))));
    }, [rows]);
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

    const archiveJobs = useCallback(async (candidates: Row[]) => {
        const jobs = candidates.filter(row => row.kind === "job" && !archivingJobIds.current.has(row.id));
        if (!canManage || jobs.length === 0) return;
        const confirmation = jobs.length === 1
            ? `Delete “${jobs[0].title}” from active work? It will remain searchable in job history.`
            : `Delete ${jobs.length} jobs from active work? Each will remain searchable in job history.`;
        if (!window.confirm(confirmation)) return;
        jobs.forEach(job => archivingJobIds.current.add(job.id));
        setSaveState(jobs.length === 1 ? "Deleting…" : `Deleting ${jobs.length} jobs…`);
        const results = await Promise.allSettled(jobs.map(job => axios.post(transitionTemplate.replace(/\/0\/transitions$/, `/${job.id}/transitions`), { action: "archive" })));
        const deletedIds = new Set(jobs.filter((_, index) => results[index].status === "fulfilled").map(job => job.id));
        const failures = results.filter(result => result.status === "rejected");
        jobs.forEach(job => archivingJobIds.current.delete(job.id));
        if (deletedIds.size > 0) {
            setOpenedJobId(current => current !== null && deletedIds.has(current) ? null : current);
            setRows(current => current.filter(row => !deletedIds.has(row.id)));
            setSelectedJobIds(current => new Set([...current].filter(id => !deletedIds.has(id))));
            setReload(value => value + 1);
        }
        if (failures.length > 0) {
            setSaveState(deletedIds.size > 0 ? `${deletedIds.size} deleted; ${failures.length} failed` : "Delete failed");
            const failure = failures[0];
            setError(failure.status === "rejected" && axios.isAxiosError(failure.reason) ? failure.reason.response?.data?.message || "Some jobs could not be deleted." : "Some jobs could not be deleted.");
            return;
        }
        setSaveState(deletedIds.size === 1 ? "Deleted — in history" : `${deletedIds.size} deleted — in history`);
        window.setTimeout(() => setSaveState(""), 2200);
    }, [canManage, transitionTemplate]);

    const postUpdate = useCallback(async () => {
        if (!openedJob || updatePosting || (!updateBody.trim() && updateFiles.length === 0)) return;
        const photoCount = updateFiles.filter(file => file.type.startsWith("image/")).length;
        setUpdatePosting(true); setUpdatePostingMessage("Preparing photos for upload…"); setUpdateError(""); setUpdateConfirmation("");
        try {
            const preparedFiles = await Promise.all(updateFiles.map(prepareWebsitePhoto));
            const batches = makeWebsiteUploadBatches(preparedFiles);
            for (const [index, batch] of batches.entries()) {
                setUpdatePostingMessage(batches.length > 1 ? `Posting attachment batch ${index + 1} of ${batches.length}…` : "Posting to the job…");
                const form = new FormData();
                form.append("body", index === 0 ? updateBody.trim() : "");
                batch.forEach(file => form.append("attachments[]", file, file.name));
                await axios.post(noteTemplate.replace(/\/0\/notes$/, `/${openedJob.id}/notes`), form);
            }
            const response = await axios.get(updatesTemplate.replace(/\/0\/updates$/, `/${openedJob.id}/updates`));
            setOpenedUpdates(Array.isArray(response.data?.updates) ? response.data.updates : []);
            setUpdateBody(""); setUpdateFiles([]);
            const confirmation = photoCount > 0
                ? `${photoCount} ${photoCount === 1 ? "photo" : "photos"} saved to this job and synced to the Everbranch app.`
                : "Update saved to this job.";
            setUpdateConfirmation(confirmation); setSaveState("Update posted");
            window.setTimeout(() => { setSaveState(""); setUpdateConfirmation(""); }, 5000);
        } catch (failure) {
            if (axios.isAxiosError(failure) && failure.response?.status === 413) {
                setUpdateError("That upload was too large for the server. The selected photos are now sent as smaller website-safe uploads; please try posting again.");
            } else {
                setUpdateError(failure instanceof Error ? failure.message : axios.isAxiosError(failure) ? failure.response?.data?.message || "Could not post the update." : "Could not post the update.");
            }
        } finally { setUpdatePosting(false); setUpdatePostingMessage(""); }
    }, [noteTemplate, openedJob, updateBody, updateFiles, updatePosting, updatesTemplate]);

    const getCellContent = useCallback(([col, rowIndex]: Item): GridCell => {
        const row = rows[rowIndex]; const column = columns[col];
        if (!row || !column) return { kind: GridCellKind.Text, data: "", displayData: "", readonly: true, allowOverlay: false };
        if (column.id === "select") return { kind: GridCellKind.Custom, data: { kind: "select-job", selected: selectedJobIds.has(row.id) }, copyData: selectedJobIds.has(row.id) ? "Selected" : "Not selected", readonly: true, allowOverlay: false, cursor: "pointer" };
        if (column.id === "open") return { kind: GridCellKind.Custom, data: { kind: "open-job" }, copyData: "Open", readonly: true, allowOverlay: false, cursor: "pointer" };
        if (column.id === "delete") return { kind: GridCellKind.Custom, data: { kind: "delete-job" }, copyData: "Delete", readonly: true, allowOverlay: false, cursor: "pointer" };
        const value = display(column.id, row);
        return { kind: GridCellKind.Text, data: value === "—" ? "" : value, displayData: value, readonly: !canManage || !editAll || row.kind !== "job" || !editable.has(column.id), allowOverlay: canManage && editAll && row.kind === "job" && editable.has(column.id) };
    }, [canManage, columns, editAll, rows, selectedJobIds]);

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
        if (!row) return;
        if (key === "select") {
            setSelectedJobIds(current => {
                const next = new Set(current);
                if (next.has(row.id)) next.delete(row.id); else next.add(row.id);
                return next;
            });
            return;
        }
        if (key === "delete") { void archiveJobs([row]); return; }
    }, [archiveJobs, columns, rows]);

    const activateCell = useCallback(([col, rowIndex]: Item) => {
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
                {canManage && bucket === "current" ? <button onClick={() => setEditAll(value => !value)} className={`min-h-11 rounded-xl border px-4 text-sm font-semibold ${editAll ? "border-emerald-800 bg-emerald-800 text-white hover:bg-emerald-900" : "border-emerald-300 bg-emerald-50 text-emerald-900 hover:bg-emerald-100"}`}>{editAll ? "Done editing" : "Edit all"}</button> : null}
                <button onClick={() => setColumnsOpen(value => !value)} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold">Columns</button>
            </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-zinc-200 bg-white p-3">
            <select defaultValue="" onChange={event => applyView(event.target.value)} className="min-h-11 rounded-xl border border-zinc-300 px-3 text-sm"><option value="" disabled>Saved views</option>{views.map(view => <option key={view.name}>{view.name}</option>)}</select>
            <button onClick={saveView} className="min-h-11 rounded-xl border border-zinc-300 px-4 text-sm font-semibold">Save this view</button>
            <span className={`ml-auto text-sm font-semibold ${saveState.includes("failed") ? "text-rose-700" : "text-emerald-700"}`}>{saveState || (loading ? "Loading…" : `${meta.total} ${bucket === "potential" ? "job draft" : `${bucket} job`}${meta.total === 1 ? "" : "s"}`)}</span>
        </div>

        {editAll ? <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950"><strong>Inline editing is on.</strong> Click a Status, Schedule, Lead, Priority, or Vehicles cell to update any job row. Each change saves immediately.</div> : null}

        {showDeleteAction && selectedJobIds.size > 0 ? <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3"><span className="text-sm font-semibold text-rose-900">{selectedJobIds.size} selected</span><button onClick={() => void archiveJobs(rows.filter(row => selectedJobIds.has(row.id)))} className="min-h-11 rounded-xl border border-rose-300 bg-white px-4 text-sm font-semibold text-rose-800 hover:bg-rose-100">Delete selected ({selectedJobIds.size})</button><button onClick={() => setSelectedJobIds(new Set())} className="min-h-11 px-2 text-sm font-semibold text-rose-800 hover:underline">Clear selection</button><span className="text-xs text-rose-700">Selected jobs move to searchable history.</span></div> : null}

        {columnsOpen ? <div className="flex flex-wrap gap-2 rounded-2xl border border-zinc-200 bg-white p-3">{availableColumns.map(column => <label key={column.id} className="flex min-h-11 items-center gap-2 rounded-xl border border-zinc-200 px-3 text-sm"><input type="checkbox" checked={visible.includes(column.id)} onChange={() => setVisible(current => current.includes(column.id) ? current.filter(key => key !== column.id) : [...current, column.id])} />{column.title}</label>)}</div> : null}
        {error ? <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{error}<button onClick={() => setError("")} className="ml-3 font-semibold underline">Dismiss</button></div> : null}

        <div ref={gridRef} className="min-h-[570px] overflow-hidden rounded-2xl border border-zinc-200 bg-white">
            {size.width > 0 ? <DataEditor columns={columns} rows={rows.length} getCellContent={getCellContent} onCellEdited={editCell} onCellClicked={clickCell} onCellActivated={activateCell} cellActivationBehavior="single-click" customRenderers={[selectCellRenderer, openCellRenderer, deleteCellRenderer]} freezeColumns={showDeleteAction ? 3 : 1} rowMarkers="none" width={size.width} height={size.height} rowHeight={46} headerHeight={46} smoothScrollX smoothScrollY /> : null}
        </div>

        <div className="flex items-center justify-between"><button disabled={page <= 1} onClick={() => setPage(value => value - 1)} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold disabled:opacity-40">Previous</button><span className="text-sm text-zinc-600">Page {meta.page} of {Math.max(1, meta.last_page)}</span><button disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-4 text-sm font-semibold disabled:opacity-40">Next</button></div>

        {openedJob ? <div className="fixed inset-0 z-[80] flex items-center justify-center bg-zinc-950/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={() => setOpenedJobId(null)}>
            <section role="dialog" aria-modal="true" aria-labelledby="work-job-dialog-title" onMouseDown={event => event.stopPropagation()} className="flex max-h-[calc(100vh-1.5rem)] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl sm:max-h-[calc(100vh-3rem)]">
                <header className="flex flex-wrap items-center gap-3 border-b border-zinc-200 bg-zinc-50 px-5 py-3 sm:px-7">
                    <span className="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-blue-950 ring-1 ring-inset ring-blue-200">{openedJob.status.replaceAll("_", " ")}</span>
                    <span className="text-sm text-zinc-500">{openedJob.source || "Everbranch"}</span>
                    <div className="ml-auto flex items-center gap-2">
                        {canManage && openedJob.url ? <a href={`${openedJob.url}${openedJob.url.includes("?") ? "&" : "?"}edit=1#job-details`} className="inline-flex min-h-10 items-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-sm font-semibold text-emerald-900 hover:bg-emerald-100">Edit job</a> : null}
                        {openedJob.url ? <a href={openedJob.url} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-950 hover:bg-blue-100">Full job page ↗</a> : null}
                        {canManage && bucket === "current" ? <button type="button" onClick={() => void archiveJobs([openedJob])} className="inline-flex min-h-10 items-center rounded-lg border border-rose-200 bg-rose-50 px-3 text-sm font-semibold text-rose-800 hover:bg-rose-100">Delete job</button> : null}
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
                            <section className="rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5">
                                <div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="text-base font-semibold text-zinc-950">Updates</h3><p className="mt-1 text-sm text-zinc-600">Add a job note, photos, or files without leaving this job.</p></div><span className="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-600">{openedUpdates.length} updates</span></div>
                                <textarea value={updateBody} onChange={event => setUpdateBody(event.target.value)} rows={3} className="mt-4 w-full rounded-xl border border-zinc-300 px-3 py-3 text-sm text-zinc-900" placeholder="Write a job update or field note" />
                                {updateFiles.length > 0 ? <div className="mt-4 flex flex-wrap gap-4">{updateFiles.map((file, index) => file.type.startsWith("image/") ? <div key={pendingFileId(file)} className="group relative h-36 w-48 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 shadow-sm">{pendingImagePreviewUrls.get(pendingFileId(file)) ? <img src={pendingImagePreviewUrls.get(pendingFileId(file))} alt={`Selected photo: ${file.name}`} className="h-full w-full object-cover" /> : <div className="grid h-full w-full place-items-center bg-zinc-100 px-4 text-center text-xs font-semibold text-zinc-500" aria-label={`Preparing preview for ${file.name}`}>Preparing photo preview…</div>}<span className="absolute inset-x-0 bottom-0 truncate bg-zinc-950/75 px-2.5 py-1.5 text-[11px] font-semibold text-white">{file.name}</span><button type="button" onClick={() => setUpdateFiles(current => current.filter((_, itemIndex) => itemIndex !== index))} className="absolute right-2 top-2 grid h-8 w-8 place-items-center rounded-full bg-white/95 text-lg font-semibold text-zinc-800 shadow-sm hover:bg-rose-50 hover:text-rose-700" aria-label={`Remove ${file.name}`}>×</button></div> : <span key={`${file.name}-${index}`} className="inline-flex min-h-11 max-w-full items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700"><span className="truncate">File · {file.name}</span><button type="button" onClick={() => setUpdateFiles(current => current.filter((_, itemIndex) => itemIndex !== index))} className="text-zinc-500 hover:text-rose-700" aria-label={`Remove ${file.name}`}>×</button></span>)}</div> : null}
                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <label className="inline-flex min-h-11 cursor-pointer items-center rounded-xl border border-zinc-300 bg-white px-3 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">Add photos<input type="file" accept="image/*" capture="environment" multiple className="sr-only" onChange={event => setUpdateFiles(current => [...current, ...Array.from(event.target.files || [])].slice(0, 20))} /></label>
                                    <label className="inline-flex min-h-11 cursor-pointer items-center rounded-xl border border-zinc-300 bg-white px-3 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">Add files<input type="file" accept="image/*,application/pdf,text/plain,text/csv,.doc,.docx,.xls,.xlsx" multiple className="sr-only" onChange={event => setUpdateFiles(current => [...current, ...Array.from(event.target.files || [])].slice(0, 20))} /></label>
                                    <button type="button" disabled={updatePosting || (!updateBody.trim() && updateFiles.length === 0)} onClick={() => void postUpdate()} className="min-h-11 min-w-48 rounded-xl bg-blue-700 px-5 text-sm font-bold text-white shadow-md ring-1 ring-inset ring-blue-950/20 transition hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-zinc-400 disabled:shadow-none disabled:ring-0">{updatePostLabel}</button>
                                </div>
                                <div className="mt-3" aria-live="polite">
                                    {updatePosting ? <p role="status" className="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-950">{updatePostingMessage || "Preparing upload…"} Keep this window open until the confirmation appears.</p> : null}
                                    {!updatePosting && updateConfirmation ? <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-950">✓ {updateConfirmation}</p> : null}
                                    {!updatePosting && updateError ? <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-950">{updateError}</p> : null}
                                    {!updatePosting && !updateConfirmation && (updateBody.trim() || updateFiles.length > 0) ? <p className="text-xs font-medium text-zinc-600">Ready to post {updateFiles.length > 0 ? `${updateFiles.length} attachment${updateFiles.length === 1 ? "" : "s"} ` : ""}to this shared job record.</p> : null}
                                </div>
                                {activePhoto ? <div className="mt-5 overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-950"><div className="relative aspect-[16/9] bg-zinc-900"><img src={activePhoto.preview_url || activePhoto.url} alt={activePhoto.name} className="h-full w-full object-contain" /><a href={activePhoto.url} target="_blank" rel="noreferrer" className="absolute right-3 top-3 rounded-lg bg-white/95 px-3 py-2 text-xs font-semibold text-zinc-950 shadow-sm">Open photo ↗</a>{updatePhotos.length > 1 ? <><button type="button" onClick={() => setActivePhotoIndex(current => (current - 1 + updatePhotos.length) % updatePhotos.length)} className="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-white/95 px-3 py-2 text-lg font-semibold text-zinc-950 shadow-sm" aria-label="Previous photo">‹</button><button type="button" onClick={() => setActivePhotoIndex(current => (current + 1) % updatePhotos.length)} className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-white/95 px-3 py-2 text-lg font-semibold text-zinc-950 shadow-sm" aria-label="Next photo">›</button></> : null}</div><div className="flex items-center justify-between gap-3 bg-zinc-900 px-4 py-3 text-sm text-white"><span className="truncate">{activePhoto.name}</span><span className="shrink-0 text-xs text-zinc-300">{activePhotoIndex + 1} of {updatePhotos.length}</span></div></div> : null}
                                <div className="mt-5 space-y-3">{updatesLoading ? <p className="text-sm text-zinc-500">Loading updates…</p> : openedUpdates.length === 0 ? <p className="text-sm text-zinc-500">No updates yet. Add the first field note above.</p> : openedUpdates.map(update => <article key={update.id} className="border-t border-zinc-200 pt-3"><div className="flex flex-wrap items-baseline justify-between gap-2"><strong className="text-sm text-zinc-950">{update.author}</strong><span className="text-xs text-zinc-500">{update.noted_at ? new Date(update.noted_at).toLocaleString([], { dateStyle: "medium", timeStyle: "short" }) : ""}</span></div><p className="mt-1 whitespace-pre-wrap text-sm leading-6 text-zinc-700">{update.body}</p>{update.attachments.length > 0 ? <div className="mt-3 flex flex-wrap gap-2">{update.attachments.map(attachment => attachment.mime_type.startsWith("image/") ? <button type="button" key={attachment.id} onClick={() => setActivePhotoIndex(updatePhotos.findIndex(photo => photo.id === attachment.id))} className="h-20 w-28 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100"><img src={attachment.preview_url || attachment.url} alt={attachment.name} className="h-full w-full object-cover" /></button> : <a key={attachment.id} href={attachment.url} className="inline-flex min-h-10 max-w-full items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 text-sm font-semibold text-emerald-800"><span className="truncate">File · {attachment.name}</span></a>)}</div> : null}</article>)}</div>
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
                            <section className="grid grid-cols-2 gap-3">
                                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><div className="text-xs font-semibold uppercase text-zinc-500">Hours</div><div className="mt-1 text-lg font-semibold text-zinc-950">{display("hours", openedJob)}</div></div>
                                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><div className="text-xs font-semibold uppercase text-zinc-500">Updated</div><div className="mt-1 text-lg font-semibold text-zinc-950">{display("updated_at", openedJob)}</div></div>
                                {openedJob.amount != null ? <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><div className="text-xs font-semibold uppercase text-zinc-500">Amount</div><div className="mt-1 text-lg font-semibold text-zinc-950">{display("amount", openedJob)}</div></div> : null}
                                {openedJob.balance != null ? <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><div className="text-xs font-semibold uppercase text-zinc-500">Balance</div><div className="mt-1 text-lg font-semibold text-zinc-950">{display("balance", openedJob)}</div></div> : null}
                            </section>
                            <section className="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><h3 className="text-xs font-bold uppercase tracking-[0.16em] text-emerald-900">Project manager</h3><div className="mt-2 text-base font-semibold text-zinc-950">{openedJob.project_manager_name || "Project manager not added"}</div>{openedJob.project_manager_company ? <div className="mt-1 text-sm text-zinc-700">{openedJob.project_manager_company}</div> : null}{openedJob.project_manager_phone ? <a className="mt-3 inline-flex min-h-10 items-center rounded-lg bg-emerald-800 px-3 text-sm font-semibold text-white hover:bg-emerald-900" href={`tel:${openedJob.project_manager_phone}`}>Call {openedJob.project_manager_phone}</a> : <p className="mt-2 text-sm text-zinc-600">No PM phone number added.</p>}{openedJob.project_manager_email ? <a className="mt-2 block break-all text-sm font-semibold text-emerald-800" href={`mailto:${openedJob.project_manager_email}`}>{openedJob.project_manager_email}</a> : null}</section>
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
    createRoot(root).render(<FieldServiceGrid endpoint={root.dataset.endpoint || ""} updateTemplate={root.dataset.updateTemplate || ""} transitionTemplate={root.dataset.transitionTemplate || ""} updatesTemplate={root.dataset.updatesTemplate || ""} noteTemplate={root.dataset.noteTemplate || ""} candidateTemplate={root.dataset.candidateTemplate || ""} canManage={root.dataset.canManage === "1"} canManageDrafts={root.dataset.canManageDrafts === "1"} />);
}
