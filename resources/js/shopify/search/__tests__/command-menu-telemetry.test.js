import test from "node:test";
import assert from "node:assert/strict";
import {
  buildQueryTelemetry,
  setCommandMenuTelemetryAdapter,
  trackCommandMenuEvent,
} from "../commandMenuTelemetry.js";

test("query telemetry redacts likely identifiers", () => {
  const payload = buildQueryTelemetry("open customer 123456");
  assert.equal(payload.queryClass, "contains_identifier");
  assert.equal(payload.normalizedQuery.includes("identifier"), true);
});

test("telemetry adapter receives open/select/zero-result/abandon events", () => {
  const captured = [];
  setCommandMenuTelemetryAdapter({
    track(eventName, payload) {
      captured.push({ eventName, payload });
    },
  });

  trackCommandMenuEvent("command_menu_opened", { queryLength: 0 });
  trackCommandMenuEvent("command_menu_result_selected", { resultId: "shopify:action:create-product" });
  trackCommandMenuEvent("command_menu_zero_result_query", { queryLength: 12 });
  trackCommandMenuEvent("command_menu_submit_no_results", { queryLength: 9 });
  trackCommandMenuEvent("command_menu_query_abandoned", { queryLength: 4 });

  assert.deepEqual(
    captured.map((entry) => entry.eventName),
    [
      "command_menu_opened",
      "command_menu_result_selected",
      "command_menu_zero_result_query",
      "command_menu_submit_no_results",
      "command_menu_query_abandoned",
    ]
  );

  setCommandMenuTelemetryAdapter(null);
});
