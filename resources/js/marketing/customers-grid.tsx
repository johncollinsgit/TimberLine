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
    const accent = readCssVar("--mf-accent", "16, 185, 129");
    const accentSoft = readCssVar("--mf-accent-2", accent);
    const panelBorder = readCssVar("--mf-panel-border", "rgba(110, 231, 183, 0.12)");
    const panelBorderStrong = readCssVar("--mf-panel-strong-border", "rgba(110, 231, 183, 0.22)");
    const fontBody = readCssVar(
        "--mf-font-body",
        "Manrope, ui-sans-serif, system-ui, sans-serif"
    );

    return {
        accentColor: alphaColor(accent, 1),
        accentFg: "#ecfdf5",
        accentLight: alphaColor(accentSoft, 0.16),
        textDark: "#e8fff5",
        textMedium: "#b8d8ca",
        textLight: "#7ca997",
        textBubble: "#e8fff5",
        bgIconHeader: alphaColor(accentSoft, 0.18),
        fgIconHeader: "#d1fae5",
        textHeader: "#e8fff5",
        textGroupHeader: "#7ca997",
        textHeaderSelected: "#ecfdf5",
        bgCell: "#091510",
        bgCellMedium: "#0d1b15",
        bgHeader: "#113428",
        bgHeaderHasFocus: "#164235",
        bgHeaderHovered: "#153d30",
        bgBubble: "#163d31",
        bgBubbleSelected: "#1d4b3c",
        bgSearchResult: "#194536",
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

function parseRootDataset(root: HTMLElement): RootDataset {
    const initialFilters = JSON.parse(root.dataset.initialFilters || "{}") as FilterState;
    const sortOptions = JSON.parse(root.dataset.sortOptions || "[]") as SortOption[];

    return {
        endpoint: root.dataset.endpoint || "/marketing/customers/data",
        addCustomerUrl: root.dataset.addCustomerUrl || "/marketing/customers/create",
        initialFilters: {
            search: initialFilters.search || "",
            sort: initialFilters.sort || "updated_at",
            dir: initialFilters.dir === "asc" ? "asc" : "desc",
            per_page: Number(initialFilters.per_page || 25),
            birthday_filter: initialFilters.birthday_filter || "all",
            source: initialFilters.source || "all",
            has_points: initialFilters.has_points || "all",
            has_phone: initialFilters.has_phone || "all",
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

function buildColumns(meta: ResponseMeta | null): GridColumn[] {
    const columns = (meta?.columns ?? []).map((column) => ({
        id: column.key,
        title: column.label,
        width: columnWidth(column),
    }));

    return [
        ...columns,
        {
            id: "__actions",
            title: "Actions",
            width: 104,
        },
    ];
}

function fieldClass(): string {
    return "h-11 w-full rounded-xl border border-white/10 bg-black/25 px-3 text-sm text-white outline-none transition placeholder:text-white/35 focus:border-emerald-300/25 focus:bg-black/35";
}

function buttonClass(): string {
    return "inline-flex h-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 text-sm font-medium text-white/85 transition hover:bg-white/10";
}

function primaryButtonClass(): string {
    return "inline-flex h-11 items-center justify-center rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-4 text-sm font-medium text-white transition hover:bg-emerald-500/25";
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
    const search = useDebouncedValue(deferredSearch, 300);
    const [source, setSource] = useState(props.initialFilters.source);
    const [hasPoints, setHasPoints] = useState(props.initialFilters.has_points);
    const [hasPhone, setHasPhone] = useState(props.initialFilters.has_phone);
    const [birthdayFilter, setBirthdayFilter] = useState(props.initialFilters.birthday_filter);
    const [sortField, setSortField] = useState(props.initialFilters.sort);
    const [sortDir, setSortDir] = useState<"asc" | "desc">(props.initialFilters.dir);
    const [perPage, setPerPage] = useState(props.initialFilters.per_page);
    const [page, setPage] = useState(1);
    const [reloadToken, setReloadToken] = useState(0);
    const [gridWrapRef, gridBounds] = useElementSize<HTMLDivElement>();
    const gridTheme = useMemo(() => resolveGridTheme(), []);
    const gridCssVars = useMemo(() => gridThemeVars(gridTheme), [gridTheme]);
    const columns = useMemo(() => buildColumns(meta), [meta]);
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
    }, [birthdayFilter, hasPhone, hasPoints, page, perPage, search, sortDir, sortField, source]);

    useEffect(() => {
        let cancelled = false;

        async function loadRows() {
            setLoading(true);
            setError("");

            try {
                const response = await axios.get(props.endpoint, {
                    params: {
                        page,
                        per_page: perPage,
                        search: search || undefined,
                        source: source !== "all" ? source : undefined,
                        has_points: hasPoints !== "all" ? hasPoints : undefined,
                        has_phone: hasPhone !== "all" ? hasPhone : undefined,
                        birthday_filter: birthdayFilter !== "all" ? birthdayFilter : undefined,
                        sort: sortField,
                        dir: sortDir,
                    },
                });

                if (cancelled) {
                    return;
                }

                setRows(Array.isArray(response.data?.data) ? (response.data.data as RowData[]) : []);
                setMeta((response.data?.meta ?? null) as ResponseMeta | null);
            } catch (requestError) {
                if (cancelled) {
                    return;
                }

                const message = axios.isAxiosError(requestError)
                    ? requestError.response?.data?.message || "Could not load customers."
                    : "Could not load customers.";

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
    }, [birthdayFilter, hasPhone, hasPoints, page, perPage, props.endpoint, reloadToken, search, sortDir, sortField, source]);

    useEffect(() => {
        setPage(1);
    }, [birthdayFilter, hasPhone, hasPoints, perPage, search, sortDir, sortField, source]);

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

        const value = formatCellValue(column, rowData[String(gridColumn.id)]);

        return {
            kind: GridCellKind.Text,
            data: value,
            displayData: value,
            allowOverlay: false,
            readonly: true,
        };
    };

    const handleCellClicked = ([, row]: Item) => {
        const rowData = rows[row];
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

    return (
        <div className="space-y-4">
            <section className="rounded-3xl border border-white/10 bg-black/15 p-5 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.72)] sm:p-6">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <div className="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">
                            Customer master index
                        </div>
                        <h2 className="mt-2 text-2xl font-semibold text-white">Manage Customers</h2>
                        <p className="mt-2 max-w-3xl text-sm text-emerald-50/70">
                            Search canonical profiles, keep Candle Cash separate from legacy Growave points,
                            and open full customer records without fighting a long static table.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
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

                <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-8">
                    <div className="xl:col-span-2">
                        <input
                            type="search"
                            value={searchInput}
                            onChange={(event) => setSearchInput(event.target.value)}
                            placeholder="Search name, email, phone, source ID"
                            className={fieldClass()}
                        />
                    </div>
                    <select value={source} onChange={(event) => setSource(event.target.value)} className={fieldClass()}>
                        <option value="all">All sources</option>
                        <option value="shopify">Shopify</option>
                        <option value="growave">Growave</option>
                        <option value="square">Square</option>
                        <option value="wholesale">Wholesale</option>
                        <option value="event">Event</option>
                        <option value="manual">Manual</option>
                    </select>
                    <select value={hasPoints} onChange={(event) => setHasPoints(event.target.value)} className={fieldClass()}>
                        <option value="all">All point states</option>
                        <option value="yes">Has points</option>
                        <option value="no">No points</option>
                    </select>
                    <select value={hasPhone} onChange={(event) => setHasPhone(event.target.value)} className={fieldClass()}>
                        <option value="all">All phone states</option>
                        <option value="yes">Has phone</option>
                        <option value="no">No phone</option>
                    </select>
                    <select value={birthdayFilter} onChange={(event) => setBirthdayFilter(event.target.value)} className={fieldClass()}>
                        <option value="all">All birthdays</option>
                        <option value="today">Birthday today</option>
                        <option value="week">Birthday this week</option>
                        <option value="month">Birthday this month</option>
                        <option value="missing">Birthday missing</option>
                    </select>
                    <select value={sortField} onChange={(event) => setSortField(event.target.value)} className={fieldClass()}>
                        {(meta?.sort_options ?? props.sortOptions).map((option) => (
                            <option key={option.value} value={option.value}>
                                Sort: {option.label}
                            </option>
                        ))}
                    </select>
                    <div className="flex gap-3">
                        <select value={sortDir} onChange={(event) => setSortDir(event.target.value === "asc" ? "asc" : "desc")} className={fieldClass()}>
                            <option value="desc">Descending</option>
                            <option value="asc">Ascending</option>
                        </select>
                        <select value={perPage} onChange={(event) => setPerPage(Number(event.target.value) || 25)} className={fieldClass()}>
                            {[25, 50, 100].map((value) => (
                                <option key={value} value={value}>
                                    {value} rows
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                    <div className="text-xs font-medium uppercase tracking-[0.2em] text-emerald-100/60">
                        {loading
                            ? "Loading customers…"
                            : pagination
                                ? `Showing ${resultStart.toLocaleString()}-${resultEnd.toLocaleString()} of ${pagination.total.toLocaleString()}`
                                : "Customer results"}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={handleReset} className={buttonClass()}>
                            Reset filters
                        </button>
                    </div>
                </div>
            </section>

            {error !== "" ? (
                <div className="rounded-2xl border border-rose-300/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-50">
                    {error}
                </div>
            ) : null}

            <section className="flex min-h-[36rem] flex-col overflow-hidden rounded-3xl border border-white/10 bg-black/20 shadow-[inset_0_1px_0_rgba(255,255,255,0.03)]">
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
                        <div className="flex h-full items-center justify-center text-sm text-emerald-50/60">
                            Loading customer grid…
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 bg-white/5 px-4 py-3">
                    <div className="text-sm text-white/65">
                        Click any row to open the full customer record.
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setPage((current) => Math.max(1, current - 1))}
                            disabled={!pagination || pagination.page <= 1}
                            className={buttonClass() + " disabled:cursor-not-allowed disabled:opacity-40"}
                        >
                            Previous
                        </button>
                        <div className="min-w-[8rem] text-center text-sm text-white/70">
                            {pagination ? `Page ${pagination.page} of ${pagination.last_page}` : "Page 1"}
                        </div>
                        <button
                            type="button"
                            onClick={() => setPage((current) => {
                                const nextPage = current + 1;
                                return pagination ? Math.min(pagination.last_page, nextPage) : nextPage;
                            })}
                            disabled={!pagination || pagination.page >= pagination.last_page}
                            className={buttonClass() + " disabled:cursor-not-allowed disabled:opacity-40"}
                        >
                            Next
                        </button>
                    </div>
                </div>
            </section>
        </div>
    );
}

function mountMarketingCustomersGrid() {
    const root = document.getElementById("marketing-customers-grid");
    if (!root) {
        return;
    }

    createRoot(root).render(<MarketingCustomersGridApp {...parseRootDataset(root)} />);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mountMarketingCustomersGrid, { once: true });
} else {
    mountMarketingCustomersGrid();
}
