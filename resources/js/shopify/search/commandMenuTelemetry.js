import { normalizeText } from "./queryNormalization.js";

function redactQuery(value) {
  const normalized = normalizeText(value);
  if (normalized === "") {
    return "";
  }

  return normalized
    .replace(/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/g, "email")
    .replace(/\b\d{3,}\b/g, "identifier");
}

function classifyQuery(value) {
  const normalized = normalizeText(value);
  if (normalized === "") {
    return "empty";
  }

  if (normalized.includes("identifier")) {
    return "contains_identifier";
  }

  if (normalized.includes("email")) {
    return "contains_email";
  }

  if (/\b\d{3,}\b/.test(normalized)) {
    return "contains_identifier";
  }

  return "generic";
}

const defaultAdapter = {
  track(eventName, payload) {
    if (typeof window === "undefined" || typeof window.dispatchEvent !== "function") {
      return;
    }

    window.dispatchEvent(new CustomEvent("fb:command-menu:event", {
      detail: {
        eventName,
        payload,
      },
    }));
  },
};

let telemetryAdapter = defaultAdapter;

export function setCommandMenuTelemetryAdapter(adapter) {
  if (!adapter || typeof adapter.track !== "function") {
    telemetryAdapter = defaultAdapter;
    return;
  }

  telemetryAdapter = adapter;
}

export function getCommandMenuTelemetryAdapter() {
  return telemetryAdapter;
}

export function buildQueryTelemetry(query) {
  const normalized = redactQuery(query);

  return {
    queryLength: String(query || "").trim().length,
    normalizedQuery: normalized,
    queryClass: classifyQuery(normalized),
  };
}

export function trackCommandMenuEvent(eventName, payload = {}) {
  try {
    telemetryAdapter.track(String(eventName || "unknown"), payload);
  } catch (_error) {
    // Telemetry should never break UX.
  }
}
