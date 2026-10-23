<section id="private-memory" class="stack" hidden aria-labelledby="private-memory-title">
  <h4 id="private-memory-title">Your private memory</h4>
  <label class="memory-check"><input type="checkbox" id="memory-use"> Use my available memory in this conversation</label>
  <p class="caption">Memory starts off. Formatting preferences are separate from source observations. Current access and source versions are checked before every use.</p>
  <details id="memory-manager"><summary>Inspect, edit or delete memory</summary>
    <p class="caption">Private to your account and this department. Nothing is captured from chat. Only the typed choices below can be saved; never passwords or keys.</p>
    <div id="memory-list" class="stack"></div>
    <div class="actions"><button type="button" class="button" id="memory-new">Save a formatting preference</button><button type="button" class="button" id="memory-refresh">Refresh memory</button></div>
    <p class="caption">To save a source observation, run a current record check below and choose “Save observation” beside a source. It adds no retrieval permission.</p>
    <form id="memory-form" class="stack" hidden aria-label="Explicit private memory">
      <h4 id="memory-edit-title">Save a formatting preference</h4>
      <div id="memory-preference-fields" class="field-pair"><label>Preference<select id="memory-key"><option value="reply_length">Reply length</option><option value="date_style">Date style</option></select></label><label>Choice<select id="memory-value"></select></label></div>
      <div id="memory-source-fields" class="stack" hidden><p id="memory-source-title"></p><label>Source field<select id="memory-field" aria-describedby="memory-preview"></select></label><p id="memory-preview" class="caption"></p></div>
      <label>Keep for<select id="memory-retention"><option value="1">1 day</option><option value="7" selected>7 days</option><option value="30">30 days</option></select></label>
      <label class="memory-check"><input type="checkbox" id="memory-confirm"> I reviewed this typed value and retention period.</label>
      <div class="actions"><button type="submit" class="button primary" id="memory-save">Save private memory</button><button type="button" class="button" id="memory-cancel">Close memory editor</button></div>
    </form>
    <div id="memory-delete-confirm" class="stack" hidden><p>Delete this private memory's saved value? Existing work and spend receipts remain. A copy already sent to OpenAI cannot be recalled; a short conversation copy expires within ten minutes or can be cleared.</p><div class="actions"><button type="button" class="button" id="memory-delete-yes">Delete this private memory</button><button type="button" class="button" id="memory-delete-no">Keep memory</button></div></div>
    <p class="caption">After expiry, memory is withheld immediately. Approved retention cleanup scrubs its saved value; content-free audit remains. Clear the current conversation before editing memory.</p>
  </details>
  <p id="memory-status" role="status" aria-live="polite" tabindex="-1"></p>
</section>
