<script>
(() => {
    const editor = document.querySelector('[data-wholesale-outreach-editor]');
    if (!editor) return;
    const preview = editor.querySelector('[data-outreach-email]');
    const input = editor.querySelector('[data-outreach-message-input]');
    const subject = editor.querySelector('[data-outreach-subject]');
    const gmail = editor.querySelector('[data-outreach-gmail]');
    const status = editor.querySelector('[data-outreach-copy-status]');
    subject.addEventListener('input', () => {
        const url = new URL(gmail.href);
        url.searchParams.set('su', subject.value.replace(/[\r\n]/g, ' '));
        gmail.href = url.toString();
    });
    input.addEventListener('input', () => {
        const message = preview.querySelector('[data-outreach-message]');
        message.replaceChildren(...input.value.split(/\n\s*\n/).map(text => {
            const paragraph = document.createElement('p');
            paragraph.style.cssText = 'margin:0 0 18px;white-space:pre-wrap';
            paragraph.textContent = text;
            return paragraph;
        }));
        status.textContent = 'Message updated. Copy the formatted email again before pasting into Gmail.';
    });
    editor.querySelector('[data-outreach-copy]').addEventListener('click', async () => {
        let copied = false;
        try {
            if (!navigator.clipboard?.write || typeof ClipboardItem === 'undefined') throw new Error('Rich clipboard unavailable');
            await navigator.clipboard.write([new ClipboardItem({
                'text/html': new Blob([preview.innerHTML], {type: 'text/html'}),
                'text/plain': new Blob([preview.innerText], {type: 'text/plain'}),
            })]);
            copied = true;
        } catch {
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(preview);
            selection.removeAllRanges();
            selection.addRange(range);
            try { copied = document.execCommand('copy'); } catch {}
            if (copied) selection.removeAllRanges();
        }
        status.textContent = copied
            ? 'Formatted email copied. Open Gmail and paste into the message body. Review the recipient and sending account before sending.'
            : 'The email is selected. Press Command+C (Ctrl+C on Windows), open Gmail, then paste into its message body.';
    });
})();
</script>
