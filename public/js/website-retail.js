/* Native controls enhance server-rendered choices; the server owns every price and stock check. */
(() => {
  'use strict';
  for (const root of document.querySelectorAll('[data-website-variant-picker]')) {
    const select = root.querySelector('[data-variant-choice]');
    if (!select) continue;
    const form = root.querySelector('[data-variant-cart-form]');
    const quantity = root.querySelector('[data-variant-quantity]');
    const button = root.querySelector('[data-variant-add]');
    const update = () => {
      const option = select.selectedOptions[0];
      if (!option) return;
      let destination = null;
      if (root.dataset.cartEnabled === '1') {
        destination = new URL(option.dataset.action, location.href);
        if (destination.origin !== location.origin || !/^\/cart\/items\/[1-9][0-9]*$/.test(destination.pathname) || destination.search || destination.hash) return;
      } else if (form) return;
      const available = root.dataset.cartEnabled === '1' && option.dataset.available === '1';
      root.querySelector('[data-variant-price]').textContent = option.dataset.price;
      const compare = root.querySelector('[data-variant-compare]');
      compare.textContent = option.dataset.comparePrice;
      compare.hidden = !option.dataset.comparePrice;
      root.querySelector('[data-variant-availability]').textContent = option.dataset.availability;
      if (form && destination) form.action = destination.href;
      if (quantity) {
        const maximum = Math.max(1, Math.min(20, Number(option.dataset.maximum) || 1));
        quantity.max = String(maximum);
        quantity.value = String(Math.max(1, Math.min(maximum, Number(quantity.value) || 1)));
        quantity.disabled = !available;
      }
      if (button) button.disabled = !available;
    };
    select.addEventListener('change', update);
    root.querySelector('[data-variant-update]').hidden = true;
    root.querySelector('[data-variant-choice-form]').addEventListener('submit', event => { event.preventDefault(); update(); });
    update();
  }
})();
