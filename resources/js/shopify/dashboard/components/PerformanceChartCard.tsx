import { BlockStack, Box, Card, InlineStack, Text } from "@shopify/polaris";
import { useEffect, useMemo, useState } from "react";
import type { DashboardPayload } from "../types";

interface PerformanceChartCardProps {
  chart: DashboardPayload["chart"];
  loading?: boolean;
}

export function PerformanceChartCard({ chart, loading = false }: PerformanceChartCardProps) {
  const defaultSelectedKeys = useMemo(() => {
    const selected = chart.seriesOptions.filter((option) => option.selected).map((option) => option.key);

    return selected.length > 0 ? selected : chart.seriesOptions.slice(0, 1).map((option) => option.key);
  }, [chart.seriesOptions]);
  const [selectedKeys, setSelectedKeys] = useState<string[]>(defaultSelectedKeys);

  useEffect(() => {
    setSelectedKeys((current) => {
      const validKeys = new Set(chart.seriesOptions.map((option) => option.key));
      const filtered = current.filter((key) => validKeys.has(key));

      return filtered.length > 0 ? filtered : defaultSelectedKeys;
    });
  }, [chart.seriesOptions, defaultSelectedKeys]);

  const selectedOptions = useMemo(() => {
    const selection = chart.seriesOptions.filter((option) => selectedKeys.includes(option.key));

    return selection.length > 0 ? selection : chart.seriesOptions.slice(0, 1);
  }, [chart.seriesOptions, selectedKeys]);
  const peak = Math.max(
    1,
    ...chart.series.flatMap((point) =>
      selectedOptions.flatMap((option) => [
        point.values?.[option.key] ?? 0,
        point.comparisonValues?.[option.key] ?? 0,
      ]),
    ),
  );
  const selectedCount = Math.max(1, selectedOptions.length);
  const chartPeakLabel = selectedOptions
    .map((option) => option.label)
    .join(" + ");

  function toggleSeries(key: string) {
    setSelectedKeys((current) => {
      if (current.includes(key)) {
        return current.length === 1 ? current : current.filter((value) => value !== key);
      }

      return [...current, key];
    });
  }

  function polylinePoints(key: string, comparison = false) {
    return chart.series
      .map((point, index) => {
        const x = chart.series.length === 1 ? 0 : (index / Math.max(1, chart.series.length - 1)) * 100;
        const value = comparison
          ? (point.comparisonValues?.[key] ?? 0)
          : (point.values?.[key] ?? 0);
        const y = 100 - ((value ?? 0) / peak) * 100;

        return `${x},${y}`;
      })
      .join(" ");
  }

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

        {chart.seriesOptions.length > 0 ? (
          <div className="sf-dashboard-chart__series-picker">
            {chart.seriesOptions.map((option) => {
              const active = selectedKeys.includes(option.key);

              return (
                <button
                  key={option.key}
                  type="button"
                  onClick={() => toggleSeries(option.key)}
                  className={`sf-dashboard-chart__series-toggle${active ? " is-active" : ""}`}
                  aria-pressed={active}
                >
                  <span
                    className="sf-dashboard-chart__series-swatch"
                    style={{ backgroundColor: option.color }}
                    aria-hidden="true"
                  />
                  <span className="sf-dashboard-chart__series-text">
                    <strong>{option.label}</strong>
                    <span>{option.formattedPrimaryTotal}</span>
                  </span>
                </button>
              );
            })}
          </div>
        ) : null}

        {chart.empty ? (
          <Box>
            <Text as="p" variant="bodySm" tone="subdued">
              No reward or Candle Cash activity has landed in the selected timeframe yet.
            </Text>
          </Box>
        ) : chart.visualization === "grouped_bar" ? (
          <div className="sf-dashboard-chart sf-dashboard-chart--bars">
            {chart.series.map((point) => (
              <div key={point.label} className="sf-dashboard-chart__column">
                <div className="sf-dashboard-chart__bar-group sf-dashboard-chart__bar-group--multi">
                  {selectedOptions.map((option) => {
                    const primaryValue = point.values?.[option.key] ?? 0;
                    const comparisonValue = point.comparisonValues?.[option.key];

                    return (
                      <div key={option.key} className="sf-dashboard-chart__bar-pair">
                        <div
                          className="sf-dashboard-chart__bar"
                          style={{
                            height: `${Math.max(12, (primaryValue / peak) * 100)}%`,
                            background: option.color,
                          }}
                        />
                        {comparisonValue !== null ? (
                          <div
                            className="sf-dashboard-chart__bar sf-dashboard-chart__bar--comparison"
                            style={{
                              height: `${Math.max(12, ((comparisonValue ?? 0) / peak) * 100)}%`,
                              background: option.color,
                            }}
                          />
                        ) : null}
                      </div>
                    );
                  })}
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
              {selectedOptions.map((option) => (
                <polyline
                  key={option.key}
                  className="sf-dashboard-chart__line sf-dashboard-chart__line--primary"
                  points={polylinePoints(option.key)}
                  style={{ stroke: option.color }}
                />
              ))}
              {selectedOptions.map((option) => {
                const hasComparison = chart.series.some((point) => point.comparisonValues?.[option.key] !== null);

                return hasComparison ? (
                  <polyline
                    key={`${option.key}-comparison`}
                    className="sf-dashboard-chart__line sf-dashboard-chart__line--comparison"
                    points={polylinePoints(option.key, true)}
                    style={{ stroke: option.color }}
                  />
                ) : null;
              })}
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
              : `Viewing ${selectedCount} selected series: ${chartPeakLabel}. The chart reuses the same timeframe and comparison window as the metric cards above.`}
          </Text>
        </Box>
      </BlockStack>
    </Card>
  );
}
