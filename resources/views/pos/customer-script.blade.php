<script>{!! file_get_contents(resource_path('js/pos-customers.js')) !!}</script>
<script>
(() => {
    const byId = id => document.getElementById(id);
    const createForm = byId('customer-create-form');
    const read = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options,
            headers: { Accept: 'application/json', ...(options.headers || {}) } });
        const data = await response.json();
        if (!response.ok) { const error = new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Native customer request unavailable.'); error.status = response.status; throw error; }
        return data;
    };
    PosCustomers.bind({ saleForm: byId('sale-form'), selectedId: byId('selected-customer-id'), selectedName: byId('selected-customer-name'),
        selectedContact: byId('selected-customer-contact'), clear: byId('customer-clear'), history: byId('selected-customer-history'), historyUrl: @json($customerHistoryUrl),
        picker: byId('customer-picker'), open: byId('customer-picker-open'), close: byId('customer-picker-close'), searchInput: byId('customer-search'),
        searchButton: byId('customer-search-button'), searchFeedback: byId('customer-search-feedback'), results: byId('customer-results'),
        createForm, createButton: createForm.querySelector('button[type="submit"]'), newButton: byId('customer-create-new'),
        createFeedback: byId('customer-create-feedback'), recovery: byId('customer-create-recovery'), scope: @json('everbranch-pos-customer-create:'.$tenant->id.':'.$register->id),
        freshKey: @json($customerRequestId), initial: @json($selectedCustomer), blocked: @json($blocked),
        search: query => { const url = new URL(@json($customerSearchUrl)); url.searchParams.set('query', query); return read(url); },
        create: input => read(@json($customerCreateUrl), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': createForm.querySelector('[name="_token"]').value }, body: JSON.stringify(input) }),
        findCreation: async key => { const data = await read(@json($customerRequestUrl).replace('00000000-0000-0000-0000-000000000000', key)); return data.customer; }
    });
})();
</script>
