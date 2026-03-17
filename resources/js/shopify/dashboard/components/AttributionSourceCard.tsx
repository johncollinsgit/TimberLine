import { Badge, BlockStack, Card, InlineStack, Text } from "@shopify/polaris";
import type { DashboardAttributionSource } from "../types";

interface AttributionSourceCardProps {
  source: DashboardAttributionSource;
}

export function AttributionSourceCard({ source }: AttributionSourceCardProps) {
  const tone =
    source.tone === "positive" ? "success" : source.tone === "negative" ? "critical" : "info";

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
          <Badge tone={source.live ? tone : "attention"}>{source.live ? source.deltaLabel : "Unmapped"}</Badge>
        </InlineStack>
        <Text as="p" variant="bodySm" tone="subdued">
          {source.description}
        </Text>
      </BlockStack>
    </Card>
  );
}
