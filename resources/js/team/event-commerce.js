const el = (tag, text = '', cls = '') => { const n = document.createElement(tag); n.textContent = text; n.className = cls; return n; };
const money = (n, currency = 'USD') => Number.isSafeInteger(n) && /^[A-Z]{3}$/.test(currency) ? new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(n / 100) : 'Needs review';
const button = (name, run) => { const n = el('button', name); n.type = 'button'; n.onclick = run; return n; };
const date = value => { const d = new Date(value); return Number.isFinite(d.valueOf()) ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(d) : value; };

export function installEventCommerce(host, { record, api, tenant, current }) {
  const panel = el('section', '', 'event-panel event-commerce'); panel.hidden = true;
  const nav = el('nav', '', 'event-commerce-tabs'), content = el('div'), feedback = el('p', '', 'event-soft event-small');
  nav.setAttribute('aria-label', 'Event workspace'); feedback.setAttribute('role', 'status'); panel.append(nav, feedback, content); host.prepend(panel);
  let active = true, tab = null, permissions = {}, eventID, page = 1, query = '', serial = 0, timer, searchTimer, detail = false, loading = false, searchInput, customerResults;
  const valid = ticket => active && current() && ticket === serial;
  const fail = (error, ticket, retry = load) => { if (valid(ticket)) { feedback.textContent = error.message || 'This view could not refresh. Your event remains open.'; feedback.append(button('Try again', retry)); } };
  const newLink = (name, path) => { const link = el('a', name); link.href = path; link.target = '_blank'; link.rel = 'noopener'; return link; };
  function select(next) { serial++; tab = next; page = 1; detail = false; drawNav(); load(); }
  function drawNav() {
    nav.replaceChildren();
    if (permissions.sales) { const b = button('Sales', () => select('sales')); b.setAttribute('aria-current', tab === 'sales' ? 'page' : 'false'); nav.append(b); }
    if (permissions.customers) { const b = button('Customers', () => select('customers')); b.setAttribute('aria-current', tab === 'customers' ? 'page' : 'false'); nav.append(b); }
    if (permissions.audience && eventID) nav.append(newLink('Marketing audience', '/marketing/event-audiences?tenant=' + encodeURIComponent(tenant) + '&event_id=' + eventID));
  }
  function stats(data) {
    const grid = el('dl', '', 'event-facts');
    for (const [title, value] of [['Net sales', money(data.net_sales_minor)], ['Orders', data.sale_count], ['Sales tax', money(data.sales_tax_minor)], ['Collected after refunds', money(data.collected_total_minor)], ['Confirmed refunds', money(data.confirmed_refunds_minor)], ['Average order', money(data.average_order_minor)]]) {
      const box = el('div', '', 'event-fact'); box.append(el('dt', title), el('dd', String(value))); grid.append(box);
    }
    content.append(grid);
    if (data.customer_counts) content.append(el('p', data.customer_counts.unique_buyers + ' customers · ' + data.customer_counts.attributed_orders + ' attributed orders · ' + data.customer_counts.unattributed_orders + ' to attribute', 'event-soft'));
    if (data.review_items?.length) content.append(el('p', data.review_items.join(' '), 'event-detail-status'));
    if (data.customer_review_items?.length) content.append(el('p', data.customer_review_items.join(' '), 'event-detail-status'));
    content.append(el('p', data.coverage, 'event-small event-soft'));
    const breakdown = el('details'); breakdown.append(el('summary', 'Tenders and products'));
    for (const row of data.tenders || []) breakdown.append(el('p', row.tender + ' · ' + money(row.collected_minor) + ' collected · ' + (row.refunded_minor === null ? 'refund allocation needs review' : money(row.refunded_minor) + ' refunded')));
    breakdown.append(el('p', 'Recorded product and scent quantities before returns', 'event-small event-soft'));
    for (const row of data.products || []) breakdown.append(el('p', row.quantity + ' × ' + row.title + ' · ' + money(row.subtotal_minor)));
    content.append(breakdown);
    if (data.source_freshness?.square_oldest_selected_sync) content.append(el('p', 'Square source last checked ' + date(data.source_freshness.square_oldest_selected_sync), 'event-small event-soft'));
  }
  async function load() {
    if (!active || !current() || detail || !eventID || !tab) return;
    const ticket = ++serial, selected = tab, requestedPage = page, requestedQuery = query; loading = true;
    feedback.textContent = 'Refreshing ' + selected + '…';
    try {
      if (selected === 'sales') {
        const result = await api('/events/' + record.id + '/confirmed-sales', { query: { page: requestedPage } });
        if (!valid(ticket)) return; content.replaceChildren(); stats(result.report.sales);
        content.append(el('h3', 'Sales'));
        for (const sale of result.sales) {
          const row = el('details', '', 'event-sale'); const heading = el('summary', (sale.customer ? (sale.customer.name || 'Customer') : (sale.customer_review_required ? 'Customer needs review' : 'Unattributed purchase')) + ' · ' + money(sale.amount_minor)); row.append(heading);
          row.append(el('p', sale.number + ' · ' + sale.tender + ' · ' + date(sale.occurred_at), 'event-small event-soft'));
          for (const line of sale.lines) row.append(el('p', line.quantity + ' × ' + line.title));
          if (sale.refunded_minor === null) row.append(el('p', 'Refund coverage needs review.', 'event-small event-soft'));
          else if (sale.refunded_minor) row.append(el('p', 'Refunded ' + money(sale.refunded_minor)));
          if (sale.customer && permissions.customers) row.append(button('View customer', () => showCustomer(sale.customer.id)));
          else if (sale.customer_review_required) row.append(el('p', 'Review this purchase’s original customer association before making changes.', 'event-small event-soft'));
          else if (!sale.customer) row.append(el('p', 'Add this customer from Sales in Everbranch Pay.', 'event-small event-soft'));
          content.append(row);
        }
        if (!result.sales.length) content.append(el('p', 'Confirmed sales for this event will appear here. Review the event register and Square allocation if sales are missing.', 'event-soft'));
        pagination(result.has_more); feedback.textContent = 'Updated ' + date(result.report.sales.as_of);
      } else {
        if (!searchInput?.isConnected) {
          content.replaceChildren(); const form = el('form', '', 'event-customer-search'); searchInput = el('input'); searchInput.type = 'search'; searchInput.value = requestedQuery; searchInput.placeholder = 'Name, phone or email'; searchInput.setAttribute('aria-label', 'Search event customers');
          searchInput.oninput = () => { serial++; query = searchInput.value; page = 1; clearTimeout(searchTimer); searchTimer = setTimeout(load, 250); }; form.onsubmit = e => { e.preventDefault(); searchInput.blur(); load(); }; form.append(searchInput); customerResults = el('div', '', 'event-customer-results'); content.append(form, customerResults);
        }
        const result = await api('/customers', { query: { event_id: eventID, query: requestedQuery, page: requestedPage } });
        if (!valid(ticket)) return;
        const list = el('div', '', 'event-customer-list');
        for (const customer of result.customers) { const row = button(customer.name, () => showCustomer(customer.id)); row.append(el('span', [customer.phone, customer.email].filter(Boolean).join(' · '), 'event-small event-soft')); list.append(row); }
        if (!result.customers.length) list.append(el('p', 'No matching captured contacts or confirmed buyers. Attribution added in Pay appears here automatically.', 'event-soft'));
        customerResults.replaceChildren(list); if (result.event_context?.review_items?.length) customerResults.append(el('p', result.event_context.review_items.join(' '), 'event-small event-soft'));
        pagination(result.has_more, customerResults); feedback.textContent = 'Captured contacts and confirmed buyers · updated just now';
      }
    } catch (error) { fail(error, ticket); } finally { if (valid(ticket)) loading = false; }
  }
  function pagination(more, target = content) { const controls = el('div', '', 'event-commerce-tabs'); if (page > 1) controls.append(button('Previous', () => { page--; load(); })); if (more) controls.append(button('More', () => { page++; load(); })); target.append(controls); }
  async function showCustomer(id) {
    detail = true; const ticket = ++serial; feedback.textContent = 'Loading customer…';
    try {
      const result = await api('/customers/' + id); if (!valid(ticket)) return;
      content.replaceChildren(button('Back to ' + tab, () => { detail = false; load(); }), el('h3', result.customer.name));
      content.append(el('p', [result.customer.phone, result.customer.email].filter(Boolean).join(' · ')), newLink('Open customer', '/workspace/customers/' + id + '?tenant=' + encodeURIComponent(tenant) + '&event_id=' + eventID));
      const rewards = result.commerce.rewards; if (rewards?.available) content.append(el('p', (rewards.label || 'Rewards') + ' · ' + rewards.balance));
      for (const purchase of result.commerce.purchases) content.append(el('p', purchase.number + ' · ' + (purchase.confirmed ? money(purchase.amount_minor, purchase.currency) : 'Unverified record') + ' · ' + date(purchase.occurred_at)));
      if (result.commerce.has_more) content.append(el('p', 'Open the customer to review older linked history.', 'event-small event-soft'));
      feedback.textContent = 'Customer purchases and rewards';
    } catch (error) { fail(error, ticket, () => showCustomer(id)); }
  }
  timer = setInterval(() => { if (!document.hidden && !loading && !detail && page === 1 && !document.activeElement?.matches('input,textarea,select') && !panel.querySelector('.event-sale[open]')) load(); }, 5000);
  return {
    update(data) {
      permissions = { sales: data.sales?.can_view === true, customers: data.can_lookup_customers === true, audience: data.can_prepare_audiences === true };
      const nextEvent = data.commerce_event_id;
      if (eventID !== nextEvent) { serial++; tab = null; detail = false; page = 1; loading = false; content.replaceChildren(); }
      eventID = nextEvent; panel.hidden = !permissions.sales && !permissions.customers;
      if (panel.hidden) { serial++; content.replaceChildren(); return; }
      if (!eventID) { content.replaceChildren(el('p', 'Link this occurrence to its reviewed event and register to connect sales and customers.', 'event-soft')); drawNav(); return; }
      if (!tab || !permissions[tab]) select(permissions.sales ? 'sales' : 'customers'); else drawNav();
    },
    dispose() { active = false; serial++; clearInterval(timer); clearTimeout(searchTimer); }
  };
}
