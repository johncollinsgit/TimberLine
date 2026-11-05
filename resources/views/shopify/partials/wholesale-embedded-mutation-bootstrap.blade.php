<script>
    (function () {
        const tokenInputs = Array.from(document.querySelectorAll('[data-wholesale-session-token]'));
        const buttons = Array.from(document.querySelectorAll('[data-wholesale-mutation-button]'));
        const helpLabels = Array.from(document.querySelectorAll('[data-wholesale-verification-help]'));

        if (tokenInputs.length === 0) {
            return;
        }

        function resolveSessionTokenHelper(timeoutMs = 10000) {
            return new Promise((resolve, reject) => {
                const startedAt = Date.now();
                const tick = () => {
                    const resolver = window.ForestryEmbeddedApp?.getShopifySessionToken;
                    if (typeof resolver === 'function') {
                        resolve(resolver.bind(window.ForestryEmbeddedApp));
                        return;
                    }

                    if ((Date.now() - startedAt) >= timeoutMs) {
                        reject(new Error('Shopify admin verification helper did not become available.'));
                        return;
                    }

                    window.setTimeout(tick, 120);
                };

                tick();
            });
        }

        let verificationFinished = false;
        function bootstrapEmbeddedIdentity() {
            if (verificationFinished) {
                return;
            }

            resolveSessionTokenHelper()
                .then((resolver) => resolver())
                .then((token) => {
                    if (typeof token !== 'string' || token.trim() === '') {
                        throw new Error('Missing Shopify admin session token.');
                    }

                    tokenInputs.forEach((input) => { input.value = token.trim(); });
                    buttons.forEach((button) => { button.removeAttribute('disabled'); });
                    helpLabels.forEach((label) => { label.textContent = 'Shopify admin identity verified.'; });
                    verificationFinished = true;
                })
                .catch(() => {
                    helpLabels.forEach((label) => {
                        label.textContent = 'Shopify admin verification did not load. Refresh this app from Shopify Admin and try again.';
                    });
                });
        }

        bootstrapEmbeddedIdentity();
        const mutationForms = new Set(tokenInputs.map((input) => input.closest('form')).filter(Boolean));
        mutationForms.forEach((form) => {
            let submitting = false;
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (submitting) return;
                submitting = true;
                const formButtons = Array.from(form.querySelectorAll('[data-wholesale-mutation-button]'));
                const help = form.querySelector('[data-wholesale-verification-help]');
                formButtons.forEach((button) => { button.disabled = true; });
                try {
                    const resolver = await resolveSessionTokenHelper();
                    const token = await resolver();
                    if (typeof token !== 'string' || token.trim() === '') throw new Error('Missing session token');
                    form.querySelector('[data-wholesale-session-token]').value = token.trim();
                    HTMLFormElement.prototype.submit.call(form);
                } catch (error) {
                    if (help) help.textContent = 'Shopify verification failed. Your entries are preserved. Refresh verification and try again.';
                    submitting = false;
                    formButtons.forEach((button) => { button.disabled = false; });
                }
            });
        });
        window.addEventListener('pageshow', bootstrapEmbeddedIdentity, { once: true });
        document.addEventListener('visibilitychange', () => {
            if (!verificationFinished && document.visibilityState === 'visible') {
                bootstrapEmbeddedIdentity();
            }
        });
    })();
</script>
