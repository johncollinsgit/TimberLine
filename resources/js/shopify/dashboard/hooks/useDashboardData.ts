import { useEffect, useMemo, useRef, useState } from "react";
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

  const [data, setData] = useState<DashboardPayload | null>(bootstrap.initialData);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
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

      try {
        const response = await requestDashboardJson<DashboardPayload>(
          `${bootstrap.dataEndpoint}?${queryString}`,
          {
            signal: controller.signal,
          },
        );

        if (!response.data) {
          throw new Error("Dashboard data could not be loaded.");
        }

        setData(response.data);
      } catch (fetchError) {
        if ((fetchError as Error).name === "AbortError") {
          return;
        }

        setError((fetchError as Error).message);
      } finally {
        setLoading(false);
      }
    };

    void fetchData();

    return () => controller.abort();
  }, [bootstrap.authorized, bootstrap.dataEndpoint, bootstrap.initialData, queryString, reloadSeed]);

  return {
    data,
    error,
    loading,
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
