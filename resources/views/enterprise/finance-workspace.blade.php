<style>
 .finance-grid{display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.4fr);gap:20px}.finance-list{display:grid;gap:10px;align-content:start;max-height:650px;overflow:auto}.finance-record{width:100%;text-align:left;border:1px solid var(--line);background:white;border-radius:7px;padding:16px;color:var(--ink)}.finance-record strong,.finance-record small{display:block;overflow-wrap:anywhere}.finance-record small{margin-top:8px;line-height:1.6}.finance-record[aria-pressed=true]{border-color:#9b8245;box-shadow:inset 3px 0 var(--gold)}#finance-workspace{overflow-wrap:anywhere}#finance-workspace button,#finance-workspace input,#finance-workspace select{min-height:44px}#finance-workspace input,#finance-workspace select,#finance-workspace textarea{width:100%;min-width:0;max-width:100%}#finance-workspace select{white-space:normal}#finance-workspace fieldset{border:0;padding:0;margin:0;min-width:0}.finance-facts{display:grid;grid-template-columns:minmax(105px,.5fr) minmax(0,1fr);gap:10px;font-size:12px}.finance-facts dt{color:var(--muted)}.finance-facts dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}.finance-confirm{display:flex!important;align-items:center;gap:10px!important;min-height:44px;line-height:1.6}.finance-confirm input{width:22px!important;height:22px;min-height:22px!important;flex:0 0 22px}.finance-action{border-top:1px solid var(--line);padding-top:20px;margin-top:18px}.finance-history{max-height:320px;overflow:auto}.finance-history article{font-size:12px;border-top:1px solid var(--line);padding:12px 0}#finance-workspace :focus-visible{outline:3px solid #738a5a;outline-offset:3px}@media(max-width:1100px){.finance-grid{grid-template-columns:1fr}.finance-list{max-height:350px}}@media(max-width:650px){.finance-facts{grid-template-columns:1fr}.finance-facts dd{margin-bottom:8px}#finance-workspace input,#finance-workspace select,#finance-workspace textarea{font-size:16px}}
</style>
<section class="panel stack" id="finance-workspace" aria-labelledby="finance-heading" hidden>
 <div><div class="eyebrow">Restricted financial handoffs</div><h2 id="finance-heading">Invoice &amp; fund review packets</h2><p class="subtle">Keep the document reference, fund restriction and next reviewer with the same project.</p></div>
 <p class="procedure-notice" id="finance-boundary">Internal procedural review only. Finance remains authoritative for funds, approvals, checks and payment. Codes and restrictions are manually referenced; no available balance, permission to pay or payment execution is inferred.</p>
 <p id="finance-status" class="caption" role="status" aria-live="polite"></p>
 <div id="finance-body" class="stack" hidden>
  <div class="actions"><button class="button" type="button" id="finance-refresh">Refresh packets</button><button class="button primary" type="button" id="finance-new">New review packet</button><button class="button" type="button" id="finance-retry" hidden>Retry exact packet action</button></div>
  <details class="procedure-notice" id="finance-retained" hidden><summary>Review retained exact packet action</summary><p>The outcome is unconfirmed. Retry the same action before editing. Current financial access is checked first.</p><pre id="finance-retained-body"></pre></details>
  <p class="caption" id="finance-coverage"></p>
  <div class="finance-grid"><section><h3>Packets and next owners</h3><div class="finance-list" id="finance-list"></div></section><section id="finance-detail" aria-label="Selected invoice packet details"></section></div>
  <form class="stack finance-action" id="finance-form" hidden>
   <h3 id="finance-form-heading">New review packet</h3><fieldset class="stack" id="finance-fields">
    <label>Packet title<input id="finance-title" required maxlength="100"></label>
    <label>Existing project<select id="finance-project" required></select></label>
    <label>Existing contractor, if applicable<select id="finance-contractor"><option value="">No contractor reference</option></select></label>
    <div class="field-pair"><label>Amount in minor units<input id="finance-amount" type="number" min="0" max="1000000000" step="1" required></label><label>Currency<input id="finance-currency" value="USD" pattern="[A-Z]{3}" maxlength="3" required></label></div>
    <label>Original invoice / check-request document reference, if known<textarea id="finance-document" rows="2" maxlength="500"></textarea></label>
    <div class="field-pair"><label>Fund / budget code reference<input id="finance-fund" maxlength="100"></label><label>Existing restriction source, if known<input id="finance-restriction" maxlength="1000"></label></div>
    <label>Source-system / Finance packet reference, if known<input id="finance-source" maxlength="500"></label>
    <label>Next authorized reviewer<select id="finance-owner" required></select></label>
    <p class="caption">Use references already agreed with Finance. Unknown fields may stay blank in a draft; document, fund and source references are required before submission. This workspace does not verify donor restrictions or replace the authoritative ledger. Editing a reviewed packet requires a new review.</p>
   </fieldset><div class="actions"><button class="button primary" type="submit">Save packet draft</button><button class="button" type="button" id="finance-close">Close unsent form</button></div>
  </form>
 </div>
</section>
