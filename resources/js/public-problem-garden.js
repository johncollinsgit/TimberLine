const ROOT_SELECTOR = "[data-problem-garden]";
const POP_DURATION = 620;
const POINTER_RADIUS = 220;
const MAX_OFFSET = 34;
const SPRING_FORCE = 0.035;
const DAMPING = 0.9;
const VELOCITY_SCALE = 0.024;
const POINTER_MEDIA_QUERY = "(hover: hover) and (pointer: fine)";
const REDUCED_MOTION_QUERY = "(prefers-reduced-motion: reduce)";

function assignPopVector(chip, index, total) {
  const angle = (Math.PI * 2 * index) / Math.max(1, total);
  const distance = 72 + (index % 4) * 22;
  const x = Math.cos(angle) * distance;
  const y = Math.sin(angle) * distance - 34;
  const rotation = index % 2 === 0 ? 22 : -24;

  chip.style.setProperty("--pop-x", `${x.toFixed(1)}px`);
  chip.style.setProperty("--pop-y", `${y.toFixed(1)}px`);
  chip.style.setProperty("--pop-r", `${rotation}deg`);
  chip.style.setProperty("--pop-delay", `${Math.min(index * 18, 180)}ms`);
}

function popProblems(root) {
  if (root.classList.contains("is-popping")) {
    return Promise.resolve();
  }

  const chips = Array.from(root.querySelectorAll(".fb-problem-chip"));
  chips.forEach((chip, index) => assignPopVector(chip, index, chips.length));
  root.classList.add("is-popping");

  return new Promise((resolve) => {
    window.setTimeout(resolve, POP_DURATION);
  });
}

function popSingleProblem(root, chip, index, total) {
  if (root.classList.contains("is-popping") || chip.classList.contains("is-popped")) {
    return;
  }

  assignPopVector(chip, index, total);
  chip.classList.add("is-popped");
  chip.disabled = true;
  chip.setAttribute("aria-hidden", "true");
}

function continueAction(link) {
  const tabKey = link.dataset.publicTabJump || link.dataset.publicTabTrigger;
  if (tabKey) {
    document.dispatchEvent(new CustomEvent("everbranch:activate-public-tab", {
      detail: { key: tabKey, scroll: true },
    }));
    return;
  }

  const href = link.getAttribute("href");
  if (href && href !== "#") {
    window.location.href = href;
  }
}

function setCursorOffset(chip, offsetX, offsetY) {
  chip.style.setProperty("--cursor-x", `${offsetX.toFixed(2)}px`);
  chip.style.setProperty("--cursor-y", `${offsetY.toFixed(2)}px`);
  chip.style.setProperty("--cursor-r", `${(offsetX * 0.12).toFixed(2)}deg`);
}

function resetCursorOffset(chip) {
  setCursorOffset(chip, 0, 0);
}

function createPointerField(root, chips) {
  if (!window.matchMedia) {
    return null;
  }

  const pointerQuery = window.matchMedia(POINTER_MEDIA_QUERY);
  const reducedMotionQuery = window.matchMedia(REDUCED_MOTION_QUERY);

  const chipStates = chips.map((chip) => ({
    chip,
    baseX: 0,
    baseY: 0,
    offsetX: 0,
    offsetY: 0,
    velocityX: 0,
    velocityY: 0,
  }));

  const pointer = {
    active: false,
    x: 0,
    y: 0,
    velocityX: 0,
    velocityY: 0,
  };

  let animationFrame = 0;
  let fieldActive = false;
  let geometryDirty = true;

  const isEnabled = () => pointerQuery.matches && !reducedMotionQuery.matches;

  const clamp = (value, max) => Math.max(-max, Math.min(max, value));

  const updateGeometry = () => {
    const rootRect = root.getBoundingClientRect();

    chipStates.forEach((state) => {
      const chipRect = state.chip.getBoundingClientRect();
      state.baseX = (chipRect.left - rootRect.left) + (chipRect.width / 2);
      state.baseY = (chipRect.top - rootRect.top) + (chipRect.height / 2);
    });

    geometryDirty = false;
  };

  const stopField = () => {
    fieldActive = false;
    pointer.active = false;

    if (animationFrame) {
      window.cancelAnimationFrame(animationFrame);
      animationFrame = 0;
    }

    chipStates.forEach((state) => {
      state.offsetX = 0;
      state.offsetY = 0;
      state.velocityX = 0;
      state.velocityY = 0;
      resetCursorOffset(state.chip);
    });
  };

  const tick = () => {
    if (!isEnabled()) {
      stopField();
      return;
    }

    if (geometryDirty) {
      updateGeometry();
    }

    let shouldContinue = pointer.active;

    chipStates.forEach((state) => {
      if (state.chip.classList.contains("is-popped")) {
        resetCursorOffset(state.chip);
        return;
      }

      const centerX = state.baseX + state.offsetX;
      const centerY = state.baseY + state.offsetY;

      if (pointer.active) {
        const deltaX = centerX - pointer.x;
        const deltaY = centerY - pointer.y;
        const distance = Math.hypot(deltaX, deltaY) || 1;

        if (distance < POINTER_RADIUS) {
          const influence = 1 - (distance / POINTER_RADIUS);
          const pointerSpeed = Math.min(Math.hypot(pointer.velocityX, pointer.velocityY), 48);
          const force = (0.9 + (pointerSpeed * VELOCITY_SCALE)) * influence * influence;
          state.velocityX += (deltaX / distance) * force;
          state.velocityY += (deltaY / distance) * force;
        }
      }

      state.velocityX += -state.offsetX * SPRING_FORCE;
      state.velocityY += -state.offsetY * SPRING_FORCE;
      state.velocityX *= DAMPING;
      state.velocityY *= DAMPING;

      state.offsetX = clamp(state.offsetX + state.velocityX, MAX_OFFSET);
      state.offsetY = clamp(state.offsetY + state.velocityY, MAX_OFFSET);

      if (
        Math.abs(state.offsetX) > 0.08
        || Math.abs(state.offsetY) > 0.08
        || Math.abs(state.velocityX) > 0.08
        || Math.abs(state.velocityY) > 0.08
      ) {
        shouldContinue = true;
      }

      setCursorOffset(state.chip, state.offsetX, state.offsetY);
    });

    if (!shouldContinue) {
      fieldActive = false;
      animationFrame = 0;
      return;
    }

    animationFrame = window.requestAnimationFrame(tick);
  };

  const startField = () => {
    if (!isEnabled() || fieldActive) {
      return;
    }

    fieldActive = true;
    animationFrame = window.requestAnimationFrame(tick);
  };

  const handlePointerMove = (event) => {
    if (!isEnabled()) {
      return;
    }

    if (geometryDirty) {
      updateGeometry();
    }

    const rootRect = root.getBoundingClientRect();
    const nextX = event.clientX - rootRect.left;
    const nextY = event.clientY - rootRect.top;

    pointer.velocityX = nextX - pointer.x;
    pointer.velocityY = nextY - pointer.y;
    pointer.x = nextX;
    pointer.y = nextY;
    pointer.active = true;

    startField();
  };

  const handlePointerLeave = () => {
    pointer.active = false;
    pointer.velocityX = 0;
    pointer.velocityY = 0;
    startField();
  };

  root.addEventListener("pointerenter", () => {
    if (isEnabled()) {
      geometryDirty = true;
    }
  });
  root.addEventListener("pointermove", handlePointerMove);
  root.addEventListener("pointerleave", handlePointerLeave);
  window.addEventListener("resize", () => {
    geometryDirty = true;
  });
  window.addEventListener("scroll", () => {
    geometryDirty = true;
  }, { passive: true });
  pointerQuery.addEventListener("change", () => {
    geometryDirty = true;
    if (!isEnabled()) {
      stopField();
    }
  });
  reducedMotionQuery.addEventListener("change", () => {
    geometryDirty = true;
    if (!isEnabled()) {
      stopField();
    }
  });

  if (!isEnabled()) {
    stopField();
  }

  return {
    refresh() {
      geometryDirty = true;
    },
  };
}

function mountRoot(root) {
  if (root.dataset.problemGardenMounted === "true") {
    return;
  }

  root.dataset.problemGardenMounted = "true";

  const shell = root.closest(".fb-public-shell") || document;
  const heroActions = Array.from(shell.querySelectorAll("a.fb-btn, [data-public-tab-trigger]"));
  const chips = Array.from(root.querySelectorAll(".fb-problem-chip"));
  const pointerField = createPointerField(root, chips);

  chips.forEach((chip, index) => {
    chip.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();

      popSingleProblem(root, chip, index, chips.length);
    });
  });

  heroActions.forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();

      pointerField?.refresh();
      document.dispatchEvent(new CustomEvent("everbranch:public-hero-action", {
        detail: { href: link.getAttribute("href"), tab: link.dataset.publicTabJump || link.dataset.publicTabTrigger || null },
      }));

      popProblems(root).then(() => continueAction(link));
    }, true);
  });
}

export function mountPublicProblemGardenNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicProblemGardenNow();
