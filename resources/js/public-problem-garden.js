const ROOT_SELECTOR = "[data-problem-garden]";
const POP_DURATION = 620;

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

function mountRoot(root) {
  if (root.dataset.problemGardenMounted === "true") {
    return;
  }

  root.dataset.problemGardenMounted = "true";

  const shell = root.closest(".fb-public-shell") || document;
  const heroActions = Array.from(shell.querySelectorAll("a.fb-btn, [data-public-tab-trigger]"));

  heroActions.forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();

      popProblems(root).then(() => continueAction(link));
    }, true);
  });
}

export function mountPublicProblemGardenNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicProblemGardenNow();
