import { Select } from "@shopify/polaris";
import type { DashboardTimeframe } from "../types";

interface TimeframeSelectorProps {
  value: DashboardTimeframe;
  options: Array<{ label: string; value: DashboardTimeframe }>;
  onChange: (value: DashboardTimeframe) => void;
}

export function TimeframeSelector({ value, options, onChange }: TimeframeSelectorProps) {
  return (
    <Select
      label="Timeframe"
      labelHidden
      options={options}
      value={value}
      onChange={(nextValue) => onChange(nextValue as DashboardTimeframe)}
    />
  );
}
