const ROOT_SELECTOR = "[data-problem-garden]";
const POP_DURATION = 620;
const POINTER_RADIUS = 220;
const MAX_REPEL_OFFSET = 42;
const REPEL_FORCE = 0.12;
const POINTER_VELOCITY_FORCE = 0.02;
const SPRING_FORCE = 0.072;
const DAMPING = 0.84;
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

function parseLength(value) {
  const trimmed = `${value ?? ""}`.trim();

  if (trimmed === "") {
    return 0;
  }

  if (trimmed.endsWith("rem")) {
    const rootFontSize = Number.parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
    return (Number.parseFloat(trimmed) || 0) * rootFontSize;
  }

  return Number.parseFloat(trimmed) || 0;
}

function parseAngle(value) {
  return Number.parseFloat(`${value ?? ""}`.trim()) || 0;
}

function clamp(value, maxMagnitude) {
  return Math.max(-maxMagnitude, Math.min(maxMagnitude, value));
}

function setBubbleTransform(state, translateX, translateY, rotation) {
  state.translateX = translateX;
  state.translateY = translateY;
  state.rotation = rotation;

  state.chip.style.setProperty("--bubble-tx", `${translateX.toFixed(2)}px`);
  state.chip.style.setProperty("--bubble-ty", `${translateY.toFixed(2)}px`);
  state.chip.style.setProperty("--bubble-rot", `${rotation.toFixed(2)}deg`);
}

function resetBubbleTransform(state) {
  setBubbleTransform(state, 0, 0, state.baseRotation);
}

function createBubbleEngine(root, chips) {
  if (!window.matchMedia) {
    return null;
  }

  const pointerQuery = window.matchMedia(POINTER_MEDIA_QUERY);
  const reducedMotionQuery = window.matchMedia(REDUCED_MOTION_QUERY);
  const interactionZone = root.closest(".fb-splash") || root.parentElement || root;
  const chipStates = chips.map((chip, index) => {
    const style = getComputedStyle(chip);
    const idleAmplitudeX = parseLength(style.getPropertyValue("--float-x")) || (18 + (index % 4) * 4);
    const idleAmplitudeY = parseLength(style.getPropertyValue("--float-y")) || (14 + (index % 3) * 3);
    const idleRotationAmplitude = parseAngle(style.getPropertyValue("--float-r")) || (4 + (index % 4));
    const baseRotation = parseAngle(style.getPropertyValue("--chip-rotate"));

    return {
      chip,
      index,
      centerX: 0,
      centerY: 0,
      width: 0,
      height: 0,
      baseRotation,
      idleAmplitudeX,
      idleAmplitudeY,
      idleRotationAmplitude,
      idlePhase: (index + 1) * 0.92,
      idleSpeed: 0.52 + ((index % 5) * 0.09),
      repelX: 0,
      repelY: 0,
      velocityX: 0,
      velocityY: 0,
      translateX: 0,
      translateY: 0,
      rotation: baseRotation,
    };
  });

  const pointer = {
    active: false,
    zoneX: 0,
    zoneY: 0,
    velocityX: 0,
    velocityY: 0,
  };

  let animationFrame = 0;
  let isRunning = false;
  let geometryDirty = true;
  let isVisible = true;
  let zoneLeft = 0;
  let zoneTop = 0;

  const isEnabled = () => pointerQuery.matches && !reducedMotionQuery.matches;

  const updateGeometry = () => {
    const zoneRect = interactionZone.getBoundingClientRect();
    zoneLeft = zoneRect.left;
    zoneTop = zoneRect.top;

    chipStates.forEach((state) => {
      state.centerX = state.chip.offsetLeft + (state.chip.offsetWidth / 2);
      state.centerY = state.chip.offsetTop + (state.chip.offsetHeight / 2);
      state.width = state.chip.offsetWidth;
      state.height = state.chip.offsetHeight;
    });

    geometryDirty = false;
  };

  const stop = ({ reset = false } = {}) => {
    isRunning = false;

    if (animationFrame) {
      window.cancelAnimationFrame(animationFrame);
      animationFrame = 0;
    }

    pointer.active = false;
    pointer.velocityX = 0;
    pointer.velocityY = 0;

    if (reset) {
      chipStates.forEach((state) => {
        if (state.chip.classList.contains("is-popped")) {
          return;
        }

        state.repelX = 0;
        state.repelY = 0;
        state.velocityX = 0;
        state.velocityY = 0;
        resetBubbleTransform(state);
      });
    }
  };

  const tick = (now) => {
    if (!isEnabled() || !isVisible) {
      stop({ reset: true });
      return;
    }

    if (geometryDirty) {
      updateGeometry();
    }

    const time = now / 1000;
    let hasRenderableChip = false;

    chipStates.forEach((state) => {
      if (state.chip.classList.contains("is-popped")) {
        return;
      }

      hasRenderableChip = true;

      const idleX = Math.sin((time * state.idleSpeed) + state.idlePhase) * state.idleAmplitudeX;
      const idleY = Math.cos((time * (state.idleSpeed * 0.88)) + (state.idlePhase * 1.17)) * state.idleAmplitudeY;
      const idleRotation = state.baseRotation + (Math.sin((time * (state.idleSpeed * 0.62)) + (state.idlePhase * 0.7)) * state.idleRotationAmplitude);

      if (pointer.active) {
        const bubbleX = state.centerX + state.repelX;
        const bubbleY = state.centerY + state.repelY;
        const deltaX = bubbleX - pointer.zoneX;
        const deltaY = bubbleY - pointer.zoneY;
        const distance = Math.hypot(deltaX, deltaY) || 1;

        if (distance < POINTER_RADIUS) {
          const falloff = 1 - (distance / POINTER_RADIUS);
          const pointerBoost = Math.min(Math.hypot(pointer.velocityX, pointer.velocityY), 42) * POINTER_VELOCITY_FORCE;
          const force = (REPEL_FORCE + pointerBoost) * falloff * falloff;

          state.velocityX += (deltaX / distance) * force * POINTER_RADIUS;
          state.velocityY += (deltaY / distance) * force * POINTER_RADIUS;
        }
      }

      state.velocityX += -state.repelX * SPRING_FORCE;
      state.velocityY += -state.repelY * SPRING_FORCE;
      state.velocityX *= DAMPING;
      state.velocityY *= DAMPING;

      state.repelX = clamp(state.repelX + state.velocityX, MAX_REPEL_OFFSET);
      state.repelY = clamp(state.repelY + state.velocityY, MAX_REPEL_OFFSET);

      const translateX = idleX + state.repelX;
      const translateY = idleY + state.repelY;
      const rotation = idleRotation + (state.repelX * 0.05);

      setBubbleTransform(state, translateX, translateY, rotation);
    });

    if (!hasRenderableChip) {
      stop();
      return;
    }

    animationFrame = window.requestAnimationFrame(tick);
  };

  const start = () => {
    if (!isEnabled() || !isVisible || isRunning) {
      return;
    }

    if (geometryDirty) {
      updateGeometry();
    }

    isRunning = true;
    animationFrame = window.requestAnimationFrame(tick);
  };

  const handlePointerMove = (event) => {
    if (!isEnabled()) {
      return;
    }

    if (geometryDirty) {
      updateGeometry();
    }

    const nextZoneX = event.clientX - zoneLeft;
    const nextZoneY = event.clientY - zoneTop;

    pointer.velocityX = nextZoneX - pointer.zoneX;
    pointer.velocityY = nextZoneY - pointer.zoneY;
    pointer.zoneX = nextZoneX;
    pointer.zoneY = nextZoneY;
    pointer.active = true;

    start();
  };

  const handlePointerLeave = () => {
    pointer.active = false;
    pointer.velocityX = 0;
    pointer.velocityY = 0;
    start();
  };

  const visibilityObserver = typeof IntersectionObserver === "function"
    ? new IntersectionObserver(([entry]) => {
      isVisible = Boolean(entry?.isIntersecting);

      if (isVisible) {
        geometryDirty = true;
        start();
        return;
      }

      stop();
    }, {
      threshold: 0.01,
    })
    : null;

  interactionZone.addEventListener("pointerenter", () => {
    if (!isEnabled()) {
      return;
    }

    geometryDirty = true;
    start();
  });
  interactionZone.addEventListener("pointermove", handlePointerMove);
  interactionZone.addEventListener("pointerleave", handlePointerLeave);

  window.addEventListener("resize", () => {
    geometryDirty = true;
    start();
  });
  window.addEventListener("scroll", () => {
    geometryDirty = true;
  }, { passive: true });
  document.addEventListener("visibilitychange", () => {
    isVisible = document.visibilityState === "visible";

    if (isVisible) {
      geometryDirty = true;
      start();
      return;
    }

    stop();
  });

  pointerQuery.addEventListener("change", () => {
    geometryDirty = true;

    if (isEnabled()) {
      start();
      return;
    }

    stop({ reset: true });
  });

  reducedMotionQuery.addEventListener("change", () => {
    geometryDirty = true;

    if (isEnabled()) {
      start();
      return;
    }

    stop({ reset: true });
  });

  visibilityObserver?.observe(interactionZone);

  if (isEnabled()) {
    updateGeometry();
    start();
  } else {
    stop({ reset: true });
  }

  return {
    freezeChip(chip) {
      const state = chipStates.find((entry) => entry.chip === chip);

      if (!state) {
        return;
      }

      setBubbleTransform(state, state.translateX, state.translateY, state.rotation);
      state.velocityX = 0;
      state.velocityY = 0;
    },
    freezeAll() {
      chipStates.forEach((state) => {
        if (state.chip.classList.contains("is-popped")) {
          return;
        }

        setBubbleTransform(state, state.translateX, state.translateY, state.rotation);
      });
      stop();
    },
    refresh() {
      geometryDirty = true;
      start();
    },
  };
}

function popProblems(root, engine) {
  if (root.classList.contains("is-popping")) {
    return Promise.resolve();
  }

  const chips = Array.from(root.querySelectorAll(".fb-problem-chip"));
  chips.forEach((chip, index) => assignPopVector(chip, index, chips.length));
  engine?.freezeAll();
  root.classList.add("is-popping");

  return new Promise((resolve) => {
    window.setTimeout(resolve, POP_DURATION);
  });
}

function popSingleProblem(root, chip, index, total, engine) {
  if (root.classList.contains("is-popping") || chip.classList.contains("is-popped")) {
    return;
  }

  assignPopVector(chip, index, total);
  engine?.freezeChip(chip);
  chip.classList.add("is-popped");
  chip.disabled = true;
  chip.setAttribute("aria-hidden", "true");
}

function mountRoot(root) {
  if (root.dataset.problemGardenMounted === "true") {
    return;
  }

  root.dataset.problemGardenMounted = "true";

  const shell = root.closest(".fb-public-shell") || document;
  const heroActions = Array.from(shell.querySelectorAll("a.fb-btn, [data-public-tab-trigger]"));
  const chips = Array.from(root.querySelectorAll(".fb-problem-chip"));
  const bubbleEngine = createBubbleEngine(root, chips);

  chips.forEach((chip, index) => {
    chip.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();

      popSingleProblem(root, chip, index, chips.length, bubbleEngine);
    });
  });

  heroActions.forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();

      bubbleEngine?.refresh();
      document.dispatchEvent(new CustomEvent("everbranch:public-hero-action", {
        detail: { href: link.getAttribute("href"), tab: link.dataset.publicTabJump || link.dataset.publicTabTrigger || null },
      }));

      popProblems(root, bubbleEngine).then(() => continueAction(link));
    }, true);
  });
}

export function mountPublicProblemGardenNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicProblemGardenNow();
