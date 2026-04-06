import "./dashboard-widgets";
import "./widget-dnd";
import "./gantt-scroll";
import "./sidebar-dnd";
import "./shopify/command-menu";
import "./bootstrap";
import "./public-premium-motion";

if (document.getElementById("shopify-messaging-root")) {
  import("./shopify/messaging");
}

if (document.getElementById("shopify-responses-root")) {
  import("./shopify/responses");
}

console.log('✅ app.js loaded');
