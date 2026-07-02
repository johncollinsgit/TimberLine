const DETAILS_CARD_SELECTOR = "[data-clickable-details-card]";
const INTERACTIVE_SELECTOR = "a, button, input, label, select, summary, textarea";

function mountDetailsCard(card) {
  if (card.dataset.clickableDetailsMounted === "true") {
    return;
  }

  card.dataset.clickableDetailsMounted = "true";

  card.addEventListener("click", (event) => {
    if (event.defaultPrevented || event.target.closest(INTERACTIVE_SELECTOR)) {
      return;
    }

    card.open = !card.open;
  });
}

export function mountPublicDetailsCardsNow() {
  document.querySelectorAll(DETAILS_CARD_SELECTOR).forEach(mountDetailsCard);
}

mountPublicDetailsCardsNow();
