import React from "react";
import { createRoot } from "react-dom/client";
import { GlobalCommandMenu } from "./search/GlobalCommandMenu.jsx";
import {
  registerDefaultShopifyActions,
  registerRouteDiscoveryDocuments,
} from "./search/defaultShopifySearchActions.js";
import { createCommandMenuMountManager } from "./search/commandMenuMountManager.js";

const commandMenuManager = createCommandMenuMountManager({
  registerDefaultActions: registerDefaultShopifyActions,
  registerRouteDiscoveryActions: registerRouteDiscoveryDocuments,
  renderMenu(root, { placeholder, contextLabel, baseQuery }) {
    const reactRoot = createRoot(root);
    reactRoot.render(
      <React.StrictMode>
        <GlobalCommandMenu
          placeholder={placeholder}
          contextLabel={contextLabel}
          baseQuery={baseQuery}
        />
      </React.StrictMode>
    );

    return () => {
      reactRoot.unmount();
    };
  },
});

function initCommandMenus() {
  commandMenuManager.init();
}

initCommandMenus();
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initCommandMenus, { once: true });
}
document.addEventListener("livewire:navigated", initCommandMenus);
commandMenuManager.bindFallbackSubmit();
