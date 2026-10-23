<div id="conversation-controls" class="stack" hidden>
  <h3 id="conversation-title">Text conversation</h3>
  <p class="caption" id="conversation-context"></p>
  <p class="caption">OpenAI replies use your current saved records. Check linked sources; estimates are not approved budgets.</p>
  @include('enterprise.saved-assistant-controls')
  <div class="field-pair">
    <label>Conversation focus<select id="conversation-intent"><option value="overview">Current work</option><option value="building">A saved building</option><option value="costs">Recorded costs and estimates</option></select></label>
    <label id="conversation-building-field" hidden>Saved building<select id="conversation-building"></select></label>
  </div>
  <p id="conversation-status" role="status" aria-live="polite"></p>
  <details class="conversation-help"><summary>Trial allowance and privacy</summary><p class="caption" id="conversation-allowance"></p><p class="caption">This short conversation expires after ten minutes. Do not enter passwords, keys or other secrets. Your browser keeps receipt IDs only. Conversation content is stored privately; Clear conversation removes its saved text.</p></details>
  <div class="actions">
    <button class="button" type="button" id="conversation-check">Check availability</button>
    <button class="button" type="button" id="conversation-stop" hidden>Stop waiting</button>
    <button class="button" type="button" id="conversation-clear" hidden>Clear conversation</button>
  </div>
  <div class="stack" id="conversation-clear-confirm" hidden>
    <p>Clear this conversation's content? A reply already in progress may still incur its reserved cost.</p>
    <div class="actions"><button class="button" type="button" id="conversation-clear-yes">Clear conversation content</button><button class="button" type="button" id="conversation-clear-no">Keep conversation</button></div>
    <button class="button" type="button" id="conversation-forget" hidden>Forget this browser's receipt only</button>
  </div>
  <p class="caption">Text only · voice is queued separately. Nothing is sent to a person or changed in your work records.</p>
</div>
