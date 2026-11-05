<script>
(() => {
    const panel = document.querySelector('[data-gmail-native]');
    if (!panel) return;
    const editor = panel.closest('[data-wholesale-outreach-editor]');
    const find = name => editor.querySelector(`[data-gmail-${name}]`);
    const status = find('status'), review = find('review'), connect = find('connect');
    const dialog = find('dialog'), send = find('send'), cancel = find('cancel');
    let reviewed = null, busy = false, frozen = false;
    const request = async (url, data = {}) => {
        if (!window.shopify?.idToken) throw new Error('Open this page inside MF Wholesale Backstage to verify your session.');
        const token = await window.shopify.idToken();
        const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}`}, body: JSON.stringify(data)});
        const value = await response.json();
        if (!response.ok) throw new Error(value.message || 'The request could not be completed. Your email has not been retried.');
        return value;
    };
    const refresh = async () => {
        if (busy || frozen) return;
        review.disabled = true;
        try {
            const value = await request(panel.dataset.statusUrl);
            status.textContent = value.message;
            find('from').textContent = value.from ? `From: ${value.name} <${value.from}>\nReply to: ${value.reply_to}` : '';
            connect.hidden = !value.configured || value.connected;
            find('disconnect').hidden = !value.can_disconnect;
            review.disabled = !(value.can_review ?? value.can_send);
        } catch (error) { status.textContent = error.message; }
    };
    find('refresh').addEventListener('click', refresh);
    connect.addEventListener('click', async () => {
        if (busy || frozen) return;
        // Create the tab during the click so popup blocking cannot lose the OAuth handoff.
        const popup = window.open('about:blank', '_blank');
        if (!popup) { status.textContent = 'Allow this connection tab, then choose Connect Gmail again.'; return; }
        popup.opener = null;
        busy = true; connect.disabled = true;
        try {
            const value = await request(panel.dataset.connectUrl);
            const url = new URL(value.url);
            if (url.origin !== window.location.origin || !url.pathname.startsWith('/wholesale/gmail/start/')) throw new Error('The Gmail connection link could not be verified.');
            popup.location.href = url.href;
            status.textContent = 'Finish connecting in the new tab, then choose Check connection here.';
        } catch (error) { popup.close(); status.textContent = error.message; }
        finally { busy = false; connect.disabled = false; }
    });
    find('disconnect').addEventListener('click', async () => {
        if (busy || frozen) return;
        busy = true; review.disabled = true;
        try { await request(panel.dataset.disconnectUrl); }
        catch (error) { status.textContent = error.message; }
        finally { busy = false; await refresh(); }
    });
    review.addEventListener('click', async () => {
        if (busy || frozen) return;
        busy = true; review.disabled = true;
        try {
            reviewed = await request(panel.dataset.reviewUrl, {
                subject: editor.querySelector('[data-outreach-subject]').value,
                message: editor.querySelector('[data-outreach-message-input]').value,
            });
            find('envelope').textContent = `From: ${reviewed.name} <${reviewed.from}>\nReply to: ${reviewed.reply_to}\nTo: ${reviewed.to}\nSubject: ${reviewed.subject}`;
            find('preview').srcdoc = reviewed.html;
            find('send-status').textContent = '';
            send.disabled = !reviewed.can_send; cancel.disabled = false;
            find('test').hidden = !reviewed.can_test;
            find('test').disabled = !reviewed.can_test;
            find('test-recipient').hidden = !reviewed.can_test;
            find('test-recipient').textContent = reviewed.can_test ? `Internal tests go only to ${reviewed.test_recipient}.` : '';
            dialog.showModal();
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; review.disabled = false; }
    });
    cancel.addEventListener('click', () => { if (!busy) { reviewed = null; dialog.close(); } });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    const deliver = async (test = false) => {
        if (busy || frozen || !reviewed) return;
        busy = true; send.disabled = true; find('test').disabled = true; cancel.disabled = true;
        find('send-status').textContent = 'Sending your reviewed email…';
        try {
            const result = await request(`${test ? panel.dataset.testUrl : panel.dataset.sendUrl}/${encodeURIComponent(reviewed.id)}`, {confirmed: true});
            frozen = !test;
            find('send-status').textContent = (test ? `Internal test to ${result.to}: ` : '') + result.message + (result.message_id ? ` Reference: ${result.message_id}.` : '');
            status.textContent = find('send-status').textContent;
        } catch (error) {
            // A lost browser response may follow a successful provider send. Retain this exact review; never create another attempt.
            frozen = true;
            find('send-status').textContent = `Send not confirmed: ${error.message} Check Gmail Sent or ask your administrator to reconcile this review: ${reviewed.id}.`;
            status.textContent = find('send-status').textContent;
        } finally {
            busy = false; review.disabled = frozen; cancel.disabled = false; cancel.textContent = 'Close';
            if (test && !frozen) send.disabled = !reviewed.can_send;
        }
    };
    send.addEventListener('click', () => deliver(false));
    find('test').addEventListener('click', () => deliver(true));
    refresh();
})();
</script>
