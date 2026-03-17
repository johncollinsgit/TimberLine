import { Button, ButtonGroup } from "@shopify/polaris";
import type { DashboardComparison } from "../types";

interface ComparisonSelectorProps {
  value: DashboardComparison;
  options: Array<{ label: string; value: DashboardComparison }>;
  onChange: (value: DashboardComparison) => void;
}

export function ComparisonSelector({ value, options, onChange }: ComparisonSelectorProps) {
  return (
    <ButtonGroup segmented>
      {options.map((option) => (
        <Button
          key={option.value}
          pressed={value === option.value}
          onClick={() => onChange(option.value)}
        >
          {option.label}
        </Button>
      ))}
    </ButtonGroup>
  );
}
