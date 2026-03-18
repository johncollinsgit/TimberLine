import {
  AppProvider,
  BlockStack,
  Card,
  InlineGrid,
  InlineStack,
  SkeletonDisplayText,
  SkeletonBodyText,
  TextField,
  Text,
} from "@shopify/polaris";
import "@shopify/polaris/build/esm/styles.css";
import enTranslations from "@shopify/polaris/locales/en.json";
import { useMemo } from "react";
import { dashboardConfig } from "./dashboardConfig";
import type { DashboardBootstrap } from "./types";
import { AttributionSection } from "./components/AttributionSection";
import { CandleCashEngagementSection } from "./components/CandleCashEngagementSection";
import { ComparisonSelector } from "./components/ComparisonSelector";
import { DashboardHeader } from "./components/DashboardHeader";
import { DashboardShell } from "./components/DashboardShell";
import { FinancialSummarySection } from "./components/FinancialSummarySection";
import { LocationGroupingSelector } from "./components/LocationGroupingSelector";
import { LocationOriginsWidget } from "./components/LocationOriginsWidget";
import { MetricCardGroup } from "./components/MetricCardGroup";
import { PerformanceChartCard } from "./components/PerformanceChartCard";
import { TimeframeSelector } from "./components/TimeframeSelector";
import { useDashboardData } from "./hooks/useDashboardData";
import "./dashboard.css";

interface DashboardAppProps {
  bootstrap: DashboardBootstrap;
}

export function DashboardApp({ bootstrap }: DashboardAppProps) {
  const config = useMemo(
    () => bootstrap.initialData?.config ?? bootstrap.config ?? dashboardConfig,
    [bootstrap],
  );
  const {
    data,
    error,
    loading,
    reminderAction,
    query,
    sendCandleCashReminders,
    setComparison,
    setCustomDates,
    setLocationGrouping,
    setTimeframe,
  } = useDashboardData(bootstrap);

  return (
    <AppProvider i18n={enTranslations}>
      {!bootstrap.authorized ? (
        <PageFallback status={bootstrap.status} />
      ) : (
        <DashboardShell
          header={
            <DashboardHeader
              storeLabel={bootstrap.storeLabel}
              links={bootstrap.links}
              timeframeLabel={data?.query.primary.label ?? "Loading timeframe"}
              generatedAt={data?.meta.generatedAt ?? null}
              partialData={data?.meta.partialData ?? null}
            />
          }
          controls={
            <Card>
              <InlineStack align="space-between" blockAlign="start" gap="400" wrap>
                <BlockStack gap="050">
                  <Text as="h3" variant="headingMd">
                    Dashboard controls
                  </Text>
                  <Text as="p" variant="bodySm" tone="subdued">
                    Timeframe, comparison, and geography controls are now wired to the embedded
                    dashboard payload.
                  </Text>
                </BlockStack>
                <InlineStack gap="300" blockAlign="center" wrap>
                  <div className="sf-dashboard-control">
                    <TimeframeSelector
                      value={query.timeframe}
                      options={config.timeframeOptions}
                      onChange={setTimeframe}
                    />
                  </div>
                  <div className="sf-dashboard-control sf-dashboard-control--comparison">
                    <ComparisonSelector
                      value={query.comparison}
                      options={config.comparisonOptions}
                      onChange={setComparison}
                    />
                  </div>
                  <div className="sf-dashboard-control">
                    <LocationGroupingSelector
                      value={query.locationGrouping}
                      options={config.locationGroupingOptions}
                      onChange={setLocationGrouping}
                    />
                  </div>
                </InlineStack>
              </InlineStack>
              {query.timeframe === "custom" ? (
                <InlineGrid columns={{ xs: 1, md: 2 }} gap="300">
                  <TextField
                    label="Custom start date"
                    type="date"
                    autoComplete="off"
                    value={query.customStartDate ?? ""}
                    onChange={(value) => setCustomDates(value || null, query.customEndDate)}
                  />
                  <TextField
                    label="Custom end date"
                    type="date"
                    autoComplete="off"
                    value={query.customEndDate ?? ""}
                    onChange={(value) => setCustomDates(query.customStartDate, value || null)}
                  />
                </InlineGrid>
              ) : null}
              {error ? (
                <Text as="p" variant="bodySm" tone="critical">
                  {error}
                </Text>
              ) : null}
            </Card>
          }
          metrics={
            config.visibleWidgets.metricCards ? (
              data ? (
                <MetricCardGroup metrics={data.topMetrics} />
              ) : (
                <MetricCardGroupSkeleton />
              )
            ) : null
          }
          candleCashEngagement={
            config.visibleWidgets.candleCashEngagement ? (
              data ? (
                <CandleCashEngagementSection
                  section={data.candleCashEngagement}
                  sendingReminders={reminderAction.loading}
                  reminderFeedback={reminderAction.message ? reminderAction : null}
                  onSendReminders={sendCandleCashReminders}
                />
              ) : (
                <LoadingCard lines={5} />
              )
            ) : null
          }
          chart={
            config.visibleWidgets.performanceChart ? (
              data ? (
                <PerformanceChartCard chart={data.chart} loading={loading} />
              ) : (
                <LoadingCard lines={7} />
              )
            ) : (
              <LoadingCard lines={6} />
            )
          }
          locations={
            config.visibleWidgets.locationOrigins ? (
              data ? (
                <LocationOriginsWidget section={data.locationOrigins} />
              ) : (
                <LoadingCard lines={4} />
              )
            ) : (
              <LoadingCard lines={4} />
            )
          }
          attribution={
            config.visibleWidgets.attribution ? (
              data ? (
                <AttributionSection section={data.attribution} />
              ) : (
                <LoadingCard lines={5} />
              )
            ) : null
          }
          financialSummary={
            config.visibleWidgets.financialSummary ? (
              data ? (
                <FinancialSummarySection section={data.financialSummary} />
              ) : (
                <LoadingCard lines={5} />
              )
            ) : null
          }
        />
      )}
    </AppProvider>
  );
}

function MetricCardGroupSkeleton() {
  return (
    <InlineGrid columns={{ xs: 1, sm: 2, md: 4 }} gap="400">
      {Array.from({ length: 4 }).map((_, index) => (
        <Card key={index}>
          <BlockStack gap="300">
            <SkeletonBodyText lines={1} />
            <SkeletonDisplayText size="small" />
            <SkeletonBodyText lines={2} />
          </BlockStack>
        </Card>
      ))}
    </InlineGrid>
  );
}

function LoadingCard({ lines }: { lines: number }) {
  return (
    <Card>
      <BlockStack gap="300">
        <SkeletonDisplayText size="small" />
        <SkeletonBodyText lines={lines} />
      </BlockStack>
    </Card>
  );
}

function PageFallback({ status }: { status: string }) {
  const heading =
    status === "open_from_shopify"
      ? "Open this app from Shopify Admin"
      : "We could not verify this Shopify request";

  const description =
    status === "open_from_shopify"
      ? "This dashboard is designed to run inside Shopify Admin so the embedded store context can be verified."
      : "Open the app again from Shopify Admin. If this keeps happening, the embedded app configuration likely needs attention.";

  return (
    <div className="sf-dashboard-fallback">
      <Card>
        <BlockStack gap="200">
          <Text as="h2" variant="headingLg">
            {heading}
          </Text>
          <Text as="p" variant="bodyMd" tone="subdued">
            {description}
          </Text>
        </BlockStack>
      </Card>
    </div>
  );
}
