import "../bootstrap";
import axios from "axios";
import "@glideapps/glide-data-grid/dist/index.css";
import {
    DataEditor,
    GridCell,
    GridCellKind,
    GridColumn,
    Item,
    type Theme,
} from "@glideapps/glide-data-grid";
import {
    useDeferredValue,
    useEffect,
    useMemo,
    useRef,
    useState,
    type CSSProperties,
    type RefObject,
} from "react";
import { createRoot } from "react-dom/client";

const SEARCH_INPUT_ID = "marketing-customers-search";

type SortOption = {
    value: string;
    label: string;
};

type ColumnMeta = {
    key: string;
    label: string;
    type: "text" | "number";
};

type PaginationMeta = {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
};

type FilterState = {
    search: string;
    sort: string;
    dir: "asc" | "desc";
    per_page: number;
    birthday_filter: string;
    source: string;
    has_points: string;
    has_phone: string;
    status: string;
};

type ResponseMeta = {
    columns: ColumnMeta[];
    pagination: PaginationMeta;
    filters: FilterState;
    sort_options: SortOption[];
};

type RowData = {
    id: number;
    profile_url: string;
    [key: string]: unknown;
};

type RootDataset = {
    endpoint: string;
    addCustomerUrl: string;
    messageCustomerUrl: string;
    bulkActionUrl: string;
    operationalDirectory: boolean;
    initialFilters: FilterState;
    sortOptions: SortOption[];
};

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

function resolveGridTheme(): Theme {
    const accent = readCssVar("--shopify-accent", "0, 128, 96");
    const panelBorder = readCssVar("--shopify-panel-border", "#e1e3e5");
    const panelBorderStrong = readCssVar("--shopify-panel-strong-border", "#c9cccf");
    const fontBody = readCssVar("--shopify-font-body", "Inter, ui-sans-serif, system-ui, sans-serif");

    return {
        accentColor: alphaColor(accent, 1),
        accentFg: "#ffffff",
        accentLight: "#e3f1df",
        textDark: "#202223",
        textMedium: "#6d7175",
        textLight: "#8c9196",
        textBubble: "#202223",
        bgIconHeader: "#f1f2f3",
        fgIconHeader: "#5c5f62",
        textHeader: "#202223",
        textGroupHeader: "#6d7175",
        textHeaderSelected: "#202223",
        bgCell: "#ffffff",
        bgCellMedium: "#f6f6f7",
        bgHeader: "#f6f6f7",
        bgHeaderHasFocus: "#edeeef",
        bgHeaderHovered: "#edeeef",
        bgBubble: "#f1f2f3",
        bgBubbleSelected: "#d9f3ec",
        bgSearchResult: "#fff5ea",
        borderColor: "#e1e3e5",
        drilldownBorder: "#c9cccf",
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

function parseRootDataset(root: HTMLElement): RootDataset {
    const initialFilters = JSON.parse(root.dataset.initialFilters || "{}") as FilterState;
    const sortOptions = JSON.parse(root.dataset.sortOptions || "[]") as SortOption[];

    return {
        endpoint: root.dataset.endpoint || "/marketing/customers/data",
        addCustomerUrl: root.dataset.addCustomerUrl || "/marketing/customers/create",
        messageCustomerUrl: root.dataset.messageCustomerUrl || "",
        bulkActionUrl: root.dataset.bulkActionUrl || "",
        operationalDirectory: root.dataset.operationalDirectory === "true",
        initialFilters: {
            search: initialFilters.search || "",
            sort: initialFilters.sort || "updated_at",
            dir: initialFilters.dir === "asc" ? "asc" : "desc",
            per_page: Number(initialFilters.per_page || 25),
            birthday_filter: initialFilters.birthday_filter || "all",
            source: initialFilters.source || "all",
            has_points: initialFilters.has_points || "all",
            has_phone: initialFilters.has_phone || "all",
            status: initialFilters.status === "archived" ? "archived" : "active",
        },
        sortOptions,
    };
}

function normalizeText(value: string): string {
    return value.trim().replace(/\s+/g, " ");
}

function columnWidth(column: ColumnMeta): number {
    switch (column.key) {
        case "customer":
            return 240;
        case "email":
            return 220;
        case "phone":
            return 150;
        case "sources":
            return 260;
        case "birthday":
        case "last_order_at":
            return 120;
        case "tier":
            return 110;
        default:
            return column.type === "number" ? 120 : 140;
    }
}

function buildColumns(meta: ResponseMeta | null, operationalDirectory: boolean): GridColumn[] {
    const columns = (meta?.columns ?? []).map((column) => ({
        id: column.key,
        title: column.label,
        width: columnWidth(column),
    }));

    return [
        ...(operationalDirectory ? [{ id: "__select", title: "Select", width: 70 }] : []),
        ...columns,
        {
            id: "__actions",
            title: "Actions",
            width: 104,
        },
    ];
}

function fieldClass(): string {
    return "h-10 w-full rounded-lg border border-[#c9cccf] bg-white px-3 text-sm text-[#202223] outline-none transition placeholder:text-[#8c9196] focus:border-[#008060] focus:ring-2 focus:ring-[#008060]/20";
}

function buttonClass(): string {
    return "inline-flex h-10 items-center justify-center rounded-lg border border-[#c9cccf] bg-white px-3.5 text-sm font-medium text-[#202223] shadow-sm transition hover:bg-[#f6f6f7]";
}

function primaryButtonClass(): string {
    return "inline-flex h-10 items-center justify-center rounded-lg border border-[#008060] bg-[#008060] px-3.5 text-sm font-medium text-white shadow-sm transition hover:bg-[#006e52]";
}

function filterChipClass(active = true): string {
    return active
        ? "inline-flex items-center rounded-full border border-[#aee9d1] bg-[#e3f1df] px-3 py-1 text-xs font-medium text-[#006e52]"
        : "inline-flex items-center rounded-full border border-[#e1e3e5] bg-[#f6f6f7] px-3 py-1 text-xs font-medium text-[#5c5f62]";
}

function paginationButtonClass(): string {
    return "inline-flex h-9 items-center justify-center rounded-lg border border-[#c9cccf] bg-white px-3 text-xs font-semibold uppercase tracking-wider text-[#202223] shadow-sm transition hover:bg-[#f6f6f7] disabled:cursor-not-allowed disabled:opacity-40";
}

function pageSizeSelectClass(): string {
    return "h-9 min-w-[80px] rounded-lg border border-[#c9cccf] bg-white px-2 text-xs text-[#202223] outline-none transition focus:border-[#008060] focus:ring-2 focus:ring-[#008060]/20";
}

function formatCellValue(column: ColumnMeta | null, rawValue: unknown): string {
    if (rawValue == null || rawValue === "") {
        return "—";
    }

    if (column?.key === "candle_cash_amount") {
        return Number(rawValue).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    if (column?.key === "average_rating") {
        return Number(rawValue).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    if (column?.type === "number") {
        return Number(rawValue).toLocaleString();
    }

    return String(rawValue);
}

function MarketingCustomersGridApp(props: RootDataset) {
    const [rows, setRows] = useState<RowData[]>([]);
    const [meta, setMeta] = useState<ResponseMeta | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [searchInput, setSearchInput] = useState(props.initialFilters.search);
    const deferredSearch = useDeferredValue(searchInput);
    const search = useDebouncedValue(deferredSearch, 250);
    const [source, setSource] = useState(props.initialFilters.source);
    const [hasPoints, setHasPoints] = useState(props.initialFilters.has_points);
    const [hasPhone, setHasPhone] = useState(props.initialFilters.has_phone);
    const [birthdayFilter, setBirthdayFilter] = useState(props.initialFilters.birthday_filter);
    const [status, setStatus] = useState(props.initialFilters.status);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [bulkWorking, setBulkWorking] = useState(false);
    const [sortField, setSortField] = useState(props.initialFilters.sort);
    const [sortDir, setSortDir] = useState<"asc" | "desc">(props.initialFilters.dir);
    const [perPage, setPerPage] = useState(props.initialFilters.per_page);
    const [page, setPage] = useState(1);
    const [reloadToken, setReloadToken] = useState(0);
    const [filtersOpen, setFiltersOpen] = useState(
        props.initialFilters.source !== "all" ||
            props.initialFilters.has_points !== "all" ||
            props.initialFilters.has_phone !== "all" ||
            props.initialFilters.birthday_filter !== "all" ||
            props.initialFilters.sort !== "updated_at" ||
            props.initialFilters.dir !== "desc" ||
            props.initialFilters.per_page !== 25,
    );
    const [gridWrapRef, gridBounds] = useElementSize<HTMLDivElement>();
    const gridTheme = useMemo(() => resolveGridTheme(), []);
    const gridCssVars = useMemo(() => gridThemeVars(gridTheme), [gridTheme]);
    const columns = useMemo(() => buildColumns(meta, props.operationalDirectory), [meta, props.operationalDirectory]);
    const gridHeight = Math.max(gridBounds.height, 560);
    const canRenderGrid = gridBounds.width > 0 && gridHeight > 0;

    useEffect(() => {
        axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
    }, []);

    useEffect(() => {
        const url = new URL(window.location.href);
        const filters: Record<string, string> = {
            search: search,
            source,
            has_points: hasPoints,
            has_phone: hasPhone,
            birthday_filter: birthdayFilter,
            status,
            sort: sortField,
            dir: sortDir,
            per_page: String(perPage),
        };

        Object.entries(filters).forEach(([key, value]) => {
            if (value === "" || value === "all") {
                url.searchParams.delete(key);
                return;
            }

            url.searchParams.set(key, value);
        });

        if (page > 1) {
            url.searchParams.set("page", String(page));
        } else {
            url.searchParams.delete("page");
        }

        window.history.replaceState({}, "", `${url.pathname}?${url.searchParams.toString()}`);
    }, [birthdayFilter, hasPhone, hasPoints, page, perPage, search, sortDir, sortField, source, status]);

    useEffect(() => {
        const controller = new AbortController();

        async function loadRows() {
            setLoading(true);
            setError("");

            try {
                const response = await axios.get(props.endpoint, {
                    signal: controller.signal,
                    params: {
                        page,
                        per_page: perPage,
                        search: search || undefined,
                        source: source !== "all" ? source : undefined,
                        has_points: hasPoints !== "all" ? hasPoints : undefined,
                        has_phone: hasPhone !== "all" ? hasPhone : undefined,
                        birthday_filter: birthdayFilter !== "all" ? birthdayFilter : undefined,
                        status: props.operationalDirectory ? status : undefined,
                        sort: sortField,
                        dir: sortDir,
                    },
                });

                if (controller.signal.aborted) {
                    return;
                }

                setRows(Array.isArray(response.data?.data) ? (response.data.data as RowData[]) : []);
                setMeta((response.data?.meta ?? null) as ResponseMeta | null);
            } catch (requestError) {
                if (axios.isAxiosError(requestError) && requestError.code === "ERR_CANCELED") {
                    return;
                }

                const message = axios.isAxiosError(requestError)
                    ? requestError.response?.data?.message || "Could not load customers."
                    : "Could not load customers.";

                setRows([]);
                setMeta(null);
                setError(message);
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        }

        void loadRows();

        return () => {
            controller.abort();
        };
    }, [birthdayFilter, hasPhone, hasPoints, page, perPage, props.endpoint, props.operationalDirectory, reloadToken, search, sortDir, sortField, source, status]);

    useEffect(() => {
        setPage(1);
    }, [birthdayFilter, hasPhone, hasPoints, perPage, search, sortDir, sortField, source, status]);

    useEffect(() => {
        setSelectedIds([]);
    }, [page, reloadToken, status]);

    const getCellContent = ([col, row]: Item): GridCell => {
        const rowData = rows[row];
        const column = meta?.columns?.[col] ?? null;
        const gridColumn = columns[col];

        if (!rowData || !gridColumn) {
            return {
                kind: GridCellKind.Text,
                data: "",
                displayData: "",
                allowOverlay: false,
                readonly: true,
            };
        }

        if (gridColumn.id === "__actions") {
            return {
                kind: GridCellKind.Text,
                data: "Open",
                displayData: "Open",
                allowOverlay: false,
                readonly: true,
            };
        }

        if (gridColumn.id === "__select") {
            const selected = selectedIds.includes(rowData.id);

            return {
                kind: GridCellKind.Text,
                data: selected ? "Selected" : "",
                displayData: selected ? "✓" : "",
                allowOverlay: false,
                readonly: true,
            };
        }

        const value = formatCellValue(column, rowData[String(gridColumn.id)]);

        return {
            kind: GridCellKind.Text,
            data: value,
            displayData: value,
            allowOverlay: false,
            readonly: true,
        };
    };

    const handleCellClicked = ([col, row]: Item) => {
        const rowData = rows[row];
        const gridColumn = columns[col];
        if (gridColumn?.id === "__select" && rowData) {
            setSelectedIds((current) => current.includes(rowData.id)
                ? current.filter((id) => id !== rowData.id)
                : [...current, rowData.id]);
            return;
        }

        if (!rowData?.profile_url) {
            return;
        }

        window.location.assign(String(rowData.profile_url));
    };

    const handleReset = () => {
        setSearchInput("");
        setSource("all");
        setHasPoints("all");
        setHasPhone("all");
        setBirthdayFilter("all");
        setStatus("active");
        setSortField("updated_at");
        setSortDir("desc");
        setPerPage(25);
        setPage(1);
        setError("");
    };

    const pagination = meta?.pagination;
    const resultStart = pagination && pagination.total > 0
        ? (pagination.page - 1) * pagination.per_page + 1
        : 0;
    const resultEnd = pagination && pagination.total > 0
        ? Math.min(pagination.page * pagination.per_page, pagination.total)
        : 0;
    const activeFilters = [
        source !== "all" ? `Source: ${source}` : null,
        hasPoints !== "all" ? (hasPoints === "yes" ? "Has Candle Cash" : "No Candle Cash") : null,
        hasPhone !== "all" ? (hasPhone === "yes" ? "Has phone" : "Missing phone") : null,
        birthdayFilter !== "all" ? `Birthday: ${birthdayFilter}` : null,
        props.operationalDirectory && status === "archived" ? "Archived customers" : null,
        sortField !== "updated_at" ? `Sort: ${sortField}` : null,
        sortDir !== "desc" ? "Ascending" : null,
        perPage !== 25 ? `${perPage} rows` : null,
    ].filter((value): value is string => Boolean(value));

    const archiveSelected = async () => {
        if (selectedIds.length === 0 || bulkWorking || props.bulkActionUrl === "") {
            return;
        }

        const action = status === "archived" ? "restore" : "archive";
        const verb = action === "archive" ? "archive" : "restore";
        if (!window.confirm(`${verb[0].toUpperCase()}${verb.slice(1)} ${selectedIds.length} selected customer${selectedIds.length === 1 ? "" : "s"}? Jobs and history will be kept.`)) {
            return;
        }

        setBulkWorking(true);
        setError("");
        try {
            const response = await axios.post(props.bulkActionUrl, { action, profile_ids: selectedIds });
            setSelectedIds([]);
            setReloadToken((current) => current + 1);
            setError("");
            window.alert(response.data?.message || "Customer directory updated.");
        } catch (requestError) {
            setError(axios.isAxiosError(requestError)
                ? requestError.response?.data?.message || "Could not update the selected customers."
                : "Could not update the selected customers.");
        } finally {
            setBulkWorking(false);
        }
    };

    return (
        <div className="space-y-4">
            <section className="border-b border-[#e1e3e5] pb-5">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <div className="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#6d7175]">
                            Customer index
                        </div>
                        <h2 className="mt-1 text-xl font-semibold tracking-[-0.01em] text-[#202223]">Manage Customers</h2>
                        <p className="mt-1.5 max-w-3xl text-sm leading-6 text-[#6d7175]">
                            {props.operationalDirectory
                                ? "Search customers, keep service addresses current, and archive outdated records without losing job history."
                                : "Search customer profiles, keep Candle Cash separate from the legacy Growave loyalty balance, and open full customer records without fighting a long static table."}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {props.messageCustomerUrl !== "" ? (
                            <a href={props.messageCustomerUrl} className={buttonClass()}>
                                Text a customer
                            </a>
                        ) : null}
                        {props.operationalDirectory && selectedIds.length > 0 ? (
                            <button type="button" onClick={() => void archiveSelected()} disabled={bulkWorking} className={buttonClass()}>
                                {bulkWorking ? "Updating…" : `${status === "archived" ? "Restore" : "Archive"} selected (${selectedIds.length})`}
                            </button>
                        ) : null}
                        <a href={props.addCustomerUrl} className={primaryButtonClass()}>
                            Add Customer
                        </a>
                        <button
                            type="button"
                            onClick={() => setReloadToken((current) => current + 1)}
                            className={buttonClass()}
                        >
                            Refresh
                        </button>
                    </div>
                </div>

                <div className="mt-5 space-y-3">
                    <div className="flex flex-col gap-3 xl:flex-row xl:items-center">
                        <div className="flex-1">
                        <label htmlFor={SEARCH_INPUT_ID} className="sr-only">
                            Search customers
                        </label>
                        <input
                            id={SEARCH_INPUT_ID}
                            type="search"
                            value={searchInput}
                            onChange={(event) => setSearchInput(event.target.value)}
                            placeholder={props.operationalDirectory ? "Search name, email, phone, or address" : "Search name, email, phone, source ID"}
                            className={fieldClass()}
                        />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => setFiltersOpen((current) => !current)}
                                className={buttonClass()}
                            >
                                {filtersOpen ? "Hide filters" : "Filters"}
                                {activeFilters.length > 0 ? ` (${activeFilters.length})` : ""}
                            </button>
                            {activeFilters.length > 0 || searchInput !== "" ? (
                                <button type="button" onClick={handleReset} className={buttonClass()}>
                                    Reset
                                </button>
                            ) : null}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {searchInput !== "" ? (
                            <span className={filterChipClass()}>
                                Searching: {searchInput}
                            </span>
                        ) : null}
                        {activeFilters.length > 0 ? (
                            activeFilters.map((label) => (
                                <span key={label} className={filterChipClass()}>
                                    {label}
                                </span>
                            ))
                        ) : (
                            <span className={filterChipClass(false)}>
                                Search starts immediately while you type. Open filters only when you need them.
                            </span>
                        )}
                    </div>

                    {filtersOpen ? (
                        <div className="grid gap-3 border-y border-[#e1e3e5] py-4 md:grid-cols-2 xl:grid-cols-6">
                            {props.operationalDirectory ? (
                                <select value={status} onChange={(event) => setStatus(event.target.value === "archived" ? "archived" : "active")} className={fieldClass()}>
                                    <option value="active">Active customers</option>
                                    <option value="archived">Archived customers</option>
                                </select>
                            ) : (
                                <select value={source} onChange={(event) => setSource(event.target.value)} className={fieldClass()}>
                                <option value="all">All sources</option>
                                <option value="shopify">Shopify</option>
                                <option value="growave">Growave</option>
                                <option value="square">Square</option>
                                <option value="wholesale">Wholesale</option>
                                <option value="event">Event</option>
                                <option value="manual">Manual</option>
                            </select>
                            )}
                            {!props.operationalDirectory ? <select value={hasPoints} onChange={(event) => setHasPoints(event.target.value)} className={fieldClass()}>
                                <option value="all">All Candle Cash states</option>
                                <option value="yes">Has Candle Cash</option>
                                <option value="no">No Candle Cash</option>
                            </select>
                            : null}
                            <select value={hasPhone} onChange={(event) => setHasPhone(event.target.value)} className={fieldClass()}>
                                <option value="all">All phone states</option>
                                <option value="yes">Has phone</option>
                                <option value="no">No phone</option>
                            </select>
                            {!props.operationalDirectory ? <select value={birthdayFilter} onChange={(event) => setBirthdayFilter(event.target.value)} className={fieldClass()}>
                                <option value="all">All birthdays</option>
                                <option value="today">Birthday today</option>
                                <option value="week">Birthday this week</option>
                                <option value="month">Birthday this month</option>
                                <option value="missing">Birthday missing</option>
                            </select>
                            : null}
                            <select value={sortField} onChange={(event) => setSortField(event.target.value)} className={fieldClass()}>
                                {(meta?.sort_options ?? props.sortOptions).map((option) => (
                                    <option key={option.value} value={option.value}>
                                        Sort: {option.label}
                                    </option>
                                ))}
                            </select>
                            <select value={sortDir} onChange={(event) => setSortDir(event.target.value === "asc" ? "asc" : "desc")} className={fieldClass()}>
                                <option value="desc">Descending</option>
                                <option value="asc">Ascending</option>
                            </select>
                        </div>
                    ) : null}
                </div>

                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div className="font-medium text-[#5c5f62]">
                        {loading
                            ? "Loading customers…"
                            : pagination
                                ? `Showing ${resultStart.toLocaleString()}-${resultEnd.toLocaleString()} of ${pagination.total.toLocaleString()}`
                                : "Customer results"}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <span className="inline-flex items-center rounded-full bg-[#f1f2f3] px-3 py-1 text-xs text-[#5c5f62]">
                            Advanced filters are tucked away until you need them.
                        </span>
                    </div>
                </div>
            </section>

            {error !== "" ? (
                <div className="rounded-lg border border-[#fecaca] bg-[#fff4f4] px-4 py-3 text-sm text-[#b91c1c]">
                    {error}
                </div>
            ) : null}

            <section className="flex min-h-[36rem] flex-col overflow-hidden rounded-lg border border-[#e1e3e5] bg-white">
                <div
                    ref={gridWrapRef}
                    className="relative flex-1 min-h-[36rem] w-full"
                    style={gridCssVars}
                >
                    {canRenderGrid ? (
                        <DataEditor
                            columns={columns}
                            rows={rows.length}
                            getCellContent={getCellContent}
                            onCellClicked={handleCellClicked}
                            width={gridBounds.width}
                            height={gridHeight}
                            rowMarkers={{ kind: "number", theme: gridTheme }}
                            smoothScrollX={true}
                            smoothScrollY={true}
                            overscrollX={96}
                            overscrollY={32}
                            rowHeight={42}
                            headerHeight={42}
                            theme={gridTheme}
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center text-sm text-[#6d7175]">
                            Loading customer grid…
                        </div>
                    )}
                    {loading ? (
                        <div className="pointer-events-none absolute inset-0 flex items-center justify-center bg-white/85 text-sm font-semibold text-[#202223]">
                            Loading customers…
                        </div>
                    ) : null}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-[#e1e3e5] bg-[#f6f6f7] px-4 py-3">
                    <div className="flex flex-col gap-1">
                        <div className="text-sm font-semibold text-[#202223]">
                            {loading
                                ? "Loading customers…"
                                : pagination
                                    ? `Showing ${resultStart.toLocaleString()}-${resultEnd.toLocaleString()} of ${pagination.total.toLocaleString()} customers`
                                    : "Showing 0 customers"}
                        </div>
                        <div className="text-xs text-[#6d7175]">
                            Click any row to open the full customer record.
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setPage((current) => Math.max(1, current - 1))}
                            disabled={!pagination || pagination.page <= 1}
                            className={paginationButtonClass()}
                        >
                            Previous
                        </button>
                        <div className="text-sm text-[#5c5f62]">
                            {pagination ? `Page ${pagination.page} of ${pagination.last_page}` : "Page 1"}
                        </div>
                        <button
                            type="button"
                            onClick={() => setPage((current) => {
                                const nextPage = current + 1;
                                return pagination ? Math.min(pagination.last_page, nextPage) : nextPage;
                            })}
                            disabled={!pagination || pagination.page >= pagination.last_page}
                            className={paginationButtonClass()}
                        >
                            Next
                        </button>
                        <label className="ml-2 flex items-center gap-2 text-xs text-[#5c5f62]">
                            Rows
                            <select value={perPage} onChange={(event) => setPerPage(Number(event.target.value) || 25)} className={pageSizeSelectClass()}>
                                {[25, 50, 100].map((value) => (
                                    <option key={value} value={value}>
                                        {value}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                </div>
            </section>
        </div>
    );
}

function scheduleIdleTask(callback: () => void): void {
    if (typeof window === "undefined") {
        return;
    }

    if (typeof window.requestIdleCallback === "function") {
        window.requestIdleCallback(callback, { timeout: 600 });
        return;
    }

    window.setTimeout(callback, 200);
}

export function mountMarketingCustomersGrid() {
    const root = document.getElementById("marketing-customers-grid");
    if (!root || root.dataset.gridMounted === "true") {
        return;
    }

    root.dataset.gridMounted = "true";

    const mount = () => {
        createRoot(root).render(<MarketingCustomersGridApp {...parseRootDataset(root)} />);
    };

    scheduleIdleTask(mount);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mountMarketingCustomersGrid, { once: true });
} else {
    mountMarketingCustomersGrid();
}
