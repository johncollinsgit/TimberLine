import type { DashboardComparison, DashboardTimeframe } from "./types";

export const dashboardConfig = {
  defaultTimeframe: "30d" as DashboardTimeframe,
  defaultComparison: "previous_period" as DashboardComparison,
  visibleWidgets: {
    metricCards: true,
    performanceChart: true,
    locationOrigins: true,
    attribution: true,
    financialSummary: true,
  },
  timeframeOptions: [
    { label: "Last 7 days", value: "7d" },
    { label: "Last 30 days", value: "30d" },
    { label: "Last 90 days", value: "90d" },
    { label: "Year to date", value: "ytd" },
    { label: "Last 12 months", value: "12m" },
  ] satisfies Array<{ label: string; value: DashboardTimeframe }>,
  comparisonOptions: [
    { label: "Previous period", value: "previous_period" },
    { label: "Previous year", value: "previous_year" },
    { label: "No comparison", value: "none" },
  ] satisfies Array<{ label: string; value: DashboardComparison }>,
};
