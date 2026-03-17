import { Select } from "@shopify/polaris";

interface LocationGroupingSelectorProps {
  value: "country" | "state" | "city";
  options: Array<{ label: string; value: "country" | "state" | "city" }>;
  onChange: (value: "country" | "state" | "city") => void;
}

export function LocationGroupingSelector({
  value,
  options,
  onChange,
}: LocationGroupingSelectorProps) {
  return (
    <Select
      label="Location grouping"
      labelHidden
      options={options}
      value={value}
      onChange={(nextValue) => onChange(nextValue as "country" | "state" | "city")}
    />
  );
}
