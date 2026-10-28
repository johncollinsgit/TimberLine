let stripeScript;
function loadStripe() {
  if (window.Stripe) return Promise.resolve(window.Stripe);
  stripeScript ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://js.stripe.com/v3/';
    script.onload = () => resolve(window.Stripe);
    script.onerror = () => reject(new Error('Secure authentication could not load. Please try again.'));
    document.head.append(script);
  });
  return stripeScript;
}
async function post(url) {
  const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: {
    Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
  } });
  if (!response.ok) throw new Error('This payment needs review before authentication. No replacement charge was created.');
  return response.json();
}
for (const button of document.querySelectorAll('[data-pilot-authentication]')) {
  button.addEventListener('click', async () => {
    const status = button.parentElement.querySelector('[data-authentication-status]');
    button.disabled = true;
    try {
      const context = await post(button.dataset.prepare);
      const Stripe = await loadStripe();
      const stripe = Stripe(context.publishable_key, { stripeAccount: context.account_id });
      const result = await stripe.handleNextAction({ clientSecret: context.client_secret });
      if (result.error) throw new Error(result.error.message || 'Authentication was not completed.');
      await post(button.dataset.confirm);
      location.reload();
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  });
}
