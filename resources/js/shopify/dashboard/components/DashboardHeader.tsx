import { Badge, BlockStack, Button, ButtonGroup, InlineStack, Text } from "@shopify/polaris";

interface DashboardHeaderProps {
  storeLabel: string;
  timeframeLabel: string;
  generatedAt: string | null;
  refreshing?: boolean;
  partialData: {
    attribution: boolean;
    locations: boolean;
    profit: boolean;
  } | null;
  links: Array<{
    label: string;
    href: string;
    external?: boolean;
  }>;
}

export function DashboardHeader({
  storeLabel,
  timeframeLabel,
  generatedAt,
  refreshing = false,
  partialData,
  links,
}: DashboardHeaderProps) {
  const note = partialData
    ? [
        partialData.attribution ? "Attribution still has partial coverage." : null,
        partialData.locations ? "Location groupings are best-effort from local profile addresses." : null,
        partialData.profit ? "Profit now uses stored COGS where available and conservative fallbacks where cost coverage is still incomplete." : null,
      ]
        .filter(Boolean)
        .join(" ")
    : null;
  const updatedAt = generatedAt ? new Date(generatedAt) : null;
  const formattedUpdatedAt =
    updatedAt && Number.isFinite(updatedAt.valueOf())
      ? new Intl.DateTimeFormat(undefined, {
          month: "short",
          day: "numeric",
          hour: "numeric",
          minute: "2-digit",
        }).format(updatedAt)
      : "—";

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
              <Text as="span" variant="bodySm" tone="subdued">
                {timeframeLabel}
              </Text>
            </InlineStack>
            <Text as="h2" variant="headingXl">
              Rewards performance, retention, and Candle Cash impact
            </Text>
            <Text as="p" variant="bodyMd" tone="subdued">
              The dashboard is now reading from live Backstage data sources so the embedded home can
              act like a real analytics surface instead of a placeholder.
            </Text>
            <Text as="p" variant="bodySm" tone="subdued" className="sf-dashboard-header__updated" aria-live="polite">
              <span>Last updated {formattedUpdatedAt}</span>
              {refreshing ? <span className="sf-dashboard-header__refreshing">Refreshing…</span> : null}
            </Text>
            {note ? (
              <Text as="p" variant="bodySm" tone="subdued">
                {note}
              </Text>
            ) : null}
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
