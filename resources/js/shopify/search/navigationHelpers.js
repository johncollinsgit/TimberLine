const EMBEDDED_KEYS = [
  "embedded",
  "host",
  "shop",
  "hmac",
  "timestamp",
  "id_token",
  "locale",
  "session",
];

export function appendEmbeddedContext(url, baseQuery = window.location.search) {
  const target = new URL(url, window.location.origin);
  const source = new URLSearchParams(baseQuery || "");

  EMBEDDED_KEYS.forEach((key) => {
    if (!source.has(key) || target.searchParams.has(key)) {
      return;
    }

    const value = source.get(key);
    if (value !== null && value !== "") {
      target.searchParams.set(key, value);
    }
  });

  if (target.origin === window.location.origin) {
    return `${target.pathname}${target.search}${target.hash}`;
  }

  return target.toString();
}

export function navigateTo(url) {
  const destination = String(url || "").trim();
  if (destination === "") {
    return;
  }

  try {
    if (window.top && window.top !== window) {
      window.top.location.assign(destination);
      return;
    }
  } catch (_error) {
    // Cross-origin frames can throw when checking top.
  }

  window.location.assign(destination);
}

export function adminShopifyUrl(shopDomain, path, query = {}) {
  const normalizedShop = String(shopDomain || "").trim();
  if (normalizedShop === "") {
    return "";
  }

  const target = new URL(`https://${normalizedShop}${path.startsWith("/") ? path : `/${path}`}`);
  Object.entries(query).forEach(([key, value]) => {
    if (value === null || value === undefined || value === "") {
      return;
    }

    target.searchParams.set(key, String(value));
  });

  return target.toString();
}

export function createNavigateAction(rawUrl, { appendContext = true, baseQuery } = {}) {
  const normalizedUrl = String(rawUrl || "").trim();

  return () => {
    const destination = appendContext
      ? appendEmbeddedContext(normalizedUrl, baseQuery)
      : normalizedUrl;

    navigateTo(destination);
  };
}
