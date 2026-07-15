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
        window.addEventListener('pageshow', bootstrapEmbeddedIdentity, { once: true });
        document.addEventListener('visibilitychange', () => {
            if (!verificationFinished && document.visibilityState === 'visible') {
                bootstrapEmbeddedIdentity();
            }
        });
    })();
</script>
