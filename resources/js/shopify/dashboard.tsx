import { createRoot } from "react-dom/client";
import { DashboardApp } from "./dashboard/DashboardApp";
import type { DashboardBootstrap } from "./dashboard/types";

function readBootstrap(): DashboardBootstrap {
  const element = document.getElementById("shopify-dashboard-bootstrap");

  if (!element?.textContent) {
    return {
      authorized: false,
      status: "invalid_request",
      storeLabel: "Shopify Admin",
      links: [],
    };
  }

  try {
    return JSON.parse(element.textContent) as DashboardBootstrap;
  } catch (error) {
    console.error("Failed to parse dashboard bootstrap payload", error);

    return {
      authorized: false,
      status: "invalid_request",
      storeLabel: "Shopify Admin",
      links: [],
    };
  }
}

const rootElement = document.getElementById("shopify-dashboard-root");

if (rootElement) {
  createRoot(rootElement).render(<DashboardApp bootstrap={readBootstrap()} />);
}
