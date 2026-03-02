import "../bootstrap";
import axios from "axios";
import "@glideapps/glide-data-grid/dist/index.css";
import {
    DataEditor,
    GridCell,
    GridCellKind,
    GridColumn,
    Item,
    EditableGridCell,
} from "@glideapps/glide-data-grid";
import {
    startTransition,
    useDeferredValue,
    useEffect,
    useRef,
    useState,
    type RefObject,
} from "react";
import { createRoot } from "react-dom/client";

type ResourceTab = {
    key: string;
    label: string;
    description: string;
};

type OptionItem = {
    value: number | string;
    label: string;
};

type ColumnMeta = {
    key: string;
    label: string;
    type: "text" | "number" | "checkbox" | "select";
    nullable?: boolean;
    options?: OptionItem[];
};

type PaginationMeta = {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
};

type ResponseMeta = {
    resource: string;
    label: string;
    columns: ColumnMeta[];
    pagination: PaginationMeta;
    filters: {
        search: string;
        active: string | null;
        sort: string;
        dir: "asc" | "desc";
    };
    supports_active_filter: boolean;
};

type RowData = {
    id: number;
    [key: string]: unknown;
};

type RootDataset = {
    resources: ResourceTab[];
    activeResource: string;
    baseEndpoint: string;
};

type SaveState = "idle" | "saving" | "saved";

const SAVE_DEBOUNCE_MS = 450;

function useDebouncedValue<T>(value: T, delayMs: number): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delayMs);

        return () => window.clearTimeout(timer);
    }, [delayMs, value]);

    return debounced;
}

function useElementWidth<T extends HTMLElement>(): [RefObject<T | null>, number] {
    const ref = useRef<T | null>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const element = ref.current;
        if (!element) {
            return;
        }

        const update = () => {
            setWidth(element.clientWidth);
        };

        update();

        const observer = new ResizeObserver(update);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return [ref, width];
}

function normalizeText(value: string): string {
    return value.trim().replace(/\s+/g, " ");
}

function columnWidth(column: ColumnMeta): number {
    if (column.type === "checkbox") {
        return 124;
    }

    if (column.type === "number") {
        return 132;
    }

    if (column.type === "select") {
        return 220;
    }

    if (column.key === "name" || column.key === "display_name" || column.key === "alias") {
        return 240;
    }

    return 180;
}

function parseRootDataset(root: HTMLElement): RootDataset {
    const resources = JSON.parse(root.dataset.resources || "[]") as ResourceTab[];

    return {
        resources,
        activeResource: root.dataset.activeResource || resources[0]?.key || "scents",
        baseEndpoint: root.dataset.baseEndpoint || "/admin/master",
    };
}

function optionLabelFor(column: ColumnMeta, value: unknown): string {
    const options = Array.isArray(column.options) ? column.options : [];
    const match = options.find((option) => String(option.value) === String(value ?? ""));

    if (match) {
        return match.label;
    }

    return value == null || value === "" ? "" : String(value);
}

function coerceValueFromCell(
    column: ColumnMeta,
    nextCell: EditableGridCell,
    previousValue: unknown
): unknown {
    if (column.type === "checkbox") {
        if (nextCell.kind === GridCellKind.Boolean) {
            return Boolean(nextCell.data);
        }

        if (nextCell.kind === GridCellKind.Text) {
            const lowered = String(nextCell.data ?? "").trim().toLowerCase();
            return ["1", "true", "yes", "y", "on"].includes(lowered);
        }

        return Boolean(previousValue);
    }

    if (column.type === "number") {
        if (nextCell.kind === GridCellKind.Number) {
            return nextCell.data == null ? (column.nullable ? null : 0) : Number(nextCell.data);
        }

        if (nextCell.kind === GridCellKind.Text) {
            const raw = normalizeText(String(nextCell.data ?? ""));
            if (raw === "") {
                return column.nullable ? null : 0;
            }

            const parsed = Number(raw);
            return Number.isFinite(parsed) ? parsed : previousValue;
        }

        return previousValue;
    }

    if (column.type === "select") {
        const raw = nextCell.kind === GridCellKind.Text
            ? normalizeText(String(nextCell.data ?? ""))
            : normalizeText(String((nextCell as { data?: unknown }).data ?? ""));

        if (raw === "") {
            return column.nullable ? null : previousValue;
        }

        const options = Array.isArray(column.options) ? column.options : [];
        const match = options.find((option) => {
            return (
                String(option.value) === raw ||
                option.label.localeCompare(raw, undefined, { sensitivity: "accent" }) === 0
            );
        });

        return match ? match.value : previousValue;
    }

    const raw = nextCell.kind === GridCellKind.Text
        ? String(nextCell.data ?? "")
        : String((nextCell as { data?: unknown }).data ?? "");
    const normalized = normalizeText(raw);

    if (normalized === "") {
        return column.nullable ? null : "";
    }

    return normalized;
}

function buildGridColumns(meta: ResponseMeta | null): GridColumn[] {
    if (!meta) {
        return [];
    }

    const baseColumns = meta.columns.map((column) => ({
        id: column.key,
        title: column.label,
        width: columnWidth(column),
    }));

    return [
        ...baseColumns,
        {
            id: "__actions",
            title: "Actions",
            width: 108,
        },
    ];
}

function MasterDataGridApp(props: RootDataset) {
    const { resources, baseEndpoint } = props;
    const [activeResource, setActiveResource] = useState(props.activeResource);
    const [rows, setRows] = useState<RowData[]>([]);
    const [meta, setMeta] = useState<ResponseMeta | null>(null);
    const [loading, setLoading] = useState(true);
    const [searchInput, setSearchInput] = useState("");
    const deferredSearchInput = useDeferredValue(searchInput);
    const search = useDebouncedValue(deferredSearchInput, 350);
    const [activeFilter, setActiveFilter] = useState("");
    const [sortField, setSortField] = useState("");
    const [sortDir, setSortDir] = useState<"asc" | "desc">("asc");
    const [page, setPage] = useState(1);
    const [reloadToken, setReloadToken] = useState(0);
    const [notice, setNotice] = useState("");
    const [error, setError] = useState("");
    const [saveState, setSaveState] = useState<SaveState>("idle");
    const [cellErrors, setCellErrors] = useState<Record<string, string>>({});
    const [gridWrapRef, gridWidth] = useElementWidth<HTMLDivElement>();
    const saveTimersRef = useRef<Map<string, number>>(new Map());
    const saveFlashTimerRef = useRef<number | null>(null);

    const activeTab = resources.find((resource) => resource.key === activeResource) ?? resources[0] ?? null;
    const gridColumns = buildGridColumns(meta);
    const gridHeight = 620;

    useEffect(() => {
        axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
    }, []);

    useEffect(() => {
        let cancelled = false;

        async function loadRows() {
            setLoading(true);
            setError("");

            try {
                const response = await axios.get(`${baseEndpoint}/${activeResource}`, {
                    params: {
                        page,
                        per_page: 50,
                        search,
                        active: activeFilter,
                        sort: sortField,
                        dir: sortDir,
                    },
                });

                if (cancelled) {
                    return;
                }

                const nextRows = Array.isArray(response.data?.data) ? (response.data.data as RowData[]) : [];
                const nextMeta = (response.data?.meta ?? null) as ResponseMeta | null;

                startTransition(() => {
                    setRows(nextRows);
                    setMeta(nextMeta);
                    setSortField(String(nextMeta?.filters?.sort || sortField || nextMeta?.columns?.[0]?.key || ""));
                    setSortDir((nextMeta?.filters?.dir || sortDir || "asc") as "asc" | "desc");
                });
            } catch (requestError) {
                if (cancelled) {
                    return;
                }

                const message = axios.isAxiosError(requestError)
                    ? requestError.response?.data?.message || "Could not load master data."
                    : "Could not load master data.";
                setRows([]);
                setMeta(null);
                setError(message);
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        }

        void loadRows();

        return () => {
            cancelled = true;
        };
    }, [activeFilter, activeResource, baseEndpoint, page, reloadToken, search, sortDir, sortField]);

    useEffect(() => {
        if (saveState !== "saved") {
            return;
        }

        if (saveFlashTimerRef.current !== null) {
            window.clearTimeout(saveFlashTimerRef.current);
        }

        saveFlashTimerRef.current = window.setTimeout(() => {
            setSaveState("idle");
        }, 1200);

        return () => {
            if (saveFlashTimerRef.current !== null) {
                window.clearTimeout(saveFlashTimerRef.current);
            }
        };
    }, [saveState]);

    useEffect(() => {
        setPage(1);
    }, [activeFilter, activeResource, search, sortDir, sortField]);

    const updateRowValue = (rowIndex: number, field: string, nextValue: unknown) => {
        setRows((currentRows) =>
            currentRows.map((row, index) =>
                index === rowIndex ? { ...row, [field]: nextValue } : row
            )
        );
    };

    const queuePatch = (
        rowIndex: number,
        rowId: number,
        field: string,
        previousValue: unknown,
        nextValue: unknown
    ) => {
        const timerKey = `${rowId}:${field}`;
        const existingTimer = saveTimersRef.current.get(timerKey);

        if (existingTimer) {
            window.clearTimeout(existingTimer);
        }

        setSaveState("saving");
        setNotice("Saving…");
        setCellErrors((current) => {
            if (!current[timerKey]) {
                return current;
            }

            const next = { ...current };
            delete next[timerKey];
            return next;
        });

        const timerId = window.setTimeout(async () => {
            try {
                await axios.patch(`${baseEndpoint}/${activeResource}/${rowId}`, {
                    [field]: nextValue,
                });
                setSaveState("saved");
                setNotice("Saved");
                setError("");
            } catch (requestError) {
                updateRowValue(rowIndex, field, previousValue);

                const message = axios.isAxiosError(requestError)
                    ? requestError.response?.data?.message || "Could not save that cell."
                    : "Could not save that cell.";

                setSaveState("idle");
                setNotice("");
                setError(message);
                setCellErrors((current) => ({
                    ...current,
                    [timerKey]: message,
                }));
            } finally {
                saveTimersRef.current.delete(timerKey);
            }
        }, SAVE_DEBOUNCE_MS);

        saveTimersRef.current.set(timerKey, timerId);
    };

    const handleAddRow = async () => {
        try {
            setError("");
            setNotice("Creating row…");
            const response = await axios.post(`${baseEndpoint}/${activeResource}`, {});
            const created = response.data?.data as RowData | undefined;

            if (created) {
                startTransition(() => {
                    setRows((currentRows) => [created, ...currentRows]);
                });
            }

            setSaveState("saved");
            setNotice("Row created");
            setPage(1);
            setReloadToken((current) => current + 1);
        } catch (requestError) {
            const message = axios.isAxiosError(requestError)
                ? requestError.response?.data?.message || "Could not create a row."
                : "Could not create a row.";
            setSaveState("idle");
            setNotice("");
            setError(message);
        }
    };

    const handleDeleteRow = async (rowIndex: number) => {
        const row = rows[rowIndex];
        if (!row) {
            return;
        }

        const confirmed = window.confirm("Delete this row?");
        if (!confirmed) {
            return;
        }

        const removedRow = row;
        setRows((currentRows) => currentRows.filter((_, index) => index !== rowIndex));
        setNotice("Deleting row…");
        setError("");

        try {
            await axios.delete(`${baseEndpoint}/${activeResource}/${row.id}`);
            setSaveState("saved");
            setNotice("Row deleted");
            setReloadToken((current) => current + 1);
        } catch (requestError) {
            setRows((currentRows) => {
                const nextRows = [...currentRows];
                nextRows.splice(rowIndex, 0, removedRow);
                return nextRows;
            });

            const message = axios.isAxiosError(requestError)
                ? requestError.response?.data?.message || "Could not delete that row."
                : "Could not delete that row.";
            setSaveState("idle");
            setNotice("");
            setError(message);
        }
    };

    const getColumnMeta = (index: number): ColumnMeta | null => {
        if (!meta) {
            return null;
        }

        return meta.columns[index] ?? null;
    };

    const getCellContent = ([col, row]: Item): GridCell => {
        const rowData = rows[row];
        const columnMeta = getColumnMeta(col);

        if (!rowData) {
            return {
                kind: GridCellKind.Text,
                allowOverlay: false,
                data: "",
                displayData: "",
                readonly: true,
            };
        }

        if (!columnMeta) {
            return {
                kind: GridCellKind.Text,
                allowOverlay: false,
                data: "Delete",
                displayData: "Delete",
                readonly: true,
            };
        }

        const cellErrorKey = `${rowData.id}:${columnMeta.key}`;
        const themeOverride = cellErrors[cellErrorKey]
            ? {
                bgCell: "#fff1f2",
                borderColor: "#fb7185",
                textDark: "#9f1239",
            }
            : undefined;

        const rawValue = rowData[columnMeta.key];

        if (columnMeta.type === "checkbox") {
            return {
                kind: GridCellKind.Boolean,
                allowOverlay: true,
                data: Boolean(rawValue),
                readonly: false,
                themeOverride,
            };
        }

        if (columnMeta.type === "number") {
            const numericValue =
                rawValue == null || rawValue === "" ? undefined : Number(rawValue);

            return {
                kind: GridCellKind.Number,
                allowOverlay: true,
                data: Number.isFinite(numericValue as number) ? (numericValue as number) : undefined,
                displayData:
                    numericValue == null || !Number.isFinite(numericValue as number)
                        ? ""
                        : String(numericValue),
                readonly: false,
                themeOverride,
            };
        }

        const textValue =
            columnMeta.type === "select"
                ? optionLabelFor(columnMeta, rawValue)
                : rawValue == null
                    ? ""
                    : String(rawValue);

        return {
            kind: GridCellKind.Text,
            allowOverlay: true,
            data: textValue,
            displayData: textValue === "" && columnMeta.type === "select" ? "Select…" : textValue,
            readonly: false,
            themeOverride,
        };
    };

    const handleCellEdited = (cell: Item, nextCell: EditableGridCell) => {
        const [col, rowIndex] = cell;
        const columnMeta = getColumnMeta(col);
        const row = rows[rowIndex];

        if (!columnMeta || !row) {
            return;
        }

        const previousValue = row[columnMeta.key];
        const nextValue = coerceValueFromCell(columnMeta, nextCell, previousValue);

        if (Object.is(nextValue, previousValue)) {
            return;
        }

        updateRowValue(rowIndex, columnMeta.key, nextValue);
        queuePatch(rowIndex, row.id, columnMeta.key, previousValue, nextValue);
    };

    const handleCellClicked = (cell: Item) => {
        const [col, rowIndex] = cell;
        const isActionColumn = col === gridColumns.length - 1;

        if (!isActionColumn) {
            return;
        }

        void handleDeleteRow(rowIndex);
    };

    const gridStatus =
        saveState === "saving"
            ? "Saving…"
            : saveState === "saved"
                ? "Saved"
                : loading
                    ? "Loading…"
                    : `${meta?.pagination.total ?? rows.length} row${(meta?.pagination.total ?? rows.length) === 1 ? "" : "s"}`;

    return (
        <div className="space-y-5">
            <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div className="space-y-3">
                    <div className="flex flex-wrap gap-2">
                        {resources.map((resource) => (
                            <button
                                key={resource.key}
                                type="button"
                                onClick={() => {
                                    startTransition(() => {
                                        setActiveResource(resource.key);
                                        setSearchInput("");
                                        setActiveFilter("");
                                        setPage(1);
                                        setError("");
                                        setNotice("");
                                    });
                                }}
                                className={
                                    "rounded-2xl border px-3 py-2 text-sm font-medium transition " +
                                    (resource.key === activeResource
                                        ? "border-emerald-300 bg-emerald-50 text-emerald-900"
                                        : "border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50")
                                }
                            >
                                {resource.label}
                            </button>
                        ))}
                    </div>

                    <div>
                        <div className="text-lg font-semibold text-zinc-950">
                            {activeTab?.label ?? "Master Data"}
                        </div>
                        <div className="text-sm text-zinc-500">
                            {activeTab?.description ?? ""}
                        </div>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-[minmax(0,16rem)_10rem_12rem_8rem_auto_auto]">
                    <input
                        type="search"
                        value={searchInput}
                        onChange={(event) => setSearchInput(event.target.value)}
                        placeholder="Search name, code, abbreviation…"
                        className="h-11 rounded-2xl border border-zinc-200 px-3 text-sm text-zinc-900 outline-none transition focus:border-emerald-300"
                    />

                    {meta?.supports_active_filter ? (
                        <select
                            value={activeFilter}
                            onChange={(event) => setActiveFilter(event.target.value)}
                            className="h-11 rounded-2xl border border-zinc-200 px-3 text-sm text-zinc-900 outline-none transition focus:border-emerald-300"
                        >
                            <option value="">All Rows</option>
                            <option value="true">Active</option>
                            <option value="false">Inactive</option>
                        </select>
                    ) : (
                        <div />
                    )}

                    <select
                        value={sortField}
                        onChange={(event) => setSortField(event.target.value)}
                        className="h-11 rounded-2xl border border-zinc-200 px-3 text-sm text-zinc-900 outline-none transition focus:border-emerald-300"
                    >
                        {(meta?.columns ?? []).map((column) => (
                            <option key={column.key} value={column.key}>
                                Sort: {column.label}
                            </option>
                        ))}
                    </select>

                    <select
                        value={sortDir}
                        onChange={(event) => setSortDir(event.target.value === "desc" ? "desc" : "asc")}
                        className="h-11 rounded-2xl border border-zinc-200 px-3 text-sm text-zinc-900 outline-none transition focus:border-emerald-300"
                    >
                        <option value="asc">Asc</option>
                        <option value="desc">Desc</option>
                    </select>

                    <button
                        type="button"
                        onClick={() => void handleAddRow()}
                        className="h-11 rounded-2xl border border-zinc-200 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                    >
                        Add Row
                    </button>

                    <button
                        type="button"
                        onClick={() => setReloadToken((current) => current + 1)}
                        className="h-11 rounded-2xl border border-zinc-200 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                    >
                        Refresh
                    </button>
                </div>
            </div>

            {error !== "" ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {error}
                </div>
            ) : null}

            {notice !== "" ? (
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    {notice}
                </div>
            ) : null}

            <div className="flex items-center justify-between gap-3">
                <div className="text-xs font-medium uppercase tracking-[0.2em] text-zinc-500">
                    {gridStatus}
                </div>
                {Object.keys(cellErrors).length > 0 ? (
                    <div className="text-xs text-rose-700">
                        {Object.keys(cellErrors).length} cell error{Object.keys(cellErrors).length === 1 ? "" : "s"}
                    </div>
                ) : null}
            </div>

            <div
                ref={gridWrapRef}
                className="overflow-hidden rounded-3xl border border-zinc-200 bg-zinc-50"
            >
                {gridWidth > 0 ? (
                    <DataEditor
                        columns={gridColumns}
                        rows={rows.length}
                        getCellContent={getCellContent}
                        onCellEdited={handleCellEdited}
                        onCellClicked={handleCellClicked}
                        onPaste={true}
                        width={gridWidth}
                        height={gridHeight}
                        rowMarkers="number"
                        smoothScrollX={true}
                        smoothScrollY={true}
                        overscrollY={48}
                    />
                ) : (
                    <div className="flex h-[620px] items-center justify-center text-sm text-zinc-500">
                        Loading grid…
                    </div>
                )}
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="text-xs text-zinc-500">
                    Page {meta?.pagination.page ?? 1} of {meta?.pagination.last_page ?? 1}
                    {" · "}
                    {meta?.pagination.total ?? rows.length} total rows
                </div>

                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setPage((current) => Math.max(1, current - 1))}
                        disabled={(meta?.pagination.page ?? 1) <= 1}
                        className="rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-700 transition enabled:hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            setPage((current) =>
                                Math.min(meta?.pagination.last_page ?? current, current + 1)
                            )
                        }
                        disabled={(meta?.pagination.page ?? 1) >= (meta?.pagination.last_page ?? 1)}
                        className="rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-700 transition enabled:hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    );
}

const rootElement = document.getElementById("master-data-grid");

if (rootElement) {
    const root = createRoot(rootElement);
    root.render(<MasterDataGridApp {...parseRootDataset(rootElement)} />);
}
