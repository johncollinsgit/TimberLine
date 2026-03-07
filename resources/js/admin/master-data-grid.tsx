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
    type EditListItem,
    type Theme,
    type DataEditorRef,
    type CellClickedEventArgs,
} from "@glideapps/glide-data-grid";
import {
    startTransition,
    useDeferredValue,
    useEffect,
    useRef,
    useState,
    type CSSProperties,
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
    bulkEndpointBase: string;
};

type SaveState = "idle" | "saving" | "saved";
type CellPhase = "default" | "saving" | "saved" | "error";

type PendingChange = {
    id: number;
    field: string;
    value: unknown;
    previousValue: unknown;
};

type BulkSaveError = {
    id: number;
    field: string;
    message: string;
};

type SaveMode = "auto" | "manual";
type SaveBatchResult = {
    failedByKey: Record<string, string>;
    succeededKeys: string[];
};

type ElementSize = {
    width: number;
    height: number;
};

type CellBounds = {
    x: number;
    y: number;
    width: number;
    height: number;
};

type PopoverEditorState = {
    cell: Item;
    rowIndex: number;
    rowId: number;
    colIndex: number;
    field: string;
    type: ColumnMeta["type"];
    originalValue: unknown;
    draftValue: string;
    bounds: CellBounds;
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

function resolveGridTheme(): Theme {
    const accent = readCssVar("--mf-accent", "16, 185, 129");
    const accentSoft = readCssVar("--mf-accent-2", accent);
    const panelBorder = readCssVar("--mf-panel-border", "rgba(110, 231, 183, 0.12)");
    const panelBorderStrong = readCssVar("--mf-panel-strong-border", "rgba(110, 231, 183, 0.22)");
    const fontBody = readCssVar(
        "--mf-font-body",
        "Manrope, ui-sans-serif, system-ui, sans-serif"
    );
    const canvasText = "#e8fff5";
    const canvasTextMuted = "#b8d8ca";
    const canvasTextSubtle = "#7ca997";
    const canvasCell = "#091510";
    const canvasCellAlt = "#0d1b15";
    const canvasHeader = "#113428";
    const canvasHeaderFocus = "#164235";
    const canvasHeaderHover = "#153d30";
    const canvasBubble = "#163d31";
    const canvasBubbleSelected = "#1d4b3c";
    const canvasSearch = "#194536";

    return {
        accentColor: alphaColor(accent, 1),
        accentFg: "#ecfdf5",
        accentLight: alphaColor(accent, 0.16),
        textDark: canvasText,
        textMedium: canvasTextMuted,
        textLight: canvasTextSubtle,
        textBubble: canvasText,
        bgIconHeader: alphaColor(accentSoft, 0.18),
        fgIconHeader: "#d1fae5",
        textHeader: canvasText,
        textGroupHeader: canvasTextSubtle,
        textHeaderSelected: "#ecfdf5",
        bgCell: canvasCell,
        bgCellMedium: canvasCellAlt,
        bgHeader: canvasHeader,
        bgHeaderHasFocus: canvasHeaderFocus,
        bgHeaderHovered: canvasHeaderHover,
        bgBubble: canvasBubble,
        bgBubbleSelected: canvasBubbleSelected,
        bgSearchResult: canvasSearch,
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

function gridThemeVars(theme: Theme): CSSProperties {
    return {
        "--gdg-accent-color": theme.accentColor,
        "--gdg-accent-fg": theme.accentFg,
        "--gdg-accent-light": theme.accentLight,
        "--gdg-text-dark": theme.textDark,
        "--gdg-text-medium": theme.textMedium,
        "--gdg-text-light": theme.textLight,
        "--gdg-text-bubble": theme.textBubble,
        "--gdg-bg-icon-header": theme.bgIconHeader,
        "--gdg-fg-icon-header": theme.fgIconHeader,
        "--gdg-text-header": theme.textHeader,
        "--gdg-text-group-header": theme.textGroupHeader ?? theme.textHeader,
        "--gdg-text-header-selected": theme.textHeaderSelected,
        "--gdg-bg-cell": theme.bgCell,
        "--gdg-bg-cell-medium": theme.bgCellMedium,
        "--gdg-bg-header": theme.bgHeader,
        "--gdg-bg-header-has-focus": theme.bgHeaderHasFocus,
        "--gdg-bg-header-hovered": theme.bgHeaderHovered,
        "--gdg-bg-bubble": theme.bgBubble,
        "--gdg-bg-bubble-selected": theme.bgBubbleSelected,
        "--gdg-bg-search-result": theme.bgSearchResult,
        "--gdg-border-color": theme.borderColor,
        "--gdg-horizontal-border-color": theme.horizontalBorderColor ?? theme.borderColor,
        "--gdg-drilldown-border": theme.drilldownBorder,
        "--gdg-link-color": theme.linkColor,
        "--gdg-cell-horizontal-padding": `${theme.cellHorizontalPadding}px`,
        "--gdg-cell-vertical-padding": `${theme.cellVerticalPadding}px`,
        "--gdg-header-font-style": theme.headerFontStyle,
        "--gdg-base-font-style": theme.baseFontStyle,
        "--gdg-marker-font-style": theme.markerFontStyle,
        "--gdg-font-family": theme.fontFamily,
        "--gdg-editor-font-size": theme.editorFontSize,
        "--gdg-resize-indicator-color": theme.resizeIndicatorColor,
        "--gdg-header-bottom-border-color": theme.headerBottomBorderColor,
        "--gdg-rounding-radius":
            theme.roundingRadius == null ? undefined : `${theme.roundingRadius}px`,
    } as CSSProperties;
}

function normalizeText(value: string): string {
    return value.trim().replace(/\s+/g, " ");
}

function clamp(value: number, min: number, max: number): number {
    return Math.max(min, Math.min(value, max));
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
        bulkEndpointBase: root.dataset.bulkEndpointBase || "/admin/master-data",
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

function draftValueForPopover(column: ColumnMeta, value: unknown): string {
    if (column.type === "select") {
        return optionLabelFor(column, value);
    }

    if (value == null) {
        return "";
    }

    return String(value);
}

function coerceValueFromPopoverDraft(
    column: ColumnMeta,
    draftValue: string,
    previousValue: unknown
): unknown {
    if (column.type === "number") {
        const normalized = normalizeText(draftValue);
        if (normalized === "") {
            return column.nullable ? null : 0;
        }

        const parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : previousValue;
    }

    if (column.type === "select") {
        const normalized = normalizeText(draftValue);
        if (normalized === "") {
            return column.nullable ? null : previousValue;
        }

        const options = Array.isArray(column.options) ? column.options : [];
        const match = options.find((option) => {
            return (
                String(option.value) === normalized ||
                option.label.localeCompare(normalized, undefined, { sensitivity: "accent" }) === 0
            );
        });

        return match ? match.value : previousValue;
    }

    const normalized = normalizeText(draftValue);
    if (normalized === "") {
        return column.nullable ? null : "";
    }

    return normalized;
}

function isSameCellValue(column: ColumnMeta, a: unknown, b: unknown): boolean {
    if (column.type === "number") {
        if (a == null && b == null) {
            return true;
        }

        return Number(a) === Number(b);
    }

    if (column.type === "checkbox") {
        return Boolean(a) === Boolean(b);
    }

    return String(a ?? "") === String(b ?? "");
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

function cellChangeKey(id: number, field: string): string {
    return `${id}:${field}`;
}

function applyPendingChanges(
    rows: RowData[],
    pendingChanges: Record<string, PendingChange>
): RowData[] {
    if (Object.keys(pendingChanges).length === 0) {
        return rows;
    }

    return rows.map((row) => {
        let nextRow = row;

        Object.values(pendingChanges).forEach((change) => {
            if (change.id !== row.id) {
                return;
            }

            if (nextRow === row) {
                nextRow = { ...row };
            }

            nextRow[change.field] = change.value;
        });

        return nextRow;
    });
}

function MasterDataGridApp(props: RootDataset) {
    const { resources, baseEndpoint, bulkEndpointBase } = props;
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
    const [pendingChanges, setPendingChanges] = useState<Record<string, PendingChange>>({});
    const [cellErrors, setCellErrors] = useState<Record<string, string>>({});
    const [savingCellKeys, setSavingCellKeys] = useState<Record<string, true>>({});
    const [savedCellKeys, setSavedCellKeys] = useState<Record<string, true>>({});
    const [gridWrapRef, gridBounds] = useElementSize<HTMLDivElement>();
    const [gridTheme] = useState<Theme>(() => resolveGridTheme());
    const dataEditorRef = useRef<DataEditorRef | null>(null);
    const pendingChangesRef = useRef<Record<string, PendingChange>>({});
    const saveFlashTimerRef = useRef<number | null>(null);
    const savedCellTimerRef = useRef<Record<string, number>>({});
    const saveQueueRef = useRef<Promise<void>>(Promise.resolve());
    const [selectedCell, setSelectedCell] = useState<Item | null>(null);
    const [popoverEditor, setPopoverEditor] = useState<PopoverEditorState | null>(null);
    const [popoverError, setPopoverError] = useState("");
    const popoverRef = useRef<HTMLDivElement | null>(null);
    const popoverInputRef = useRef<HTMLInputElement | HTMLSelectElement | null>(null);
    const [diagnostics, setDiagnostics] = useState({
        popoverVisible: false,
        focusInsideEditor: false,
        lastFailedSave: "",
    });

    const gridColumns = buildGridColumns(meta);
    const activeResourceMeta =
        resources.find((resource) => resource.key === activeResource) ?? null;
    const canRenderGrid = gridBounds.width > 0 && gridBounds.height > 0;
    const gridViewportHeight = Math.max(gridBounds.height, 360);
    const dirtyCount = Object.keys(pendingChanges).length;

    useEffect(() => {
        axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
    }, []);

    useEffect(() => {
        pendingChangesRef.current = pendingChanges;
    }, [pendingChanges]);

    useEffect(() => {
        return () => {
            Object.values(savedCellTimerRef.current).forEach((timer) => {
                window.clearTimeout(timer);
            });
            savedCellTimerRef.current = {};
        };
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

                const nextRows = Array.isArray(response.data?.data)
                    ? (response.data.data as RowData[])
                    : [];
                const nextMeta = (response.data?.meta ?? null) as ResponseMeta | null;

                startTransition(() => {
                    setRows(applyPendingChanges(nextRows, pendingChangesRef.current));
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

    useEffect(() => {
        if (!popoverEditor) {
            setDiagnostics((current) => ({
                ...current,
                popoverVisible: false,
                focusInsideEditor: false,
            }));
            return;
        }

        setDiagnostics((current) => ({
            ...current,
            popoverVisible: true,
        }));

        const timer = window.setTimeout(() => {
            popoverInputRef.current?.focus();
            setDiagnostics((current) => ({
                ...current,
                focusInsideEditor:
                    popoverInputRef.current != null &&
                    document.activeElement === popoverInputRef.current,
            }));
        }, 0);

        return () => window.clearTimeout(timer);
    }, [popoverEditor]);

    const updateRowValue = (rowIndex: number, field: string, nextValue: unknown) => {
        setRows((currentRows) =>
            currentRows.map((row, index) =>
                index === rowIndex ? { ...row, [field]: nextValue } : row
            )
        );
    };

    const trackPendingChange = (
        rowId: number,
        field: string,
        previousValue: unknown,
        nextValue: unknown
    ) => {
        const changeKey = cellChangeKey(rowId, field);

        setPendingChanges((current) => {
            const existing = current[changeKey];
            const baseline = existing ? existing.previousValue : previousValue;

            if (Object.is(nextValue, baseline)) {
                if (!existing) {
                    return current;
                }

                const next = { ...current };
                delete next[changeKey];
                return next;
            }

            return {
                ...current,
                [changeKey]: {
                    id: rowId,
                    field,
                    value: nextValue,
                    previousValue: baseline,
                },
            };
        });

        setCellErrors((current) => {
            if (!current[changeKey]) {
                return current;
            }

            const next = { ...current };
            delete next[changeKey];
            return next;
        });
        setSaveState("idle");
        setNotice("");
        setError("");
    };

    const markCellsSaved = (keys: string[]) => {
        if (keys.length === 0) {
            return;
        }

        setSavedCellKeys((current) => {
            const next = { ...current };
            keys.forEach((key) => {
                next[key] = true;
            });

            return next;
        });

        keys.forEach((key) => {
            const existingTimer = savedCellTimerRef.current[key];
            if (existingTimer != null) {
                window.clearTimeout(existingTimer);
            }

            savedCellTimerRef.current[key] = window.setTimeout(() => {
                setSavedCellKeys((current) => {
                    if (!current[key]) {
                        return current;
                    }

                    const next = { ...current };
                    delete next[key];
                    return next;
                });
                delete savedCellTimerRef.current[key];
            }, 1000);
        });
    };

    const saveChangesBatch = async (
        incomingBatch: PendingChange[],
        mode: SaveMode
    ): Promise<SaveBatchResult> => {
        const batchMap = new Map<string, PendingChange>();

        incomingBatch.forEach((change) => {
            batchMap.set(cellChangeKey(change.id, change.field), change);
        });

        const batch = Array.from(batchMap.values());

        if (batch.length === 0) {
            if (mode === "manual") {
                setNotice("No changes to save.");
            }
            return { failedByKey: {}, succeededKeys: [] };
        }

        const batchKeys = Array.from(batchMap.keys());
        const batchValueMap = new Map(
            batch.map((change) => [cellChangeKey(change.id, change.field), change.value])
        );

        setSavingCellKeys((current) => {
            const next = { ...current };
            batchKeys.forEach((key) => {
                next[key] = true;
            });
            return next;
        });

        let failedByKey: Record<string, string> = {};
        let succeededKeys: string[] = [];

        try {
            setSaveState("saving");
            setNotice(
                mode === "manual"
                    ? `Saving ${batch.length} change${batch.length === 1 ? "" : "s"}…`
                    : "Saving…"
            );
            setError("");

            const response = await axios.post(
                `${bulkEndpointBase}/${activeResource}/bulk-update`,
                {
                    changes: batch.map(({ id, field, value }) => ({
                        id,
                        field,
                        value,
                    })),
                }
            );

            const responseErrors = Array.isArray(response.data?.errors)
                ? (response.data.errors as BulkSaveError[])
                : [];
            failedByKey = Object.fromEntries(
                responseErrors.map((item) => [cellChangeKey(item.id, item.field), item.message])
            );
            const failedKeys = new Set(
                responseErrors.map((item) => cellChangeKey(item.id, item.field))
            );
            succeededKeys = batchKeys.filter((key) => !failedKeys.has(key));

            setPendingChanges((current) => {
                const next = { ...current };

                batch.forEach((change) => {
                    const changeKey = cellChangeKey(change.id, change.field);

                    if (failedKeys.has(changeKey)) {
                        return;
                    }

                    if (!next[changeKey]) {
                        return;
                    }

                    if (Object.is(next[changeKey].value, batchValueMap.get(changeKey))) {
                        delete next[changeKey];
                    }
                });

                return next;
            });

            setCellErrors((current) => {
                const next = { ...current };

                batch.forEach((change) => {
                    delete next[cellChangeKey(change.id, change.field)];
                });

                responseErrors.forEach((item) => {
                    next[cellChangeKey(item.id, item.field)] = item.message;
                });

                return next;
            });

            markCellsSaved(succeededKeys);

            if (responseErrors.length === 0) {
                setSaveState("saved");
                setNotice(
                    mode === "manual"
                        ? "Changes saved."
                        : "Saved."
                );
                return { failedByKey, succeededKeys };
            }

            setSaveState("idle");
            setNotice(
                response.data?.updated > 0
                    ? `Saved ${response.data.updated} change${response.data.updated === 1 ? "" : "s"}; fix highlighted cells.`
                    : "Fix highlighted cells and save again."
            );
            return { failedByKey, succeededKeys };
        } catch (requestError) {
            const message = axios.isAxiosError(requestError)
                ? requestError.response?.data?.message || "Could not save changes."
                : "Could not save changes.";
            console.error("[master-data-grid] save batch failed", {
                message,
                activeResource,
                batch,
            });
            failedByKey = Object.fromEntries(batchKeys.map((key) => [key, message]));

            setCellErrors((current) => {
                const next = { ...current };
                batchKeys.forEach((key) => {
                    if (!next[key]) {
                        next[key] = message;
                    }
                });
                return next;
            });

            setSaveState("idle");
            setNotice("");
            setError(message);
            setDiagnostics((current) => ({
                ...current,
                lastFailedSave: message,
            }));
            return { failedByKey, succeededKeys: [] };
        } finally {
            setSavingCellKeys((current) => {
                const next = { ...current };
                batchKeys.forEach((key) => {
                    delete next[key];
                });
                return next;
            });
        }
    };

    const queueBatchSave = (batch: PendingChange[], mode: SaveMode) => {
        saveQueueRef.current = saveQueueRef.current
            .catch(() => undefined)
            .then(async () => {
                await saveChangesBatch(batch, mode);
            });
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
            setPendingChanges((current) => {
                const next = { ...current };

                Object.keys(next).forEach((key) => {
                    if (next[key]?.id === row.id) {
                        delete next[key];
                    }
                });

                return next;
            });
            setCellErrors((current) => {
                const next = { ...current };

                Object.keys(next).forEach((key) => {
                    if (key.startsWith(`${row.id}:`)) {
                        delete next[key];
                    }
                });

                return next;
            });
            setSavingCellKeys((current) => {
                const next = { ...current };
                Object.keys(next).forEach((key) => {
                    if (key.startsWith(`${row.id}:`)) {
                        delete next[key];
                    }
                });
                return next;
            });
            setSavedCellKeys((current) => {
                const next = { ...current };
                Object.keys(next).forEach((key) => {
                    if (key.startsWith(`${row.id}:`)) {
                        delete next[key];
                    }
                });
                return next;
            });
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

    const actionColumnIndex = meta?.columns.length ?? -1;

    const getCellBounds = (cell: Item): CellBounds | null => {
        const bounds = dataEditorRef.current?.getBounds(cell);
        if (!bounds) {
            return null;
        }

        return {
            x: bounds.x,
            y: bounds.y,
            width: bounds.width,
            height: bounds.height,
        };
    };

    const isPopoverEditableColumn = (column: ColumnMeta | null): column is ColumnMeta => {
        return column != null && (column.type === "text" || column.type === "number" || column.type === "select");
    };

    const openPopoverEditor = (cell: Item, fallbackBounds?: CellBounds | null) => {
        const [colIndex, rowIndex] = cell;
        const columnMeta = getColumnMeta(colIndex);
        const rowData = rows[rowIndex];

        if (!rowData || !isPopoverEditableColumn(columnMeta)) {
            return;
        }

        const bounds = fallbackBounds ?? getCellBounds(cell);
        if (!bounds) {
            return;
        }

        setPopoverError("");
        setPopoverEditor({
            cell,
            rowIndex,
            rowId: rowData.id,
            colIndex,
            field: columnMeta.key,
            type: columnMeta.type,
            originalValue: rowData[columnMeta.key],
            draftValue: draftValueForPopover(columnMeta, rowData[columnMeta.key]),
            bounds,
        });
    };

    const closePopoverEditor = () => {
        setPopoverEditor(null);
        setPopoverError("");
    };

    const findRelativeEditableCell = (
        fromCell: Item,
        direction: "next" | "prev"
    ): Item | null => {
        if (!meta) {
            return null;
        }

        const editableCols = meta.columns
            .map((column, index) => ({ column, index }))
            .filter(({ column }) => column.type !== "checkbox")
            .map(({ index }) => index);

        if (editableCols.length === 0 || rows.length === 0) {
            return null;
        }

        const [fromCol, fromRow] = fromCell;
        const totalCells = rows.length * editableCols.length;
        if (totalCells === 0) {
            return null;
        }

        const currentColPosition = Math.max(0, editableCols.indexOf(fromCol));
        const currentLinear = fromRow * editableCols.length + currentColPosition;
        const offset = direction === "next" ? 1 : -1;
        const nextLinear = currentLinear + offset;

        if (nextLinear < 0 || nextLinear >= totalCells) {
            return null;
        }

        const nextRow = Math.floor(nextLinear / editableCols.length);
        const nextCol = editableCols[nextLinear % editableCols.length];

        return [nextCol, nextRow];
    };

    const commitPopoverEditor = async (
        direction: "stay" | "next" | "prev" | "close"
    ) => {
        if (!popoverEditor) {
            return;
        }

        const columnMeta = getColumnMeta(popoverEditor.colIndex);
        if (!columnMeta) {
            closePopoverEditor();
            return;
        }

        const nextValue = coerceValueFromPopoverDraft(
            columnMeta,
            popoverEditor.draftValue,
            popoverEditor.originalValue
        );
        const changeKey = cellChangeKey(popoverEditor.rowId, popoverEditor.field);

        if (isSameCellValue(columnMeta, nextValue, popoverEditor.originalValue)) {
            if (direction === "next" || direction === "prev") {
                const relativeCell = findRelativeEditableCell(popoverEditor.cell, direction);
                if (relativeCell) {
                    setSelectedCell(relativeCell);
                    const nextBounds = getCellBounds(relativeCell);
                    if (nextBounds) {
                        openPopoverEditor(relativeCell, nextBounds);
                        return;
                    }
                }
            }

            closePopoverEditor();
            return;
        }

        updateRowValue(popoverEditor.rowIndex, popoverEditor.field, nextValue);
        trackPendingChange(
            popoverEditor.rowId,
            popoverEditor.field,
            popoverEditor.originalValue,
            nextValue
        );

        const result = await saveChangesBatch([
            {
                id: popoverEditor.rowId,
                field: popoverEditor.field,
                value: nextValue,
                previousValue: popoverEditor.originalValue,
            },
        ], "auto");

        const failure = result.failedByKey[changeKey];
        if (failure) {
            updateRowValue(
                popoverEditor.rowIndex,
                popoverEditor.field,
                popoverEditor.originalValue
            );
            setPopoverError(failure);
            setDiagnostics((current) => ({
                ...current,
                lastFailedSave: failure,
            }));
            return;
        }

        if (direction === "next" || direction === "prev") {
            const relativeCell = findRelativeEditableCell(popoverEditor.cell, direction);
            if (relativeCell) {
                setSelectedCell(relativeCell);
                const nextBounds = getCellBounds(relativeCell);
                if (nextBounds) {
                    openPopoverEditor(relativeCell, nextBounds);
                    return;
                }
            }
        }

        closePopoverEditor();
    };

    const cancelPopoverEditor = () => {
        if (!popoverEditor) {
            return;
        }

        updateRowValue(
            popoverEditor.rowIndex,
            popoverEditor.field,
            popoverEditor.originalValue
        );
        closePopoverEditor();
    };

    const refreshPopoverBounds = () => {
        if (!popoverEditor) {
            return;
        }

        const nextBounds = getCellBounds(popoverEditor.cell);
        if (!nextBounds) {
            return;
        }

        setPopoverEditor((current) => {
            if (!current) {
                return current;
            }

            return {
                ...current,
                bounds: nextBounds,
            };
        });
    };

    useEffect(() => {
        if (!popoverEditor) {
            return;
        }

        const onPointerDown = (event: MouseEvent) => {
            if (!popoverRef.current) {
                return;
            }

            const target = event.target as Node | null;
            if (target && popoverRef.current.contains(target)) {
                return;
            }

            void commitPopoverEditor("stay");
        };

        document.addEventListener("mousedown", onPointerDown, true);
        window.addEventListener("resize", refreshPopoverBounds);

        return () => {
            document.removeEventListener("mousedown", onPointerDown, true);
            window.removeEventListener("resize", refreshPopoverBounds);
        };
    }, [popoverEditor]);

    const cellPhase = (changeKey: string): CellPhase => {
        if (cellErrors[changeKey]) {
            return "error";
        }

        if (savingCellKeys[changeKey]) {
            return "saving";
        }

        if (savedCellKeys[changeKey]) {
            return "saved";
        }

        return "default";
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

        const changeKey = cellChangeKey(rowData.id, columnMeta.key);
        const hasPendingChange = Boolean(pendingChanges[changeKey]);
        const phase = cellPhase(changeKey);
        const themeOverride =
            phase === "error"
                ? {
                    bgCell: "rgba(76, 5, 25, 0.92)",
                    borderColor: "#fb7185",
                    textDark: "#ffe4e6",
                }
                : phase === "saving"
                    ? {
                        bgCell: "rgba(12, 32, 51, 0.92)",
                        borderColor: "#38bdf8",
                        textDark: "#e0f2fe",
                    }
                    : phase === "saved"
                                ? {
                                    bgCell: "rgba(6, 48, 37, 0.9)",
                                    borderColor: "#10b981",
                                    textDark: "#ecfdf5",
                                }
                                : hasPendingChange
                                    ? {
                                        bgCell: "rgba(59, 35, 6, 0.9)",
                                        borderColor: "#f59e0b",
                                        textDark: "#fef3c7",
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
                allowOverlay: false,
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
            allowOverlay: false,
            data: textValue,
            displayData: textValue === "" && columnMeta.type === "select" ? "Select…" : textValue,
            readonly: false,
            themeOverride,
        };
    };

    const applyEdits = (edits: readonly EditListItem[]) => {
        const changed: PendingChange[] = [];

        edits.forEach((edit) => {
            const [col, rowIndex] = edit.location;
            const columnMeta = getColumnMeta(col);
            const row = rows[rowIndex];

            if (!columnMeta || !row) {
                return;
            }

            if (columnMeta.type !== "checkbox") {
                return;
            }

            const previousValue = row[columnMeta.key];
            const nextValue = coerceValueFromCell(columnMeta, edit.value, previousValue);

            if (Object.is(nextValue, previousValue)) {
                return;
            }

            updateRowValue(rowIndex, columnMeta.key, nextValue);
            trackPendingChange(row.id, columnMeta.key, previousValue, nextValue);
            changed.push({
                id: row.id,
                field: columnMeta.key,
                value: nextValue,
                previousValue,
            });
        });

        if (changed.length > 0) {
            queueBatchSave(changed, "auto");
        }
    };

    const handleCellsEdited = (edits: readonly EditListItem[]) => {
        applyEdits(edits);
        return true;
    };

    const handleCellClicked = (cell: Item, event: CellClickedEventArgs) => {
        const [col, rowIndex] = cell;
        setSelectedCell(cell);
        const isActionColumn = col === actionColumnIndex;

        if (isActionColumn) {
            closePopoverEditor();
            void handleDeleteRow(rowIndex);
            return;
        }

        const columnMeta = getColumnMeta(col);
        if (!isPopoverEditableColumn(columnMeta)) {
            return;
        }

        if (!event.isDoubleClick) {
            return;
        }

        event.preventDefault();
        openPopoverEditor(cell, {
            x: event.bounds.x,
            y: event.bounds.y,
            width: event.bounds.width,
            height: event.bounds.height,
        });
    };

    const handleCellActivated = (cell: Item) => {
        const [col] = cell;
        setSelectedCell(cell);

        if (col === actionColumnIndex) {
            return;
        }

        const columnMeta = getColumnMeta(col);
        if (!isPopoverEditableColumn(columnMeta)) {
            return;
        }

        openPopoverEditor(cell);
    };

    const gridStatus =
        saveState === "saving"
            ? "Saving…"
            : dirtyCount > 0
                ? `${dirtyCount} unsaved change${dirtyCount === 1 ? "" : "s"}`
            : saveState === "saved"
                ? "Saved"
                : loading
                    ? "Loading…"
                    : `${meta?.pagination.total ?? rows.length} row${(meta?.pagination.total ?? rows.length) === 1 ? "" : "s"}`;

    const gridCssVars = gridThemeVars(gridTheme);
    const fieldClass =
        "h-11 w-full appearance-none rounded-2xl border border-white/10 bg-black/25 px-3 text-sm text-white/90 outline-none transition placeholder:text-emerald-50/35 focus:border-emerald-300/30 focus:bg-black/30";
    const buttonClass =
        "inline-flex h-11 shrink-0 appearance-none items-center justify-center rounded-full border border-emerald-300/20 bg-black/25 px-4 text-sm font-medium text-emerald-50/85 shadow-[inset_0_1px_0_rgba(255,255,255,0.03)] transition hover:border-emerald-300/30 hover:bg-emerald-500/10 hover:text-white disabled:cursor-not-allowed disabled:opacity-50";
    const popoverColumnMeta = popoverEditor ? getColumnMeta(popoverEditor.colIndex) : null;
    const popoverChangeKey =
        popoverEditor != null ? cellChangeKey(popoverEditor.rowId, popoverEditor.field) : null;
    const popoverPhase = popoverChangeKey ? cellPhase(popoverChangeKey) : "default";
    const popoverStatus =
        popoverError !== ""
            ? popoverError
            : popoverPhase === "saving"
                ? "Saving…"
                : popoverPhase === "saved"
                    ? "Saved"
                    : "";
    const popoverStatusTone =
        popoverError !== ""
            ? "text-rose-100/95"
            : popoverPhase === "saving"
                ? "text-sky-100/95"
                : "text-emerald-100/95";
    const popoverPosition = (() => {
        if (!popoverEditor) {
            return null;
        }

        const desiredWidth = clamp(popoverEditor.bounds.width + 24, 240, 460);
        const maxWidth = Math.max(220, gridBounds.width - 16);
        const width = Math.min(desiredWidth, maxWidth);
        const left = clamp(popoverEditor.bounds.x, 8, Math.max(8, gridBounds.width - width - 8));
        const estimatedHeight = 156;
        const spaceBelow = gridViewportHeight - (popoverEditor.bounds.y + popoverEditor.bounds.height);
        const top =
            spaceBelow > estimatedHeight
                ? popoverEditor.bounds.y + popoverEditor.bounds.height + 6
                : Math.max(8, popoverEditor.bounds.y - estimatedHeight - 6);

        return { left, top, width };
    })();

    return (
        <div className="flex h-full min-h-0 flex-col gap-5">
            <section className="rounded-3xl border border-white/10 bg-black/15 p-4 md:p-5">
                <div className="space-y-4">
                    <div>
                        <div className="text-[11px] uppercase tracking-[0.32em] text-emerald-100/50">
                            Resource Tables
                        </div>
                        <div className="mt-3 flex gap-2 overflow-x-auto pb-1 whitespace-nowrap [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
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
                                                setPendingChanges({});
                                                setCellErrors({});
                                                setSavingCellKeys({});
                                                setSavedCellKeys({});
                                                setSaveState("idle");
                                            });
                                        }}
                                        className={
                                            "inline-flex h-10 shrink-0 items-center rounded-md border px-3 text-xs font-semibold transition " +
                                            (isActive
                                                ? "border-emerald-300/30 bg-emerald-500/12 text-emerald-50"
                                                : "border-white/10 bg-black/25 text-emerald-50/70 hover:border-emerald-300/20 hover:bg-emerald-500/8 hover:text-white")
                                        }
                                    >
                                        {resource.label}
                                    </button>
                                );
                            })}
                        </div>
                        {activeResourceMeta?.description ? (
                            <div className="mt-2 text-xs text-emerald-50/60">
                                {activeResourceMeta.description}
                            </div>
                        ) : null}
                    </div>

                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-[16rem] flex-1 basis-[20rem]">
                            <input
                                type="search"
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search name, code, abbreviation…"
                                className={fieldClass}
                            />
                        </div>

                        {meta?.supports_active_filter ? (
                            <div className="w-full min-w-[11rem] flex-none sm:w-auto">
                                <select
                                    value={activeFilter}
                                    onChange={(event) => setActiveFilter(event.target.value)}
                                    className={fieldClass}
                                >
                                    <option value="">All Rows</option>
                                    <option value="true">Active</option>
                                    <option value="false">Inactive</option>
                                </select>
                            </div>
                        ) : null}

                        <div className="w-full min-w-[12rem] flex-none sm:w-auto">
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
                        </div>

                        <div className="w-full min-w-[10rem] flex-none sm:w-auto">
                            <select
                                value={sortDir}
                                onChange={(event) => setSortDir(event.target.value === "desc" ? "desc" : "asc")}
                                className={fieldClass}
                            >
                                <option value="asc">Ascending</option>
                                <option value="desc">Descending</option>
                            </select>
                        </div>

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

            <div
                className="sr-only"
                data-testid="md-diagnostics"
                data-popover-visible={diagnostics.popoverVisible ? "true" : "false"}
                data-focus-inside-editor={diagnostics.focusInsideEditor ? "true" : "false"}
                data-last-failed-save={diagnostics.lastFailedSave}
            />

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

                <div className="flex min-h-[18rem] flex-1 flex-col overflow-hidden rounded-3xl border border-white/10 bg-black/20 shadow-[inset_0_1px_0_rgba(255,255,255,0.03)] sm:min-h-[24rem]">
                    <div
                        ref={gridWrapRef}
                        className="relative flex-1 min-h-[16rem] w-full sm:min-h-[22rem]"
                        style={gridCssVars}
                    >
                        {canRenderGrid ? (
                            <DataEditor
                                key={activeResource}
                                ref={dataEditorRef}
                                columns={gridColumns}
                                rows={rows.length}
                                getCellContent={getCellContent}
                                onCellEdited={(cell, newValue) =>
                                    applyEdits([{ location: cell, value: newValue }])
                                }
                                onCellsEdited={handleCellsEdited}
                                onCellClicked={handleCellClicked}
                                onCellActivated={handleCellActivated}
                                onPaste={true}
                                width={gridBounds.width}
                                height={gridViewportHeight}
                                rowMarkers={{ kind: "number", theme: gridTheme }}
                                cellActivationBehavior="second-click"
                                editOnType={false}
                                smoothScrollX={true}
                                smoothScrollY={true}
                                preventDiagonalScrolling={true}
                                overscrollX={96}
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
                        {popoverEditor && popoverColumnMeta && popoverPosition ? (
                            <div
                                ref={popoverRef}
                                data-testid="md-popover-editor"
                                className="absolute z-40 rounded-xl border border-emerald-300/25 bg-[#11231d]/96 p-3 shadow-[0_16px_40px_-24px_rgba(0,0,0,0.85)] backdrop-blur"
                                style={{
                                    left: popoverPosition.left,
                                    top: popoverPosition.top,
                                    width: popoverPosition.width,
                                }}
                            >
                                <div className="mb-1 text-[10px] uppercase tracking-[0.22em] text-emerald-100/60">
                                    {popoverColumnMeta.label}
                                </div>
                                {popoverColumnMeta.type === "select" ? (
                                    <>
                                        <input
                                            ref={(element) => {
                                                popoverInputRef.current = element;
                                            }}
                                            data-testid="md-popover-input"
                                            type="text"
                                            list={`md-options-${popoverColumnMeta.key}`}
                                            value={popoverEditor.draftValue}
                                            onChange={(event) => {
                                                const nextDraft = event.target.value;
                                                setPopoverEditor((current) =>
                                                    current
                                                        ? {
                                                            ...current,
                                                            draftValue: nextDraft,
                                                        }
                                                        : current
                                                );
                                                setPopoverError("");
                                            }}
                                            onKeyDown={(event) => {
                                                if (event.key === "Escape") {
                                                    event.preventDefault();
                                                    cancelPopoverEditor();
                                                    return;
                                                }

                                                if (event.key === "Tab") {
                                                    event.preventDefault();
                                                    void commitPopoverEditor(event.shiftKey ? "prev" : "next");
                                                    return;
                                                }

                                                if (event.key === "Enter") {
                                                    event.preventDefault();
                                                    void commitPopoverEditor("close");
                                                }
                                            }}
                                            className="h-10 w-full rounded-lg border border-white/12 bg-black/30 px-3 text-sm text-emerald-50/95 outline-none transition placeholder:text-emerald-100/40 focus:border-emerald-300/40"
                                            placeholder="Type to search options…"
                                        />
                                        <datalist id={`md-options-${popoverColumnMeta.key}`}>
                                            {(popoverColumnMeta.options ?? []).map((option) => (
                                                <option key={`${option.value}`} value={option.label}>
                                                    {option.label}
                                                </option>
                                            ))}
                                        </datalist>
                                    </>
                                ) : (
                                    <input
                                        ref={(element) => {
                                            popoverInputRef.current = element;
                                        }}
                                        data-testid="md-popover-input"
                                        type={popoverColumnMeta.type === "number" ? "number" : "text"}
                                        value={popoverEditor.draftValue}
                                        onChange={(event) => {
                                            const nextDraft = event.target.value;
                                            setPopoverEditor((current) =>
                                                current
                                                    ? {
                                                        ...current,
                                                        draftValue: nextDraft,
                                                    }
                                                    : current
                                            );
                                            setPopoverError("");
                                        }}
                                        onKeyDown={(event) => {
                                            if (event.key === "Escape") {
                                                event.preventDefault();
                                                cancelPopoverEditor();
                                                return;
                                            }

                                            if (event.key === "Tab") {
                                                event.preventDefault();
                                                void commitPopoverEditor(event.shiftKey ? "prev" : "next");
                                                return;
                                            }

                                            if (event.key === "Enter") {
                                                event.preventDefault();
                                                void commitPopoverEditor("close");
                                            }
                                        }}
                                        className="h-10 w-full rounded-lg border border-white/12 bg-black/30 px-3 text-sm text-emerald-50/95 outline-none transition placeholder:text-emerald-100/40 focus:border-emerald-300/40"
                                    />
                                )}
                                {popoverStatus !== "" ? (
                                    <div
                                        data-testid={popoverError !== "" ? "md-popover-error" : "md-popover-status"}
                                        className={`mt-2 text-xs ${popoverStatusTone}`}
                                    >
                                        {popoverStatus}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
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

function mountMasterDataGrid() {
    const rootElement = document.getElementById("master-data-grid");

    if (!rootElement || rootElement.dataset.mfMounted === "1") {
        return;
    }

    rootElement.dataset.mfMounted = "1";
    rootElement.replaceChildren();
    const root = createRoot(rootElement);
    root.render(<MasterDataGridApp {...parseRootDataset(rootElement)} />);
}

mountMasterDataGrid();

if (typeof window !== "undefined") {
    const registry = window as typeof window & {
        __mfMasterDataGridBound?: boolean;
    };

    if (!registry.__mfMasterDataGridBound) {
        registry.__mfMasterDataGridBound = true;
        document.addEventListener("livewire:navigated", mountMasterDataGrid);
    }
}
