import { Badge, BlockStack, Button, ButtonGroup, InlineStack, Text } from "@shopify/polaris";

interface DashboardHeaderProps {
  storeLabel: string;
  links: Array<{
    label: string;
    href: string;
    external?: boolean;
  }>;
}

export function DashboardHeader({ storeLabel, links }: DashboardHeaderProps) {
  return (
    <div className="sf-dashboard-header">
      <BlockStack gap="400">
        <InlineStack align="space-between" blockAlign="start" gap="400">
          <BlockStack gap="200">
            <InlineStack gap="200" blockAlign="center">
              <Badge tone="success">Dashboard</Badge>
              <Text as="span" variant="bodySm" tone="subdued">
                {storeLabel}
              </Text>
            </InlineStack>
            <Text as="h2" variant="headingXl">
              Rewards performance, retention, and Candle Cash impact
            </Text>
            <Text as="p" variant="bodyMd" tone="subdued">
              Slice 1 is a static Polaris shell with realistic mock analytics so we can refine
              layout, hierarchy, and component boundaries before wiring live data.
            </Text>
          </BlockStack>
        </InlineStack>

        {links.length > 0 ? (
          <ButtonGroup>
            {links.map((link) => (
              <Button
                key={link.label}
                url={link.href}
                target={link.external ? "_blank" : "_self"}
                variant="tertiary"
              >
                {link.label}
              </Button>
            ))}
          </ButtonGroup>
        ) : null}
      </BlockStack>
    </div>
  );
}
