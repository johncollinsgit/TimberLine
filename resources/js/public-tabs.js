function activateTab(root, key, updateHash = true) {
  const scope = root.closest(".fb-public-shell") || document;
  const triggers = Array.from(scope.querySelectorAll("[data-public-tab-trigger]"));
  const panels = Array.from(root.querySelectorAll("[data-public-tab-panel]"));
  const selectedTrigger = triggers.find((trigger) => trigger.dataset.publicTabTrigger === key) || triggers[0];
  const selectedKey = selectedTrigger?.dataset.publicTabTrigger;

  if (!selectedKey) return;

  triggers.forEach((trigger) => {
    const active = trigger.dataset.publicTabTrigger === selectedKey;
    trigger.classList.toggle("is-active", active);
    trigger.setAttribute("aria-selected", active ? "true" : "false");
    trigger.tabIndex = active ? 0 : -1;
  });

  panels.forEach((panel) => {
    const active = panel.dataset.publicTabPanel === selectedKey;
    panel.classList.toggle("is-active", active);
    panel.hidden = !active;
  });

  if (updateHash && selectedTrigger.id) {
    window.history.replaceState(null, "", `#${selectedTrigger.id}`);
  }
}

function tabKeyFromHash(root) {
  const scope = root.closest(".fb-public-shell") || document;
  const id = window.location.hash.replace("#", "");
  if (!id) return null;

  return scope.querySelector(`#${CSS.escape(id)}[data-public-tab-trigger]`)?.dataset.publicTabTrigger || null;
}

export function mountPublicTabsNow() {
  document.querySelectorAll("[data-public-tabs]").forEach((root) => {
    if (root.__mfPublicTabsMounted) return;
    root.__mfPublicTabsMounted = true;

    const scope = root.closest(".fb-public-shell") || document;
    const triggers = Array.from(scope.querySelectorAll("[data-public-tab-trigger]"));
    const panelAnchor = document.getElementById("everbranch-public") || root;

    triggers.forEach((trigger, index) => {
      trigger.tabIndex = trigger.classList.contains("is-active") ? 0 : -1;
      trigger.addEventListener("click", (event) => {
        event.preventDefault();
        activateTab(root, trigger.dataset.publicTabTrigger);
        panelAnchor?.scrollIntoView({ behavior: "smooth", block: "start" });
      });
      trigger.addEventListener("keydown", (event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();

        let nextIndex = index;
        if (event.key === "ArrowLeft") nextIndex = index === 0 ? triggers.length - 1 : index - 1;
        if (event.key === "ArrowRight") nextIndex = index === triggers.length - 1 ? 0 : index + 1;
        if (event.key === "Home") nextIndex = 0;
        if (event.key === "End") nextIndex = triggers.length - 1;

        const nextTrigger = triggers[nextIndex];
        nextTrigger?.focus();
        activateTab(root, nextTrigger?.dataset.publicTabTrigger);
      });
    });

    const activeTrigger = triggers.find((trigger) => trigger.classList.contains("is-active"));
    activateTab(root, tabKeyFromHash(root) || activeTrigger?.dataset.publicTabTrigger || triggers[0]?.dataset.publicTabTrigger, false);
  });
}
