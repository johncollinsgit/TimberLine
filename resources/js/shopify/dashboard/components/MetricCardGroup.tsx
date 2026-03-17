import { InlineGrid } from "@shopify/polaris";
import type { DashboardMetric } from "../types";
import { MetricCard } from "./MetricCard";

interface MetricCardGroupProps {
  metrics: DashboardMetric[];
}

export function MetricCardGroup({ metrics }: MetricCardGroupProps) {
  return (
    <InlineGrid columns={{ xs: 1, sm: 2, md: 4 }} gap="400">
      {metrics.map((metric) => (
        <MetricCard key={metric.key} metric={metric} />
      ))}
    </InlineGrid>
  );
}
