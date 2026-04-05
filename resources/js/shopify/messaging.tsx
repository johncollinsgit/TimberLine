import { createRoot } from "react-dom/client";
import { MessagingApp } from "./messaging/MessagingApp";
import type { MessagingBootstrap } from "./messaging/types";
import "@shopify/polaris/build/esm/styles.css";

function fallbackBootstrap(): MessagingBootstrap {
  return {
    authorized: false,
    tenant_id: null,
    status: "invalid_request",
    module_access: false,
    module_state: null,
    data: {
      groups: { saved: [], auto: [] },
      templates: [],
    },
    endpoints: {},
  };
}

function readBootstrap(): MessagingBootstrap {
  const node = document.getElementById("shopify-messaging-bootstrap");
  if (!node?.textContent) {
    return fallbackBootstrap();
  }

  try {
    return JSON.parse(node.textContent) as MessagingBootstrap;
  } catch (error) {
    console.error("Failed to parse messaging bootstrap payload", error);

    return fallbackBootstrap();
  }
}

const rootElement = document.getElementById("shopify-messaging-root");

if (rootElement) {
  createRoot(rootElement).render(<MessagingApp bootstrap={readBootstrap()} />);
}
