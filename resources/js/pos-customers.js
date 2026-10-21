/* Explicit native-customer selection. No contact details are persisted in browser storage. */
(() => {
    const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    function summary(customer) {
        if (!customer || !Number.isSafeInteger(customer.id) || customer.id < 1 || typeof customer.name !== 'string'
            || ![customer.email, customer.phone].every(value => value === null || typeof value === 'string')) {
            throw new Error('The native customer response needs review. No selection was changed.');
        }
        return customer;
    }
    function choices({ container, customers, select, document = globalThis.document }) {
        const checked = customers.map(summary);
        const rows = checked.map(customer => {
            const row = document.createElement('div'); row.className = 'customer-result';
            const name = document.createElement('strong'); name.textContent = customer.name;
            const contact = document.createElement('span'); contact.className = 'helper-copy'; contact.textContent = [customer.email, customer.phone].filter(Boolean).join(' · ');
            const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary'; button.textContent = 'Select customer';
            button.addEventListener('click', () => select(customer)); row.append(name, contact, button); return row;
        });
        container.replaceChildren(...rows);
    }
    function bind({ saleForm, selectedId, selectedName, selectedContact, clear, history, historyUrl, picker, open, close,
        searchInput, searchButton, searchFeedback, results, createForm, createButton, newButton, createFeedback, recovery,
        search, create, findCreation, scope, freshKey, initial = null, blocked = false,
        storage = null, newKey = () => globalThis.crypto.randomUUID(), document = globalThis.document }) {
        if (storage === null) { try { storage = globalThis.sessionStorage; } catch {} }
        let searchPending = false, createPending = false, recovering = true, creationFinished = false, selectionVersion = 0;
        const keyInput = createForm.querySelector('[name="request_id"]');
        const fields = ['first_name', 'last_name', 'email', 'phone'].map(name => createForm.querySelector(`[name="${name}"]`));
        const remember = value => { try { storage?.setItem(scope, value); } catch {} };
        const select = customer => {
            if (blocked) return;
            if (customer) summary(customer);
            selectionVersion++;
            selectedId.value = customer ? String(customer.id) : ''; selectedName.textContent = customer ? customer.name : 'Guest checkout';
            selectedContact.textContent = customer ? [customer.email, customer.phone].filter(Boolean).join(' · ') : 'No customer profile is required.';
            clear.hidden = !customer; history.hidden = !customer;
            if (customer) history.href = historyUrl.replace('/customers/0/history', `/customers/${customer.id}/history`);
            picker.hidden = true;
        };
        const choose = (container, customers) => choices({ container, customers, select, document });
        const reflectFinished = customer => {
            creationFinished = true; createButton.disabled = true; newButton.hidden = false;
            fields.forEach(field => field.readOnly = true);
            createFeedback.textContent = 'This creation request already finished. Select the existing customer below, or explicitly prepare a different customer.';
            choose(recovery, [customer]);
        };
        saleForm.addEventListener('submit', event => {
            if (searchPending || createPending || recovering) { event.preventDefault(); searchFeedback.textContent = 'Wait for the customer request to finish before preparing the sale.'; }
        });
        open.addEventListener('click', () => { if (!blocked) { picker.hidden = false; searchInput.focus(); } });
        close.addEventListener('click', () => { picker.hidden = true; open.focus(); });
        clear.addEventListener('click', () => select(null));
        const performSearch = async () => {
            if (blocked || searchPending) return;
            const query = searchInput.value.trim();
            if (query.length < 2) { searchFeedback.textContent = 'Enter at least two characters. No customer was selected.'; return; }
            searchPending = true; searchButton.disabled = true; searchFeedback.textContent = 'Finding native customers…'; results.replaceChildren();
            try {
                const data = await search(query);
                if (!data || !Array.isArray(data.customers)) throw new Error('The customer search needs review.');
                choose(results, data.customers);
                searchFeedback.textContent = data.customers.length ? `${data.customers.length} matching customers. Choose one explicitly.${data.has_more ? ' More matches exist; refine this search.' : ''}` : 'No active customer matched. Continue as guest or explicitly create a native customer.';
            } catch (error) { searchFeedback.textContent = error?.message || 'Customer search is unavailable. No selection was changed.'; }
            finally { searchPending = false; searchButton.disabled = blocked; }
        };
        searchInput.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); void performSearch(); } });
        searchButton.addEventListener('click', () => { void performSearch(); });
        newButton.addEventListener('click', () => {
            if (createPending || recovering) return;
            keyInput.value = newKey(); creationFinished = false; newButton.hidden = true; recovery.replaceChildren();
            fields.forEach(field => { field.readOnly = false; field.value = ''; });
            try { storage?.removeItem(scope); } catch {}
            createButton.disabled = blocked; createButton.textContent = 'Create & select customer'; createFeedback.textContent = 'Ready for an explicit new native customer.';
            fields[0].focus();
        });
        const performCreate = async () => {
            if (blocked || recovering || creationFinished || createPending) return;
            createPending = true; createButton.disabled = true; remember(keyInput.value); createFeedback.textContent = 'Creating the native customer…';
            const requestedSelection = selectionVersion;
            const input = { request_id: keyInput.value };
            fields.forEach(field => { input[field.name] = field.value; field.readOnly = true; });
            try {
                const data = await create(input); const customer = summary(data.customer);
                reflectFinished(customer);
                if (requestedSelection === selectionVersion) select(customer);
            } catch (error) {
                createFeedback.textContent = error?.message || 'Creation was not confirmed. Retry these same details to recover the existing request.';
                // An uncertain response retains both the request UUID and terms.
                // Validation errors precede creation and may be corrected.
                fields.forEach(field => {
                    field.readOnly = error?.status !== 422;
                    if (error?.status !== 422) field.value = input[field.name];
                });
                createButton.textContent = error?.status === 422 ? 'Create & select customer' : 'Retry existing creation';
            } finally { createPending = false; createButton.disabled = blocked || creationFinished; }
        };
        createForm.addEventListener('submit', event => { event.preventDefault(); void performCreate(); });
        const ready = (async () => {
            createButton.disabled = true;
            let saved = null; try { saved = storage?.getItem(scope); } catch {}
            keyInput.value = saved && uuid.test(saved) ? saved : freshKey;
            try {
                if (saved && uuid.test(saved)) {
                    const customer = await findCreation(saved);
                    if (customer) reflectFinished(summary(customer));
                }
                recovering = false;
                if (!creationFinished) { createButton.disabled = blocked; createFeedback.textContent = 'Create only when the customer explicitly needs a new native profile.'; }
            } catch {
                createFeedback.textContent = 'The saved creation request cannot be checked. Reload to recover it before creating another customer. Guest checkout remains available after recovery.';
                // Fail closed across both creation and sale until recovery can be checked.
            }
        })();
        if (initial) select(summary(initial));
        return { ready, select, performSearch, performCreate };
    }
    globalThis.PosCustomers = { summary, choices, bind };
})();
