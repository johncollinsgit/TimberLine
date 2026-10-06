import type {
  CartValidationsGenerateRunInput,
  CartValidationsGenerateRunResult,
  ValidationError,
} from "../generated/api";

type BundleScentRule = {
  enabled: boolean;
  optionCount: number;
  requireDistinctValues: boolean;
  allowedValues: string[];
};

type LineAttribute = {
  value?: string | null;
} | null;

const MAX_SCENT_OPTIONS = 24;

// These assignments protect the initially migrated option sets before their
// product metafields have been synced. Everbranch-managed product metafields
// are authoritative when present.
const MIGRATED_RULES: Record<string, Omit<BundleScentRule, "allowedValues">> = {
  "three-room-sprays-for-30": {
    enabled: true,
    optionCount: 3,
    requireDistinctValues: true,
  },
  "8oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry": {
    enabled: true,
    optionCount: 3,
    requireDistinctValues: true,
  },
  "teacher-candles": {
    enabled: true,
    optionCount: 2,
    requireDistinctValues: false,
  },
  "5-wax-melts-bundle": {
    enabled: true,
    optionCount: 5,
    requireDistinctValues: true,
  },
  "4oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry": {
    enabled: true,
    optionCount: 3,
    requireDistinctValues: true,
  },
};

export function cartValidationsGenerateRun(input: CartValidationsGenerateRunInput): CartValidationsGenerateRunResult {
  const errors: ValidationError[] = [];

  input.cart.lines.forEach((line) => {
    if (line.merchandise.__typename !== "ProductVariant") {
      return;
    }

    const product = line.merchandise.product;
    const rule = normalizeRule(product.bundleScentRule?.jsonValue, product.handle);
    if (!rule?.enabled) {
      return;
    }

    const selected = selectedScents(line, rule.optionCount);
    if (selected.length !== rule.optionCount) {
      errors.push({
        message: `${product.title} requires ${rule.optionCount} scent ${rule.optionCount === 1 ? "selection" : "selections"}. Return to the product and choose every scent before checkout.`,
        target: "$.cart",
      });
      return;
    }

    if (
      rule.requireDistinctValues
      && new Set(selected.map((value) => value.toLocaleLowerCase())).size !== selected.length
    ) {
      errors.push({
        message: `${product.title} requires a different scent for each selection.`,
        target: "$.cart",
      });
      return;
    }

    if (rule.allowedValues.length > 0) {
      const allowed = new Set(rule.allowedValues.map((value) => value.toLocaleLowerCase()));
      if (selected.some((value) => !allowed.has(value.toLocaleLowerCase()))) {
        errors.push({
          message: `${product.title} contains a scent that is no longer available. Return to the product and choose the scents again.`,
          target: "$.cart",
        });
      }
    }
  });

  const operations = [
    {
      validationAdd: {
        errors,
      },
    },
  ];

  return { operations };
}

function selectedScents(
  line: CartValidationsGenerateRunInput["cart"]["lines"][number],
  optionCount: number,
): string[] {
  const attributes = line as unknown as Record<string, LineAttribute>;
  const values: string[] = [];

  for (let position = 1; position <= optionCount; position += 1) {
    const value = String(attributes[`scent${position}`]?.value || "").trim();
    if (value !== "") {
      values.push(value);
    }
  }

  return values;
}

function normalizeRule(value: unknown, handle: string): BundleScentRule | null {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    const input = value as Record<string, unknown>;
    const optionCount = clampOptionCount(input.option_count);
    if (optionCount !== null) {
      return {
        enabled: input.enabled !== false,
        optionCount,
        requireDistinctValues: input.require_distinct_values === true,
        allowedValues: Array.isArray(input.allowed_values)
          ? input.allowed_values
            .map((item) => String(item || "").trim())
            .filter(Boolean)
          : [],
      };
    }
  }

  const migrated = MIGRATED_RULES[handle];
  return migrated ? { ...migrated, allowedValues: [] } : null;
}

function clampOptionCount(value: unknown): number | null {
  const count = Number(value);
  if (!Number.isInteger(count) || count < 1) {
    return null;
  }

  return Math.min(count, MAX_SCENT_OPTIONS);
}
