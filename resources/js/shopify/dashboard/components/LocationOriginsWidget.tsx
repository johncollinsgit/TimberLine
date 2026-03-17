import { BlockStack, Card, InlineStack, ProgressBar, Text } from "@shopify/polaris";
import type { DashboardPayload } from "../types";

interface LocationOriginsWidgetProps {
  section: DashboardPayload["locationOrigins"];
}

export function LocationOriginsWidget({ section }: LocationOriginsWidgetProps) {
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
            No location-linked reward data is available for this window yet.
          </Text>
        ) : (
          <BlockStack gap="300">
            {section.items.map((location) => (
            <BlockStack key={location.name} gap="100">
              <InlineStack align="space-between">
                <Text as="span" variant="bodyMd">
                  {location.name}
                </Text>
                <Text as="span" variant="bodySm" tone="subdued">
                  {location.orders} orders · {location.formattedRevenue}
                </Text>
              </InlineStack>
              <ProgressBar progress={location.share} tone="success" size="small" />
            </BlockStack>
            ))}
          </BlockStack>
        )}
      </BlockStack>
    </Card>
  );
}
