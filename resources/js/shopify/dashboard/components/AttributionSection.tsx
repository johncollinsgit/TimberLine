import { BlockStack, Card, InlineGrid, Text } from "@shopify/polaris";
import type { DashboardAttributionSource } from "../types";
import { AttributionSourceCard } from "./AttributionSourceCard";

interface AttributionSectionProps {
  sources: DashboardAttributionSource[];
}

export function AttributionSection({ sources }: AttributionSectionProps) {
  return (
    <Card>
      <BlockStack gap="400">
        <BlockStack gap="100">
          <Text as="h3" variant="headingMd">
            Attribution
          </Text>
          <Text as="p" variant="bodySm" tone="subdued">
            Mock source mix across the channels we expect to normalize in Slice 4.
          </Text>
        </BlockStack>

        <InlineGrid columns={{ xs: 1, sm: 2, md: 5 }} gap="300">
          {sources.map((source) => (
            <AttributionSourceCard key={source.key} source={source} />
          ))}
        </InlineGrid>
      </BlockStack>
    </Card>
  );
}
