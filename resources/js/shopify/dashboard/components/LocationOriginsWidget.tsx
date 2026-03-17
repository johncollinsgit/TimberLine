import { BlockStack, Card, InlineStack, ProgressBar, Text } from "@shopify/polaris";
import type { DashboardLocationOrigin } from "../types";

interface LocationOriginsWidgetProps {
  locations: DashboardLocationOrigin[];
}

export function LocationOriginsWidget({ locations }: LocationOriginsWidgetProps) {
  return (
    <Card>
      <BlockStack gap="400">
        <BlockStack gap="100">
          <Text as="h3" variant="headingMd">
            Location origins
          </Text>
          <Text as="p" variant="bodySm" tone="subdued">
            Where reward-aware orders are beginning across the storefront and marketing journey.
          </Text>
        </BlockStack>

        <BlockStack gap="300">
          {locations.map((location) => (
            <BlockStack key={location.name} gap="100">
              <InlineStack align="space-between">
                <Text as="span" variant="bodyMd">
                  {location.name}
                </Text>
                <Text as="span" variant="bodySm" tone="subdued">
                  {location.orders} orders
                </Text>
              </InlineStack>
              <ProgressBar progress={location.share} tone="success" size="small" />
            </BlockStack>
          ))}
        </BlockStack>
      </BlockStack>
    </Card>
  );
}
