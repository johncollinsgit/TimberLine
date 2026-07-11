function activatePhoneTab(root, key) {
  const tabs = Array.from(root.querySelectorAll("[data-phone-tab]"));
  const panels = Array.from(root.querySelectorAll("[data-phone-panel]"));
  const selectedTab = tabs.find((tab) => tab.dataset.phoneTab === key) || tabs[0];
  const selectedKey = selectedTab?.dataset.phoneTab;

  if (!selectedKey) return;

  root.dataset.activePhoneTab = selectedKey;

  tabs.forEach((tab) => {
    const active = tab.dataset.phoneTab === selectedKey;
    tab.classList.toggle("is-active", active);
    tab.setAttribute("aria-selected", active ? "true" : "false");
    tab.tabIndex = active ? 0 : -1;
  });

  panels.forEach((panel) => {
    const active = panel.dataset.phonePanel === selectedKey;
    panel.classList.toggle("is-active", active);
    panel.hidden = !active;

    if (active) {
      panel.classList.remove("is-animating");
      window.requestAnimationFrame(() => {
        panel.classList.add("is-animating");
      });
    }
  });
}

function nextTabIndex(currentIndex, key, tabCount) {
  if (key === "ArrowLeft") return currentIndex === 0 ? tabCount - 1 : currentIndex - 1;
  if (key === "ArrowRight") return currentIndex === tabCount - 1 ? 0 : currentIndex + 1;
  if (key === "Home") return 0;
  if (key === "End") return tabCount - 1;

  return currentIndex;
}

export function mountPublicPhoneDemoNow() {
  document.querySelectorAll("[data-public-phone-demo]").forEach((root) => {
    if (root.__mfPublicPhoneDemoMounted) return;
    root.__mfPublicPhoneDemoMounted = true;

    const tabs = Array.from(root.querySelectorAll("[data-phone-tab]"));

    tabs.forEach((tab, index) => {
      tab.addEventListener("click", () => {
        activatePhoneTab(root, tab.dataset.phoneTab);
      });

      tab.addEventListener("keydown", (event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;

        event.preventDefault();
        const nextIndex = nextTabIndex(index, event.key, tabs.length);
        const nextTab = tabs[nextIndex];

        nextTab?.focus();
        activatePhoneTab(root, nextTab?.dataset.phoneTab);
      });
    });

    activatePhoneTab(root, root.dataset.activePhoneTab || tabs[0]?.dataset.phoneTab);
  });
}
