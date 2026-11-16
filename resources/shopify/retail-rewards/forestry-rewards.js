(function () {
  const ROOT_SELECTOR = '[data-forestry-rewards-root]';
  const RUNTIME_KEY = '__forestryRewardsRuntime';
  const STATUS_REQUEST_TIMEOUT_MS = 8000;
  const CART_DISCOUNT_SYNC_TIMEOUT_MS = 2500;
  const CART_DISCOUNT_SYNC_POLL_MS = 200;
  const runtime = window[RUNTIME_KEY] || {
    mounted: new Set(),
    state: new WeakMap(),
  };

  runtime.mounted = runtime.mounted || new Set();
  runtime.state = runtime.state || new WeakMap();
  window[RUNTIME_KEY] = runtime;

  function cleanString(value) {
    return value == null ? '' : String(value).trim();
  }

  function rewardsLoginUrl(root) {
    // New customer accounts use this storefront endpoint for a return-to-store
    // handoff. `routes.account_login_url` opens the account dashboard and
    // ignores `return_url`, which leaves rewards looking like a guest session.
    const destination = '/pages/rewards';
    return '/customer_authentication/login?return_to=' + encodeURIComponent(destination);
  }

  function escapeHtml(value) {
    return cleanString(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeState(value) {
    return cleanString(value).toLowerCase().replace(/[\s-]+/g, '_');
  }

  function positiveInt(value) {
    const parsed = Number.parseInt(String(value || ''), 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }

  function bool(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
  }

  function currencyLabel(value) {
    const parsed = Number.parseFloat(value);
    if (!Number.isFinite(parsed)) {
      return '';
    }

    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
      maximumFractionDigits: parsed % 1 === 0 ? 0 : 2,
    }).format(parsed);
  }

  function shortDate(value) {
    if (!value) {
      return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return '';
    }

    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }).format(date);
  }

  function titleCaseSlug(value) {
    return cleanString(value)
      .split(/[_\s-]+/)
      .filter(Boolean)
      .map(function (part) {
        return part.charAt(0).toUpperCase() + part.slice(1);
      })
      .join(' ');
  }

  function formatBalance(value) {
    const parsed = Number.parseInt(String(value || 0), 10);
    return Number.isFinite(parsed) ? parsed.toLocaleString('en-US') : '0';
  }

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function emptyModel() {
    return {
      profileId: null,
      state: 'unknown_customer',
      consentSms: false,
      consentEmail: false,
      balance: 0,
      balanceAmount: 0,
      birthdayState: 'unavailable',
      birthday: null,
      birthdayIssuance: null,
      otherRewards: [],
      rewardCodes: [],
      copy: {},
      summary: {},
      referral: null,
      tasks: [],
      taskHistory: [],
      ledgerHistory: [],
      cartAppliedBirthday: false,
      cartDiscounts: [],
    };
  }

  function buildIdentity(root) {
    return {
      marketing_profile_id: positiveInt(root.dataset.marketingProfileId),
      email: cleanString(root.dataset.customerEmail),
      phone: cleanString(root.dataset.customerPhone),
      shopify_customer_id: cleanString(root.dataset.shopifyCustomerId),
      first_name: cleanString(root.dataset.customerFirstName),
      last_name: cleanString(root.dataset.customerLastName),
    };
  }

  function bodyFromIdentity(identity) {
    const body = {};

    if (identity.marketing_profile_id) {
      body.marketing_profile_id = identity.marketing_profile_id;
    }
    if (identity.email) {
      body.email = identity.email;
    }
    if (identity.phone) {
      body.phone = identity.phone;
    }
    if (identity.shopify_customer_id) {
      body.shopify_customer_id = identity.shopify_customer_id;
    }
    if (identity.first_name) {
      body.first_name = identity.first_name;
    }
    if (identity.last_name) {
      body.last_name = identity.last_name;
    }

    return body;
  }

  function queryFromIdentity(identity) {
    const query = new URLSearchParams();

    if (identity.marketing_profile_id) {
      query.set('marketing_profile_id', String(identity.marketing_profile_id));
    }
    if (identity.email) {
      query.set('email', identity.email);
    }
    if (identity.phone) {
      query.set('phone', identity.phone);
    }
    if (identity.shopify_customer_id) {
      query.set('shopify_customer_id', identity.shopify_customer_id);
    }

    return query;
  }

  function rootState(root) {
    return runtime.state.get(root) || {
      busy: false,
      formOpen: false,
      openTaskHandle: '',
      toast: '',
      toastTone: 'neutral',
    };
  }

  function setRootState(root, patch) {
    const next = Object.assign({}, rootState(root), patch || {});
    runtime.state.set(root, next);
    return next;
  }

  function computeLastModel(root) {
    return root.__forestryLastModel || emptyModel();
  }

  function storageSafe() {
    try {
      const key = '__forestryRewardsStorage';
      window.localStorage.setItem(key, '1');
      window.localStorage.removeItem(key);
      return true;
    } catch (error) {
      return false;
    }
  }

  function referralStorageKey() {
    return 'forestryRewardsReferralCode';
  }

  function readStoredReferralCode() {
    if (!storageSafe()) {
      return '';
    }

    return cleanString(window.localStorage.getItem(referralStorageKey()));
  }

  function writeStoredReferralCode(code) {
    if (!storageSafe()) {
      return;
    }

    if (cleanString(code) === '') {
      window.localStorage.removeItem(referralStorageKey());
      return;
    }

    window.localStorage.setItem(referralStorageKey(), cleanString(code));
  }

  function activeReferralCode() {
    const params = new URLSearchParams(window.location.search || '');
    const fromUrl = cleanString(params.get('ref'));
    if (fromUrl) {
      writeStoredReferralCode(fromUrl);
      return fromUrl;
    }

    return readStoredReferralCode();
  }

  function requestedTaskHandleFromUrl() {
    const params = new URLSearchParams(window.location.search || '');
    return cleanString(params.get('task'));
  }

  function missingStorefrontIdentityAfterShopifyLogin(root) {
    const params = new URLSearchParams(window.location.search || '');
    return params.get('shop_sign_in') === 'true' &&
      !cleanString(root && root.dataset && root.dataset.customerEmail) &&
      !cleanString(root && root.dataset && root.dataset.shopifyCustomerId);
  }

  async function fetchContract(root, endpoint, options) {
    options = options || {};
    const method = cleanString(options && options.method).toUpperCase() || 'GET';
    const url = new URL(endpoint, window.location.origin);
    const init = {
      method: method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
      },
    };
    const timeoutMs = positiveInt(options.timeoutMs);
    const controller = timeoutMs && typeof AbortController === 'function' ? new AbortController() : null;
    let timeoutId = null;

    if (options && options.query) {
      options.query.forEach(function (value, key) {
        if (cleanString(value) !== '') {
          url.searchParams.set(key, value);
        }
      });
    }

    if (method !== 'GET' && options && options.body) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }

    if (controller) {
      init.signal = controller.signal;
      timeoutId = window.setTimeout(function () {
        controller.abort();
      }, timeoutMs);
    }

    try {
      const response = await fetch(url.toString(), init);
      const text = await response.text();
      let payload = null;

      if (text) {
        try {
          payload = JSON.parse(text);
        } catch (error) {
          payload = null;
        }
      }

      if (response.ok && payload && payload.ok) {
        return {
          ok: true,
          status: response.status,
          data: payload.data || {},
          meta: payload.meta || {},
          error: null,
        };
      }

      const errorPayload = (payload && payload.error) || {};
      return {
        ok: false,
        status: response.status,
        data: payload && payload.data ? payload.data : {},
        meta: payload && payload.meta ? payload.meta : {},
        error: {
          code: errorPayload.code || (response.status === 404 ? 'proxy_not_available' : 'request_failed'),
          message: errorPayload.message || (response.status === 404 ? 'Rewards are still connecting.' : 'The request could not be completed.'),
          details: errorPayload.details || {},
        },
      };
    } catch (error) {
      return {
        ok: false,
        status: 0,
        data: {},
        meta: {},
        error: {
          code: error && error.name === 'AbortError' ? 'request_timeout' : 'network_error',
          message: error && error.message ? error.message : 'Network request failed.',
          details: {},
        },
      };
    } finally {
      if (timeoutId) {
        window.clearTimeout(timeoutId);
      }
    }
  }

  async function fetchCartState() {
    try {
      const response = await fetch('/cart.js', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        return { ok: false, data: {} };
      }

      return { ok: true, data: await response.json() };
    } catch (error) {
      return { ok: false, data: {} };
    }
  }

  async function persistReferralCodeToCart() {
    const referralCode = activeReferralCode();
    if (!referralCode) {
      return;
    }

    const cartState = await fetchCartState();
    const attributes = Object.assign({}, (cartState.data && cartState.data.attributes) || {});
    if (cleanString(attributes.forestry_referral_code) === referralCode) {
      return;
    }

    attributes.forestry_referral_code = referralCode;

    try {
      await fetch('/cart/update.js', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ attributes: attributes }),
      });
    } catch (error) {
      // Ignore cart attribute sync failures. The referral code still persists locally.
    }
  }

  function getApplyMarker() {
    try {
      const raw = sessionStorage.getItem('forestryRewardsApply');
      return raw ? JSON.parse(raw) : null;
    } catch (error) {
      return null;
    }
  }

  function setApplyMarker(marker) {
    try {
      sessionStorage.setItem('forestryRewardsApply', JSON.stringify(marker));
    } catch (error) {
      // ignore
    }
  }

  function clearApplyMarker() {
    try {
      sessionStorage.removeItem('forestryRewardsApply');
    } catch (error) {
      // ignore
    }
  }

  function oncePerSession(key) {
    try {
      if (sessionStorage.getItem(key)) {
        return false;
      }
      sessionStorage.setItem(key, '1');
      return true;
    } catch (error) {
      return true;
    }
  }

  function badge(text, tone) {
    return '<span class="ForestryRewardsBadge ForestryRewardsBadge--' + tone + '">' + escapeHtml(text) + '</span>';
  }

  async function logRewardEvent(root, payload) {
    const endpoint = root.dataset.endpointRewardsEvent;
    if (!endpoint) {
      return;
    }

    const identity = buildIdentity(root);
    await fetchContract(root, endpoint, {
      method: 'POST',
      body: Object.assign({}, bodyFromIdentity(identity), payload || {}),
    });
  }

  function logLivenessEvent(root, eventType, source, state) {
    const surface = root.dataset.surface || 'page';
    const requestKey = ['forestryRewardsLiveness', eventType, window.location.pathname, surface, source].join(':');
    if (!oncePerSession(requestKey)) {
      return;
    }

    logRewardEvent(root, {
      event_type: eventType,
      request_key: requestKey,
      reward_kind: 'surface',
      surface: surface,
      state: state || source || 'unknown',
    });
  }

  function primaryRewardCode(model) {
    const firstReward = model.otherRewards.find(function (reward) {
      return reward && reward.is_usable;
    });

    if (firstReward) {
      return firstReward;
    }

    return model.otherRewards[0] || null;
  }

  function appliedCandleCashReward(model) {
    const appliedTitles = matchingCartDiscountTitles(model.cartDiscounts || []);

    return model.otherRewards.find(function (reward) {
      const code = cleanString(reward && reward.redemption_code).toUpperCase();
      const title = cleanString(reward && reward.discount_title).toUpperCase();
      return (code && appliedTitles.indexOf(code) >= 0) || (title && appliedTitles.indexOf(title) >= 0);
    }) || primaryRewardCode(model);
  }

  function birthdayViewState(model) {
    const issuance = model.birthdayIssuance;

    if (!issuance) {
      if (model.birthdayState === 'add_birthday_unlock_reward') {
        return 'missing_birthday';
      }
      return 'unavailable';
    }

    if (issuance.is_redeemed) {
      return 'redeemed';
    }

    if (issuance.status === 'expired' || (issuance.expires_at && new Date(issuance.expires_at).getTime() < Date.now())) {
      return 'expired';
    }

    if (model.cartAppliedBirthday) {
      return 'applied';
    }

    if (issuance.is_usable) {
      return 'activated';
    }

    if (issuance.discount_sync_status === 'failed') {
      return 'sync_failed';
    }

    if (model.birthdayState === 'birthday_reward_ready' || model.birthdayState === 'already_claimed') {
      return issuance.is_usable ? 'activated' : 'available';
    }

    return 'unavailable';
  }

  function renderMoneySummary(otherRewards, birthdayIssuance) {
    let total = 0;

    if (birthdayIssuance && birthdayIssuance.is_usable) {
      const value = Number.parseFloat(birthdayIssuance.reward_value || '0');
      if (Number.isFinite(value)) {
        total += value;
      }
    }

    otherRewards.forEach(function (reward) {
      const value = Number.parseFloat((reward.reward && reward.reward.reward_value) || '0');
      if (Number.isFinite(value)) {
        total += value;
      }
    });

    return total > 0 ? currencyLabel(total.toFixed(2)) : null;
  }

  function rewardCardDescription(model, viewState) {
    if (viewState === 'missing_birthday') {
      return 'Save your birthday for your annual $10 coupon.';
    }
    if (viewState === 'available') {
      return 'Your birthday reward is ready. Activate it once, then use it at checkout in one calm click.';
    }
    if (viewState === 'activated') {
      return 'Activated and ready to travel with your cart.';
    }
    if (viewState === 'applied') {
      return 'We found it in your cart already, so you can head straight to checkout.';
    }
    if (viewState === 'redeemed') {
      return 'Used successfully and saved back into your rewards history.';
    }
    if (viewState === 'expired') {
      return 'This year\'s birthday reward has expired, but next year\'s will show up here when it is ready.';
    }
    if (viewState === 'sync_failed') {
      return 'We saved the reward, but Shopify still needs a moment to finish syncing the code.';
    }
    return 'Your annual birthday coupon will appear here when it is available.';
  }

  function helperMessage(model, viewState) {
    const primaryCode = primaryRewardCode(model);

    if (viewState === 'applied') {
      return 'Birthday reward is already attached. You can head straight to checkout.';
    }
    if (viewState === 'activated') {
      return 'Use your birthday reward now. We will carry it into the cart for you.';
    }
    if (viewState === 'available') {
      return 'Activate your birthday reward first, then we will make checkout easy.';
    }
    if (primaryCode && primaryCode.is_usable && primaryCode.apply_path) {
      return 'You have another Candle Cash code ready. We can carry it into your cart for you.';
    }
    if (model.otherRewards.length > 0) {
      return 'You also have saved reward codes waiting below. If Shopify only allows one code, start with the strongest one.';
    }
    return 'When a reward is ready, the easy button lives here.';
  }

  function summaryHeadline(model, viewState) {
    if (viewState === 'activated' || viewState === 'applied') {
      return 'Birthday reward is ready';
    }
    if (viewState === 'available') {
      return 'Your birthday reward is waiting';
    }
    if (model.otherRewards.length > 0) {
      return 'You have reward codes ready';
    }
    if (model.balance > 0) {
      return 'Your rewards are on standby';
    }
    return 'Rewards live here once they are ready';
  }

  function renderBirthdayMeta(model, viewState) {
    const issuance = model.birthdayIssuance;
    const parts = [];

    if (issuance && issuance.reward_value) {
      parts.push('<li><span>Value</span><strong>' + escapeHtml(currencyLabel(issuance.reward_value) || issuance.reward_value) + '</strong></li>');
    }
    if (issuance && issuance.expires_at) {
      parts.push('<li><span>Expires</span><strong>' + escapeHtml(shortDate(issuance.expires_at)) + '</strong></li>');
    }
    if ((viewState === 'activated' || viewState === 'applied') && issuance && issuance.reward_code) {
      parts.push('<li><span>Code</span><strong>' + escapeHtml(issuance.reward_code) + '</strong></li>');
    }
    if (viewState === 'redeemed' && issuance && issuance.order_number) {
      parts.push('<li><span>Used on</span><strong>' + escapeHtml(issuance.order_number) + '</strong></li>');
    }

    return parts.length ? '<ul class="ForestryRewardsMeta">' + parts.join('') + '</ul>' : '';
  }

  function birthdayActionMarkup(model, viewState, uiState) {
    const issuance = model.birthdayIssuance;
    const disabled = uiState.busy ? ' disabled aria-disabled="true"' : '';

    if (viewState === 'missing_birthday') {
      return '<button class="Button Button--secondary" type="button" data-action="toggle-birthday-form"' + disabled + '>Add Birthday</button>';
    }
    if (viewState === 'available') {
      return '<button class="Button Button--primary" type="button" data-action="activate-birthday"' + disabled + '>Activate Reward</button>';
    }
    if (viewState === 'activated' && issuance && issuance.apply_path) {
      return '<button class="Button Button--primary" type="button" data-action="apply-reward" data-reward-kind="birthday" data-code="' + escapeHtml(issuance.reward_code || '') + '" data-apply-path="' + escapeHtml(issuance.apply_path) + '"' + disabled + '>Use My Birthday Coupon!</button>';
    }
    if (viewState === 'applied') {
      return '<span class="ForestryRewardsApplied">Birthday reward applied</span>';
    }
    if (viewState === 'redeemed') {
      return '<span class="ForestryRewardsApplied">Used' + (issuance && issuance.order_number ? ' on ' + escapeHtml(issuance.order_number) : '') + '</span>';
    }
    if (viewState === 'expired') {
      return '<span class="ForestryRewardsMuted">Expired</span>';
    }
    if (viewState === 'sync_failed') {
      return '<button class="Button Button--secondary" type="button" data-action="activate-birthday"' + disabled + '>Try Again</button>';
    }

    return '<span class="ForestryRewardsMuted">Not available yet</span>';
  }

  function birthdayFormMarkup(model, uiState, compact) {
    const birthday = model.birthday || {};
    const show = uiState.formOpen || (!compact && birthdayViewState(model) === 'missing_birthday');

    return '<div class="ForestryRewardsBirthdayForm' + (show ? ' is-open' : '') + '">' +
      '<div class="ForestryRewardsBirthdayForm__grid">' +
        '<label><span>Month</span><input data-field="birth-month" type="number" min="1" max="12" value="' + escapeHtml(birthday.birth_month || '') + '" placeholder="MM"></label>' +
        '<label><span>Day</span><input data-field="birth-day" type="number" min="1" max="31" value="' + escapeHtml(birthday.birth_day || '') + '" placeholder="DD"></label>' +
        '<label><span>Year</span><input data-field="birth-year" type="number" min="1900" max="2100" value="' + escapeHtml(birthday.birth_year || '') + '" placeholder="Optional"></label>' +
      '</div>' +
      '<div class="ForestryRewardsBirthdayForm__actions">' +
        '<button class="Button Button--secondary" type="button" data-action="save-birthday"' + (uiState.busy ? ' disabled aria-disabled="true"' : '') + '>Save Birthday</button>' +
      '</div>' +
    '</div>';
  }

  function rewardAmountLabel(task) {
    return currencyLabel(task.reward_amount || '0') || '$0';
  }

  function taskState(task) {
    return normalizeState(task && task.eligibility && task.eligibility.state);
  }

  function taskTone(state) {
    if (state === 'available') {
      return 'success';
    }
    if (state === 'completed' || state === 'awarded' || state === 'approved') {
      return 'success';
    }
    if (state === 'pending') {
      return 'soft';
    }
    if (state === 'locked') {
      return 'soft';
    }
    return 'neutral';
  }

  function taskVerificationMode(task) {
    return cleanString(task && task.verification_mode);
  }

  function taskIsOnsiteAction(task) {
    return taskVerificationMode(task) === 'onsite_action';
  }

  function taskIsAutomatic(task) {
    if (!bool(task && task.auto_award)) {
      return false;
    }

    return [
      'system_event',
      'subscription_event',
      'referral_conversion',
      'google_business_review',
      'product_review_platform_event',
      'external_campaign_comment',
    ].indexOf(taskVerificationMode(task)) >= 0;
  }

  function taskNeedsProof(task) {
    return taskVerificationMode(task) === 'manual_review_fallback' || bool(task.requires_customer_submission) || bool(task.requires_manual_approval);
  }

  function taskNeedsInlineEmailSignup(task, model) {
    return cleanString(task.handle) === 'email-signup' && !model.consentEmail;
  }

  function taskNeedsInlineSmsSignup(task, model) {
    return cleanString(task.handle) === 'sms-signup' && !model.consentSms;
  }

  function taskEyebrow(task) {
    switch (cleanString(task && task.handle)) {
      case 'email-signup':
      case 'sms-signup':
        return 'Verified signup';
      case 'google-review':
      case 'product-review':
        return 'Verified review';
      case 'refer-a-friend':
      case 'referred-friend-bonus':
        return 'Referral reward';
      case 'birthday-signup':
        return 'Birthday reward';
      case 'candle-club-join':
      case 'candle-club-vote':
        return 'Candle Club';
      case 'second-order':
        return 'Order reward';
      default:
        return cleanString(taskVerificationMode(task)) ? titleCaseSlug(taskVerificationMode(task)) : 'Reward';
    }
  }

  function taskStatusText(task, state, model) {
    if (cleanString(task.handle) === 'email-signup' && model.consentEmail) {
      return 'Completed';
    }
    if (cleanString(task.handle) === 'sms-signup' && model.consentSms) {
      return 'Completed';
    }
    if (taskNeedsInlineEmailSignup(task, model)) {
      return 'Get $5';
    }
    if (taskNeedsInlineSmsSignup(task, model)) {
      return 'Turn on texts';
    }
    if (state === 'login_required') {
      return 'Sign in';
    }
    if (state === 'locked') {
      return cleanString(task.eligibility && task.eligibility.locked_message) || 'Locked';
    }
    if (state === 'pending') {
      return 'Verifying';
    }
    if (state === 'completed' || state === 'awarded' || state === 'approved') {
      return 'Completed';
    }
    if (taskIsAutomatic(task)) {
      return 'Verified automatically';
    }
    if (taskIsOnsiteAction(task)) {
      return 'Ready';
    }
    return 'Available';
  }

  function taskDetailCopy(task, state, model) {
    if (state === 'login_required') {
      return 'Sign in first and we will save your progress to the right account.';
    }
    if (state === 'locked') {
      return cleanString(task.eligibility && task.eligibility.locked_message) || 'This reward is not available on your account yet.';
    }
    if (state === 'pending') {
      return 'We recorded the action. Candle Cash lands as soon as the matching event clears.';
    }
    if (state === 'completed' || state === 'awarded' || state === 'approved') {
      return 'Already earned. We saved the Candle Cash in your account.';
    }

    if (task.handle === 'refer-a-friend') {
      return 'Share your referral link below. You earn once a friend places a qualifying first order.';
    }
    if (task.handle === 'birthday-signup') {
      return model.birthdayState === 'add_birthday_unlock_reward'
        ? 'Save your birthday for your annual $10 coupon. There is no separate signup bonus.'
        : 'Your Birthday Club page shows your annual coupon.';
    }
    if (task.handle === 'email-signup') {
      return model.consentEmail
        ? 'You are already on the list, so this one is handled.'
        : 'Join our email list here and we will add $5 in Candle Cash once it is saved.';
    }
    if (task.handle === 'sms-signup') {
      return model.consentSms
        ? 'Text deals are already on for your account.'
        : 'Turn on text deals here and we will add Candle Cash automatically when the signup is confirmed.';
    }
    if (task.handle === 'google-review') {
      return task.action_url
        ? 'Open Google, leave your review, and we will match it automatically once it posts.'
        : 'Google review matching is ready. Add the review link in admin to make this task actionable.';
    }
    if (task.handle === 'product-review') {
      return task.action_url
        ? 'Leave your review through the connected review flow and we will add Candle Cash automatically.'
        : 'This review reward stays hidden until the verified review integration is connected.';
    }
    if (task.handle === 'second-order') {
      return 'This one lands automatically after your second order is complete.';
    }
    if (task.handle === 'candle-club-join') {
      return 'Join Candle Club and the reward lands automatically as soon as your membership is active.';
    }
    if (task.handle === 'candle-club-vote') {
      return 'Vote in the live Candle Club poll and we will add the reward as soon as your vote is recorded.';
    }
    if (taskIsAutomatic(task)) {
      return 'This reward is handled automatically when the verified trigger happens.';
    }

    return cleanString(task.description);
  }

  function taskSystemActionMarkup(root, model, task) {
    if (task.handle === 'refer-a-friend' && model.profileId) {
      return '<a class="Button Button--secondary" href="#candle-cash-referrals">Share your link</a>';
    }

    if (task.handle === 'birthday-signup') {
      return '<a class="Button Button--secondary" href="#candle-cash-birthday">Birthday reward</a>';
    }

    if (task.handle === 'google-review' && task.action_url) {
      return '<button class="Button Button--primary" type="button" data-action="start-google-review" data-task-handle="' + escapeHtml(task.handle) + '" data-open-url="' + escapeHtml(task.action_url) + '"' + (rootState(root).busy ? ' disabled aria-disabled="true"' : '') + '>' + escapeHtml(task.button_text || 'Leave a review') + '</button>';
    }

    if (task.action_url) {
      const isAnchor = cleanString(task.action_url).charAt(0) === '#';
      return '<a class="Button Button--primary" ' + (isAnchor ? 'href="' + escapeHtml(task.action_url) + '"' : 'href="' + escapeHtml(task.action_url) + '" target="_blank" rel="noopener"') + '>' + escapeHtml(task.button_text || 'Open task') + '</a>';
    }

    return '<span class="ForestryRewardsMuted">Verified automatically</span>';
  }

  function taskProofFormMarkup(task, uiState) {
    if (uiState.openTaskHandle !== task.handle) {
      return '';
    }

    const disabled = uiState.busy ? ' disabled aria-disabled="true"' : '';
    const openLink = task.action_url
      ? '<a class="Button Button--secondary" href="' + escapeHtml(task.action_url) + '" target="_blank" rel="noopener">Open task</a>'
      : '';

    return '<div class="ForestryRewardsTaskProof">' +
      '<div class="ForestryRewardsTaskProof__grid">' +
        '<label class="ForestryRewardsInlineField"><span>Proof link</span><input type="url" data-task-proof-url="' + escapeHtml(task.handle) + '" placeholder="Paste a review or proof link"></label>' +
        '<label class="ForestryRewardsInlineField"><span>Note</span><textarea rows="3" data-task-proof-text="' + escapeHtml(task.handle) + '" placeholder="Anything you want the team to review?"></textarea></label>' +
      '</div>' +
      '<div class="ForestryRewardsTaskProof__actions">' + openLink + '<button class="Button Button--primary" type="button" data-action="submit-task" data-task-handle="' + escapeHtml(task.handle) + '" data-task-requires-proof="1"' + disabled + '>Submit for review</button></div>' +
    '</div>';
  }

  function emailSignupFormMarkup(root, task, uiState) {
    if (uiState.openTaskHandle !== task.handle) {
      return '';
    }

    const identity = buildIdentity(root);

    return '<div class="ForestryRewardsTaskProof">' +
      '<div class="ForestryRewardsTaskProof__grid">' +
        '<label class="ForestryRewardsInlineField"><span>Email</span><input type="email" data-task-email="' + escapeHtml(task.handle) + '" value="' + escapeHtml(identity.email || '') + '" placeholder="you@example.com"></label>' +
        '<label class="ForestryRewardsInlineField"><span>First name</span><input type="text" data-task-first-name="' + escapeHtml(task.handle) + '" value="' + escapeHtml(identity.first_name || '') + '" placeholder="First name"></label>' +
        '<label class="ForestryRewardsInlineField"><span>Last name</span><input type="text" data-task-last-name="' + escapeHtml(task.handle) + '" value="' + escapeHtml(identity.last_name || '') + '" placeholder="Last name"></label>' +
      '</div>' +
      '<div class="ForestryRewardsTaskProof__actions">' +
        '<button class="Button Button--primary" type="button" data-action="submit-email-signup" data-task-handle="' + escapeHtml(task.handle) + '"' + (uiState.busy ? ' disabled aria-disabled="true"' : '') + '>Join and get $5</button>' +
      '</div>' +
    '</div>';
  }

  function smsSignupFormMarkup(root, task, uiState) {
    if (uiState.openTaskHandle !== task.handle) {
      return '';
    }

    const identity = buildIdentity(root);

    return '<div class="ForestryRewardsTaskProof">' +
      '<div class="ForestryRewardsTaskProof__grid">' +
        '<label class="ForestryRewardsInlineField"><span>Phone</span><input type="tel" data-task-phone="' + escapeHtml(task.handle) + '" value="' + escapeHtml(identity.phone || '') + '" placeholder="(555) 555-5555"></label>' +
        '<label class="ForestryRewardsInlineField"><span>First name</span><input type="text" data-task-first-name="' + escapeHtml(task.handle) + '" value="' + escapeHtml(identity.first_name || '') + '" placeholder="First name"></label>' +
        '<label class="ForestryRewardsInlineField"><span>Last name</span><input type="text" data-task-last-name="' + escapeHtml(task.handle) + '" value="' + escapeHtml(identity.last_name || '') + '" placeholder="Last name"></label>' +
      '</div>' +
      '<div class="ForestryRewardsTaskProof__actions">' +
        '<button class="Button Button--primary" type="button" data-action="submit-sms-signup" data-task-handle="' + escapeHtml(task.handle) + '"' + (uiState.busy ? ' disabled aria-disabled="true"' : '') + '>Join texts</button>' +
      '</div>' +
    '</div>';
  }

  function taskSupplementalMarkup(root, model, task, uiState) {
    if (taskNeedsInlineEmailSignup(task, model)) {
      return emailSignupFormMarkup(root, task, uiState);
    }

    if (taskNeedsInlineSmsSignup(task, model)) {
      return smsSignupFormMarkup(root, task, uiState);
    }

    if (taskNeedsProof(task)) {
      return taskProofFormMarkup(task, uiState);
    }

    return '';
  }

  function taskActionMarkup(root, model, task, uiState) {
    const state = taskState(task);
    const disabled = uiState.busy ? ' disabled aria-disabled="true"' : '';
    const loginUrl = rewardsLoginUrl(root);
    const lockedUrl = cleanString(task.eligibility && task.eligibility.locked_cta_url);
    const lockedText = cleanString(task.eligibility && task.eligibility.locked_cta_text) || 'Learn more';

    if (cleanString(task.handle) === 'email-signup' && model.consentEmail) {
      return '<span class="ForestryRewardsApplied">Completed</span>';
    }
    if (cleanString(task.handle) === 'sms-signup' && model.consentSms) {
      return '<span class="ForestryRewardsApplied">Completed</span>';
    }
    if (taskNeedsInlineEmailSignup(task, model)) {
      const label = uiState.openTaskHandle === task.handle ? 'Hide form' : (task.button_text || 'Join and get $5');
      return '<button class="Button Button--primary" type="button" data-action="toggle-task-form" data-task-handle="' + escapeHtml(task.handle) + '"' + disabled + '>' + escapeHtml(label) + '</button>';
    }
    if (taskNeedsInlineSmsSignup(task, model)) {
      const label = uiState.openTaskHandle === task.handle ? 'Hide form' : (task.button_text || 'Join text deals');
      return '<button class="Button Button--primary" type="button" data-action="toggle-task-form" data-task-handle="' + escapeHtml(task.handle) + '"' + disabled + '>' + escapeHtml(label) + '</button>';
    }
    if (state === 'login_required') {
      return '<a class="Button Button--secondary" href="' + escapeHtml(loginUrl) + '">Sign in</a>';
    }
    if (state === 'locked') {
      if (lockedUrl) {
        const isAnchor = lockedUrl.charAt(0) === '#';
        return '<a class="Button Button--secondary" ' + (isAnchor ? 'href="' + escapeHtml(lockedUrl) + '"' : 'href="' + escapeHtml(lockedUrl) + '" target="_blank" rel="noopener"') + '>' + escapeHtml(lockedText) + '</a>';
      }
      return '<span class="ForestryRewardsMuted">Locked</span>';
    }
    if (state === 'pending') {
      return '<span class="ForestryRewardsMuted">Waiting on verification</span>';
    }
    if (state === 'completed' || state === 'awarded' || state === 'approved') {
      return '<span class="ForestryRewardsApplied">Completed</span>';
    }

    if (taskIsAutomatic(task)) {
      return taskSystemActionMarkup(root, model, task);
    }

    if (taskNeedsProof(task)) {
      const label = uiState.openTaskHandle === task.handle ? 'Hide form' : 'Submit proof';
      const openLink = task.action_url
        ? '<a class="Button Button--secondary" href="' + escapeHtml(task.action_url) + '" target="_blank" rel="noopener">Open task</a>'
        : '';
      return openLink + '<button class="Button Button--primary" type="button" data-action="toggle-task-form" data-task-handle="' + escapeHtml(task.handle) + '"' + disabled + '>' + escapeHtml(label) + '</button>';
    }

    if (taskIsOnsiteAction(task)) {
      return '<button class="Button Button--primary" type="button" data-action="submit-task" data-task-handle="' + escapeHtml(task.handle) + '" data-open-url="' + escapeHtml(task.action_url || '') + '"' + disabled + '>' + escapeHtml(task.button_text || 'Complete task') + '</button>';
    }

    if (task.action_url) {
      return taskSystemActionMarkup(root, model, task);
    }

    return '<span class="ForestryRewardsMuted">Verified automatically</span>';
  }

  function taskCardMarkup(root, model, task, uiState) {
    const state = taskState(task);
    const count = positiveInt(task.eligibility && task.eligibility.completed_count) || 0;
    const pending = positiveInt(task.eligibility && task.eligibility.pending_count) || 0;

    return '<article class="ForestryRewardsTaskCard ForestryRewardsTaskCard--' + escapeHtml(state || 'available') + '" data-task-card="' + escapeHtml(task.handle) + '">' +
      '<div class="ForestryRewardsTaskCard__header">' +
        '<div>' +
          '<p class="ForestryRewardsCard__eyebrow">' + escapeHtml(taskEyebrow(task)) + '</p>' +
          '<h3 class="Heading u-h4">' + escapeHtml(task.title) + '</h3>' +
        '</div>' +
        '<div class="ForestryRewardsTaskCard__badges">' + badge(taskStatusText(task, state, model), taskTone(state)) + badge(rewardAmountLabel(task), 'neutral') + '</div>' +
      '</div>' +
      '<p class="ForestryRewardsCard__description">' + escapeHtml(taskDetailCopy(task, state, model)) + '</p>' +
      '<div class="ForestryRewardsTaskMeta">' +
        '<span>' + escapeHtml(count > 0 ? count + ' earned' : (pending > 0 ? 'Waiting on verification' : (taskIsAutomatic(task) ? 'Verified automatically' : 'Ready when you are'))) + '</span>' +
        '<span>' + escapeHtml(task.max_completions_per_customer > 1 ? ('Up to ' + task.max_completions_per_customer + ' times') : 'One-time reward') + '</span>' +
      '</div>' +
      '<div class="ForestryRewardsCard__actions">' + taskActionMarkup(root, model, task, uiState) + '</div>' +
      taskSupplementalMarkup(root, model, task, uiState) +
    '</article>';
  }

  function otherRewardItem(reward, uiState) {
    const amount = reward.reward && reward.reward.reward_value
      ? currencyLabel(reward.reward.reward_value)
      : 'Reward code';
    const disabled = uiState.busy ? ' disabled aria-disabled="true"' : '';
    const actions = [];

    if (reward.apply_path && reward.is_usable) {
      actions.push('<button class="Button Button--secondary" type="button" data-action="apply-reward" data-reward-kind="candle_cash" data-code="' + escapeHtml(reward.redemption_code || '') + '" data-apply-path="' + escapeHtml(reward.apply_path) + '"' + disabled + '>Use code</button>');
    }
    actions.push('<button class="Button Button--secondary" type="button" data-action="copy-code" data-code="' + escapeHtml(reward.redemption_code || '') + '">Copy code</button>');

    return '<li class="ForestryRewardsList__item">' +
      '<div>' +
        '<p class="ForestryRewardsList__title">' + escapeHtml((reward.reward && reward.reward.name) || 'Saved Candle Cash') + '</p>' +
        '<p class="ForestryRewardsList__meta">' + escapeHtml(amount) + (reward.expires_at ? ' · Expires ' + escapeHtml(shortDate(reward.expires_at)) : '') + '</p>' +
      '</div>' +
      '<div class="ForestryRewardsList__actions">' +
        '<code>' + escapeHtml(reward.redemption_code || '') + '</code>' +
        actions.join('') +
      '</div>' +
    '</li>';
  }

  function helperActionMarkup(model, viewState, uiState) {
    const issuance = model.birthdayIssuance;
    const primaryCode = primaryRewardCode(model);
    const disabled = uiState.busy ? ' disabled aria-disabled="true"' : '';

    if (viewState === 'available') {
      return '<button class="Button Button--primary Button--full" type="button" data-action="activate-birthday"' + disabled + '>Activate Reward</button>';
    }
    if (viewState === 'activated' && issuance && issuance.apply_path) {
      return '<button class="Button Button--primary Button--full" type="button" data-action="apply-reward" data-reward-kind="birthday" data-code="' + escapeHtml(issuance.reward_code || '') + '" data-apply-path="' + escapeHtml(issuance.apply_path) + '"' + disabled + '>Use My Birthday Coupon!</button>';
    }
    if (viewState === 'applied') {
      return '<div class="ForestryRewardsHelper__applied">Birthday reward applied. You are set.</div>';
    }
    if (primaryCode && primaryCode.is_usable && primaryCode.apply_path) {
      return '<button class="Button Button--secondary Button--full" type="button" data-action="apply-reward" data-reward-kind="candle_cash" data-code="' + escapeHtml(primaryCode.redemption_code || '') + '" data-apply-path="' + escapeHtml(primaryCode.apply_path) + '"' + disabled + '>Use Saved Candle Cash</button>';
    }
    const candleCashAction = compactCandleCashReward(model, uiState).action;
    if (candleCashAction) {
      return candleCashAction;
    }
    if (model.otherRewards.length > 0) {
      return '<div class="ForestryRewardsHelper__quiet">Your saved reward is still being confirmed. Please check again in a moment.</div>';
    }
    return '<div class="ForestryRewardsHelper__quiet">No reward code needs your attention right now.</div>';
  }

  function renderUnavailable(root, title, detail) {
    const surface = cleanString(root.dataset.surface || 'page');
    const retryControl = surface === 'cart' || surface === 'drawer'
      ? '<button class="Button Button--secondary" type="button" data-action="refresh-status">Try again</button>'
      : '';
    root.innerHTML = '' +
      '<section class="ForestryRewardsSurface ForestryRewardsSurface--fallback" role="status" aria-live="polite">' +
        '<div class="ForestryRewardsFallback">' +
          '<p class="ForestryRewardsEyebrow">Rewards</p>' +
          '<h3 class="Heading u-h3">' + escapeHtml(title) + '</h3>' +
          '<p class="ForestryRewardsLead ForestryRewardsLead--compact">' + escapeHtml(detail) + '</p>' +
          retryControl +
        '</div>' +
      '</section>';
  }

  function centralStatsMarkup(model) {
    const cards = [
      {
        label: 'Available now',
        value: model.balanceAmount > 0 ? currencyLabel(model.balanceAmount) : renderMoneySummary(model.otherRewards, model.birthdayIssuance) || '$0',
        note: model.balance > 0 ? formatBalance(model.balance) + ' Candle Cash in your account' : 'Fresh rewards show up here as soon as they are ready.',
      },
      {
        label: 'Lifetime earned',
        value: currencyLabel(model.summary.lifetime_earned_amount || 0) || '$0',
        note: (model.summary.lifetime_earned_points || 0) + ' total Candle Cash points earned',
      },
      {
        label: 'Pending',
        value: String(model.summary.pending_rewards || 0),
        note: (model.summary.pending_rewards || 0) > 0 ? 'Tasks waiting on review' : 'Nothing waiting on review right now',
      },
      {
        label: 'Referrals',
        value: String(model.referral && model.referral.count ? model.referral.count : 0),
        note: 'Friends you have brought into the studio',
      },
    ];

    return '<div class="ForestryRewardsStatGrid">' + cards.map(function (card) {
      return '<article class="ForestryRewardsStatCard">' +
        '<p class="ForestryRewardsStatLabel">' + escapeHtml(card.label) + '</p>' +
        '<p class="ForestryRewardsStatValue">' + escapeHtml(card.value) + '</p>' +
        '<p class="ForestryRewardsStatNote">' + escapeHtml(card.note) + '</p>' +
      '</article>';
    }).join('') + '</div>';
  }

  function referralCardMarkup(root, model) {
    const loginUrl = rewardsLoginUrl(root);
    const referral = model.referral || {};

    if (!model.profileId) {
      return '<article class="ForestryRewardsCard ForestryRewardsCard--referral" id="candle-cash-referrals">' +
        '<p class="ForestryRewardsCard__eyebrow">Referral rewards</p>' +
        '<h3 class="Heading u-h3">Share Candle Cash with a friend</h3>' +
        '<p class="ForestryRewardsCard__description">Sign in and we will give you a personal link you can copy in one click.</p>' +
        '<div class="ForestryRewardsCard__actions"><a class="Button Button--primary" href="' + escapeHtml(loginUrl) + '">Sign in to share</a></div>' +
      '</article>';
    }

    return '<article class="ForestryRewardsCard ForestryRewardsCard--referral" id="candle-cash-referrals">' +
      '<div class="ForestryRewardsCard__header">' +
        '<div>' +
          '<p class="ForestryRewardsCard__eyebrow">Referral rewards</p>' +
          '<h3 class="Heading u-h3">' + escapeHtml(referral.headline || 'Share Candle Cash with a friend') + '</h3>' +
        '</div>' +
        '<div class="ForestryRewardsCard__state">' + badge('Best growth move', 'soft') + '</div>' +
      '</div>' +
      '<p class="ForestryRewardsCard__description">' + escapeHtml(referral.copy || 'Share your link and earn Candle Cash when a friend places a qualifying first order.') + '</p>' +
      '<div class="ForestryRewardsReferralSplit">' +
        '<div><span>You earn</span><strong>' + escapeHtml(currencyLabel(referral.referrer_reward_amount || 0) || '$0') + '</strong></div>' +
        '<div><span>Friend earns</span><strong>' + escapeHtml(currencyLabel(referral.referred_reward_amount || 0) || '$0') + '</strong></div>' +
      '</div>' +
      '<div class="ForestryRewardsReferralCode">' +
        '<code>' + escapeHtml(referral.code || '') + '</code>' +
        '<button class="Button Button--secondary" type="button" data-action="copy-referral" data-referral-value="' + escapeHtml(referral.link || referral.code || '') + '">Copy link</button>' +
        '<button class="Button Button--secondary" type="button" data-action="share-referral" data-referral-value="' + escapeHtml(referral.link || '') + '">Share</button>' +
      '</div>' +
      '<div class="ForestryRewardsReferralFoot">' + escapeHtml((referral.count || 0) + ' referral' + ((referral.count || 0) === 1 ? '' : 's') + ' tracked so far') + '</div>' +
    '</article>';
  }

  function guestCalloutMarkup(root) {
    const loginUrl = rewardsLoginUrl(root);

    return '<div class="ForestryRewardsGuestCallout">' +
      '<div>' +
        '<p class="ForestryRewardsCard__eyebrow">Sign in for your live balance</p>' +
        '<p class="ForestryRewardsCard__description">We will show your balance, birthday reward, saved codes, and referral link as soon as you are in the right account.</p>' +
      '</div>' +
      '<a class="Button Button--primary" href="' + escapeHtml(loginUrl) + '">Sign in</a>' +
    '</div>';
  }

  function historyTasksMarkup(model) {
    if (!model.taskHistory.length) {
      return '<div class="ForestryRewardsHistoryEmpty">No task history yet.</div>';
    }

    return model.taskHistory.slice(0, 8).map(function (row) {
      const status = titleCaseSlug(row.status || 'pending');
      const date = row.awarded_at || row.created_at;
      return '<div class="ForestryRewardsHistoryItem">' +
        '<div>' +
          '<strong>' + escapeHtml(row.task_title || 'Task') + '</strong>' +
          '<p>' + escapeHtml(status) + (date ? ' · ' + shortDate(date) : '') + '</p>' +
        '</div>' +
        '<span>' + escapeHtml(row.reward_amount ? currencyLabel(row.reward_amount) || row.reward_amount : '') + '</span>' +
      '</div>';
    }).join('');
  }

  function historyLedgerMarkup(model) {
    if (!model.ledgerHistory.length) {
      return '<div class="ForestryRewardsHistoryEmpty">No earned or redeemed history yet.</div>';
    }

    return model.ledgerHistory.slice(0, 8).map(function (row) {
      const points = Number.parseInt(String(row.points || 0), 10) || 0;
      const label = points > 0 ? '+' + points : String(points);
      return '<div class="ForestryRewardsHistoryItem">' +
        '<div>' +
          '<strong>' + escapeHtml(row.description || 'Candle Cash activity') + '</strong>' +
          '<p>' + escapeHtml(shortDate(row.created_at)) + '</p>' +
        '</div>' +
        '<span>' + escapeHtml(label + ' pts') + '</span>' +
      '</div>';
    }).join('');
  }

  function faqMarkup(model) {
    const items = [
      {
        q: 'How long do approvals take?',
        a: cleanString(model.copy.faq_approval_copy) || 'Manual approvals usually land within 1 to 3 business days.',
      },
      {
        q: 'Can I use more than one reward?',
        a: cleanString(model.copy.faq_stack_copy) || 'Only one discount code can be used at a time unless a reward card says otherwise.',
      },
      {
        q: 'Where do pending tasks show up?',
        a: cleanString(model.copy.faq_pending_copy) || 'Pending tasks stay in your history until they are approved or declined.',
      },
    ];

    return '<div class="ForestryRewardsFaq">' + items.map(function (item) {
      return '<article class="ForestryRewardsFaq__item"><h3 class="Heading u-h5">' + escapeHtml(item.q) + '</h3><p class="ForestryRewardsCard__description">' + escapeHtml(item.a) + '</p></article>';
    }).join('') + '</div>';
  }

  function renderCentralSurface(root, model, uiState) {
    const viewState = birthdayViewState(model);
    const badges = [];
    const title = cleanString(model.copy.title) || cleanString(root.dataset.title) || 'Candle Cash Central';
    const subtitle = cleanString(model.copy.subtitle) || cleanString(root.dataset.subtitle);
    const tasksMarkup = model.tasks.length
      ? model.tasks.map(function (task) { return taskCardMarkup(root, model, task, uiState); }).join('')
      : '<div class="ForestryRewardsHistoryEmpty">No active tasks right now.</div>';

    if (model.balance > 0) {
      badges.push(badge(formatBalance(model.balance) + ' Candle Cash', 'soft'));
    }
    if (viewState === 'activated' || viewState === 'applied') {
      badges.push(badge('Birthday ready', 'success'));
    }
    if (model.otherRewards.length > 0) {
      badges.push(badge(model.otherRewards.length + ' saved code' + (model.otherRewards.length === 1 ? '' : 's'), 'neutral'));
    }
    if (model.referral && model.referral.enabled) {
      badges.push(badge('Referral rewards live', 'neutral'));
    }

    root.innerHTML = '' +
      '<section class="ForestryRewardsSurface ForestryRewardsSurface--page">' +
        '<div class="ForestryRewardsSurface__hero">' +
          '<div>' +
            '<p class="ForestryRewardsEyebrow">' + escapeHtml(title) + '</p>' +
            '<h2 class="Heading u-h1">' + escapeHtml(summaryHeadline(model, viewState)) + '</h2>' +
            '<p class="ForestryRewardsLead">' + escapeHtml(subtitle) + '</p>' +
          '</div>' +
          '<div class="ForestryRewardsSurface__summary">' +
            '<p class="ForestryRewardsStatLabel">Candle Cash balance</p>' +
            '<p class="ForestryRewardsStatValue">' + escapeHtml(currencyLabel(model.balanceAmount || 0) || '$0') + '</p>' +
            '<p class="ForestryRewardsStatNote">' + escapeHtml(model.profileId ? formatBalance(model.balance) + ' Candle Cash in your account' : 'Sign in and we will show your live balance.') + '</p>' +
          '</div>' +
        '</div>' +
        '<div class="ForestryRewardsSurface__badges">' + badges.join('') + '</div>' +
        (!model.profileId ? guestCalloutMarkup(root) : '') +
        centralStatsMarkup(model) +
        '<div class="ForestryRewardsGrid">' +
          '<article class="ForestryRewardsCard ForestryRewardsCard--birthday" id="candle-cash-birthday">' +
            '<div class="ForestryRewardsCard__header">' +
              '<div>' +
                '<p class="ForestryRewardsCard__eyebrow">Birthday reward</p>' +
                '<h3 class="Heading u-h3">' + escapeHtml((model.birthdayIssuance && model.birthdayIssuance.reward_name) || 'Birthday reward') + '</h3>' +
              '</div>' +
              '<div class="ForestryRewardsCard__state">' + badge(titleCaseSlug(viewState), viewState === 'activated' || viewState === 'applied' ? 'success' : (viewState === 'expired' ? 'soft' : 'neutral')) + '</div>' +
            '</div>' +
            '<p class="ForestryRewardsCard__amount">' + escapeHtml(model.birthdayIssuance && model.birthdayIssuance.reward_value ? currencyLabel(model.birthdayIssuance.reward_value) : '$10') + '</p>' +
            '<p class="ForestryRewardsCard__description">' + escapeHtml(rewardCardDescription(model, viewState)) + '</p>' +
            renderBirthdayMeta(model, viewState) +
            '<div class="ForestryRewardsCard__actions">' + birthdayActionMarkup(model, viewState, uiState) + '</div>' +
            birthdayFormMarkup(model, uiState) + '<p><a class="Link Link--underline" href="/pages/birthday-club">View all birthday gifts</a></p>' +
          '</article>' +
          '<aside class="ForestryRewardsStack">' +
            '<div class="ForestryRewardsCard ForestryRewardsCard--helper">' +
              '<p class="ForestryRewardsCard__eyebrow">Checkout helper</p>' +
              '<h3 class="Heading u-h4">Use your rewards</h3>' +
              '<p class="ForestryRewardsCard__description">' + escapeHtml(helperMessage(model, viewState)) + '</p>' +
              '<div class="ForestryRewardsCard__actions ForestryRewardsCard__actions--stack">' + helperActionMarkup(model, viewState, uiState) + '</div>' +
              '<p class="ForestryRewardsHelper__rule">If Shopify only accepts one code, start with the strongest reward first.</p>' +
            '</div>' +
            '<div class="ForestryRewardsCard ForestryRewardsCard--balance">' +
              '<p class="ForestryRewardsCard__eyebrow">Account snapshot</p>' +
              '<div class="ForestryRewardsSnapshot">' +
                '<div><span>Candle Cash</span><strong>' + escapeHtml(formatBalance(model.balance)) + '</strong></div>' +
                '<div><span>SMS</span><strong>' + escapeHtml(model.consentSms ? 'On' : 'Off') + '</strong></div>' +
                '<div><span>Email</span><strong>' + escapeHtml(model.consentEmail ? 'On' : 'Off') + '</strong></div>' +
              '</div>' +
            '</div>' +
            referralCardMarkup(root, model) +
          '</aside>' +
        '</div>' +
        '<section class="ForestryRewardsSection">' +
          '<div class="ForestryRewardsSection__header">' +
            '<div><p class="ForestryRewardsCard__eyebrow">Ways to earn</p><h3 class="Heading u-h3">Complete a few easy wins</h3></div>' +
          '</div>' +
          '<div class="ForestryRewardsTaskGrid">' + tasksMarkup + '</div>' +
        '</section>' +
        '<div class="ForestryRewardsSurface__lower">' +
          '<div class="ForestryRewardsCard ForestryRewardsCard--list">' +
            '<div class="ForestryRewardsCard__header">' +
              '<div>' +
                '<p class="ForestryRewardsCard__eyebrow">Other activated Candle Cash</p>' +
                '<h3 class="Heading u-h4">Saved reward codes</h3>' +
              '</div>' +
            '</div>' +
            '<p class="ForestryRewardsCard__description">These are already waiting in your account. Copy one when you are ready to check out.</p>' +
            '<ul class="ForestryRewardsList">' +
              (model.otherRewards.length ? model.otherRewards.map(function (reward) { return otherRewardItem(reward, uiState); }).join('') : '<li class="ForestryRewardsList__empty">No other active codes yet.</li>') +
            '</ul>' +
          '</div>' +
          '<div class="ForestryRewardsHistoryGrid">' +
            '<article class="ForestryRewardsCard ForestryRewardsCard--history">' +
              '<p class="ForestryRewardsCard__eyebrow">Task history</p>' +
              '<h3 class="Heading u-h4">What you have completed</h3>' +
              '<div class="ForestryRewardsHistoryList">' + historyTasksMarkup(model) + '</div>' +
            '</article>' +
            '<article class="ForestryRewardsCard ForestryRewardsCard--history">' +
              '<p class="ForestryRewardsCard__eyebrow">Reward history</p>' +
              '<h3 class="Heading u-h4">Recent Candle Cash movement</h3>' +
              '<div class="ForestryRewardsHistoryList">' + historyLedgerMarkup(model) + '</div>' +
            '</article>' +
          '</div>' +
          '<section class="ForestryRewardsSection ForestryRewardsSection--faq">' +
            '<div class="ForestryRewardsSection__header">' +
              '<div><p class="ForestryRewardsCard__eyebrow">A few helpful notes</p><h3 class="Heading u-h4">How Candle Cash works</h3></div>' +
            '</div>' +
            faqMarkup(model) +
          '</section>' +
        '</div>' +
        (uiState.toast ? '<div class="ForestryRewardsToast ForestryRewardsToast--' + escapeHtml(uiState.toastTone || 'neutral') + '">' + escapeHtml(uiState.toast) + '</div>' : '') +
      '</section>';
  }

  function renderAccountSurface(root, model, uiState) {
    const viewState = birthdayViewState(model);
    const tasksPreview = model.tasks.slice(0, 4);

    root.innerHTML = '' +
      '<section class="ForestryRewardsSurface ForestryRewardsSurface--account">' +
        '<div class="ForestryRewardsSurface__hero">' +
          '<div>' +
            '<p class="ForestryRewardsEyebrow">Your Rewards</p>' +
            '<h2 class="Heading u-h2">Keep your Candle Cash close</h2>' +
            '<p class="ForestryRewardsLead">Birthday rewards, referral wins, and your next best task all live here.</p>' +
          '</div>' +
          '<div class="ForestryRewardsSurface__summary">' +
            '<p class="ForestryRewardsStatLabel">Balance</p>' +
            '<p class="ForestryRewardsStatValue">' + escapeHtml(currencyLabel(model.balanceAmount || 0) || '$0') + '</p>' +
            '<p class="ForestryRewardsStatNote">' + escapeHtml(formatBalance(model.balance) + ' Candle Cash in your account') + '</p>' +
          '</div>' +
        '</div>' +
        '<div class="ForestryRewardsGrid">' +
          '<article class="ForestryRewardsCard ForestryRewardsCard--birthday">' +
            '<div class="ForestryRewardsCard__header">' +
              '<div><p class="ForestryRewardsCard__eyebrow">Birthday reward</p><h3 class="Heading u-h4">' + escapeHtml((model.birthdayIssuance && model.birthdayIssuance.reward_name) || 'Birthday reward') + '</h3></div>' +
              '<div class="ForestryRewardsCard__state">' + badge(titleCaseSlug(viewState), taskTone(viewState)) + '</div>' +
            '</div>' +
            '<p class="ForestryRewardsCard__description">' + escapeHtml(rewardCardDescription(model, viewState)) + '</p>' +
            '<div class="ForestryRewardsCard__actions">' + birthdayActionMarkup(model, viewState, uiState) + '</div>' +
            birthdayFormMarkup(model, uiState) + '<p><a class="Link Link--underline" href="/pages/birthday-club">View all birthday gifts</a></p>' +
          '</article>' +
          '<aside class="ForestryRewardsStack">' +
            referralCardMarkup(root, model) +
            '<div class="ForestryRewardsCard ForestryRewardsCard--list">' +
              '<p class="ForestryRewardsCard__eyebrow">Next best moves</p>' +
              '<div class="ForestryRewardsTaskGrid ForestryRewardsTaskGrid--compact">' +
                (tasksPreview.length ? tasksPreview.map(function (task) { return taskCardMarkup(root, model, task, uiState); }).join('') : '<div class="ForestryRewardsHistoryEmpty">No active tasks right now.</div>') +
              '</div>' +
              '<div class="ForestryRewardsCard__actions"><a class="Button Button--secondary" href="/pages/rewards">Open Candle Cash Central</a></div>' +
            '</div>' +
          '</aside>' +
        '</div>' +
        (uiState.toast ? '<div class="ForestryRewardsToast ForestryRewardsToast--' + escapeHtml(uiState.toastTone || 'neutral') + '">' + escapeHtml(uiState.toast) + '</div>' : '') +
      '</section>';
  }

  function compactRewardRow(label, detail, amount, action) {
    return '<article class="ForestryRewardsCartReward">' +
      '<div class="ForestryRewardsCartReward__copy">' +
        '<span class="ForestryRewardsCartReward__label">' + escapeHtml(label) + '</span>' +
        '<span class="ForestryRewardsCartReward__detail">' + escapeHtml(detail) + '</span>' +
      '</div>' +
      '<div class="ForestryRewardsCartReward__use">' +
        '<strong>' + escapeHtml(amount) + '</strong>' +
        action +
      '</div>' +
    '</article>';
  }

  function compactBirthdayReward(model, uiState) {
    const issuance = model.birthdayIssuance;
    const viewState = birthdayViewState(model);
    const amount = currencyLabel(issuance && issuance.reward_value) || '$10';
    const disabled = uiState.busy ? ' disabled aria-disabled="true"' : '';
    let detail = 'Not available on this order yet';
    let action = '';

    if (model.cartAppliedCandleCash) {
      detail = 'Can’t combine with the Candle Cash already applied';
      action = '<span class="ForestryRewardsMuted">Use on a future order</span>';
    } else if (viewState === 'activated' && issuance && issuance.apply_path) {
      detail = 'Ready to apply to this order';
      action = '<button class="Button Button--secondary" type="button" data-action="apply-reward" data-reward-kind="birthday" data-code="' + escapeHtml(issuance.reward_code || '') + '" data-apply-path="' + escapeHtml(issuance.apply_path) + '"' + disabled + '>Use birthday reward</button>';
    } else if (viewState === 'available' && issuance) {
      detail = 'Ready to unlock and apply here';
      action = '<button class="Button Button--secondary" type="button" data-action="activate-birthday"' + disabled + '>Use birthday reward</button>';
    } else if (viewState === 'applied') {
      detail = 'Applied to this order';
      action = '<span class="ForestryRewardsCartReward__applied">Applied</span>';
    } else if (viewState === 'missing_birthday') {
      detail = 'Add your birthday right here';
      action = '<button class="Button Button--secondary" type="button" data-action="toggle-birthday-form"' + disabled + '>Add birthday</button>';
    } else if (!issuance) {
      detail = 'Birthday reward not available yet';
    }

    return { action: action, markup: compactRewardRow('Birthday reward', detail, amount, action) };
  }

  function compactCandleCashReward(model, uiState) {
    const primaryCode = model.cartAppliedCandleCash ? appliedCandleCashReward(model) : primaryRewardCode(model);
    const disabled = uiState.busy ? ' disabled aria-disabled="true"' : '';
    const increment = model.redemptionAccess.increment || 10;
    const amount = currencyLabel(increment) || '$10';
    const redeemableReward = model.availableRewards.find(function (reward) {
      const cost = Number.parseFloat(reward && (reward.candle_cash_cost || reward.candle_cash_amount || increment)) || increment;
      return reward && positiveInt(reward.id) && model.balanceAmount >= cost && reward.is_redeemable_now !== false;
    });
    let detail = model.balanceAmount >= increment
      ? 'Ready to redeem in ' + amount + ' increments'
      : 'Earn ' + amount + ' to redeem on an order';
    let action = '';

    if (model.cartAppliedBirthday) {
      detail = 'Can’t combine with the birthday reward already applied';
      action = '<span class="ForestryRewardsMuted">Use on a future order</span>';
    } else if (model.cartAppliedCandleCash) {
      detail = 'Applied to this order';
      action = '<button class="Button Button--secondary" type="button" data-action="remove-reward" data-reward-kind="candle_cash" data-code="' + escapeHtml(primaryCode && primaryCode.redemption_code || '') + '"' + disabled + '>Remove Candle Cash</button>';
    } else if (primaryCode && primaryCode.is_usable && primaryCode.apply_path) {
      detail = 'Ready to apply to this order';
      action = '<button class="Button Button--primary" type="button" data-action="apply-reward" data-reward-kind="candle_cash" data-code="' + escapeHtml(primaryCode.redemption_code || '') + '" data-apply-path="' + escapeHtml(primaryCode.apply_path) + '"' + disabled + '>Use ' + escapeHtml(amount) + ' Candle Cash</button>';
    } else if (primaryCode) {
      detail = 'Your saved reward is still being confirmed';
    } else if (model.redemptionAccess.redeemEnabled && redeemableReward) {
      action = '<button class="Button Button--primary" type="button" data-action="redeem-reward" data-reward-id="' + escapeHtml(redeemableReward.id) + '"' + disabled + '>Use ' + escapeHtml(amount) + ' Candle Cash</button>';
    } else if (model.balanceAmount >= increment) {
      detail = 'Checking whether this reward is ready';
    } else {
      detail = 'See easy ways to earn Candle Cash';
      action = '<a class="Button Button--secondary" href="/pages/rewards">Open Candle Cash Central</a>';
    }

    return { action: action, markup: compactRewardRow('Candle Cash', detail, currencyLabel(model.balanceAmount) || '$0', action) };
  }

  function compactCartImpactMarkup(model) {
    if (!model.cartDiscountAmount) {
      return '';
    }

    return '<div class="ForestryRewardsCartImpact" role="status" aria-live="polite">' +
      '<span>Reward applied to this cart</span>' +
      '<strong>−' + escapeHtml(currencyLabel(model.cartDiscountAmount) || '$0') +
        (model.cartTotalAmount >= 0 ? ' · New total ' + escapeHtml(currencyLabel(model.cartTotalAmount) || '$0') : '') +
      '</strong>' +
    '</div>';
  }

  function compactCartChoiceMessage(model, hasChoice) {
    if (model.cartAppliedBirthday) {
      return 'You chose Birthday reward for this order. Candle Cash is saved for a future order. These two rewards cannot be combined; remove this reward to switch.';
    }
    if (model.cartAppliedCandleCash) {
      return 'You chose Candle Cash for this order. Birthday reward is saved for a future order. These two rewards cannot be combined; remove this reward to switch.';
    }
    return hasChoice
      ? 'Choose one reward per order. Birthday reward and Candle Cash cannot be used together.'
      : 'Eligible rewards become available here automatically.';
  }

  function renderCompactSurface(root, model, uiState) {
    const birthday = compactBirthdayReward(model, uiState);
    const candleCash = compactCandleCashReward(model, uiState);
    const hasChoice = birthday.action && candleCash.action;
    const rewardOptions = model.cartAppliedBirthday
      ? birthday.markup
      : (model.cartAppliedCandleCash ? candleCash.markup : birthday.markup + candleCash.markup);

    root.innerHTML = '' +
      '<section class="ForestryRewardsCartPanel ForestryRewardsCartPanel--' + escapeHtml(root.dataset.surface || 'cart') + '">' +
        '<div class="ForestryRewardsCartPanel__head">' +
          '<div><p class="ForestryRewardsEyebrow">Rewards</p><h3 class="Heading u-h5">Use a reward on this order</h3></div>' +
          '<span class="ForestryRewardsCartPanel__balance">' + escapeHtml(currencyLabel(model.balanceAmount) || '$0') + ' available</span>' +
        '</div>' +
        '<div class="ForestryRewardsCartPanel__options">' + rewardOptions + '</div>' +
        (!model.cartAppliedBirthday && !model.cartAppliedCandleCash ? birthdayFormMarkup(model, uiState, true) : '') +
        compactCartImpactMarkup(model) +
        '<p class="ForestryRewardsCartPanel__note">' + escapeHtml(compactCartChoiceMessage(model, hasChoice)) + '</p>' +
        '<div class="ForestryRewardsCartPanel__actions"><a class="Button Button--secondary" href="/pages/rewards">Earn more Candle Cash</a></div>' +
        (uiState.toast ? '<div class="ForestryRewardsToast ForestryRewardsToast--' + escapeHtml(uiState.toastTone || 'neutral') + '">' + escapeHtml(uiState.toast) + '</div>' : '') +
      '</section>';
  }

  function render(root, model) {
    root.__forestryLastModel = model;
    const uiState = rootState(root);
    const surface = cleanString(root.dataset.surface || 'page');

    if (surface === 'cart' || surface === 'drawer') {
      renderCompactSurface(root, model, uiState);
      return;
    }

    if (surface === 'account') {
      renderAccountSurface(root, model, uiState);
      return;
    }

    renderCentralSurface(root, model, uiState);
  }

  function rerender(root) {
    render(root, computeLastModel(root));
  }

  function showToast(root, message, tone) {
    setRootState(root, {
      busy: false,
      toast: message,
      toastTone: tone || 'neutral',
    });
    rerender(root);
  }

  function markBusy(root, message) {
    setRootState(root, {
      busy: true,
      toast: message || 'Working on it…',
      toastTone: 'neutral',
    });
    rerender(root);
  }

  function celebrate(root, tone) {
    if (prefersReducedMotion()) {
      return;
    }

    const host = document.createElement('div');
    host.className = 'ForestryRewardsConfetti';
    host.setAttribute('aria-hidden', 'true');

    const colors = tone === 'activation' || tone === 'birthday'
      ? ['#f2d58b', '#f6b4ce', '#d9c7ff', '#cfeadc']
      : ['#e9c265', '#f2997e', '#f7d6bd', '#cadfca'];

    if (tone === 'birthday') {
      for (let balloonIndex = 0; balloonIndex < 7; balloonIndex += 1) {
        const balloon = document.createElement('div');
        balloon.className = 'ForestryRewardsBalloon';
        balloon.textContent = '🎈';
        balloon.style.setProperty('--balloon-x', (8 + balloonIndex * 14) + '%');
        balloon.style.setProperty('--balloon-delay', (balloonIndex * 90) + 'ms');
        host.appendChild(balloon);
      }
    }

    for (let i = 0; i < 18; i += 1) {
      const piece = document.createElement('span');
      piece.style.setProperty('--x', (Math.random() * 220 - 110).toFixed(0) + 'px');
      piece.style.setProperty('--r', (Math.random() * 520 - 260).toFixed(0) + 'deg');
      piece.style.setProperty('--delay', (Math.random() * 120).toFixed(0) + 'ms');
      piece.style.setProperty('--bg', colors[i % colors.length]);
      host.appendChild(piece);
    }

    root.appendChild(host);
    window.setTimeout(function () {
      host.remove();
    }, 1800);
  }

  function matchingCartDiscountTitles(cartDiscounts) {
    return cartDiscounts.map(function (discount) {
      return cleanString(discount && discount.title).toUpperCase();
    }).filter(Boolean);
  }

  function cartDiscountAmount(cartDiscounts) {
    return cartDiscounts.reduce(function (total, discount) {
      const cents = Number.parseFloat(discount && discount.total_allocated_amount);
      return total + (Number.isFinite(cents) ? cents / 100 : 0);
    }, 0);
  }

  function computeModel(statusResult, cartState) {
    const data = (statusResult && statusResult.data) || {};
    const birthday = data.birthday || {};
    const issuance = birthday.issuance || null;
    const rewardCodes = Array.isArray(data.reward_codes) ? data.reward_codes : [];
    const otherRewards = rewardCodes.filter(function (row) {
      return cleanString(row.redemption_code) !== cleanString(issuance && issuance.reward_code);
    });
    const cartDiscounts = cartState && cartState.ok && Array.isArray(cartState.data.cart_level_discount_applications)
      ? cartState.data.cart_level_discount_applications
      : [];
    const cartDiscountTitles = matchingCartDiscountTitles(cartDiscounts);
    const birthdayApplied = Boolean(issuance && (
      cartDiscountTitles.indexOf(cleanString(issuance.discount_title).toUpperCase()) >= 0 ||
      cartDiscountTitles.indexOf(cleanString(issuance.reward_code).toUpperCase()) >= 0
    ));
    const candleCashApplied = otherRewards.some(function (reward) {
      const code = cleanString(reward && reward.redemption_code).toUpperCase();
      const title = cleanString(reward && reward.discount_title).toUpperCase();
      return (code && cartDiscountTitles.indexOf(code) >= 0) || (title && cartDiscountTitles.indexOf(title) >= 0);
    });
    const summary = data.summary || {};
    const referral = data.referral || null;
    const balanceAmount = Number.parseFloat((data.balance && data.balance.amount) || summary.current_balance_amount || 0) || 0;
    const availableRewards = Array.isArray(data.available_rewards) ? data.available_rewards : [];
    const redemptionAccess = data.redemption_access || {};

    return {
      profileId: positiveInt(data.profile_id),
      state: normalizeState(data.state || ''),
      consentSms: bool(data.consent && data.consent.sms),
      consentEmail: bool(data.consent && data.consent.email),
      balance: Number.parseInt(String((data.balance && data.balance.points) || summary.current_balance_points || 0), 10) || 0,
      balanceAmount: balanceAmount,
      birthdayState: normalizeState(birthday.state || ''),
      birthday: birthday.birthday || null,
      birthdayIssuance: issuance,
      otherRewards: otherRewards,
      rewardCodes: rewardCodes,
      availableRewards: availableRewards,
      redemptionAccess: {
        redeemEnabled: bool(redemptionAccess.redeem_enabled),
        increment: Number.parseFloat((data.redemption_rules && data.redemption_rules.redeem_increment_dollars) || 10) || 10,
      },
      copy: data.copy || {},
      summary: summary,
      referral: referral,
      tasks: Array.isArray(data.tasks) ? data.tasks : [],
      taskHistory: Array.isArray(data.history && data.history.tasks) ? data.history.tasks : [],
      ledgerHistory: Array.isArray(data.history && data.history.ledger) ? data.history.ledger : [],
      cartAppliedBirthday: birthdayApplied,
      cartAppliedCandleCash: candleCashApplied,
      cartDiscountAmount: cartDiscountAmount(cartDiscounts),
      cartTotalAmount: cartState && cartState.ok ? (Number.parseFloat(cartState.data.total_price || 0) / 100) : 0,
      cartDiscounts: cartDiscounts,
    };
  }

  function statusTimeoutMs(root) {
    const requested = positiveInt(root.dataset.rewardsStatusTimeoutMs);
    return requested ? Math.min(requested, STATUS_REQUEST_TIMEOUT_MS) : STATUS_REQUEST_TIMEOUT_MS;
  }

  async function loadModel(root) {
    await persistReferralCodeToCart();

    const identity = buildIdentity(root);
    const query = queryFromIdentity(identity);
    const referralCode = activeReferralCode();
    if (referralCode) {
      query.set('ref', referralCode);
    }
    // Safari can retain a prior app-proxy status document across a Shopify
    // customer-account return. Ask for a fresh signed status only on that
    // return path so a successful sign-in never re-renders an old guest/$0
    // response.
    if (new URLSearchParams(window.location.search || '').get('shop_sign_in') === 'true') {
      query.set('storefront_session_refresh', String(Date.now()));
    }

    const status = await fetchContract(root, root.dataset.endpointCandleCashStatus, {
      method: 'GET',
      query: query,
      timeoutMs: statusTimeoutMs(root),
    });

    if (!status.ok && status.status === 404) {
      return {
        ok: false,
        title: 'Rewards are waking up.',
        detail: 'We are still finishing the storefront connection. Please check back in a moment.',
      };
    }

    if (!status.ok && status.error && status.error.code === 'unauthorized_storefront_request') {
      return {
        ok: false,
        title: 'Rewards are almost ready.',
        detail: 'The rewards connection is not active on this storefront yet.',
      };
    }

    if (!status.ok) {
      return {
        ok: false,
        title: 'Rewards unavailable',
        detail: status.error && status.error.message ? status.error.message : 'We could not load your rewards right now.',
        errorCode: status.error && status.error.code ? status.error.code : 'request_failed',
      };
    }

    const cartState = await fetchCartState();
    return { ok: true, model: computeModel(status, cartState) };
  }

  function viewSessionKey(root, model) {
    return ['forestryRewardsView', window.location.pathname, root.dataset.surface || 'page', model.profileId || 'guest'].join(':');
  }

  function maybeTrackView(root, model) {
    const key = viewSessionKey(root, model);
    if (!oncePerSession(key)) {
      return;
    }

    logRewardEvent(root, {
      event_type: 'reward_view',
      request_key: key,
      reward_kind: 'surface',
      surface: root.dataset.surface || 'page',
      state: model.state || 'linked_customer',
    });
  }

  function maybeHandleApplyMarker(root, model) {
    const marker = getApplyMarker();
    if (!marker || !marker.code) {
      return;
    }

    const titles = matchingCartDiscountTitles(model.cartDiscounts);
    const codeMatches = titles.indexOf(cleanString(marker.code).toUpperCase()) >= 0;
    const birthdayMatches = marker.kind === 'birthday' && model.cartAppliedBirthday;
    const candleCashMatches = marker.kind === 'candle_cash' && model.cartAppliedCandleCash;
    const surface = root.dataset.surface || 'page';

    if (codeMatches || birthdayMatches || candleCashMatches) {
      if (oncePerSession(marker.request_key + ':success-ui')) {
        showToast(root, marker.kind === 'birthday' ? 'Birthday reward applied!' : 'Reward code applied!', 'success');
        celebrate(root, marker.kind === 'birthday' ? 'birthday' : 'apply');
        logRewardEvent(root, {
          event_type: 'reward_apply_success',
          request_key: marker.request_key || ('reward-apply-success:' + marker.code),
          reward_code: marker.code,
          reward_kind: marker.kind || 'candle_cash',
          surface: surface,
          state: 'applied',
        });
        logRewardEvent(root, {
          event_type: 'reward_confetti_shown',
          request_key: (marker.request_key || marker.code) + ':confetti',
          reward_code: marker.code,
          reward_kind: marker.kind || 'candle_cash',
          surface: surface,
          state: 'applied',
        });
      }
      clearApplyMarker();
      return;
    }

    if ((surface === 'cart' || surface === 'drawer') && Date.now() - Number(marker.at || 0) < 5 * 60 * 1000) {
      if (oncePerSession(marker.request_key + ':failure-ui')) {
        showToast(root, 'We could not apply that reward automatically. Your code is still ready to use.', 'warning');
        logRewardEvent(root, {
          event_type: 'reward_apply_failure',
          request_key: marker.request_key + ':failure',
          reward_code: marker.code,
          reward_kind: marker.kind || 'candle_cash',
          surface: surface,
          state: 'discount_not_applied',
        });
      }
      clearApplyMarker();
    }
  }

  function setLivenessState(root, state) {
    root.dataset.forestryRewardsState = state;
    root.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
  }

  async function loadAndRender(root, options) {
    options = options || {};
    const source = cleanString(options.source) || (options.force ? 'retry' : 'initial');
    if (!document.documentElement.contains(root)) {
      return;
    }

    if (root.__forestryLoadPromise && !options.force) {
      return root.__forestryLoadPromise;
    }

    const requestId = (root.__forestryRequestId || 0) + 1;
    root.__forestryRequestId = requestId;
    setLivenessState(root, 'loading');
    const wasError = root.dataset.forestryRewardsLastState === 'error';

    root.__forestryLoadPromise = (async function () {
      const result = await loadModel(root);
      if (!document.documentElement.contains(root) || root.__forestryRequestId !== requestId) {
        return;
      }

      if (!result.ok) {
        root.dataset.forestryRewardsLastState = 'error';
        setLivenessState(root, 'error');
        renderUnavailable(root, result.title || 'Rewards unavailable', result.detail || 'We could not load your rewards right now.');
        logLivenessEvent(root, 'reward_widget_load_error', source, result.errorCode || 'request_failed');
        return;
      }

      root.dataset.forestryRewardsLastState = 'loaded';
      setLivenessState(root, 'loaded');
      render(root, result.model);
      maybeTrackView(root, result.model);
      maybeHandleApplyMarker(root, result.model);
      if (wasError || source === 'retry') {
        logLivenessEvent(root, 'reward_widget_recovered', source, 'loaded');
      }
    })();

    try {
      await root.__forestryLoadPromise;
    } finally {
      if (root.__forestryRequestId === requestId) {
        root.__forestryLoadPromise = null;
      }
    }
  }

  async function saveBirthday(root) {
    if (rootState(root).busy) {
      return;
    }

    const monthField = root.querySelector('[data-field="birth-month"]');
    const dayField = root.querySelector('[data-field="birth-day"]');
    const yearField = root.querySelector('[data-field="birth-year"]');
    const month = positiveInt(monthField && monthField.value);
    const day = positiveInt(dayField && dayField.value);
    const year = positiveInt(yearField && yearField.value);

    if (!month || !day) {
      showToast(root, 'Month and day are required.', 'warning');
      return;
    }

    markBusy(root, 'Saving your birthday…');
    const identity = buildIdentity(root);
    const result = await fetchContract(root, root.dataset.endpointBirthdayCapture, {
      method: 'POST',
      body: Object.assign({}, bodyFromIdentity(identity), {
        birth_month: month,
        birth_day: day,
        birth_year: year || null,
        source: 'shopify_rewards_surface',
      }),
    });

    if (!result.ok) {
      showToast(root, 'We could not save your birthday yet.', 'warning');
      await loadAndRender(root);
      return;
    }

    setRootState(root, { formOpen: false, busy: false, toast: 'Birthday saved. We will keep your reward ready here.', toastTone: 'success' });
    await loadAndRender(root);
    rerender(root);
  }

  async function activateBirthday(root) {
    if (rootState(root).busy) {
      return;
    }

    const model = computeLastModel(root);
    const issuance = model.birthdayIssuance;
    const requestKey = 'birthday-activate:' + (issuance && issuance.id ? issuance.id : Date.now());

    logRewardEvent(root, {
      event_type: 'reward_activate_click',
      request_key: requestKey,
      reward_code: issuance && issuance.reward_code ? issuance.reward_code : null,
      reward_kind: 'birthday',
      surface: root.dataset.surface || 'page',
      state: model.birthdayState || 'activate_requested',
    });

    markBusy(root, 'Unlocking your birthday reward…');
    const identity = buildIdentity(root);
    const result = await fetchContract(root, root.dataset.endpointBirthdayClaim, {
      method: 'POST',
      body: bodyFromIdentity(identity),
    });

    if (!result.ok) {
      logRewardEvent(root, {
        event_type: 'reward_activation_failure',
        request_key: requestKey + ':failure',
        reward_code: issuance && issuance.reward_code ? issuance.reward_code : null,
        reward_kind: 'birthday',
        surface: root.dataset.surface || 'page',
        state: result.error && result.error.code ? result.error.code : 'birthday_claim_failed',
      });
      showToast(root, 'We could not activate your reward yet. Try again in a moment.', 'warning');
      await loadAndRender(root);
      return;
    }

    const rewardIssuance = result.data && result.data.reward && result.data.reward.issuance
      ? result.data.reward.issuance
      : null;
    const rewardCode = rewardIssuance ? cleanString(rewardIssuance.reward_code) : '';
    const applyPath = rewardIssuance ? cleanString(rewardIssuance.apply_path) : '';

    logRewardEvent(root, {
      event_type: 'reward_activation_success',
      request_key: requestKey + ':success',
      reward_code: rewardCode,
      reward_kind: 'birthday',
      surface: root.dataset.surface || 'page',
      state: 'already_claimed',
    });

    if ((root.dataset.surface === 'cart' || root.dataset.surface === 'drawer') && rewardCode && applyPath) {
      setRootState(root, { busy: false });
      await performRewardApply(root, rewardCode, 'birthday', applyPath);
      return;
    }

    setRootState(root, { busy: false, toast: 'Reward unlocked and ready to use!', toastTone: 'success' });
    await loadAndRender(root);
    rerender(root);

    if (oncePerSession(requestKey + ':confetti')) {
      celebrate(root, 'birthday');
      logRewardEvent(root, {
        event_type: 'reward_confetti_shown',
        request_key: requestKey + ':confetti',
        reward_code: rewardCode,
        reward_kind: 'birthday',
        surface: root.dataset.surface || 'page',
        state: 'already_claimed',
      });
    }
  }

  function sameOriginUrl(url) {
    try {
      return new URL(url, window.location.origin).origin === window.location.origin;
    } catch (error) {
      return false;
    }
  }

  function sleep(ms) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, ms);
    });
  }

  function cartDataHasReward(cartData, rewardCode, rewardKind) {
    const applications = Array.isArray(cartData && cartData.cart_level_discount_applications)
      ? cartData.cart_level_discount_applications
      : [];
    const titles = matchingCartDiscountTitles(applications);
    const markerCode = cleanString(rewardCode).toUpperCase();
    const hasDiscount = cartDiscountAmount(applications) > 0;

    if (!hasDiscount) {
      return false;
    }
    if (markerCode && titles.indexOf(markerCode) >= 0) {
      return true;
    }
    if (rewardKind === 'birthday') {
      return titles.some(function (title) { return title.indexOf('BIRTHDAY') >= 0; });
    }
    return titles.some(function (title) {
      return title.indexOf('CANDLE CASH') >= 0 || title.indexOf('REWARD') >= 0;
    });
  }

  function cartDataHasExactReward(cartData, rewardCode) {
    const applications = Array.isArray(cartData && cartData.cart_level_discount_applications)
      ? cartData.cart_level_discount_applications
      : [];
    const target = cleanString(rewardCode).toUpperCase();

    return target !== '' && matchingCartDiscountTitles(applications).indexOf(target) >= 0;
  }

  async function waitForCartDiscount(rewardCode, rewardKind) {
    const startedAt = Date.now();
    let lastCart = null;

    while (Date.now() - startedAt < CART_DISCOUNT_SYNC_TIMEOUT_MS) {
      const cartState = await fetchCartState();
      if (cartState.ok) {
        lastCart = cartState.data;
        if (cartDataHasReward(lastCart, rewardCode, rewardKind)) {
          return { ok: true, data: lastCart };
        }
      }
      await sleep(CART_DISCOUNT_SYNC_POLL_MS);
    }

    return { ok: false, data: lastCart };
  }

  async function waitForCartRewardRemoval(rewardCode) {
    const startedAt = Date.now();
    let lastCart = null;

    while (Date.now() - startedAt < CART_DISCOUNT_SYNC_TIMEOUT_MS) {
      const cartState = await fetchCartState();
      if (cartState.ok) {
        lastCart = cartState.data;
        if (!cartDataHasExactReward(lastCart, rewardCode)) {
          return { ok: true, data: lastCart };
        }
      }
      await sleep(CART_DISCOUNT_SYNC_POLL_MS);
    }

    return { ok: false, data: lastCart };
  }

  function syncCartTotals(cartData) {
    const applications = Array.isArray(cartData && cartData.cart_level_discount_applications)
      ? cartData.cart_level_discount_applications
      : [];
    const discountAmount = cartDiscountAmount(applications);
    const totalAmount = Number.parseFloat(cartData && cartData.total_price || 0) / 100;
    const firstApplication = applications[0] || {};
    const label = cleanString(firstApplication.title) || 'Candle Cash';

    document.querySelectorAll('[data-forestry-cart-discount-row]').forEach(function (row) {
      row.hidden = discountAmount <= 0;
    });
    document.querySelectorAll('[data-forestry-cart-discount-label]').forEach(function (node) {
      node.textContent = label;
    });
    document.querySelectorAll('[data-forestry-cart-discount-value]').forEach(function (node) {
      node.textContent = currencyLabel(discountAmount) || '$0';
    });
    document.querySelectorAll('[data-forestry-cart-total]').forEach(function (node) {
      node.textContent = (currencyLabel(totalAmount) || '$0') + ' USD';
    });
  }

  async function performRewardApply(root, rewardCode, rewardKind, applyPath) {
    const surface = cleanString(root.dataset.surface || 'page');
    const inlineApply = (surface === 'cart' || surface === 'drawer') && sameOriginUrl(applyPath);

    if (!rewardCode || !applyPath) {
      showToast(root, 'That reward is not ready to apply yet.', 'warning');
      return;
    }

    const requestKey = rewardKind + '-apply:' + rewardCode;
    logRewardEvent(root, {
      event_type: 'reward_apply_click',
      request_key: requestKey,
      reward_code: rewardCode,
      reward_kind: rewardKind,
      surface: surface,
      state: 'apply_requested',
    });
    setApplyMarker({ request_key: requestKey, code: rewardCode, kind: rewardKind, at: Date.now() });
    setRootState(root, {
      busy: true,
      toast: rewardKind === 'birthday' ? 'Applying your birthday Candle Cash…' : 'Applying your Candle Cash…',
      toastTone: 'neutral',
    });
    rerender(root);

    if (!inlineApply) {
      window.location.assign(applyPath);
      return;
    }

    try {
      const response = await fetch(new URL(applyPath, window.location.origin).toString(), {
        method: 'GET',
        credentials: 'same-origin',
        redirect: 'follow',
        headers: { Accept: 'text/html,application/json;q=0.9,*/*;q=0.8' },
      });
      if (!response.ok) {
        throw new Error('reward_apply_failed');
      }

      const appliedCart = await waitForCartDiscount(rewardCode, rewardKind);
      if (!appliedCart.ok || !appliedCart.data) {
        window.location.assign(applyPath);
        return;
      }

      syncCartTotals(appliedCart.data);
      setRootState(root, { busy: false, toast: '', toastTone: 'success' });
      await loadAndRender(root, { force: true, source: 'reward_apply' });
    } catch (error) {
      clearApplyMarker();
      showToast(root, 'We could not apply that reward yet. Please try again.', 'warning');
      logRewardEvent(root, {
        event_type: 'reward_apply_failure',
        request_key: requestKey + ':network',
        reward_code: rewardCode,
        reward_kind: rewardKind,
        surface: surface,
        state: error && error.message ? error.message : 'reward_apply_failed',
      });
    }
  }

  async function applyReward(root, target) {
    if (rootState(root).busy) {
      return;
    }

    const applyPath = cleanString(target.getAttribute('data-apply-path'));
    const rewardCode = cleanString(target.getAttribute('data-code'));
    const rewardKind = cleanString(target.getAttribute('data-reward-kind')) || 'candle_cash';

    await performRewardApply(root, rewardCode, rewardKind, applyPath);
  }

  async function removeReward(root, target) {
    if (rootState(root).busy) {
      return;
    }

    const rewardCode = cleanString(target.getAttribute('data-code'));
    const model = computeLastModel(root);
    const activeTitles = matchingCartDiscountTitles(model.cartDiscounts);
    const codeToRemove = rewardCode.toUpperCase();
    const remainingCodes = activeTitles.filter(function (title) {
      return title !== codeToRemove;
    });

    if (!rewardCode || activeTitles.indexOf(codeToRemove) < 0) {
      showToast(root, 'Candle Cash is no longer applied to this cart.', 'success');
      await loadAndRender(root, { force: true, source: 'reward_remove_refresh' });
      return;
    }

    markBusy(root, 'Removing Candle Cash…');

    try {
      const response = await fetch('/cart/update.js', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ discount: remainingCodes.join(',') }),
      });

      if (!response.ok) {
        throw new Error('reward_remove_failed');
      }

      const cart = await response.json();
      const removedCart = !cartDataHasExactReward(cart, rewardCode)
        ? { ok: true, data: cart }
        : await waitForCartRewardRemoval(rewardCode);

      if (!removedCart.ok || !removedCart.data) {
        throw new Error('reward_remove_not_confirmed');
      }

      syncCartTotals(removedCart.data);
      setRootState(root, { busy: false, toast: 'Candle Cash removed. You can now use another eligible reward.', toastTone: 'success' });
      await loadAndRender(root, { force: true, source: 'reward_removed' });
    } catch (error) {
      showToast(root, 'We could not remove Candle Cash yet. Please try again.', 'warning');
      logRewardEvent(root, {
        event_type: 'reward_remove_failure',
        request_key: 'candle_cash-remove:' + rewardCode,
        reward_code: rewardCode,
        reward_kind: 'candle_cash',
        surface: root.dataset.surface || 'page',
        state: error && error.message ? error.message : 'reward_remove_failed',
      });
    }
  }

  async function redeemReward(root, target) {
    if (rootState(root).busy) {
      return;
    }

    const rewardId = positiveInt(target.getAttribute('data-reward-id'));
    const endpoint = cleanString(root.dataset.endpointRewardsRedeem);
    if (!rewardId || !endpoint) {
      showToast(root, 'This Candle Cash reward is not ready to use yet.', 'warning');
      return;
    }

    markBusy(root, 'Preparing your Candle Cash…');
    const identity = buildIdentity(root);
    const result = await fetchContract(root, endpoint, {
      method: 'POST',
      body: Object.assign({}, bodyFromIdentity(identity), {
        reward_id: rewardId,
        reuse_existing_code: true,
      }),
    });

    if (!result.ok) {
      showToast(root, 'We could not prepare your Candle Cash yet. Please try again.', 'warning');
      await loadAndRender(root, { force: true, source: 'redeem_failure' });
      return;
    }

    const rewardCode = cleanString(result.data && result.data.redemption_code);
    const applyPath = cleanString(result.data && result.data.apply_path);
    if (!rewardCode || !applyPath) {
      showToast(root, 'Your Candle Cash is ready, but needs a moment before it can be applied.', 'warning');
      await loadAndRender(root, { force: true, source: 'redeem_refresh' });
      return;
    }

    const requestKey = 'candle_cash-redeem:' + rewardCode;
    logRewardEvent(root, {
      event_type: 'reward_redeem_success',
      request_key: requestKey,
      reward_code: rewardCode,
      reward_kind: 'candle_cash',
      surface: root.dataset.surface || 'page',
      state: 'apply_requested',
    });
    setRootState(root, { busy: false });
    await performRewardApply(root, rewardCode, 'candle_cash', applyPath);
  }

  async function copyValue(root, value, successMessage) {
    if (!value) {
      return;
    }

    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(value);
      } else {
        const field = document.createElement('textarea');
        field.value = value;
        field.setAttribute('readonly', 'readonly');
        field.style.position = 'absolute';
        field.style.left = '-9999px';
        document.body.appendChild(field);
        field.select();
        document.execCommand('copy');
        field.remove();
      }

      showToast(root, successMessage, 'success');
    } catch (error) {
      showToast(root, 'Could not copy that yet. You can still use it manually.', 'warning');
    }
  }

  async function shareReferral(root, value) {
    const shareValue = cleanString(value);
    if (!shareValue) {
      showToast(root, 'Your referral link is not ready yet.', 'warning');
      return;
    }

    try {
      if (navigator.share) {
        await navigator.share({
          title: 'Candle Cash',
          text: 'Here is my Candle Cash link from Forestry.',
          url: shareValue,
        });
        showToast(root, 'Referral link ready to share.', 'success');
        return;
      }
    } catch (error) {
      // Fall back to copy.
    }

    await copyValue(root, shareValue, 'Referral link copied.');
  }

  function openTaskDestination(url) {
    const destination = cleanString(url);
    if (!destination) {
      return;
    }

    if (destination.charAt(0) === '#') {
      const el = document.querySelector(destination);
      if (el && el.scrollIntoView) {
        el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
      } else {
        window.location.hash = destination.substring(1);
      }
      return;
    }

    window.open(destination, '_blank', 'noopener');
  }

  async function submitTask(root, target) {
    if (rootState(root).busy) {
      return;
    }

    const taskHandle = cleanString(target.getAttribute('data-task-handle'));
    if (!taskHandle) {
      return;
    }

    const requiresProof = bool(target.getAttribute('data-task-requires-proof'));
    const taskCard = target.closest('[data-task-card]');
    const proofUrlField = taskCard && taskCard.querySelector('[data-task-proof-url="' + taskHandle + '"]');
    const proofTextField = taskCard && taskCard.querySelector('[data-task-proof-text="' + taskHandle + '"]');
    const proofUrl = cleanString(proofUrlField && proofUrlField.value);
    const proofText = cleanString(proofTextField && proofTextField.value);
    const openUrl = cleanString(target.getAttribute('data-open-url'));
    const requestKey = 'task-submit:' + taskHandle + ':' + Date.now();

    if (requiresProof && !proofUrl && !proofText) {
      showToast(root, 'Add a note or proof link so the team can review it.', 'warning');
      return;
    }

    if (openUrl) {
      openTaskDestination(openUrl);
    }

    logRewardEvent(root, {
      event_type: 'reward_task_click',
      request_key: requestKey,
      reward_kind: 'candle_cash_task',
      surface: root.dataset.surface || 'page',
      state: taskHandle,
    });

    markBusy(root, 'Saving your task…');
    const identity = buildIdentity(root);
    const result = await fetchContract(root, root.dataset.endpointCandleCashTaskSubmit, {
      method: 'POST',
      body: Object.assign({}, bodyFromIdentity(identity), {
        task_handle: taskHandle,
        proof_url: proofUrl || null,
        proof_text: proofText || null,
        request_key: requestKey,
      }),
    });

    if (!result.ok) {
      const failureCode = result.error && result.error.code ? result.error.code : 'task_submit_failed';
      logRewardEvent(root, {
        event_type: 'reward_task_failure',
        request_key: requestKey + ':failure',
        reward_kind: 'candle_cash_task',
        surface: root.dataset.surface || 'page',
        state: failureCode,
      });
      setRootState(root, { openTaskHandle: taskHandle });
      showToast(root, failureCode === 'auto_verified_task' ? 'That reward lands automatically once the verified event happens.' : 'We could not save that task yet. Try again in a moment.', 'warning');
      await loadAndRender(root);
      return;
    }

    const completionState = normalizeState(result.data && result.data.state);
    logRewardEvent(root, {
      event_type: 'reward_task_success',
      request_key: requestKey + ':success',
      reward_kind: 'candle_cash_task',
      surface: root.dataset.surface || 'page',
      state: completionState || 'saved',
    });

    setRootState(root, {
      busy: false,
      openTaskHandle: '',
      toast: completionState === 'awarded' ? 'Candle Cash added to your account.' : 'We saved the action. Candle Cash will land once the event verifies.',
      toastTone: 'success',
    });
    await loadAndRender(root);
    rerender(root);

    if (completionState === 'awarded' && oncePerSession(requestKey + ':confetti')) {
      celebrate(root, 'activation');
      logRewardEvent(root, {
        event_type: 'reward_confetti_shown',
        request_key: requestKey + ':confetti',
        reward_kind: 'candle_cash_task',
        surface: root.dataset.surface || 'page',
        state: 'awarded',
      });
    }
  }

  async function startGoogleReview(root, target) {
    if (rootState(root).busy) {
      return;
    }

    const destination = cleanString(target.getAttribute('data-open-url'));
    const requestKey = 'google-review-start:' + Date.now();

    logRewardEvent(root, {
      event_type: 'reward_task_click',
      request_key: requestKey,
      reward_kind: 'candle_cash_task',
      surface: root.dataset.surface || 'page',
      state: 'google-review',
    });

    if (!cleanString(root.dataset.endpointGoogleReviewStart)) {
      openTaskDestination(destination);
      return;
    }

    markBusy(root, 'Opening Google review…');

    const identity = buildIdentity(root);
    const result = await fetchContract(root, root.dataset.endpointGoogleReviewStart, {
      method: 'POST',
      body: Object.assign({}, bodyFromIdentity(identity), {
        request_key: requestKey,
      }),
    });

    if (!result.ok) {
      const failureCode = result.error && result.error.code ? result.error.code : 'google_review_not_ready';
      logRewardEvent(root, {
        event_type: 'reward_task_failure',
        request_key: requestKey + ':failure',
        reward_kind: 'candle_cash_task',
        surface: root.dataset.surface || 'page',
        state: failureCode,
      });
      showToast(root, 'Google review matching is not ready yet. Try again in a moment.', 'warning');
      await loadAndRender(root);
      return;
    }

    const reviewUrl = cleanString(result.data && result.data.review_url) || destination;
    logRewardEvent(root, {
      event_type: 'reward_task_success',
      request_key: requestKey + ':success',
      reward_kind: 'candle_cash_task',
      surface: root.dataset.surface || 'page',
      state: 'google_review_started',
    });

    setRootState(root, {
      busy: false,
      toast: 'Google review opened. We will match it automatically once it posts.',
      toastTone: 'success',
    });
    rerender(root);

    if (reviewUrl) {
      openTaskDestination(reviewUrl);
    }
  }

  async function submitEmailSignup(root, target) {
    if (rootState(root).busy) {
      return;
    }

    const taskHandle = cleanString(target.getAttribute('data-task-handle')) || 'email-signup';
    const emailField = root.querySelector('[data-task-email="' + taskHandle + '"]');
    const firstNameField = root.querySelector('[data-task-first-name="' + taskHandle + '"]');
    const lastNameField = root.querySelector('[data-task-last-name="' + taskHandle + '"]');
    const email = cleanString(emailField && emailField.value);
    const firstName = cleanString(firstNameField && firstNameField.value);
    const lastName = cleanString(lastNameField && lastNameField.value);

    if (!email) {
      showToast(root, 'Add your email first.', 'warning');
      return;
    }

    const requestKey = 'email-signup:' + email.toLowerCase();

    logRewardEvent(root, {
      event_type: 'reward_task_click',
      request_key: requestKey,
      reward_kind: 'candle_cash_task',
      surface: root.dataset.surface || 'page',
      state: taskHandle,
    });

    markBusy(root, 'Saving your email signup…');
    const identity = buildIdentity(root);
    const result = await fetchContract(root, root.dataset.endpointConsentOptin, {
      method: 'POST',
      body: Object.assign({}, bodyFromIdentity(identity), {
        email: email,
        first_name: firstName || null,
        last_name: lastName || null,
        consent_email: true,
        award_bonus: false,
        flow: 'direct',
      }),
    });

    if (!result.ok) {
      logRewardEvent(root, {
        event_type: 'reward_task_failure',
        request_key: requestKey + ':failure',
        reward_kind: 'candle_cash_task',
        surface: root.dataset.surface || 'page',
        state: result.error && result.error.code ? result.error.code : 'email_signup_failed',
      });
      setRootState(root, { busy: false, openTaskHandle: taskHandle });
      showToast(root, 'We could not save your email signup yet.', 'warning');
      return;
    }

    root.dataset.customerEmail = email;
    root.dataset.customerFirstName = firstName;
    root.dataset.customerLastName = lastName;
    if (result.data && result.data.profile_id) {
      root.dataset.marketingProfileId = String(result.data.profile_id);
    }

    logRewardEvent(root, {
      event_type: 'reward_task_success',
      request_key: requestKey + ':success',
      reward_kind: 'candle_cash_task',
      surface: root.dataset.surface || 'page',
      state: 'awarded',
    });

    setRootState(root, {
      busy: false,
      openTaskHandle: '',
      toast: 'Email saved. $5 in Candle Cash is now attached to your account.',
      toastTone: 'success',
    });

    await loadAndRender(root);
    rerender(root);

    if (oncePerSession(requestKey + ':confetti')) {
      celebrate(root, 'activation');
      logRewardEvent(root, {
        event_type: 'reward_confetti_shown',
        request_key: requestKey + ':confetti',
        reward_kind: 'candle_cash_task',
        surface: root.dataset.surface || 'page',
        state: 'awarded',
      });
    }
  }

  async function submitSmsSignup(root, target) {
    if (rootState(root).busy) {
      return;
    }

    const taskHandle = cleanString(target.getAttribute('data-task-handle')) || 'sms-signup';
    const phoneField = root.querySelector('[data-task-phone="' + taskHandle + '"]');
    const firstNameField = root.querySelector('[data-task-first-name="' + taskHandle + '"]');
    const lastNameField = root.querySelector('[data-task-last-name="' + taskHandle + '"]');
    const phone = cleanString(phoneField && phoneField.value);
    const firstName = cleanString(firstNameField && firstNameField.value);
    const lastName = cleanString(lastNameField && lastNameField.value);

    if (!phone) {
      showToast(root, 'Add your phone number first.', 'warning');
      return;
    }

    const requestKey = 'sms-signup:' + phone.replace(/\D+/g, '');

    logRewardEvent(root, {
      event_type: 'reward_task_click',
      request_key: requestKey,
      reward_kind: 'candle_cash_task',
      surface: root.dataset.surface || 'page',
      state: taskHandle,
    });

    markBusy(root, 'Saving your text signup…');
    const identity = buildIdentity(root);
    const result = await fetchContract(root, root.dataset.endpointConsentOptin, {
      method: 'POST',
      body: Object.assign({}, bodyFromIdentity(identity), {
        phone: phone,
        first_name: firstName || null,
        last_name: lastName || null,
        consent_sms: true,
        award_bonus: false,
        flow: 'direct',
      }),
    });

    if (!result.ok) {
      logRewardEvent(root, {
        event_type: 'reward_task_failure',
        request_key: requestKey + ':failure',
        reward_kind: 'candle_cash_task',
        surface: root.dataset.surface || 'page',
        state: result.error && result.error.code ? result.error.code : 'sms_signup_failed',
      });
      setRootState(root, { busy: false, openTaskHandle: taskHandle });
      showToast(root, 'We could not save your text signup yet.', 'warning');
      return;
    }

    root.dataset.customerPhone = phone;
    root.dataset.customerFirstName = firstName;
    root.dataset.customerLastName = lastName;
    if (result.data && result.data.profile_id) {
      root.dataset.marketingProfileId = String(result.data.profile_id);
    }

    logRewardEvent(root, {
      event_type: 'reward_task_success',
      request_key: requestKey + ':success',
      reward_kind: 'candle_cash_task',
      surface: root.dataset.surface || 'page',
      state: 'awarded',
    });

    setRootState(root, {
      busy: false,
      openTaskHandle: '',
      toast: 'Texts are on. Candle Cash is now attached to your account.',
      toastTone: 'success',
    });

    await loadAndRender(root);
    rerender(root);

    if (oncePerSession(requestKey + ':confetti')) {
      celebrate(root, 'activation');
      logRewardEvent(root, {
        event_type: 'reward_confetti_shown',
        request_key: requestKey + ':confetti',
        reward_kind: 'candle_cash_task',
        surface: root.dataset.surface || 'page',
        state: 'awarded',
      });
    }
  }

  async function handleAction(root, target) {
    const action = cleanString(target.getAttribute('data-action'));

    if (action === 'refresh-status') {
      logLivenessEvent(root, 'reward_widget_retry', 'retry', 'requested');
      await loadAndRender(root, { force: true });
      return;
    }

    if (action === 'toggle-birthday-form') {
      setRootState(root, {
        formOpen: !rootState(root).formOpen,
        toast: '',
        toastTone: 'neutral',
      });
      rerender(root);
      return;
    }

    if (action === 'save-birthday') {
      await saveBirthday(root);
      return;
    }

    if (action === 'activate-birthday') {
      await activateBirthday(root);
      return;
    }

    if (action === 'apply-reward') {
      await applyReward(root, target);
      return;
    }

    if (action === 'remove-reward') {
      await removeReward(root, target);
      return;
    }

    if (action === 'redeem-reward') {
      await redeemReward(root, target);
      return;
    }

    if (action === 'copy-code') {
      await copyValue(root, cleanString(target.getAttribute('data-code')), 'Reward code copied.');
      return;
    }

    if (action === 'copy-referral') {
      await copyValue(root, cleanString(target.getAttribute('data-referral-value')), 'Referral link copied.');
      return;
    }

    if (action === 'share-referral') {
      await shareReferral(root, cleanString(target.getAttribute('data-referral-value')));
      return;
    }

    if (action === 'toggle-task-form') {
      const handle = cleanString(target.getAttribute('data-task-handle'));
      setRootState(root, {
        openTaskHandle: rootState(root).openTaskHandle === handle ? '' : handle,
        toast: '',
        toastTone: 'neutral',
      });
      rerender(root);
      return;
    }

    if (action === 'submit-task') {
      await submitTask(root, target);
      return;
    }

    if (action === 'start-google-review') {
      await startGoogleReview(root, target);
      return;
    }

    if (action === 'submit-email-signup') {
      await submitEmailSignup(root, target);
      return;
    }

    if (action === 'submit-sms-signup') {
      await submitSmsSignup(root, target);
    }
  }

  function registerEvents(root) {
    root.addEventListener('click', function (event) {
      const target = event.target.closest('[data-action]');
      if (!target || !root.contains(target)) {
        return;
      }

      event.preventDefault();
      handleAction(root, target);
    });
  }

  function observeDrawer(root) {
    const drawer = root.closest('.Drawer');
    if (!drawer) {
      loadAndRender(root, { source: 'mount' });
      return;
    }

    const hydrateIfOpen = function () {
      if (drawer.getAttribute('aria-hidden') === 'false') {
        loadAndRender(root, { source: 'drawer_open' });
      }
    };

    hydrateIfOpen();
    const observer = new MutationObserver(hydrateIfOpen);
    observer.observe(drawer, { attributes: true, attributeFilter: ['aria-hidden'] });
    root.__forestryDrawerObserver = observer;
  }

  function mount(root, source) {
    if (runtime.mounted.has(root)) {
      return;
    }

    runtime.mounted.add(root);
    logLivenessEvent(root, 'reward_widget_mount', source || 'initial', 'mounted');
    if (missingStorefrontIdentityAfterShopifyLogin(root)) {
      logLivenessEvent(root, 'reward_widget_storefront_identity_missing', 'shop_sign_in', 'guest');
    }
    if ((root.dataset.surface || '') === 'page') {
      const requestedTask = requestedTaskHandleFromUrl();
      if (requestedTask) {
        setRootState(root, { openTaskHandle: requestedTask });
      }
    }
    registerEvents(root);

    if ((root.dataset.surface || '') === 'drawer') {
      observeDrawer(root);
      return;
    }

    loadAndRender(root, { source: source || 'initial' });
  }

  function pruneDetachedRoots() {
    Array.from(runtime.mounted).forEach(function (root) {
      if (!root || !document.documentElement.contains(root)) {
        if (root && root.__forestryDrawerObserver) {
          root.__forestryDrawerObserver.disconnect();
        }
        runtime.mounted.delete(root);
      }
    });
  }

  function refreshMounted(source) {
    pruneDetachedRoots();
    scan(source || 'refresh');
    runtime.mounted.forEach(function (root) {
      if (root && document.documentElement.contains(root)) {
        loadAndRender(root, { force: true, source: source || 'refresh' });
      }
    });
  }

  function scan(source) {
    activeReferralCode();
    document.querySelectorAll(ROOT_SELECTOR).forEach(function (root) {
      mount(root, source || 'initial');
    });
  }

  function queueScan(source) {
    if (runtime.domScanTimer) {
      return;
    }
    runtime.domScanTimer = window.setTimeout(function () {
      runtime.domScanTimer = null;
      scan(source || 'dom_insert');
    }, 0);
  }

  function observeRewardRoots() {
    if (runtime.domObserver || !document.documentElement) {
      return;
    }

    runtime.domObserver = new MutationObserver(function (mutations) {
      const hasInsertedRoot = mutations.some(function (mutation) {
        return Array.from(mutation.addedNodes).some(function (node) {
          return node.nodeType === Node.ELEMENT_NODE && (
            node.matches(ROOT_SELECTOR) || node.querySelector(ROOT_SELECTOR)
          );
        });
      });
      if (hasInsertedRoot) {
        queueScan('dom_insert');
      }
    });
    runtime.domObserver.observe(document.documentElement, { childList: true, subtree: true });
  }

  function initialize() {
    observeRewardRoots();
    scan('initial');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }

  document.addEventListener('forestry:rewards:refresh', function () {
    refreshMounted('manual_refresh');
  });
  document.documentElement.addEventListener('cart:refresh', function () {
    window.setTimeout(function () {
      refreshMounted('cart_refresh');
    }, 180);
  });
  document.addEventListener('shopify:section:load', function () {
    queueScan('section_load');
  });
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      refreshMounted('pageshow');
    }
  });
})();
