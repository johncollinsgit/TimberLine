const mounted = new WeakSet();

function mount(root) {
  if (mounted.has(root)) return;
  mounted.add(root);

  const form = root.querySelector("[data-autosave-form]");
  const status = root.querySelector("[data-autosave-status]");
  if (!form || !status) return;

  let timer = null;
  let controller = null;

  const sample = {
    task_name: "Prepare launch order", source: "Shopify", order_number: "1042", customer_name: "Jamie Lee",
  };
  const renderPreview = () => {
    const input = root.querySelector("[data-event-title-template]");
    const title = root.querySelector("[data-preview-title]");
    if (input && title) {
      title.textContent = String(input.value || "Everbranch event").replace(/\{\{([a-z0-9_]+)\}\}/gi, (_, key) => sample[key] || "").replace(/\s+/g, " ").trim() || "Everbranch event";
    }
    const color = root.querySelector("[data-event-color]");
    const marker = root.querySelector("[data-preview-color]");
    if (color && marker) marker.style.background = color.selectedOptions[0]?.dataset?.color || "#0b8043";
    const location = root.querySelector("[data-event-location-source]");
    const locationPreview = root.querySelector("[data-preview-location]");
    if (location && locationPreview) {
      const locations = { shipping_address: "128 Evergreen Way, Asheville, NC 28801", billing_address: "128 Evergreen Way, Asheville, NC 28801", pickup_location: "Downtown shop", none: "" };
      const value = locations[location.value] || "";
      locationPreview.textContent = value ? `⌖ ${value}` : "";
      locationPreview.classList.toggle("hidden", !value);
    }
  };

  const save = async () => {
    const data = new FormData(form);
    const triggerReady = String(data.get("project_gid") || data.get("trigger_connection_id") || "").trim();
    if (!triggerReady || !["name", "calendar_id", "timezone", "default_duration_minutes", "event_title_template"].every((key) => String(data.get(key) || "").trim())) {
      status.textContent = "Complete setup to autosave";
      return;
    }

    controller?.abort();
    controller = new AbortController();
    status.textContent = "Saving…";
    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: data,
        credentials: "same-origin",
        headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" },
        signal: controller.signal,
      });
      if (!response.ok) throw new Error("Autosave failed");
      status.textContent = "Draft saved";
      root.querySelectorAll("[data-test-passed]").forEach((node) => node.remove());
    } catch (error) {
      if (error?.name !== "AbortError") status.textContent = "Save needs attention";
    }
  };

  form.addEventListener("input", () => {
    renderPreview();
    status.textContent = "Unsaved changes";
    clearTimeout(timer);
    timer = setTimeout(save, 900);
  });
  form.addEventListener("change", () => {
    renderPreview();
    status.textContent = "Unsaved changes";
    clearTimeout(timer);
    timer = setTimeout(save, 450);
  });
  renderPreview();
}

export function mountWorkflowEditorNow() {
  document.querySelectorAll("[data-workflow-editor-root]").forEach(mount);
}

mountWorkflowEditorNow();
