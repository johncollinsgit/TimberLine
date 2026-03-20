import { BlockStack, Box, Card, InlineStack, Text } from "@shopify/polaris";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  buildAreaPath,
  buildSmoothPathSegments,
  computeLabelVisibility,
  formatAxisLabel,
  formatCurrencyValue,
  formatTooltipTitle,
  mutedComparisonColor,
  normalizeChartSeries,
} from "../chartUtils";
import type { DashboardPayload } from "../types";

interface PerformanceChartCardProps {
  chart: DashboardPayload["chart"];
  query: DashboardPayload["query"];
  loading?: boolean;
}

const CHART_PADDING = { top: 8, bottom: 14 };
const GRIDLINE_RATIOS = [0.25, 0.5, 0.75];

export function PerformanceChartCard({
  chart,
  query,
  loading = false,
}: PerformanceChartCardProps) {
  const seriesOptions = chart.seriesOptions ?? [];
  const normalizedSeries = useMemo(() => normalizeChartSeries(chart.series), [chart.series]);
  const [chartWidth, setChartWidth] = useState<number | null>(null);
  const [chartElement, setChartElement] = useState<HTMLDivElement | null>(null);
  const [activeIndex, setActiveIndex] = useState<number | null>(null);

  const chartRef = useCallback((node: HTMLDivElement | null) => {
    setChartElement(node);
  }, []);

  useEffect(() => {
    if (!chartElement || typeof ResizeObserver === "undefined") {
      return;
    }

    const observer = new ResizeObserver((entries) => {
      const entry = entries[0];
      if (entry?.contentRect?.width) {
        setChartWidth(entry.contentRect.width);
      }
    });

    observer.observe(chartElement);

    return () => observer.disconnect();
  }, [chartElement]);

  useEffect(() => {
    setActiveIndex((current) => {
      if (current === null) {
        return null;
      }

      return current < normalizedSeries.length ? current : null;
    });
  }, [normalizedSeries.length]);

  const defaultSelectedKeys = useMemo(() => {
    const selected = seriesOptions.filter((option) => option.selected).map((option) => option.key);

    return selected.length > 0 ? selected : seriesOptions.slice(0, 1).map((option) => option.key);
  }, [seriesOptions]);
  const [selectedKeys, setSelectedKeys] = useState<string[]>(defaultSelectedKeys);

  useEffect(() => {
    setSelectedKeys((current) => {
      const validKeys = new Set(seriesOptions.map((option) => option.key));
      const filtered = current.filter((key) => validKeys.has(key));

      return filtered.length > 0 ? filtered : defaultSelectedKeys;
    });
  }, [seriesOptions, defaultSelectedKeys]);

  const selectedOptions = useMemo(() => {
    if (seriesOptions.length === 0) {
      return [];
    }

    const selection = seriesOptions.filter((option) => selectedKeys.includes(option.key));

    return selection.length > 0 ? selection : seriesOptions.slice(0, 1);
  }, [seriesOptions, selectedKeys]);

  const peak = useMemo(() => {
    let maxValue = 0;

    normalizedSeries.forEach((point) => {
      selectedOptions.forEach((option) => {
        const primaryValue = point.values?.[option.key] ?? 0;
        maxValue = Math.max(maxValue, primaryValue);

        const comparisonValue = point.comparisonValues?.[option.key];
        if (comparisonValue !== null && comparisonValue !== undefined) {
          maxValue = Math.max(maxValue, comparisonValue);
        }
      });
    });

    return Math.max(1, maxValue);
  }, [normalizedSeries, selectedOptions]);

  const chartMax = peak * 1.08;
  const selectedCount = selectedOptions.length;
  const chartPeakLabel = selectedOptions.map((option) => option.label).join(" + ");
  const pointCount = normalizedSeries.length;
  const labelVisibility = useMemo(
    () => computeLabelVisibility(pointCount, query.interval.unit, chartWidth),
    [pointCount, query.interval.unit, chartWidth],
  );

  const chartContext = useMemo(
    () => ({
      unit: query.interval.unit,
      bucketCount: query.interval.bucketCount,
      timeframe: query.timeframe,
    }),
    [query.interval.bucketCount, query.interval.unit, query.timeframe],
  );

  const xForIndex = (index: number) =>
    pointCount === 1 ? 50 : (index / Math.max(1, pointCount - 1)) * 100;

  const valueToY = (value: number | null) => {
    if (value === null || !Number.isFinite(value)) {
      return null;
    }

    const ratio = chartMax > 0 ? Math.max(0, Math.min(1, value / chartMax)) : 0;
    const range = 100 - CHART_PADDING.top - CHART_PADDING.bottom;

    return CHART_PADDING.top + (1 - ratio) * range;
  };

  const hasComparison = useMemo(
    () =>
      selectedOptions.some((option) =>
        normalizedSeries.some((point) => {
          const value = point.comparisonValues?.[option.key];
          return value !== null && value !== undefined && Math.abs(value) > 0.001;
        }),
      ),
    [normalizedSeries, selectedOptions],
  );

  const tooltipPoint = activeIndex !== null ? normalizedSeries[activeIndex] ?? null : null;
  const tooltipX = activeIndex !== null ? xForIndex(activeIndex) : null;
  const showChart = !chart.empty && pointCount > 0 && selectedOptions.length > 0;
  const showMarkers = pointCount <= 2;
  const baselineY = 100 - CHART_PADDING.bottom;

  function toggleSeries(key: string) {
    setSelectedKeys((current) => {
      if (current.includes(key)) {
        return current.length === 1 ? current : current.filter((value) => value !== key);
      }

      return [...current, key];
    });
  }

  function hitAreaBounds(index: number) {
    if (pointCount <= 1) {
      return { x: 0, width: 100 };
    }

    const current = xForIndex(index);
    const previous = index === 0 ? 0 : xForIndex(index - 1);
    const next = index === pointCount - 1 ? 100 : xForIndex(index + 1);
    const start = index === 0 ? 0 : (previous + current) / 2;
    const end = index === pointCount - 1 ? 100 : (current + next) / 2;

    return {
      x: start,
      width: Math.max(0, end - start),
    };
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

        {seriesOptions.length > 0 ? (
          <div className="sf-dashboard-chart__series-picker">
            {seriesOptions.map((option) => {
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

        {chart.empty || !showChart ? (
          <div className="sf-dashboard-chart__empty-state">
            <Text as="p" variant="bodyMd">
              {chart.empty
                ? "No reward or Candle Cash activity has landed in the selected timeframe yet."
                : "Chart data is unavailable for the selected controls."}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              The chart is fed by live reward-attributed revenue, birthday rewards, referrals, and Candle Cash ledger activity.
            </Text>
          </div>
        ) : chart.visualization === "grouped_bar" ? (
          <div className="sf-dashboard-chart sf-dashboard-chart--bars" ref={chartRef}>
            {normalizedSeries.map((point, index) => (
              <div key={`${point.label}-${index}`} className="sf-dashboard-chart__column">
                <div className="sf-dashboard-chart__bar-group sf-dashboard-chart__bar-group--multi">
                  {selectedOptions.map((option) => {
                    const primaryValue = point.values?.[option.key] ?? 0;
                    const comparisonValue = point.comparisonValues?.[option.key];
                    const primaryHeight =
                      primaryValue > 0 ? Math.max(8, (primaryValue / chartMax) * 100) : 0;
                    const comparisonHeight =
                      comparisonValue !== null && comparisonValue !== undefined && comparisonValue > 0
                        ? Math.max(8, (comparisonValue / chartMax) * 100)
                        : 0;
                    const primaryMinHeight = primaryValue > 0 ? "0.75rem" : "0";
                    const comparisonMinHeight =
                      comparisonValue !== null && comparisonValue !== undefined && comparisonValue > 0
                        ? "0.75rem"
                        : "0";

                    return (
                      <div key={option.key} className="sf-dashboard-chart__bar-pair">
                        <div
                          className="sf-dashboard-chart__bar"
                          style={{
                            height: `${primaryHeight}%`,
                            minHeight: primaryMinHeight,
                            background: option.color,
                          }}
                        />
                        {comparisonValue !== null && comparisonValue !== undefined ? (
                          <div
                            className="sf-dashboard-chart__bar sf-dashboard-chart__bar--comparison"
                            style={{
                              height: `${comparisonHeight}%`,
                              minHeight: comparisonMinHeight,
                              background: mutedComparisonColor(option.color),
                            }}
                          />
                        ) : null}
                      </div>
                    );
                  })}
                </div>
                <Text as="span" variant="bodySm" tone="subdued" className="sf-dashboard-chart__bar-label">
                  {formatAxisLabel(point, chartContext)}
                </Text>
              </div>
            ))}
          </div>
        ) : (
          <div className="sf-dashboard-chart sf-dashboard-chart--line" ref={chartRef}>
            <div className="sf-dashboard-chart__plot">
              {tooltipPoint && tooltipX !== null ? (
                <div
                  className="sf-dashboard-chart__tooltip"
                  style={{ left: `${tooltipX}%` }}
                  role="status"
                  aria-live="polite"
                >
                  <Text as="p" variant="bodySm">
                    {formatTooltipTitle(tooltipPoint, chartContext)}
                  </Text>
                  <div className="sf-dashboard-chart__tooltip-values">
                    {selectedOptions.map((option) => {
                      const primaryValue = tooltipPoint.values?.[option.key] ?? 0;
                      const comparisonValue = tooltipPoint.comparisonValues?.[option.key] ?? null;

                      return (
                        <div key={option.key} className="sf-dashboard-chart__tooltip-row">
                          <span
                            className="sf-dashboard-chart__tooltip-swatch"
                            style={{ backgroundColor: option.color }}
                            aria-hidden="true"
                          />
                          <span className="sf-dashboard-chart__tooltip-label">{option.label}</span>
                          <span className="sf-dashboard-chart__tooltip-value">
                            {formatCurrencyValue(primaryValue)}
                          </span>
                          {hasComparison ? (
                            <span className="sf-dashboard-chart__tooltip-comparison">
                              vs {formatCurrencyValue(comparisonValue)}
                            </span>
                          ) : null}
                        </div>
                      );
                    })}
                  </div>
                </div>
              ) : null}

              <svg
                viewBox="0 0 100 100"
                preserveAspectRatio="none"
                className="sf-dashboard-chart__svg"
                onMouseLeave={() => setActiveIndex(null)}
              >
                <g className="sf-dashboard-chart__grid" aria-hidden="true">
                  {GRIDLINE_RATIOS.map((ratio) => {
                    const range = 100 - CHART_PADDING.top - CHART_PADDING.bottom;
                    const y = CHART_PADDING.top + (1 - ratio) * range;
                    return <line key={ratio} x1="0" x2="100" y1={y} y2={y} />;
                  })}
                </g>

                {selectedOptions.length === 1
                  ? selectedOptions.map((option) => {
                      const points = normalizedSeries.map((point, index) => ({
                        x: xForIndex(index),
                        y: valueToY(point.values?.[option.key] ?? 0),
                      }));
                      const paths = buildSmoothPathSegments(points);

                      return paths.map((path, segmentIndex) => {
                        const firstPoint = points.find((point) => point.y !== null);
                        const lastPoint = [...points].reverse().find((point) => point.y !== null);
                        if (!firstPoint || !lastPoint) {
                          return null;
                        }

                        return (
                          <path
                            key={`${option.key}-area-${segmentIndex}`}
                            className="sf-dashboard-chart__area"
                            d={buildAreaPath(path, firstPoint.x, lastPoint.x, baselineY)}
                            style={{ fill: option.color }}
                          />
                        );
                      });
                    })
                  : null}

                {hasComparison
                  ? selectedOptions.map((option) => {
                      const points = normalizedSeries.map((point, index) => ({
                        x: xForIndex(index),
                        y: valueToY(point.comparisonValues?.[option.key] ?? null),
                      }));

                      return buildSmoothPathSegments(points).map((path, segmentIndex) => (
                        <path
                          key={`${option.key}-comparison-${segmentIndex}`}
                          className="sf-dashboard-chart__line sf-dashboard-chart__line--comparison"
                          d={path}
                          style={{ stroke: mutedComparisonColor(option.color) }}
                        />
                      ));
                    })
                  : null}

                {selectedOptions.map((option) => {
                  const points = normalizedSeries.map((point, index) => ({
                    x: xForIndex(index),
                    y: valueToY(point.values?.[option.key] ?? 0),
                  }));

                  return buildSmoothPathSegments(points).map((path, segmentIndex) => (
                    <path
                      key={`${option.key}-primary-${segmentIndex}`}
                      className="sf-dashboard-chart__line sf-dashboard-chart__line--primary"
                      d={path}
                      style={{ stroke: option.color }}
                    />
                  ));
                })}

                {activeIndex !== null && tooltipX !== null ? (
                  <line
                    className="sf-dashboard-chart__guide"
                    x1={tooltipX}
                    x2={tooltipX}
                    y1={CHART_PADDING.top}
                    y2={baselineY}
                  />
                ) : null}

                {showMarkers || activeIndex !== null
                  ? selectedOptions.map((option) =>
                      normalizedSeries.map((point, index) => {
                        const y = valueToY(point.values?.[option.key] ?? 0);
                        if (y === null || (!showMarkers && activeIndex !== index)) {
                          return null;
                        }

                        return (
                          <circle
                            key={`${option.key}-marker-${index}`}
                            className={`sf-dashboard-chart__marker${activeIndex === index ? " is-active" : ""}`}
                            cx={xForIndex(index)}
                            cy={y}
                            r={activeIndex === index ? 3.3 : 2.2}
                            style={{ stroke: option.color }}
                          />
                        );
                      }),
                    )
                  : null}

                <g className="sf-dashboard-chart__hit-areas" aria-hidden="true">
                  {normalizedSeries.map((point, index) => {
                    const bounds = hitAreaBounds(index);

                    return (
                      <rect
                        key={`${point.label}-${index}`}
                        className="sf-dashboard-chart__hit-area"
                        x={bounds.x}
                        y={0}
                        width={bounds.width}
                        height={100}
                        onMouseEnter={() => setActiveIndex(index)}
                      />
                    );
                  })}
                </g>
              </svg>
            </div>

            <div className="sf-dashboard-chart__labels">
              {normalizedSeries.map((point, index) => {
                const visible = labelVisibility[index];
                const label = formatAxisLabel(point, chartContext);
                const align =
                  pointCount === 1
                    ? "center"
                    : index === 0
                      ? "start"
                      : index === Math.max(0, pointCount - 1)
                        ? "end"
                        : "center";

                return (
                  <Text
                    key={`${point.label}-${index}`}
                    as="span"
                    variant="bodySm"
                    tone="subdued"
                    className={`sf-dashboard-chart__label${visible ? "" : " is-hidden"}`}
                    aria-hidden={!visible}
                    data-align={align}
                    style={{ left: `${xForIndex(index)}%` }}
                  >
                    {visible ? label : ""}
                  </Text>
                );
              })}
            </div>
          </div>
        )}

        <Box>
          <Text as="p" variant="bodySm" tone="subdued">
            {loading
              ? "Refreshing live reward and Candle Cash activity for the selected controls."
              : selectedCount > 0
                ? `Viewing ${selectedCount} selected series: ${chartPeakLabel}. The chart updates from the same live dashboard payload as the metric cards above.`
                : "Select a series to visualize the performance trend."}
          </Text>
        </Box>
      </BlockStack>
    </Card>
  );
}
