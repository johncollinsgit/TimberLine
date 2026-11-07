<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>{{ $label }} · {{ $tenant->name }}</title>
<style nonce="{{ $nonce }}">body{font:17px system-ui;background:#f5f3ed;color:#173e32;margin:0}main{max-width:430px;margin:5vh auto;padding:28px}section{background:white;padding:26px;border-radius:24px}button,a{font:inherit;border:0;border-radius:24px;padding:15px 20px;background:#173e32;color:white;display:block;margin-top:18px;text-decoration:none}small{line-height:1.5;display:block}#code svg{max-width:100%;height:auto;background:white}p{line-height:1.5}button:disabled{opacity:.5}</style></head><body><main><h1>{{ $tenant->name }}</h1><section><h2>{{ $label }}</h2>
@if($identity['customer_id'] === '')
<p>Sign in to your store account to show a rewards code. If your account does not appear here after signing in, the cashier can verify your saved phone or email instead.</p>
<a href="/account/login?return_url={{ rawurlencode($identity['path_prefix'].'/rewards/show-code') }}">Sign in</a>
@elseif($cap !== null && $profile)
<p>Available balance: <strong>${{ number_format($balance/100,2) }}</strong></p>
<p>Showing this code lets your cashier use up to <strong>${{ number_format($cap/100,2) }}</strong> from your rewards for one in-person purchase, limited to your balance and eligible merchandise.</p>
<small>The code expires in two minutes. Share it only with your cashier. Showing a code does not start a purchase or sign you up for marketing.</small>
<button id="agree" type="button">Agree and show one-use code</button>
@elseif(!$profile)
<p>Connect your existing rewards wallet with the cashier before using a show code. This request will not move or combine balances.</p><button id="review" type="button">Request wallet connection</button>
@else
<p>In-person rewards need to be set up by this business. Your cashier can help.</p>
@endif
<div id="code" aria-label="Temporary rewards authorization code"></div><p id="message" role="status"></p></section></main>
<script nonce="{{ $nonce }}">
const challenge = @json($challenge);
const message = document.getElementById('message');
let expiryTimer;
async function submit(action) {
    document.querySelectorAll('button').forEach(button => button.disabled = true);
    message.textContent = 'Preparing…';
    try {
        const response = await fetch(location.pathname + location.search, {method:'POST', credentials:'omit', cache:'no-store', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({action, challenge, confirmed:true})});
        const result = await response.json();
        if (!response.ok) throw new Error('unavailable');
        if (result.svg) {
            document.getElementById('code').innerHTML = result.svg;
            message.textContent = 'Show this code to your cashier. It expires shortly.';
            expiryTimer = setTimeout(clearCode, Math.max(0, Date.parse(result.expires_at) - Date.now()));
        } else if (result.review) {
            message.textContent = 'Give this review reference to your cashier: ' + result.review.reference + '. After they connect your wallet, reload this page.';
        }
    } catch {
        message.textContent = 'The request was not confirmed. Reload this page to review the current terms before trying again.';
    }
}
function clearCode() { document.getElementById('code').replaceChildren(); clearTimeout(expiryTimer); message.textContent = 'The code has been cleared. Reload this page for a new code.'; }
document.getElementById('agree')?.addEventListener('click', () => submit('code'));
document.getElementById('review')?.addEventListener('click', () => submit('review'));
document.addEventListener('visibilitychange', () => { if (document.hidden) clearCode(); });
window.addEventListener('pagehide', clearCode);
</script></body></html>
