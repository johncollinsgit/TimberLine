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
        const response = await fetch(`${bootstrap.dataEndpoint}?${queryString}`, {
          headers: bootstrap.contextToken
            ? {
                "X-Forestry-Embedded-Context": bootstrap.contextToken,
              }
            : undefined,
          credentials: "same-origin",
          signal: controller.signal,
        });

        const payload = (await response.json()) as { ok: boolean; data?: DashboardPayload; message?: string };
        if (!response.ok || !payload.ok || !payload.data) {
          throw new Error(payload.message ?? "Dashboard data could not be loaded.");
        }

        setData(payload.data);
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
  }, [bootstrap.authorized, bootstrap.contextToken, bootstrap.dataEndpoint, bootstrap.initialData, queryString, reloadSeed]);

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
        const response = await fetch(bootstrap.reminderEndpoint, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            ...(bootstrap.contextToken
              ? {
                  "X-Forestry-Embedded-Context": bootstrap.contextToken,
                }
              : {}),
          },
          credentials: "same-origin",
          body: JSON.stringify({}),
        });

        const payload = (await response.json()) as { ok: boolean; message?: string };
        if (!response.ok || !payload.ok) {
          throw new Error(payload.message ?? "Reminder send could not be started.");
        }

        setReminderAction({
          loading: false,
          message: payload.message ?? "Reminder send attempted.",
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
