import { Badge, BlockStack, Card, InlineStack, Text } from "@shopify/polaris";
import type { DashboardAttributionSource } from "../types";

interface AttributionSourceCardProps {
  source: DashboardAttributionSource;
}

export function AttributionSourceCard({ source }: AttributionSourceCardProps) {
  const tone =
    source.tone === "positive" ? "success" : source.tone === "negative" ? "critical" : "info";
  const badgeTone = source.live ? tone : "attention";
  const badgeLabel =
    source.orders === 0 ? "No data" : source.live ? (source.deltaPct === null ? "Live" : source.deltaLabel) : "Pending";

  return (
    <Card>
      <BlockStack gap="250">
        <InlineStack align="space-between" blockAlign="start">
          <BlockStack gap="050">
            <Text as="h4" variant="headingSm">
              {source.label}
            </Text>
            <Text as="span" variant="headingLg">
              {source.formattedRevenue}
            </Text>
          </BlockStack>
          <Badge tone={badgeTone}>{badgeLabel}</Badge>
        </InlineStack>
        <Text as="p" variant="bodySm" tone="subdued">
          {source.orders} order{source.orders === 1 ? "" : "s"}
        </Text>
        {source.profit && source.profit > 0 && source.formattedProfit ? (
          <Text as="p" variant="bodySm" tone="subdued">
            Net profit {source.formattedProfit}
          </Text>
        ) : null}
        <Text as="p" variant="bodySm" tone="subdued">
          {source.description}
        </Text>
      </BlockStack>
    </Card>
  );
}
