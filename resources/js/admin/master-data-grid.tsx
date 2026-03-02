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
    type Theme,
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

type ElementSize = {
    width: number;
    height: number;
};

function useDebouncedValue<T>(value: T, delayMs: number): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delayMs);

        return () => window.clearTimeout(timer);
    }, [delayMs, value]);

    return debounced;
}

function useElementSize<T extends HTMLElement>(): [RefObject<T | null>, ElementSize] {
    const ref = useRef<T | null>(null);
    const [size, setSize] = useState<ElementSize>({ width: 0, height: 0 });

    useEffect(() => {
        const element = ref.current;
        if (!element) {
            return;
        }

        const update = (width: number, height: number) => {
            setSize((current) => {
                if (current.width === width && current.height === height) {
                    return current;
                }

                return { width, height };
            });
        };

        update(element.clientWidth, element.clientHeight);

        const observer = new ResizeObserver((entries) => {
            const entry = entries[0];
            if (!entry) {
                return;
            }

            update(
                Math.round(entry.contentRect.width),
                Math.round(entry.contentRect.height)
            );
        });
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return [ref, size];
}

function readCssVar(name: string, fallback: string): string {
    if (typeof window === "undefined") {
        return fallback;
    }

    const styleRoot = document.body ?? document.documentElement;
    const value = window.getComputedStyle(styleRoot).getPropertyValue(name).trim();

    return value === "" ? fallback : value;
}

function alphaColor(rgbTriplet: string, alpha: number): string {
    return `rgba(${rgbTriplet}, ${alpha})`;
}

function resolveGridTheme(): Partial<Theme> {
    const accent = readCssVar("--mf-accent", "16, 185, 129");
    const accentSoft = readCssVar("--mf-accent-2", accent);
    const panelBg = readCssVar("--mf-input-bg", "rgba(8, 25, 19, 0.55)");
    const panelBgAlt = readCssVar("--mf-panel-bg-2", "rgba(9, 30, 22, 0.55)");
    const panelBorder = readCssVar("--mf-panel-border", "rgba(110, 231, 183, 0.12)");
    const panelBorderStrong = readCssVar("--mf-panel-strong-border", "rgba(110, 231, 183, 0.22)");
    const textPrimary = readCssVar("--mf-text-1", "rgba(236, 253, 245, 0.94)");
    const textSecondary = readCssVar("--mf-text-2", "rgba(209, 250, 229, 0.78)");
    const textMuted = readCssVar("--mf-text-3", "rgba(167, 243, 208, 0.58)");
    const fontBody = readCssVar(
        "--mf-font-body",
        "Manrope, ui-sans-serif, system-ui, sans-serif"
    );

    return {
        accentColor: alphaColor(accent, 1),
        accentFg: "#ecfdf5",
        accentLight: alphaColor(accent, 0.16),
        textDark: textPrimary,
        textMedium: textSecondary,
        textLight: textMuted,
        textBubble: textPrimary,
        bgIconHeader: alphaColor(accentSoft, 0.18),
        fgIconHeader: "#d1fae5",
        textHeader: textPrimary,
        textGroupHeader: textMuted,
        textHeaderSelected: "#ecfdf5",
        bgCell: panelBg,
        bgCellMedium: panelBgAlt,
        bgHeader: alphaColor(accent, 0.08),
        bgHeaderHasFocus: alphaColor(accent, 0.14),
        bgHeaderHovered: alphaColor(accent, 0.11),
        bgBubble: alphaColor(accent, 0.1),
        bgBubbleSelected: alphaColor(accent, 0.18),
        bgSearchResult: alphaColor(accent, 0.18),
        borderColor: panelBorder,
        drilldownBorder: panelBorderStrong,
        linkColor: alphaColor(accent, 1),
        cellHorizontalPadding: 14,
        cellVerticalPadding: 8,
        headerFontStyle: "600 13px",
        headerIconSize: 16,
        baseFontStyle: "13px",
        markerFontStyle: "600 12px",
        fontFamily: fontBody,
        editorFontSize: "13px",
        lineHeight: 1.4,
        resizeIndicatorColor: alphaColor(accent, 1),
        horizontalBorderColor: panelBorder,
        headerBottomBorderColor: panelBorderStrong,
        roundingRadius: 10,
    };
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
    const [gridWrapRef, gridBounds] = useElementSize<HTMLDivElement>();
    const [gridTheme] = useState<Partial<Theme>>(() => resolveGridTheme());
    const saveTimersRef = useRef<Map<string, number>>(new Map());
    const saveFlashTimerRef = useRef<number | null>(null);

    const gridColumns = buildGridColumns(meta);
    const canRenderGrid = gridBounds.width > 0 && gridBounds.height > 0;

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
                bgCell: "rgba(76, 5, 25, 0.92)",
                borderColor: "#fb7185",
                textDark: "#ffe4e6",
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

    const fieldClass =
        "h-11 w-full appearance-none rounded-2xl border border-white/10 bg-black/25 px-3 text-sm text-white/90 outline-none transition placeholder:text-emerald-50/35 focus:border-emerald-300/30 focus:bg-black/30";
    const buttonClass =
        "inline-flex h-11 items-center justify-center rounded-full border border-white/10 bg-white/5 px-4 text-sm font-medium text-white/85 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-50";

    return (
        <div className="flex h-full min-h-0 flex-col gap-5">
            <section className="rounded-3xl border border-white/10 bg-black/15 p-4 md:p-5">
                <div className="space-y-4">
                    <div>
                        <div className="text-[11px] uppercase tracking-[0.32em] text-emerald-100/50">
                            Resource Tables
                        </div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {resources.map((resource) => {
                                const isActive = resource.key === activeResource;

                                return (
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
                                            "max-w-full rounded-2xl border px-4 py-3 text-left transition " +
                                            (isActive
                                                ? "border-emerald-300/35 bg-emerald-500/15 text-emerald-50 shadow-[0_10px_28px_rgba(16,185,129,0.12)]"
                                                : "border-white/10 bg-white/5 text-white/75 hover:bg-white/10 hover:text-white")
                                        }
                                    >
                                        <span className="block text-sm font-semibold">{resource.label}</span>
                                        {isActive ? (
                                            <span className="mt-1 block text-[11px] leading-5 text-emerald-50/70">
                                                {resource.description}
                                            </span>
                                        ) : null}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div className="grid gap-3 md:grid-cols-2 2xl:grid-cols-[minmax(0,1.8fr)_12rem_12rem_8rem_auto_auto]">
                        <input
                            type="search"
                            value={searchInput}
                            onChange={(event) => setSearchInput(event.target.value)}
                            placeholder="Search name, code, abbreviation…"
                            className={fieldClass}
                        />

                        {meta?.supports_active_filter ? (
                            <select
                                value={activeFilter}
                                onChange={(event) => setActiveFilter(event.target.value)}
                                className={fieldClass}
                            >
                                <option value="">All Rows</option>
                                <option value="true">Active</option>
                                <option value="false">Inactive</option>
                            </select>
                        ) : null}

                        <select
                            value={sortField}
                            onChange={(event) => setSortField(event.target.value)}
                            className={fieldClass}
                        >
                            {(meta?.columns ?? []).length > 0 ? (
                                (meta?.columns ?? []).map((column) => (
                                    <option key={column.key} value={column.key}>
                                        Sort: {column.label}
                                    </option>
                                ))
                            ) : (
                                <option value="">Sort: Loading…</option>
                            )}
                        </select>

                        <select
                            value={sortDir}
                            onChange={(event) => setSortDir(event.target.value === "desc" ? "desc" : "asc")}
                            className={fieldClass}
                        >
                            <option value="asc">Ascending</option>
                            <option value="desc">Descending</option>
                        </select>

                        <button
                            type="button"
                            onClick={() => void handleAddRow()}
                            className={buttonClass}
                        >
                            Add Row
                        </button>

                        <button
                            type="button"
                            onClick={() => setReloadToken((current) => current + 1)}
                            className={buttonClass}
                        >
                            Refresh
                        </button>
                    </div>
                </div>
            </section>

            {error !== "" ? (
                <div className="rounded-2xl border border-rose-300/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-50">
                    {error}
                </div>
            ) : null}

            {notice !== "" ? (
                <div className="rounded-2xl border border-emerald-300/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-50">
                    {notice}
                </div>
            ) : null}

            <div className="flex min-h-0 flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                    <div className="text-xs font-medium uppercase tracking-[0.2em] text-emerald-100/60">
                        {gridStatus}
                    </div>
                    {Object.keys(cellErrors).length > 0 ? (
                        <div className="text-xs text-rose-100/90">
                            {Object.keys(cellErrors).length} cell error{Object.keys(cellErrors).length === 1 ? "" : "s"}
                        </div>
                    ) : null}
                </div>

                <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-3xl border border-white/10 bg-black/20 shadow-[inset_0_1px_0_rgba(255,255,255,0.03)]">
                    <div ref={gridWrapRef} className="h-full min-h-0 w-full">
                        {canRenderGrid ? (
                            <DataEditor
                                columns={gridColumns}
                                rows={rows.length}
                                getCellContent={getCellContent}
                                onCellEdited={handleCellEdited}
                                onCellClicked={handleCellClicked}
                                onPaste={true}
                                width={gridBounds.width}
                                height={gridBounds.height}
                                rowMarkers="number"
                                smoothScrollX={true}
                                smoothScrollY={true}
                                overscrollY={32}
                                rowHeight={40}
                                headerHeight={42}
                                theme={gridTheme}
                            />
                        ) : (
                            <div className="flex h-full items-center justify-center text-sm text-emerald-50/60">
                                Loading grid…
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="text-xs text-emerald-50/65">
                        Page {meta?.pagination.page ?? 1} of {meta?.pagination.last_page ?? 1}
                        {" · "}
                        {meta?.pagination.total ?? rows.length} total rows
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setPage((current) => Math.max(1, current - 1))}
                            disabled={(meta?.pagination.page ?? 1) <= 1}
                            className={buttonClass}
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
                            className={buttonClass}
                        >
                            Next
                        </button>
                    </div>
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
