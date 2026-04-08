function snapToDate(el, targetDate) {
  const day = el.querySelector(`[data-gantt-day][data-date="${targetDate}"]`);
  if (!day) return;
  const offset = day.offsetLeft - 200;
  el.scrollLeft = Math.max(0, offset);
}

function initScrollBehavior(el) {
  let isDown = false;
  let startX = 0;
  let scrollLeft = 0;
  let velocity = 0;
  let lastX = 0;
  let lastTime = 0;
  let raf = null;

  const stopMomentum = () => {
    if (raf) cancelAnimationFrame(raf);
    raf = null;
  };

  const step = () => {
    if (Math.abs(velocity) < 0.1) {
      velocity = 0;
      return;
    }
    el.scrollLeft -= velocity;
    velocity *= 0.95;
    raf = requestAnimationFrame(step);
  };

  el.addEventListener("pointerdown", (e) => {
    if (e.button !== 0) return;
    isDown = true;
    el.setPointerCapture(e.pointerId);
    startX = e.pageX - el.offsetLeft;
    scrollLeft = el.scrollLeft;
    lastX = e.pageX;
    lastTime = performance.now();
    velocity = 0;
    stopMomentum();
    el.classList.add("cursor-grabbing");
  });

  el.addEventListener("pointerleave", () => {
    if (!isDown) return;
    isDown = false;
    el.classList.remove("cursor-grabbing");
  });

  el.addEventListener("pointerup", () => {
    if (!isDown) return;
    isDown = false;
    el.classList.remove("cursor-grabbing");
    stopMomentum();
    if (Math.abs(velocity) > 0.2) {
      raf = requestAnimationFrame(step);
    }
  });

  el.addEventListener("pointermove", (e) => {
    if (!isDown) return;
    e.preventDefault();
    const x = e.pageX - el.offsetLeft;
    const walk = (x - startX) * 1.2;
    el.scrollLeft = scrollLeft - walk;
    const now = performance.now();
    const dx = e.pageX - lastX;
    const dt = now - lastTime || 16;
    velocity = dx / dt * 16;
    lastX = e.pageX;
    lastTime = now;
  });

  el.addEventListener(
    "wheel",
    (evt) => {
      if (el.scrollWidth <= el.clientWidth) {
        return;
      }
      if (Math.abs(evt.deltaX) > Math.abs(evt.deltaY)) {
        evt.preventDefault();
        el.scrollLeft += evt.deltaX;
      }
      // allow vertical scroll to bubble to the page
    },
    { passive: false }
  );

  el.addEventListener("dragstart", (e) => e.preventDefault());
  el.style.scrollBehavior = "auto";
  el.style.userSelect = "none";
  el.style.webkitUserSelect = "none";
}

function initGanttScroll() {
  const scrollers = document.querySelectorAll("[data-gantt-scroll]");
  scrollers.forEach((el) => {
    if (!el.__ganttScrollBound) {
      el.__ganttScrollBound = true;
      initScrollBehavior(el);
    }

    if (el.dataset.ganttSnap === "today" && !el.dataset.ganttSnapped) {
      const today = new Date();
      const yyyy = today.getFullYear();
      const mm = String(today.getMonth() + 1).padStart(2, "0");
      const dd = String(today.getDate()).padStart(2, "0");
      snapToDate(el, `${yyyy}-${mm}-${dd}`);
      el.dataset.ganttSnapped = "1";
    }
  });
}

export function mountGanttScrollNow() {
  initGanttScroll();
}

document.addEventListener("DOMContentLoaded", mountGanttScrollNow);
document.addEventListener("livewire:navigated", mountGanttScrollNow);
document.addEventListener("livewire:load", mountGanttScrollNow);
if (window.Livewire?.hook) {
  window.Livewire.hook("message.processed", mountGanttScrollNow);
}
mountGanttScrollNow();
