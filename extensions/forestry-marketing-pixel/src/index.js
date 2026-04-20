import {register} from '@shopify/web-pixels-extension';

const ATTR_KEY = 'forestry:marketing:attribution';
const DEDUPE_PREFIX = 'forestry:marketing:pixel:';
const WINDOW_DAYS = 7;

register(({analytics, browser, settings}) => {
  const proxyBase = String(settings.appProxyBase || '/apps/forestry').replace(/\/$/, '');
  const funnelEndpoint = proxyBase + '/funnel/event';

  analytics.subscribe('page_viewed', async (event) => {
    const attribution = await resolveAttribution(event, browser);
    const url = event?.context?.document?.location?.href || null;
    const dedupeKey = 'landing:' + attribution.signature + ':' + (event?.context?.document?.location?.pathname || '/');
    if (await isDuplicate(browser, dedupeKey, 30 * 60 * 1000)) {
      return;
    }

    await postEvent(funnelEndpoint, {
      event_type: 'landing_page_viewed',
      occurred_at: new Date().toISOString(),
      page_url: url,
      landing_page: attribution.landing_url || url,
      referrer: event?.context?.document?.referrer || attribution.referrer || null,
      session_key: attribution.session_key || null,
      client_id: attribution.client_id || null,
      utm_source: attribution.utm_source || null,
      utm_medium: attribution.utm_medium || null,
      utm_campaign: attribution.utm_campaign || null,
      utm_content: attribution.utm_content || null,
      utm_term: attribution.utm_term || null,
      fbclid: attribution.fbclid || null,
      fbc: attribution.fbc || null,
      fbp: attribution.fbp || null,
      mf_channel: attribution.mf_channel || null,
      mf_source_label: attribution.mf_source_label || null,
      mf_template_key: attribution.mf_template_key || null,
      mf_campaign_id: attribution.mf_campaign_id || null,
      mf_delivery_id: attribution.mf_delivery_id || null,
      mf_profile_id: attribution.mf_profile_id || null,
      mf_campaign_recipient_id: attribution.mf_campaign_recipient_id || null,
      mf_module_type: attribution.mf_module_type || null,
      mf_module_position: attribution.mf_module_position || null,
      mf_product_id: attribution.mf_product_id || null,
      mf_tile_position: attribution.mf_tile_position || null,
      mf_link_label: attribution.mf_link_label || null,
      meta: {
        tracker: 'web_pixel',
        attribution_signature: attribution.signature,
      },
    });
  });

  analytics.subscribe('product_viewed', async (event) => {
    const attribution = await resolveAttribution(event, browser);
    if (!attribution) {
      return;
    }

    const productVariant = event?.data?.productVariant || {};
    const product = productVariant?.product || {};
    await postEvent(funnelEndpoint, {
      event_type: 'product_viewed',
      occurred_at: new Date().toISOString(),
      page_url: event?.context?.document?.location?.href || null,
      landing_page: attribution.landing_url || null,
      referrer: event?.context?.document?.referrer || attribution.referrer || null,
      session_key: attribution.session_key || null,
      client_id: attribution.client_id || null,
      product_id: normalizeShopifyId(product?.id || productVariant?.product?.id),
      product_handle: product?.handle || null,
      product_title: product?.title || null,
      variant_id: normalizeShopifyId(productVariant?.id),
      utm_source: attribution.utm_source || null,
      utm_medium: attribution.utm_medium || null,
      utm_campaign: attribution.utm_campaign || null,
      fbclid: attribution.fbclid || null,
      fbc: attribution.fbc || null,
      fbp: attribution.fbp || null,
      mf_channel: attribution.mf_channel || null,
      mf_campaign_id: attribution.mf_campaign_id || null,
      mf_delivery_id: attribution.mf_delivery_id || null,
      mf_profile_id: attribution.mf_profile_id || null,
      mf_module_type: attribution.mf_module_type || null,
      mf_product_id: attribution.mf_product_id || null,
      mf_tile_position: attribution.mf_tile_position || null,
      meta: {
        tracker: 'web_pixel',
        attribution_signature: attribution.signature,
      },
    });
  });

  analytics.subscribe('product_added_to_cart', async (event) => {
    const attribution = await resolveAttribution(event, browser);
    if (!attribution) {
      return;
    }

    const line = event?.data?.cartLine || {};
    const merchandise = line?.merchandise || {};
    const product = merchandise?.product || {};
    await postEvent(funnelEndpoint, {
      event_type: 'add_to_cart',
      occurred_at: new Date().toISOString(),
      page_url: event?.context?.document?.location?.href || null,
      landing_page: attribution.landing_url || null,
      referrer: event?.context?.document?.referrer || attribution.referrer || null,
      session_key: attribution.session_key || null,
      client_id: attribution.client_id || null,
      product_id: normalizeShopifyId(product?.id),
      product_handle: product?.handle || null,
      product_title: product?.title || null,
      variant_id: normalizeShopifyId(merchandise?.id),
      quantity: safeQuantity(line?.quantity),
      cart_token: event?.data?.cart?.id || null,
      utm_source: attribution.utm_source || null,
      utm_medium: attribution.utm_medium || null,
      utm_campaign: attribution.utm_campaign || null,
      fbclid: attribution.fbclid || null,
      fbc: attribution.fbc || null,
      fbp: attribution.fbp || null,
      mf_channel: attribution.mf_channel || null,
      mf_campaign_id: attribution.mf_campaign_id || null,
      mf_delivery_id: attribution.mf_delivery_id || null,
      mf_profile_id: attribution.mf_profile_id || null,
      mf_module_type: attribution.mf_module_type || null,
      mf_product_id: attribution.mf_product_id || null,
      mf_tile_position: attribution.mf_tile_position || null,
      meta: {
        tracker: 'web_pixel',
        attribution_signature: attribution.signature,
      },
    });
  });

  analytics.subscribe('checkout_started', async (event) => {
    const attribution = await resolveAttribution(event, browser);
    if (!attribution) {
      return;
    }

    await postEvent(funnelEndpoint, {
      event_type: 'checkout_started',
      occurred_at: new Date().toISOString(),
      page_url: event?.context?.document?.location?.href || null,
      landing_page: attribution.landing_url || null,
      referrer: event?.context?.document?.referrer || attribution.referrer || null,
      session_key: attribution.session_key || null,
      client_id: attribution.client_id || null,
      checkout_token: normalizeShopifyId(event?.data?.checkout?.id),
      cart_token: normalizeShopifyId(event?.data?.checkout?.cart?.id),
      currency: event?.data?.checkout?.currencyCode || null,
      utm_source: attribution.utm_source || null,
      utm_medium: attribution.utm_medium || null,
      utm_campaign: attribution.utm_campaign || null,
      fbclid: attribution.fbclid || null,
      fbc: attribution.fbc || null,
      fbp: attribution.fbp || null,
      mf_channel: attribution.mf_channel || null,
      mf_campaign_id: attribution.mf_campaign_id || null,
      mf_delivery_id: attribution.mf_delivery_id || null,
      mf_profile_id: attribution.mf_profile_id || null,
      meta: {
        tracker: 'web_pixel',
        attribution_signature: attribution.signature,
      },
    });
  });

  analytics.subscribe('checkout_completed', async (event) => {
    const attribution = await resolveAttribution(event, browser);
    if (!attribution) {
      return;
    }

    await postEvent(funnelEndpoint, {
      event_type: 'checkout_completed',
      occurred_at: new Date().toISOString(),
      page_url: event?.context?.document?.location?.href || null,
      landing_page: attribution.landing_url || null,
      referrer: event?.context?.document?.referrer || attribution.referrer || null,
      session_key: attribution.session_key || null,
      client_id: attribution.client_id || null,
      checkout_token: normalizeShopifyId(event?.data?.checkout?.id),
      cart_token: normalizeShopifyId(event?.data?.checkout?.cart?.id),
      currency: event?.data?.checkout?.currencyCode || null,
      value_cents: toCents(event?.data?.checkout?.totalPrice?.amount),
      utm_source: attribution.utm_source || null,
      utm_medium: attribution.utm_medium || null,
      utm_campaign: attribution.utm_campaign || null,
      fbclid: attribution.fbclid || null,
      fbc: attribution.fbc || null,
      fbp: attribution.fbp || null,
      mf_channel: attribution.mf_channel || null,
      mf_campaign_id: attribution.mf_campaign_id || null,
      mf_delivery_id: attribution.mf_delivery_id || null,
      mf_profile_id: attribution.mf_profile_id || null,
      meta: {
        tracker: 'web_pixel',
        attribution_signature: attribution.signature,
      },
    });
  });
});

async function resolveAttribution(event, browser) {
  const currentSignals = compactObject({
    ...metaSignalsFromEvent(event),
    ...signalsFromUrl(event?.context?.document?.location?.href || null),
  });
  let stored = await readAttribution(browser);

  if (hasPersistableSignals(currentSignals)) {
    stored = await writeAttribution(browser, currentSignals, event?.context?.document?.location?.href || null, event?.context?.document?.referrer || null, stored);
  }

  if (!stored) {
    stored = await writeAttribution(browser, {}, event?.context?.document?.location?.href || null, event?.context?.document?.referrer || null, null);
  }

  if (!stored || !stored.expires_at || Number(stored.expires_at) < Date.now()) {
    return baselineAttribution(currentSignals, stored);
  }

  const merged = compactObject({
    ...stored,
    ...currentSignals,
  });

  if (!merged.fbc && merged.fbclid) {
    merged.fbc = fbcFromFbclid(merged.fbclid);
  }

  if (!merged.signature) {
    merged.signature = signature(merged);
  }

  return merged;
}

async function readAttribution(browser) {
  try {
    const raw = await browser.localStorage.getItem(ATTR_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw);
    if (!parsed || !parsed.expires_at || Number(parsed.expires_at) < Date.now()) {
      await browser.localStorage.removeItem(ATTR_KEY);
      return null;
    }

    return parsed;
  } catch (error) {
    return null;
  }
}

async function writeAttribution(browser, signals, landingUrl, referrer, existing) {
  const fbclid = trimmed(signals.fbclid || existing?.fbclid || null);
  const fbc = trimmed(signals.fbc || existing?.fbc || null) || fbcFromFbclid(fbclid);
  const payload = {
    ...(existing || {}),
    ...signals,
    landing_url: landingUrl || existing?.landing_url || null,
    referrer: referrer || existing?.referrer || null,
    expires_at: Date.now() + (WINDOW_DAYS * 24 * 60 * 60 * 1000),
    signature: signature(signals),
    fbclid: fbclid || null,
    fbc: fbc || null,
    fbp: trimmed(signals.fbp || existing?.fbp || null) || null,
    session_key: existing?.session_key || randomId('pixel-session'),
    client_id: existing?.client_id || randomId('pixel-client'),
  };

  try {
    await browser.localStorage.setItem(ATTR_KEY, JSON.stringify(payload));
  } catch (error) {
  }

  return payload;
}

async function isDuplicate(browser, key, ttlMs) {
  try {
    const storageKey = DEDUPE_PREFIX + key;
    const raw = await browser.localStorage.getItem(storageKey);
    const sentAt = Number(raw || 0);
    if (sentAt > 0 && (Date.now() - sentAt) < ttlMs) {
      return true;
    }
    await browser.localStorage.setItem(storageKey, String(Date.now()));
    return false;
  } catch (error) {
    return false;
  }
}

async function postEvent(endpoint, payload) {
  try {
    await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      credentials: 'include',
      body: JSON.stringify(compactObject(payload)),
    });
  } catch (error) {
  }
}

function signalsFromUrl(url) {
  if (!url) {
    return {};
  }

  try {
    const parsed = new URL(url);
    const result = {};
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
    ].forEach((key) => {
      const value = trimmed(parsed.searchParams.get(key));
      if (value) {
        result[key] = value;
      }
    });
    return result;
  } catch (error) {
    return {};
  }
}

function metaSignalsFromEvent(event) {
  const cookieValue = trimmed(event?.context?.document?.cookie || '');
  if (!cookieValue) {
    return {};
  }

  const output = {};
  cookieValue.split(';').forEach((segment) => {
    const [keyRaw, ...rest] = segment.split('=');
    const key = trimmed(keyRaw);
    const value = trimmed(rest.join('='));
    if (!key || !value) {
      return;
    }

    if (key === '_fbc') {
      output.fbc = value;
    }
    if (key === '_fbp') {
      output.fbp = value;
    }
  });

  return output;
}

function hasPersistableSignals(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }

  return ['utm_campaign', 'utm_source', 'fbclid', 'fbc', 'fbp', 'mf_campaign_id', 'mf_delivery_id', 'mf_channel', 'mf_template_key'].some((key) => Boolean(trimmed(value[key])));
}

function signature(signals) {
  return [
    trimmed(signals.utm_campaign),
    trimmed(signals.mf_campaign_id),
    trimmed(signals.mf_delivery_id),
    trimmed(signals.mf_template_key),
    trimmed(signals.mf_channel),
    trimmed(signals.fbclid),
    trimmed(signals.fbc),
    trimmed(signals.fbp)
  ].filter(Boolean).join(':') || 'baseline';
}

function baselineAttribution(signals, existing) {
  const fbclid = trimmed(signals?.fbclid || existing?.fbclid || null);
  const fbc = trimmed(signals?.fbc || existing?.fbc || null) || fbcFromFbclid(fbclid);

  return compactObject({
    ...(existing || {}),
    ...signals,
    signature: signature(signals || existing || {}),
    fbclid: fbclid || null,
    fbc: fbc || null,
    fbp: trimmed(signals?.fbp || existing?.fbp || null) || null,
    session_key: existing?.session_key || randomId('pixel-session'),
    client_id: existing?.client_id || randomId('pixel-client'),
    landing_url: existing?.landing_url || null,
    referrer: existing?.referrer || null,
  });
}

function fbcFromFbclid(fbclid) {
  const value = trimmed(fbclid);
  if (!value) {
    return null;
  }

  return `fb.1.${Math.floor(Date.now() / 1000)}.${value}`;
}

function normalizeShopifyId(value) {
  const text = trimmed(value);
  if (!text) {
    return null;
  }

  const match = text.match(/\/(\d+)$/);
  return match && match[1] ? match[1] : text;
}

function safeQuantity(value) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? Math.round(number) : null;
}

function toCents(value) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? Math.round(number * 100) : null;
}

function compactObject(input) {
  const output = {};
  Object.entries(input || {}).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') {
      return;
    }
    if (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0) {
      return;
    }
    output[key] = value;
  });
  return output;
}

function trimmed(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function randomId(prefix) {
  return `${prefix}:${Math.random().toString(36).slice(2)}:${Date.now().toString(36)}`;
}
