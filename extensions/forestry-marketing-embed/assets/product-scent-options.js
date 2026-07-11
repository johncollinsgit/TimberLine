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

    if (!form.id) form.id = 'everbranch-product-form-' + Math.random().toString(36).slice(2, 10);

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

    for (let index = 1; index <= count; index += 1) {
      const field = document.createElement('label');
      field.className = 'everbranch-product-options__field';

      const label = document.createElement('span');
      label.className = 'everbranch-product-options__label';
      label.textContent = 'Scent ' + index;

      const select = document.createElement('select');
      select.className = 'everbranch-product-options__select';
      select.name = 'properties[Scent ' + index + ']';
      select.setAttribute('form', form.id);
      select.required = true;
      select.dataset.scentPosition = String(index);

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
    }

    root.appendChild(fields);

    const error = document.createElement('p');
    error.className = 'everbranch-product-options__error';
    error.hidden = true;
    error.setAttribute('aria-live', 'polite');
    root.appendChild(error);

    form.addEventListener('submit', function (event) {
      error.hidden = true;
      error.textContent = '';

      const missing = selects.find((select) => !select.value);
      if (missing) {
        event.preventDefault();
        error.textContent = 'Choose all ' + count + ' scent' + (count === 1 ? '' : 's') + ' before adding this bundle.';
        error.hidden = false;
        missing.focus();
        return;
      }

      if (ruleset.require_distinct_values) {
        const chosen = selects.map((select) => select.value);
        if (new Set(chosen).size !== chosen.length) {
          event.preventDefault();
          error.textContent = 'Choose a different scent for each item in this bundle.';
          error.hidden = false;
          selects[0].focus();
        }
      }
    }, true);
  }

  function findProductForm(root) {
    return root.closest('form[action*="/cart/add"]')
      || document.querySelector('form[action*="/cart/add"]');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll, { once: true });
  } else {
    initAll();
  }

  document.addEventListener('shopify:section:load', initAll);
})();
