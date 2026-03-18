import { BlockStack, Card, Divider, InlineGrid, Text } from "@shopify/polaris";
import type { DashboardPayload } from "../types";

interface FinancialSummarySectionProps {
  section: DashboardPayload["financialSummary"];
}

export function FinancialSummarySection({ section }: FinancialSummarySectionProps) {
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
        <Divider />
        <InlineGrid columns={{ xs: 1, md: 3 }} gap="400">
          {section.items.map((item) => (
            <BlockStack key={item.label} gap="100">
              <Text as="span" variant="bodySm" tone="subdued">
                {item.label}
              </Text>
              <Text as="p" variant="headingLg">
                {item.formattedValue}
              </Text>
              <Text as="p" variant="bodySm" tone="subdued">
                {item.detail}
              </Text>
            </BlockStack>
          ))}
        </InlineGrid>
        <Divider />
        <BlockStack gap="100">
          <Text as="span" variant="bodySm" tone="subdued">
            {section.netProfit.label ?? "Net profit created"}
          </Text>
          <Text as="p" variant="headingLg">
            {section.netProfit.formattedValue}
          </Text>
          {section.netProfit.detail ? (
            <Text as="p" variant="bodySm" tone="subdued">
              {section.netProfit.detail}
            </Text>
          ) : null}
        </BlockStack>
      </BlockStack>
    </Card>
  );
}
