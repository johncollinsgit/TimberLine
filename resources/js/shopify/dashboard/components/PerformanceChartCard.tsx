import { BlockStack, Box, Card, InlineStack, Text } from "@shopify/polaris";
import type { DashboardData } from "../types";

interface PerformanceChartCardProps {
  chart: DashboardData["chart"];
}

export function PerformanceChartCard({ chart }: PerformanceChartCardProps) {
  const peak = Math.max(...chart.series.map((point) => point.value), 1);

  return (
    <Card>
      <BlockStack gap="400">
        <InlineStack align="space-between" blockAlign="start">
          <BlockStack gap="100">
            <Text as="h3" variant="headingMd">
              {chart.title}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              {chart.subtitle}
            </Text>
          </BlockStack>
          <BlockStack gap="050" inlineAlign="end">
            <Text as="span" variant="bodySm" tone="subdued">
              {chart.benchmarkLabel}
            </Text>
            <Text as="span" variant="headingSm">
              {chart.benchmarkValue}
            </Text>
          </BlockStack>
        </InlineStack>

        <div className="sf-dashboard-chart">
          {chart.series.map((point) => (
            <div key={point.label} className="sf-dashboard-chart__column">
              <div
                className="sf-dashboard-chart__bar"
                style={{ height: `${Math.max(14, (point.value / peak) * 100)}%` }}
              />
              <Text as="span" variant="bodySm" tone="subdued">
                {point.label}
              </Text>
            </div>
          ))}
        </div>

        <Box>
          <Text as="p" variant="bodySm" tone="subdued">
            Placeholder chart visuals are mocked for Slice 1. Slice 3 will swap this into live
            historical series and real timeframe comparisons.
          </Text>
        </Box>
      </BlockStack>
    </Card>
  );
}
