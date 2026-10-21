/* Local register interactions. Server lookup, pricing and payment verification remain authoritative. */
(() => {
    const money = cents => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
    function parseCashCents(value) {
        if (typeof value !== 'string' || !/^(0|[1-9]\d{0,6})(\.\d{1,2})?$/.test(value.trim())) return null;
        const [whole, fraction = ''] = value.trim().split('.');
        const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
        return Number.isSafeInteger(cents) && cents <= 100000000 ? cents : null;
    }

    function addItem({ tbody, item, document = globalThis.document }) {
        if (!item || !Number.isSafeInteger(item.variant_id) || item.variant_id < 1 || typeof item.title !== 'string'
            || typeof item.sku !== 'string' || !Number.isSafeInteger(item.price_cents) || item.price_cents < 1 || item.price_cents > 100000000
            || !(item.available_quantity === null || (Number.isSafeInteger(item.available_quantity) && item.available_quantity >= 1))) {
            throw new Error('The catalog response needs review. No item was added.');
        }
        const rows = Array.from(tbody.querySelectorAll('[data-product]'));
        let row = rows.find(candidate => candidate.dataset.variantId === String(item.variant_id));
        const previous = row ? Number(row.querySelector('.quantity').value) || 0 : 0;
        if (!Number.isSafeInteger(previous) || previous < 0) throw new Error('Review this item quantity before scanning it again.');
        const next = previous + 1;
        const selected = rows.filter(candidate => Number(candidate.querySelector('.quantity').value) > 0).length;
        if (next > 99 || (item.available_quantity !== null && next > item.available_quantity)) throw new Error('The requested quantity exceeds available stock or the 99-item limit.');
        if (previous === 0 && selected >= 50) throw new Error('A practice sale supports up to 50 different items.');
        if (!row && rows.length >= 200) throw new Error('The loaded item list is full. Finish or reset this practice basket before adding another SKU.');
        if (!row) {
            const index = Number(tbody.dataset.nextIndex || rows.length);
            row = document.createElement('tr'); row.dataset.product = ''; row.dataset.variantId = String(item.variant_id);
            row.dataset.search = `${item.title} ${item.variant_title || ''} ${item.sku}`.toLowerCase();
            const titleCell = document.createElement('td');
            const title = document.createElement('strong'); title.dataset.productTitle = ''; title.textContent = item.title;
            const detail = document.createElement('span'); detail.className = 'sub'; detail.textContent = [item.variant_title, item.sku].filter(Boolean).join(' · ');
            const availability = document.createElement('span'); availability.className = 'sub'; availability.textContent = item.available_quantity === null ? 'Not stock tracked' : `${item.available_quantity} available`;
            titleCell.append(title, detail, availability);
            const priceCell = document.createElement('td'); priceCell.className = 'number'; priceCell.dataset.unitPrice = '';
            const quantityCell = document.createElement('td'); quantityCell.className = 'number';
            const variant = document.createElement('input'); variant.type = 'hidden'; variant.name = `lines[${index}][variant_id]`; variant.value = item.variant_id;
            const label = document.createElement('label'); label.className = 'visually-hidden'; label.htmlFor = `quantity-${item.variant_id}`; label.textContent = `Quantity for ${item.title}`;
            const quantity = document.createElement('input'); quantity.type = 'number'; quantity.className = 'quantity'; quantity.id = `quantity-${item.variant_id}`;
            quantity.name = `lines[${index}][quantity]`; quantity.min = '0'; quantity.max = '99'; quantity.step = '1'; quantity.value = '0';
            quantityCell.append(variant, label, quantity); row.append(titleCell, priceCell, quantityCell);
            tbody.querySelector('[data-empty-catalog]')?.remove(); tbody.append(row); tbody.dataset.nextIndex = String(index + 1);
        }
        row.hidden = false; row.dataset.price = String(item.price_cents);
        row.querySelector('[data-unit-price]').textContent = money(item.price_cents);
        row.querySelector('.quantity').value = String(next);
        return next;
    }

    function bindScanner({ form, input, button, feedback, tbody, lookup, update, blocked = false, document = globalThis.document }) {
        let pending = false;
        // Independent of request recovery: completing a SKU lookup cannot unlock
        // a sale whose original payment request still needs review.
        form.addEventListener('submit', event => { if (pending) { event.preventDefault(); feedback.textContent = 'Wait for this SKU lookup before preparing the sale.'; } });
        const perform = async () => {
            if (blocked || pending) return;
            const code = input.value.trim();
            if (!code) { feedback.textContent = 'Scan or enter the exact catalog SKU first.'; input.focus(); return; }
            pending = true; button.disabled = true; feedback.textContent = 'Checking this SKU…';
            try {
                const item = await lookup(code);
                const quantity = addItem({ tbody, item, document });
                update(); feedback.textContent = `Added ${item.title}. Basket quantity ${quantity}.`;
                if (input.value.trim() === code) input.value = '';
            } catch (error) {
                feedback.textContent = error?.message || 'Unable to look up this SKU. No item was added.';
            } finally { pending = false; button.disabled = blocked; input.focus(); }
        };
        input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); void perform(); } });
        button.addEventListener('click', () => { void perform(); });
        return { perform };
    }
    globalThis.PosRegisterUI = { parseCashCents, addItem, bindScanner };
})();
