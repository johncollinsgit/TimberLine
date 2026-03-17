import { Layout, Page } from "@shopify/polaris";
import type { ReactNode } from "react";

interface DashboardShellProps {
  header: ReactNode;
  controls: ReactNode;
  metrics: ReactNode;
  chart: ReactNode;
  locations: ReactNode;
  attribution: ReactNode;
  financialSummary: ReactNode;
}

export function DashboardShell({
  header,
  controls,
  metrics,
  chart,
  locations,
  attribution,
  financialSummary,
}: DashboardShellProps) {
  return (
    <div className="sf-dashboard-shell">
      <Page fullWidth>
        <Layout>
          <Layout.Section>{header}</Layout.Section>
          <Layout.Section>{controls}</Layout.Section>
          <Layout.Section>{metrics}</Layout.Section>
          <Layout.Section>
            <div className="sf-dashboard-main-grid">
              <div>{chart}</div>
              <div>{locations}</div>
            </div>
          </Layout.Section>
          <Layout.Section>{attribution}</Layout.Section>
          <Layout.Section>{financialSummary}</Layout.Section>
        </Layout>
      </Page>
    </div>
  );
}
