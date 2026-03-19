export type DashboardTimeframe =
  | "today"
  | "yesterday"
  | "last_7_days"
  | "last_30_days"
  | "month_to_date"
  | "quarter_to_date"
  | "year_to_date"
  | "full_year"
  | "custom";

export type DashboardComparison = "previous_period" | "previous_year" | "none";

export type DashboardMetricTone = "positive" | "negative" | "neutral";

export interface DashboardMetric {
  key: string;
  label: string;
  value: number;
  formattedValue: string;
  comparisonValue: number | null;
  deltaPct: number | null;
  deltaLabel: string;
  tone: DashboardMetricTone;
  caption: string;
}

export interface DashboardChartSeriesPoint {
  label: string;
  primary: number;
  comparison: number | null;
}

export interface DashboardLocationOrigin {
  name: string;
  orders: number;
  revenue: number;
  formattedRevenue: string;
  share: number;
  partial: boolean;
}

export interface DashboardAttributionSource {
  key: string;
  label: string;
  revenue: number;
  formattedRevenue: string;
  profit?: number;
  formattedProfit?: string;
  orders: number;
  deltaPct: number | null;
  deltaLabel: string;
  tone: DashboardMetricTone;
  description: string;
  live: boolean;
}

export interface DashboardFinancialSummaryItem {
  label: string;
  value: number;
  formattedValue: string;
  detail: string;
}

export interface DashboardCandleCashEarnBreakdownRow {
  key: string;
  label: string;
  definition: string;
  points: number;
  amount: number;
  formattedAmount: string;
  sharePct: number;
  eventCount: number;
  customerCount: number;
}

export interface DashboardPayload {
  meta: {
    generatedAt: string;
    currencyCode: string;
    partialData: {
      attribution: boolean;
      locations: boolean;
      profit: boolean;
    };
  };
  query: {
    timeframe: DashboardTimeframe;
    comparison: DashboardComparison;
    locationGrouping: "country" | "state" | "city";
    chartMetric: string;
    customStartDate: string | null;
    customEndDate: string | null;
    primary: {
      from: string;
      to: string;
      label: string;
    };
    comparisonWindow: {
      from: string;
      to: string;
      label: string;
    } | null;
    interval: {
      unit: "hour" | "day" | "week" | "month";
      displayFormat: string;
      bucketCount: number;
    };
    visualization: "line" | "grouped_bar";
  };
  config: DashboardConfig;
  topMetrics: DashboardMetric[];
  chart: {
    title: string;
    subtitle: string;
    metric: {
      key: string;
      label: string;
    };
    visualization: "line" | "grouped_bar";
    series: DashboardChartSeriesPoint[];
    benchmarkLabel: string;
    benchmarkValue: string;
    empty: boolean;
  };
  attribution: {
    title: string;
    subtitle: string;
    sources: DashboardAttributionSource[];
    empty: boolean;
  };
  locationOrigins: {
    title: string;
    subtitle: string;
    grouping: "country" | "state" | "city";
    items: DashboardLocationOrigin[];
    empty: boolean;
  };
  financialSummary: {
    title: string;
    subtitle: string;
    items: DashboardFinancialSummaryItem[];
    netProfit: {
      value: number;
      formattedValue: string;
      comparisonValue: number | null;
      label?: string;
      confidenceLevel?: "high" | "medium" | "low";
      detail?: string;
    };
  };
  candleCashEngagement: {
    title: string;
    subtitle: string;
    earned: {
      points: number;
      amount: number;
      formattedAmount: string;
      eventCount: number;
      customerCount: number;
      sourceSummary: string;
    };
    breakdown: {
      rows: DashboardCandleCashEarnBreakdownRow[];
      sourceDefinitions: Record<string, { label: string; definition: string }>;
    };
    outstanding: {
      points: number;
      amount: number;
      formattedAmount: string;
      customerCount: number;
      excludedGrandfatheredPoints: number;
      excludedGrandfatheredAmount: number;
      helperText: string;
    };
    timeToFirstRedemption: {
      averageDays: number | null;
      medianDays: number | null;
      formattedAverageDays: string;
      formattedMedianDays: string;
      sampleCount: number;
      approximation: string;
    };
    customersWithOutstandingEarned: {
      count: number;
    };
    reminderEligibility: {
      eligibleCustomers: number;
      missingEmailCustomers: number;
      expirationPolicy: string;
      emailReadiness?: {
        status: "ready_for_live_send" | "dry_run_only" | "disabled" | "misconfigured" | string;
        enabled: boolean;
        dryRun: boolean;
        missingReasons: string[];
      };
    };
    comparison?: {
      earnedAmount?: number | null;
      timeToFirstRedemptionAverageDays?: number | null;
    };
  };
  flags: {
    hasAnyData: boolean;
    usesFallbackAttribution: boolean;
    usesEstimatedOrderRevenue: boolean;
  };
}

export interface DashboardConfig {
  defaultTimeframe: DashboardTimeframe;
  defaultComparison: DashboardComparison;
  chartDefaultMetric: string;
  locationGroupingPreference: "country" | "state" | "city";
  timeframeOptions: Array<{ label: string; value: DashboardTimeframe }>;
  comparisonOptions: Array<{ label: string; value: DashboardComparison }>;
  locationGroupingOptions: Array<{ label: string; value: "country" | "state" | "city" }>;
  visibleWidgets: {
    metricCards: boolean;
    candleCashEngagement: boolean;
    performanceChart: boolean;
    locationOrigins: boolean;
    attribution: boolean;
    financialSummary: boolean;
  };
  visibleAttributionSources: string[];
  widgetRegistry: Record<string, { title: string }>;
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
  dataEndpoint: string | null;
  reminderEndpoint: string | null;
  config: DashboardConfig | null;
  initialData: DashboardPayload | null;
}
