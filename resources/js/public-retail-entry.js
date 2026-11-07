import "./public-premium-motion";
import { mountPublicRetailNow } from "./public-retail";

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mountPublicRetailNow, { once: true });
} else {
    mountPublicRetailNow();
}
