const ROOT_SELECTOR = "[data-public-mobile-nav]";
const MOBILE_QUERY = "(max-width: 760px)";
const CLOSE_DELAY = 180;

function setActiveLinkState(root, activeKey) {
  const links = Array.from(root.querySelectorAll("[data-public-mobile-nav-link][data-public-tab-trigger]"));

  links.forEach((link) => {
    const isActive = link.dataset.publicTabTrigger === activeKey;
    link.classList.toggle("is-active", isActive);

    if (isActive) {
      link.setAttribute("aria-current", "page");
      return;
    }

    link.removeAttribute("aria-current");
  });
}

function mountRoot(root) {
  if (root.dataset.publicMobileNavMounted === "true") {
    return;
  }

  const toggle = root.querySelector("[data-public-mobile-nav-toggle]");
  const drawer = root.querySelector("[data-public-mobile-nav-drawer]");
  const backdrop = root.querySelector("[data-public-mobile-nav-backdrop]");

  if (!toggle || !drawer || !backdrop) {
    return;
  }

  root.dataset.publicMobileNavMounted = "true";

  const mobileQuery = window.matchMedia(MOBILE_QUERY);
  let closeTimer = null;
  let isOpen = false;

  const clearCloseTimer = () => {
    if (closeTimer !== null) {
      window.clearTimeout(closeTimer);
      closeTimer = null;
    }
  };

  const syncHiddenState = (hidden) => {
    drawer.hidden = hidden;
    backdrop.hidden = hidden;
  };

  const closeDrawer = ({ returnFocus = false } = {}) => {
    clearCloseTimer();
    isOpen = false;
    toggle.setAttribute("aria-expanded", "false");
    toggle.setAttribute("aria-label", "Open navigation menu");
    drawer.classList.remove("is-open");
    backdrop.classList.remove("is-open");
    document.body.classList.remove("is-public-nav-open");

    closeTimer = window.setTimeout(() => {
      if (!isOpen) {
        syncHiddenState(true);
      }
    }, CLOSE_DELAY);

    if (returnFocus) {
      toggle.focus();
    }
  };

  const openDrawer = () => {
    clearCloseTimer();
    syncHiddenState(false);
    isOpen = true;
    toggle.setAttribute("aria-expanded", "true");
    toggle.setAttribute("aria-label", "Close navigation menu");
    document.body.classList.add("is-public-nav-open");

    window.requestAnimationFrame(() => {
      drawer.classList.add("is-open");
      backdrop.classList.add("is-open");
    });
  };

  const handleEscape = (event) => {
    if (event.key === "Escape" && isOpen) {
      closeDrawer({ returnFocus: true });
    }
  };

  toggle.addEventListener("click", (event) => {
    event.preventDefault();

    if (isOpen) {
      closeDrawer({ returnFocus: false });
      return;
    }

    openDrawer();
  });

  backdrop.addEventListener("click", () => {
    closeDrawer({ returnFocus: false });
  });

  root.addEventListener("click", (event) => {
    const link = event.target.closest("[data-public-mobile-nav-link]");

    if (!link) {
      return;
    }

    closeDrawer({ returnFocus: false });
  }, true);

  document.addEventListener("keydown", handleEscape);

  document.addEventListener("everbranch:activate-public-tab", (event) => {
    const activeKey = event.detail?.key;

    if (typeof activeKey === "string" && activeKey.length > 0) {
      setActiveLinkState(root, activeKey);
    }

    if (isOpen) {
      closeDrawer({ returnFocus: false });
    }
  });

  document.addEventListener("everbranch:public-hero-action", () => {
    if (isOpen) {
      closeDrawer({ returnFocus: false });
    }
  });

  mobileQuery.addEventListener("change", (event) => {
    if (!event.matches) {
      closeDrawer({ returnFocus: false });
      syncHiddenState(true);
    }
  });

  const initialActiveKey = root.querySelector(".fb-site-links [data-public-tab-trigger].is-active")?.dataset.publicTabTrigger ?? "product";
  setActiveLinkState(root, initialActiveKey);
  syncHiddenState(true);
}

export function mountPublicMobileNavNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicMobileNavNow();
