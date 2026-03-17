import {
  AppProvider,
  BlockStack,
  Card,
  InlineStack,
  SkeletonBodyText,
  Text,
} from "@shopify/polaris";
import "@shopify/polaris/build/esm/styles.css";
import enTranslations from "@shopify/polaris/locales/en.json";
import { useMemo, useState } from "react";
import { dashboardConfig } from "./dashboardConfig";
import { mockDashboardData } from "./dashboardMockData";
import type { DashboardBootstrap, DashboardComparison, DashboardTimeframe } from "./types";
import { AttributionSection } from "./components/AttributionSection";
import { ComparisonSelector } from "./components/ComparisonSelector";
import { DashboardHeader } from "./components/DashboardHeader";
import { DashboardShell } from "./components/DashboardShell";
import { FinancialSummarySection } from "./components/FinancialSummarySection";
import { LocationOriginsWidget } from "./components/LocationOriginsWidget";
import { MetricCardGroup } from "./components/MetricCardGroup";
import { PerformanceChartCard } from "./components/PerformanceChartCard";
import { TimeframeSelector } from "./components/TimeframeSelector";
import "./dashboard.css";

interface DashboardAppProps {
  bootstrap: DashboardBootstrap;
}

export function DashboardApp({ bootstrap }: DashboardAppProps) {
  const [timeframe, setTimeframe] = useState<DashboardTimeframe>(dashboardConfig.defaultTimeframe);
  const [comparison, setComparison] = useState<DashboardComparison>(
    dashboardConfig.defaultComparison,
  );

  const data = useMemo(() => mockDashboardData, []);

  return (
    <AppProvider i18n={enTranslations}>
      {!bootstrap.authorized ? (
        <PageFallback status={bootstrap.status} />
      ) : (
        <DashboardShell
          header={<DashboardHeader storeLabel={bootstrap.storeLabel} links={bootstrap.links} />}
          controls={
            <Card>
              <InlineStack align="space-between" blockAlign="center" gap="400" wrap>
                <BlockStack gap="050">
                  <Text as="h3" variant="headingMd">
                    Dashboard controls
                  </Text>
                  <Text as="p" variant="bodySm" tone="subdued">
                    Static selectors for Slice 1. Live timeframe and comparison plumbing lands in
                    Slice 2.
                  </Text>
                </BlockStack>
                <InlineStack gap="300" blockAlign="center" wrap>
                  <div className="sf-dashboard-control">
                    <TimeframeSelector
                      value={timeframe}
                      options={dashboardConfig.timeframeOptions}
                      onChange={setTimeframe}
                    />
                  </div>
                  <div className="sf-dashboard-control sf-dashboard-control--comparison">
                    <ComparisonSelector
                      value={comparison}
                      options={dashboardConfig.comparisonOptions}
                      onChange={setComparison}
                    />
                  </div>
                </InlineStack>
              </InlineStack>
            </Card>
          }
          metrics={dashboardConfig.visibleWidgets.metricCards ? <MetricCardGroup metrics={data.metrics} /> : null}
          chart={
            dashboardConfig.visibleWidgets.performanceChart ? (
              <PerformanceChartCard chart={data.chart} />
            ) : (
              <Card>
                <SkeletonBodyText lines={6} />
              </Card>
            )
          }
          locations={
            dashboardConfig.visibleWidgets.locationOrigins ? (
              <LocationOriginsWidget locations={data.locations} />
            ) : (
              <Card>
                <SkeletonBodyText lines={4} />
              </Card>
            )
          }
          attribution={
            dashboardConfig.visibleWidgets.attribution ? (
              <AttributionSection sources={data.attribution} />
            ) : null
          }
          financialSummary={
            dashboardConfig.visibleWidgets.financialSummary ? (
              <FinancialSummarySection items={data.financialSummary} />
            ) : null
          }
        />
      )}
    </AppProvider>
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
