import Sortable from "sortablejs";

async function saveSidebarOrder(list) {
  const order = Array.from(list.querySelectorAll("[data-sidebar-item]"))
    .map((item) => item.dataset.sidebarKey)
    .filter(Boolean);

  const url = list.dataset.sidebarSaveUrl;
  const token = list.dataset.sidebarCsrf;
  if (!url || !token || order.length === 0) {
    return;
  }

  try {
    const response = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": token,
      },
      body: JSON.stringify({ sidebar_order: order }),
    });

    if (!response.ok) {
      throw new Error(`Sidebar order save failed with status ${response.status}`);
    }
  } catch (error) {
    // Keep this non-blocking; a failed save should not break navigation.
    console.error(error);
  }
}

function mountSidebarSortable() {
  document.querySelectorAll("[data-sidebar-sortable]").forEach((list) => {
    if (list.__sortableInstance) {
      return;
    }

    list.__sortableInstance = Sortable.create(list, {
      animation: 150,
      draggable: "[data-sidebar-item]",
      ghostClass: "mf-sidebar-ghost",
      dragClass: "mf-sidebar-drag",
      onEnd: () => saveSidebarOrder(list),
    });
  });
}

document.addEventListener("DOMContentLoaded", mountSidebarSortable);
document.addEventListener("livewire:navigated", mountSidebarSortable);
document.addEventListener("livewire:load", mountSidebarSortable);
if (window.Livewire?.hook) {
  window.Livewire.hook("message.processed", mountSidebarSortable);
}
