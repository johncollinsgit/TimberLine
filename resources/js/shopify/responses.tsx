import { createRoot } from "react-dom/client";
import { ResponsesApp, type ResponsesBootstrap } from "./responses/ResponsesApp";
import "@shopify/polaris/build/esm/styles.css";

function fallbackBootstrap(): ResponsesBootstrap {
  return {
    authorized: false,
    tenant_id: null,
    status: "invalid_request",
    module_access: false,
    endpoints: {},
  };
}

function readBootstrap(): ResponsesBootstrap {
  const node = document.getElementById("shopify-responses-bootstrap");
  if (!node?.textContent) {
    return fallbackBootstrap();
  }

  try {
    return JSON.parse(node.textContent) as ResponsesBootstrap;
  } catch (error) {
    console.error("Failed to parse responses bootstrap payload", error);

    return fallbackBootstrap();
  }
}

const rootElement = document.getElementById("shopify-responses-root");

if (rootElement) {
  createRoot(rootElement).render(<ResponsesApp bootstrap={readBootstrap()} />);
}
