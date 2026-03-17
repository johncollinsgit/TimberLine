export type DashboardTimeframe =
  | "7d"
  | "30d"
  | "90d"
  | "ytd"
  | "12m";

export type DashboardComparison = "previous_period" | "previous_year" | "none";

export type MetricTrendTone = "positive" | "negative" | "neutral";

export interface DashboardMetric {
  key: string;
  label: string;
  value: string;
  delta: string;
  tone: MetricTrendTone;
  caption: string;
}

export interface DashboardChartPoint {
  label: string;
  value: number;
}

export interface DashboardLocationOrigin {
  name: string;
  share: number;
  orders: number;
}

export interface DashboardAttributionSource {
  key: string;
  label: string;
  revenue: string;
  change: string;
  tone: MetricTrendTone;
  description: string;
}

export interface DashboardFinancialSummaryItem {
  label: string;
  value: string;
  detail: string;
}

export interface DashboardData {
  metrics: DashboardMetric[];
  chart: {
    title: string;
    subtitle: string;
    series: DashboardChartPoint[];
    benchmarkLabel: string;
    benchmarkValue: string;
  };
  locations: DashboardLocationOrigin[];
  attribution: DashboardAttributionSource[];
  financialSummary: DashboardFinancialSummaryItem[];
}

export interface DashboardBootstrap {
  authorized: boolean;
  status: string;
  storeLabel: string;
  links: Array<{
    label: string;
    href: string;
    external?: boolean;
  }>;
}
