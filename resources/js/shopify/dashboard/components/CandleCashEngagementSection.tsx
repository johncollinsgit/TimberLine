import { Badge, BlockStack, Button, Card, InlineGrid, InlineStack, Text } from "@shopify/polaris";
import type { DashboardPayload } from "../types";

interface CandleCashEngagementSectionProps {
  section: DashboardPayload["candleCashEngagement"];
  balanceLiability: DashboardPayload["balanceLiability"];
  sendingReminders: boolean;
  reminderFeedback: { tone: "success" | "critical" | "subdued"; message: string } | null;
  onSendReminders: () => Promise<void>;
}

const readinessBadgeTone = {
  ready: "success",
  unsupported: "critical",
  incomplete: "critical",
  error: "critical",
  not_configured: "warning",
} as const;

export function CandleCashEngagementSection({
  section,
  balanceLiability,
  sendingReminders,
  reminderFeedback,
  onSendReminders,
}: CandleCashEngagementSectionProps) {
  const readiness = section.reminderEligibility.emailReadiness;
  const readinessStatus = readiness?.status ?? "not_configured";
  const remindersBlocked = readiness?.canSend === false || readinessStatus !== "ready";
  const reminderButtonLabel =
    readinessStatus === "ready" && readiness?.dryRun
      ? "Run unused-balance reminder dry run"
      : "Send unused-balance reminder emails";

  return (
    <Card>
      <BlockStack gap="300">
        <InlineStack align="space-between" blockAlign="start" gap="300" wrap>
          <BlockStack gap="100">
            <Text as="h3" variant="headingMd">
              {section.title}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              {section.subtitle}
            </Text>
          </BlockStack>
          <BlockStack gap="150">
            <InlineStack gap="200" blockAlign="center">
              <Badge tone={readinessBadgeTone[readinessStatus as keyof typeof readinessBadgeTone] ?? "warning"}>
                {readinessStatus === "ready"
                  ? readiness?.dryRun
                    ? "Ready (dry run)"
                    : "Ready"
                  : readinessStatus === "unsupported"
                    ? "Unsupported"
                    : readinessStatus === "incomplete"
                      ? "Incomplete setup"
                      : readinessStatus === "error"
                        ? "Validation error"
                        : "Not configured"}
              </Badge>
              <Button
                variant="primary"
                loading={sendingReminders}
                disabled={sendingReminders || remindersBlocked || section.reminderEligibility.eligibleCustomers === 0}
                onClick={() => {
                  void onSendReminders();
                }}
              >
                {reminderButtonLabel}
              </Button>
            </InlineStack>
            <Text as="p" variant="bodySm" tone="subdued">
              Eligible customers: {section.reminderEligibility.eligibleCustomers}
              {section.reminderEligibility.missingEmailCustomers > 0
                ? ` · Missing email: ${section.reminderEligibility.missingEmailCustomers}`
                : ""}
            </Text>
          </BlockStack>
        </InlineStack>

        {readiness?.missingReasons?.length ? (
          <Text as="p" variant="bodySm" tone="critical">
            {readiness.missingReasons.join(" · ")}
          </Text>
        ) : null}
        {readiness?.notes?.length ? (
          <Text as="p" variant="bodySm" tone="subdued">
            {readiness.notes.join(" · ")}
          </Text>
        ) : null}
        {readiness?.warnings?.length ? (
          <Text as="p" variant="bodySm" tone="subdued">
            {readiness.warnings.join(" · ")}
          </Text>
        ) : null}
        {reminderFeedback ? (
          <Text as="p" variant="bodySm" tone={reminderFeedback.tone}>
            {reminderFeedback.message}
          </Text>
        ) : null}

        <InlineGrid columns={{ xs: 1, md: 3 }} gap="300">
          <div className="sf-dashboard-engagement-pill">
            <Text as="p" variant="bodySm" tone="subdued">
              Total Active Candle Cash
            </Text>
            <Text as="p" variant="headingLg">
              {balanceLiability.totalCurrentBalance.formattedAmount}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              {balanceLiability.reconciled ? "Ledger reconciled" : "Ledger reconciliation needed"}
            </Text>
          </div>
          <div className="sf-dashboard-engagement-pill">
            <Text as="p" variant="bodySm" tone="subdued">
              Legacy Growave Candle Cash
            </Text>
            <Text as="p" variant="headingLg">
              {balanceLiability.legacyMigrated.formattedAmount}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              Non-expiring migrated balance
            </Text>
          </div>
          <div className="sf-dashboard-engagement-pill">
            <Text as="p" variant="bodySm" tone="subdued">
              Expiring Program Candle Cash
            </Text>
            <Text as="p" variant="headingLg">
              {balanceLiability.programExpiring.formattedAmount}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              Earned under the active rewards policy
            </Text>
          </div>
        </InlineGrid>

        {balanceLiability.manualNonExpiring.amount > 0 ? (
          <Text as="p" variant="bodySm" tone="subdued">
            Manual non-expiring Candle Cash outside the legacy Growave migration:{" "}
            {balanceLiability.manualNonExpiring.formattedAmount}
          </Text>
        ) : null}

        <InlineGrid columns={{ xs: 1, md: 3 }} gap="300">
          <div className="sf-dashboard-engagement-pill">
            <Text as="p" variant="bodySm" tone="subdued">
              Program Earned (Selected Period)
            </Text>
            <Text as="p" variant="headingLg">
              {section.earned.formattedAmount}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              {section.earned.eventCount} events · {section.earned.customerCount} customers
            </Text>
          </div>
          <div className="sf-dashboard-engagement-pill">
            <Text as="p" variant="bodySm" tone="subdued">
              Outstanding Expiring Rewards
            </Text>
            <Text as="p" variant="headingLg">
              {section.outstanding.formattedAmount}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              {section.outstanding.customerCount} customers
            </Text>
          </div>
          <div className="sf-dashboard-engagement-pill">
            <Text as="p" variant="bodySm" tone="subdued">
              Time to first redemption
            </Text>
            <Text as="p" variant="headingLg">
              {section.timeToFirstRedemption.formattedAverageDays}
            </Text>
            <Text as="p" variant="bodySm" tone="subdued">
              Median {section.timeToFirstRedemption.formattedMedianDays}
            </Text>
          </div>
        </InlineGrid>

        <BlockStack gap="200">
          <Text as="p" variant="bodySm" tone="subdued">
            {balanceLiability.helperText}
          </Text>
          <Text as="p" variant="bodySm" tone="subdued">
            {section.earned.sourceSummary}
          </Text>
          <div className="sf-dashboard-breakdown-table">
            <div className="sf-dashboard-breakdown-table__head">
              <span>Earn source</span>
              <span>Amount</span>
              <span>Share</span>
              <span>Events</span>
              <span>Customers</span>
            </div>
            {section.breakdown.rows.map((row) => (
              <div key={row.key} className="sf-dashboard-breakdown-table__row" title={row.definition}>
                <span>{row.label}</span>
                <span>{row.formattedAmount}</span>
                <span>{row.sharePct.toFixed(1)}%</span>
                <span>{row.eventCount}</span>
                <span>{row.customerCount}</span>
              </div>
            ))}
          </div>
          <details className="sf-dashboard-breakdown-details">
            <summary>Source definitions</summary>
            <BlockStack gap="100">
              {Object.entries(section.breakdown.sourceDefinitions).map(([key, definition]) => (
                <Text key={key} as="p" variant="bodySm" tone="subdued">
                  <strong>{definition.label}:</strong> {definition.definition}
                </Text>
              ))}
            </BlockStack>
          </details>
          <Text as="p" variant="bodySm" tone="subdued">
            {section.outstanding.helperText}
          </Text>
          <Text as="p" variant="bodySm" tone="subdued">
            {section.timeToFirstRedemption.approximation}
          </Text>
        </BlockStack>
      </BlockStack>
    </Card>
  );
}
