const el = (tag, text = '', cls = '') => { const n = document.createElement(tag); n.textContent = text; n.className = cls; return n; };
const money = (n, currency = 'USD') => Number.isSafeInteger(n) && /^[A-Z]{3}$/.test(currency) ? new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(n / 100) : 'Needs review';
const button = (name, run) => { const n = el('button', name); n.type = 'button'; n.onclick = run; return n; };
const date = value => { const d = new Date(value); return Number.isFinite(d.valueOf()) ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(d) : value; };
const restricted = error => [401, 403, 404, 409, 419].includes(error.status);
function fold(title, cls) { const panel = el('details', '', 'event-panel ' + cls), heading = el('summary', title), content = el('div'); heading.setAttribute('role', 'button'); panel.append(heading, content); return { panel, content }; }

export function installEventCommerce(host, { record, api, tenant, current }) {
  const panel = el('section', '', 'event-commerce'); panel.hidden = true;
  const sales = fold('Sales', 'event-sales-panel'), customers = fold('Customers', 'event-customers-panel'); panel.append(sales.panel, customers.panel); host.prepend(panel);
  let active = true, accessBlocked = false, permissions = {}, eventID, salesSerial = 0, customerSerial = 0, summaryController, rowController, customerController, searchTimer;
  let transactions, transactionRows, summaryHost, salesFeedback, rowFeedback, page = 1, customerPage = 1, query = '', customerDetail = false, searchInput, customerResults, customerFeedback, salesLoading = false;
  const valid = (kind, ticket) => active && !accessBlocked && current() && !panel.hidden && (kind === 'sales' ? sales.panel.open && ticket === salesSerial : customers.panel.open && ticket === customerSerial);
  const newLink = (name, path) => { const link = el('a', name); link.href = path; link.target = '_blank'; link.rel = 'noopener'; return link; };
  function stopSales(clear = false) { salesSerial++; summaryController?.abort(); rowController?.abort(); salesLoading = false; if (clear) { sales.content.replaceChildren(); transactions = transactionRows = summaryHost = salesFeedback = rowFeedback = null; page = 1; } }
  function stopCustomers(clear = false) { customerSerial++; customerController?.abort(); clearTimeout(searchTimer); if (clear) { customers.content.replaceChildren(); searchInput = customerResults = customerFeedback = null; customerDetail = false; customerPage = 1; } }
  function accessChanged(error) { accessBlocked = true; stopSales(true); stopCustomers(true); for (const view of [sales, customers]) if (!view.panel.hidden) view.content.append(el('p', error.message || 'Event access changed. Reopen this event to check current access.', 'event-detail-status')); }
  function fail(error, kind, ticket, target, retry) { if (!valid(kind, ticket) || error.name === 'AbortError') return; if (restricted(error)) { accessChanged(error); return; } target.replaceChildren(document.createTextNode(error.message || 'This view could not refresh.'), button('Try again', retry)); }
  function stats(data) {
    const content = document.createDocumentFragment(), grid = el('dl', '', 'event-facts');
    for (const [title, value] of [['Collected before refunds', money(data.paid_total_minor)], ['Orders', data.sale_count], ['Net sales', money(data.net_sales_minor)], ['Sales tax', money(data.sales_tax_minor)], ['Collected after refunds', money(data.collected_total_minor)], ['Recorded refunds', money(data.confirmed_refunds_minor)], ['Average order', data.sale_count === 0 ? 'No sales yet' : money(data.average_order_minor)]]) { const box = el('div', '', 'event-fact'); box.append(el('dt', title), el('dd', String(value))); grid.append(box); }
    content.append(grid);
    if (data.refunds_complete === false) content.append(el('p', 'Recorded refunds cover available evidence only. Refund coverage and net totals need review.', 'event-detail-status'));
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
    return content;
  }
  function salesShell() {
    sales.content.replaceChildren(); summaryHost = el('div'); salesFeedback = el('p', '', 'event-soft event-small'); salesFeedback.setAttribute('role', 'status');
    const transactionView = fold('Transactions', 'event-transactions'); transactions = transactionView.panel; transactionRows = transactionView.content; rowFeedback = el('p', '', 'event-soft event-small'); rowFeedback.setAttribute('role', 'status'); transactionRows.append(rowFeedback);
    transactions.ontoggle = () => { if (!active || accessBlocked || !current()) return; if (transactions?.open) { summaryController?.abort(); void loadRows(); } else { rowController?.abort(); transactionRows?.replaceChildren(); } };
    sales.content.append(salesFeedback, summaryHost, transactions);
  }
  async function loadSummary() {
    if (!active || accessBlocked || !current() || !sales.panel.open || !permissions.sales || !eventID || transactions?.open) return;
    const ticket = ++salesSerial; summaryController?.abort(); summaryController = new AbortController(); salesLoading = true;
    if (!summaryHost?.isConnected) salesShell(); salesFeedback.textContent = 'Loading sales summary…';
    try { const result = await api('/events/' + record.id + '/confirmed-sales', { query: { summary: 1 }, signal: summaryController.signal }); if (!valid('sales', ticket) || transactions?.open) return; summaryHost.replaceChildren(stats(result.report.sales)); salesFeedback.textContent = 'Updated ' + date(result.report.sales.as_of); }
    catch (error) { fail(error, 'sales', ticket, salesFeedback, loadSummary); } finally { if (valid('sales', ticket)) salesLoading = false; }
  }
  function pagination(more, currentPage, run, target) { const controls = el('div', '', 'event-commerce-tabs'); if (currentPage > 1) controls.append(button('Previous', () => run(currentPage - 1))); if (more) controls.append(button('More', () => run(currentPage + 1))); target.append(controls); }
  async function loadRows(nextPage = page) {
    if (!active || accessBlocked || !current() || !sales.panel.open || !transactions?.open || !permissions.sales || !eventID) return;
    const ticket = ++salesSerial; page = nextPage; rowController?.abort(); rowController = new AbortController(); salesLoading = true;
    transactionRows.replaceChildren(rowFeedback); rowFeedback.textContent = 'Loading transactions…';
    try {
      const result = await api('/events/' + record.id + '/confirmed-sales', { query: { page, summary: 0 }, signal: rowController.signal });
      if (!valid('sales', ticket) || !transactions?.open) return;
      summaryHost.replaceChildren(stats(result.report.sales)); transactionRows.replaceChildren(rowFeedback);
      for (const sale of result.sales) {
        const row = el('details', '', 'event-sale'); row.append(el('summary', (sale.customer ? (sale.customer.name || 'Customer') : (sale.customer_review_required ? 'Customer needs review' : 'Unattributed purchase')) + ' · ' + money(sale.amount_minor)), el('p', sale.number + ' · ' + sale.tender + ' · ' + date(sale.occurred_at), 'event-small event-soft'));
        for (const line of sale.lines) row.append(el('p', line.quantity + ' × ' + line.title));
        if (sale.refunded_minor === null) row.append(el('p', 'Refund coverage needs review.', 'event-small event-soft')); else if (sale.refunded_minor) row.append(el('p', 'Refunded ' + money(sale.refunded_minor)));
        if (sale.customer && permissions.customers) row.append(button('View customer', () => { customers.panel.open = true; void showCustomer(sale.customer.id, 'sales'); }));
        else if (sale.customer_review_required) row.append(el('p', 'Review this purchase’s original customer association before making changes.', 'event-small event-soft')); else if (!sale.customer) row.append(el('p', 'Add this customer from Sales in Everbranch Pay.', 'event-small event-soft'));
        transactionRows.append(row);
      }
      if (!result.sales.length) transactionRows.append(el('p', 'Confirmed sales for this event will appear here. Review the event register and Square allocation if sales are missing.', 'event-soft'));
      pagination(result.has_more, page, loadRows, transactionRows); rowFeedback.textContent = 'Updated ' + date(result.report.sales.as_of);
    } catch (error) { fail(error, 'sales', ticket, rowFeedback, loadRows); } finally { if (valid('sales', ticket)) salesLoading = false; }
  }
  function customerShell() {
    customers.content.replaceChildren(); customerFeedback = el('p', '', 'event-soft event-small'); customerFeedback.setAttribute('role', 'status');
    const form = el('form', '', 'event-customer-search'); searchInput = el('input'); searchInput.type = 'search'; searchInput.value = query; searchInput.placeholder = 'Name, phone or email'; searchInput.setAttribute('aria-label', 'Search event customers');
    searchInput.oninput = () => { customerSerial++; customerController?.abort(); query = searchInput.value; customerPage = 1; clearTimeout(searchTimer); searchTimer = setTimeout(() => loadCustomers(), 250); };
    form.onsubmit = event => { event.preventDefault(); searchInput.blur(); void loadCustomers(); }; form.append(searchInput); customerResults = el('div', '', 'event-customer-results'); customers.content.append(customerFeedback, form, customerResults);
    if (permissions.audience) customers.content.append(newLink('Marketing audience', '/marketing/event-audiences?tenant=' + encodeURIComponent(tenant) + '&event_id=' + eventID));
  }
  async function loadCustomers(nextPage = customerPage) {
    if (!active || accessBlocked || !current() || !customers.panel.open || !permissions.customers || !eventID || customerDetail) return;
    const ticket = ++customerSerial; customerPage = nextPage; customerController?.abort(); customerController = new AbortController();
    if (!searchInput?.isConnected) customerShell(); customerFeedback.textContent = 'Loading customers…';
    try {
      const result = await api('/customers', { query: { event_id: eventID, query, page: customerPage }, signal: customerController.signal }); if (!valid('customers', ticket) || customerDetail) return;
      const list = el('div', '', 'event-customer-list');
      for (const customer of result.customers) { const name = String(customer.name || 'Customer').trim(), row = button(name, () => showCustomer(customer.id)); const contacts = [customer.phone, customer.email].filter(value => value && String(value).trim().toLowerCase() !== name.toLowerCase()); if (contacts.length) row.append(el('span', contacts.join(' · '), 'event-small event-soft')); list.append(row); }
      if (!result.customers.length) list.append(el('p', 'No matching captured contacts or confirmed buyers. Attribution added in Pay appears here automatically.', 'event-soft'));
      customerResults.replaceChildren(list); if (result.event_context?.review_items?.length) customerResults.append(el('p', result.event_context.review_items.join(' '), 'event-small event-soft'));
      pagination(result.has_more, customerPage, loadCustomers, customerResults); customerFeedback.textContent = 'Captured contacts and confirmed buyers';
    } catch (error) { fail(error, 'customers', ticket, customerFeedback, loadCustomers); }
  }
  async function showCustomer(id, back = 'customers') {
    if (!active || accessBlocked || !current() || !permissions.customers) return;
    customerDetail = true; const ticket = ++customerSerial; customerController?.abort(); customerController = new AbortController();
    customers.content.replaceChildren(); customerFeedback = el('p', 'Loading customer…', 'event-soft'); customers.content.append(customerFeedback);
    try {
      const result = await api('/customers/' + id, { signal: customerController.signal }); if (!valid('customers', ticket)) return;
      customers.content.replaceChildren(button('Back to ' + back, () => { customerDetail = false; if (back === 'sales') { customers.panel.open = false; sales.panel.open = true; } else void loadCustomers(); }), el('h3', result.customer.name));
      const contacts = [result.customer.phone, result.customer.email].filter(value => value && String(value).trim().toLowerCase() !== String(result.customer.name || '').trim().toLowerCase()); if (contacts.length) customers.content.append(el('p', contacts.join(' · ')));
      customers.content.append(newLink('Open customer', '/workspace/customers/' + id + '?tenant=' + encodeURIComponent(tenant) + '&event_id=' + eventID));
      const rewards = result.commerce.rewards; if (rewards?.available) customers.content.append(el('p', (rewards.label || 'Rewards') + ' · ' + (rewards.balance == null || String(rewards.balance).trim() === '' ? 'Balance unavailable' : rewards.balance)));
      for (const purchase of result.commerce.purchases) { const text = ['square_order', 'square_payment'].includes(purchase.source) ? 'Square purchase' : purchase.source === 'pos_sale' ? 'In-person purchase' : purchase.number || 'Purchase'; const row = el('details', '', 'event-purchase'); row.append(el('summary', text + ' · ' + (purchase.confirmed ? money(purchase.amount_minor, purchase.currency) : 'Unverified record') + ' · ' + date(purchase.occurred_at))); if (purchase.source_id || purchase.number) row.append(el('p', 'Reference: ' + (purchase.source_id || purchase.number), 'event-small event-soft')); customers.content.append(row); }
      if (result.commerce.has_more) customers.content.append(el('p', 'Open the customer to review older linked history.', 'event-small event-soft'));
    } catch (error) { fail(error, 'customers', ticket, customerFeedback, () => showCustomer(id, back)); }
  }
  sales.panel.ontoggle = () => { if (sales.panel.open) { if (!eventID) sales.content.replaceChildren(el('p', 'Link this occurrence to its reviewed event and register to connect sales.', 'event-soft')); else void loadSummary(); } else stopSales(true); };
  customers.panel.ontoggle = () => { if (customers.panel.open) { if (!eventID) customers.content.replaceChildren(el('p', 'Link this occurrence to its reviewed event and register to connect customers.', 'event-soft')); else if (!customerDetail) void loadCustomers(); } else stopCustomers(true); };
  const timer = setInterval(() => { if (active && !accessBlocked && current() && sales.panel.open && !transactions?.open && !document.hidden && !salesLoading && !document.activeElement?.matches('input,textarea,select')) void loadSummary(); }, 5000);
  return {
    update(data) {
      permissions = { sales: data.sales?.can_view === true, customers: data.can_lookup_customers === true, audience: data.can_prepare_audiences === true };
      if (eventID !== data.commerce_event_id) { stopSales(true); stopCustomers(true); sales.panel.open = customers.panel.open = false; }
      eventID = data.commerce_event_id; panel.hidden = !permissions.sales && !permissions.customers; sales.panel.hidden = !permissions.sales; customers.panel.hidden = !permissions.customers;
      if (!permissions.sales) stopSales(true); if (!permissions.customers) stopCustomers(true);
    },
    dispose() { active = false; stopSales(true); stopCustomers(true); clearInterval(timer); panel.remove(); }
  };
}
