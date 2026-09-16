import type {
  CartValidationsGenerateRunInput,
  CartValidationsGenerateRunResult,
  ValidationError,
} from "../generated/api";

export function cartValidationsGenerateRun(input: CartValidationsGenerateRunInput): CartValidationsGenerateRunResult {
  const errors: ValidationError[] = [];

  // Shopify runs this independently of storefront JavaScript, including for
  // accelerated checkout. The wholesale customer tag is therefore the
  // authoritative purchase permission whenever the cart has merchandise.
  if (input.cart.lines.length > 0 && input.cart.buyerIdentity?.customer?.hasWholesaleAccess !== true) {
    errors.push({
      message: "Wholesale ordering is available to approved partners. Sign in with your approved wholesale account or apply for wholesale access.",
      target: "$.cart",
    });
  }

  const operations = [
    {
      validationAdd: {
        errors
      },
    },
  ];

  return { operations };
};
