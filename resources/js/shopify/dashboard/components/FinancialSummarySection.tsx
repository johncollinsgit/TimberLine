import { BlockStack, Card, Divider, InlineGrid, Text } from "@shopify/polaris";
import type { DashboardFinancialSummaryItem } from "../types";

interface FinancialSummarySectionProps {
  items: DashboardFinancialSummaryItem[];
}

export function FinancialSummarySection({ items }: FinancialSummarySectionProps) {
  return (
    <Card>
      <BlockStack gap="400">
        <BlockStack gap="100">
          <Text as="h3" variant="headingMd">
            Financial summary
          </Text>
          <Text as="p" variant="bodySm" tone="subdued">
            A mocked financial rollup so we can settle the final Candle Cash and profit model
            layout before backend aggregation arrives.
          </Text>
        </BlockStack>
        <Divider />
        <InlineGrid columns={{ xs: 1, md: 3 }} gap="400">
          {items.map((item) => (
            <BlockStack key={item.label} gap="100">
              <Text as="span" variant="bodySm" tone="subdued">
                {item.label}
              </Text>
              <Text as="p" variant="headingLg">
                {item.value}
              </Text>
              <Text as="p" variant="bodySm" tone="subdued">
                {item.detail}
              </Text>
            </BlockStack>
          ))}
        </InlineGrid>
      </BlockStack>
    </Card>
  );
}
