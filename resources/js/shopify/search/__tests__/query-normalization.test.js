import test from "node:test";
import assert from "node:assert/strict";
import { normalizeQueryIntent } from "../queryNormalization.js";

test("normalizes intent phrases and product synonyms", () => {
  const query = normalizeQueryIntent("new product");
  assert.equal(query.normalizedQuery, "create product");
  assert.ok(query.expandedTerms.includes("create"));
});

test("normalizes delivery to shipping", () => {
  const query = normalizeQueryIntent("delivery settings");
  assert.equal(query.normalizedQuery, "shipping settings");
  assert.ok(query.expandedTerms.includes("shipping"));
});

test("normalizes prefs to settings", () => {
  const query = normalizeQueryIntent("prefs");
  assert.equal(query.normalizedQuery, "settings");
  assert.ok(query.expandedTerms.includes("settings"));
});

