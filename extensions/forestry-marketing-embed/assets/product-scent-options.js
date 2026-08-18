(function () {
  if (window.__everbranchProductOptionsLoaded) return;
  window.__everbranchProductOptionsLoaded = true;

  const ROOT_SELECTOR = '[data-everbranch-product-options]';
  const initialized = new WeakSet();

  function initAll() {
    document.querySelectorAll(ROOT_SELECTOR).forEach(init);
  }

  async function init(root) {
    if (initialized.has(root)) return;
    initialized.add(root);

    const endpoint = String(root.dataset.proxyEndpoint || '').trim();
    const productId = String(root.dataset.productId || '').trim();
    const handle = String(root.dataset.productHandle || '').trim();
    if (!endpoint || (!productId && !handle)) return;

    try {
      const url = new URL(endpoint, window.location.origin);
      if (productId) url.searchParams.set('product_id', productId);
      if (handle) url.searchParams.set('handle', handle);

      const response = await fetch(url.toString(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      const ruleset = payload && payload.ok ? payload.data : null;
      if (!response.ok || !ruleset) return;

      render(root, ruleset);
    } catch (error) {
      root.hidden = false;
      root.innerHTML = '<p class="everbranch-product-options__error">Scent options could not be loaded. Please refresh and try again.</p>';
    }
  }

  function render(root, ruleset) {
    const form = findProductForm(root);
    if (!form) {
      root.hidden = false;
      root.innerHTML = '<p class="everbranch-product-options__error">The product form could not be found.</p>';
      return;
    }

    if (form.dataset.everbranchProductOptionsAttached === 'true') {
      root.remove();
      return;
    }
    form.dataset.everbranchProductOptionsAttached = 'true';

    if (!form.id) form.id = 'everbranch-product-form-' + Math.random().toString(36).slice(2, 10);
    placeInsideProductForm(root, form);

    const count = Math.max(1, Number(ruleset.option_count || 1));
    const values = Array.isArray(ruleset.allowed_values) ? ruleset.allowed_values.filter(Boolean) : [];
    if (!values.length) return;

    root.innerHTML = '';
    root.hidden = false;

    const heading = document.createElement('h3');
    heading.className = 'everbranch-product-options__heading';
    heading.textContent = root.dataset.heading || 'Choose your scents';
    root.appendChild(heading);

    const help = document.createElement('p');
    help.className = 'everbranch-product-options__help';
    help.textContent = root.dataset.helpText || 'Choose one scent for each item in this bundle.';
    root.appendChild(help);

    const fields = document.createElement('div');
    fields.className = 'everbranch-product-options__fields';
    const selects = [];
    const propertyInputs = [];

    for (let index = 1; index <= count; index += 1) {
      const field = document.createElement('label');
      field.className = 'everbranch-product-options__field';

      const label = document.createElement('span');
      label.className = 'everbranch-product-options__label';
      label.textContent = 'Scent ' + index;

      const select = document.createElement('select');
      select.className = 'everbranch-product-options__select';
      select.required = true;
      select.dataset.scentPosition = String(index);

      // Prestige's AJAX cart serializer is only reliable for inputs that are
      // direct members of the product form. Keep the visible selector
      // presentation-only and maintain the Shopify line-item properties as
      // hidden inputs on that form. This also works with a normal HTML submit.
      const propertyInput = document.createElement('input');
      propertyInput.type = 'hidden';
      propertyInput.name = 'properties[Scent ' + index + ']';
      propertyInput.dataset.everbranchScentPosition = String(index);
      form.appendChild(propertyInput);

      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Choose a scent';
      placeholder.disabled = true;
      placeholder.selected = true;
      select.appendChild(placeholder);

      values.forEach((value) => {
        const option = document.createElement('option');
        option.value = String(value);
        option.textContent = String(value);
        select.appendChild(option);
      });

      field.appendChild(label);
      field.appendChild(select);
      fields.appendChild(field);
      selects.push(select);
      propertyInputs.push(propertyInput);
    }

    root.appendChild(fields);

    const error = document.createElement('p');
    error.className = 'everbranch-product-options__error';
    error.hidden = true;
    error.setAttribute('aria-live', 'polite');
    root.appendChild(error);

    function syncProperties() {
      selects.forEach((select, index) => {
        propertyInputs[index].value = select.value;
      });
    }

    selects.forEach((select) => {
      select.addEventListener('change', syncProperties);
    });

    function validate(event) {
      error.hidden = true;
      error.textContent = '';

      syncProperties();

      const missing = selects.find((select) => !select.value);
      if (missing) {
        blockEvent(event);
        error.textContent = 'Choose all ' + count + ' scent' + (count === 1 ? '' : 's') + ' before adding this bundle.';
        error.hidden = false;
        missing.focus();
        return false;
      }

      if (ruleset.require_distinct_values) {
        const chosen = selects.map((select) => select.value);
        if (new Set(chosen).size !== chosen.length) {
          blockEvent(event);
          error.textContent = 'Choose a different scent for each item in this bundle.';
          error.hidden = false;
          selects[0].focus();
          return false;
        }
      }

      return true;
    }

    form.addEventListener('submit', validate, true);
    form.addEventListener('click', function (event) {
      if (isCheckoutControl(event.target)) {
        validate(event);
      }
    }, true);
  }

  function findProductForm(root) {
    const closest = root.closest('form[action*="/cart/add"]');
    if (closest) return closest;

    const forms = Array.from(document.querySelectorAll('form[action*="/cart/add"]'))
      .filter((form) => form.querySelector('[name="id"]'));

    return forms.find((form) => form.getClientRects().length > 0)
      || forms[0]
      || null;
  }

  function placeInsideProductForm(root, form) {
    if (root.closest('form') === form) return;

    const anchor = form.querySelector(
      '.product-form__buttons, .product-form__payment-container, .shopify-payment-button, shopify-accelerated-checkout, button[type="submit"], input[type="submit"]'
    );

    if (anchor) {
      let insertionPoint = anchor;
      while (insertionPoint.parentElement && insertionPoint.parentElement !== form) {
        insertionPoint = insertionPoint.parentElement;
      }
      form.insertBefore(root, insertionPoint);
    } else {
      form.appendChild(root);
    }
  }

  function isCheckoutControl(target) {
    if (!(target instanceof Element)) return false;

    return Boolean(target.closest(
      'button[type="submit"], input[type="submit"], .shopify-payment-button, shopify-accelerated-checkout, [data-shopify="payment-button"]'
    ));
  }

  function blockEvent(event) {
    if (!event) return;
    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === 'function') {
      event.stopImmediatePropagation();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll, { once: true });
  } else {
    initAll();
  }

  document.addEventListener('shopify:section:load', initAll);
})();
