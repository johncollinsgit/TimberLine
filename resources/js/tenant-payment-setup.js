import { loadConnectAndInitialize } from '@stripe/connect-js/pure';

function mountPaymentSetup() {
    document.querySelectorAll('[data-stripe-setup]').forEach((root) => {
        if (root.dataset.mounted) return;
        root.dataset.mounted = 'true';
        const button = root.querySelector('[data-start]');
        const error = root.querySelector('[data-error]');
        let instance;
        const showError = (message) => {
            error.textContent = message;
            error.hidden = false;
            button.disabled = false;
        };
        button.addEventListener('click', () => {
            button.disabled = true;
            error.hidden = true;
            try {
                instance ??= loadConnectAndInitialize({
                    publishableKey: root.dataset.key,
                    fetchClientSecret: async () => {
                        const response = await fetch(root.dataset.sessionUrl, {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        });
                        const data = await response.json();
                        if (!response.ok || !data.client_secret) {
                            const message = data.message || 'Payment setup is unavailable. Please try again.';
                            showError(message);
                            throw new Error('Payment setup session unavailable');
                        }
                        return data.client_secret;
                    },
                    appearance: { variables: { colorPrimary: '#183e34', borderRadius: '8px' } },
                });
                const component = instance.create('account-onboarding');
                component.setOnExit(() => {
                    root.querySelector('[data-complete]').hidden = false;
                    root.querySelector('[data-component]').replaceChildren();
                    button.disabled = false;
                });
                component.setOnLoadError(() => showError('Payment setup could not load. Please try again or contact support.'));
                root.querySelector('[data-component]').replaceChildren(component);
            } catch {
                showError('Payment setup could not load. Please try again or contact support.');
            }
        });
        document.addEventListener('livewire:navigating', () => instance?.logout(), { once: true });
    });
}

mountPaymentSetup();
document.addEventListener('livewire:navigated', mountPaymentSetup);
