(function () {
  const CONFIG_ID = 'forestry-storefront-tracking-config';
  const DEFAULT_STORAGE_KEY = 'forestry:marketing:attribution';
  const CLIENT_KEY = 'forestry:marketing:client';
  const SESSION_KEY = 'forestry:marketing:session';
  const DEDUPE_PREFIX = 'forestry:marketing:dedupe:';
  const CART_ATTR_SYNC_KEY = 'forestry:marketing:cart-attributes-sync';
  const CART_ATTR_SYNC_TTL_MS = 5 * 60 * 1000;
  const SESSION_TTL_MS = 30 * 60 * 1000;
  const SHORT_DEDUPE_MS = 4000;

  const config = readConfig();
  if (!config) {
    return;
  }

  const currentSignals = captureCurrentSignals();
  if (hasPersistableSignals(currentSignals)) {
    persistAttribution(currentSignals);
  }
  const attribution = activeAttribution() || baselineAttribution(currentSignals);

  const pageKey = String(window.location.pathname || '/');
  const sessionKey = getSessionKey();
  const clientId = getClientId();

  postEvent('session_started', {
    dedupeKey: 'session_started:' + sessionKey + ':' + attribution.signature,
    dedupeTtlMs: SESSION_TTL_MS,
    properties: {
      via: 'theme_app_embed',
      entry_path: pageKey,
    },
  });

  postEvent('landing_page_viewed', {
    dedupeKey: 'landing:' + sessionKey + ':' + pageKey + ':' + attribution.signature,
    dedupeTtlMs: SESSION_TTL_MS,
    page_url: window.location.href,
    landing_page: attribution.landing_url || window.location.href,
    properties: {
      via: 'theme_app_embed',
    },
  });

  if (config.product && config.product.id) {
    postEvent('product_viewed', {
      dedupeKey: 'product:' + sessionKey + ':' + String(config.product.id),
      dedupeTtlMs: SESSION_TTL_MS,
      product_id: String(config.product.id),
      product_handle: stringOrNull(config.product.handle),
      product_title: stringOrNull(config.product.title),
      variant_id: stringOrNull(config.product.variantId),
      properties: {
        via: 'theme_app_embed',
      },
    });
  }

  wireCartForms();
  wireCheckoutIntents();
  wireWishlistSignals();
  patchNetworkSignals();
  syncCartAttributes();

  function readConfig() {
    const node = document.getElementById(CONFIG_ID);
    if (!node) {
      return null;
    }

    try {
      const parsed = JSON.parse(node.textContent || '{}');
      parsed.storageKey = stringOrNull(parsed.storageKey) || DEFAULT_STORAGE_KEY;
      parsed.proxyBase = String(parsed.proxyBase || '/apps/forestry').replace(/\/$/, '');
      parsed.funnelEndpoint = String(parsed.funnelEndpoint || (parsed.proxyBase + '/funnel/event'));
      parsed.attributionWindowDays = Math.max(1, Number(parsed.attributionWindowDays || 7));
      parsed.debug = Boolean(parsed.debug);

      return parsed;
    } catch (error) {
      return null;
    }
  }

  function wireCartForms() {
    document.addEventListener('submit', function (event) {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) {
        return;
      }

      const action = normalizeUrl(form.getAttribute('action') || '');
      if (!action.includes('/cart/add')) {
        return;
      }

      const formData = new FormData(form);
      const variantId = stringOrNull(formData.get('id'));
      const quantity = positiveInt(formData.get('quantity')) || 1;

      postEvent('add_to_cart', {
        dedupeKey: 'cart_form:' + (variantId || 'unknown') + ':' + pageKey,
        dedupeTtlMs: SHORT_DEDUPE_MS,
        product_id: stringOrNull(config.product && config.product.id),
        product_handle: stringOrNull(config.product && config.product.handle),
        product_title: stringOrNull(config.product && config.product.title),
        variant_id: variantId,
        quantity: quantity,
        cart_token: stringOrNull(config.cart && config.cart.token),
        properties: {
          via: 'theme_form',
        },
      });

      syncCartAttributes();
    }, true);
  }

  function wireCheckoutIntents() {
    document.addEventListener('click', function (event) {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      const actionable = target.closest('a, button, input[type="submit"]');
      if (!actionable) {
        return;
      }

      const href = actionable instanceof HTMLAnchorElement
        ? actionable.href
        : stringOrNull(actionable.getAttribute('formaction'));
      const text = actionable.textContent || actionable.getAttribute('value') || '';
      const checkoutToken = checkoutTokenFromUrl(href);
      const isCheckout = typeof href === 'string' && normalizeUrl(href).includes('/checkout');
      const isNamedCheckout = ['checkout', 'name="checkout"', "name='checkout'"].some(function (needle) {
        return String(actionable.outerHTML || '').toLowerCase().includes(needle);
      });

      if (!isCheckout && !isNamedCheckout) {
        return;
      }

      postEvent('checkout_started', {
        dedupeKey: 'checkout_click:' + pageKey,
        dedupeTtlMs: SHORT_DEDUPE_MS,
        cart_token: stringOrNull(config.cart && config.cart.token) || cartTokenFromLocation(),
        checkout_token: checkoutToken,
        link_label: compactText(text) || 'Checkout',
        properties: {
          via: 'theme_click',
        },
      });

      syncCartAttributes({
        checkout_token: checkoutToken,
      });
    }, true);

    document.addEventListener('submit', function (event) {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) {
        return;
      }

      const action = normalizeUrl(form.getAttribute('action') || window.location.href);
      const formHtml = String(form.outerHTML || '').toLowerCase();
      const submitter = event.submitter instanceof Element ? event.submitter : null;
      const submitterName = submitter ? compactText(submitter.getAttribute('name') || '') : '';
      const submitterText = submitter
        ? compactText(submitter.textContent || submitter.getAttribute('value') || '')
        : '';
      const isCheckoutAction = action.includes('/checkout');
      const isCheckoutSubmit = submitterName.toLowerCase() === 'checkout'
        || formHtml.includes('name="checkout"')
        || formHtml.includes("name='checkout'");

      if (!isCheckoutAction && !isCheckoutSubmit) {
        return;
      }

      postEvent('checkout_started', {
        dedupeKey: 'checkout_submit:' + pageKey,
        dedupeTtlMs: SHORT_DEDUPE_MS,
        cart_token: stringOrNull(config.cart && config.cart.token) || cartTokenFromLocation(),
        checkout_token: checkoutTokenFromUrl(action),
        link_label: submitterText || 'Checkout',
        properties: {
          via: 'theme_submit',
        },
      });

      syncCartAttributes({
        checkout_token: checkoutTokenFromUrl(action),
      });
    }, true);
  }

  function wireWishlistSignals() {
    ['forestry:wishlist:added', 'wishlist:add', 'wishlist:added'].forEach(function (eventName) {
      window.addEventListener(eventName, function (event) {
        const detail = event && event.detail ? event.detail : {};
        postEvent('wishlist_added', {
          dedupeKey: 'wishlist:' + (detail.productId || detail.product_id || pageKey),
          dedupeTtlMs: SHORT_DEDUPE_MS,
          product_id: stringOrNull(detail.productId || detail.product_id || (config.product && config.product.id)),
          product_handle: stringOrNull(detail.productHandle || detail.product_handle || (config.product && config.product.handle)),
          product_title: stringOrNull(detail.productTitle || detail.product_title || (config.product && config.product.title)),
          properties: {
            via: 'wishlist_event',
          },
        });
      });
    });
  }

  function patchNetworkSignals() {
    if (typeof window.fetch === 'function') {
      const originalFetch = window.fetch.bind(window);
      window.fetch = function forestryTrackedFetch(input, init) {
        observeRequest(input, init);
        return originalFetch(input, init);
      };
    }

    if (typeof window.XMLHttpRequest === 'function') {
      const OriginalXHR = window.XMLHttpRequest;
      const originalOpen = OriginalXHR.prototype.open;
      const originalSend = OriginalXHR.prototype.send;

      OriginalXHR.prototype.open = function forestryTrackedOpen(method, url) {
        this.__forestryTrackingUrl = typeof url === 'string' ? url : '';
        this.__forestryTrackingMethod = typeof method === 'string' ? method : 'GET';
        return originalOpen.apply(this, arguments);
      };

      OriginalXHR.prototype.send = function forestryTrackedSend(body) {
        observeRequest(this.__forestryTrackingUrl || '', {
          method: this.__forestryTrackingMethod || 'GET',
          body: body,
        });

        return originalSend.apply(this, arguments);
      };
    }
  }

  function observeRequest(input, init) {
    const url = normalizeUrl(typeof input === 'string' ? input : (input && input.url) || '');
    if (!url) {
      return;
    }

    if (url.includes('/cart/add')) {
      const bodyData = bodyParams(init && init.body);
      postEvent('add_to_cart', {
        dedupeKey: 'cart_network:' + (bodyData.id || pageKey),
        dedupeTtlMs: SHORT_DEDUPE_MS,
        product_id: stringOrNull(config.product && config.product.id),
        product_handle: stringOrNull(config.product && config.product.handle),
        product_title: stringOrNull(config.product && config.product.title),
        variant_id: stringOrNull(bodyData.id),
        quantity: positiveInt(bodyData.quantity) || 1,
        cart_token: stringOrNull(config.cart && config.cart.token),
        properties: {
          via: 'network_fetch',
        },
      });

      syncCartAttributes();
    }

    if (url.includes('/apps/forestry/wishlist/add')) {
      postEvent('wishlist_added', {
        dedupeKey: 'wishlist_network:' + pageKey,
        dedupeTtlMs: SHORT_DEDUPE_MS,
        product_id: stringOrNull(config.product && config.product.id),
        product_handle: stringOrNull(config.product && config.product.handle),
        product_title: stringOrNull(config.product && config.product.title),
        properties: {
          via: 'network_fetch',
        },
      });
    }
  }

  function postEvent(eventType, overrides) {
    const payload = buildPayload(eventType, overrides || {});
    if (!payload) {
      return;
    }

    const dedupeKey = stringOrNull(overrides && overrides.dedupeKey);
    const dedupeTtlMs = Number((overrides && overrides.dedupeTtlMs) || SHORT_DEDUPE_MS);
    if (dedupeKey && isRecentlySent(dedupeKey, dedupeTtlMs)) {
      return;
    }

    if (dedupeKey) {
      rememberSent(dedupeKey);
    }

    fetch(config.funnelEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(payload),
    }).catch(function () {
      debug('Tracker post failed for', eventType);
    });
  }

  function buildPayload(eventType, overrides) {
    const current = activeAttribution() || baselineAttribution(captureCurrentSignals());

    const pageUrl = stringOrNull(overrides.page_url) || window.location.href;
    const landingPage = stringOrNull(overrides.landing_page) || current.landing_url || window.location.href;
    const referrer = stringOrNull(document.referrer) || stringOrNull(current.referrer);
    const properties = typeof overrides.properties === 'object' && overrides.properties !== null
      ? overrides.properties
      : {};

    return compactObject({
      event_type: eventType,
      request_key: 'theme:' + eventType + ':' + sessionKey + ':' + Date.now(),
      occurred_at: new Date().toISOString(),
      page_url: pageUrl,
      landing_page: landingPage,
      referrer: referrer,
      page_type: stringOrNull(config.pageType),
      session_key: sessionKey,
      client_id: clientId,
      cart_token: stringOrNull(overrides.cart_token) || stringOrNull(config.cart && config.cart.token) || cartTokenFromLocation(),
      checkout_token: stringOrNull(overrides.checkout_token) || checkoutTokenFromLocation(),
      product_id: stringOrNull(overrides.product_id),
      product_handle: stringOrNull(overrides.product_handle),
      product_title: stringOrNull(overrides.product_title),
      variant_id: stringOrNull(overrides.variant_id),
      quantity: positiveInt(overrides.quantity),
      link_label: stringOrNull(overrides.link_label),
      properties: compactObject(properties),
      shop: stringOrNull(config.shop),
      customer_id: stringOrNull(config.customer && config.customer.id),
      utm_source: stringOrNull(current.utm_source),
      utm_medium: stringOrNull(current.utm_medium),
      utm_campaign: stringOrNull(current.utm_campaign),
      utm_content: stringOrNull(current.utm_content),
      utm_term: stringOrNull(current.utm_term),
      fbclid: stringOrNull(current.fbclid),
      fbc: stringOrNull(current.fbc),
      fbp: stringOrNull(current.fbp),
      mf_channel: stringOrNull(current.mf_channel),
      mf_source_label: stringOrNull(current.mf_source_label),
      mf_template_key: stringOrNull(current.mf_template_key),
      mf_campaign_id: stringOrNull(current.mf_campaign_id),
      mf_delivery_id: stringOrNull(current.mf_delivery_id),
      mf_profile_id: stringOrNull(current.mf_profile_id),
      mf_campaign_recipient_id: stringOrNull(current.mf_campaign_recipient_id),
      mf_module_type: stringOrNull(current.mf_module_type),
      mf_module_position: stringOrNull(current.mf_module_position),
      mf_product_id: stringOrNull(current.mf_product_id),
      mf_tile_position: stringOrNull(current.mf_tile_position),
      mf_link_label: stringOrNull(current.mf_link_label),
      meta: {
        tracker: 'theme_app_embed',
        attribution_signature: current.signature,
      },
    });
  }

  function syncCartAttributes(overrides) {
    const attributes = compactObject({
      session_key: sessionKey,
      client_id: clientId,
      cart_token: stringOrNull((overrides || {}).cart_token) || stringOrNull(config.cart && config.cart.token) || cartTokenFromLocation(),
      checkout_token: stringOrNull((overrides || {}).checkout_token) || checkoutTokenFromLocation(),
      fbclid: stringOrNull(attribution.fbclid),
      fbc: stringOrNull(attribution.fbc),
      fbp: stringOrNull(attribution.fbp),
    });

    if (Object.keys(attributes).length === 0) {
      return;
    }

    const signature = Object.keys(attributes).sort().map(function (key) {
      return key + ':' + String(attributes[key]);
    }).join('|');

    if (cartAttributesRecentlySynced(signature)) {
      return;
    }

    rememberCartAttributesSync(signature);

    fetch('/cart/update.js', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        attributes: attributes,
      }),
    }).then(function (response) {
      if (!response.ok) {
        return null;
      }

      return response.json().catch(function () {
        return null;
      });
    }).then(function (cart) {
      const token = stringOrNull(cart && cart.token);
      if (!token) {
        return;
      }

      if (!config.cart || typeof config.cart !== 'object') {
        config.cart = {};
      }
      config.cart.token = token;
    }).catch(function () {
      debug('Unable to sync cart linkage attributes');
    });
  }

  function cartAttributesRecentlySynced(signature) {
    try {
      const raw = window.sessionStorage.getItem(CART_ATTR_SYNC_KEY);
      if (!raw) {
        return false;
      }

      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return false;
      }

      if (parsed.signature !== signature) {
        return false;
      }

      const syncedAt = Number(parsed.synced_at || 0);
      return syncedAt > 0 && (Date.now() - syncedAt) < CART_ATTR_SYNC_TTL_MS;
    } catch (error) {
      return false;
    }
  }

  function rememberCartAttributesSync(signature) {
    try {
      window.sessionStorage.setItem(CART_ATTR_SYNC_KEY, JSON.stringify({
        signature: signature,
        synced_at: Date.now(),
      }));
    } catch (error) {
      debug('Unable to persist cart linkage sync signature');
    }
  }

  function persistAttribution(signals) {
    const derivedFbc = stringOrNull(signals.fbc) || fbcFromFbclid(stringOrNull(signals.fbclid));
    const expiresAt = Date.now() + (config.attributionWindowDays * 24 * 60 * 60 * 1000);
    const payload = compactObject({
      signature: attributionSignature(signals),
      landing_url: window.location.href,
      landing_path: window.location.pathname,
      referrer: document.referrer || null,
      expires_at: expiresAt,
      captured_at: Date.now(),
      utm_source: stringOrNull(signals.utm_source),
      utm_medium: stringOrNull(signals.utm_medium),
      utm_campaign: stringOrNull(signals.utm_campaign),
      utm_content: stringOrNull(signals.utm_content),
      utm_term: stringOrNull(signals.utm_term),
      fbclid: stringOrNull(signals.fbclid),
      fbc: derivedFbc,
      fbp: stringOrNull(signals.fbp),
      mf_channel: stringOrNull(signals.mf_channel),
      mf_source_label: stringOrNull(signals.mf_source_label),
      mf_template_key: stringOrNull(signals.mf_template_key),
      mf_campaign_id: stringOrNull(signals.mf_campaign_id),
      mf_delivery_id: stringOrNull(signals.mf_delivery_id),
      mf_profile_id: stringOrNull(signals.mf_profile_id),
      mf_campaign_recipient_id: stringOrNull(signals.mf_campaign_recipient_id),
      mf_module_type: stringOrNull(signals.mf_module_type),
      mf_module_position: stringOrNull(signals.mf_module_position),
      mf_product_id: stringOrNull(signals.mf_product_id),
      mf_tile_position: stringOrNull(signals.mf_tile_position),
      mf_link_label: stringOrNull(signals.mf_link_label),
    });

    try {
      window.localStorage.setItem(config.storageKey, JSON.stringify(payload));
    } catch (error) {
      debug('Failed to persist attribution', error && error.message ? error.message : error);
    }
  }

  function activeAttribution() {
    try {
      const raw = window.localStorage.getItem(config.storageKey);
      if (!raw) {
        return null;
      }

      const parsed = JSON.parse(raw);
      const expiresAt = Number(parsed && parsed.expires_at ? parsed.expires_at : 0);
      if (!expiresAt || expiresAt < Date.now()) {
        window.localStorage.removeItem(config.storageKey);
        return null;
      }

      return parsed;
    } catch (error) {
      return null;
    }
  }

  function baselineAttribution(signals) {
    const fallbackSignals = typeof signals === 'object' && signals !== null ? signals : {};
    const fbclid = stringOrNull(fallbackSignals.fbclid);
    const fbc = stringOrNull(fallbackSignals.fbc) || fbcFromFbclid(fbclid);
    const payload = compactObject({
      signature: attributionSignature(fallbackSignals),
      landing_url: window.location.href,
      landing_path: window.location.pathname,
      referrer: document.referrer || null,
      expires_at: Date.now() + (config.attributionWindowDays * 24 * 60 * 60 * 1000),
      captured_at: Date.now(),
      utm_source: stringOrNull(fallbackSignals.utm_source),
      utm_medium: stringOrNull(fallbackSignals.utm_medium),
      utm_campaign: stringOrNull(fallbackSignals.utm_campaign),
      utm_content: stringOrNull(fallbackSignals.utm_content),
      utm_term: stringOrNull(fallbackSignals.utm_term),
      fbclid: fbclid,
      fbc: fbc,
      fbp: stringOrNull(fallbackSignals.fbp),
      mf_channel: stringOrNull(fallbackSignals.mf_channel),
      mf_source_label: stringOrNull(fallbackSignals.mf_source_label),
      mf_template_key: stringOrNull(fallbackSignals.mf_template_key),
      mf_campaign_id: stringOrNull(fallbackSignals.mf_campaign_id),
      mf_delivery_id: stringOrNull(fallbackSignals.mf_delivery_id),
      mf_profile_id: stringOrNull(fallbackSignals.mf_profile_id),
      mf_campaign_recipient_id: stringOrNull(fallbackSignals.mf_campaign_recipient_id),
      mf_module_type: stringOrNull(fallbackSignals.mf_module_type),
      mf_module_position: stringOrNull(fallbackSignals.mf_module_position),
      mf_product_id: stringOrNull(fallbackSignals.mf_product_id),
      mf_tile_position: stringOrNull(fallbackSignals.mf_tile_position),
      mf_link_label: stringOrNull(fallbackSignals.mf_link_label),
    });

    if (!payload.signature) {
      payload.signature = 'baseline';
    }

    return payload;
  }

  function captureCurrentSignals() {
    const urlSignals = campaignSignalsFromUrl(window.location.href);
    const cookieSignals = metaSignalsFromCookies();

    return compactObject({
      ...cookieSignals,
      ...urlSignals,
    });
  }

  function campaignSignalsFromUrl(url) {
    try {
      const parsed = new URL(url, window.location.origin);
      const signals = {};
      [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'fbc',
        'fbp',
        'mf_channel',
        'mf_source_label',
        'mf_template_key',
        'mf_campaign_id',
        'mf_delivery_id',
        'mf_profile_id',
        'mf_campaign_recipient_id',
        'mf_module_type',
        'mf_module_position',
        'mf_product_id',
        'mf_tile_position',
        'mf_link_label'
      ].forEach(function (key) {
        const value = compactText(parsed.searchParams.get(key));
        if (value) {
          signals[key] = value;
        }
      });

      return signals;
    } catch (error) {
      return {};
    }
  }

  function metaSignalsFromCookies() {
    const cookieValue = String(document.cookie || '');
    if (!cookieValue) {
      return {};
    }

    const segments = cookieValue.split(';');
    const parsed = {};
    segments.forEach(function (segment) {
      const [keyRaw, ...rest] = segment.split('=');
      const key = compactText(keyRaw || '');
      const value = compactText(rest.join('='));
      if (!key || !value) {
        return;
      }

      if (key === '_fbc') {
        parsed.fbc = value;
      }
      if (key === '_fbp') {
        parsed.fbp = value;
      }
    });

    return parsed;
  }

  function hasPersistableSignals(signals) {
    if (!signals || typeof signals !== 'object') {
      return false;
    }

    return [
      'utm_campaign',
      'utm_source',
      'fbclid',
      'fbc',
      'fbp',
      'mf_campaign_id',
      'mf_delivery_id',
      'mf_template_key',
      'mf_channel'
    ].some(function (key) {
      return Boolean(compactText(signals[key]));
    });
  }

  function attributionSignature(signals) {
    return [
      compactText(signals.utm_campaign),
      compactText(signals.mf_campaign_id),
      compactText(signals.mf_delivery_id),
      compactText(signals.mf_template_key),
      compactText(signals.mf_channel),
      compactText(signals.fbclid),
      compactText(signals.fbc),
      compactText(signals.fbp),
      compactText(signals.mf_product_id),
      compactText(signals.mf_tile_position)
    ].filter(Boolean).join(':') || 'baseline';
  }

  function fbcFromFbclid(fbclid) {
    const normalized = compactText(fbclid);
    if (!normalized) {
      return null;
    }

    return 'fb.1.' + Math.floor(Date.now() / 1000) + '.' + normalized;
  }

  function getClientId() {
    try {
      const existing = window.localStorage.getItem(CLIENT_KEY);
      if (existing) {
        return existing;
      }

      const generated = randomId('client');
      window.localStorage.setItem(CLIENT_KEY, generated);
      return generated;
    } catch (error) {
      return randomId('client');
    }
  }

  function getSessionKey() {
    try {
      const raw = window.sessionStorage.getItem(SESSION_KEY);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed && parsed.id && parsed.expires_at && parsed.expires_at > Date.now()) {
          return parsed.id;
        }
      }

      const generated = {
        id: randomId('session'),
        expires_at: Date.now() + SESSION_TTL_MS,
      };
      window.sessionStorage.setItem(SESSION_KEY, JSON.stringify(generated));
      return generated.id;
    } catch (error) {
      return randomId('session');
    }
  }

  function isRecentlySent(key, ttlMs) {
    try {
      const raw = window.sessionStorage.getItem(DEDUPE_PREFIX + key);
      const sentAt = Number(raw || 0);
      return sentAt > 0 && (Date.now() - sentAt) < ttlMs;
    } catch (error) {
      return false;
    }
  }

  function rememberSent(key) {
    try {
      window.sessionStorage.setItem(DEDUPE_PREFIX + key, String(Date.now()));
    } catch (error) {
      debug('Unable to persist dedupe key', key);
    }
  }

  function bodyParams(body) {
    if (!body) {
      return {};
    }

    if (body instanceof URLSearchParams) {
      return Object.fromEntries(body.entries());
    }

    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      return Object.fromEntries(body.entries());
    }

    if (typeof body === 'string') {
      try {
        return Object.fromEntries(new URLSearchParams(body).entries());
      } catch (error) {
        return {};
      }
    }

    return {};
  }

  function checkoutTokenFromLocation() {
    return checkoutTokenFromUrl(window.location.href);
  }

  function cartTokenFromLocation() {
    return cartTokenFromUrl(window.location.href);
  }

  function checkoutTokenFromUrl(value) {
    const url = normalizeUrl(value || '');
    if (!url) {
      return null;
    }

    const pathMatch = String(url).match(/\/checkouts\/([^/?#]+)/i);
    if (pathMatch && pathMatch[1]) {
      return compactText(pathMatch[1]);
    }

    try {
      const parsed = new URL(url, window.location.origin);
      const queryToken = compactText(parsed.searchParams.get('token') || parsed.searchParams.get('checkout_token'));
      return queryToken || null;
    } catch (error) {
      return null;
    }
  }

  function cartTokenFromUrl(value) {
    const url = normalizeUrl(value || '');
    if (!url) {
      return null;
    }

    const pathMatch = String(url).match(/\/cart\/c\/([^/?#]+)/i);
    if (pathMatch && pathMatch[1]) {
      return compactText(pathMatch[1]);
    }

    try {
      const parsed = new URL(url, window.location.origin);
      const queryToken = compactText(parsed.searchParams.get('cart_token'));
      return queryToken || null;
    } catch (error) {
      return null;
    }
  }

  function normalizeUrl(value) {
    if (!value) {
      return '';
    }

    try {
      return new URL(value, window.location.origin).toString();
    } catch (error) {
      return String(value || '');
    }
  }

  function compactObject(input) {
    const output = {};
    Object.keys(input || {}).forEach(function (key) {
      const value = input[key];
      if (value === null || value === undefined || value === '' || value === false) {
        return;
      }
      if (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0) {
        return;
      }
      output[key] = value;
    });
    return output;
  }

  function compactText(value) {
    return typeof value === 'string' ? value.trim() : '';
  }

  function stringOrNull(value) {
    const normalized = compactText(value);
    return normalized === '' ? null : normalized;
  }

  function positiveInt(value) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? Math.round(number) : null;
  }

  function randomId(prefix) {
    return prefix + ':' + Math.random().toString(36).slice(2) + ':' + Date.now().toString(36);
  }

  function debug() {
    if (!config.debug || typeof console === 'undefined' || typeof console.info !== 'function') {
      return;
    }

    const args = Array.prototype.slice.call(arguments);
    args.unshift('[Forestry tracker]');
    console.info.apply(console, args);
  }
})();
