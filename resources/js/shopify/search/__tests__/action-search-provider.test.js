import test from "node:test";
import assert from "node:assert/strict";
import { ActionSearchProvider } from "../ActionSearchProvider.js";
import { registerSearchActions } from "../registerSearchActions.js";

test("action registration and unregistration works by id and scope", () => {
  const provider = new ActionSearchProvider();

  const unregister = registerSearchActions(
    "feature:orders",
    [
      {
        id: "action:orders",
        title: "Go to orders",
        section: "actions",
        execute: () => {},
      },
    ],
    provider
  );

  assert.equal(provider.snapshot().length, 1);
  assert.equal(provider.snapshot()[0].id, "action:orders");

  unregister();
  assert.equal(provider.snapshot().length, 0);

  registerSearchActions(
    "feature:products",
    [
      { id: "action:create-product", title: "Create product", section: "actions", execute: () => {} },
      { id: "action:view-products", title: "View products", section: "pages", execute: () => {} },
    ],
    provider
  );
  assert.equal(provider.snapshot().length, 2);

  provider.unregister("feature:products");
  assert.equal(provider.snapshot().length, 0);
});
