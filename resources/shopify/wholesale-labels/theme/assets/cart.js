/* Modern Forestry — Cart (Trade theme)
   ✅ Features
   - Line-based cart updates via /cart/change.js (line=<1-based index>)
   - Uses REAL section instance IDs from:
       #main-cart-items[data-id] and #main-cart-footer[data-id]
   - Smooth refresh (no blink) using HTMLUpdateUtility.viewTransition when available
   - Preserves scroll position (keeps edited line item in same viewport spot)
   - Subtle "updating" state during refresh
   - Discount Progress Bar (WHOLESALE/PAID-TIER DRIVEN — qualify by what they pay):
       Pay <  $100  -> 20% tier (default)
       Pay $100-$299 -> 30% tier
       Pay $300+     -> 50% tier (max)
     - Tier qualification + progress are based on PAID amount (data-paid-cents / cart.total_price)
     - Savings text uses actual Shopify totals (data-savings-cents / data-pre-cents)
     - Confetti/fireworks ONCE when max tier reached
     - FIXES “reverts to default” by observing footer mutations + re-initting

   Notes:
   - Trade’s QuantityInput (global.js) dispatches bubbled "change" on +/- clicks.
   - Liquid should provide (in sections/main-cart-footer.liquid):
       data-pre-cents="{{ cart.original_total_price }}"
       data-savings-cents="{{ cart.total_discount }}"
       data-paid-cents="{{ cart.total_price }}"
       data-tiers='[
         {"min":0,"discount":20,"label":"20% under $100"},
         {"min":10000,"discount":30,"label":"30% $100–$299"},
         {"min":30000,"discount":50,"label":"Max 50% $300+"}
       ]'
     IMPORTANT: In this PAID-based mode, tier `min` is interpreted as MINIMUM PAID cents.
*/

(function () {
  'use strict';

  // ---------------- Config ----------------
  const CART_CHANGE_URL = '/cart/change.js';
  const CART_JSON_URL = '/cart.js';
  const STATIC_SECTION_IDS = ['cart-icon-bubble', 'cart-drawer'];
  const UPDATING_CLASS = 'mf-cart-updating';

  // ---------------- Utils ----------------
  const enc = (obj) =>
    Object.entries(obj)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
      .join('&');

  function fmtInt(v, fallback = 0) {
    const n = Number(v);
    return Number.isFinite(n) ? n : fallback;
  }

  function parseHTML(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp;
  }

  function firstElementFromHTML(html) {
    const tmp = parseHTML(html);
    return tmp.firstElementChild;
  }

  function normalizeQty(input, raw) {
    const step = fmtInt(input.dataset.step || input.getAttribute('step') || 1, 1);
    const min = fmtInt(input.dataset.min || input.getAttribute('min') || 0, 0);
    let n = fmtInt(raw, 0);

    if (n === 0) return 0; // allow explicit remove
    if (n < min) n = min;

    // Round UP to step
    if (step > 1) {
      const r = n % step;
      if (r) n += step - r;
    }
    return n;
  }

  function ensureUpdatingStyles() {
    if (document.getElementById('mf-cart-updating-style')) return;

    const style = document.createElement('style');
    style.id = 'mf-cart-updating-style';
    style.textContent = `
      body.${UPDATING_CLASS} #main-cart-items,
      body.${UPDATING_CLASS} #main-cart-footer {
        pointer-events: none;
        opacity: 0.85;
        transition: opacity 160ms ease;
      }
    `;
    document.head.appendChild(style);
  }

  function showInlineError(input, msg) {
    const row = input?.closest?.('tr.cart-item');
    const errText = row?.querySelector?.('.cart-item__error-text');
    if (!errText) return;
    errText.textContent = msg;
    setTimeout(() => {
      if (errText.textContent === msg) errText.textContent = '';
    }, 2500);
  }

  function smoothSwap(oldNode, newNode) {
    if (!oldNode || !newNode) return;

    const h = oldNode.getBoundingClientRect?.().height;
    if (h) oldNode.style.minHeight = `${h}px`;

    if (window.HTMLUpdateUtility && typeof window.HTMLUpdateUtility.viewTransition === 'function') {
      window.HTMLUpdateUtility.viewTransition(oldNode, newNode);
      return;
    }

    oldNode.style.transition = 'opacity 160ms ease';
    oldNode.style.opacity = '0';
    setTimeout(() => {
      oldNode.replaceWith(newNode);
    }, 160);
  }

  function getInstanceSectionIds() {
    const itemsInstanceId = document.querySelector('#main-cart-items')?.dataset?.id;
    const footerInstanceId = document.querySelector('#main-cart-footer')?.dataset?.id;
    return Array.from(new Set([itemsInstanceId, footerInstanceId, ...STATIC_SECTION_IDS].filter(Boolean)));
  }

  function sectionsUrl() {
    const ids = getInstanceSectionIds();
    return `${location.pathname}?sections=${encodeURIComponent(ids.join(','))}`;
  }

  function captureScrollAnchor(line, inputEl) {
    return {
      line,
      scrollY: window.scrollY,
      anchorTop: inputEl?.getBoundingClientRect ? inputEl.getBoundingClientRect().top : null,
    };
  }

  function restoreScrollAnchor(anchor) {
    if (!anchor) return;

    if (anchor.anchorTop == null) {
      window.scrollTo({ top: anchor.scrollY, left: window.scrollX, behavior: 'auto' });
      return;
    }

    const freshInput = document.querySelector(`input.quantity__input[data-index="${anchor.line}"]`);
    if (!freshInput || !freshInput.getBoundingClientRect) {
      window.scrollTo({ top: anchor.scrollY, left: window.scrollX, behavior: 'auto' });
      return;
    }

    const newTop = freshInput.getBoundingClientRect().top;
    const delta = newTop - anchor.anchorTop;

    window.scrollTo({
      top: window.scrollY + delta,
      left: window.scrollX,
      behavior: 'auto',
    });
  }

  // ---------------- Discount Progress (PAID/WHOLESALE TIER DRIVEN) ----------------
  function mfClamp(v, min, max) {
    return Math.min(max, Math.max(min, v));
  }

  function mfFormatMoney(cents) {
    const n = Number(cents) || 0;

    if (window.Shopify && typeof window.Shopify.formatMoney === 'function') {
      const fmt = window.money_format || window.Shopify.money_format || '${{amount}}';
      try {
        return window.Shopify.formatMoney(n, fmt);
      } catch (e) {}
    }

    const currency =
      (window.Shopify && window.Shopify.currency && window.Shopify.currency.active) || 'USD';

    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(n / 100);
  }

  function mfConfettiAndFireworks() {
    const wrap = document.createElement('div');
    wrap.className = 'mf-confetti';

    const colors = [
      'rgba(0,0,0,0.85)',
      'rgba(0,0,0,0.55)',
      'rgba(255,255,255,0.9)',
      'rgba(255,255,255,0.6)',
    ];

    for (let i = 0; i < 48; i++) {
      const p = document.createElement('div');
      p.className = 'mf-confetti__piece';
      p.style.left = `${Math.random() * 100}vw`;
      p.style.top = `${-10 - Math.random() * 30}vh`;
      p.style.background = colors[Math.floor(Math.random() * colors.length)];
      p.style.animationDelay = `${Math.random() * 160}ms`;
      p.style.transform = `rotate(${Math.random() * 360}deg)`;
      p.style.width = `${6 + Math.random() * 6}px`;
      p.style.height = `${10 + Math.random() * 10}px`;
      wrap.appendChild(p);
    }

    document.body.appendChild(wrap);
    setTimeout(() => wrap.remove(), 1600);

    const fw = document.createElement('div');
    fw.className = 'mf-firework';
    fw.style.transform = `translateX(${(Math.random() * 40 - 20)}vw) translateY(${(Math.random() * 10 - 5)}vh)`;
    document.body.appendChild(fw);
    setTimeout(() => fw.remove(), 900);
  }

  // Backfill missing dataset values from /cart.js (once per element swap)
  async function mfBackfillDiscountDatasetFromCart(el) {
    if (!el || el.__mfBackfilledFromCart) return false;
    el.__mfBackfilledFromCart = true;

    try {
      const resp = await fetch(CART_JSON_URL, { credentials: 'same-origin' });
      if (!resp.ok) return false;
      const cart = await resp.json();

      const original = Number(cart?.original_total_price || 0);
      const discount = Number(cart?.total_discount || 0);
      const paid = Number(cart?.total_price || 0);

      if (Number.isFinite(original) && Number.isFinite(discount)) {
        el.dataset.preCents = String(original);
        el.dataset.savingsCents = String(discount);
        el.dataset.paidCents = String(Number.isFinite(paid) ? paid : Math.max(0, original - discount));
        return true;
      }
    } catch (e) {}
    return false;
  }

function initDiscountProgress() {
  const el = document.querySelector('.mf-discount-progress');
  if (!el) return;

  const preCents = Number(el.dataset.preCents || 0);         // retail subtotal (pre-discount)
  const savingsCents = Number(el.dataset.savingsCents || 0); // actual discount applied
  const paidCentsActual = Number(el.dataset.paidCents || 0); // actual paid (optional; for display sanity)

  const invalid =
    !Number.isFinite(preCents) ||
    !Number.isFinite(savingsCents) ||
    preCents <= 0;

  if (invalid) {
    mfBackfillDiscountDatasetFromCart(el).then((did) => {
      if (did) scheduleDiscountInit();
    });
    return;
  }

  // Truthful savings display (based on actual Shopify discount)
  const pctSaved = preCents > 0 ? (savingsCents / preCents) * 100 : 0;

  // Parse tiers:
  // IMPORTANT: `min` means "minimum customer pays AFTER that tier discount"
  let tiers = [];
  try {
    tiers = JSON.parse(el.dataset.tiers || '[]');
  } catch (e) {
    tiers = [];
  }

  if (!Array.isArray(tiers) || !tiers.length) {
    tiers = [
      { min: 0, discount: 20, label: '20% under $100' },
      { min: 10000, discount: 30, label: '30% $100–$299' },
      { min: 30000, discount: 50, label: 'Max 50% $300+' },
    ];
  }

  tiers = tiers
    .map((t) => ({
      min: Number(t.min || 0),                 // min PAID after discount (cents)
      discount: Number(t.discount || 0),       // tier percent
      label: t.label || `${t.discount}%`,
    }))
    .sort((a, b) => a.min - b.min);

  // Helper: projected paid if THIS tier were applied to current retail subtotal
  const projectedPaidAt = (tier) => {
    const mult = (100 - tier.discount) / 100;
    // round to cents
    return Math.round(preCents * mult);
  };

  // Determine current tier based on projected paid at each tier
  // (stable: doesn't depend on current discount)
  let currentIndex = 0;
  for (let i = 0; i < tiers.length; i++) {
    const proj = projectedPaidAt(tiers[i]);
    if (proj >= tiers[i].min) currentIndex = i;
  }

  const currentTier = tiers[currentIndex];
  const nextTier = tiers[currentIndex + 1] || null;

  // Progress should be based on how close retail subtotal is to satisfying next tier.
  // Compute the retail subtotal required to hit a given tier's PAID minimum:
  const requiredPreForTier = (tier) => {
    const mult = (100 - tier.discount) / 100;
    if (mult <= 0) return Infinity;
    // ceil division to cents
    return Math.ceil(tier.min / mult);
  };

  const currentReqPre = requiredPreForTier(currentTier) || 0;
  const nextReqPre = nextTier ? requiredPreForTier(nextTier) : null;

  let progress = 100;
  if (nextTier && Number.isFinite(nextReqPre)) {
    const span = nextReqPre - currentReqPre;
    const into = preCents - currentReqPre;
    progress = span > 0 ? mfClamp((into / span) * 100, 0, 100) : 0;
  }

  const valueEl = el.querySelector('.mf-discount-progress__value');
  const fillEl = el.querySelector('.mf-discount-progress__fill');
  const ticksEl = el.querySelector('.mf-discount-progress__ticks');
  const nextEl = el.querySelector('.mf-discount-progress__next');
  const barEl = el.querySelector('.mf-discount-progress__bar');

  if (valueEl) valueEl.textContent = `Saving ${mfFormatMoney(savingsCents)} (${pctSaved.toFixed(1)}%)`;

  if (fillEl) fillEl.style.width = `${progress}%`;
  if (barEl) barEl.setAttribute('aria-valuenow', String(Math.round(progress)));

  // Ticks: mark tiers hit based on projected eligibility
  if (ticksEl) {
    ticksEl.innerHTML = '';
    ticksEl.style.gridTemplateColumns = `repeat(${tiers.length}, 1fr)`;

    tiers.forEach((t, idx) => {
      const hit = idx <= currentIndex;
      const tick = document.createElement('div');
      tick.className = `mf-discount-progress__tick${hit ? ' is-hit' : ''}`;
      tick.innerHTML = `
        <span class="mf-discount-progress__dot"></span>
        <span>${t.label}</span>
      `;
      ticksEl.appendChild(tick);
    });
  }

  // Next-tier helper:
  // Show remaining retail dollars needed so that AFTER next tier discount they pay its min.
  if (nextEl) {
    if (!nextTier) {
      nextEl.textContent = `You’ve unlocked the maximum discount.`;
    } else {
      const needPre = requiredPreForTier(nextTier);
      const remainingPre = Math.max(0, needPre - preCents);

      if (remainingPre === 0) {
        nextEl.textContent = `You’ve unlocked the ${currentTier.discount}% discount.`;
      } else {
        // Clear wording: we’re targeting what they will pay after the next tier discount
        nextEl.textContent =
          `Add ${mfFormatMoney(remainingPre)} more to unlock ${nextTier.discount}% (pay ${mfFormatMoney(nextTier.min)}+ after discount).`;
      }
    }
  }

  const isMax = currentIndex === tiers.length - 1;
  el.classList.toggle('is-max', isMax);

  // Celebrate once per cart state
  if (isMax) {
    const key = `mf_max_projected_${preCents}_${savingsCents}_${currentTier.discount}`;
    if (!sessionStorage.getItem(key)) {
      sessionStorage.setItem(key, '1');
      mfConfettiAndFireworks();
    }
  }
}


  // Re-init scheduling
  let mfProgressInitScheduled = false;

  function scheduleDiscountInit() {
    if (mfProgressInitScheduled) return;
    mfProgressInitScheduled = true;
    requestAnimationFrame(() => {
      mfProgressInitScheduled = false;
      initDiscountProgress();
    });
  }

  function observeDiscountProgress() {
    const footer = document.getElementById('main-cart-footer');
    if (!footer || footer.__mfProgressObserved) return;
    footer.__mfProgressObserved = true;

    const obs = new MutationObserver(() => {
      scheduleDiscountInit();
    });

    obs.observe(footer, { childList: true, subtree: true });
  }

  // ---------------- Network ----------------
  let mutationQueue = Promise.resolve();
  function changeLine(lineIndex, quantity, input) {
    const work = mutationQueue.then(() => changeCurrentLine(lineIndex, quantity, input));
    mutationQueue = work.catch(() => {});
    return work;
  }
  async function changeCurrentLine(lineIndex, quantity, input) {
    const current = await fetch(CART_JSON_URL, {credentials:'same-origin'}).then(r=>r.json());
    const key = input?.dataset.lineKey || input?.closest('quantity-input')?.dataset.lineItemKey;
    let item = current.items.find(item=>item.key===key);
    if (!item && input?.dataset.quantityVariantId) {
      const properties = JSON.parse(input.closest('quantity-input')?.dataset.properties || '{}');
      const normalize = value => JSON.stringify(Object.entries(value || {}).sort(([a],[b])=>a.localeCompare(b)));
      const matches = current.items.filter(line => String(line.variant_id) === input.dataset.quantityVariantId && normalize(line.properties) === normalize(properties));
      if (matches.length === 1) item = matches[0];
    }
    if (!item) throw new Error('Your cart changed. Refresh before editing this item.');
    const resp = await fetch(CART_CHANGE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: enc({ id: item.key, quantity }),
      credentials: 'same-origin',
    });

    if (!resp.ok) {
      const text = await resp.text().catch(() => '');
      throw new Error(`change.js ${resp.status} ${text || ''}`.trim());
    }
    return resp.json();
  }

  async function refreshSections() {
    const resp = await fetch(sectionsUrl(), { credentials: 'same-origin' });
    if (!resp.ok) throw new Error(`sections ${resp.status}`);
    const map = await resp.json();

    const itemsInstanceId = document.querySelector('#main-cart-items')?.dataset?.id;
    const footerInstanceId = document.querySelector('#main-cart-footer')?.dataset?.id;

    // Items (.js-contents)
    if (itemsInstanceId && map[itemsInstanceId]) {
      const current = document.querySelector('#main-cart-items .js-contents');
      if (current) {
        const tmp = parseHTML(map[itemsInstanceId]);
        const fresh = tmp.querySelector('#main-cart-items .js-contents');
        if (fresh) smoothSwap(current, fresh);
      }
    }

    // Footer (.js-contents)
    if (footerInstanceId && map[footerInstanceId]) {
      const current = document.querySelector('#main-cart-footer .js-contents');
      if (current) {
        const tmp = parseHTML(map[footerInstanceId]);
        const fresh = tmp.querySelector('#main-cart-footer .js-contents');
        if (fresh) smoothSwap(current, fresh);
      }
    }

    // Bubble / Drawer (optional)
    if (map['cart-icon-bubble']) {
      const bubbleExisting =
        document.querySelector('cart-icon-bubble') ||
        document.getElementById('cart-icon-bubble') ||
        document.querySelector('.shopify-section#cart-icon-bubble');

      const bubbleFresh = firstElementFromHTML(map['cart-icon-bubble']);

      if (bubbleExisting && bubbleFresh) smoothSwap(bubbleExisting, bubbleFresh);
      else if (bubbleExisting) bubbleExisting.outerHTML = map['cart-icon-bubble'];
    }

    if (map['cart-drawer']) {
      const drawerExisting = document.querySelector('cart-drawer');
      const drawerFresh = firstElementFromHTML(map['cart-drawer']);

      if (drawerExisting && drawerFresh) smoothSwap(drawerExisting, drawerFresh);
      else if (drawerExisting) drawerExisting.outerHTML = map['cart-drawer'];
    }

    // Re-init no matter what
    scheduleDiscountInit();
    observeDiscountProgress();
  }

  // ---------------- Commit logic (queue per line) ----------------
  const inFlightByLine = new Map();
  const queuedQtyByLine = new Map();

  async function commitLineQty(line, inputForErrors, qty) {
    if (inFlightByLine.has(line)) {
      queuedQtyByLine.set(line, qty);
      return inFlightByLine.get(line);
    }

    const anchor = captureScrollAnchor(line, inputForErrors);

    const p = (async () => {
      document.body.classList.add(UPDATING_CLASS);

      try {
        await changeLine(line, qty, inputForErrors);
        await refreshSections();

        requestAnimationFrame(() => {
          restoreScrollAnchor(anchor);
          requestAnimationFrame(() => restoreScrollAnchor(anchor));
        });

        scheduleDiscountInit();
      } catch (err) {
        console.error('[cart] update failed:', err);
        showInlineError(inputForErrors, 'Could not update cart. Please try again.');
      } finally {
        document.body.classList.remove(UPDATING_CLASS);
        inFlightByLine.delete(line);
      }

      if (queuedQtyByLine.has(line)) {
        const nextQty = queuedQtyByLine.get(line);
        queuedQtyByLine.delete(line);
        return commitLineQty(line, inputForErrors, nextQty);
      }
    })();

    inFlightByLine.set(line, p);
    return p;
  }

  function commitFromInput(input) {
    const line = fmtInt(input.dataset.index, 0);
    if (!line) return;

    const qty = normalizeQty(input, input.value);
    input.value = qty;

    if (!Number.isFinite(qty) || qty < 0) return;
    commitLineQty(line, input, qty);
  }

  // ---------------- Handlers ----------------
  function onQtyChange(e) {
    const input = e.target.closest?.('input.quantity__input');
    if (!input || !input.closest('#main-cart-items, cart-drawer')) return;
    commitFromInput(input);
  }

  function onRemove(e) {
    const removeLink = e.target.closest?.('cart-remove-button a[href]');
    if (!removeLink) return;

    const href = removeLink.getAttribute('href') || '';
    if (!href.includes('change')) return;

    e.preventDefault();

    const row = removeLink.closest('tr.cart-item');
    const input = row?.querySelector('input.quantity__input');
    const lineFromInput = input ? fmtInt(input.dataset.index, 0) : 0;

    const m = href.match(/[?&]line=(\d+)/);
    const lineFromHref = m ? fmtInt(m[1], 0) : 0;

    const line = lineFromInput || lineFromHref;
    if (!line) return;

    commitLineQty(line, input || removeLink, 0);
  }

  // ---------------- Wiring ----------------
  let wired = false;

  function wire() {
    if (wired) return;
    wired = true;

    ensureUpdatingStyles();

    document.addEventListener('change', onQtyChange);
    document.addEventListener('click', onRemove);
    document.addEventListener('mf:cart-changed', () => { refreshSections().then(scheduleDiscountInit).catch(error => console.error('[cart] refresh failed', error)); });

    observeDiscountProgress();
    scheduleDiscountInit();

    console.log('[cart] Modern Forestry Trade cart override wired (smooth + scroll + WHOLESALE/PAID tiers + observer)');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }
})();
