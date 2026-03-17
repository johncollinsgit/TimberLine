import { BlockStack, Box, Card, InlineStack, Text } from "@shopify/polaris";
import type { DashboardPayload } from "../types";

interface PerformanceChartCardProps {
  chart: DashboardPayload["chart"];
  loading?: boolean;
}

export function PerformanceChartCard({ chart, loading = false }: PerformanceChartCardProps) {
  const peak = Math.max(
    1,
    ...chart.series.flatMap((point) => [point.primary, point.comparison ?? 0]),
  );
  const linePoints = chart.series
    .map((point, index) => {
      const x = chart.series.length === 1 ? 0 : (index / Math.max(1, chart.series.length - 1)) * 100;
      const y = 100 - (point.primary / peak) * 100;
      return `${x},${y}`;
    })
    .join(" ");
  const comparisonPoints = chart.series
    .filter((point) => point.comparison !== null)
    .map((point, index) => {
      const x = chart.series.length === 1 ? 0 : (index / Math.max(1, chart.series.length - 1)) * 100;
      const value = point.comparison ?? 0;
      const y = 100 - (value / peak) * 100;
      return `${x},${y}`;
    })
    .join(" ");

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

        {chart.empty ? (
          <Box>
            <Text as="p" variant="bodySm" tone="subdued">
              No rewards-linked revenue has landed in the selected timeframe yet.
            </Text>
          </Box>
        ) : chart.visualization === "grouped_bar" ? (
          <div className="sf-dashboard-chart sf-dashboard-chart--bars">
            {chart.series.map((point) => (
              <div key={point.label} className="sf-dashboard-chart__column">
                <div className="sf-dashboard-chart__bar-group">
                  <div
                    className="sf-dashboard-chart__bar"
                    style={{ height: `${Math.max(12, (point.primary / peak) * 100)}%` }}
                  />
                  {point.comparison !== null ? (
                    <div
                      className="sf-dashboard-chart__bar sf-dashboard-chart__bar--comparison"
                      style={{ height: `${Math.max(12, ((point.comparison ?? 0) / peak) * 100)}%` }}
                    />
                  ) : null}
                </div>
                <Text as="span" variant="bodySm" tone="subdued">
                  {point.label}
                </Text>
              </div>
            ))}
          </div>
        ) : (
          <div className="sf-dashboard-chart sf-dashboard-chart--line">
            <svg viewBox="0 0 100 100" preserveAspectRatio="none" className="sf-dashboard-chart__svg">
              <polyline className="sf-dashboard-chart__line sf-dashboard-chart__line--primary" points={linePoints} />
              {comparisonPoints ? (
                <polyline
                  className="sf-dashboard-chart__line sf-dashboard-chart__line--comparison"
                  points={comparisonPoints}
                />
              ) : null}
            </svg>
            <div className="sf-dashboard-chart__labels">
              {chart.series.map((point) => (
                <Text key={point.label} as="span" variant="bodySm" tone="subdued">
                  {point.label}
                </Text>
              ))}
            </div>
          </div>
        )}

        <Box>
          <Text as="p" variant="bodySm" tone="subdued">
            {loading
              ? "Refreshing the chart and top-line metrics for the selected controls."
              : "The chart reuses the same timeframe and comparison window as the metric cards above."}
          </Text>
        </Box>
      </BlockStack>
    </Card>
  );
}
