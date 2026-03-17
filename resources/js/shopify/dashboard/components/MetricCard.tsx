import { Badge, BlockStack, Box, Card, InlineStack, Text } from "@shopify/polaris";
import type { DashboardMetric } from "../types";

interface MetricCardProps {
  metric: DashboardMetric;
}

export function MetricCard({ metric }: MetricCardProps) {
  const badgeTone =
    metric.tone === "positive" ? "success" : metric.tone === "negative" ? "critical" : "info";

  return (
    <Card>
      <BlockStack gap="300">
        <InlineStack align="space-between" blockAlign="start" gap="200">
          <BlockStack gap="100">
            <Text as="span" variant="bodySm" tone="subdued">
              {metric.label}
            </Text>
            <Text as="p" variant="headingLg">
              {metric.value}
            </Text>
          </BlockStack>
          <Badge tone={badgeTone}>{metric.delta}</Badge>
        </InlineStack>
        <Box minHeight="40px">
          <Text as="p" variant="bodySm" tone="subdued">
            {metric.caption}
          </Text>
        </Box>
      </BlockStack>
    </Card>
  );
}
