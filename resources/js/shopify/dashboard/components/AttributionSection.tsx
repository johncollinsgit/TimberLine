import { BlockStack, Card, InlineGrid, Text } from "@shopify/polaris";
import type { DashboardPayload } from "../types";
import { AttributionSourceCard } from "./AttributionSourceCard";

interface AttributionSectionProps {
  section: DashboardPayload["attribution"];
}

export function AttributionSection({ section }: AttributionSectionProps) {
  return (
    <Card>
      <BlockStack gap="400">
        <BlockStack gap="100">
          <Text as="h3" variant="headingMd">
            {section.title}
          </Text>
          <Text as="p" variant="bodySm" tone="subdued">
            {section.subtitle}
          </Text>
        </BlockStack>

        {section.empty ? (
          <Text as="p" variant="bodySm" tone="subdued">
            No attributable rewards-linked revenue was found for the selected timeframe yet.
          </Text>
        ) : (
          <InlineGrid columns={{ xs: 1, sm: 2, md: 5 }} gap="300">
            {section.sources.map((source) => (
            <AttributionSourceCard key={source.key} source={source} />
            ))}
          </InlineGrid>
        )}
      </BlockStack>
    </Card>
  );
}
