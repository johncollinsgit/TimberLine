const ROOT_SELECTOR = "[data-problem-garden]";

function mountRoot(root) {
  if (root.dataset.problemGardenMounted === "true") {
    return;
  }

  root.dataset.problemGardenMounted = "true";

  const chips = Array.from(root.querySelectorAll(".fb-problem-chip"));
  const tree = root.querySelector(".fb-problem-tree");
  const splash = root.closest(".fb-splash");

  const growTree = () => {
    root.classList.add("is-grown");
    chips.forEach((chip, index) => {
      window.setTimeout(() => chip.classList.add("is-planted"), index * 45);
    });
  };

  const resetGarden = () => {
    root.classList.remove("is-grown");
    chips.forEach((chip) => chip.classList.remove("is-planted"));
  };

  chips.forEach((chip) => chip.addEventListener("click", growTree));
  splash?.addEventListener("click", (event) => {
    const clickedTree = tree?.contains(event.target);
    const clickedLink = event.target.closest("a");

    if (clickedTree) {
      resetGarden();
      return;
    }

    if (!clickedLink) {
      growTree();
    }
  });
}

export function mountPublicProblemGardenNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicProblemGardenNow();
