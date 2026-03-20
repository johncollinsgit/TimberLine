import { useEffect, useMemo, useRef, useState } from "react";
import { normalizeChartSeries } from "../chartUtils";
import type { DashboardBootstrap, DashboardComparison, DashboardPayload, DashboardTimeframe } from "../types";

export interface DashboardQueryState {
  timeframe: DashboardTimeframe;
  comparison: DashboardComparison;
  locationGrouping: "country" | "state" | "city";
  customStartDate: string | null;
  customEndDate: string | null;
}

interface UseDashboardDataResult {
  data: DashboardPayload | null;
  error: string | null;
  loading: boolean;
  refreshing: boolean;
  reminderAction: {
    loading: boolean;
    message: string | null;
    tone: "success" | "critical" | "subdued";
  };
  query: DashboardQueryState;
  setTimeframe: (value: DashboardTimeframe) => void;
  setComparison: (value: DashboardComparison) => void;
  setLocationGrouping: (value: "country" | "state" | "city") => void;
  setCustomDates: (start: string | null, end: string | null) => void;
  refreshData: () => void;
  sendCandleCashReminders: () => Promise<void>;
}

interface DashboardJsonEnvelope<TData> {
  ok: boolean;
  data?: TData;
  message?: string;
  status?: string;
}

interface DashboardRequestError extends Error {
  payload?: DashboardJsonEnvelope<unknown>;
  status?: string;
}

const DASHBOARD_DEBUG_KEY = "sf-dashboard-debug";
const DASHBOARD_AUTO_REFRESH_KEY = "sf-dashboard-auto-refresh";
const AUTO_REFRESH_INTERVAL_MS = 10 * 60 * 1000;

function isDashboardDebugEnabled(): boolean {
  if (typeof window === "undefined") {
    return false;
  }

  const params = new URLSearchParams(window.location.search);

  return (
    params.get("dashboard_debug") === "1" ||
    params.get("debug") === "dashboard" ||
    window.localStorage.getItem(DASHBOARD_DEBUG_KEY) === "true"
  );
}

function isAutoRefreshEnabled(): boolean {
  if (typeof window === "undefined") {
    return false;
  }

  const params = new URLSearchParams(window.location.search);

  return (
    params.get("auto_refresh") === "1" ||
    window.localStorage.getItem(DASHBOARD_AUTO_REFRESH_KEY) === "true"
  );
}

function authFailureMessage(status?: string | null, fallbackMessage?: string | null): string {
  const messages: Record<string, string> = {
    missing_api_auth:
      "Shopify Admin verification is unavailable. Reload the dashboard from Shopify Admin and try again.",
    invalid_session_token:
      "Shopify Admin verification failed. Reload the dashboard from Shopify Admin and try again.",
    expired_session_token:
      "Your Shopify Admin session expired. Reload the dashboard from Shopify Admin and try again.",
  };

  return messages[status ?? ""] ?? fallbackMessage ?? "Request failed.";
}

async function resolveEmbeddedAuthHeaders(): Promise<Record<string, string>> {
  const shopifyBridge = (
    window as Window & {
      shopify?: {
        idToken?: () => Promise<string> | string;
      };
    }
  ).shopify;

  if (!shopifyBridge || typeof shopifyBridge.idToken !== "function") {
    throw new Error(authFailureMessage("missing_api_auth"));
  }

  let sessionToken: unknown = null;

  try {
    sessionToken = await Promise.race([
      Promise.resolve(shopifyBridge.idToken()),
      new Promise<null>((resolve) => window.setTimeout(() => resolve(null), 1500)),
    ]);
  } catch {
    throw new Error(authFailureMessage("invalid_session_token"));
  }

  if (typeof sessionToken !== "string" || sessionToken.trim() === "") {
    throw new Error(authFailureMessage("missing_api_auth"));
  }

  return {
    Accept: "application/json",
    Authorization: `Bearer ${sessionToken.trim()}`,
  };
}

async function requestDashboardJson<TData>(
  url: string,
  options: RequestInit = {},
): Promise<DashboardJsonEnvelope<TData>> {
  const authHeaders = await resolveEmbeddedAuthHeaders();
  const headers = {
    ...authHeaders,
    ...(options.body ? { "Content-Type": "application/json" } : {}),
    ...(options.headers ?? {}),
  };

  const response = await fetch(url, {
    credentials: "same-origin",
    ...options,
    headers,
  });

  const payload = (await response.json().catch(() => ({
    ok: false,
    message: "Unexpected response from Backstage.",
  }))) as DashboardJsonEnvelope<TData>;

  if (!response.ok || !payload.ok) {
    const error = new Error(
      authFailureMessage(payload.status, payload.message ?? "Request failed."),
    ) as DashboardRequestError;
    error.payload = payload;
    error.status = payload.status;
    throw error;
  }

  return payload;
}

export function useDashboardData(bootstrap: DashboardBootstrap): UseDashboardDataResult {
  const config = bootstrap.initialData?.config ?? bootstrap.config;
  const initialQuery = bootstrap.initialData?.query;
  const firstLoadRef = useRef(true);
  const manualRefreshRef = useRef(false);

  const [data, setData] = useState<DashboardPayload | null>(bootstrap.initialData);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [reloadSeed, setReloadSeed] = useState(0);
  const [reminderAction, setReminderAction] = useState<{
    loading: boolean;
    message: string | null;
    tone: "success" | "critical" | "subdued";
  }>({
    loading: false,
    message: null,
    tone: "subdued",
  });
  const [query, setQuery] = useState<DashboardQueryState>({
    timeframe: initialQuery?.timeframe ?? config?.defaultTimeframe ?? "last_30_days",
    comparison: initialQuery?.comparison ?? config?.defaultComparison ?? "previous_period",
    locationGrouping:
      initialQuery?.locationGrouping ?? config?.locationGroupingPreference ?? "state",
    customStartDate: initialQuery?.customStartDate ?? null,
    customEndDate: initialQuery?.customEndDate ?? null,
  });

  const autoRefreshEnabled = useMemo(() => isAutoRefreshEnabled(), []);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    params.set("timeframe", query.timeframe);
    params.set("comparison", query.comparison);
    params.set("location_grouping", query.locationGrouping);

    if (query.timeframe === "custom") {
      if (query.customStartDate) {
        params.set("custom_start_date", query.customStartDate);
      }
      if (query.customEndDate) {
        params.set("custom_end_date", query.customEndDate);
      }
    }

    return params.toString();
  }, [query]);

  useEffect(() => {
    if (!bootstrap.authorized) {
      return;
    }

    const nextUrl = `${window.location.pathname}?${queryString}`;
    window.history.replaceState({}, "", nextUrl);
  }, [bootstrap.authorized, queryString]);

  useEffect(() => {
    if (!bootstrap.authorized || !bootstrap.dataEndpoint) {
      return;
    }

    if (firstLoadRef.current) {
      firstLoadRef.current = false;
      if (bootstrap.initialData) {
        return;
      }
    }

    const controller = new AbortController();
    const fetchData = async () => {
      setLoading(true);
      setError(null);
      const shouldForceRefresh = manualRefreshRef.current;
      if (shouldForceRefresh) {
        manualRefreshRef.current = false;
      }

      try {
        const params = new URLSearchParams(queryString);
        if (shouldForceRefresh) {
          params.set("refresh", "1");
        }

        const response = await requestDashboardJson<DashboardPayload>(
          `${bootstrap.dataEndpoint}?${params.toString()}`,
          {
            signal: controller.signal,
          },
        );

        if (!response.data) {
          throw new Error("Dashboard data could not be loaded.");
        }

        if (response.data) {
          if (isDashboardDebugEnabled()) {
            const normalizedSeries = normalizeChartSeries(response.data.chart?.series);
            const chartLabels = normalizedSeries.map((point) => ({
              raw: point.label,
              start: point.bucketStart ?? null,
              end: point.bucketEnd ?? null,
            }));

            console.groupCollapsed("[Shopify Dashboard] Data payload");
            console.log("Raw response payload", response);
            console.log("Query/timeframe", response.data.query);
            console.log("Interval/granularity", response.data.query?.interval);
            console.log("Comparison window", response.data.query?.comparisonWindow);
            console.log("Freshness metadata", response.data.meta?.freshness);
            console.log("Dashboard cache TTL (seconds)", response.data.meta?.cacheTtlSeconds ?? null);
            console.log("Chart series (normalized)", normalizedSeries);
            console.log("Chart series options", response.data.chart?.seriesOptions);
            console.log("Chart bucket labels", chartLabels);
            console.groupEnd();
          }

          if (!Array.isArray(response.data.chart?.series)) {
            console.warn(
              "[Shopify Dashboard] Chart series payload is malformed; rendering fallback empty state.",
            );
          }
        }

        setData(response.data);
      } catch (fetchError) {
        if ((fetchError as Error).name === "AbortError") {
          return;
        }

        setError((fetchError as Error).message);
      } finally {
        setLoading(false);
        if (shouldForceRefresh) {
          setRefreshing(false);
        }
      }
    };

    void fetchData();

    return () => controller.abort();
  }, [bootstrap.authorized, bootstrap.dataEndpoint, bootstrap.initialData, queryString, reloadSeed]);

  useEffect(() => {
    if (!bootstrap.authorized || !bootstrap.dataEndpoint || !autoRefreshEnabled) {
      return;
    }

    const interval = window.setInterval(() => {
      if (document.visibilityState !== "visible") {
        return;
      }

      if (loading || refreshing) {
        return;
      }

      setReloadSeed((current) => current + 1);
    }, AUTO_REFRESH_INTERVAL_MS);

    return () => window.clearInterval(interval);
  }, [autoRefreshEnabled, bootstrap.authorized, bootstrap.dataEndpoint, loading, refreshing]);

  return {
    data,
    error,
    loading,
    refreshing,
    reminderAction,
    query,
    setTimeframe: (value) =>
      setQuery((current) => ({
        ...current,
        timeframe: value,
        customStartDate: value === "custom" ? current.customStartDate : null,
        customEndDate: value === "custom" ? current.customEndDate : null,
      })),
    setComparison: (value) =>
      setQuery((current) => ({
        ...current,
        comparison: value,
      })),
    setLocationGrouping: (value) =>
      setQuery((current) => ({
        ...current,
        locationGrouping: value,
      })),
    setCustomDates: (start, end) =>
      setQuery((current) => ({
        ...current,
        customStartDate: start,
        customEndDate: end,
      })),
    refreshData: () => {
      if (!bootstrap.authorized || !bootstrap.dataEndpoint || loading || refreshing) {
        return;
      }

      manualRefreshRef.current = true;
      setRefreshing(true);
      setReloadSeed((current) => current + 1);
    },
    sendCandleCashReminders: async () => {
      if (!bootstrap.authorized || !bootstrap.reminderEndpoint) {
        setReminderAction({
          loading: false,
          message: "Reminder endpoint is unavailable for this embedded session.",
          tone: "critical",
        });
        return;
      }

      setReminderAction({
        loading: true,
        message: null,
        tone: "subdued",
      });

      try {
        const response = await requestDashboardJson<Record<string, never>>(bootstrap.reminderEndpoint, {
          method: "POST",
          body: JSON.stringify({}),
        });

        setReminderAction({
          loading: false,
          message: response.message ?? "Reminder send attempted.",
          tone: "success",
        });
        setReloadSeed((current) => current + 1);
      } catch (requestError) {
        setReminderAction({
          loading: false,
          message: (requestError as Error).message,
          tone: "critical",
        });
      }
    },
  };
}
